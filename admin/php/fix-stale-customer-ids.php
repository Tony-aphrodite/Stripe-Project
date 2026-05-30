<?php
/**
 * Voltika Admin — Auto-recover stale stripe_customer_id by finding a sibling
 * subscription for the same cliente that has a valid customer ID.
 *
 * Customer brief 2026-05-30: audit showed Carlos has TWO subscripciones for
 * the same cliente_id (7), one with a valid customer_id (id 2: cus_UU0...)
 * and one with a stale customer_id (id 3: cus_UWTu...). The PAGAR endpoint
 * picks the most recent (id 3) and fails. Fix: copy the valid customer +
 * payment_method from the sibling.
 *
 * Algorithm:
 *   1. Find every active subscripciones_credito with stale stripe_customer_id
 *   2. For each, look for OTHER subscriptions of the same cliente_id whose
 *      customer_id is valid in Stripe
 *   3. If found, copy customer_id + payment_method_id to the broken one
 *   4. Verify the payment_method also belongs to that customer (extra safety)
 *   5. Log the change
 *
 * Two-stage flow: dry-run preview → commit.
 *
 * Safety: only writes to rows currently marked stale. Never overwrites valid
 * customer IDs. Free (only Stripe READ calls).
 *
 * Auth: admin only.
 */

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
adminRequireAuth(['admin']);

$cfgFile = __DIR__ . '/../../configurador/php/config.php';
if (is_file($cfgFile)) require_once $cfgFile;
$stripeKey = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : '';
if (!$stripeKey) die('STRIPE_SECRET_KEY no definido');

$pdo = getDB();
$commit = isset($_POST['commit']) && $_POST['commit'] === '1';

function checkStripeCustomer(string $key, string $id): bool {
    $ch = curl_init('https://api.stripe.com/v1/customers/' . urlencode($id));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key],
        CURLOPT_TIMEOUT => 8,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

function checkStripePaymentMethod(string $key, string $pmId, string $customerId): bool {
    if (!$pmId || !$customerId) return false;
    $ch = curl_init('https://api.stripe.com/v1/payment_methods/' . urlencode($pmId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key],
        CURLOPT_TIMEOUT => 8,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) return false;
    $data = json_decode((string)$resp, true) ?: [];
    return ($data['customer'] ?? null) === $customerId;
}

