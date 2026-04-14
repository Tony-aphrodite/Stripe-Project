<?php
/**
 * POST — Lock/unlock a motorcycle from being sold (Punto access)
 * Body: { moto_id, bloqueado: 1|0, motivo: "reason text" }
 * Only allows locking motos assigned to this punto.
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$d = puntoJsonIn();
$motoId    = (int)($d['moto_id'] ?? 0);
$bloqueado = (int)($d['bloqueado'] ?? 0);
$motivo    = trim($d['motivo'] ?? '');

if (!$motoId) puntoJsonOut(['error' => 'moto_id requerido'], 400);
if ($bloqueado && !$motivo) puntoJsonOut(['error' => 'Motivo de bloqueo requerido'], 400);

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

if ($bloqueado) {
    $pdo->prepare("UPDATE inventario_motos SET bloqueado_venta=1, bloqueado_motivo=?, bloqueado_por=?, bloqueado_fecha=NOW() WHERE id=?")
        ->execute([$motivo, $ctx['user_id'], $motoId]);
} else {
    $pdo->prepare("UPDATE inventario_motos SET bloqueado_venta=0, bloqueado_motivo=NULL, bloqueado_por=NULL, bloqueado_fecha=NULL WHERE id=?")
        ->execute([$motoId]);
}

puntoLog($bloqueado ? 'moto_bloqueada_venta' : 'moto_desbloqueada_venta', [
    'moto_id' => $motoId,
    'vin' => $moto['vin_display'] ?? $moto['vin'],
    'motivo' => $bloqueado ? $motivo : 'Desbloqueado',
]);

puntoJsonOut([
    'ok' => true,
    'bloqueado_venta' => $bloqueado,
    'message' => $bloqueado ? 'Moto bloqueada para venta' : 'Moto desbloqueada para venta',
]);
