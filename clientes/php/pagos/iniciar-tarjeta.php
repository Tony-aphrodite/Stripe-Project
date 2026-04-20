<?php
/**
 * Voltika Portal - Start card payment via Stripe Checkout
 * Creates a Checkout Session in payment mode and returns the URL. The frontend
 * redirects the user there; Stripe collects the card, confirms the payment,
 * and redirects back to success_url. Ciclos get marked paid by the webhook on
 * payment_intent.succeeded using the metadata we attach to the PI below.
 */
require_once __DIR__ . '/../bootstrap.php';
$cid = portalRequireAuth();

$in          = portalJsonIn();
$tipo        = $in['tipo'] ?? 'semanal';
$montoCustom = (float)($in['monto'] ?? 0);

if (!defined('STRIPE_SECRET_KEY') || !STRIPE_SECRET_KEY) {
    portalJsonOut(['error' => 'Stripe no está configurado en el servidor'], 500);
}

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM subscripciones_credito
    WHERE cliente_id = ? AND (estado IS NULL OR estado NOT IN ('cancelada','liquidada'))
    ORDER BY id DESC LIMIT 1");
$stmt->execute([$cid]);
$sub = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sub) portalJsonOut(['error' => 'No tienes una cuenta activa'], 404);

portalEnsureCiclos($sub);

$stmt = $pdo->prepare("SELECT * FROM ciclos_pago
    WHERE subscripcion_id = ? AND estado IN ('pending','overdue')
    ORDER BY semana_num ASC");
$stmt->execute([$sub['id']]);
$pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($pendientes)) portalJsonOut(['error' => 'No hay pagos pendientes'], 400);

$numCiclos = match ($tipo) {
    'dos_semanas' => 2,
    'adelanto'    => max(1, (int)($in['num_semanas'] ?? 4)),
    default       => 1,
};
$aPagar = ($tipo === 'adelanto')
    ? array_slice($pendientes, -$numCiclos)
    : array_slice($pendientes, 0, $numCiclos);

$monto = 0;
foreach ($aPagar as $c) $monto += (float)$c['monto'];
if ($montoCustom > 0) $monto = $montoCustom;

$amountCents = (int)round($monto * 100);
if ($amountCents <= 0) portalJsonOut(['error' => 'Monto inválido'], 400);

// ── Resolve cliente info ────────────────────────────────────────────────────
$cliRow = $pdo->prepare("SELECT nombre, email, telefono FROM clientes WHERE id = ?");
$cliRow->execute([$cid]);
$cli = $cliRow->fetch(PDO::FETCH_ASSOC) ?: [];
$customer = $sub['stripe_customer_id'] ?? '';

$baseUrl = (defined('VOLTIKA_BASE_URL') && VOLTIKA_BASE_URL)
    ? rtrim(VOLTIKA_BASE_URL, '/')
    : 'https://voltika.mx';
$successUrl = $baseUrl . '/clientes/?pago=ok&session_id={CHECKOUT_SESSION_ID}';
$cancelUrl  = $baseUrl . '/clientes/?pago=cancelado';

$ciclosCsv = implode(',', array_column($aPagar, 'semana_num'));
$descripcion = 'Voltika - Pago semanal ' .
    (count($aPagar) > 1 ? (count($aPagar) . ' semanas') : ('semana ' . $aPagar[0]['semana_num']));

// ── Build Checkout Session ──────────────────────────────────────────────────
$payload = [
    'mode' => 'payment',
    'payment_method_types[0]' => 'card',
    'success_url' => $successUrl,
    'cancel_url'  => $cancelUrl,

    'line_items[0][quantity]' => 1,
    'line_items[0][price_data][currency]'    => 'mxn',
    'line_items[0][price_data][unit_amount]' => $amountCents,
    'line_items[0][price_data][product_data][name]'        => $descripcion,
    'line_items[0][price_data][product_data][description]' => 'Cliente #' . $cid . ' · Ciclos ' . $ciclosCsv,

    // Attach the same metadata to the underlying PaymentIntent so the
    // existing webhook (handleCiclosPagoUpdate) can mark ciclos paid_manual
    // using metadata.subscripcion_id + metadata.ciclos.
    'payment_intent_data[metadata][cliente_id]'      => $cid,
    'payment_intent_data[metadata][subscripcion_id]' => $sub['id'],
    'payment_intent_data[metadata][tipo]'    => $tipo,
    'payment_intent_data[metadata][ciclos]'  => $ciclosCsv,
    'payment_intent_data[metadata][origen]'  => 'portal_tarjeta',
    'payment_intent_data[description]'       => $descripcion,

    'metadata[cliente_id]'      => $cid,
    'metadata[subscripcion_id]' => $sub['id'],
    'metadata[tipo]'    => $tipo,
    'metadata[ciclos]'  => $ciclosCsv,
    'metadata[origen]'  => 'portal_tarjeta',
];
if ($customer)      $payload['customer']       = $customer;
elseif (!empty($cli['email'])) $payload['customer_email'] = $cli['email'];

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($payload),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . STRIPE_SECRET_KEY,
        'Content-Type: application/x-www-form-urlencoded',
    ],
    CURLOPT_TIMEOUT => 20,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$data = json_decode((string)$resp, true) ?: [];

$logFile = __DIR__ . '/../../../configurador_prueba_test/php/logs/portal-pagos.log';
@file_put_contents($logFile, json_encode([
    'ts' => date('c'), 'cliente' => $cid, 'sub' => $sub['id'], 'kind' => 'tarjeta_checkout',
    'amount' => $amountCents, 'httpCode' => $code, 'id' => $data['id'] ?? null,
    'error' => $data['error']['message'] ?? null,
]) . "\n", FILE_APPEND | LOCK_EX);

if ($code < 200 || $code >= 300 || empty($data['url'])) {
    $err = $data['error']['message'] ?? 'No se pudo iniciar el pago con tarjeta';
    portalLog('tarjeta_fail', ['success' => 0, 'detalle' => $err]);
    portalJsonOut(['error' => $err, 'stripe' => $data['error'] ?? null], 402);
}

portalLog('tarjeta_ok', ['success' => 1, 'detalle' => 'session=' . ($data['id'] ?? '') . ' $' . $monto]);
portalJsonOut([
    'status' => 'ok',
    'url'    => $data['url'],
    'session_id' => $data['id'] ?? '',
    'monto'  => $monto,
    'ciclos' => array_column($aPagar, 'semana_num'),
    'tipo'   => $tipo,
]);
