<?php
/**
 * Voltika — Stripe card-payment diagnostic.
 *
 * Customer brief 2026-05-01: customers complain "no deja pagar con TDC"
 * (cards aren't working). This tool checks every layer of the
 * card-payment pipeline WITHOUT charging anything:
 *   1. APP_ENV / Stripe key presence
 *   2. Stripe API connectivity (account info)
 *   3. Webhook secret presence
 *   4. Recent failed PaymentIntents on the live account
 *   5. Configuration of payment_method_options (3DS + installments)
 *
 * Usage:
 *   ?token=voltika_diag_2026
 *
 * Delete this file via FileZilla after diagnosis.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

ini_set('max_execution_time', '60');
header('Content-Type: text/plain; charset=utf-8');

if (($_GET['token'] ?? '') !== 'voltika_diag_2026') {
    http_response_code(403);
    echo "invalid token\n";
    exit;
}

echo "================================================================\n";
echo "  Voltika Stripe card-payment diagnostic\n";
echo "================================================================\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// ── 1. Environment & key presence ──────────────────────────────────────────
$env  = defined('APP_ENV') ? APP_ENV : '?';
$sk   = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : '';
$pk   = defined('STRIPE_PUBLISHABLE_KEY') ? STRIPE_PUBLISHABLE_KEY : '';
$wh   = defined('STRIPE_WEBHOOK_SECRET') ? STRIPE_WEBHOOK_SECRET : '';

echo "1. Configuration:\n";
printf("   APP_ENV                  : %s\n", $env);
printf("   STRIPE_SECRET_KEY        : %s\n", $sk
    ? (substr($sk, 0, 7) . '...' . substr($sk, -4) . ' (length=' . strlen($sk) . ')')
    : 'MISSING ✗');
printf("   STRIPE_PUBLISHABLE_KEY   : %s\n", $pk
    ? (substr($pk, 0, 7) . '...' . substr($pk, -4) . ' (length=' . strlen($pk) . ')')
    : 'MISSING ✗');
printf("   STRIPE_WEBHOOK_SECRET    : %s\n", $wh && $wh !== 'whsec_PLACEHOLDER'
    ? (substr($wh, 0, 7) . '...' . substr($wh, -4))
    : ($wh === 'whsec_PLACEHOLDER' ? 'PLACEHOLDER (not set) ✗' : 'MISSING ✗'));

$keysMatch = $sk && $pk
    && (
        (strpos($sk, 'sk_live_') === 0 && strpos($pk, 'pk_live_') === 0) ||
        (strpos($sk, 'sk_test_') === 0 && strpos($pk, 'pk_test_') === 0)
    );
$envMatchesKey = $sk
    && (($env === 'live' && strpos($sk, 'sk_live_') === 0)
        || ($env === 'test' && strpos($sk, 'sk_test_') === 0));

printf("   Live/test key parity     : %s\n", $keysMatch ? 'OK ✓' : 'MISMATCH ✗ (one key is live, other is test)');
printf("   APP_ENV ↔ key match      : %s\n", $envMatchesKey ? 'OK ✓' : 'MISMATCH ✗ (APP_ENV says ' . $env . ' but secret key is ' . (strpos($sk, 'sk_live_') === 0 ? 'LIVE' : (strpos($sk, 'sk_test_') === 0 ? 'TEST' : 'unknown')) . ')');
echo "\n";

if (!$sk) {
    echo "FATAL: STRIPE_SECRET_KEY missing — cards cannot work.\n";
    echo "Add the correct sk_live_... value to .env STRIPE_SECRET_KEY_LIVE.\n";
    exit;
}

// ── 2. Stripe API connectivity ─────────────────────────────────────────────
echo "2. Stripe API connectivity:\n";
$ch = curl_init('https://api.stripe.com/v1/account');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $sk],
    CURLOPT_TIMEOUT        => 10,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);
if ($err) {
    echo "   curl error: $err\n";
} elseif ($code !== 200) {
    echo "   HTTP $code: " . substr((string)$resp, 0, 400) . "\n";
} else {
    $acc = json_decode((string)$resp, true);
    printf("   Account ID    : %s\n", $acc['id'] ?? '?');
    printf("   Country       : %s\n", $acc['country'] ?? '?');
    printf("   Currency      : %s\n", $acc['default_currency'] ?? '?');
    printf("   Charges enabled : %s\n", !empty($acc['charges_enabled']) ? 'YES ✓' : 'NO ✗');
    printf("   Payouts enabled : %s\n", !empty($acc['payouts_enabled']) ? 'YES ✓' : 'NO ✗');
    printf("   Details submitted: %s\n", !empty($acc['details_submitted']) ? 'YES ✓' : 'NO ✗');
    if (!empty($acc['requirements']) && !empty($acc['requirements']['currently_due'])) {
        echo "   ⚠ Currently due requirements: " . implode(', ', $acc['requirements']['currently_due']) . "\n";
    }
    if (!empty($acc['requirements']) && !empty($acc['requirements']['disabled_reason'])) {
        echo "   ⚠ Account disabled reason: " . $acc['requirements']['disabled_reason'] . "\n";
    }
}
echo "\n";

// ── 3. Recent PaymentIntent statuses (last 30) ─────────────────────────────
echo "3. Last 30 PaymentIntents (status breakdown):\n";
$ch = curl_init('https://api.stripe.com/v1/payment_intents?limit=30');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $sk],
    CURLOPT_TIMEOUT        => 15,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($code !== 200) {
    echo "   HTTP $code: " . substr((string)$resp, 0, 300) . "\n";
} else {
    $data = json_decode((string)$resp, true);
    $pis  = $data['data'] ?? [];
    $statusCount = [];
    $failedSamples = [];
    foreach ($pis as $pi) {
        $st = $pi['status'] ?? '?';
        $statusCount[$st] = ($statusCount[$st] ?? 0) + 1;
        if ($st !== 'succeeded' && $st !== 'requires_payment_method' && count($failedSamples) < 5) {
            $failedSamples[] = $pi;
        }
        // Capture last_payment_error for declined cards.
        if (!empty($pi['last_payment_error']) && count($failedSamples) < 5) {
            $failedSamples[] = $pi;
        }
    }
    foreach ($statusCount as $st => $n) {
        printf("   %-30s : %d\n", $st, $n);
    }
    echo "\n";

    // Show last_payment_error for any PI that has one (helps diagnose)
    echo "   Recent payment errors (if any):\n";
    $hasErrors = false;
    foreach ($pis as $pi) {
        if (empty($pi['last_payment_error'])) continue;
        $hasErrors = true;
        $err = $pi['last_payment_error'];
        printf("     %s  | code=%s  decline=%s  type=%s\n",
            $pi['id'] ?? '?',
            $err['code'] ?? '?',
            $err['decline_code'] ?? '-',
            $err['type'] ?? '?');
        printf("       msg: %s\n", substr((string)($err['message'] ?? ''), 0, 200));
    }
    if (!$hasErrors) echo "     (none — recent attempts have no error logged)\n";
}
echo "\n";

// ── 4. Test PaymentIntent CREATE through OUR endpoint (validates fix) ──────
// Customer brief 2026-05-01 followup: verify the 3DS=automatic fix is
// actually deployed by hitting our own create-payment-intent.php (the
// same code path real customers use), then inspecting the resulting PI's
// payment_method_options. If we still see 3DS='any' the file isn't
// uploaded; if we see 'automatic' the fix is live and active. The PI is
// canceled immediately afterwards so no real charge ever happens.
echo "4. End-to-end verification through OUR endpoint (no charge):\n";

$selfBase = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'voltika.mx') . dirname($_SERVER['REQUEST_URI'] ?? '/configurador/php/');
$selfBase = preg_replace('#/+$#', '', $selfBase);
$payload = json_encode([
    'amount'   => 5000,        // $50 MXN — above Stripe MX $10 minimum
    'method'   => 'card',
    'tipo'     => 'contado',
    'customer' => [
        'nombre'    => 'Voltika',
        'apellidos' => 'Diag',
        'email'     => 'diag+' . date('Ymd-His') . '@voltika.mx',
        'telefono'  => '5500000000',
        'modelo'    => 'M05',
        'color'     => 'gris',
        'ciudad'    => 'CDMX',
        'estado'    => 'CDMX',
        'cp'        => '01000',
        'tpago'     => 'unico',
    ],
]);

$ch = curl_init($selfBase . '/create-payment-intent.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_FOLLOWLOCATION => true,
]);
$selfResp = curl_exec($ch);
$selfCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$selfErr  = curl_error($ch);
curl_close($ch);

if ($selfErr) {
    echo "   curl error hitting our endpoint: $selfErr\n";
} elseif ($selfCode !== 200) {
    echo "   FAIL HTTP $selfCode from our endpoint\n";
    echo "   Body: " . substr((string)$selfResp, 0, 400) . "\n";
} else {
    $j = json_decode((string)$selfResp, true);
    if (empty($j['clientSecret'])) {
        echo "   FAIL — endpoint returned no clientSecret\n";
        echo "   Body: " . substr((string)$selfResp, 0, 400) . "\n";
    } else {
        printf("   OK ✓  Our endpoint returned clientSecret + PI created\n");
        // Extract PI id from clientSecret format: pi_xxx_secret_yyy
        $piId = (string)($j['paymentIntentId'] ?? '');
        if ($piId === '' && preg_match('/^(pi_[A-Za-z0-9]+)_secret_/', (string)$j['clientSecret'], $m)) {
            $piId = $m[1];
        }
        printf("   PI ID         : %s\n", $piId ?: '?');

        // ── KEY CHECK: fetch the PI from Stripe and inspect 3DS setting ──
        if ($piId) {
            $ch2 = curl_init('https://api.stripe.com/v1/payment_intents/' . urlencode($piId));
            curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $sk],
                CURLOPT_TIMEOUT        => 10,
            ]);
            $piResp = curl_exec($ch2);
            $piCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            curl_close($ch2);

            if ($piCode === 200) {
                $pi = json_decode((string)$piResp, true);
                $tds = $pi['payment_method_options']['card']['request_three_d_secure'] ?? '(not set)';
                $inst = $pi['payment_method_options']['card']['installments']['enabled'] ?? null;
                printf("   amount        : %s MXN\n", number_format(($pi['amount'] ?? 0) / 100, 2));
                printf("   currency      : %s\n", $pi['currency'] ?? '?');
                printf("   description   : %s\n", $pi['description'] ?? '?');
                printf("   3DS setting   : %s\n", $tds);

                echo "\n   ┌─────────────────────────────────────────────────────────┐\n";
                if ($tds === 'automatic') {
                    echo "   │  ✅ FIX DEPLOYED — 3DS = 'automatic' (Stripe-recommended)│\n";
                    echo "   │  Cards no longer forced through 3DS unconditionally.    │\n";
                    echo "   │  Mexican cards should now succeed at the normal rate.   │\n";
                } elseif ($tds === 'any') {
                    echo "   │  ❌ FIX NOT DEPLOYED — 3DS = 'any' (still forcing 3DS)  │\n";
                    echo "   │  Re-upload create-payment-intent.php via FileZilla.     │\n";
                    echo "   │  PHP-FPM may need an opcache reset for changes to take. │\n";
                } else {
                    echo "   │  ⚠ Unexpected 3DS value: $tds                          │\n";
                }
                echo "   └─────────────────────────────────────────────────────────┘\n";

                // Cancel the test PI so it doesn't pollute the dashboard.
                $ch3 = curl_init('https://api.stripe.com/v1/payment_intents/' . urlencode($piId) . '/cancel');
                curl_setopt_array($ch3, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $sk],
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => '',
                    CURLOPT_TIMEOUT        => 10,
                ]);
                curl_exec($ch3);
                curl_close($ch3);
                echo "\n   (test PI canceled — no charge; tracking row left as 'pendiente'\n";
                echo "    in DB will be cleaned manually if needed)\n";
            } else {
                echo "   could not fetch PI for verification (HTTP $piCode)\n";
            }
        }
    }
}

echo "\n================================================================\n";
echo "DELETE this file (diag-stripe-card.php) via FileZilla after use.\n";
