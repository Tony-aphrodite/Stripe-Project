<?php
/**
 * Voltika — Actualizar tabla checklist_entrega_v2 con nuevos campos del acta legal
 * Ejecutar UNA SOLA VEZ: ?key=voltika_update_entrega_2026
 */

$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika_update_entrega_2026') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/php/config.php';
header('Content-Type: text/html; charset=UTF-8');

$pdo = getDB();

$alteraciones = [
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS decl_identidad TINYINT DEFAULT 0",
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS decl_validacion TINYINT DEFAULT 0",
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS decl_condicion TINYINT DEFAULT 0",
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS decl_componentes TINYINT DEFAULT 0",
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS decl_funcionamiento TINYINT DEFAULT 0",
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS acta_liberacion TINYINT DEFAULT 0",
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS acepta_terminos TINYINT DEFAULT 0",
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS firma_punto TINYINT DEFAULT 0",
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS dealer_nombre_firma VARCHAR(200)",
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS pdf_acta_url VARCHAR(255)",
];

echo '<h1>Voltika — Actualizar tabla checklist_entrega_v2</h1>';

foreach ($alteraciones as $sql) {
    try {
        $pdo->exec($sql);
        echo '<p style="color:green;">✅ ' . htmlspecialchars($sql) . '</p>';
    } catch (PDOException $e) {
        // "Duplicate column" is OK — means it already exists
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo '<p style="color:#f59e0b;">⚠️ Ya existe: ' . htmlspecialchars($sql) . '</p>';
        } else {
            echo '<p style="color:red;">❌ Error: ' . $e->getMessage() . '</p>';
        }
    }
}

echo '<hr>';
echo '<p style="color:#C62828;">⚠️ Eliminar este script después de ejecutar.</p>';
