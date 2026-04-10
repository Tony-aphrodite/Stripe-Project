<?php
/**
 * GET — List consultas_buro for admin panel
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$pdo = getDB();

// Ensure table exists with all columns
$pdo->exec("CREATE TABLE IF NOT EXISTS consultas_buro (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(200), apellido_paterno VARCHAR(100), apellido_materno VARCHAR(100),
    fecha_nacimiento VARCHAR(20), cp VARCHAR(10),
    score INT, pago_mensual DECIMAL(12,2), dpd90_flag TINYINT(1), dpd_max INT,
    num_cuentas INT, folio_consulta VARCHAR(100),
    tipo_consulta VARCHAR(50), fecha_aprobacion_consulta VARCHAR(20), hora_aprobacion_consulta VARCHAR(20),
    fecha_consulta VARCHAR(20), hora_consulta VARCHAR(20), usuario_api VARCHAR(100),
    ingreso_nip_ciec VARCHAR(10), respuesta_leyenda VARCHAR(10), aceptacion_tyc VARCHAR(10),
    freg DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

try {
    $total = (int) $pdo->query("SELECT COUNT(*) FROM consultas_buro")->fetchColumn();
    $conScore = (int) $pdo->query("SELECT COUNT(*) FROM consultas_buro WHERE score IS NOT NULL AND score > 0")->fetchColumn();

    $rows = $pdo->query("
        SELECT id, folio_consulta, nombre, apellido_paterno, apellido_materno,
               score, tipo_consulta, fecha_aprobacion_consulta, hora_aprobacion_consulta,
               fecha_consulta, hora_consulta, usuario_api,
               ingreso_nip_ciec, respuesta_leyenda, aceptacion_tyc, freg
        FROM consultas_buro
        ORDER BY freg DESC
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);

    adminJsonOut([
        'ok' => true,
        'total' => $total,
        'conScore' => $conScore,
        'rows' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('buro/listar error: ' . $e->getMessage());
    adminJsonOut(['ok' => true, 'total' => 0, 'conScore' => 0, 'rows' => [], 'warn' => $e->getMessage()]);
}
