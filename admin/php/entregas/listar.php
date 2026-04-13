<?php
/**
 * GET — List delivery time configurations per model/city
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$pdo = getDB();

// Ensure table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tiempos_entrega (
        id INT AUTO_INCREMENT PRIMARY KEY,
        modelo VARCHAR(120) NOT NULL DEFAULT '',
        ciudad VARCHAR(120) NOT NULL DEFAULT '',
        dias_estimados INT DEFAULT 7,
        disponible_inmediato TINYINT(1) DEFAULT 0,
        notas VARCHAR(500) DEFAULT '',
        activo TINYINT(1) DEFAULT 1,
        freg DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_modelo_ciudad (modelo, ciudad)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$rows = [];
try {
    $rows = $pdo->query("SELECT * FROM tiempos_entrega ORDER BY modelo, ciudad")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('entregas/listar: ' . $e->getMessage()); }

// Get distinct modelos and ciudades for dropdowns
$modelos = [];
try { $modelos = array_column($pdo->query("SELECT DISTINCT modelo FROM inventario_motos WHERE modelo IS NOT NULL AND modelo<>'' ORDER BY modelo")->fetchAll(PDO::FETCH_ASSOC), 'modelo'); } catch (Throwable $e) {}

adminJsonOut(['ok' => true, 'tiempos' => $rows, 'modelos' => $modelos]);
