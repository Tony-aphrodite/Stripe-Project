<?php
/**
 * POST — Reset password with verified token
 * Body: { "resetToken": "abc...", "password": "newpass123" }
 */
require_once __DIR__ . '/../bootstrap.php';

$d = adminJsonIn();
$token = trim($d['resetToken'] ?? '');
$pass  = $d['password'] ?? '';

if (!$token || !$pass) adminJsonOut(['error' => 'Token y nueva contraseña requeridos'], 400);
if (strlen($pass) < 6) adminJsonOut(['error' => 'La contraseña debe tener al menos 6 caracteres'], 400);

// Verify token from session
if (
    empty($_SESSION['reset_token']) ||
    empty($_SESSION['reset_user_id']) ||
    $_SESSION['reset_token'] !== $token ||
    ($_SESSION['reset_expires'] ?? 0) < time()
) {
    adminJsonOut(['error' => 'Token inválido o expirado. Solicita un nuevo código.'], 401);
}

$userId = (int)$_SESSION['reset_user_id'];

$pdo = getDB();
$hash = password_hash($pass, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("UPDATE dealer_usuarios SET password_hash = ? WHERE id = ? AND activo = 1");
$stmt->execute([$hash, $userId]);

if ($stmt->rowCount() === 0) {
    adminJsonOut(['error' => 'No se pudo actualizar la contraseña'], 500);
}

// Clear reset session
unset($_SESSION['reset_token'], $_SESSION['reset_user_id'], $_SESSION['reset_expires']);

adminLog('password_reset', ['usuario_id' => $userId, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
adminJsonOut(['ok' => true, 'message' => 'Contraseña actualizada correctamente.']);
