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

// Per dashboards_diagrams.pdf CASE 1: moto must be assigned ONLY after payment
// is successful. Use `pago_estado` as the source of truth (set by the Stripe
// webhook on payment_intent.succeeded, regardless of payment method).
//
// `tpago` is NOT a whitelist — it varies by payment instrument (contado, unico,
// msi, spei, oxxo, credito, enganche, parcial) and past attempts to gate on it
// blocked legitimate paid orders. Credit-family orders only require the
// enganche to be captured (reflected as stripe_pi present + pago_estado in
// 'parcial'|'pagada').
$tpago      = strtolower(trim($order['tpago']       ?? ''));
$pagoEstadoOrder = strtolower(trim($order['pago_estado'] ?? ''));
$isCreditFamily  = in_array($tpago, ['credito', 'enganche', 'parcial'], true);

$pagoOk = false;
if (in_array($pagoEstadoOrder, ['pagada', 'aprobada', 'approved', 'paid'], true)) {
    $pagoOk = true;
} elseif ($isCreditFamily && $pagoEstadoOrder === 'parcial' && !empty($order['stripe_pi'])) {
    // Credit: enganche captured, rest collected via subscription
    $pagoOk = true;
} elseif (!$pagoEstadoOrder && !empty($order['stripe_pi'])) {
    // Legacy rows written before pago_estado column existed: trust stripe_pi
    // (PaymentIntent id stored only on successful/confirmed payments).
    $pagoOk = true;
}

if (!$pagoOk) {
    $msg = 'El pago de esta orden aún no ha sido confirmado.';
    if ($pagoEstadoOrder === 'pendiente' || $pagoEstadoOrder === '') {
        $msg .= ' Espera unos minutos a que Stripe confirme la transacción e intenta de nuevo. Si el cliente pagó por SPEI/OXXO puede tardar varias horas.';
    } elseif ($pagoEstadoOrder === 'fallido' || $pagoEstadoOrder === 'cancelada') {
        $msg .= ' El pago fue rechazado o cancelado — esta orden no puede recibir una moto.';
    }
    adminJsonOut(['error' => $msg], 403);
}

// ── Check order doesn't already have a bike assigned ────────────────────
$pedidoCheck = 'VK-' . $order['pedido'];
$existingMoto = $pdo->prepare("
    SELECT id, vin_display FROM inventario_motos
    WHERE pedido_num = ? AND activo = 1
    LIMIT 1
");
$existingMoto->execute([$pedidoCheck]);
$alreadyAssigned = $existingMoto->fetch(PDO::FETCH_ASSOC);
if ($alreadyAssigned) {
    adminJsonOut([
        'error' => 'Esta orden ya tiene una moto asignada (VIN: ' . ($alreadyAssigned['vin_display'] ?? '?') . '). Desasígnala primero antes de asignar otra.'
    ], 409);
}

// ── Fetch the bike ───────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ? AND activo = 1 LIMIT 1");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) {
    adminJsonOut(['error' => 'Moto no encontrada'], 404);
}

// Rule: checklist_origen must be complete before assigning a bike.
$co = $pdo->prepare("SELECT completado FROM checklist_origen WHERE moto_id = ? ORDER BY freg DESC LIMIT 1");
$co->execute([$motoId]);
$coRow = $co->fetch(PDO::FETCH_ASSOC);
if (!$coRow || !$coRow['completado']) {
    adminJsonOut([
        'error' => 'El checklist de origen no está completo para esta moto. Debe completarse antes de asignar.'
    ], 403);
}

// Rule: sale-locked motos cannot be assigned.
if (!empty($moto['bloqueado_venta'])) {
    adminJsonOut([
        'error' => 'Esta moto está bloqueada para venta. Motivo: ' . ($moto['bloqueado_motivo'] ?? 'Sin motivo') . '. Desbloquéala primero.'
    ], 403);
}

// Block phantom VINs created by the old confirmar-orden.php auto-INSERT.
// These are virtual records (VK-{MODEL}-{timestamp}-{hex}), not real bikes.
if (preg_match('/^VK-[A-Z0-9]+-\d+-[a-f0-9]+$/i', $moto['vin'] ?? '')) {
    adminJsonOut([
        'error' => 'Esta moto es un registro virtual (VIN: ' . ($moto['vin'] ?? '') . '). Solo se pueden asignar motos reales del inventario físico.'
    ], 409);
}

// Check bike is not already assigned
if (!empty($moto['pedido_num']) || !empty($moto['cliente_email'])) {
    adminJsonOut(['error' => 'Esta moto ya está asignada a otra orden (pedido: ' . ($moto['pedido_num'] ?: 'N/A') . ')'], 409);
}

