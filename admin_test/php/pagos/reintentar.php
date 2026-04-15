<?php
/**
 * POST — Retry a failed charge for a payment cycle
 * Body: { "ciclo_id": 123 }
 * If a previous stripe_pi exists, attempts to confirm it again.
 * Otherwise creates a new PaymentIntent.
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$cicloId = (int)($d['ciclo_id'] ?? 0);
if (!$cicloId) adminJsonOut(['error' => 'ciclo_id requerido'], 400);

$pdo = getDB();

$stmt = $pdo->prepare("
    SELECT c.*, s.stripe_customer_id, s.stripe_payment_method_id, COALESCE(s.nombre, cl.nombre, '') as nombre
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
    adminJsonOut(['error' => 'Sin método de pago para reintentar'], 400);
}

$stripeKey = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : (getenv('STRIPE_SECRET_KEY') ?: '');
if (!$stripeKey) adminJsonOut(['error' => 'Stripe no configurado'], 500);

$result = null;

// If there's an existing PI, try to confirm it
if (!empty($ciclo['stripe_payment_intent'])) {
    $ch = curl_init('https://api.stripe.com/v1/payment_intents/' . $ciclo['stripe_payment_intent'] . '/confirm');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $stripeKey . ':',
        CURLOPT_POSTFIELDS     => http_build_query([
            'payment_method' => $ciclo['stripe_payment_method_id'],
        ]),
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $result = json_decode($raw, true);
}

// If no existing PI or it couldn't be confirmed, create a new one
if (!$result || !in_array($result['status'] ?? '', ['succeeded','processing'])) {
    $amount = (int)(round($ciclo['monto'] * 100));
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
            'description'          => 'Voltika reintento ciclo #' . $ciclo['semana_num'] . ' - ' . ($ciclo['nombre'] ?? ''),
            'metadata[ciclo_id]'   => $cicloId,
            'metadata[tipo]'       => 'reintento_admin',
        ]),
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($raw, true);
}

if (($result['status'] ?? '') === 'succeeded') {
    $pdo->prepare("UPDATE ciclos_pago SET estado='paid_auto', stripe_payment_intent=?, fecha_pago=NOW() WHERE id=?")
        ->execute([$result['id'], $cicloId]);

    adminLog('reintentar_pago', [
        'ciclo_id'  => $cicloId,
        'stripe_pi' => $result['id'],
        'monto'     => $ciclo['monto'],
    ]);

    adminJsonOut(['ok' => true, 'stripe_pi' => $result['id'], 'status' => 'succeeded']);
} else {
    $errorMsg = $result['error']['message'] ?? ($result['last_payment_error']['message'] ?? 'Error al reintentar');
    adminLog('reintentar_pago_error', ['ciclo_id' => $cicloId, 'error' => $errorMsg]);
    adminJsonOut(['ok' => false, 'error' => $errorMsg, 'status' => $result['status'] ?? 'failed']);
}
