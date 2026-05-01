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

// ── 4. Inspect 3DS setting on REAL recent customer PIs (no side effects) ───
// Customer brief 2026-05-01 followup #2: an earlier version of this section
// hit our create-payment-intent.php with a $50 MXN test payload, which
// (correctly) cascaded into _voltikaInsertPendingTransaccion() + the
// recovery email helper — sending a "Total a pagar $50 MXN" email and
// inserting a phantom transacciones row. That looked like a bug to anyone
// who saw the email. This version is read-only: it inspects the most
// recent REAL customer PIs in Stripe and reports their 3DS configuration,
// so we can verify the fix is live without creating new noise.
echo "4. Inspect 3DS configuration on recent real customer PIs (read-only):\n";

$ch = curl_init('https://api.stripe.com/v1/payment_intents?limit=10');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $sk],
    CURLOPT_TIMEOUT        => 15,
]);
$listResp = curl_exec($ch);
$listCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($listCode !== 200) {
    echo "   FAIL HTTP $listCode\n";
    echo "   Body: " . substr((string)$listResp, 0, 300) . "\n";
} else {
    $listData = json_decode((string)$listResp, true);
    $pisRecent = $listData['data'] ?? [];
    $cardPis = array_values(array_filter($pisRecent, function($p) {
        $types = $p['payment_method_types'] ?? [];
        return is_array($types) && in_array('card', $types, true);
    }));

    if (empty($cardPis)) {
        echo "   No recent card PIs found.\n";
    } else {
        $countAuto = 0;
        $countAny = 0;
        $countOther = 0;
        printf("   %-30s %-19s %-9s %-12s\n", 'PI ID', 'CREATED', 'AMOUNT', '3DS');
        echo "   " . str_repeat('-', 75) . "\n";
        foreach (array_slice($cardPis, 0, 8) as $pi) {
            $tds = $pi['payment_method_options']['card']['request_three_d_secure'] ?? '(unset)';
            if ($tds === 'automatic')      $countAuto++;
            elseif ($tds === 'any')        $countAny++;
            else                            $countOther++;
            printf("   %-30s %-19s %-9s %-12s\n",
                substr((string)($pi['id'] ?? ''), 0, 30),
                date('Y-m-d H:i:s', (int)($pi['created'] ?? 0)),
                '$' . number_format(($pi['amount'] ?? 0) / 100, 2),
                $tds);
        }

        echo "\n   ┌─────────────────────────────────────────────────────────────┐\n";
        if ($countAuto > 0 && $countAny === 0) {
            echo "   │  ✅ FIX DEPLOYED — recent PIs all use 3DS = 'automatic'      │\n";
            echo "   │  Mexican cards should succeed at the normal issuer rate now.│\n";
        } elseif ($countAny > 0 && $countAuto === 0) {
            echo "   │  ❌ FIX NOT DEPLOYED — recent PIs still use 3DS = 'any'      │\n";
            echo "   │  Re-upload create-payment-intent.php; PHP-FPM may need     │\n";
            echo "   │  an opcache reset for the change to take effect.            │\n";
        } elseif ($countAuto > 0 && $countAny > 0) {
            echo "   │  ⚠ TRANSITIONAL — both 'automatic' AND 'any' seen recently. │\n";
            echo "   │  The 'any' ones are pre-fix; 'automatic' ones are post-fix. │\n";
            echo "   │  Confirm the most recent (top of table) is 'automatic'.    │\n";
        } else {
            echo "   │  ⚠ Could not determine 3DS setting from recent PIs.         │\n";
        }
        echo "   └─────────────────────────────────────────────────────────────┘\n";
    }
}

// ── 5. Cleanup: remove diag-created phantom rows from previous runs ────────
// Earlier diag versions inserted 'pendiente' rows + sent recovery emails.
// Remove any leftover noise so the admin dashboard is clean.
try {
    $pdoCleanup = getDB();
    $delStmt = $pdoCleanup->prepare("DELETE FROM transacciones
        WHERE pago_estado = 'pendiente'
          AND email LIKE 'diag+%@voltika.mx'");
    $delStmt->execute();
    $deleted = $delStmt->rowCount();
    if ($deleted > 0) {
        echo "5. Cleanup: removed $deleted phantom diag rows from transacciones.\n";
    } else {
        echo "5. Cleanup: no phantom diag rows to remove. ✓\n";
    }
} catch (Throwable $e) {
    echo "5. Cleanup error: " . $e->getMessage() . "\n";
}

echo "\n================================================================\n";
echo "DELETE this file (diag-stripe-card.php) via FileZilla after use.\n";
