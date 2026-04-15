<?php
/**
 * POST — Send OTP SMS for delivery checklist
 * Body: { moto_id }
 * Gets client phone from inventario_motos, sends 6-digit code
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$motoId = (int)($d['moto_id'] ?? 0);
if (!$motoId) adminJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();

// Get client phone
$stmt = $pdo->prepare("SELECT cliente_nombre, cliente_telefono, cliente_email FROM inventario_motos WHERE id=?");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) adminJsonOut(['error' => 'Moto no encontrada'], 404);

$telefono = preg_replace('/\D/', '', $moto['cliente_telefono'] ?? '');
if (strlen($telefono) < 10) {
    adminJsonOut(['error' => 'El cliente no tiene teléfono registrado o es inválido'], 400);
}

// Generate 6-digit code
$codigo = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

// Store in checklist_entrega_v2
$existing = $pdo->prepare("SELECT id FROM checklist_entrega_v2 WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$existing->execute([$motoId]);
$row = $existing->fetch(PDO::FETCH_ASSOC);

if ($row) {
    $pdo->prepare("UPDATE checklist_entrega_v2 SET otp_code=?, otp_expires=?, otp_enviado=1, otp_timestamp=NOW() WHERE id=?")
        ->execute([$codigo, date('Y-m-d H:i:s', time() + 600), $row['id']]);
} else {
    $pdo->prepare("INSERT INTO checklist_entrega_v2 (moto_id, dealer_id, otp_code, otp_expires, otp_enviado, otp_timestamp) VALUES (?,?,?,?,1,NOW())")
        ->execute([$motoId, $uid, $codigo, date('Y-m-d H:i:s', time() + 600)]);
}

// Send SMS
$apiKey = defined('SMSMASIVOS_API_KEY') ? SMSMASIVOS_API_KEY : (getenv('SMSMASIVOS_API_KEY') ?: '');
$mensaje = "Voltika: Tu codigo de verificacion para entrega es {$codigo}. Valido por 10 minutos.";
$smsSent = false;

if ($apiKey) {
    $ch = curl_init('https://api.smsmasivos.com.mx/sms/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['apikey: ' . $apiKey, 'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'message' => $mensaje,
            'numbers' => $telefono,
            'country_code' => '52'
        ]),
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($resp, true);
    $smsSent = ($httpCode >= 200 && $httpCode < 300 && !empty($data['success']));
}

adminLog('checklist_otp_enviado', ['moto_id' => $motoId, 'telefono' => substr($telefono, 0, 3) . '****']);

$result = [
    'ok' => true,
    'enviado' => $smsSent,
    'telefono_masked' => substr($telefono, 0, 3) . '****' . substr($telefono, -2),
];
// Fallback: if SMS failed, include code for manual verification
if (!$smsSent) {
    $result['fallback_code'] = $codigo;
    $result['warn'] = 'SMS no enviado. Código de respaldo incluido.';
}

adminJsonOut($result);
