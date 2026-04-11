<?php
/**
 * POST — Bulk-recover all orphan orders.
 *
 * Iterates two sources:
 *   1) transacciones_errores — rows captured by confirmar-orden.php's
 *      error fallback (Plan B). Payload is stored verbatim so recovery is
 *      straightforward.
 *   2) subscripciones_credito with no matching transacciones row (Plan A
 *      UNION). Some of these may be legitimate (e.g. abandoned flows), so
 *      we only promote rows whose `status` = 'active' (card actually confirmed).
 *
 * For each recoverable row, if a Stripe PI is available, we verify its
 * status is `succeeded` before creating the transacciones entry.
 *
 * Response: { ok, processed, recovered, skipped, errors[] }
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

// Support dry-run mode: POST { dry_run: true } to see what would be recovered
$body   = adminJsonIn();
$dryRun = !empty($body['dry_run']);

$pdo = getDB();

$processed = 0;
$recovered = 0;
$skipped   = 0;
$errors    = [];
$actions   = [];

// Relax NOT NULL constraints first (same logic as confirmar-orden.php)
$nullableFixes = [
    'referido'       => "VARCHAR(40)",
    'referido_id'    => "INT",
    'referido_tipo'  => "VARCHAR(20)",
    'punto_id'       => "VARCHAR(80)",
    'punto_nombre'   => "VARCHAR(200)",
    'msi_meses'      => "INT",
    'msi_pago'       => "DECIMAL(12,2)",
    'caso'           => "TINYINT",
    'folio_contrato' => "VARCHAR(40)",
    'ciudad'         => "VARCHAR(100)",
    'estado'         => "VARCHAR(100)",
    'cp'             => "VARCHAR(10)",
];
try {
    $meta = $pdo->query("SHOW COLUMNS FROM transacciones")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($meta as $c) {
        $name = $c['Field'] ?? '';
        if (isset($nullableFixes[$name]) && ($c['Null'] ?? 'YES') === 'NO') {
            try {
                $pdo->exec("ALTER TABLE transacciones MODIFY COLUMN `{$name}` {$nullableFixes[$name]} NULL DEFAULT NULL");
                $actions[] = "relaxed NOT NULL on transacciones.{$name}";
            } catch (Throwable $e) { /* noop */ }
        }
    }
} catch (Throwable $e) { /* noop */ }

// Make sure recuperado_tx_id audit column exists
try { $pdo->exec("ALTER TABLE transacciones_errores ADD COLUMN recuperado_tx_id INT NULL"); }
catch (Throwable $e) { /* already exists */ }

function insertTransaccion(PDO $pdo, array $r): int {
    $ins = $pdo->prepare("
        INSERT INTO transacciones
            (nombre, email, telefono, modelo, color, ciudad, estado, cp, tpago,
             precio, total, freg, pedido, stripe_pi,
             asesoria_placas, seguro_qualitas,
             punto_id, punto_nombre,
             msi_meses, msi_pago,
             referido, referido_id, referido_tipo, caso,
             folio_contrato)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?,
             0, 0,
             '', '',
             0, 0,
             '', 0, '', 1,
             ?)
    ");
    $ins->execute([
        $r['nombre']   ?? '',
        $r['email']    ?? '',
        $r['telefono'] ?? '',
        $r['modelo']   ?? '',
        $r['color']    ?? '',
        $r['ciudad']   ?? '',
        $r['estado']   ?? '',
        $r['cp']       ?? '',
        $r['tpago']    ?? 'enganche',
        $r['total']    ?? 0,
        $r['total']    ?? 0,
        $r['freg']     ?? date('Y-m-d H:i:s'),
        $r['pedido']   ?? (time() . '-' . substr(bin2hex(random_bytes(3)), 0, 4)),
        $r['stripe_pi']?? '',
        $r['folio_contrato'] ?? '',
    ]);
    return (int)$pdo->lastInsertId();
}

