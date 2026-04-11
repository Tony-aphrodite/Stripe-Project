<?php
/**
 * GET — Notification history for the authenticated client.
 *
 * Returns the last ~60 entries from `notificaciones_log` tied to this cliente,
 * matched by cliente_id OR by the client's email/telefono (legacy rows where
 * voltikaNotify didn't receive a clienteId still carry the destination).
 *
 * Response: { ok: true, items: [{ id, tipo, canal, destino, mensaje, status, freg }, ...] }
 */
require_once __DIR__ . '/../bootstrap.php';
$cid = portalRequireAuth();

$pdo = getDB();

// Ensure the log table exists (same DDL as voltika-notify.php) so this endpoint
// never 500s on fresh installs where no notification has been sent yet.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notificaciones_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT NULL,
        tipo VARCHAR(60) NOT NULL,
        canal VARCHAR(20) NOT NULL,
        destino VARCHAR(150) NULL,
        mensaje TEXT NULL,
        status VARCHAR(30) DEFAULT 'sent',
        error TEXT NULL,
        freg DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cliente (cliente_id),
        INDEX idx_tipo (tipo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) { error_log('notificaciones ensure: ' . $e->getMessage()); }

// Pull the client's email and telefono to widen the match
$email = null; $tel = null;
try {
    $q = $pdo->prepare("SELECT email, telefono FROM clientes WHERE id = ?");
    $q->execute([$cid]);
    $c = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    $email = $c['email'] ?? null;
    $tel   = $c['telefono'] ?? null;
} catch (Throwable $e) { error_log('notificaciones cliente: ' . $e->getMessage()); }

$where  = ['cliente_id = ?'];
$params = [$cid];
if ($email) { $where[] = 'destino = ?';                        $params[] = $email; }
if ($tel)   { $where[] = 'destino = ?';                        $params[] = $tel; }
if ($tel && strlen($tel) >= 10) {
    // Last-10-digit match — destinos may include country code or formatting
    $where[] = "RIGHT(REPLACE(REPLACE(destino,'+',''),' ',''), 10) = ?";
    $params[] = substr($tel, -10);
}

$sql = "SELECT id, tipo, canal, destino, mensaje, status, freg
        FROM notificaciones_log
        WHERE " . implode(' OR ', $where) . "
        ORDER BY freg DESC, id DESC
        LIMIT 60";

$items = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { error_log('notificaciones query: ' . $e->getMessage()); }

portalJsonOut(['ok' => true, 'items' => $items]);
