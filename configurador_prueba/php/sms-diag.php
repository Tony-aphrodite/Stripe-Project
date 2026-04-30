<?php
/**
 * Voltika — SMS OTP delivery diagnostic.
 *
 * Shows the last N entries from logs/sms-otp.log so we can see exactly
 * what SMSMasivos returns for each send attempt. Customer report
 * 2026-04-30: 5,144 credits available but SMS not arriving — answers
 * the question of whether the API is failing silently or returning
 * success but the carrier is dropping the message.
 *
 * Auth: ?token=voltika_diag_2026 (or admin session).
 *   https://voltika.mx/configurador_prueba/php/sms-diag.php?token=voltika_diag_2026
 */
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_name('VOLTIKA_ADMIN');
    @session_start();
}
$expectedToken = getenv('VOLTIKA_DIAG_TOKEN') ?: (defined('VOLTIKA_DIAG_TOKEN') ? VOLTIKA_DIAG_TOKEN : 'voltika_diag_2026');
$adminOk = !empty($_SESSION['admin_user_id']);
$tokenOk = isset($_GET['token']) && hash_equals($expectedToken, $_GET['token']);
if (!$adminOk && !$tokenOk) {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><body style="font-family:system-ui;padding:30px;">';
    echo '<h2>SMS diag — acceso protegido</h2>';
    echo '<p>Use: <code>?token=' . htmlspecialchars($expectedToken) . '</code></p>';
    echo '<p><a href="?token=' . urlencode($expectedToken) . '">▶ Abrir</a></p>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><title>SMS OTP diag</title>
<style>
body{font-family:ui-monospace,Menlo,monospace;background:#0f172a;color:#e2e8f0;padding:20px;line-height:1.5;font-size:13px;margin:0;}
h1{color:#fff;font-size:18px;margin:0 0 4px;}
h2{color:#60a5fa;margin-top:22px;border-bottom:1px solid #334155;padding-bottom:6px;font-size:15px;}
.entry{margin:8px 0;padding:10px 12px;border-left:3px solid #60a5fa;background:#1e293b;border-radius:4px;}
.ok{color:#10b981;font-weight:bold;}
.bad{color:#ef4444;font-weight:bold;}
.warn{color:#f59e0b;font-weight:bold;}
.muted{color:#64748b;}
pre{background:#020617;padding:8px 10px;border-radius:4px;overflow:auto;font-size:11px;white-space:pre-wrap;word-break:break-all;margin:6px 0 0;}
.diag{background:#1e293b;padding:14px;border-radius:8px;border-left:4px solid #f59e0b;margin:14px 0;}
code{background:#020617;padding:1px 5px;border-radius:3px;color:#fbbf24;}
</style></head><body>
<h1>SMS OTP delivery diagnostic</h1>
<p class="muted">Generated <?= date('Y-m-d H:i:s') ?></p>

<?php
$logFile = __DIR__ . '/logs/sms-otp.log';

echo '<h2>1. Log file</h2>';
echo '<p>Path: <code>' . htmlspecialchars($logFile) . '</code></p>';
if (!file_exists($logFile)) {
    echo '<p class="bad">[FAIL] Log file does not exist.</p>';
    echo '<div class="diag"><strong>Diagnosis:</strong> No SMS sends recorded yet on this server, OR the <code>logs/</code> directory could not be created (permissions). If you have tested SMS recently and this file is missing, the <code>enviar-otp.php</code> endpoint isn\'t being reached at all (front-end isn\'t calling it, or PHP can\'t write to <code>php/logs/</code>).</div>';
    exit;
}
$size = filesize($logFile);
echo '<p>Size: ' . number_format($size) . ' bytes · last modified: ' . date('Y-m-d H:i:s', filemtime($logFile)) . '</p>';

echo '<h2>2. Last 15 send attempts (most recent first)</h2>';
$raw = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!$raw) {
    echo '<p class="warn">[WARN] Log file empty or unreadable.</p>';
    exit;
}
$entries = array_reverse($raw);
$entries = array_slice($entries, 0, 15);

$totalShown = 0;
$successCount = 0;
$apiErrorCount = 0;
$curlErrorCount = 0;
$lastEntry = null;

foreach ($entries as $line) {
    $e = json_decode($line, true);
    if (!is_array($e)) continue;
    $totalShown++;
    if ($lastEntry === null) $lastEntry = $e;

    $http = (int)($e['httpCode'] ?? 0);
    $curl = (string)($e['curlErr'] ?? '');
    $resp = (string)($e['response'] ?? '');
    $respDecoded = json_decode($resp, true);
    $apiSuccess = is_array($respDecoded) && !empty($respDecoded['success']);

    $status = '?';
    $cls = 'warn';
    if ($curl !== '') {
        $status = 'cURL error';
        $cls = 'bad';
        $curlErrorCount++;
    } elseif ($http >= 200 && $http < 300 && $apiSuccess) {
        $status = 'API success';
        $cls = 'ok';
        $successCount++;
    } else {
        $status = 'API error';
        $cls = 'bad';
        $apiErrorCount++;
    }

    echo '<div class="entry">';
    echo '<strong>' . htmlspecialchars($e['timestamp'] ?? '?') . '</strong>';
    echo ' · phone: <code>' . htmlspecialchars($e['telefono'] ?? '?') . '</code>';
    echo ' · HTTP <span class="' . $cls . '">' . $http . '</span>';
    echo ' · <span class="' . $cls . '">' . $status . '</span>';
    if (isset($e['send_count'])) echo ' · attempt #' . (int)$e['send_count'];
    if ($curl !== '') echo '<br><span class="bad">cURL:</span> ' . htmlspecialchars($curl);
    if ($resp !== '') {
        echo '<pre>' . htmlspecialchars(substr($resp, 0, 600)) . '</pre>';
    }
    echo '</div>';
}

echo '<p class="muted">Counts in last ' . $totalShown . ': ' .
     '<span class="ok">' . $successCount . ' OK</span>, ' .
     '<span class="bad">' . $apiErrorCount . ' API error</span>, ' .
     '<span class="bad">' . $curlErrorCount . ' cURL error</span></p>';

// ── Diagnosis ──────────────────────────────────────────────────
echo '<h2>3. Diagnosis</h2>';
echo '<div class="diag">';
if ($totalShown === 0) {
    echo '<p class="bad">Log has no parseable entries.</p>';
} elseif ($curlErrorCount > 0 && $curlErrorCount === $totalShown) {
    echo '<p class="bad"><strong>Every recent send hit a cURL error.</strong> The server cannot reach <code>api.smsmasivos.com.mx</code>. Check outbound HTTPS connectivity, DNS, or firewall rules from this host.</p>';
} elseif ($apiErrorCount > 0 && $successCount === 0) {
    echo '<p class="bad"><strong>SMSMasivos is rejecting every request.</strong> Look at the response body in the most-recent entry above. Common causes: <ul>';
    echo '<li>Wrong / expired <code>SMSMASIVOS_API_KEY</code> in <code>.env</code> (most common — credit balance still visible because it\'s a different account property)</li>';
    echo '<li>Account suspended or in test mode (the dashboard credits may not actually be usable)</li>';
    echo '<li>Wrong endpoint or auth header — verify <code>apikey:</code> header and <code>https://api.smsmasivos.com.mx/sms/send</code> URL with SMSMasivos support</li>';
    echo '</ul></p>';
} elseif ($successCount === $totalShown) {
    echo '<p class="warn"><strong>SMSMasivos confirms every send as successful, but the customer doesn\'t receive the SMS.</strong> The bottleneck is downstream of SMSMasivos:<ul>';
    echo '<li><strong>Carrier filtering / antispam</strong> — Telcel/Movistar/AT&T often silently drop unsolicited SMS in México. Ask SMSMasivos support whether your sender ID needs to be registered with Mexican carriers.</li>';
    echo '<li><strong>Recipient blocked SMS</strong> — Test with a different phone number on a different carrier (Telcel vs Movistar) to isolate.</li>';
    echo '<li><strong>SMS body content</strong> — words like "código" or international format issues sometimes trigger spam filters. Current body uses ASCII without accents, so this is unlikely.</li>';
    echo '<li><strong>Phone format</strong> — SMSMasivos receives <code>numbers=' . htmlspecialchars($lastEntry['telefono'] ?? '') . '</code> with <code>country_code=52</code>. Confirm the dashboard\'s "Sent" log actually shows delivery to this number.</li>';
    echo '</ul></p>';
    echo '<p class="warn">Best next step: check the SMSMasivos web dashboard\'s send-history view for the most recent number — it should show delivery status per message (delivered / failed / pending). If the dashboard shows "delivered" but the phone never received, the issue is 100% carrier-side.</p>';
} else {
    echo '<p class="warn">Mixed results — some sends OK, some failing. Inspect the failing entries above; the failing pattern (specific number? specific time? recurring HTTP code?) will tell us where to look.</p>';
}
echo '</div>';
?>
</body></html>
