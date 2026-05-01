<?php
/**
 * Voltika — pending-transaction visibility diagnostic.
 *
 * Customer brief 2026-05-01: a tester ("David") completed payment-screen
 * approach but no transacciones row showed on the admin dashboard. This
 * tool helps trace exactly what happened:
 *   1. confirms create-payment-intent.php has the pending-row helper
 *   2. lists last 20 transacciones rows (sorted by freg desc)
 *   3. lists last 20 Stripe PaymentIntents (live API call)
 *   4. cross-references PIs that exist in Stripe but NOT in our DB —
 *      those are the missing dashboard entries
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
echo "  Voltika pending-orders diagnostic\n";
echo "================================================================\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// ── 1. Check the pending-row helper is deployed ────────────────────────
$srcFile = __DIR__ . '/create-payment-intent.php';
$src = @file_get_contents($srcFile);
$hasHelper = $src && strpos($src, '_voltikaInsertPendingTransaccion') !== false;
$hasDedup  = $src && strpos($src, 'DATE_SUB(NOW(), INTERVAL 24 HOUR)') !== false;
echo "1. create-payment-intent.php deployment:\n";
echo "   _voltikaInsertPendingTransaccion present : " . ($hasHelper ? "YES ✓" : "NO  ✗ (needs upload)") . "\n";
echo "   email/phone dedup logic present          : " . ($hasDedup  ? "YES ✓" : "NO  ✗ (needs upload latest version)") . "\n\n";

// ── 2. Last 20 transacciones rows ───────────────────────────────────────
try {
    $pdo = getDB();
    $stmt = $pdo->query("SELECT id, freg, nombre, email, telefono, modelo, color,
                                pago_estado, stripe_pi, environment
                           FROM transacciones
                          ORDER BY id DESC
                          LIMIT 20");
    echo "2. Last 20 transacciones rows (newest first):\n";
    printf("   %-4s %-19s %-30s %-12s %-15s %-10s %-12s %s\n",
        'ID', 'FREG', 'NOMBRE', 'TELEFONO', 'MODELO', 'COLOR', 'ESTADO', 'STRIPE_PI');
    echo "   " . str_repeat('─', 130) . "\n";
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        printf("   %-4d %-19s %-30s %-12s %-15s %-10s %-12s %s\n",
            $r['id'],
            substr((string)$r['freg'], 0, 19),
            substr((string)$r['nombre'], 0, 30),
            substr((string)$r['telefono'], 0, 12),
            substr((string)$r['modelo'], 0, 15),
            substr((string)$r['color'], 0, 10),
            substr((string)$r['pago_estado'], 0, 12),
            substr((string)$r['stripe_pi'], 0, 30));
    }
    echo "\n";
} catch (Throwable $e) {
    echo "   ERROR querying transacciones: " . $e->getMessage() . "\n\n";
}

// ── 3. Last 20 Stripe PaymentIntents ────────────────────────────────────
echo "3. Last 20 Stripe PaymentIntents (live API):\n";
if (!defined('STRIPE_SECRET_KEY') || !STRIPE_SECRET_KEY) {
    echo "   ERROR: STRIPE_SECRET_KEY not configured.\n\n";
} else {
    try {
        $ch = curl_init('https://api.stripe.com/v1/payment_intents?limit=20');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . STRIPE_SECRET_KEY],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            echo "   ERROR HTTP $code: " . substr((string)$resp, 0, 300) . "\n\n";
        } else {
            $data = json_decode((string)$resp, true);
            $pis  = $data['data'] ?? [];
            printf("   %-30s %-19s %-12s %-9s %-10s %s\n",
                'STRIPE_PI', 'CREATED', 'AMOUNT', 'STATUS', 'METHOD', 'EMAIL/META');
            echo "   " . str_repeat('─', 130) . "\n";
            foreach ($pis as $pi) {
                $methods = is_array($pi['payment_method_types'] ?? null) ? implode(',', $pi['payment_method_types']) : '?';
                $email   = $pi['receipt_email'] ?? ($pi['metadata']['email'] ?? '');
                $nombre  = $pi['metadata']['nombre'] ?? '';
                printf("   %-30s %-19s %-12s %-9s %-10s %s%s\n",
                    substr((string)$pi['id'], 0, 30),
                    date('Y-m-d H:i:s', (int)($pi['created'] ?? 0)),
                    '$' . number_format(($pi['amount'] ?? 0) / 100, 2),
                    substr((string)$pi['status'], 0, 9),
                    substr($methods, 0, 10),
                    $email,
                    $nombre ? "  ($nombre)" : '');
            }
            echo "\n";

            // ── 4. Cross-reference: PIs in Stripe but NOT in DB ────────
            echo "4. PaymentIntents in Stripe but NOT in our DB (the 'invisible' ones):\n";
            $missing = [];
            foreach ($pis as $pi) {
                $piId = (string)($pi['id'] ?? '');
                if ($piId === '') continue;
                try {
                    $chk = $pdo->prepare("SELECT id FROM transacciones WHERE stripe_pi = ? LIMIT 1");
                    $chk->execute([$piId]);
                    if (!$chk->fetchColumn()) {
                        $missing[] = $pi;
                    }
                } catch (Throwable $e) {}
            }
            if (empty($missing)) {
                echo "   None — every Stripe PI is mirrored in our transacciones table. ✓\n";
            } else {
                echo "   These PIs were created in Stripe but our DB has no row for them:\n";
                foreach ($missing as $pi) {
                    $email   = $pi['receipt_email'] ?? ($pi['metadata']['email'] ?? '');
                    $nombre  = $pi['metadata']['nombre'] ?? '';
                    printf("     - %s   created=%s  email=%s  nombre=%s\n",
                        $pi['id'],
                        date('Y-m-d H:i:s', (int)($pi['created'] ?? 0)),
                        $email,
                        $nombre);
                }
                echo "\n   ROOT CAUSE: create-payment-intent.php was called and Stripe\n";
                echo "   accepted the PI, but our _voltikaInsertPendingTransaccion()\n";
                echo "   either failed silently or the deployed version is older than\n";
                echo "   the one that has the helper. Check #1 above for deployment\n";
                echo "   status, and the PHP error log for any 'voltika pending-transaccion'\n";
                echo "   entries.\n";
            }
        }
    } catch (Throwable $e) {
        echo "   ERROR: " . $e->getMessage() . "\n";
    }
}

echo "\n================================================================\n";
echo "DELETE this file (diag-pending-orders.php) via FileZilla after use.\n";
