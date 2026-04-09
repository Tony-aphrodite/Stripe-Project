<?php
/**
 * Voltika Admin — List consultas_buro (JSON)
 */
header('Content-Type: application/json; charset=utf-8');
session_start();
if (empty($_SESSION['VOLTIKA_ADMIN'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

require_once __DIR__ . '/../../configurador_prueba/php/config.php';

try {
    $pdo = getDB();

    // Ensure table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS consultas_buro (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(200), apellido_paterno VARCHAR(100), apellido_materno VARCHAR(100),
        fecha_nacimiento VARCHAR(20), cp VARCHAR(10),
        score INT, pago_mensual DECIMAL(12,2), dpd90_flag TINYINT(1), dpd_max INT,
        num_cuentas INT, folio_consulta VARCHAR(100),
        freg DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $total = (int) $pdo->query("SELECT COUNT(*) FROM consultas_buro")->fetchColumn();

    $rows = $pdo->query("
        SELECT id, folio_consulta, nombre, apellido_paterno, apellido_materno,
               score, tipo_consulta, fecha_aprobacion_consulta, hora_aprobacion_consulta,
               fecha_consulta, hora_consulta, usuario_api,
               ingreso_nip_ciec, respuesta_leyenda, aceptacion_tyc, freg
        FROM consultas_buro
        ORDER BY freg DESC
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'total' => $total, 'rows' => $rows]);

} catch (Throwable $e) {
    echo json_encode(['ok' => true, 'total' => 0, 'rows' => [], 'warn' => $e->getMessage()]);
}
