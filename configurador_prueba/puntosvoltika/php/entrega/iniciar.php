<?php
/**
 * POST — Iniciar proceso de entrega al cliente: generar OTP y enviar por SMS
 * Body: { moto_id }
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$d = puntoJsonIn();
$motoId = (int)($d['moto_id'] ?? 0);
if (!$motoId) puntoJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE id=? AND punto_voltika_id=?");
$stmt->execute([$motoId, $ctx['punto_id']]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) puntoJsonOut(['error' => 'Moto no encontrada en este punto'], 404);
if (!$moto['cliente_telefono']) puntoJsonOut(['error' => 'Moto no tiene cliente asignado'], 400);

// Generate OTP
$otp = puntoGenOTP();
$expires = date('Y-m-d H:i:s', time() + 600);

// Upsert entrega record
$pdo->prepare("INSERT INTO entregas (moto_id, pedido_num, cliente_name, cliente_email, cliente_telefono,
    otp_code, otp_expires, estado, dealer_id)
    VALUES (?,?,?,?,?,?,?,'otp_enviado',?)
    ON DUPLICATE KEY UPDATE otp_code=VALUES(otp_code), otp_expires=VALUES(otp_expires), estado='otp_enviado'")
    ->execute([
        $motoId, $moto['pedido_num'], $moto['cliente_nombre'],
        $moto['cliente_email'], $moto['cliente_telefono'],
        $otp, $expires, $ctx['user_id']
    ]);

// Send SMS
$tel = preg_replace('/\D/', '', $moto['cliente_telefono']);
if (strlen($tel) === 10) $tel = '52' . $tel;
$msg = "Voltika: Tu código de entrega es {$otp}. Muéstralo al asesor en el punto. No lo compartas.";
$smsKey = defined('SMSMASIVOS_API_KEY') ? SMSMASIVOS_API_KEY : (getenv('SMSMASIVOS_API_KEY') ?: '');
$smsSent = false;
if ($smsKey) {
    $ch = curl_init('https://api.smsmasivos.com.mx/sms/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json','Authorization: Bearer '.$smsKey],
        CURLOPT_POSTFIELDS => json_encode(['phone_number'=>$tel,'message'=>$msg]),
        CURLOPT_TIMEOUT => 8,
    ]);
    $res = curl_exec($ch); curl_close($ch);
    $smsSent = !empty($res);
}

puntoLog('entrega_otp_enviado', ['moto_id' => $motoId]);
puntoJsonOut([
    'ok' => true,
    'sms_enviado' => $smsSent,
    'test_code' => $smsSent ? null : $otp, // fallback for dev
    'cliente' => ['nombre' => $moto['cliente_nombre'], 'telefono' => $moto['cliente_telefono']]
]);
