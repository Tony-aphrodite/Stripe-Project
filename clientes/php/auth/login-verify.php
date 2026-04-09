<?php
require_once __DIR__ . '/../bootstrap.php';

$in = portalJsonIn();
$tel = portalNormPhone($in['telefono'] ?? '');
$cod = preg_replace('/\D/', '', $in['codigo'] ?? '');

// Try DB first (authoritative), then session (fast path)
$pdo = getDB();
$otp = null;
try {
    $stmt = $pdo->prepare("SELECT codigo, cliente_id, expira FROM portal_otp WHERE telefono=? LIMIT 1");
    $stmt->execute([$tel]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $otp = [
            'codigo'     => $row['codigo'],
            'telefono'   => $tel,
            'cliente_id' => (int)$row['cliente_id'],
            'expira'     => (int)$row['expira'],
        ];
    }
} catch (Throwable $e) { error_log('portal_otp read: ' . $e->getMessage()); }

if (!$otp) {
    $otp = $_SESSION['portal_otp_login'] ?? null;
}

if (!$otp || $otp['telefono'] !== $tel || time() > ($otp['expira'] ?? 0)) {
    portalLog('login_verify', ['telefono' => $tel, 'success' => 0, 'detalle' => 'otp_expirado']);
    portalJsonOut(['error' => 'Código expirado. Solicita uno nuevo.'], 400);
}
if ($otp['codigo'] !== $cod) {
    portalLog('login_verify', ['telefono' => $tel, 'success' => 0, 'detalle' => 'otp_invalido']);
    portalJsonOut(['error' => 'Código incorrecto.'], 400);
}

$_SESSION['portal_cliente_id'] = (int)$otp['cliente_id'];
$_SESSION['portal_login_at']   = time();
unset($_SESSION['portal_otp_login']);
try { $pdo->prepare("DELETE FROM portal_otp WHERE telefono=?")->execute([$tel]); } catch (Throwable $e) {}

// Persist session row
try {
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO portal_sesiones (id, cliente_id, ip, user_agent)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE last_seen = CURRENT_TIMESTAMP");
    $stmt->execute([
        session_id(),
        (int)$otp['cliente_id'],
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
} catch (Throwable $e) { error_log($e->getMessage()); }

portalLog('login_verify', [
    'telefono' => $tel,
    'cliente_id' => (int)$otp['cliente_id'],
    'success' => 1,
]);

// Load cliente for frontend
$cli = null;
try {
    $stmt = $pdo->prepare("SELECT id, nombre, apellido_paterno, apellido_materno, email, telefono FROM clientes WHERE id=? LIMIT 1");
    $stmt->execute([(int)$otp['cliente_id']]);
    $cli = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {}

portalJsonOut([
    'ok' => true,
    'status' => 'ok',
    'cliente_id' => (int)$otp['cliente_id'],
    'cliente' => $cli,
]);
