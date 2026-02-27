<?php
/**
 * Voltika - Verificar OTP
 * Compara el código ingresado con el almacenado en sesión.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();

$json = json_decode(file_get_contents('php://input'), true);
if (!$json) {
    http_response_code(400);
    echo json_encode(['error' => 'Request inválido']);
    exit;
}

$codigoIngresado = trim($json['codigo'] ?? '');
$codigoEsperado  = $_SESSION['otp_codigo']  ?? null;
$expira          = $_SESSION['otp_expira']   ?? 0;

// Verificar expiración
if (time() > $expira) {
    echo json_encode(['valido' => false, 'error' => 'Código expirado. Solicita uno nuevo.']);
    exit;
}

// Verificar código
if ($codigoIngresado === $codigoEsperado) {
    // Limpiar sesión OTP
    unset($_SESSION['otp_codigo'], $_SESSION['otp_telefono'], $_SESSION['otp_expira']);
    echo json_encode(['valido' => true]);
} else {
    echo json_encode(['valido' => false, 'error' => 'Código incorrecto.']);
}
