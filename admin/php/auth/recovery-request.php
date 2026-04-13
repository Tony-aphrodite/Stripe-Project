<?php
/**
 * POST — Request password recovery OTP
 * Body: { "email": "user@example.com" }
 * Sends a 6-digit OTP to the user's email.
 */
require_once __DIR__ . '/../bootstrap.php';

$d = adminJsonIn();
$email = trim($d['email'] ?? '');
if (!$email) adminJsonOut(['error' => 'Email requerido'], 400);

$pdo = getDB();
$stmt = $pdo->prepare("SELECT id, nombre, email FROM dealer_usuarios WHERE email = ? AND activo = 1 LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // Don't reveal whether the email exists
    adminJsonOut(['ok' => true, 'message' => 'Si el email existe, recibirás un código de verificación.']);
}

// Generate 6-digit OTP
$code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expira = date('Y-m-d H:i:s', strtotime('+10 minutes'));

// Invalidate previous codes for this user
$pdo->prepare("UPDATE admin_otp SET usado = 1 WHERE usuario_id = ? AND usado = 0")->execute([$user['id']]);

// Save new OTP
$pdo->prepare("INSERT INTO admin_otp (usuario_id, codigo, expira, ip) VALUES (?, ?, ?, ?)")
    ->execute([$user['id'], $code, $expira, $_SERVER['REMOTE_ADDR'] ?? '']);

// Send email
$nombre = htmlspecialchars($user['nombre']);
$cuerpo = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f5f7fa;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;"><tr><td align="center" style="padding:24px 12px;">
<table width="500" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;max-width:500px;width:100%;">
<tr><td style="background:linear-gradient(135deg,#1a3a5c,#039fe1);padding:24px;text-align:center;">
<img src="https://www.voltika.mx/configurador_prueba/img/voltika_logo_h_white.svg" alt="Voltika" style="height:36px;">
</td></tr>
<tr><td style="padding:28px;text-align:center;">
<h2 style="margin:0 0 8px;color:#1a3a5c;">Recuperar contraseña</h2>
<p style="color:#555;font-size:14px;">Hola ' . $nombre . ', tu código de verificación es:</p>
<div style="font-size:36px;font-weight:900;letter-spacing:8px;color:#039fe1;margin:20px 0;padding:16px;background:#f0f9ff;border-radius:8px;">' . $code . '</div>
<p style="color:#888;font-size:12px;">Este código expira en 10 minutos.</p>
<p style="color:#888;font-size:12px;">Si no solicitaste este código, ignora este mensaje.</p>
</td></tr>
<tr><td style="background:#1a3a5c;padding:16px;text-align:center;">
<p style="margin:0;font-size:12px;color:rgba(255,255,255,0.6);">Voltika México — Panel de Administración</p>
</td></tr>
</table></td></tr></table></body></html>';

try {
    sendMail($user['email'], $user['nombre'], 'Voltika — Código de recuperación', $cuerpo);
} catch (Throwable $e) {
    error_log('recovery-request email error: ' . $e->getMessage());
}

adminLog('recovery_request', ['email' => $email, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
adminJsonOut(['ok' => true, 'message' => 'Si el email existe, recibirás un código de verificación.']);
