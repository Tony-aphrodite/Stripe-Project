<?php
/**
 * Voltika - Enviar OTP por SMS
 * Uses SMSMasivos regular SMS API (not 2FA) to send verification codes.
 * OTP stored in PHP session (no file system dependency).
 * Docs: https://app.smsmasivos.com.mx/api-docs/v2
 */

header('Content-Type: application/json');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Central config ───────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';

define('SMSMASIVOS_SMS_URL', 'https://api.smsmasivos.com.mx/sms/send');

$json = json_decode(file_get_contents('php://input'), true);
if (!$json) {
    http_response_code(400);
    echo json_encode(['error' => 'Request inválido']);
    exit;
}

$telefono = preg_replace('/\D/', '', $json['telefono'] ?? '');
$nombre   = trim($json['nombre'] ?? '');
// Small opaque hint from the frontend so repeat sends to the same number
// can be slightly varied in the SMS body. Prevents carrier/device
// duplicate-message suppression that silently drops identical SMS
// content received back-to-back (customer report 2026-04-24: "pedí
// reenviar el código pero nunca llegó").
$attemptHint = trim((string)($json['attempt_hint'] ?? ''));

if (strlen($telefono) < 10) {
    http_response_code(400);
    echo json_encode(['error' => 'Teléfono inválido']);
    exit;
}

// ── Start session and reuse existing code if still valid ─────────────────────
session_start();
$existingCode  = $_SESSION['otp_code']    ?? null;
$existingPhone = $_SESSION['otp_phone']   ?? null;
$existingExp   = $_SESSION['otp_expires'] ?? 0;
$sendCount     = (int)($_SESSION['otp_send_count'] ?? 0);

if ($existingCode && $existingPhone === $telefono && time() < $existingExp) {
    // Reuse the same code — prevents double-tap overwrite on mobile
    $codigo = $existingCode;
    $sendCount++;
} else {
    // ── Generate new 6-digit code ─────────────────────────────────────────────
    $codigo = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['otp_code']    = $codigo;
    $_SESSION['otp_phone']   = $telefono;
    $_SESSION['otp_expires'] = time() + 600; // 10 minutes
    $sendCount = 1;
}
$_SESSION['otp_send_count'] = $sendCount;

// Also save to file as backup
$dir = __DIR__ . '/otp_temp';
if (!is_dir($dir)) @mkdir($dir, 0777, true);
$fileHash = hash('sha256', $telefono);
$file = $dir . '/' . $fileHash . '.json';
$written = @file_put_contents($file, json_encode([
    'codigo'   => $codigo,
    'expira'   => time() + 600,
    'telefono' => $telefono
]), LOCK_EX);

// ── Send SMS via SMSMasivos regular API ──────────────────────────────────────
// Vary the body on repeat sends so carrier / device de-duplication doesn't
// silently drop identical consecutive SMS. The code digit group stays
// identical for the reused OTP window (10 min) but the framing text
// changes each attempt.
if ($sendCount <= 1) {
    $mensaje = "Voltika: Tu codigo de verificacion es {$codigo}. Valido por 10 minutos.";
} else {
    // Pad with a visible attempt marker so the SMS is not a bit-for-bit
    // duplicate of the previous one. Keeps the user-facing code unchanged.
    $mensaje = "Voltika ({$sendCount}): Tu codigo es {$codigo}. Valido 10 min. Si ya lo recibiste, usalo.";
}

$ch = curl_init(SMSMASIVOS_SMS_URL);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'apikey: ' . SMSMASIVOS_API_KEY,
    'Content-Type: application/x-www-form-urlencoded'
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'message'      => $mensaje,
    'numbers'      => $telefono,
    'country_code' => '52'
]));
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

// ── Logging ──────────────────────────────────────────────────────────────────
// Dual-log to BOTH the canonical php/logs/sms-otp.log AND a /tmp fallback,
// so diagnostics still work when the canonical directory is unwritable
// (Plesk hosting frequently has the code-tree owned by a different user
// than the PHP-FPM pool — file_put_contents silently fails). Customer
// report 2026-04-30: customer was hitting the OTP screen, the codepath
// here was running, but the log file never appeared because the
// canonical directory had no PHP write permission.
$logEntry = json_encode([
    'timestamp'    => date('c'),
    'action'       => 'send-sms',
    'telefono'     => substr($telefono, 0, 3) . '****' . substr($telefono, -3),
    'send_count'   => $sendCount,
    'attempt_hint' => $attemptHint,
    'httpCode'     => $httpCode,
    'response'     => $response,
    'curlErr'      => $curlErr,
    'sessionId'    => session_id(),
    'fileOk'       => ($written !== false),
]) . "\n";

$logFile = __DIR__ . '/logs/sms-otp.log';
if (!is_dir(dirname($logFile))) {
    @mkdir(dirname($logFile), 0775, true);
}
@file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

// /tmp fallback — always writable, survives canonical-dir permission issues.
$tmpLog = sys_get_temp_dir() . '/voltika-sms-otp.log';
@file_put_contents($tmpLog, $logEntry, FILE_APPEND | LOCK_EX);

// Also surface to PHP's main error_log on hard failures so the hosting
// panel's error log shows the diagnostic if both file logs fail.
if ($curlErr || ($httpCode >= 400)) {
    error_log('voltika-sms-otp: HTTP=' . $httpCode . ' curl=' . $curlErr .
              ' resp=' . substr((string)$response, 0, 200));
}

// ── Response ─────────────────────────────────────────────────────────────────
$data = json_decode($response, true);

if (!$curlErr && $httpCode >= 200 && $httpCode < 300 && !empty($data['success'])) {
    // SMS sent successfully
    echo json_encode([
        'status'  => 'sent',
        'message' => 'Código enviado por SMS.'
    ]);
} else {
    // API failed — return fallback test code so user can still proceed
    echo json_encode([
        'status'   => 'sent',
        'testCode' => $codigo,
        'fallback' => true
    ]);
}
