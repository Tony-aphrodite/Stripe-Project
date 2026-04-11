<?php
/**
 * POST — Verificar OTP introducido por el cliente
 * Body: { moto_id, codigo }
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$d = puntoJsonIn();
$motoId = (int)($d['moto_id'] ?? 0);
$code   = trim($d['codigo'] ?? '');

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM entregas WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$stmt->execute([$motoId]);
$e = $stmt->fetch(PDO::FETCH_ASSOC);
// Step-order guard — OTP verification requires an entrega row created by
// iniciar.php (which itself enforces payment complete). Cannot be called first.
if (!$e) puntoJsonOut(['error' => 'Debes iniciar la entrega antes de verificar el OTP'], 409);
if (empty($e['otp_code'])) puntoJsonOut(['error' => 'No se ha emitido un OTP para esta entrega'], 409);
if (!in_array($e['estado'] ?? '', ['otp_enviado'], true)) {
    // If it's already 'confirmado' the OTP was verified; if other, flow is out of order
    if (($e['estado'] ?? '') === 'confirmado') {
        puntoJsonOut(['ok' => true, 'already' => true, 'entrega_id' => $e['id']]);
    }
    puntoJsonOut(['error' => 'Estado de entrega inválido: ' . ($e['estado'] ?? '?')], 409);
}
if ($e['otp_code'] !== $code) puntoJsonOut(['error' => 'Código incorrecto'], 400);
if (strtotime($e['otp_expires']) < time()) puntoJsonOut(['error' => 'Código expirado'], 400);

$pdo->prepare("UPDATE entregas SET otp_verified=1, otp_verified_at=NOW(), estado='confirmado' WHERE id=?")
    ->execute([$e['id']]);

puntoLog('entrega_otp_verificado', ['moto_id' => $motoId]);
puntoJsonOut(['ok' => true, 'entrega_id' => $e['id']]);