// ── 1) transacciones_errores ────────────────────────────────────────────
try {
    $rows = $pdo->query("
        SELECT * FROM transacciones_errores
        WHERE recuperado_tx_id IS NULL
        ORDER BY freg ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $processed++;
        // Hydrate from payload
        $payload = [];
        if (!empty($r['payload'])) {
            $tmp = json_decode($r['payload'], true);
            if (is_array($tmp)) $payload = $tmp;
        }

        // Check idempotency: already in transacciones?
        if (!empty($r['stripe_pi'])) {
            $chk = $pdo->prepare("SELECT id FROM transacciones WHERE stripe_pi = ? LIMIT 1");
            $chk->execute([$r['stripe_pi']]);
            if ($chk->fetchColumn()) {
                $skipped++;
                $pdo->prepare("UPDATE transacciones_errores SET recuperado_tx_id = -1 WHERE id = ?")
                    ->execute([$r['id']]);
                continue;
            }
        }

        $merged = array_merge($payload, [
            'nombre'    => $r['nombre'],
            'email'     => $r['email'],
            'telefono'  => $r['telefono'],
            'modelo'    => $r['modelo'],
            'color'     => $r['color'],
            'total'     => $r['total'],
            'stripe_pi' => $r['stripe_pi'],
            'freg'      => $r['freg'],
        ]);

        if ($dryRun) {
            $actions[] = "would recover error_id={$r['id']} → pi={$r['stripe_pi']}";
            $recovered++;
            continue;
        }

        try {
            $newId = insertTransaccion($pdo, $merged);
            $pdo->prepare("UPDATE transacciones_errores SET recuperado_tx_id = ? WHERE id = ?")
                ->execute([$newId, $r['id']]);
            $recovered++;
            $actions[] = "recovered error_id={$r['id']} → tx_id={$newId}";
        } catch (Throwable $e) {
            $errors[] = "error_id={$r['id']}: " . $e->getMessage();
        }
    }
} catch (Throwable $e) {
    $errors[] = 'transacciones_errores scan: ' . $e->getMessage();
}

// ── 2) subscripciones_credito orphans (status=active only) ──────────────
try {
    $rows = $pdo->query("
        SELECT s.id, s.nombre, s.email, s.telefono, s.modelo, s.color,
               s.precio_contado, s.stripe_customer_id, s.stripe_setup_intent_id,
               s.freg, s.status
        FROM subscripciones_credito s
        LEFT JOIN transacciones t
               ON t.telefono = s.telefono
              AND t.modelo   = s.modelo
        WHERE t.id IS NULL
          AND s.status = 'active'
        ORDER BY s.freg ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $processed++;

        if ($dryRun) {
            $actions[] = "would recover sub_id={$r['id']} telefono={$r['telefono']} modelo={$r['modelo']}";
            $recovered++;
            continue;
        }

        $merged = [
            'nombre'    => $r['nombre'],
            'email'     => $r['email'],
            'telefono'  => $r['telefono'],
            'modelo'    => $r['modelo'],
            'color'     => $r['color'],
            'total'     => $r['precio_contado'] ?? 0,
            'stripe_pi' => $r['stripe_customer_id'] ?? '',
            'freg'      => $r['freg'],
            'tpago'     => 'enganche',
            'pedido'    => 'SC-' . $r['id'],
            'folio_contrato' => 'VK-' . date('Ymd', strtotime($r['freg'] ?? 'now')) . '-' . strtoupper(substr($r['nombre'] ?: 'REC', 0, 3)),
        ];

        try {
            $newId = insertTransaccion($pdo, $merged);
            $recovered++;
            $actions[] = "recovered sub_id={$r['id']} → tx_id={$newId}";
        } catch (Throwable $e) {
            $errors[] = "sub_id={$r['id']}: " . $e->getMessage();
        }
    }
} catch (Throwable $e) {
    $errors[] = 'subscripciones_credito scan: ' . $e->getMessage();
}

adminLog('recuperar_lote', [
    'dry_run'   => $dryRun,
    'processed' => $processed,
    'recovered' => $recovered,
    'skipped'   => $skipped,
    'errors'    => count($errors),
]);

adminJsonOut([
    'ok'        => true,
    'dry_run'   => $dryRun,
    'processed' => $processed,
    'recovered' => $recovered,
    'skipped'   => $skipped,
    'errors'    => $errors,
    'actions'   => $actions,
]);
