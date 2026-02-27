<?php
/**
 * Voltika - Enviar OTP por SMS
 * Genera un código de 4 dígitos, lo guarda en sesión y lo envía por SMS.
 * En modo MVP devuelve testCode para facilitar pruebas sin proveedor SMS.
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

$telefono = preg_replace('/\D/', '', $json['telefono'] ?? '');
$nombre   = trim($json['nombre'] ?? '');

if (strlen($telefono) < 10) {
    http_response_code(400);
    echo json_encode(['error' => 'Teléfono inválido']);
    exit;
}

// Generar código de 4 dígitos
$codigo = str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);

// Guardar en sesión (expira en 10 minutos)
$_SESSION['otp_codigo']    = $codigo;
$_SESSION['otp_telefono']  = $telefono;
$_SESSION['otp_expira']    = time() + 600;

// ── Envío real por SMS (Twilio u otro proveedor) ──────────────────────────────
// Descomentar y configurar cuando se tenga el proveedor SMS contratado.
/*
$accountSid = 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
$authToken  = 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
$fromNumber = '+1xxxxxxxxxx'; // Twilio number
$toNumber   = '+52' . $telefono;
$mensaje    = "Voltika: tu código de verificación es $codigo. Válido 10 min.";

$url = "https://api.twilio.com/2010-04-01/Accounts/$accountSid/Messages.json";
$ch  = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, "$accountSid:$authToken");
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'To'   => $toNumber,
    'From' => $fromNumber,
    'Body' => $mensaje,
]));
$response = curl_exec($ch);
curl_close($ch);
*/

// En MVP siempre devolvemos el testCode para que el front pueda continuar
echo json_encode([
    'status'   => 'sent',
    'testCode' => $codigo,   // Quitar en producción
]);
