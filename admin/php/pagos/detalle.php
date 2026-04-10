<?php
/**
 * POST — Get full Stripe PaymentIntent details for an order
 * Body: { "stripe_pi": "pi_xxx" } or { "pedido_id": 123 }
 * Returns Stripe payment info + local order data
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$stripePi  = trim($d['stripe_pi'] ?? '');
$pedidoId  = (int)($d['pedido_id'] ?? 0);
$fuente    = $d['fuente'] ?? 'orden';

$pdo = getDB();

// ── Get local order data ─────────────────────────────────────────────
$local = null;
if ($fuente === 'credito' && $pedidoId) {
    $stmt = $pdo->prepare("SELECT s.*, 'credito' as fuente FROM subscripciones_credito s WHERE s.id=? LIMIT 1");
    $stmt->execute([$pedidoId]);
    $local = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($local) $stripePi = $local['stripe_setup_intent_id'] ?? '';
} else {
    if ($pedidoId) {
        $stmt = $pdo->prepare("SELECT t.* FROM transacciones t WHERE t.id=? LIMIT 1");
        $stmt->execute([$pedidoId]);
        $local = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($local) $stripePi = $local['stripe_pi'] ?? '';
    }
}

if (!$stripePi && !$local) {
    adminJsonOut(['error' => 'stripe_pi o pedido_id requerido'], 400);
}

// ── Fetch from Stripe API ────────────────────────────────────────────
$stripeKey = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : '';
$stripe = null;

if ($stripePi && $stripeKey) {
    // Determine if it's a PaymentIntent or SetupIntent
    $isSetup = strpos($stripePi, 'seti_') === 0;
    $endpoint = $isSetup
        ? 'https://api.stripe.com/v1/setup_intents/' . $stripePi
        : 'https://api.stripe.com/v1/payment_intents/' . $stripePi;

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERPWD        => $stripeKey . ':',
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        $data = json_decode($raw, true);

        if ($isSetup) {
            $stripe = [
                'type'            => 'setup_intent',
                'id'              => $data['id'] ?? '',
                'status'          => $data['status'] ?? 'unknown',
                'created'         => isset($data['created']) ? date('Y-m-d H:i:s', $data['created']) : null,
                'payment_method'  => $data['payment_method'] ?? null,
                'usage'           => $data['usage'] ?? '',
            ];
        } else {
            // PaymentIntent
            $stripe = [
                'type'            => 'payment_intent',
                'id'              => $data['id'] ?? '',
                'status'          => $data['status'] ?? 'unknown',
                'amount'          => ($data['amount'] ?? 0) / 100,
                'currency'        => strtoupper($data['currency'] ?? 'MXN'),
                'created'         => isset($data['created']) ? date('Y-m-d H:i:s', $data['created']) : null,
                'description'     => $data['description'] ?? '',
                'payment_method_types' => $data['payment_method_types'] ?? [],
            ];

            // Charges
            $charges = $data['latest_charge'] ?? null;
            if (is_string($charges) && $charges) {
                // Fetch the charge detail
                $ch2 = curl_init('https://api.stripe.com/v1/charges/' . $charges);
                curl_setopt_array($ch2, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_USERPWD        => $stripeKey . ':',
                ]);
                $chargeRaw = curl_exec($ch2);
                curl_close($ch2);
                $charge = json_decode($chargeRaw, true);

                if ($charge) {
                    $pm = $charge['payment_method_details'] ?? [];
                    $card = $pm['card'] ?? [];
                    $oxxo = $pm['oxxo'] ?? [];
                    $spei = $pm['customer_balance'] ?? $pm['bank_transfer'] ?? [];

                    $stripe['payment_method'] = $pm['type'] ?? 'unknown';
                    $stripe['card'] = $card ? [
                        'brand'   => $card['brand'] ?? '',
                        'last4'   => $card['last4'] ?? '',
                        'exp'     => ($card['exp_month'] ?? '') . '/' . ($card['exp_year'] ?? ''),
                        'country' => $card['country'] ?? '',
                        'funding' => $card['funding'] ?? '',
                    ] : null;
                    $stripe['oxxo'] = $oxxo ? ['number' => $oxxo['number'] ?? ''] : null;
                    $stripe['receipt_url']    = $charge['receipt_url'] ?? null;
                    $stripe['paid']           = $charge['paid'] ?? false;
                    $stripe['refunded']       = $charge['refunded'] ?? false;
                    $stripe['amount_refunded'] = ($charge['amount_refunded'] ?? 0) / 100;
                    $stripe['failure_message'] = $charge['failure_message'] ?? null;
                }
            }

            // Refunds from charges list
            $chargesList = $data['charges']['data'] ?? [];
            $refunds = [];
            foreach ($chargesList as $c) {
                foreach (($c['refunds']['data'] ?? []) as $ref) {
                    $refunds[] = [
                        'id'      => $ref['id'],
                        'amount'  => ($ref['amount'] ?? 0) / 100,
                        'status'  => $ref['status'] ?? '',
                        'created' => isset($ref['created']) ? date('Y-m-d H:i:s', $ref['created']) : null,
                        'reason'  => $ref['reason'] ?? '',
                    ];
                }
            }
            if ($refunds) $stripe['refunds'] = $refunds;
        }
    } else {
        $stripe = ['error' => 'Stripe API error HTTP ' . $httpCode];
    }
}

// ── Build response ───────────────────────────────────────────────────
$out = ['ok' => true];

if ($local) {
    $out['orden'] = [
        'id'        => (int)$local['id'],
        'pedido'    => $local['pedido'] ?? $local['pedido_num'] ?? '',
        'nombre'    => $local['nombre'] ?? '',
        'email'     => $local['email'] ?? '',
        'telefono'  => $local['telefono'] ?? '',
        'modelo'    => $local['modelo'] ?? '',
        'color'     => $local['color'] ?? '',
        'tipo_pago' => $local['tpago'] ?? $local['tipo_pago'] ?? $fuente,
        'monto'     => (float)($local['total'] ?? $local['precio_contado'] ?? 0),
        'fecha'     => $local['freg'] ?? '',
        'stripe_pi' => $stripePi,
        'punto_id'     => $local['punto_id'] ?? null,
        'punto_nombre' => $local['punto_nombre'] ?? null,
    ];
}

$out['stripe'] = $stripe;

adminJsonOut($out);
