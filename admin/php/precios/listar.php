<?php
/**
 * GET — List pricing conditions per model
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$pdo = getDB();

// Ensure table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS precios_condiciones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        modelo_id INT NOT NULL,
        enganche_min DECIMAL(12,2) DEFAULT 0,
        enganche_max DECIMAL(12,2) DEFAULT 0,
        pago_semanal DECIMAL(12,2) DEFAULT 0,
        tasa_interna DECIMAL(6,4) DEFAULT 0,
        plazo_semanas INT DEFAULT 52,
        msi_opciones JSON DEFAULT NULL,
        promocion_nombre VARCHAR(200) DEFAULT '',
        promocion_activa TINYINT(1) DEFAULT 0,
        promocion_descuento DECIMAL(12,2) DEFAULT 0,
        freg DATETIME DEFAULT CURRENT_TIMESTAMP,
        factualizado DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_modelo (modelo_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$rows = [];
try {
    $rows = $pdo->query("
        SELECT pc.*, m.nombre as modelo_nombre
        FROM precios_condiciones pc
        LEFT JOIN modelos m ON pc.modelo_id = m.id
        ORDER BY m.nombre ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('precios/listar: ' . $e->getMessage());
}

// Get models list for dropdown
$modelos = [];
try {
    $modelos = $pdo->query("SELECT id, nombre FROM modelos WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

adminJsonOut(['ok' => true, 'precios' => $rows, 'modelos' => $modelos]);
