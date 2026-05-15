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
    $stmt = $pdo->prepare("SELECT id, stripe_customer_id FROM subscripciones_credito
        WHERE cliente_id = ?
        ORDER BY id DESC LIMIT 1");
    $stmt->execute([$cid]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) { error_log('cambiar-tarjeta sub: ' . $e->getMessage()); }

if (!$sub) {
    portalJsonOut(['error' => 'No tienes una suscripción activa.'], 400);
}

if (!defined('STRIPE_SECRET_KEY') || !STRIPE_SECRET_KEY) {
    portalJsonOut(['error' => 'Stripe no está configurado en el servidor'], 500);
}

// ── Round 34 (2026-05-14, Óscar — "No such customer: cus_UU0...") ────
// The Stripe customer ID stored in subscripciones_credito may have been
// created in a different Stripe mode (test ↔ live) or deleted in the
// Stripe dashboard. Result: the Checkout Session creation fails with
// "No such customer" and the operator sees a dead-end error.
//
// Helper: build a fresh Stripe customer from the cliente row + persist
// the new ID. Idempotent — if any other subscripción already has a
// valid customer, we reuse it; otherwise create one.
function _voltikaCreateStripeCustomer(int $cid, PDO $pdo): array {
    // Round 39 (2026-05-14, Óscar — Round 34 customer still saw "No such
    // customer"): the previous helper returned null when email was empty
    // OR when the Stripe API itself rejected the request, but the caller
    // couldn't tell the two apart and the operator/customer just saw a
    // generic error. Return a structured array so cambiar-tarjeta.php can
    // surface the exact reason (missing email / Stripe rejection / network).
    // Also: email is NOT strictly required by Stripe Checkout setup-mode —
    // the Checkout page itself can collect it. So we attempt the create
    // even when email is empty; only refuse if BOTH email and phone are
    // missing (which means we can't identify the customer at all).
    $cliRow = $pdo->prepare("SELECT nombre, email, telefono FROM clientes WHERE id = ?");
    $cliRow->execute([$cid]);
    $cli = $cliRow->fetch(PDO::FETCH_ASSOC) ?: [];
    $email = trim((string)($cli['email']    ?? ''));
    $phone = trim((string)($cli['telefono'] ?? ''));
    $name  = trim((string)($cli['nombre']   ?? ''));
    if ($email === '' && $phone === '') {
        error_log('cambiar-tarjeta: cliente ' . $cid . ' sin email NI teléfono — no se puede crear Stripe customer.');
        return ['id' => null, 'error' => 'sin_contacto',
                'detail' => 'Tu perfil no tiene email ni teléfono registrado.'];
    }
    $body = http_build_query(array_filter([
        'email'                  => $email,
        'name'                   => $name,
        'phone'                  => $phone !== '' ? ('+52' . $phone) : '',
        'metadata[cliente_id]'   => (string)$cid,
        'metadata[origen]'       => 'portal_cambiar_tarjeta_recovery',
    ]));
    $ch = curl_init('https://api.stripe.com/v1/customers');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . STRIPE_SECRET_KEY],
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 12,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        $shortResp = substr((string)$resp, 0, 400);
        error_log('cambiar-tarjeta create customer failed (HTTP ' . $code . '): ' . $shortResp);
        $parsed = json_decode((string)$resp, true) ?: [];
        return ['id'     => null,
                'error'  => 'stripe_rejected',
                'detail' => $parsed['error']['message'] ?? ('Stripe HTTP ' . $code),
                'code'   => $code,
                'curl_error' => $curlErr ?: null];
    }
    $arr = json_decode((string)$resp, true) ?: [];
    $newId = is_string($arr['id'] ?? null) ? $arr['id'] : null;
    if (!$newId) {
        return ['id' => null, 'error' => 'invalid_response',
                'detail' => 'Stripe respondió sin ID de cliente.'];
    }
    return ['id' => $newId, 'error' => null];
}

// Build Checkout Session in setup mode
$baseUrl = (defined('VOLTIKA_BASE_URL') && VOLTIKA_BASE_URL)
    ? rtrim(VOLTIKA_BASE_URL, '/')
    : 'https://voltika.mx';
$successUrl = $baseUrl . '/clientes/?cambio_tarjeta=ok';
$cancelUrl  = $baseUrl . '/clientes/?cambio_tarjeta=cancelado';

