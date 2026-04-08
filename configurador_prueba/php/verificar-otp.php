<?php
/**
 * Voltika - Verificar OTP
 * Verifies the 6-digit code against SESSION first, then file as fallback.
 * We use the regular SMS API now (not 2FA), so verification is local only.
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

$json = json_decode(file_get_contents('php://input'), true);
if (!$json) {
    http_response_code(400);
    echo json_encode(['error' => 'Request inválido']);
    exit;
}

$codigoIngresado = trim($json['codigo'] ?? '');
$telefono        = preg_replace('/\D/', '', $json['telefono'] ?? '');

if (!$telefono || !$codigoIngresado) {
    echo json_encode(['valido' => false, 'error' => 'Datos incompletos.']);
    exit;
}

$logFile = __DIR__ . '/logs/sms-otp.log';
if (!is_dir(dirname($logFile))) mkdir(dirname($logFile), 0755, true);

// ── Method 1: Verify via PHP SESSION ─────────────────────────────────────────
session_start();
$sessionCode  = $_SESSION['otp_code'] ?? null;
$sessionPhone = $_SESSION['otp_phone'] ?? null;
$sessionExp   = $_SESSION['otp_expires'] ?? 0;

if ($sessionCode && $sessionPhone === $telefono) {
    // Session has OTP for this phone
    file_put_contents($logFile, json_encode([
        'timestamp' => date('c'),
        'action'    => 'verify-session',
        'telefono'  => substr($telefono, 0, 3) . '****' . substr($telefono, -3),
        'expected'  => $sessionCode,
        'received'  => $codigoIngresado,
        'match'     => ($codigoIngresado === $sessionCode),
        'expired'   => (time() > $sessionExp)
    ]) . "\n", FILE_APPEND | LOCK_EX);

    if (time() > $sessionExp) {
        unset($_SESSION['otp_code'], $_SESSION['otp_phone'], $_SESSION['otp_expires']);
        echo json_encode(['valido' => false, 'error' => 'Código expirado. Solicita uno nuevo.']);
        exit;
    }

    if ($codigoIngresado === $sessionCode) {
        unset($_SESSION['otp_code'], $_SESSION['otp_phone'], $_SESSION['otp_expires']);
        echo json_encode(['valido' => true, 'method' => 'session']);
        exit;
    }

    // Code didn't match via session — don't fall through, return error
    echo json_encode(['valido' => false, 'error' => 'Código incorrecto. Verifica e intenta de nuevo.']);
    exit;
}

// ── Method 2: Fallback to file-based verification ────────────────────────────
$otpDir   = __DIR__ . '/otp_temp';
$codeFile = $otpDir . '/' . hash('sha256', $telefono) . '.json';

if (!file_exists($codeFile)) {
    file_put_contents($logFile, json_encode([
        'timestamp'    => date('c'),
        'action'       => 'verify-file',
        'telefono'     => substr($telefono, 0, 3) . '****' . substr($telefono, -3),
        'result'       => 'no_code_found',
        'sessionEmpty' => ($sessionCode === null),
        'sessionPhone' => $sessionPhone ? (substr($sessionPhone, 0, 3) . '****') : null,
        'dirExists'    => is_dir($otpDir)
    ]) . "\n", FILE_APPEND | LOCK_EX);

    echo json_encode(['valido' => false, 'error' => 'No hay código pendiente. Solicita uno nuevo.']);
    exit;
}

$data           = json_decode(file_get_contents($codeFile), true);
$codigoEsperado = $data['codigo'] ?? null;
$expira         = $data['expira'] ?? 0;

file_put_contents($logFile, json_encode([
    'timestamp' => date('c'),
    'action'    => 'verify-file',
    'telefono'  => substr($telefono, 0, 3) . '****' . substr($telefono, -3),
    'expected'  => $codigoEsperado,
    'received'  => $codigoIngresado,
    'match'     => ($codigoIngresado === $codigoEsperado)
]) . "\n", FILE_APPEND | LOCK_EX);

if (time() > $expira) {
    @unlink($codeFile);
    echo json_encode(['valido' => false, 'error' => 'Código expirado. Solicita uno nuevo.']);
    exit;
}

if ($codigoEsperado && $codigoIngresado === $codigoEsperado) {
    @unlink($codeFile);
    echo json_encode(['valido' => true, 'method' => 'file']);
} else {
    echo json_encode(['valido' => false, 'error' => 'Código incorrecto. Verifica e intenta de nuevo.']);
}
