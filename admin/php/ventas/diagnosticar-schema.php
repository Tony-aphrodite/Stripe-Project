<?php
/**
 * GET — Schema diagnostics for order-related tables
 *
 * Reports the column layout of `transacciones`, `transacciones_errores`,
 * `subscripciones_credito` and `inventario_motos`, flagging columns that
 * are NOT NULL without a DEFAULT (the most common source of silent
 * INSERT failures — e.g. the legacy `referido NOT NULL` constraint that
 * produced 17 orphan orders before Plan A–H was applied).
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$pdo = getDB();

$tables = [
    'transacciones',
    'transacciones_errores',
    'subscripciones_credito',
    'inventario_motos',
    'clientes',
];

$out = [];
foreach ($tables as $t) {
    $info = ['table' => $t, 'exists' => false, 'columns' => [], 'problemas' => []];
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `{$t}`")->fetchAll(PDO::FETCH_ASSOC);
        $info['exists'] = true;
        foreach ($rows as $r) {
            $col = [
                'name'    => $r['Field'],
                'type'    => $r['Type'],
                'null'    => $r['Null'],
                'default' => $r['Default'],
                'extra'   => $r['Extra'],
            ];
            $info['columns'][] = $col;

            // Heuristic: NOT NULL without a default AND not auto_increment is a
            // latent INSERT time bomb unless every caller always sets it.
            $isAuto = strpos($r['Extra'] ?? '', 'auto_increment') !== false;
            if ($r['Null'] === 'NO' && $r['Default'] === null && !$isAuto) {
                $info['problemas'][] = [
                    'columna' => $r['Field'],
                    'motivo'  => 'NOT NULL sin DEFAULT — cualquier INSERT que omita esta columna fallará con SQLSTATE[HY000] 1364.',
                ];
            }
        }
        // Specific known-bad columns
        foreach ($rows as $r) {
            if (in_array($r['Field'], ['referido','referido_id','referido_tipo','punto_id','punto_nombre','msi_meses','msi_pago','caso','folio_contrato'], true)
                && $r['Null'] === 'NO') {
                $info['problemas'][] = [
                    'columna' => $r['Field'],
                    'motivo'  => "Columna opcional con NOT NULL — causa error 1048 cuando el cliente no envía valor. Debe ser NULL.",
                ];
            }
        }
    } catch (Throwable $e) {
        $info['error'] = $e->getMessage();
    }
    $out[] = $info;
}

// Row counts for health snapshot
$counts = [];
foreach (['transacciones', 'transacciones_errores', 'subscripciones_credito'] as $t) {
    try {
        $c = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        $counts[$t] = $c;
    } catch (Throwable $e) {
        $counts[$t] = null;
    }
}

// Recent errores for quick context
$recentErrors = [];
try {
    $st = $pdo->query("SELECT id, error_msg, freg FROM transacciones_errores ORDER BY freg DESC LIMIT 10");
    $recentErrors = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Problem summary
$totalProblems = 0;
foreach ($out as $t) { $totalProblems += count($t['problemas'] ?? []); }

adminJsonOut([
    'ok'              => true,
    'generated_at'    => date('c'),
    'tablas'          => $out,
    'counts'          => $counts,
    'errores_recientes' => $recentErrors,
    'total_problemas' => $totalProblems,
    'recomendacion'   => $totalProblems > 0
        ? 'Ejecuta confirmar-orden.php una vez (cualquier request) — la función ensureTransaccionesColumns() hará MODIFY COLUMN para relajar las restricciones NOT NULL detectadas.'
        : 'Schema OK — no se detectaron columnas NOT NULL problemáticas.',
]);
