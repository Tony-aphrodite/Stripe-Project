<?php
/**
 * Voltika Admin — Fix subscripciones where stripe_payment_method_id does
 * NOT belong to the stripe_customer_id.
 *
 * Customer brief 2026-05-30 follow-up: after cambiar-tarjeta replaced the
 * stale customer, the payment_method_id was not updated. Result: Stripe
 * returns "PaymentMethod does not belong to Customer" on every charge.
 *
 * Algorithm:
 *   1. For each active subscripcion, check if its stored PM belongs to
 *      its stored customer
 *   2. If mismatch, query Stripe for any card attached to the customer
 *   3. If a card exists, write it back to subscripciones_credito
 *   4. If no card exists, mark for manual intervention (cambiar-tarjeta again)
 *
 * Two-stage: dry-run preview → commit. Free (Stripe READ only).
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

function stripeGet(string $key, string $path): array {
    $ch = curl_init('https://api.stripe.com/v1/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key],
        CURLOPT_TIMEOUT => 8,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'data' => json_decode((string)$resp, true) ?: []];
}

$subs = $pdo->query("SELECT id, cliente_id, nombre, email,
        stripe_customer_id, stripe_payment_method_id, factivacion
    FROM subscripciones_credito
    WHERE stripe_customer_id IS NOT NULL AND stripe_customer_id != ''
      AND (estado IS NULL OR estado NOT IN ('cancelada','liquidada'))
    ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

$plan = [];
foreach ($subs as $s) {
    $cust = (string)$s['stripe_customer_id'];
    $pm   = (string)($s['stripe_payment_method_id'] ?? '');

    // First check the customer is valid
    $cr = stripeGet($stripeKey, 'customers/' . urlencode($cust));
    if ($cr['code'] < 200 || $cr['code'] >= 300) {
        continue; // customer itself broken — handled by other tool
    }

    // Check whether the PM belongs to this customer
    $pmBelongs = false;
    if ($pm) {
        $pmRes = stripeGet($stripeKey, 'payment_methods/' . urlencode($pm));
        if ($pmRes['code'] >= 200 && $pmRes['code'] < 300) {
            $pmBelongs = ($pmRes['data']['customer'] ?? null) === $cust;
        }
    }
    if ($pmBelongs) continue; // all good

    // Find any card attached to this customer
    $listRes = stripeGet($stripeKey, 'customers/' . urlencode($cust) . '/payment_methods?type=card&limit=10');
    $cards = $listRes['data']['data'] ?? [];
    $newPm = is_array($cards) && !empty($cards) ? ($cards[0]['id'] ?? null) : null;

    $plan[] = [
        'id' => (int)$s['id'],
        'cliente_id' => (int)$s['cliente_id'],
        'nombre' => (string)$s['nombre'],
        'customer' => $cust,
        'old_pm' => $pm,
        'new_pm' => $newPm,
        'cards_found' => count($cards),
    ];
    usleep(80000);
}

$updateStats = null;
if ($commit && !empty($plan)) {
    $updated = 0; $skipped = 0; $errors = 0;
    foreach ($plan as $p) {
        if (!$p['new_pm']) { $skipped++; continue; }
        try {
            $up = $pdo->prepare("UPDATE subscripciones_credito
                SET stripe_payment_method_id = ?
                WHERE id = ? AND stripe_customer_id = ?");
            $up->execute([$p['new_pm'], $p['id'], $p['customer']]);
            if ($up->rowCount() > 0) {
                $updated++;
                if (function_exists('adminLog')) {
                    adminLog('fix_pm_customer_mismatch', [
                        'subscripcion_id' => $p['id'],
                        'cliente_id' => $p['cliente_id'],
                        'customer' => $p['customer'],
                        'old_pm' => $p['old_pm'],
                        'new_pm' => $p['new_pm'],
                    ]);
                }
            }
        } catch (Throwable $e) {
            $errors++;
            error_log('fix-pm-mismatch id ' . $p['id'] . ': ' . $e->getMessage());
        }
    }
    $updateStats = compact('updated','skipped','errors');
}

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Fix PM/customer mismatch</title>';
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
echo '<h1>Fix payment_method ↔ customer mismatch</h1>';

if ($updateStats) {
    echo '<div class="banner banner-ok">Updated: <strong>' . $updateStats['updated'] . '</strong> &middot; '
       . 'Skipped (no card): <strong>' . $updateStats['skipped'] . '</strong> &middot; '
       . 'Errors: <strong>' . $updateStats['errors'] . '</strong></div>';
}

echo '<div class="banner banner-info">Mismatch found: <strong>' . count($plan) . '</strong> subscripciones</div>';

if (empty($plan)) {
    echo '<p class="ok">All payment_method_id values match their customer_id. Nothing to fix.</p>';
} else {
    echo '<table><thead><tr><th>id</th><th>Cliente</th><th>Nombre</th><th>Customer (valid)</th><th>Old PM</th><th>New PM (from Stripe)</th><th>Cards found</th></tr></thead><tbody>';
    foreach ($plan as $p) {
        echo '<tr>';
        echo '<td>' . $p['id'] . '</td>';
        echo '<td>' . $p['cliente_id'] . '</td>';
        echo '<td>' . htmlspecialchars($p['nombre']) . '</td>';
        echo '<td><code>' . htmlspecialchars($p['customer']) . '</code></td>';
        echo '<td><code class="err">' . htmlspecialchars($p['old_pm']) . '</code></td>';
        echo '<td>' . ($p['new_pm'] ? '<code class="ok">' . htmlspecialchars($p['new_pm']) . '</code>' : '<span class="err">none</span>') . '</td>';
        echo '<td>' . $p['cards_found'] . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    if (!$commit) {
        $fixable = count(array_filter($plan, fn($p) => $p['new_pm']));
        echo '<form method="post" style="margin-top:14px;">';
        echo '<input type="hidden" name="commit" value="1">';
        echo '<button class="btn" type="submit" onclick="return confirm(\'Apply ' . $fixable . ' PM fixes?\');">Commit fix (' . $fixable . ' rows)</button>';
        echo '</form>';
    }
}

echo '</body></html>';
