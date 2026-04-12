<?php
/**
 * POST — One-shot total repair orchestrator.
 *
 * Runs the full Plan A–H repair sequence in one HTTP call, so the admin
 * doesn't have to orchestrate 4 separate endpoints. Order:
 *
 *   1) reparar-schema  → relax NOT NULL constraints on text columns
 *   2) diagnosticar    → verify schema is now clean
 *   3) recuperar-lote  → promote orphans from transacciones_errores and
 *                        subscripciones_credito into transacciones
 *   4) counts          → final row counts + remaining problem count
 *
 * Each step's output is included in the response so the admin can see
 * exactly what happened. Any step that fails short-circuits with ok=false
 * but the partial report is still returned.
 *
 * Body: { dry_run: bool }
 * Response: { ok, steps: { schema, diagnostico, recuperacion, counts } }
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$body   = adminJsonIn();
$dryRun = !empty($body['dry_run']);

$pdo = getDB();

$report = [
    'ok'      => true,
    'dry_run' => $dryRun,
    'steps'   => [],
    'started_at'  => date('c'),
];

// ═══════════════════════════════════════════════════════════════════════
// STEP 1 — Schema repair (replaces ensureTransaccionesColumns hardcoding)
// ═══════════════════════════════════════════════════════════════════════
$step1 = ['name' => 'reparar-schema', 'tables' => [], 'ok' => true];
$tables = ['transacciones', 'clientes'];

foreach ($tables as $table) {
    $entry = ['table' => $table, 'changes' => [], 'skipped' => 0];
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $entry['error'] = 'SHOW COLUMNS failed: ' . $e->getMessage();
        $step1['tables'][] = $entry;
        $step1['ok'] = false;
        continue;
    }
    foreach ($cols as $c) {
        $name = $c['Field']; $type = $c['Type']; $null = $c['Null'];
        $default = $c['Default']; $extra = $c['Extra'] ?? '';
        if (strpos($extra, 'auto_increment') !== false) { $entry['skipped']++; continue; }
        if ($null === 'YES') { $entry['skipped']++; continue; }
        if ($default !== null) { $entry['skipped']++; continue; }

        $sql = "ALTER TABLE `{$table}` MODIFY COLUMN `{$name}` {$type} NULL DEFAULT NULL";
        $change = ['column' => $name, 'type' => $type];
        if ($dryRun) {
            $change['ok'] = true; $change['dryrun'] = true;
        } else {
            try { $pdo->exec($sql); $change['ok'] = true; }
            catch (Throwable $e) {
                $change['ok'] = false;
                $change['error'] = $e->getMessage();
                $step1['ok'] = false;
            }
        }
        $entry['changes'][] = $change;
    }
    $step1['tables'][] = $entry;
}
$step1['total_changes'] = array_sum(array_map(fn($t) => count($t['changes'] ?? []), $step1['tables']));
$step1['failed'] = 0;
foreach ($step1['tables'] as $t) {
    foreach ($t['changes'] ?? [] as $ch) if (empty($ch['ok'])) $step1['failed']++;
}
$report['steps']['schema'] = $step1;

// ═══════════════════════════════════════════════════════════════════════
// STEP 2 — Diagnostico (verify schema is clean)
// ═══════════════════════════════════════════════════════════════════════
// We distinguish "blocking" tables (transacciones, clientes — tables that
// the recovery INSERT writes to) from "informational" tables (inventario_motos,
// transacciones_errores, subscripciones_credito — scanned for visibility but
// their NOT NULL constraints are legitimate business rules, e.g. inventario_motos
// requires vin/modelo/color). Early-exit only triggers on blocking tables.
$blockingTables = ['transacciones', 'clientes'];
$diagTables     = array_merge($blockingTables, ['transacciones_errores', 'subscripciones_credito', 'inventario_motos']);

$step2 = ['name' => 'diagnosticar-schema', 'problemas' => 0, 'problemas_bloqueantes' => 0, 'tablas' => []];
foreach ($diagTables as $t) {
    $info = ['table' => $t, 'blocking' => in_array($t, $blockingTables, true), 'problemas' => []];
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `{$t}`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $isAuto = strpos($r['Extra'] ?? '', 'auto_increment') !== false;
            if ($r['Null'] === 'NO' && $r['Default'] === null && !$isAuto) {
                $info['problemas'][] = $r['Field'];
                $step2['problemas']++;
                if ($info['blocking']) $step2['problemas_bloqueantes']++;
            }
        }
    } catch (Throwable $e) { $info['error'] = $e->getMessage(); }
    $step2['tablas'][] = $info;
}
// "ok" now means: no problems in blocking tables. Non-blocking tables can
// retain legitimate NOT NULL columns (e.g. inventario_motos.vin).
$step2['ok'] = $step2['problemas_bloqueantes'] === 0;
$report['steps']['diagnostico'] = $step2;

// Early-exit only if BLOCKING tables still have problems. The recovery
// INSERT only touches `transacciones`, so non-blocking issues don't matter.
if (!$dryRun && !$step2['ok']) {
    $report['ok'] = false;
    $report['early_exit'] = 'transacciones/clientes aún tienen ' . $step2['problemas_bloqueantes'] .
        ' columnas problemáticas después del paso 1 — no se intenta la recuperación.';
    $report['finished_at'] = date('c');
    adminLog('reparar_todo_partial', $report);
    adminJsonOut($report);
}

// ═══════════════════════════════════════════════════════════════════════
// STEP 3 — Bulk recovery (promote orphans → transacciones)
// ═══════════════════════════════════════════════════════════════════════
$step3 = [
    'name'      => 'recuperar-lote',
    'processed' => 0, 'recovered' => 0, 'skipped' => 0,
    'errors'    => [], 'actions' => [],
];

// Ensure audit column exists
try { $pdo->exec("ALTER TABLE transacciones_errores ADD COLUMN recuperado_tx_id INT NULL"); }
catch (Throwable $e) { /* already exists */ }