// Per dashboards_diagrams.pdf CASE 1/3 step: "the model and color needs to be
// the same in the order". Block mismatched assignments so CEDIS cannot ship the
// wrong bike to a customer.
//
// Legacy-origin orders store "Voltika Tromox Pesgo" / "Gris moderno" while
// inventory uses short codes. Go through voltikaNormalizeModelo/Color so both
// sides collapse to the same canonical value — otherwise a perfectly valid
// assignment would be rejected as "modelo no coincide".
$normM = static function ($v) { return strtolower(trim(voltikaNormalizeModelo($v))); };
$normC = static function ($v) { return strtolower(trim(voltikaNormalizeColor($v))); };
$orderModelo = $normM($order['modelo'] ?? '');
$orderColor  = $normC($order['color']  ?? '');
$motoModelo  = $normM($moto['modelo']  ?? '');
$motoColor   = $normC($moto['color']   ?? '');

if ($orderModelo && $motoModelo && $orderModelo !== $motoModelo) {
    adminJsonOut([
        'error' => 'El modelo de la moto (' . ($moto['modelo'] ?? '?') . ') no coincide con el de la orden (' . ($order['modelo'] ?? '?') . ').'
    ], 409);
}
if ($orderColor && $motoColor && $orderColor !== $motoColor) {
    adminJsonOut([
        'error' => 'El color de la moto (' . ($moto['color'] ?? '?') . ') no coincide con el de la orden (' . ($order['color'] ?? '?') . ').'
    ], 409);
}

// Per dashboards_diagrams.pdf diagram 5: motos sent to a point as `consignacion`
// (showroom stock) can only be sold by the point via its CODIGO REFERIDO (CASE 4).
// CEDIS must NOT pull a showroom-pool moto to fulfill a general order.
if (($moto['tipo_asignacion'] ?? '') === 'consignacion') {
    adminJsonOut([
        'error' => 'Esta moto está en consignación (inventario de showroom del punto) y solo puede venderse por el punto con su código de referido.'
    ], 409);
}

// Per diagram: only motos in a free-to-assign state may be picked. Accept the
// states that represent "bike in the delivery inventory pool, not yet linked to
// a customer": por_llegar / recibida / en_ensamble / lista_para_entrega.
$estadoMoto = strtolower(trim($moto['estado'] ?? ''));
$estadosLibres = ['por_llegar', 'recibida', 'en_ensamble', 'lista_para_entrega'];
if ($estadoMoto && !in_array($estadoMoto, $estadosLibres, true)) {
    adminJsonOut([
        'error' => 'Esta moto no está en un estado asignable (estado=' . $estadoMoto . ').'
    ], 409);
}

// ── Assign ───────────────────────────────────────────────────────────────
$pedidoNum = 'VK-' . $order['pedido'];

// Credit-family orders only have enganche paid at this point → mark 'parcial'.
// Every other tpago (contado, unico, msi, spei, oxxo, ...) is a fully settled
// single payment when pago_estado='pagada' — mark as 'pagada'.
$pagoEstado = $isCreditFamily ? 'parcial' : 'pagada';

// Resolve punto_voltika_id from order's punto_id or punto_nombre
$puntoVoltId = null;
$orderPuntoId = $order['punto_id'] ?? '';
$orderPuntoNombre = $order['punto_nombre'] ?? '';
if ($orderPuntoId && $orderPuntoId !== 'centro-cercano') {
    if (is_numeric($orderPuntoId)) {
        $puntoVoltId = (int)$orderPuntoId;
    } else {
        $pLook = $pdo->prepare("SELECT id FROM puntos_voltika WHERE nombre = ? AND activo = 1 LIMIT 1");
        $pLook->execute([$orderPuntoNombre ?: $orderPuntoId]);
        $pRow = $pLook->fetch(PDO::FETCH_ASSOC);
        if ($pRow) $puntoVoltId = (int)$pRow['id'];
    }
} elseif ($orderPuntoNombre) {
    $pLook = $pdo->prepare("SELECT id FROM puntos_voltika WHERE nombre = ? AND activo = 1 LIMIT 1");
    $pLook->execute([$orderPuntoNombre]);
    $pRow = $pLook->fetch(PDO::FETCH_ASSOC);
    if ($pRow) $puntoVoltId = (int)$pRow['id'];
}

$stmt = $pdo->prepare("
    UPDATE inventario_motos SET
        cliente_nombre   = ?,
        cliente_email    = ?,
        cliente_telefono = ?,
        pedido_num       = ?,
        stripe_pi        = ?,
        pago_estado      = ?,
        punto_voltika_id = ?,
        tipo_asignacion  = 'entrega_con_orden',
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
    $pagoEstado,
    $puntoVoltId,
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
