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

session_start();

$json = json_decode(file_get_contents('php://input'), true);
if (!$json) {
    http_response_code(400);
    echo json_encode(['error' => 'Request inválido']);
    exit;
}

$codigoIngresado = trim($json['codigo'] ?? '');
$telefono        = preg_replace('/\D/', '', $json['telefono'] ?? '');
$telefonoSession = $_SESSION['otp_telefono'] ?? '';
$expira          = $_SESSION['otp_expira'] ?? 0;

// Usar teléfono de sesión si no se proporcionó
if (!$telefono && $telefonoSession) {
    $telefono = $telefonoSession;
}

// Verificar expiración
if (time() > $expira) {
    echo json_encode(['valido' => false, 'error' => 'Código expirado. Solicita uno nuevo.']);
    exit;
}

// ── Primero intentar verificar contra SMSMasivos ─────────────────────────────
$postData = [
    'phone_number'      => $telefono,
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
file_put_contents($logFile, json_encode([
    'timestamp' => date('c'),
    'action'    => 'verify',
    'telefono'  => substr($telefono, 0, 3) . '****' . substr($telefono, -3),
    'httpCode'  => $httpCode,
    'response'  => $response
]) . "\n", FILE_APPEND | LOCK_EX);

// ── Evaluar respuesta ────────────────────────────────────────────────────────
if (!$curlErr && $httpCode >= 200 && $httpCode < 300) {
    $data = json_decode($response, true);
    // SMSMasivos devuelve un status de verificación
    if (isset($data['status']) && $data['status'] === 'approved') {
        unset($_SESSION['otp_codigo'], $_SESSION['otp_telefono'], $_SESSION['otp_expira']);
        echo json_encode(['valido' => true]);
        exit;
    }
    if (isset($data['valid']) && $data['valid'] === true) {
        unset($_SESSION['otp_codigo'], $_SESSION['otp_telefono'], $_SESSION['otp_expira']);
        echo json_encode(['valido' => true]);
        exit;
    }
    echo json_encode(['valido' => false, 'error' => 'Código incorrecto.']);
    exit;
}

// ── Fallback: verificar contra sesión local ──────────────────────────────────
$codigoEsperado = $_SESSION['otp_codigo'] ?? null;

if ($codigoEsperado && $codigoIngresado === $codigoEsperado) {
    unset($_SESSION['otp_codigo'], $_SESSION['otp_telefono'], $_SESSION['otp_expira']);
    echo json_encode(['valido' => true]);
} else {
    echo json_encode(['valido' => false, 'error' => 'Código incorrecto.']);
}
