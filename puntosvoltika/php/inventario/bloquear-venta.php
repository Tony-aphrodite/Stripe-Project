<?php
/**
 * POST — Lock/unlock a motorcycle from being sold (Punto access)
 * Body: { moto_id, bloqueado: 1|0, motivo: "reason text" }
 * Only allows locking motos assigned to this punto.
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

// Customer brief 2026-05-12 (Óscar, 6th round): "Punto cannot do this."
// The Bloquear moto button is no longer rendered in punto-inventario.js.
// This endpoint hard-rejects any forged request from a punto session so
// the restriction can't be bypassed. Audit logged.
$d = puntoJsonIn();
puntoLog('intento_bloquear_bloqueado', [
    'moto_id'  => (int)($d['moto_id'] ?? 0),
    'punto_id' => $ctx['punto_id'],
    'user_id'  => $ctx['user_id'],
]);
puntoJsonOut([
    'error' => 'El bloqueo de motos solo lo puede gestionar CEDIS / admin.',
    'hint'  => 'Esta acción ya no está disponible para puntos.',
], 403);

// ── Legacy code path kept below (now unreachable) ────────────────────
$motoId    = (int)($d['moto_id'] ?? 0);
$bloqueado = (int)($d['bloqueado'] ?? 0);
$motivo    = trim($d['motivo'] ?? '');

if (!$motoId) puntoJsonOut(['error' => 'moto_id requerido'], 400);
if (!$bloqueado) puntoJsonOut(['error' => 'El punto solo puede bloquear motos. Para desbloquear, contacta a CEDIS.'], 403);
if (!$motivo) puntoJsonOut(['error' => 'Motivo de bloqueo requerido'], 400);

$pdo = getDB();

// Verify moto belongs to this punto
$stmt = $pdo->prepare("SELECT id, vin_display, vin, modelo, color, punto_voltika_id, bloqueado_venta
    FROM inventario_motos WHERE id=? AND activo=1");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) puntoJsonOut(['error' => 'Moto no encontrada'], 404);

if ((int)$moto['punto_voltika_id'] !== $ctx['punto_id']) {
    puntoJsonOut(['error' => 'Esta moto no pertenece a tu punto'], 403);
}

$pdo->prepare("UPDATE inventario_motos SET bloqueado_venta=1, bloqueado_motivo=?, bloqueado_por=?, bloqueado_fecha=NOW() WHERE id=?")
    ->execute([$motivo, $ctx['user_id'], $motoId]);

puntoLog('moto_bloqueada', [
    'moto_id' => $motoId,
    'vin' => $moto['vin_display'] ?? $moto['vin'],
    'motivo' => $motivo,
]);

puntoJsonOut([
    'ok' => true,
    'bloqueado_venta' => 1,
    'message' => 'Moto bloqueada — solo CEDIS puede desbloquearla',
]);
