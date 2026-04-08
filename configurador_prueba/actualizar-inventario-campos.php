<?php
/**
 * Voltika — Agregar campos nuevos a inventario_motos
 * Ejecutar UNA SOLA VEZ: ?key=voltika_inv_campos_2026
 */

$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika_inv_campos_2026') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/php/config.php';
header('Content-Type: text/html; charset=UTF-8');

$pdo = getDB();

$alteraciones = [
    "ALTER TABLE inventario_motos ADD COLUMN IF NOT EXISTS anio_modelo VARCHAR(10)",
    "ALTER TABLE inventario_motos ADD COLUMN IF NOT EXISTS potencia VARCHAR(100)",
    "ALTER TABLE inventario_motos ADD COLUMN IF NOT EXISTS descripcion TEXT",
    "ALTER TABLE inventario_motos ADD COLUMN IF NOT EXISTS accesorios TEXT",
    "ALTER TABLE inventario_motos ADD COLUMN IF NOT EXISTS num_pedimento VARCHAR(100)",
    "ALTER TABLE inventario_motos ADD COLUMN IF NOT EXISTS fecha_ingreso_pais DATE",
    "ALTER TABLE inventario_motos ADD COLUMN IF NOT EXISTS aduana VARCHAR(200)",
    "ALTER TABLE inventario_motos ADD COLUMN IF NOT EXISTS hecho_en VARCHAR(100) DEFAULT 'China'",
];

echo '<h1>Voltika — Agregar campos a inventario_motos</h1>';

foreach ($alteraciones as $sql) {
    try {
        $pdo->exec($sql);
        echo '<p style="color:green;">✅ ' . htmlspecialchars($sql) . '</p>';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo '<p style="color:#f59e0b;">⚠️ Ya existe</p>';
        } else {
            echo '<p style="color:red;">❌ ' . $e->getMessage() . '</p>';
        }
    }
}

echo '<hr><p style="color:#C62828;">⚠️ Eliminar este script después de ejecutar.</p>';