// Round 34: try the stored customer ID first. If Stripe rejects it,
// auto-recover by creating a new customer + persisting the new ID, then
// retry once. This avoids a dead-end "No such customer" for clients
// whose stored ID is stale (test/live mismatch or deleted in dashboard).
function _voltikaCambiarTarjetaCreateSession(string $customerId, int $cid, string $successUrl, string $cancelUrl): array {
    $payload = [
        'mode'                     => 'setup',
        'customer'                 => $customerId,
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
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $resp, 'data' => json_decode((string)$resp, true) ?: []];
}

$customerId = (string)($sub['stripe_customer_id'] ?? '');
$result = null;
if ($customerId !== '') {
    $result = _voltikaCambiarTarjetaCreateSession($customerId, (int)$cid, $successUrl, $cancelUrl);
}

// Detect the "No such customer" recovery condition. Stripe returns a 400
// with error.code = 'resource_missing' and error.message containing
// the missing customer id. Also retry when there was no stored customer
// at all.
$needsRecovery = false;
if (!$customerId) {
    $needsRecovery = true;
} elseif ($result && ($result['code'] < 200 || $result['code'] >= 300)) {
    $errCode = $result['data']['error']['code'] ?? '';
    $errMsg  = $result['data']['error']['message'] ?? '';
    if ($errCode === 'resource_missing' || stripos($errMsg, 'no such customer') !== false) {
        $needsRecovery = true;
    }
}

if ($needsRecovery) {
    error_log('cambiar-tarjeta: stale customer ' . $customerId . ' for cliente ' . $cid . ' — creating fresh one.');
    // Round 39: structured return so we can give the user a specific reason.
    $newCust = _voltikaCreateStripeCustomer((int)$cid, $pdo);
    $newCustomerId = $newCust['id'] ?? null;
    if (!$newCustomerId) {
        // Surface the specific failure reason so the customer/operator
        // can act (update profile email, or escalate to support).
        $userMsg = 'No se pudo crear tu cliente en Stripe.';
        if (($newCust['error'] ?? '') === 'sin_contacto') {
            $userMsg = 'Tu perfil no tiene email ni teléfono registrado. ' .
                       'Pide a soporte que actualice tus datos de contacto y vuelve a intentar.';
        } elseif (($newCust['error'] ?? '') === 'stripe_rejected') {
            $userMsg = 'Stripe rechazó la creación del cliente. Detalle: ' .
                       ($newCust['detail'] ?? '') . ' — reporta a soporte con este código.';
        } elseif (($newCust['error'] ?? '') === 'invalid_response') {
            $userMsg = 'Stripe no devolvió un cliente válido. Reporta a soporte.';
        }
        portalJsonOut([
            'error'  => $userMsg,
            'reason' => $newCust['error'] ?? 'unknown',
            'detail' => $newCust['detail'] ?? null,
        ], 500);
    }
    // Persist on the most-recent subscripción for this cliente so future
    // calls hit the valid customer.
    try {
        $pdo->prepare("UPDATE subscripciones_credito SET stripe_customer_id = ? WHERE id = ?")
            ->execute([$newCustomerId, (int)$sub['id']]);
    } catch (Throwable $e) { error_log('cambiar-tarjeta persist customer: ' . $e->getMessage()); }

    portalLog('tarjeta_cambiar_customer_recreado', [
        'cliente_id'   => $cid,
        'customer_old' => $customerId,
        'customer_new' => $newCustomerId,
    ]);
    $result = _voltikaCambiarTarjetaCreateSession($newCustomerId, (int)$cid, $successUrl, $cancelUrl);
}

if ($result['code'] < 200 || $result['code'] >= 300 || empty($result['data']['url'])) {
    error_log('cambiar-tarjeta stripe error: ' . substr((string)$result['body'], 0, 400));
    portalJsonOut([
        'error' => $result['data']['error']['message'] ?? 'No se pudo iniciar el flujo de cambio de tarjeta',
    ], 500);
}

portalLog('tarjeta_cambiar_iniciado', [
    'cliente_id' => $cid,
    'session_id' => $result['data']['id'] ?? '',
]);

portalJsonOut(['url' => $result['data']['url']]);
