<?php
/**
 * GET — Redirige al PUBLIC hosted-receipt URL de Stripe para un PaymentIntent.
 *
 * Customer brief 2026-05-12 (Óscar, 8th round — screenshot: clicking
 * Recibo de Stripe from the admin Documentos modal opened the Stripe
 * dashboard login page). The dashboard URL
 * (https://dashboard.stripe.com/payments/<pi>) is meant for Stripe
 * operators, not customers — and clicking it without an active Stripe
 * login shows the auth screen.
 *
 * Mirror of puntosvoltika/php/entrega/stripe-recibo.php but auth-scoped
 * to the admin session. Returns a 302-redirect to the customer-facing
 * latest_charge.receipt_url so the admin sees the same branded receipt
 * the customer received.
 *
 * Query params:
 *   pi=<pi_id>        — required, the PaymentIntent id.
 *   inline=1          — optional, return JSON with the URL instead of redirecting.
 */

require_once __DIR__ . '/../bootstrap.php';
$adminId = adminRequireAuth(); // any logged-in admin role

$piId = trim((string)($_GET['pi'] ?? ''));
if ($piId === '' || strpos($piId, 'pi_') !== 0) {
    adminJsonOut(['error' => 'PaymentIntent id requerido (pi=pi_…)'], 400);
}

// Load Stripe config.
$cfgPath = null;
foreach ([
    __DIR__ . '/../../../configurador/php/config.php',
    __DIR__ . '/../../../configurador_prueba_test/php/config.php',
] as $p) {
    if (is_file($p)) { $cfgPath = $p; break; }
}
if ($cfgPath) { try { require_once $cfgPath; } catch (Throwable $e) {} }

$stripeKey = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : '';
if (!$stripeKey) {
    adminJsonOut(['error' => 'Stripe no configurado en el servidor'], 500);
}

// Retrieve PaymentIntent with latest_charge expanded — that's where
// receipt_url lives.
$ch = curl_init('https://api.stripe.com/v1/payment_intents/' . urlencode($piId) . '?expand[]=latest_charge');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $stripeKey],
    CURLOPT_TIMEOUT        => 15,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$receiptUrl = null;
$voucherUrl = null;
if ($code === 200 && ($data = json_decode($resp, true))) {
    $receiptUrl = $data['latest_charge']['receipt_url']
               ?? $data['charges']['data'][0]['receipt_url']
               ?? null;
    $voucherUrl = $data['next_action']['oxxo_display_details']['hosted_voucher_url']
               ?? null;
}

$finalUrl = $receiptUrl ?: $voucherUrl;
if (!$finalUrl) {
    adminJsonOut([
        'error' => 'Recibo aún no disponible en Stripe',
        'hint'  => 'Stripe genera el recibo cuando el cargo se completa. Para OXXO/SPEI el voucher aparece hasta confirmación.',
        'pi'    => $piId,
    ], 404);
}

if (!empty($_GET['inline'])) {
    adminJsonOut(['ok' => true, 'url' => $finalUrl]);
}

header('Location: ' . $finalUrl, true, 302);
exit;
