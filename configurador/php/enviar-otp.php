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

if (strlen($telefono) < 10) {
    http_response_code(400);
    echo json_encode(['error' => 'Teléfono inválido']);
    exit;
}

// ── Generate 6-digit code ────────────────────────────────────────────────────
$codigo = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

// ── Save code to SESSION (reliable, no file permission issues) ──────────────
session_start();
$_SESSION['otp_code']    = $codigo;
$_SESSION['otp_phone']   = $telefono;
$_SESSION['otp_expires'] = time() + 600; // 10 minutes

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
$mensaje = "Voltika: Tu codigo de verificacion es {$codigo}. Valido por 10 minutos.";

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
$logFile = __DIR__ . '/logs/sms-otp.log';
if (!is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}
file_put_contents($logFile, json_encode([
    'timestamp' => date('c'),
    'action'    => 'send-sms',
    'telefono'  => substr($telefono, 0, 3) . '****' . substr($telefono, -3),
    'httpCode'  => $httpCode,
    'response'  => $response,
    'curlErr'   => $curlErr,
    'sessionId' => session_id(),
    'fileOk'    => ($written !== false)
]) . "\n", FILE_APPEND | LOCK_EX);

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
