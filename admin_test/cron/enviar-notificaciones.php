<?php
/**
 * Cron — Enviar notificaciones pendientes (delayed)
 * ──────────────────────────────────────────────────
 * Picks up rows from `pending_notifications` whose send_after has passed
 * and sends them via voltikaNotify(). Used for the 5-minute-delay portal
 * messages (MSG 1B/1C/1D) scheduled by confirmar-orden.php and stripe-webhook.php.
 *
 * Recommended schedule: every 1 minute (cron: * * * * *)
 * Processes max 50 per run to avoid timeout.
 */
require_once __DIR__ . '/../php/bootstrap.php';

// ── voltika-notify.php lives in configurador_prueba/php (shared) ────────────
$notifyPath = realpath(__DIR__ . '/../../configurador_prueba/php/voltika-notify.php');
if (!$notifyPath) {
    $notifyPath = realpath(__DIR__ . '/../../configurador_prueba_test/php/voltika-notify.php');
}
if ($notifyPath) {
    require_once $notifyPath;
}

// ── Auth: validar token cron ────────────────────────────────────────────────
$cronToken = defined('VOLTIKA_CRON_TOKEN') ? VOLTIKA_CRON_TOKEN : (getenv('VOLTIKA_CRON_TOKEN') ?: '');
if ($cronToken) {
    $provided = $_SERVER['HTTP_X_CRON_TOKEN'] ?? ($_GET['token'] ?? '');
    if ($provided !== $cronToken) {
        adminJsonOut(['error' => 'Token inválido'], 403);
    }
}

$pdo = getDB();

// Ensure table exists (should already be created by voltikaNotifyDelayed)
$pdo->exec("CREATE TABLE IF NOT EXISTS pending_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(60) NOT NULL,
    data_json TEXT NOT NULL,
    send_after DATETIME NOT NULL,
    sent TINYINT(1) DEFAULT 0,
    freg DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pending (sent, send_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Fetch up to 50 unsent notifications whose time has come
$rows = $pdo->prepare("
    SELECT id, tipo, data_json
    FROM pending_notifications
    WHERE sent = 0 AND send_after <= NOW()
    ORDER BY send_after ASC
    LIMIT 50
");
$rows->execute();
$pending = $rows->fetchAll(PDO::FETCH_ASSOC);

$sent   = 0;
$failed = 0;

foreach ($pending as $row) {
    $data = json_decode($row['data_json'], true);
    if (!$data || !function_exists('voltikaNotify')) {
        $failed++;
        // Mark as sent to avoid infinite retry
        $pdo->prepare("UPDATE pending_notifications SET sent = 1 WHERE id = ?")->execute([$row['id']]);
        continue;
    }

    try {
        voltikaNotify($row['tipo'], $data);
        $pdo->prepare("UPDATE pending_notifications SET sent = 1 WHERE id = ?")->execute([$row['id']]);
        $sent++;
    } catch (Throwable $e) {
        error_log('enviar-notificaciones: ' . $e->getMessage());
        $failed++;
        // Mark as sent after error to avoid infinite loop
        $pdo->prepare("UPDATE pending_notifications SET sent = 1 WHERE id = ?")->execute([$row['id']]);
    }
}

adminLog('cron_enviar_notificaciones', [
    'pendientes' => count($pending),
    'enviadas'   => $sent,
    'fallidas'   => $failed,
]);

adminJsonOut([
    'ok'         => true,
    'pendientes' => count($pending),
    'enviadas'   => $sent,
    'fallidas'   => $failed,
]);
