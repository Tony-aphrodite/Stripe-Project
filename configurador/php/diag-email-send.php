<?php
/**
 * Voltika — SMTP / confirmation email diagnostic.
 *
 * Customer brief 2026-05-02: a contado buyer made a successful purchase
 * and received NO email (no Stripe receipt, no Voltika confirmation).
 * This diagnoses each layer of the email pipeline without sending to a
 * real customer:
 *   1. SMTP credentials presence + format
 *   2. PHPMailer class loaded
 *   3. sendMail() function defined
 *   4. Live SMTP test (sends to a token-supplied test address)
 *   5. Recent voltikaNotify activity (was confirmar-orden invoked?)
 *
 * Usage:
 *   ?token=voltika_diag_2026                    → diagnostic only
 *   ?token=voltika_diag_2026&to=you@you.com    → ALSO send a real test email
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
echo "  Voltika SMTP / confirmation email diagnostic\n";
echo "================================================================\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// ── 1. SMTP credentials present? ──────────────────────────────────────────
echo "1. SMTP configuration:\n";
$smtpHost = defined('SMTP_HOST') ? SMTP_HOST : '';
$smtpPort = defined('SMTP_PORT') ? SMTP_PORT : '';
$smtpUser = defined('SMTP_USER') ? SMTP_USER : '';
$smtpPass = defined('SMTP_PASS') ? SMTP_PASS : '';
printf("   SMTP_HOST : %s\n", $smtpHost ?: '(MISSING ✗)');
printf("   SMTP_PORT : %s\n", $smtpPort ?: '(MISSING ✗)');
printf("   SMTP_USER : %s\n", $smtpUser ?: '(MISSING ✗)');
printf("   SMTP_PASS : %s\n", $smtpPass ? ('(set, length=' . strlen($smtpPass) . ')') : '(MISSING ✗)');
echo "\n";

// ── 2. PHPMailer + sendMail availability ──────────────────────────────────
echo "2. Email library availability:\n";
$autoload = __DIR__ . '/vendor/autoload.php';
$autoloadExists = file_exists($autoload);
printf("   vendor/autoload.php   : %s\n", $autoloadExists ? 'present ✓' : 'MISSING ✗');
if ($autoloadExists) require_once $autoload;
$phpmailerLoaded = class_exists('PHPMailer\PHPMailer\PHPMailer');
printf("   PHPMailer class       : %s\n", $phpmailerLoaded ? 'loaded ✓' : 'NOT LOADED ✗ (will fall back to mail())');
$sendMailFn = function_exists('sendMail');
printf("   sendMail() function   : %s\n", $sendMailFn ? 'defined ✓' : 'MISSING ✗');
echo "\n";

// ── 3. SMTP connection test (TCP-only, doesn't send mail) ─────────────────
echo "3. SMTP server reachability:\n";
if ($smtpHost && $smtpPort) {
    $errno = 0; $errstr = '';
    $start = microtime(true);
    $sock = @fsockopen($smtpHost, (int)$smtpPort, $errno, $errstr, 8);
    $elapsed = round((microtime(true) - $start) * 1000);
    if ($sock) {
        $banner = trim((string)@fgets($sock, 256));
        fclose($sock);
        printf("   %s:%s reached in %dms ✓\n", $smtpHost, $smtpPort, $elapsed);
        printf("   Banner: %s\n", substr($banner, 0, 150));
    } else {
        printf("   %s:%s UNREACHABLE ✗ (errno=%d msg=%s)\n", $smtpHost, $smtpPort, $errno, $errstr);
    }
} else {
    echo "   skipped (no host/port configured)\n";
}
echo "\n";

// ── 4. Live send test (only if &to= provided) ─────────────────────────────
echo "4. Live send test:\n";
$toAddr = trim((string)($_GET['to'] ?? ''));
if ($toAddr === '') {
    echo "   SKIPPED — pass &to=your-email@domain.com to send a real test email.\n";
} elseif (!filter_var($toAddr, FILTER_VALIDATE_EMAIL)) {
    echo "   FAIL — invalid email format: $toAddr\n";
} else {
    echo "   Sending to: $toAddr ...\n";
    $subject = 'Voltika SMTP diag — ' . date('Y-m-d H:i:s');
    $html = '<div style="font-family:sans-serif;padding:20px;">'
          . '<h2>Voltika SMTP diagnostic</h2>'
          . '<p>This is a test email from <code>diag-email-send.php</code>.</p>'
          . '<p>Time: ' . date('c') . '</p>'
          . '<p>If you receive this, SMTP is working from this server.</p>'
          . '</div>';

    if (function_exists('sendMail')) {
        // Attempt PHPMailer/SMTP first. We deliberately catch + log any
        // PHPMailer exception so we can see the real reason a send fails
        // (smtp auth, blocked port, recipient rejected, etc.) instead of
        // sendMail's silent fallback to mail().
        $detailErr = '';
        if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            try {
                $m = new PHPMailer\PHPMailer\PHPMailer(true);
                $m->SMTPDebug = 0;            // 2 would print SMTP convo
                $m->isSMTP();
                $m->SMTPAuth   = true;
                $m->Host       = $smtpHost;
                $m->Port       = (int)$smtpPort;
                $m->Username   = $smtpUser;
                $m->Password   = $smtpPass;
                $m->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                $m->setFrom($smtpUser, 'Voltika DIAG');
                $m->addAddress($toAddr);
                $m->Subject = $subject;
                $m->Body    = $html;
                $m->AltBody = strip_tags($html);
                $m->isHTML(true);
                $m->send();
                echo "   PHPMailer/SMTP : SENT ✓\n";
            } catch (Throwable $e) {
                $detailErr = $e->getMessage();
                echo "   PHPMailer/SMTP : FAIL — $detailErr\n";
            }
        } else {
            echo "   PHPMailer not loaded, skipping SMTP test.\n";
        }

        // Also try the production sendMail() so we know whether the
        // production path itself works end-to-end.
        $okSend = false;
        try { $okSend = (bool) sendMail($toAddr, 'Voltika DIAG', $subject . ' (via sendMail)', $html); }
        catch (Throwable $e) { echo "   sendMail() exception: " . $e->getMessage() . "\n"; }
        echo "   sendMail() return : " . ($okSend ? 'TRUE ✓' : 'FALSE ✗ (silent failure — check error_log)') . "\n";
    } else {
        echo "   sendMail() not defined — cannot test.\n";
    }
}
echo "\n";

// ── 5. Recent voltikaNotify activity ──────────────────────────────────────
echo "5. Recent confirmation-email activity (last 20):\n";
try {
    $pdo = getDB();
    $tables = $pdo->query("SHOW TABLES LIKE 'notificaciones_log'")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($tables)) {
        echo "   notificaciones_log table doesn't exist — voltikaNotify never ran.\n";
    } else {
        $stmt = $pdo->query("
            SELECT id, tipo, canal, destino, status, error, freg
              FROM notificaciones_log
             WHERE tipo LIKE 'compra_confirmada%' OR tipo LIKE 'portal_%'
             ORDER BY id DESC
             LIMIT 20
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            echo "   No 'compra_confirmada*' or 'portal_*' notifications logged yet.\n";
            echo "   This means confirmar-orden.php's voltikaNotify() never fired,\n";
            echo "   or fired before notificaciones_log existed.\n";
        } else {
            printf("   %-4s %-30s %-9s %-30s %-8s %s\n",
                'ID', 'TIPO', 'CANAL', 'DESTINO', 'STATUS', 'FREG');
            echo "   " . str_repeat('-', 110) . "\n";
            foreach ($rows as $r) {
                printf("   %-4d %-30s %-9s %-30s %-8s %s\n",
                    $r['id'],
                    substr((string)$r['tipo'], 0, 30),
                    (string)$r['canal'],
                    substr((string)$r['destino'], 0, 30),
                    (string)$r['status'],
                    (string)$r['freg']);
                if (!empty($r['error'])) {
                    echo "        error: " . substr((string)$r['error'], 0, 200) . "\n";
                }
            }
        }
    }
} catch (Throwable $e) {
    echo "   query error: " . $e->getMessage() . "\n";
}

// ── 6. Most recent successful contado purchase ────────────────────────────
echo "\n6. Last 5 successful contado purchases (to verify confirmar-orden ran):\n";
try {
    $stmt = $pdo->query("
        SELECT id, nombre, email, telefono, modelo, total, pago_estado, stripe_pi,
               freg, notif_sent_at
          FROM transacciones
         WHERE pago_estado = 'pagada' AND tpago IN ('unico','contado','tarjeta')
         ORDER BY id DESC
         LIMIT 5
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "   No successful contado purchases found.\n";
    } else {
        foreach ($rows as $r) {
            printf("   id=%d  nombre=%s  email=%s  monto=$%s\n",
                $r['id'], substr($r['nombre'] ?? '', 0, 30),
                $r['email'] ?? '', number_format((float)$r['total'], 2));
            printf("      pago_estado=%s  freg=%s  notif_sent_at=%s\n",
                $r['pago_estado'], $r['freg'], $r['notif_sent_at'] ?: '(NULL — confirm email NOT dispatched)');
        }
    }
} catch (Throwable $e) {
    echo "   error: " . $e->getMessage() . "\n";
}

echo "\n================================================================\n";
echo "DELETE this file (diag-email-send.php) via FileZilla after use.\n";
