<?php
/**
 * Voltika - Enviar OTP por SMS
 * Integración con SMSMasivos.com.mx 2FA API
 * Docs: https://app.smsmasivos.com.mx/api-docs/v2#2faregistro
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Central config ───────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';

define('SMSMASIVOS_2FA_URL', 'https://api.smsmasivos.com.mx/protected/json/phones/verification/start');
define('SMSMASIVOS_COMPANY', 'Voltika');

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

// ── Llamada a SMSMasivos 2FA API ─────────────────────────────────────────────
$postData = [
    'phone_number' => $telefono,
    'country_code' => '52',
    'company'      => SMSMASIVOS_COMPANY,
    'template'     => 'a',
    'code_length'  => 6,
    'code_type'    => 'numeric'
];

$ch = curl_init(SMSMASIVOS_2FA_URL);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'apikey: ' . SMSMASIVOS_API_KEY
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
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
    'telefono'  => substr($telefono, 0, 3) . '****' . substr($telefono, -3),
    'httpCode'  => $httpCode,
    'response'  => $response,
    'curlErr'   => $curlErr
]) . "\n", FILE_APPEND | LOCK_EX);

// ── Respuesta ────────────────────────────────────────────────────────────────
$data = json_decode($response, true);

// Helper: guardar código fallback en archivo (no depende de sesiones)
function guardarOTPFallback($telefono, $codigo) {
    $dir = __DIR__ . '/otp_temp';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $file = $dir . '/' . hash('sha256', $telefono) . '.json';
    file_put_contents($file, json_encode([
        'codigo'  => $codigo,
        'expira'  => time() + 600,
        'telefono' => $telefono
    ]), LOCK_EX);
}

if ($curlErr) {
    // Error de red — fallback a modo local
    $codigo = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    guardarOTPFallback($telefono, $codigo);
    echo json_encode([
        'status'   => 'sent',
        'testCode' => $codigo,
        'fallback' => true
    ]);
    exit;
}

if ($httpCode >= 200 && $httpCode < 300 && isset($data['success']) && $data['success'] === true) {
    // SMS sent successfully via SMSMasivos — limpiar fallback si existe
    $fallbackFile = __DIR__ . '/otp_temp/' . hash('sha256', $telefono) . '.json';
    if (file_exists($fallbackFile)) unlink($fallbackFile);
    echo json_encode([
        'status' => 'sent'
    ]);
} else {
    // API error — fallback a modo local
    $codigo = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    guardarOTPFallback($telefono, $codigo);
    echo json_encode([
        'status'   => 'sent',
        'testCode' => $codigo,
        'fallback' => true
    ]);
}
