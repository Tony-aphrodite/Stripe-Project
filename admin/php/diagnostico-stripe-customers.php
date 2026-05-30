<?php
/**
 * Voltika Admin — Stripe customer ID audit.
 *
 * Customer brief 2026-05-30 ROOT CAUSE: Carlos (and any other customer
 * created during Round 56 test/live transition window 2026-05-18) has a
 * stripe_customer_id that doesn't exist in the current LIVE Stripe account.
 * Every payment attempt returns "No such customer".
 *
 * This tool:
 *   1. Lists every subscripciones_credito row with its stripe_customer_id
 *   2. Calls Stripe API to verify whether each customer ID is valid in the
 *      currently active Stripe mode (LIVE)
 *   3. Tags each row: OK / MISSING / INVALID_FORMAT / NOT_TESTED
 *   4. Outputs the actionable list — "needs new card" customers
 *
 * Read-only. No charges. Admin only.
 *
 * Why this is the root-cause investigation (not just patching):
 *   - Reveals the FULL blast radius (how many customers affected)
 *   - Shows the pattern (creation date cluster → confirms test/live history)
 *   - Provides input for the recovery plan (notify N customers to re-add card)
 *
 * Prevention strategy implemented separately:
 *   - confirmar-autopago.php will record `stripe_key_mode_at_creation`
 *     (TEST or LIVE) so future migrations are auditable
 *   - crear-pago-directo.php returns `needs_new_card` flag (already done)
 *   - bootstrap consistency enforced (already done in Round 56)
 */

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
adminRequireAuth(['admin']);

// Load Stripe key
$cfgFile = __DIR__ . '/../../configurador/php/config.php';
if (is_file($cfgFile)) require_once $cfgFile;
$stripeKey = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : '';
if (!$stripeKey) {
    die('STRIPE_SECRET_KEY no definido');
}
$keyMode = strpos($stripeKey, 'sk_live_') === 0 ? 'LIVE'
         : (strpos($stripeKey, 'sk_test_') === 0 ? 'TEST' : 'UNKNOWN');

$pdo = getDB();
$limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
$onlyInvalid = !empty($_GET['only_invalid']);

// Get all subscriptions with customer IDs
$rows = $pdo->query("SELECT s.id, s.cliente_id, s.nombre, s.email, s.telefono,
        s.stripe_customer_id, s.stripe_payment_method_id, s.estado, s.factivacion,
        s.freg, s.modelo, s.color
    FROM subscripciones_credito s
    WHERE s.stripe_customer_id IS NOT NULL AND s.stripe_customer_id != ''
      AND (s.estado IS NULL OR s.estado NOT IN ('cancelada','liquidada'))
    ORDER BY s.id DESC
    LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);

// Check each customer ID against Stripe
function checkStripeCustomer(string $stripeKey, string $customerId): array {
    $ch = curl_init('https://api.stripe.com/v1/customers/' . urlencode($customerId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $stripeKey],
        CURLOPT_TIMEOUT => 8,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$resp, true) ?: [];
    return [
        'http' => $code,
        'exists' => $code >= 200 && $code < 300,
        'email_in_stripe' => $data['email'] ?? null,
        'error_code' => $data['error']['code'] ?? null,
        'error_msg'  => $data['error']['message'] ?? null,
    ];
}

