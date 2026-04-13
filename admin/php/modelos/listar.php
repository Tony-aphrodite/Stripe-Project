<?php
/**
 * GET — List all models with full details
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador','dealer']);

$pdo = getDB();

// Ensure modelos table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS modelos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(120) NOT NULL,
        categoria VARCHAR(80) DEFAULT '',
        precio_contado DECIMAL(12,2) DEFAULT 0,
        precio_financiado DECIMAL(12,2) DEFAULT 0,
        costo DECIMAL(12,2) DEFAULT 0,
        bateria VARCHAR(120) DEFAULT '',
        velocidad VARCHAR(60) DEFAULT '',
        autonomia VARCHAR(60) DEFAULT '',
        torque VARCHAR(60) DEFAULT '',
        imagen_url VARCHAR(500) DEFAULT '',
        activo TINYINT(1) DEFAULT 1,
        freg DATETIME DEFAULT CURRENT_TIMESTAMP,
        factualizado DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$rows = [];
try {
    $rows = $pdo->query("SELECT * FROM modelos ORDER BY activo DESC, nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('modelos/listar: ' . $e->getMessage());
}

// Auto-populate from inventory if modelos table is empty
if (empty($rows)) {
    try {
        $distinct = $pdo->query("SELECT DISTINCT modelo FROM inventario_motos WHERE modelo IS NOT NULL AND modelo != '' ORDER BY modelo")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($distinct as $nombre) {
            $pdo->prepare("INSERT IGNORE INTO modelos (nombre) VALUES (?)")->execute([$nombre]);
        }
        if (!empty($distinct)) {
            $rows = $pdo->query("SELECT * FROM modelos ORDER BY activo DESC, nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {}
}

// Also get inventory count per model
$stock = [];
try {
    $st = $pdo->query("SELECT modelo, COUNT(*) as total,
        SUM(estado IN ('recibida','lista_para_entrega')) as disponible
        FROM inventario_motos WHERE modelo IS NOT NULL GROUP BY modelo");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $stock[$s['modelo']] = $s;
    }
} catch (Throwable $e) {}

foreach ($rows as &$r) {
    $r['stock_total'] = (int)($stock[$r['nombre']]['total'] ?? 0);
    $r['stock_disponible'] = (int)($stock[$r['nombre']]['disponible'] ?? 0);
}

adminJsonOut(['ok' => true, 'modelos' => $rows]);
