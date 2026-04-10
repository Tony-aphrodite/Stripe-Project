<?php
/**
 * POST — Manually assign a bike to a purchase order
 * Body: { "transaccion_id": 123, "moto_id": 456 }
 *
 * Links inventario_motos to the transaccion by writing customer data
 * + stripe_pi + pedido_num onto the bike record.
 */
require_once __DIR__ . '/../bootstrap.php';
$adminId = adminRequireAuth(['admin','cedis']);

$pdo  = getDB();
$body = adminJsonIn();

$transId = (int)($body['transaccion_id'] ?? 0);
$motoId  = (int)($body['moto_id'] ?? 0);

if (!$transId || !$motoId) {
    adminJsonOut(['error' => 'transaccion_id y moto_id son requeridos'], 400);
}

// ── Fetch the order ──────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM transacciones WHERE id = ? LIMIT 1");
$stmt->execute([$transId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    adminJsonOut(['error' => 'Orden no encontrada'], 404);
}

// ── Fetch the bike ───────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ? AND activo = 1 LIMIT 1");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) {
    adminJsonOut(['error' => 'Moto no encontrada'], 404);
}

// Check bike is not already assigned
if (!empty($moto['pedido_num']) || !empty($moto['cliente_email'])) {
    adminJsonOut(['error' => 'Esta moto ya está asignada a otra orden (pedido: ' . ($moto['pedido_num'] ?: 'N/A') . ')'], 409);
}

// ── Assign ───────────────────────────────────────────────────────────────
$pedidoNum = 'VK-' . $order['pedido'];

$stmt = $pdo->prepare("
    UPDATE inventario_motos SET
        cliente_nombre   = ?,
        cliente_email    = ?,
        cliente_telefono = ?,
        pedido_num       = ?,
        stripe_pi        = ?,
        pago_estado      = 'pagada',
        fecha_estado     = NOW(),
        fmod             = NOW()
    WHERE id = ?
");
$stmt->execute([
    $order['nombre']   ?? '',
    $order['email']    ?? '',
    $order['telefono'] ?? '',
    $pedidoNum,
    $order['stripe_pi'] ?? '',
    $motoId,
]);

// ── Log ──────────────────────────────────────────────────────────────────
adminLog('asignar_moto', [
    'transaccion_id' => $transId,
    'moto_id'        => $motoId,
    'vin'            => $moto['vin_display'] ?? $moto['vin'] ?? '',
    'pedido'         => $pedidoNum,
    'cliente'        => $order['nombre'] ?? '',
]);

adminJsonOut([
    'ok'      => true,
    'message' => 'Moto ' . ($moto['vin_display'] ?? $moto['vin']) . ' asignada a pedido ' . $pedidoNum,
    'moto_id' => $motoId,
    'vin'     => $moto['vin_display'] ?? $moto['vin'],
]);
