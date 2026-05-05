<?php
/**
 * Diagnostic — runs the same SELECT as listar.php and surfaces the
 * actual error if it fails. Token-gated so we don't leak data, but no
 * admin session required (so it works even when admin auth is the
 * blocker).
 *
 * Usage:
 *   https://voltika.mx/admin/php/ventas/diag-listar.php?key=voltika-diag-2026
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// Capture any fatal so the page never silently returns empty.
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok'    => false,
            'stage' => 'shutdown',
            'error' => 'php_fatal: ' . $err['message'],
            'file'  => basename($err['file'] ?? ''),
            'line'  => $err['line'] ?? 0,
        ]);
    }
});

$key = $_GET['key'] ?? '';
if ($key !== 'voltika-diag-2026') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$result = ['ok' => true, 'steps' => []];

// Step 1: load config + get DB
try {
    require_once __DIR__ . '/../../../configurador/php/config.php';
    $pdo = getDB();
    $result['steps'][] = ['step' => 'connect', 'ok' => true];
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'stage' => 'connect', 'error' => $e->getMessage()]);
    exit;
}

// Step 2: list columns of transacciones + inventario_motos
try {
    $tCols = array_column($pdo->query("SHOW COLUMNS FROM transacciones")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $mCols = array_column($pdo->query("SHOW COLUMNS FROM inventario_motos")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $result['steps'][] = [
        'step'                  => 'schema',
        'transacciones_cols'    => $tCols,
        'inventario_motos_cols' => $mCols,
        'has_t_pedido_corto'    => in_array('pedido_corto', $tCols, true),
        'has_t_seguimiento'     => in_array('seguimiento', $tCols, true),
        'has_t_stripe_pi'       => in_array('stripe_pi', $tCols, true),
        'has_m_stripe_pi'       => in_array('stripe_pi', $mCols, true),
        'has_m_pedido_num'      => in_array('pedido_num', $mCols, true),
        'has_m_activo'          => in_array('activo', $mCols, true),
    ];
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'stage' => 'schema', 'error' => $e->getMessage()]);
    exit;
}

// Step 3: try the simplified SELECT (the same one listar.php builds)
try {
    $stmt = $pdo->query("
        SELECT t.id, t.pedido, t.pedido_corto, t.nombre,
               m.id AS moto_id, m.vin_display AS moto_vin
        FROM transacciones t
        LEFT JOIN inventario_motos m
               ON m.id = (
                   SELECT m2.id FROM inventario_motos m2
                    WHERE m2.activo = 1
                      AND (
                          m2.pedido_num = CONCAT('VK-', t.pedido)
                          OR m2.pedido_num = t.pedido_corto
                      )
                    ORDER BY m2.id DESC
                    LIMIT 1
               )
        ORDER BY t.freg DESC
        LIMIT 5
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result['steps'][] = ['step' => 'select_simplified', 'ok' => true, 'rows' => count($rows), 'sample' => array_slice($rows, 0, 2)];
} catch (Throwable $e) {
    echo json_encode([
        'ok'    => false,
        'stage' => 'select_simplified',
        'error' => $e->getMessage(),
        'sql_state' => $e instanceof PDOException ? $e->getCode() : null,
    ]);
    exit;
}

// Step 4: check for the seguro/placas tracking columns the JOIN references
try {
    $extras = ['placas_estado', 'seguro_estado', 'last_reminder_at', 'reminders_sent_count', 'seguro_cotizacion_archivo'];
    $missing = array_filter($extras, fn($c) => !in_array($c, $tCols, true));
    $result['steps'][] = ['step' => 'extras', 'missing_columns' => array_values($missing)];
} catch (Throwable $e) {}

echo json_encode($result, JSON_PRETTY_PRINT);
