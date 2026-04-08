<?php
/**
 * Voltika — Add Stripe payment verification columns to inventario_motos
 * Run once: ?key=voltika_stripe_campos_2026
 */
$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika_stripe_campos_2026') { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/php/config.php';
header('Content-Type: text/html; charset=UTF-8');
$pdo = getDB();

$sqls = [
    "ALTER TABLE inventario_motos ADD COLUMN IF NOT EXISTS stripe_pi VARCHAR(100)",
    "ALTER TABLE inventario_motos ADD COLUMN IF NOT EXISTS stripe_payment_status VARCHAR(30) DEFAULT 'unknown'",
    "ALTER TABLE inventario_motos ADD COLUMN IF NOT EXISTS stripe_verified_at DATETIME",
    "ALTER TABLE inventario_motos ADD COLUMN IF NOT EXISTS cedis_origen VARCHAR(200)",
    "ALTER TABLE inventario_motos ADD COLUMN IF NOT EXISTS transaccion_id INT",
    // entregas table
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS cliente_acta_firmada TINYINT DEFAULT 0",
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS cliente_acta_fecha DATETIME",
    "ALTER TABLE checklist_entrega_v2 ADD COLUMN IF NOT EXISTS cliente_checklist_completado TINYINT DEFAULT 0",
    // dealer_usuarios - add 'cedis' role
    "ALTER TABLE dealer_usuarios MODIFY COLUMN rol ENUM('dealer','admin','cedis') DEFAULT 'dealer'",
];

echo '<h1>Voltika — Stripe + Panel columns</h1>';
foreach ($sqls as $sql) {
    try {
        $pdo->exec($sql);
        echo '<p style="color:green;">✅ OK</p>';
    } catch (PDOException $e) {
        echo '<p style="color:#f59e0b;">⚠️ ' . substr($e->getMessage(), 0, 80) . '</p>';
    }
}
echo '<hr><p style="color:#C62828;">⚠️ Eliminar después de ejecutar.</p>';
