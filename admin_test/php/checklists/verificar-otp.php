<?php
/**
 * POST — Verify OTP code for delivery checklist
 * Body: { moto_id, codigo }
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$motoId = (int)($d['moto_id'] ?? 0);
$codigo = trim($d['codigo'] ?? '');

if (!$motoId || !$codigo) adminJsonOut(['error' => 'moto_id y codigo requeridos'], 400);

$pdo = getDB();

$stmt = $pdo->prepare("SELECT id, otp_code, otp_expires, completado FROM checklist_entrega_v2 WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$stmt->execute([$motoId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) adminJsonOut(['error' => 'Checklist de entrega no encontrado'], 404);
if ($row['completado']) adminJsonOut(['error' => 'Checklist ya completado'], 403);

if (empty($row['otp_code'])) {
    adminJsonOut(['ok' => false, 'error' => 'No se ha enviado un código OTP']);
}

// Check expiration
if (strtotime($row['otp_expires']) < time()) {
    adminJsonOut(['ok' => false, 'error' => 'El código ha expirado. Envía uno nuevo.']);
}

// Check code match
if ($row['otp_code'] !== $codigo) {
    adminJsonOut(['ok' => false, 'error' => 'Código incorrecto']);
}

// Mark OTP validated
$pdo->prepare("UPDATE checklist_entrega_v2 SET otp_validado=1, fase4_completada=1, fase4_fecha=NOW() WHERE id=?")
    ->execute([$row['id']]);

adminLog('checklist_otp_validado', ['moto_id' => $motoId]);
adminJsonOut(['ok' => true, 'validado' => true]);
