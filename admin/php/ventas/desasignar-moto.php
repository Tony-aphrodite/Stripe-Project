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
//
// Customer brief 2026-05-09 (Óscar — "The system allows you to unassign
// a motorcycle that has already been delivered, and that's wrong"):
// the single estado='entregada' check missed three failure modes:
//   (a) cliente_acta_firmada=1 was set but estado lagged behind (race
//       between firmar-acta.php and finalizar.php)
//   (b) an entregas / acta row exists pointing at this moto even though
//       inventario_motos.estado was manually changed back
//   (c) the punto's finalizar-entrega flow already wrote estado='entregada'
//       earlier and a later manual edit re-set it to 'recibida'
// Belt-and-suspenders: refuse if ANY delivery evidence exists. The audit
// log records which signal blocked the release so support can debug.
$est = strtolower(trim($moto['estado'] ?? ''));
$actaFirmada = (int)($moto['cliente_acta_firmada'] ?? 0) === 1;
$entregaCompleta = false;
try {
    // entrega_punto / entrega rows have a `completada` or `estado='entregada'`
    // marker depending on schema age — check both forms.
    $envCols = $pdo->query("SHOW TABLES LIKE 'entregas'")->fetchColumn();
    if ($envCols) {
        $entCols = $pdo->query("SHOW COLUMNS FROM entregas")->fetchAll(PDO::FETCH_COLUMN);
        $estadoCol = in_array('estado', $entCols, true);
        $completaCol = in_array('completada', $entCols, true);
        $cond = [];
        if ($estadoCol)   $cond[] = "estado = 'entregada'";
        if ($completaCol) $cond[] = "completada = 1";
        if ($cond) {
            $sql = "SELECT 1 FROM entregas WHERE moto_id = ? AND (" . implode(' OR ', $cond) . ") LIMIT 1";
            $eStmt = $pdo->prepare($sql);
            $eStmt->execute([(int)$moto['id']]);
            $entregaCompleta = (bool)$eStmt->fetchColumn();
        }
    }
} catch (Throwable $e) { error_log('desasignar entregas check: ' . $e->getMessage()); }

if ($est === 'entregada' || $actaFirmada || $entregaCompleta) {
    $reasons = [];
    if ($est === 'entregada')   $reasons[] = "estado=entregada";
    if ($actaFirmada)           $reasons[] = "cliente_acta_firmada=1";
    if ($entregaCompleta)       $reasons[] = "entregas.completada=1";
    adminJsonOut([
        'error' => 'La moto ya fue entregada al cliente. No se puede desasignar.',
        'reasons' => $reasons,
        'moto_id' => (int)$moto['id'],
        'vin'     => $moto['vin_display'] ?? $moto['vin'] ?? '',
    ], 409);
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
