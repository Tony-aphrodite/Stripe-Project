<?php
/**
 * POST — Lock/unlock a motorcycle from being sold
 * Body: { moto_id, bloqueado: 1|0, motivo: "reason text" }
 * Roles: admin, cedis
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$motoId    = (int)($d['moto_id'] ?? 0);
$bloqueado = (int)($d['bloqueado'] ?? 0);
$motivo    = trim($d['motivo'] ?? '');

if (!$motoId) adminJsonOut(['error' => 'moto_id requerido'], 400);
if ($bloqueado && !$motivo) adminJsonOut(['error' => 'Motivo de bloqueo requerido'], 400);

$pdo = getDB();

$stmt = $pdo->prepare("SELECT id, vin_display, vin, modelo, color, bloqueado_venta FROM inventario_motos WHERE id=? AND activo=1");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) adminJsonOut(['error' => 'Moto no encontrada'], 404);

if ($bloqueado) {
    $pdo->prepare("UPDATE inventario_motos SET bloqueado_venta=1, bloqueado_motivo=?, bloqueado_por=?, bloqueado_fecha=NOW() WHERE id=?")
        ->execute([$motivo, $uid, $motoId]);
} else {
    $pdo->prepare("UPDATE inventario_motos SET bloqueado_venta=0, bloqueado_motivo=NULL, bloqueado_por=NULL, bloqueado_fecha=NULL WHERE id=?")
        ->execute([$motoId]);
}

adminLog($bloqueado ? 'moto_bloqueada_venta' : 'moto_desbloqueada_venta', [
    'moto_id' => $motoId,
    'vin' => $moto['vin_display'] ?? $moto['vin'],
    'motivo' => $bloqueado ? $motivo : 'Desbloqueado',
]);

adminJsonOut([
    'ok' => true,
    'bloqueado_venta' => $bloqueado,
    'message' => $bloqueado ? 'Moto bloqueada para venta' : 'Moto desbloqueada para venta',
]);
