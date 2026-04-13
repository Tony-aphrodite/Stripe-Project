<?php
/**
 * POST — Verify recovery OTP code
 * Body: { "email": "user@example.com", "codigo": "123456" }
 * Returns a temporary reset token on success.
 */
require_once __DIR__ . '/../bootstrap.php';

$d = adminJsonIn();
$email  = trim($d['email'] ?? '');
$codigo = trim($d['codigo'] ?? '');
if (!$email || !$codigo) adminJsonOut(['error' => 'Email y código requeridos'], 400);

$pdo = getDB();
$stmt = $pdo->prepare("SELECT id FROM dealer_usuarios WHERE email = ? AND activo = 1 LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) adminJsonOut(['error' => 'Código inválido o expirado'], 401);

// Check OTP
$stmt = $pdo->prepare("
    SELECT id FROM admin_otp
    WHERE usuario_id = ? AND codigo = ? AND usado = 0 AND expira > NOW()
    ORDER BY freg DESC LIMIT 1
");
$stmt->execute([$user['id'], $codigo]);
$otp = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$otp) {
    adminLog('recovery_verify_fail', ['email' => $email]);
    adminJsonOut(['error' => 'Código inválido o expirado'], 401);
}

// Mark OTP as used
$pdo->prepare("UPDATE admin_otp SET usado = 1 WHERE id = ?")->execute([$otp['id']]);

// Generate reset token (stored in session)
$resetToken = bin2hex(random_bytes(16));
$_SESSION['reset_token'] = $resetToken;
$_SESSION['reset_user_id'] = $user['id'];
$_SESSION['reset_expires'] = time() + 600; // 10 minutes

adminLog('recovery_verify_ok', ['email' => $email]);
adminJsonOut(['ok' => true, 'resetToken' => $resetToken]);