// Discover real column set of `transacciones` so INSERT adapts to the
// actual (legacy) schema instead of assuming Plan H column layout.
$txCols = [];
try {
    foreach ($pdo->query("SHOW COLUMNS FROM transacciones")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (strpos($c['Extra'] ?? '', 'auto_increment') !== false) continue;
        $txCols[] = $c['Field'];
    }
} catch (Throwable $e) {
    $step3['errors'][] = 'SHOW COLUMNS transacciones: ' . $e->getMessage();
}

$insertTx = function(array $r) use ($pdo, $txCols): int {
    // Build INSERT dynamically against the ACTUAL columns present in the
    // table. Unknown fields in $r are dropped; missing fields default to
    // empty-string or 0 so NOT NULL without default (if any still) doesn't
    // bite. This makes the function robust against legacy schema drift.
    $defaults = [
        'pedido'        => (string)time(),
        'referido'      => '',
        'referido_id'   => 0,
        'referido_tipo' => '',
        'caso'          => 1,
        'folio_contrato'=> '',
        'nombre'  => '', 'telefono' => '', 'email' => '',
        'razon'   => '', 'rfc' => '', 'direccion' => '',
        'ciudad'  => '', 'estado' => '', 'cp' => '',
        'e_nombre'    => '', 'e_telefono' => '', 'e_direccion' => '',
        'e_ciudad'    => '', 'e_estado' => '', 'e_cp' => '',
        'modelo'  => '', 'color' => '',
        'tpago'   => 'enganche', 'tenvio' => 'envio',
        'precio'  => '0', 'penvio' => '0', 'total' => '0',
        'freg'    => date('Y-m-d H:i:s'),
        'stripe_pi' => '',
        'asesoria_placas' => 0, 'seguro_qualitas' => 0,
        'punto_id' => '', 'punto_nombre' => '',
        'msi_meses' => 0, 'msi_pago' => 0,
    ];
    $cols = []; $placeholders = []; $vals = [];
    foreach ($txCols as $col) {
        if (!array_key_exists($col, $defaults)) continue;  // skip unknown
        $cols[] = "`{$col}`";
        $placeholders[] = '?';
        $vals[] = $r[$col] ?? $defaults[$col];
    }
    $sql = "INSERT INTO transacciones (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($vals);
    return (int)$pdo->lastInsertId();
};

// 3a — transacciones_errores
try {
    $rows = $pdo->query("
        SELECT * FROM transacciones_errores
        WHERE recuperado_tx_id IS NULL
        ORDER BY freg ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $step3['processed']++;
        $payload = [];
        if (!empty($r['payload'])) {
            $tmp = json_decode($r['payload'], true);
            if (is_array($tmp)) $payload = $tmp;
        }
        if (!empty($r['stripe_pi'])) {
            $chk = $pdo->prepare("SELECT id FROM transacciones WHERE stripe_pi = ? LIMIT 1");
            $chk->execute([$r['stripe_pi']]);
            if ($chk->fetchColumn()) {
                $step3['skipped']++;
                if (!$dryRun) {
                    $pdo->prepare("UPDATE transacciones_errores SET recuperado_tx_id = -1 WHERE id = ?")
                        ->execute([$r['id']]);
                }
                continue;
            }
        }
        $merged = array_merge($payload, [
            'nombre'    => $r['nombre']   ?? '',
            'email'     => $r['email']    ?? '',
            'telefono'  => $r['telefono'] ?? '',
            'modelo'    => $r['modelo']   ?? '',
            'color'     => $r['color']    ?? '',
            'total'     => (string)($r['total'] ?? 0),
            'precio'    => (string)($r['total'] ?? 0),
            'stripe_pi' => $r['stripe_pi'] ?? '',
            'freg'      => $r['freg'] ?? date('Y-m-d H:i:s'),
        ]);
        if ($dryRun) {
            $step3['actions'][] = "would recover error_id={$r['id']} pi={$r['stripe_pi']}";
            $step3['recovered']++;
            continue;
        }
        try {
            $newId = $insertTx($merged);
            $pdo->prepare("UPDATE transacciones_errores SET recuperado_tx_id = ? WHERE id = ?")
                ->execute([$newId, $r['id']]);
            $step3['recovered']++;
            $step3['actions'][] = "recovered error_id={$r['id']} → tx_id={$newId}";
        } catch (Throwable $e) {
            $step3['errors'][] = "error_id={$r['id']}: " . $e->getMessage();
        }
    }
} catch (Throwable $e) {
    $step3['errors'][] = 'scan errores: ' . $e->getMessage();
}

