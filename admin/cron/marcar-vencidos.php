<?php
/**
 * Cron — Marcar ciclos vencidos
 * Cambia a 'overdue' todos los ciclos pendientes cuya fecha_vencimiento ya pasó.
 */
require_once __DIR__ . '/../php/bootstrap.php';

// ── Auth: validar token cron ────────────────────────────────────────────────
$cronToken = defined('VOLTIKA_CRON_TOKEN') ? VOLTIKA_CRON_TOKEN : (getenv('VOLTIKA_CRON_TOKEN') ?: '');
if ($cronToken) {
    $provided = $_SERVER['HTTP_X_CRON_TOKEN'] ?? ($_GET['token'] ?? '');
    if ($provided !== $cronToken) {
        adminJsonOut(['error' => 'Token inválido'], 403);
    }
}

$pdo = getDB();

$stmt = $pdo->prepare("
    UPDATE ciclos_pago
    SET estado = 'overdue'
    WHERE estado = 'pending' AND fecha_vencimiento < CURDATE()
");
$stmt->execute();
$updated = $stmt->rowCount();

adminLog('cron_marcar_vencidos', ['actualizados' => $updated]);

adminJsonOut([
    'ok'           => true,
    'actualizados' => $updated,
]);
