<?php
/**
 * POST — Manually release (desasignar) a bike from a purchase order.
 * Body: { "transaccion_id": 123 }  or  { "moto_id": 456 }
 *
 * Customer brief 2026-05-06: Sales must be able to detach a unit from
 * an order without going through the full re-assignment flow. The
 * release MUST be reflected in CEDIS inventory — i.e. the moto becomes
 * available again for picking. We clear the customer/pedido fields on
 * inventario_motos so the dashboard JOIN in listar.php no longer ties
 * the unit to the order.
 */
require_once __DIR__ . '/../bootstrap.php';
$adminId = adminRequireAuth(['admin','cedis']);

$pdo  = getDB();
$body = adminJsonIn();

$transId = (int)($body['transaccion_id'] ?? 0);
$motoId  = (int)($body['moto_id'] ?? 0);

if (!$transId && !$motoId) {
    adminJsonOut(['error' => 'transaccion_id o moto_id es requerido'], 400);
}

// Resolve the moto to release. Prefer moto_id when supplied; otherwise
// look up by pedido_num so we can use the same JOIN logic as Ventas.
$moto = null;
if ($motoId) {
    $st = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ? AND activo = 1 LIMIT 1");
    $st->execute([$motoId]);
    $moto = $st->fetch(PDO::FETCH_ASSOC);
} elseif ($transId) {
    $stOrd = $pdo->prepare("SELECT pedido FROM transacciones WHERE id = ? LIMIT 1");
    $stOrd->execute([$transId]);
    $ord = $stOrd->fetch(PDO::FETCH_ASSOC);
    if (!$ord) {
        adminJsonOut(['error' => 'Orden no encontrada'], 404);
    }
    $pedidoNum = 'VK-' . $ord['pedido'];
    $st = $pdo->prepare("SELECT * FROM inventario_motos WHERE pedido_num = ? AND activo = 1 LIMIT 1");
    $st->execute([$pedidoNum]);
    $moto = $st->fetch(PDO::FETCH_ASSOC);
}

if (!$moto) {
    adminJsonOut(['error' => 'No se encontró ninguna moto asignada a esta orden.'], 404);
}

// Block desasignar if the moto is already entregada — at that point it
// has left inventory and the operation makes no sense.
$est = strtolower(trim($moto['estado'] ?? ''));
if ($est === 'entregada') {
    adminJsonOut(['error' => 'La moto ya fue entregada al cliente. No se puede desasignar.'], 409);
}

$rel = $pdo->prepare("
    UPDATE inventario_motos SET
        cliente_nombre   = NULL,
        cliente_email    = NULL,
        cliente_telefono = NULL,
        pedido_num       = NULL,
        stripe_pi        = NULL,
        pago_estado      = NULL,
        tipo_asignacion  = NULL,
        punto_voltika_id = NULL,
        fmod             = NOW()
    WHERE id = ? AND activo = 1
");
$rel->execute([(int)$moto['id']]);

adminLog('desasignar_moto', [
    'moto_id'        => (int)$moto['id'],
    'vin'            => $moto['vin_display'] ?? $moto['vin'] ?? '',
    'pedido_num'     => $moto['pedido_num'] ?? '',
    'transaccion_id' => $transId,
    'admin_id'       => $adminId,
]);

adminJsonOut([
    'ok'         => true,
    'message'    => 'Moto ' . ($moto['vin_display'] ?? $moto['vin'] ?? '#'.$moto['id']) . ' liberada y devuelta al inventario.',
    'moto_id'    => (int)$moto['id'],
    'vin'        => $moto['vin_display'] ?? $moto['vin'] ?? '',
]);
