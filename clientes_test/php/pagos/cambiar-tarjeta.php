<?php
/**
 * POST — Create a Stripe Checkout Session in setup mode so the customer can
 * register a new backup card. The previous card stays attached until the
 * webhook sets the new default + detaches the old one.
 *
 * Response: { url } — frontend redirects there.
 */
require_once __DIR__ . '/../bootstrap.php';
$cid = portalRequireAuth();
$pdo = getDB();

$sub = null;
try {
    $stmt = $pdo->prepare("SELECT stripe_customer_id FROM subscripciones_credito
        WHERE cliente_id = ? AND stripe_customer_id IS NOT NULL
        ORDER BY id DESC LIMIT 1");
    $stmt->execute([$cid]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) { error_log('cambiar-tarjeta sub: ' . $e->getMessage()); }

if (!$sub || empty($sub['stripe_customer_id'])) {
    portalJsonOut(['error' => 'No tienes una suscripción activa con cliente Stripe.'], 400);
}

if (!defined('STRIPE_SECRET_KEY') || !STRIPE_SECRET_KEY) {
    portalJsonOut(['error' => 'Stripe no está configurado en el servidor'], 500);
}

// Build Checkout Session in setup mode
$baseUrl = (defined('VOLTIKA_BASE_URL') && VOLTIKA_BASE_URL)
    ? rtrim(VOLTIKA_BASE_URL, '/')
    : 'https://voltika.mx';
$successUrl = $baseUrl . '/clientes/?cambio_tarjeta=ok';
$cancelUrl  = $baseUrl . '/clientes/?cambio_tarjeta=cancelado';

$payload = [
    'mode'                     => 'setup',
    'customer'                 => $sub['stripe_customer_id'],
    'payment_method_types[0]'  => 'card',
    'success_url'              => $successUrl,
    'cancel_url'               => $cancelUrl,
    // Tag the session so the webhook can find this client + replace the
    // previous payment_method when setup_intent.succeeded fires.
    'metadata[cliente_id]'     => (string)$cid,
    'metadata[purpose]'        => 'replace_backup_card',
];

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . STRIPE_SECRET_KEY],
    CURLOPT_POSTFIELDS     => http_build_query($payload),
    CURLOPT_TIMEOUT        => 15,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode((string)$resp, true) ?: [];
if ($code < 200 || $code >= 300 || empty($data['url'])) {
    error_log('cambiar-tarjeta stripe error: ' . substr((string)$resp, 0, 400));
    portalJsonOut([
        'error' => $data['error']['message'] ?? 'No se pudo iniciar el flujo de cambio de tarjeta',
    ], 500);
}

portalLog('tarjeta_cambiar_iniciado', [
    'cliente_id' => $cid,
    'session_id' => $data['id'] ?? '',
]);

portalJsonOut(['url' => $data['url']]);
