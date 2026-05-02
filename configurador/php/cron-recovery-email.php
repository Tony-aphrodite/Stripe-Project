<?php
/**
 * Voltika — abandoned-cart recovery email (cron).
 *
 * Customer brief 2026-05-01: the recovery email used to fire the moment
 * a customer reached the payment screen, so anyone who paid 5 seconds
 * later received a "completa tu pago" email AFTER they paid — confusing
 * and damaging to reputation.
 *
 * This script is now the SOLE sender of recovery emails. It looks for
 * transacciones rows that:
 *   1. Are still pago_estado='pendiente' (no successful charge)
 *   2. Were created at least 30 minutes ago (proven abandonment)
 *   3. Haven't already received a recovery email (recovery_email_sent_at NULL)
 *   4. Have a usable email address
 *
 * Schedule via Plesk → Tools & Settings → Scheduled Tasks (Cron):
 *   Every 30 minutes:
 *   /usr/bin/php /var/www/vhosts/voltika.mx/httpdocs/configurador/php/cron-recovery-email.php
 *
 * Or HTTP-trigger from any external cron service:
 *   GET https://www.voltika.mx/configurador/php/cron-recovery-email.php?token=voltika_cron_2026
 *
 * Manual invocation (admin):
 *   ?token=voltika_cron_2026&dry=1   → preview without sending
 *   ?token=voltika_cron_2026         → execute and send
 */

declare(strict_types=1);

// Tell create-payment-intent.php to load functions only — do NOT run its
// HTTP-request flow (which would die with "Request invalido" since this
// is a GET cron call without a JSON body).
define('VOLTIKA_PI_HELPERS_ONLY', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/create-payment-intent.php';

ini_set('max_execution_time', '120');
ini_set('memory_limit', '128M');

// ── Auth ───────────────────────────────────────────────────────────────────
$isCli   = (php_sapi_name() === 'cli');
$expectedToken = getenv('CRON_RECOVERY_TOKEN') ?: 'voltika_cron_2026';
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (($_GET['token'] ?? '') !== $expectedToken) {
        http_response_code(403);
        echo "invalid token\n";
        exit;
    }
}
$dryRun = !empty($_GET['dry']) || !empty($_SERVER['argv'][1]) && $_SERVER['argv'][1] === '--dry';

echo "================================================================\n";
echo "  Voltika abandoned-cart recovery (cron)\n";
echo "  Mode: " . ($dryRun ? 'DRY-RUN' : 'EXECUTE') . "\n";
echo "  Time: " . date('Y-m-d H:i:s') . "\n";
echo "================================================================\n\n";

try {
    $pdo = getDB();

    // Idempotent migration: track when the recovery email was sent so we
    // don't double-mail the same customer. Safe to run on every cron tick.
    try {
        $pdo->exec("ALTER TABLE transacciones ADD COLUMN recovery_email_sent_at DATETIME NULL");
        echo "Schema migration: added transacciones.recovery_email_sent_at\n\n";
    } catch (Throwable $e) {
        // Already exists — that's fine.
    }

    // Find candidates:
    //   - pendiente status (no successful charge)
    //   - older than 30 minutes
    //   - no recovery email sent yet
    //   - has a real email (not blank, not diag, not the system test address)
    //   - matches the configurador environment (live/test) we're running in
    $env = defined('APP_ENV') ? APP_ENV : 'test';
    $stmt = $pdo->prepare("
        SELECT id, nombre, email, telefono, modelo, color, total, tpago,
               msi_meses, freg, stripe_pi, environment
          FROM transacciones
         WHERE pago_estado = 'pendiente'
           AND freg <= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
           AND recovery_email_sent_at IS NULL
           AND email IS NOT NULL
           AND email <> ''
           AND email NOT LIKE 'diag+%@voltika.mx'
           AND email NOT LIKE '%@example.com'
           AND (environment = ? OR environment IS NULL)
         ORDER BY id ASC
         LIMIT 50
    ");
    $stmt->execute([$env]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Candidates found: " . count($rows) . "\n";
    if (empty($rows)) {
        echo "Nothing to do.\n";
        exit;
    }

    $sent = 0;
    $skipped = 0;
    $errors = 0;

    foreach ($rows as $r) {
        $email = trim((string)$r['email']);
        $tpago = strtolower(trim((string)$r['tpago']));
        $msi   = (int)($r['msi_meses'] ?? 0);
        $amountCents = (int)round(((float)$r['total']) * 100);

        // Build a customer array shaped like create-payment-intent.php's
        // helper expects.
        $customer = [
            'modelo'   => $r['modelo'] ?? '',
            'color'    => $r['color']  ?? '',
            'tpago'    => $tpago,
        ];
        $purchaseTipo = $tpago !== '' ? $tpago : 'contado';

        echo " - id=" . $r['id'] . "  email=" . substr($email, 0, 40)
            . "  tipo=" . $purchaseTipo . "  amount=$" . number_format($amountCents/100, 2);

        if ($dryRun) {
            echo "  [DRY — would send]\n";
            $skipped++;
            continue;
        }

        // Re-check status RIGHT BEFORE sending (race-protection: payment
        // might have just succeeded since the SELECT above).
        $chk = $pdo->prepare("SELECT pago_estado FROM transacciones WHERE id = ?");
        $chk->execute([(int)$r['id']]);
        $currentStatus = (string)$chk->fetchColumn();
        if ($currentStatus !== 'pendiente') {
            echo "  [skip — status changed to '$currentStatus' since SELECT]\n";
            $skipped++;
            continue;
        }

        try {
            _voltikaSendIncompletePaymentEmail(
                $email,
                (string)($r['nombre'] ?? ''),
                $customer,
                $amountCents,
                $purchaseTipo,
                $msi
            );
            // Mark sent so we don't repeat tomorrow.
            $upd = $pdo->prepare("UPDATE transacciones SET recovery_email_sent_at = NOW() WHERE id = ?");
            $upd->execute([(int)$r['id']]);
            $sent++;
            echo "  [SENT ✓]\n";
        } catch (Throwable $e) {
            $errors++;
            echo "  [ERROR: " . $e->getMessage() . "]\n";
        }
    }

    echo "\nSummary:\n";
    echo "  sent    : $sent\n";
    echo "  skipped : $skipped\n";
    echo "  errors  : $errors\n";

} catch (Throwable $e) {
    echo "Fatal: " . $e->getMessage() . "\n";
    http_response_code(500);
    exit;
}
