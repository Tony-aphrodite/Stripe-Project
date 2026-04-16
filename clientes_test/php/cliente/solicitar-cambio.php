<?php
/**
 * POST — Request OTP to change email or phone
 * Body: { campo: "email"|"telefono", nuevo_valor: "..." }
 *
 * If changing email  → sends OTP via SMS to current phone
 * If changing phone  → sends OTP via SMS to NEW phone (for verification)
 */
require_once __DIR__ . '/../bootstrap.php';
$cid = portalRequireAuth();

$in = portalJsonIn();
$campo = $in['campo'] ?? '';
$nuevoValor = trim($in['nuevo_valor'] ?? '');

if (!in_array($campo, ['email', 'telefono']))
    portalJsonOut(['error' => 'Campo inválido'], 400);

if (!$nuevoValor)
    portalJsonOut(['error' => 'Valor requerido'], 400);

$pdo = getDB();

// Get current client info
$stmt = $pdo->prepare("SELECT nombre, email, telefono FROM clientes WHERE id = ?");
$stmt->execute([$cid]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cliente) portalJsonOut(['error' => 'Cliente no encontrado'], 404);

// Validate new value
if ($campo === 'email') {
    $nuevoValor = strtolower($nuevoValor);
    if (!filter_var($nuevoValor, FILTER_VALIDATE_EMAIL))
        portalJsonOut(['error' => 'Correo inválido'], 400);
    if ($nuevoValor === strtolower($cliente['email'] ?? ''))
        portalJsonOut(['error' => 'El correo es igual al actual'], 400);
    $destino = $cliente['telefono'];
    if (!$destino) portalJsonOut(['error' => 'No tienes teléfono registrado para verificación'], 400);
} else {
    $nuevoValor = preg_replace('/[^0-9+]/', '', $nuevoValor);
    if (strlen($nuevoValor) < 10)
        portalJsonOut(['error' => 'Teléfono inválido'], 400);
    if ($nuevoValor === ($cliente['telefono'] ?? ''))
        portalJsonOut(['error' => 'El teléfono es igual al actual'], 400);
    $destino = $nuevoValor; // Send OTP to the NEW phone to verify ownership
}

// Generate and store OTP
$otp = portalGenOTP();
$expires = date('Y-m-d H:i:s', time() + 600); // 10 minutes

// Create table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS portal_cambios_otp (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    campo VARCHAR(20) NOT NULL,
    nuevo_valor VARCHAR(200) NOT NULL,
    otp_code VARCHAR(10) NOT NULL,
    otp_expires DATETIME NOT NULL,
    verificado TINYINT DEFAULT 0,
    freg DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Invalidate previous pending OTPs for same campo
$pdo->prepare("DELETE FROM portal_cambios_otp WHERE cliente_id = ? AND campo = ? AND verificado = 0")
    ->execute([$cid, $campo]);

// Insert new OTP request
$pdo->prepare("INSERT INTO portal_cambios_otp (cliente_id, campo, nuevo_valor, otp_code, otp_expires) VALUES (?, ?, ?, ?, ?)")
    ->execute([$cid, $campo, $nuevoValor, $otp, $expires]);

// Send OTP via SMS
$msg = "Voltika: Tu código para cambiar tu $campo es: $otp (expira en 10 min)";
$smsResult = portalSendSMS($destino, $msg);

portalLog('cambio_otp_enviado', ['campo' => $campo, 'sms_ok' => $smsResult['ok']]);

$destinoMask = $campo === 'email'
    ? '••••' . substr($destino, -4)
    : '••••' . substr($destino, -4);

portalJsonOut([
    'ok' => true,
    'destino' => $destinoMask,
    'canal' => 'SMS',
    'debug_code' => $smsResult['ok'] ? null : $otp, // fallback if SMS fails
]);