// 1. Get all active subscriptions
$subs = $pdo->query("SELECT id, cliente_id, nombre, email, telefono,
        stripe_customer_id, stripe_payment_method_id, factivacion, freg
    FROM subscripciones_credito
    WHERE stripe_customer_id IS NOT NULL AND stripe_customer_id != ''
      AND (estado IS NULL OR estado NOT IN ('cancelada','liquidada'))
    ORDER BY cliente_id, id ASC")->fetchAll(PDO::FETCH_ASSOC);

// 2. Group by cliente_id and verify each customer
$byClient = [];
foreach ($subs as $s) {
    $valid = checkStripeCustomer($stripeKey, (string)$s['stripe_customer_id']);
    $s['stripe_valid'] = $valid;
    $byClient[(int)$s['cliente_id']][] = $s;
    usleep(80000);
}

// 3. Build fix plan
$plan = [];
foreach ($byClient as $cid => $rows) {
    $valid = array_values(array_filter($rows, fn($r) => $r['stripe_valid']));
    $invalid = array_values(array_filter($rows, fn($r) => !$r['stripe_valid']));
    if (empty($valid) || empty($invalid)) continue;

    // Pick the most recent valid one as donor
    usort($valid, fn($a, $b) => strcmp((string)$b['factivacion'], (string)$a['factivacion']));
    $donor = $valid[0];

    foreach ($invalid as $broken) {
        // Verify donor's payment method actually belongs to donor's customer
        $pmValid = checkStripePaymentMethod(
            $stripeKey,
            (string)$donor['stripe_payment_method_id'],
            (string)$donor['stripe_customer_id']
        );
        $plan[] = [
            'broken_id' => (int)$broken['id'],
            'broken_customer' => $broken['stripe_customer_id'],
            'donor_id' => (int)$donor['id'],
            'donor_customer' => $donor['stripe_customer_id'],
            'donor_pm' => $donor['stripe_payment_method_id'],
            'pm_valid' => $pmValid,
            'cliente_id' => $cid,
            'nombre' => $broken['nombre'],
            'email' => $broken['email'],
        ];
    }
}

// 4. Commit
$updateStats = null;
if ($commit && !empty($plan)) {
    $updated = 0; $errors = 0; $skipped = 0;
    foreach ($plan as $p) {
        if (!$p['pm_valid']) { $skipped++; continue; }
        try {
            $up = $pdo->prepare("UPDATE subscripciones_credito
                SET stripe_customer_id = ?, stripe_payment_method_id = ?
                WHERE id = ? AND stripe_customer_id = ?");
            $up->execute([
                $p['donor_customer'],
                $p['donor_pm'],
                $p['broken_id'],
                $p['broken_customer'],
            ]);
            if ($up->rowCount() > 0) {
                $updated++;
                if (function_exists('adminLog')) {
                    adminLog('fix_stale_customer_id', [
                        'subscripcion_id' => $p['broken_id'],
                        'cliente_id' => $p['cliente_id'],
                        'old_customer' => $p['broken_customer'],
                        'new_customer' => $p['donor_customer'],
                        'donor_subscripcion' => $p['donor_id'],
                    ]);
                }
            }
        } catch (Throwable $e) {
            $errors++;
            error_log('fix-stale-customer id ' . $p['broken_id'] . ': ' . $e->getMessage());
        }
    }
    $updateStats = compact('updated','errors','skipped');
}

// UI
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Fix stale customer IDs</title>';
echo '<style>body{font-family:system-ui,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1180px;margin:0 auto;line-height:1.55;}
h1{font-size:20px;margin:0 0 6px;}
table{width:100%;border-collapse:collapse;font-size:11.5px;margin-top:10px;}
th{background:#f1f5f9;padding:6px 9px;text-align:left;}
td{padding:6px 9px;border-top:1px solid #f1f5f9;}
.banner{padding:11px 14px;border-radius:8px;font-size:13.5px;margin-bottom:12px;font-weight:600;}
.banner-info{background:#dbeafe;border:1px solid #93c5fd;color:#1e3a8a;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.btn{padding:8px 16px;background:#16a34a;color:#fff;border:0;border-radius:5px;font-weight:600;font-size:13px;cursor:pointer;}
.ok{color:#15803d;font-weight:700;}
.err{color:#b91c1c;font-weight:700;}
code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:11px;}
</style></head><body>';
echo '<h1>Fix stale stripe_customer_id by copying from valid sibling subscription</h1>';

if ($updateStats) {
    echo '<div class="banner banner-ok">Updated: <strong>' . $updateStats['updated'] . '</strong> &middot; '
       . 'Skipped (donor PM invalid): <strong>' . $updateStats['skipped'] . '</strong> &middot; '
       . 'Errors: <strong>' . $updateStats['errors'] . '</strong></div>';
}

echo '<div class="banner banner-info">Candidates: <strong>' . count($plan) . '</strong> subscripciones with stale customer + a valid sibling</div>';

if (empty($plan)) {
    echo '<p>No fixable stale subscriptions found. (Either no stale IDs, or no valid sibling for each stale row.)</p>';
} else {
    echo '<table><thead><tr><th>Broken id</th><th>Cliente</th><th>Nombre</th><th>Old (broken)</th><th>→</th><th>Donor id</th><th>New (valid)</th><th>Donor PM</th></tr></thead><tbody>';
    foreach ($plan as $p) {
        echo '<tr>';
        echo '<td>' . $p['broken_id'] . '</td>';
        echo '<td>' . $p['cliente_id'] . '</td>';
        echo '<td>' . htmlspecialchars((string)$p['nombre']) . '</td>';
        echo '<td><code class="err">' . htmlspecialchars((string)$p['broken_customer']) . '</code></td>';
        echo '<td>→</td>';
        echo '<td>' . $p['donor_id'] . '</td>';
        echo '<td><code class="ok">' . htmlspecialchars((string)$p['donor_customer']) . '</code></td>';
        echo '<td>' . htmlspecialchars((string)$p['donor_pm']);
        echo $p['pm_valid'] ? ' <span class="ok">✓</span>' : ' <span class="err">✗ skipped</span>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    if (!$commit) {
        $validCount = count(array_filter($plan, fn($p) => $p['pm_valid']));
        echo '<form method="post" style="margin-top:14px;">';
        echo '<input type="hidden" name="commit" value="1">';
        echo '<button class="btn" type="submit" onclick="return confirm(\'Copy valid customer_id + payment_method to ' . $validCount . ' stale subscripciones?\');">Commit fix (' . $validCount . ' rows)</button>';
        echo '</form>';
    }
}

echo '</body></html>';
