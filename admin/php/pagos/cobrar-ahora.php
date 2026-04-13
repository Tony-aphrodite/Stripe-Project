<?php
/**
 * POST — Charge a customer's card now for a specific payment cycle
 * Body: { "ciclo_id": 123 }
 * Creates a Stripe PaymentIntent and charges the stored payment method
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$cicloId = (int)($d['ciclo_id'] ?? 0);
if (!$cicloId) adminJsonOut(['error' => 'ciclo_id requerido'], 400);

$pdo = getDB();

// Get cycle + subscription data
$stmt = $pdo->prepare("
    SELECT c.*, s.stripe_customer_id, s.stripe_payment_method_id, COALESCE(s.nombre, cl.nombre, '') as nombre, s.email
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
if (empty($ciclo['stripe_customer_id']) || empty($ciclo['stripe_payment_method_id'])) {
    adminJsonOut(['error' => 'Cliente sin método de pago registrado en Stripe'], 400);
}

$stripeKey = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : (getenv('STRIPE_SECRET_KEY') ?: '');
if (!$stripeKey) adminJsonOut(['error' => 'Stripe no configurado'], 500);

$amount = (int)(round($ciclo['monto'] * 100)); // centavos

// Create PaymentIntent with off-session confirmation
$ch = curl_init('https://api.stripe.com/v1/payment_intents');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => $stripeKey . ':',
    CURLOPT_POSTFIELDS     => http_build_query([
        'amount'               => $amount,
        'currency'             => 'mxn',
        'customer'             => $ciclo['stripe_customer_id'],
        'payment_method'       => $ciclo['stripe_payment_method_id'],
        'off_session'          => 'true',
        'confirm'              => 'true',
        'description'          => 'Voltika ciclo #' . $ciclo['semana_num'] . ' - ' . ($ciclo['nombre'] ?? ''),
        'metadata[ciclo_id]'   => $cicloId,
        'metadata[tipo]'       => 'cobro_admin',
    ]),
]);
$raw = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$resp = json_decode($raw, true);

if ($httpCode >= 200 && $httpCode < 300 && ($resp['status'] ?? '') === 'succeeded') {
    // Update cycle as paid
    $pdo->prepare("UPDATE ciclos_pago SET estado='paid_auto', stripe_payment_intent=?, fecha_pago=NOW() WHERE id=?")
        ->execute([$resp['id'], $cicloId]);

    adminLog('cobrar_ahora', [
        'ciclo_id'   => $cicloId,
        'stripe_pi'  => $resp['id'],
        'monto'      => $ciclo['monto'],
        'cliente'    => $ciclo['nombre'],
    ]);

    adminJsonOut([
        'ok'        => true,
        'stripe_pi' => $resp['id'],
        'status'    => 'succeeded',
        'monto'     => $ciclo['monto'],
    ]);
} else {
    $errorMsg = $resp['error']['message'] ?? ($resp['last_payment_error']['message'] ?? 'Error desconocido de Stripe');
    adminLog('cobrar_ahora_error', [
        'ciclo_id' => $cicloId,
        'error'    => $errorMsg,
        'status'   => $resp['status'] ?? 'failed',
    ]);
    adminJsonOut([
        'ok'     => false,
        'error'  => $errorMsg,
        'status' => $resp['status'] ?? 'failed',
    ]);
}
