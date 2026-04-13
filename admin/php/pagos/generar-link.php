<?php
/**
 * POST — Generate a Stripe payment link for a specific cycle
 * Body: { "ciclo_id": 123 }
 * Returns a Stripe-hosted payment page URL
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$cicloId = (int)($d['ciclo_id'] ?? 0);
if (!$cicloId) adminJsonOut(['error' => 'ciclo_id requerido'], 400);

$pdo = getDB();

$stmt = $pdo->prepare("
    SELECT c.*, s.stripe_customer_id, COALESCE(s.nombre, cl.nombre, '') as nombre, s.email, s.telefono
    FROM ciclos_pago c
    LEFT JOIN subscripciones_credito s ON c.subscripcion_id = s.id
    LEFT JOIN clientes cl ON c.cliente_id = cl.id
    WHERE c.id = ?
");
$stmt->execute([$cicloId]);
$ciclo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ciclo) adminJsonOut(['error' => 'Ciclo no encontrado'], 404);
if (in_array($ciclo['estado'], ['paid_auto','paid_manual'])) {
    adminJsonOut(['error' => 'Este ciclo ya fue pagado'], 400);
}

$stripeKey = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : (getenv('STRIPE_SECRET_KEY') ?: '');
if (!$stripeKey) adminJsonOut(['error' => 'Stripe no configurado'], 500);

$amount = (int)(round($ciclo['monto'] * 100));

// Create a Checkout Session for one-time payment
$postData = [
    'mode'                        => 'payment',
    'payment_method_types[0]'     => 'card',
    'payment_method_types[1]'     => 'oxxo',
    'line_items[0][price_data][currency]'     => 'mxn',
    'line_items[0][price_data][product_data][name]' => 'Voltika - Pago ciclo #' . $ciclo['semana_num'],
    'line_items[0][price_data][unit_amount]'  => $amount,
    'line_items[0][quantity]'     => 1,
    'metadata[ciclo_id]'          => $cicloId,
    'metadata[tipo]'              => 'link_pago',
    'success_url'                 => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/clientes/?pago=ok',
    'cancel_url'                  => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/clientes/?pago=cancelado',
];

if (!empty($ciclo['stripe_customer_id'])) {
    $postData['customer'] = $ciclo['stripe_customer_id'];
}
if (!empty($ciclo['email'])) {
    $postData['customer_email'] = $ciclo['email'];
}

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => $stripeKey . ':',
    CURLOPT_POSTFIELDS     => http_build_query($postData),
]);
$raw = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$resp = json_decode($raw, true);

if ($httpCode >= 200 && $httpCode < 300 && !empty($resp['url'])) {
    adminLog('generar_link_pago', [
        'ciclo_id'   => $cicloId,
        'session_id' => $resp['id'],
        'monto'      => $ciclo['monto'],
    ]);
    adminJsonOut([
        'ok'         => true,
        'url'        => $resp['url'],
        'session_id' => $resp['id'],
    ]);
} else {
    $err = $resp['error']['message'] ?? 'Error al crear link de pago';
    adminJsonOut(['ok' => false, 'error' => $err]);
}
