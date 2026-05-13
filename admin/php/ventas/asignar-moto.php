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
// Customer brief 2026-05-04 ("where can we reassign a moto"): when the
// caller sends replace_previous_moto_id, release that moto BEFORE the
// duplicate-assignment guard fires. The frontend's showReasignar()
// shows a confirm dialog and forwards the previously-linked moto's id
// in this field; without it the existing 409 "Esta orden ya tiene una
// moto asignada" guard kicks in and reassignment is impossible.
$prevMotoId = (int)($body['replace_previous_moto_id'] ?? 0);

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

// ── Reassignment: release the previously-linked moto first ─────────────
// When replace_previous_moto_id is provided we treat this as a swap:
// clear the customer/pedido fields off the OLD moto so the duplicate-
// assignment guard below doesn't reject the new assignment.
if ($prevMotoId > 0) {
    $rel = $pdo->prepare("
        UPDATE inventario_motos
           SET cliente_nombre   = NULL,
               cliente_email    = NULL,
               cliente_telefono = NULL,
               pedido_num       = NULL,
               stripe_pi        = NULL,
               pago_estado      = NULL,
               tipo_asignacion  = NULL,
               punto_voltika_id = NULL,
               fmod             = NOW()
         WHERE id = ? AND activo = 1");
    $rel->execute([$prevMotoId]);
    adminLog('moto_reasignacion_release', [
        'moto_id_liberada' => $prevMotoId,
        'transaccion_id'   => $transId,
        'admin_id'         => $adminId,
    ]);
}

// ── Check order doesn't already have a bike assigned ────────────────────
// Customer brief 2026-05-04 round 5: when the Ventas dashboard's JOIN
// (matched on `pedido_num = CONCAT('VK-', t.pedido)`) fails because
// pedido vs pedido_corto formats drift, the row shows "Sin asignar"
// even though the bike IS linked. Admin clicks Asignar, this guard
// fires, and the user is stuck. The error response now carries the
// conflicting moto_id so the frontend can offer a one-click
// "release & reassign" workflow without forcing the admin into the
// database.
$pedidoCheck = 'VK-' . $order['pedido'];
$existingMoto = $pdo->prepare("
    SELECT id, vin_display, vin, pedido_num FROM inventario_motos
    WHERE pedido_num = ? AND activo = 1
    LIMIT 1
");
$existingMoto->execute([$pedidoCheck]);
$alreadyAssigned = $existingMoto->fetch(PDO::FETCH_ASSOC);
if ($alreadyAssigned) {
    adminJsonOut([
        'error'              => 'Esta orden ya tiene una moto asignada (VIN: ' . ($alreadyAssigned['vin_display'] ?? '?') . ').',
        'error_code'         => 'order_already_assigned',
        'conflict_moto_id'   => (int)$alreadyAssigned['id'],
        'conflict_vin'       => $alreadyAssigned['vin_display'] ?? $alreadyAssigned['vin'] ?? '',
        'conflict_pedido_num'=> $alreadyAssigned['pedido_num'] ?? '',
        'hint'               => 'Reintenta con replace_previous_moto_id=' . (int)$alreadyAssigned['id'] . ' para liberar la moto actual y asignar la nueva en una sola operación.',
    ], 409);
}

// ── Fetch the bike ───────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ? AND activo = 1 LIMIT 1");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) {
    adminJsonOut(['error' => 'Moto no encontrada'], 404);
}

// Customer brief 2026-04-30: checklist_origen requirement removed. Sales can
// proceed before physical inspection; CEDIS completes the checklist after
// the order is placed. Only `bloqueado_venta` still blocks assignment.

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
// Customer brief 2026-05-07: voltikaNormalizePedidoNum prevents double
// "VK-VK-" prefix when t.pedido already carried "VK-" (mostly legacy
// rows). Without this the dashboard JOIN failed and Ventas displayed
// "Sin moto asignada" while CEDIS / inventario rendered the link.
//
// Customer brief 2026-05-09 (Óscar — "La orden no tiene pedido válido"
// on VK-2605-0002): some rows have the legacy `pedido` field empty
// while the customer-facing short code is stored under `pedido_corto`.
// Normalising only `pedido` made these orders unassignable even though
// the dashboard still listed them by their short code. Fall back to
// `pedido_corto`, then to the row id, so a real assignment never gets
// blocked by an empty legacy column. We only refuse when EVERY
// identifier is missing — at that point the row is genuinely
// unidentifiable.
// Customer brief 2026-05-12 (Óscar, 8th round — screenshot still shows
// "La orden no tiene pedido válido" on VK-2605-0002 despite previous
// fallbacks): expanded the chain so even rows with hostile data shapes
// (whitespace-only pedido, "0" string, missing column) still resolve.
// We also include a diagnostic detail in the error response so future
// occurrences are immediately debuggable from the network tab.
$tryPedido      = trim((string)($order['pedido']       ?? ''));
$tryPedidoCorto = trim((string)($order['pedido_corto'] ?? ''));

// 1) Raw pedido (e.g. "1778302204-2d66242e") → "VK-1778302204-2d66242e"
$pedidoNum = ($tryPedido !== '' && $tryPedido !== '0')
    ? voltikaNormalizePedidoNum($tryPedido)
    : '';
// 2) pedido_corto (e.g. "2605-0002" or "VK-2605-0002") → normalized
if ($pedidoNum === '' && $tryPedidoCorto !== '' && $tryPedidoCorto !== '0') {
    $pedidoNum = voltikaNormalizePedidoNum($tryPedidoCorto);
}
// 3) Synthetic TX-id key for orders where every pedido column is empty
if ($pedidoNum === '' && !empty($order['id'])) {
    $pedidoNum = 'VK-TX' . (int)$order['id'];
}
if ($pedidoNum === '') {
    adminJsonOut([
        'error'  => 'La orden no tiene pedido válido — no se puede vincular la moto.',
        'detail' => [
            'transaccion_id'  => $transId,
            'pedido_raw'      => $tryPedido,
            'pedido_corto_raw'=> $tryPedidoCorto,
            'order_id'        => $order['id'] ?? null,
            'columns_present' => array_keys($order),
        ],
    ], 400);
}

// Credit-family orders only have enganche paid at this point → mark 'parcial'.
// Every other tpago (contado, unico, msi, spei, oxxo, ...) is a fully settled
// single payment when pago_estado='pagada' — mark as 'pagada'.
$pagoEstado = $isCreditFamily ? 'parcial' : 'pagada';

// Customer brief 2026-05-13 (Óscar, 13th round — "el cliente no ve su
// compra en su portal"): until today this UPDATE wrote cliente_nombre /
// email / telefono but NOT cliente_id. The client portal queries
// inventario_motos.cliente_id, so assigned motos were invisible to the
// owner. We now resolve cliente_id by matching email or phone against
// the clientes table — the same identity link clientes/ uses for login.
$resolvedClienteId = null;
try {
    $emailLk = trim((string)($order['email']    ?? ''));
    $telLk   = preg_replace('/\D/', '', (string)($order['telefono'] ?? ''));
    if (strlen($telLk) > 10) $telLk = substr($telLk, -10);
    if ($emailLk !== '' || $telLk !== '') {
        $cWh = []; $cPv = [];
        if ($emailLk !== '') {
            $cWh[] = "LOWER(email) = LOWER(?)";
            $cPv[] = $emailLk;
        }
        if ($telLk !== '') {
            $cWh[] = "RIGHT(REPLACE(REPLACE(REPLACE(COALESCE(telefono,''),'+',''),' ',''),'-',''), 10) = ?";
            $cPv[] = $telLk;
        }
        $cLookup = $pdo->prepare("SELECT id FROM clientes WHERE " . implode(' OR ', $cWh) . " ORDER BY id ASC LIMIT 1");
        $cLookup->execute($cPv);
        $resolvedClienteId = (int)($cLookup->fetchColumn() ?: 0) ?: null;
    }
} catch (Throwable $e) {
    error_log('asignar-moto cliente_id lookup: ' . $e->getMessage());
}

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

// Customer brief 2026-05-13: also set transaccion_id + cliente_id so
// the client portal can find this moto via the same identity link the
// rest of the system uses. Detect whether those columns exist on this
// install (older schemas may be missing them) and build the UPDATE
// dynamically so legacy installs don't break.
$updateSets = [
    'cliente_nombre = ?',
    'cliente_email = ?',
    'cliente_telefono = ?',
    'pedido_num = ?',
    'stripe_pi = ?',
    'pago_estado = ?',
    'punto_voltika_id = ?',
    "tipo_asignacion = 'entrega_con_orden'",
    'fecha_estado = NOW()',
    'fmod = NOW()',
];
$updateVals = [
    $order['nombre']   ?? '',
    $order['email']    ?? '',
    $order['telefono'] ?? '',
    $pedidoNum,
    $order['stripe_pi'] ?? '',
    $pagoEstado,
    $puntoVoltId,
];
try {
    $imCols = $pdo->query("SHOW COLUMNS FROM inventario_motos")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('cliente_id', $imCols, true) && $resolvedClienteId) {
        $updateSets[] = 'cliente_id = ?';
        $updateVals[] = $resolvedClienteId;
    }
    if (in_array('transaccion_id', $imCols, true)) {
        $updateSets[] = 'transaccion_id = ?';
        $updateVals[] = (int)$order['id'];
    }
} catch (Throwable $e) { /* optional columns */ }
$updateVals[] = $motoId;

$stmt = $pdo->prepare("UPDATE inventario_motos SET " . implode(', ', $updateSets) . " WHERE id = ?");
$stmt->execute($updateVals);

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
