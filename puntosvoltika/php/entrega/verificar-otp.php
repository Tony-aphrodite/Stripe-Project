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
if (!$e) puntoJsonOut(['error' => 'No hay entrega iniciada'], 404);
if ($e['otp_code'] !== $code) puntoJsonOut(['error' => 'Código incorrecto'], 400);
if (strtotime($e['otp_expires']) < time()) puntoJsonOut(['error' => 'Código expirado'], 400);

$pdo->prepare("UPDATE entregas SET otp_verified=1, otp_verified_at=NOW(), estado='confirmado' WHERE id=?")
    ->execute([$e['id']]);

puntoLog('entrega_otp_verificado', ['moto_id' => $motoId]);
puntoJsonOut(['ok' => true, 'entrega_id' => $e['id']]);
