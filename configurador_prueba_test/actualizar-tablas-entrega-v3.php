<?php
/**
 * Voltika — Actualizar checklist_entrega_v2 con campos para acta de contado/MSI/SPEI/OXXO
 * Ejecutar UNA SOLA VEZ: ?key=voltika_update_entrega_v3
 */

$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika_update_entrega_v3') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/php/config.php';
header('Content-Type: text/html; charset=UTF-8');

$pdo = getDB();

$alteraciones = [
    // Payment declaration
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS decl_pago_total TINYINT DEFAULT 0",
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS metodo_pago_acta VARCHAR(50)",

    // Cumplimiento operación comercial
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS cumpl_pago_confirmado TINYINT DEFAULT 0",
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS cumpl_entrega_total TINYINT DEFAULT 0",
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS cumpl_sin_obligacion TINYINT DEFAULT 0",
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS cumpl_op_concluida TINYINT DEFAULT 0",

    // Transferencia de posesión
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS transf_posesion TINYINT DEFAULT 0",
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS transf_uso_responsable TINYINT DEFAULT 0",
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS transf_voltika_libre TINYINT DEFAULT 0",

    // Renuncia a desconocimiento de cargos
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS renuncia_pago_voluntario TINYINT DEFAULT 0",
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS renuncia_cumplimiento TINYINT DEFAULT 0",
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS renuncia_contracargos TINYINT DEFAULT 0",
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS renuncia_registros_prueba TINYINT DEFAULT 0",

    // Evidencia de entrega
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS evidencia_foto_cliente TINYINT DEFAULT 0",
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS evidencia_foto_vin TINYINT DEFAULT 0",
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS evidencia_foto_entrega TINYINT DEFAULT 0",
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS evidencia_video TINYINT DEFAULT 0",

    // Extra firma fields
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS telefono_validado TINYINT DEFAULT 0",
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS punto_taller VARCHAR(200)",

    // Tipo de acta
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS tipo_acta ENUM('credito','contado') DEFAULT 'credito'",
];

echo '<h1>Voltika — Actualizar tabla para acta contado/MSI</h1>';

foreach ($alteraciones as $sql) {
    try {
        $pdo->exec($sql);
        echo '<p style="color:green;">✅ ' . htmlspecialchars($sql) . '</p>';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo '<p style="color:#f59e0b;">⚠️ Ya existe: ' . htmlspecialchars(substr($sql, 0, 80)) . '...</p>';
        } else {
            echo '<p style="color:red;">❌ ' . $e->getMessage() . '</p>';
        }
    }
}

echo '<hr><p style="color:#C62828;">⚠️ Eliminar este script después de ejecutar.</p>';
