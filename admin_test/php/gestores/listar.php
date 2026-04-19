<?php
/**
 * GET — List gestores de placas per estado.
 * Returns all 32 MX states, even if empty, so the admin sees coverage gaps.
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$pdo = getDB();

// Ensure schema
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS gestores_placas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        estado_mx VARCHAR(100) NOT NULL,
        nombre VARCHAR(200) NOT NULL,
        telefono VARCHAR(30) DEFAULT NULL,
        email VARCHAR(200) DEFAULT NULL,
        whatsapp VARCHAR(30) DEFAULT NULL,
        notas TEXT DEFAULT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        freg DATETIME DEFAULT CURRENT_TIMESTAMP,
        fmod DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_estado (estado_mx, activo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) { error_log('gestores schema: ' . $e->getMessage()); }

// 32 estados de México (canonical list)
$estados = [
    'Aguascalientes','Baja California','Baja California Sur','Campeche','Chiapas',
    'Chihuahua','Ciudad de México','Coahuila','Colima','Durango',
    'Guanajuato','Guerrero','Hidalgo','Jalisco','México','Michoacán',
    'Morelos','Nayarit','Nuevo León','Oaxaca','Puebla','Querétaro',
    'Quintana Roo','San Luis Potosí','Sinaloa','Sonora','Tabasco',
    'Tamaulipas','Tlaxcala','Veracruz','Yucatán','Zacatecas',
];

// Fetch all existing gestores grouped by estado
$rows = $pdo->query("SELECT * FROM gestores_placas ORDER BY estado_mx, activo DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
$byEstado = [];
foreach ($rows as $r) {
    $byEstado[$r['estado_mx']][] = $r;
}

// Build canonical response — one entry per estado_mx, with its gestores (if any)
$out = [];
foreach ($estados as $est) {
    $out[] = [
        'estado'   => $est,
        'gestores' => $byEstado[$est] ?? [],
    ];
}

adminJsonOut(['ok' => true, 'estados' => $out]);