$results = [];
$counts = ['ok' => 0, 'missing' => 0, 'other_error' => 0];
foreach ($rows as $r) {
    $check = checkStripeCustomer($stripeKey, (string)$r['stripe_customer_id']);
    if ($check['exists']) {
        $status = 'OK';
        $counts['ok']++;
    } elseif (($check['error_code'] ?? '') === 'resource_missing') {
        $status = 'MISSING';
        $counts['missing']++;
    } else {
        $status = 'ERROR_HTTP_' . $check['http'];
        $counts['other_error']++;
    }
    $r['stripe_check'] = $check;
    $r['status'] = $status;
    if (!$onlyInvalid || $status !== 'OK') {
        $results[] = $r;
    }
    usleep(80000); // 80ms — be gentle on Stripe API
}

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Stripe customer audit</title>';
echo '<style>body{font-family:system-ui,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1280px;margin:0 auto;line-height:1.55;}
h1{font-size:20px;margin:0 0 6px;}
table{width:100%;border-collapse:collapse;font-size:11.5px;margin-top:10px;}
th{background:#f1f5f9;padding:6px 9px;text-align:left;}
td{padding:6px 9px;border-top:1px solid #f1f5f9;vertical-align:top;}
.ok{color:#15803d;font-weight:700;}
.err{color:#b91c1c;font-weight:700;}
.warn{color:#a16207;font-weight:700;}
.muted{color:#94a3b8;}
.banner{padding:11px 14px;border-radius:8px;font-size:13.5px;margin-bottom:12px;font-weight:600;}
.banner-info{background:#dbeafe;border:1px solid #93c5fd;color:#1e3a8a;}
.banner-warn{background:#fef3c7;border:1px solid #fcd34d;color:#92400e;}
.banner-bad{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;}
code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:11px;}
.btn{padding:6px 12px;background:#039fe1;color:#fff;border:0;border-radius:5px;font-size:12px;text-decoration:none;display:inline-block;}
</style></head><body>';
echo '<h1>Stripe customer audit · key mode = <code>' . $keyMode . '</code></h1>';
echo '<p class="muted">Verifies whether each subscripciones_credito.stripe_customer_id exists in the active Stripe account.</p>';

echo '<div class="banner banner-info">'
   . 'Subscriptions scanned: <strong>' . count($rows) . '</strong> &middot; '
   . '✓ OK: <strong>' . $counts['ok'] . '</strong> &middot; '
   . '✗ MISSING: <strong>' . $counts['missing'] . '</strong> &middot; '
   . '⚠ Other error: <strong>' . $counts['other_error'] . '</strong>'
   . ' &nbsp; <a href="?only_invalid=1' . ($limit !== 100 ? '&limit=' . $limit : '') . '">Show only invalid</a>'
   . ' &nbsp; <a href="?limit=500">Scan up to 500</a>'
   . '</div>';

if ($counts['missing'] > 0) {
    echo '<div class="banner banner-bad">'
       . '⚠ <strong>' . $counts['missing'] . '</strong> customers have stale stripe_customer_id values. They will see '
       . '"No such customer" on every payment attempt until they re-add a card via "Cambiar tarjeta".'
       . '<br>Root cause likely: created during the test→live transition window before Round 56 fix (2026-05-18).'
       . '</div>';
}

echo '<table><thead><tr><th>id</th><th>cliente</th><th>Nombre</th><th>Email</th><th>Tel</th><th>customer_id</th><th>Status</th><th>Stripe email</th><th>Creada</th></tr></thead><tbody>';
foreach ($results as $r) {
    $check = $r['stripe_check'];
    $statusClass = $r['status'] === 'OK' ? 'ok' : ($r['status'] === 'MISSING' ? 'err' : 'warn');
    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td>' . (int)($r['cliente_id'] ?? 0) . '</td>';
    echo '<td>' . htmlspecialchars((string)$r['nombre']) . '</td>';
    echo '<td><small>' . htmlspecialchars((string)$r['email']) . '</small></td>';
    echo '<td>' . htmlspecialchars((string)$r['telefono']) . '</td>';
    echo '<td><code>' . htmlspecialchars((string)$r['stripe_customer_id']) . '</code></td>';
    echo '<td class="' . $statusClass . '">' . htmlspecialchars($r['status']);
    if (!empty($check['error_msg'])) echo '<br><small>' . htmlspecialchars($check['error_msg']) . '</small>';
    echo '</td>';
    echo '<td><small>' . htmlspecialchars((string)($check['email_in_stripe'] ?? '')) . '</small></td>';
    echo '<td><small>' . htmlspecialchars((string)($r['factivacion'] ?? $r['freg'])) . '</small></td>';
    echo '</tr>';
}
echo '</tbody></table>';

echo '</body></html>';
