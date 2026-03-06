<?php
/**
 * Voltika - Verificar OTP
 * Integración con SMSMasivos.com.mx 2FA API (check)
 * Fallback: verifica contra sesión local si SMSMasivos no responde
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

define('SMSMASIVOS_CHECK_URL', 'https://api.smsmasivos.com.mx/protected/json/phones/verification/check');

$json = json_decode(file_get_contents('php://input'), true);
if (!$json) {
    http_response_code(400);
    echo json_encode(['error' => 'Request inválido']);
    exit;
}

$codigoIngresado = trim($json['codigo'] ?? '');
$telefono        = preg_replace('/\D/', '', $json['telefono'] ?? '');
$countryCode     = preg_replace('/\D/', '', $json['countryCode'] ?? '52');
$allowedCodes    = ['52', '48'];
if (!in_array($countryCode, $allowedCodes)) $countryCode = '52';

if (!$telefono || !$codigoIngresado) {
    echo json_encode(['valido' => false, 'error' => 'Datos incompletos.']);
    exit;
}

// ── Fallback: verificar contra archivo local PRIMERO ─────────────────────────
$fallbackFile = __DIR__ . '/otp_temp/' . hash('sha256', $telefono) . '.json';

if (file_exists($fallbackFile)) {
    $fallbackData = json_decode(file_get_contents($fallbackFile), true);
    $codigoEsperado = $fallbackData['codigo'] ?? null;
    $expira         = $fallbackData['expira'] ?? 0;

    // Logging
    $logFile = __DIR__ . '/logs/sms-otp.log';
    if (!is_dir(dirname($logFile))) mkdir(dirname($logFile), 0755, true);
    file_put_contents($logFile, json_encode([
        'timestamp' => date('c'),
        'action'    => 'verify-fallback',
        'telefono'  => substr($telefono, 0, 3) . '****' . substr($telefono, -3),
        'expected'  => $codigoEsperado,
        'received'  => $codigoIngresado,
        'match'     => ($codigoIngresado === $codigoEsperado)
    ]) . "\n", FILE_APPEND | LOCK_EX);

    if (time() > $expira) {
        @unlink($fallbackFile);
        echo json_encode(['valido' => false, 'error' => 'Código expirado. Solicita uno nuevo.']);
        exit;
    }

    if ($codigoEsperado && $codigoIngresado === $codigoEsperado) {
        @unlink($fallbackFile);
        echo json_encode(['valido' => true]);
    } else {
        echo json_encode(['valido' => false, 'error' => 'Código incorrecto. Verifica e intenta de nuevo.']);
    }
    exit;
}

// ── Verificar contra SMSMasivos API (SMS fue enviado exitosamente) ───────────
$postData = [
    'phone_number'      => $telefono,
    'country_code'      => $countryCode,
    'verification_code' => $codigoIngresado
];

$ch = curl_init(SMSMASIVOS_CHECK_URL);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'apikey: ' . SMSMASIVOS_API_KEY
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

// Logging
$logFile = __DIR__ . '/logs/sms-otp.log';
if (!is_dir(dirname($logFile))) mkdir(dirname($logFile), 0755, true);
file_put_contents($logFile, json_encode([
    'timestamp' => date('c'),
    'action'    => 'verify-api',
    'telefono'  => substr($telefono, 0, 3) . '****' . substr($telefono, -3),
    'httpCode'  => $httpCode,
    'response'  => $response,
    'curlErr'   => $curlErr
]) . "\n", FILE_APPEND | LOCK_EX);

if (!$curlErr && $httpCode >= 200 && $httpCode < 300) {
    $data = json_decode($response, true);
    if (isset($data['success']) && $data['success'] === true) {
        echo json_encode(['valido' => true]);
        exit;
    }
    echo json_encode(['valido' => false, 'error' => $data['message'] ?? 'Código incorrecto.']);
    exit;
}

// API error — accept code anyway (SMS was sent but API check failed)
echo json_encode(['valido' => false, 'error' => 'Error de verificación. Intenta de nuevo.']);
