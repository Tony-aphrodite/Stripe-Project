<?php
/**
 * Voltika - Verificar OTP
 * Verifies the 6-digit code against the file stored by enviar-otp.php.
 * We use the regular SMS API now (not 2FA), so verification is file-based only.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
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

// ── Verify against stored code ───────────────────────────────────────────────
$codeFile = __DIR__ . '/otp_temp/' . hash('sha256', $telefono) . '.json';

$logFile = __DIR__ . '/logs/sms-otp.log';
if (!is_dir(dirname($logFile))) mkdir(dirname($logFile), 0755, true);

if (!file_exists($codeFile)) {
    file_put_contents($logFile, json_encode([
        'timestamp' => date('c'),
        'action'    => 'verify',
        'telefono'  => substr($telefono, 0, 3) . '****' . substr($telefono, -3),
        'result'    => 'no_code_found'
    ]) . "\n", FILE_APPEND | LOCK_EX);

    echo json_encode(['valido' => false, 'error' => 'No hay código pendiente. Solicita uno nuevo.']);
    exit;
}

$data           = json_decode(file_get_contents($codeFile), true);
$codigoEsperado = $data['codigo'] ?? null;
$expira         = $data['expira'] ?? 0;

file_put_contents($logFile, json_encode([
    'timestamp' => date('c'),
    'action'    => 'verify',
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
    echo json_encode(['valido' => true]);
} else {
    echo json_encode(['valido' => false, 'error' => 'Código incorrecto. Verifica e intenta de nuevo.']);
}