// 3b — subscripciones_credito orphans (active only)
try {
    $rows = $pdo->query("
        SELECT s.id, s.nombre, s.email, s.telefono, s.modelo, s.color,
               s.precio_contado, s.stripe_customer_id, s.freg, s.status
        FROM subscripciones_credito s
        LEFT JOIN transacciones t
               ON t.telefono = s.telefono AND t.modelo = s.modelo
        WHERE t.id IS NULL
          AND s.status IN ('active','activa')
        ORDER BY s.freg ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $step3['processed']++;
        if ($dryRun) {
            $step3['actions'][] = "would recover sub_id={$r['id']} tel={$r['telefono']}";
            $step3['recovered']++;
            continue;
        }
        $merged = [
            'nombre'    => $r['nombre'] ?? '',
            'email'     => $r['email'] ?? '',
            'telefono'  => $r['telefono'] ?? '',
            'modelo'    => $r['modelo'] ?? '',
            'color'     => $r['color'] ?? '',
            'total'     => (string)($r['precio_contado'] ?? 0),
            'precio'    => (string)($r['precio_contado'] ?? 0),
            'stripe_pi' => $r['stripe_customer_id'] ?? '',
            'freg'      => $r['freg'] ?? date('Y-m-d H:i:s'),
            'tpago'     => 'enganche',
            'pedido'    => 'SC-' . $r['id'],
            'folio_contrato' => 'VK-' . date('Ymd', strtotime($r['freg'] ?? 'now'))
                                . '-' . strtoupper(substr($r['nombre'] ?: 'REC', 0, 3)),
        ];
        try {
            $newId = $insertTx($merged);
            $step3['recovered']++;
            $step3['actions'][] = "recovered sub_id={$r['id']} → tx_id={$newId}";
        } catch (Throwable $e) {
            $step3['errors'][] = "sub_id={$r['id']}: " . $e->getMessage();
        }
    }
} catch (Throwable $e) {
    $step3['errors'][] = 'scan subscripciones: ' . $e->getMessage();
}
$step3['ok'] = count($step3['errors']) === 0;
$report['steps']['recuperacion'] = $step3;

// ═══════════════════════════════════════════════════════════════════════
// STEP 4 — Final counts
// ═══════════════════════════════════════════════════════════════════════
$step4 = [];
foreach (['transacciones','transacciones_errores','subscripciones_credito'] as $t) {
    try { $step4[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn(); }
    catch (Throwable $e) { $step4[$t] = null; }
}
try {
    $step4['errores_no_recuperados'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM transacciones_errores WHERE recuperado_tx_id IS NULL"
    )->fetchColumn();
} catch (Throwable $e) { $step4['errores_no_recuperados'] = null; }
$report['steps']['counts'] = $step4;

// Overall ok flag
$report['ok'] = $step1['ok'] && $step2['ok'] && $step3['ok'];
$report['finished_at'] = date('c');
$report['resumen'] = sprintf(
    '%d cambios de schema (%d fallos), %d problemas restantes, %d órdenes recuperadas, %d errores sin recuperar',
    $step1['total_changes'] ?? 0,
    $step1['failed'] ?? 0,
    $step2['problemas'] ?? 0,
    $step3['recovered'] ?? 0,
    $step4['errores_no_recuperados'] ?? 0
);

adminLog('reparar_todo', [
    'dry_run'   => $dryRun,
    'ok'        => $report['ok'],
    'schema'    => $step1['total_changes'] ?? 0,
    'problemas' => $step2['problemas'] ?? 0,
    'recovered' => $step3['recovered'] ?? 0,
]);

adminJsonOut($report);
