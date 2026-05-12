<?php
/**
 * GET — Redirige al PUBLIC hosted-receipt URL de Stripe para un PaymentIntent.
 *
 * Customer brief 2026-05-12 (Óscar, 7th round — screenshot 4: clicking the
 * Stripe link from the punto Documentos modal opened the Stripe dashboard
 * login page instead of the receipt). The dashboard URL
 * (`https://dashboard.stripe.com/payments/<pi>`) is for Stripe operators,
 * not customers — anyone without a Stripe login sees the auth screen.
 *
 * Stripe already hosts a public, customer-facing receipt for every Charge:
 * `latest_charge.receipt_url`. That URL needs no authentication on the
 * viewer side; opening it shows the same branded receipt the customer
 * receives by email. We fetch it server-side using the PaymentIntent id
 * and 302-redirect the punto user there. Same approach the client portal
 * already uses (see clientes/php/documentos/descargar.php).
 *
 * Auth: any logged-in punto user. Punto-id scoped so a punto can't fetch
 * receipts for orders that don't belong to its motos.
 *
 * Query params:
 *   moto_id   — required; we look up stripe_pi via inventario_motos →
 *               transacciones, AND verify the moto belongs to this punto.
 *   inline=1  — optional; if set we return JSON with the URL instead of
 *               redirecting (used by the punto JS to open in a new tab).
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$motoId = (int)($_GET['moto_id'] ?? 0);
if (!$motoId) {
    puntoJsonOut(['error' => 'moto_id requerido'], 400);
}

$pdo = getDB();

// Scope-check: the moto must belong to this punto. Prevents a punto from
// enumerating PaymentIntents of other puntos by guessing moto_ids.
//
// Customer brief 2026-05-12 (Óscar, 7th round): the strict JOIN
// (t.id = m.transaccion_id) fails for legacy rows where transaccion_id
// is NULL even though pedido_num is set. Mirror the historial.php fix:
// fall back to matching transacciones via pedido_num shapes.
$stmt = $pdo->prepare("SELECT t.stripe_pi
                         FROM inventario_motos m
                         LEFT JOIN transacciones t ON (
                                t.id = m.transaccion_id
                             OR (m.transaccion_id IS NULL AND CONCAT('VK-', t.pedido) = m.pedido_num)
                             OR (m.transaccion_id IS NULL AND t.pedido_corto         = m.pedido_num)
                             OR (m.transaccion_id IS NULL AND t.pedido               = m.pedido_num)
                         )
                        WHERE m.id = ? AND m.punto_voltika_id = ?
                        LIMIT 1");
$stmt->execute([$motoId, $ctx['punto_id']]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    puntoJsonOut(['error' => 'Moto no encontrada en este punto'], 404);
}

$piId = trim((string)($row['stripe_pi'] ?? ''));
if ($piId === '' || strpos($piId, 'pi_') !== 0) {
    puntoJsonOut(['error' => 'Esta orden no tiene PaymentIntent de Stripe asociado'], 404);
}

// Load Stripe config (config.php sits in the same tree).
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
    puntoJsonOut(['error' => 'Stripe no configurado en el servidor'], 500);
}

// Retrieve PaymentIntent with the latest_charge expanded.
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
    // OXXO / SPEI vouchers — fallback when there's no card charge yet.
    $voucherUrl = $data['next_action']['oxxo_display_details']['hosted_voucher_url']
               ?? null;
}

$finalUrl = $receiptUrl ?: $voucherUrl;
if (!$finalUrl) {
    puntoJsonOut([
        'error' => 'Recibo aún no disponible en Stripe',
        'hint'  => 'Stripe genera el recibo cuando el cargo se completa. Si el pago es por OXXO/SPEI, el voucher aparece hasta que se confirma.',
    ], 404);
}

puntoLog('stripe_recibo_abierto', [
    'moto_id' => $motoId,
    'pi'      => $piId,
]);

// Default behavior: 302-redirect so the punto can hand this URL to an
// <a target="_blank"> directly. With ?inline=1 we return JSON so the
// frontend can open the URL itself (useful for popup blockers).
if (!empty($_GET['inline'])) {
    puntoJsonOut(['ok' => true, 'url' => $finalUrl]);
}

header('Location: ' . $finalUrl, true, 302);
exit;
