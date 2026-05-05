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

// Step 5: run the EXACT full SELECT from listar.php to reproduce the 500
try {
    $hasTracking   = in_array('placas_estado', $tCols, true);
    $hasCotiz      = in_array('seguro_cotizacion_archivo', $tCols, true);
    $hasSeguimiento= in_array('seguimiento', $tCols, true);

    $trackingSelect = $hasTracking
        ? ", t.placas_estado, t.placas_gestor_nombre, t.placas_gestor_telefono, t.placas_nota,
             t.seguro_estado, t.seguro_cotizacion, t.seguro_poliza, t.seguro_nota"
        : "";
    $cotizSelect = $hasCotiz
        ? ", t.seguro_cotizacion_archivo, t.seguro_cotizacion_mime, t.seguro_cotizacion_size, t.seguro_cotizacion_subido,
             t.placas_cotizacion_archivo, t.placas_cotizacion_mime, t.placas_cotizacion_size, t.placas_cotizacion_subido"
        : "";
    $seguimientoFilter = $hasSeguimiento
        ? "WHERE (t.seguimiento IS NULL OR t.seguimiento <> 'archivado')"
        : "";

    $start = microtime(true);
    $sql = "
        SELECT t.id, t.pedido, t.pedido_corto, t.nombre, t.email, t.telefono,
               t.modelo, t.color, t.tpago, t.total, t.stripe_pi, t.freg,
               t.punto_id, t.punto_nombre, t.ciudad, t.estado, t.cp, t.folio_contrato,
               t.fecha_estimada_entrega,
               t.asesoria_placas, t.seguro_qualitas,
               COALESCE(t.last_reminder_at, NULL) AS last_reminder_at,
               COALESCE(t.reminders_sent_count, 0) AS reminders_sent_count
               $trackingSelect
               $cotizSelect,
               t.pago_estado AS tx_pago_estado,
               m.id AS moto_id, m.vin_display AS moto_vin, m.estado AS moto_estado,
               m.pago_estado,
               m.punto_voltika_id AS moto_punto_id,
               DATEDIFF(CURDATE(), COALESCE(m.fecha_estado, m.freg)) AS moto_dias_en_estado,
               pm.nombre    AS punto_moto_nombre,
               pm.ciudad    AS punto_moto_ciudad,
               pm.direccion AS punto_moto_direccion,
               (SELECT e.estado FROM envios e WHERE e.moto_id = m.id ORDER BY e.id DESC LIMIT 1) AS envio_estado,
               (SELECT e.carrier FROM envios e WHERE e.moto_id = m.id ORDER BY e.id DESC LIMIT 1) AS envio_carrier,
               (SELECT e.tracking_number FROM envios e WHERE e.moto_id = m.id ORDER BY e.id DESC LIMIT 1) AS envio_tracking,
               (SELECT e.fecha_estimada_llegada FROM envios e WHERE e.moto_id = m.id ORDER BY e.id DESC LIMIT 1) AS envio_eta,
               p.direccion AS punto_direccion, p.colonia AS punto_colonia,
               p.ciudad AS punto_ciudad, p.estado AS punto_estado,
               p.cp AS punto_cp, p.telefono AS punto_telefono,
               (SELECT fc.id FROM firmas_contratos fc
                  WHERE (fc.telefono <> '' AND fc.telefono = t.telefono)
                     OR (fc.email <> '' AND fc.email = t.email)
                  ORDER BY fc.id DESC LIMIT 1) AS firma_id,
               (SELECT fc.freg FROM firmas_contratos fc
                  WHERE (fc.telefono <> '' AND fc.telefono = t.telefono)
                     OR (fc.email <> '' AND fc.email = t.email)
                  ORDER BY fc.id DESC LIMIT 1) AS firma_freg
        FROM transacciones t
        LEFT JOIN inventario_motos m
               ON m.id = (
                   SELECT m2.id FROM inventario_motos m2
                    WHERE m2.activo = 1
                      AND m2.vin NOT REGEXP '^VK-[A-Z0-9]+-[0-9]+-[a-f0-9]+'
                      AND (
                          m2.pedido_num = CONCAT('VK-', t.pedido)
                          OR m2.pedido_num = t.pedido_corto
                      )
                    ORDER BY m2.fmod DESC
                    LIMIT 1
               )
        LEFT JOIN puntos_voltika pm ON pm.id = m.punto_voltika_id
        LEFT JOIN puntos_voltika p
               ON p.id = (
                   SELECT p2.id FROM puntos_voltika p2
                   WHERE p2.nombre = t.punto_nombre AND p2.activo = 1
                   ORDER BY p2.id ASC
                   LIMIT 1
               )
        $seguimientoFilter
        ORDER BY t.freg DESC
        LIMIT 100
    ";
    $st = $pdo->query($sql);
    $full = $st->fetchAll(PDO::FETCH_ASSOC);
    $elapsed = round((microtime(true) - $start) * 1000);
    $result['steps'][] = ['step' => 'select_full_listar', 'ok' => true, 'rows' => count($full), 'elapsed_ms' => $elapsed];
} catch (Throwable $e) {
    echo json_encode([
        'ok'        => false,
        'stage'     => 'select_full_listar',
        'error'     => $e->getMessage(),
        'sql_state' => $e instanceof PDOException ? $e->getCode() : null,
    ]);
    exit;
}

// Step 6: try the bootstrap.php that listar.php requires (catches admin-auth fatals)
try {
    if (file_exists(__DIR__ . '/../bootstrap.php')) {
        // Include in a separate scope so any auth side-effects don't kill us.
        $boot_ok = (function() {
            ob_start();
            try { require __DIR__ . '/../bootstrap.php'; }
            catch (Throwable $e) { ob_end_clean(); return ['ok'=>false, 'err'=>$e->getMessage()]; }
            ob_end_clean();
            return ['ok'=>true,
                    'has_adminRequireAuth' => function_exists('adminRequireAuth'),
                    'has_adminJsonOut'     => function_exists('adminJsonOut'),
                    'has_adminLog'         => function_exists('adminLog')];
        })();
        $result['steps'][] = ['step' => 'bootstrap', 'ok' => $boot_ok];
    }
} catch (Throwable $e) {
    $result['steps'][] = ['step' => 'bootstrap', 'ok' => false, 'error' => $e->getMessage()];
}

echo json_encode($result, JSON_PRETTY_PRINT);
