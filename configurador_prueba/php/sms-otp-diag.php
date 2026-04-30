<?php
/**
 * Voltika — SMS OTP delivery diagnostic.
 *
 * Reads the recent entries from logs/sms-otp.log (written by
 * enviar-otp.php on every send attempt) and renders them as a single
 * page so we can diagnose why customers report SMS not arriving even
 * when the SMSMasivos balance shows healthy credit.
 *
 * Usage:
 *   https://voltika.mx/configurador_prueba/php/sms-otp-diag.php?token=voltika_diag_2026
 *
 * What to look for:
 *   - HTTP 200 + response with "success":true → SMSMasivos accepted the
 *     send. If user still doesn't receive, the issue is downstream
 *     (carrier filter, do-not-disturb, wrong number).
 *   - HTTP 401/403 → API key rejected. Check SMSMASIVOS_API_KEY env var.
 *   - HTTP 4xx with error message → fix the request payload.
 *   - cURL error → network / DNS / TLS problem.
 *   - No log entries at all → enviar-otp.php never reached, or logs/
 *     directory not writable.
 */
require_once __DIR__ . '/config.php';

// Auth — admin session OR shared diag token
@session_name('VOLTIKA_ADMIN');
if (session_status() === PHP_SESSION_NONE) @session_start();
$adminOk = !empty($_SESSION['admin_user_id']);
$expectedToken = getenv('VOLTIKA_DIAG_TOKEN') ?: (defined('VOLTIKA_DIAG_TOKEN') ? VOLTIKA_DIAG_TOKEN : 'voltika_diag_2026');
$tokenOk = isset($_GET['token']) && hash_equals($expectedToken, $_GET['token']);
if (!$adminOk && !$tokenOk) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><body style="font-family:system-ui;padding:30px;">';
    echo '<h2>SMS OTP diag — protected</h2>';
    echo '<p>Use <code>?token=' . htmlspecialchars($expectedToken) . '</code></p>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>SMS OTP diag</title>
<style>
body{font-family:ui-monospace,Menlo,monospace;background:#0f172a;color:#e2e8f0;padding:20px;line-height:1.5;font-size:13px;}
h1,h2{color:#60a5fa;}
h2{margin-top:24px;border-bottom:1px solid #334155;padding-bottom:6px;}
.entry{margin:10px 0;padding:10px 14px;border-left:4px solid #60a5fa;background:#1e293b;border-radius:4px;}
.entry.ok{border-left-color:#10b981;}
.entry.bad{border-left-color:#ef4444;}
.entry.warn{border-left-color:#f59e0b;}
.ok{color:#10b981;font-weight:bold;}
.bad{color:#ef4444;font-weight:bold;}
.warn{color:#f59e0b;font-weight:bold;}
pre{background:#020617;padding:8px 10px;border-radius:4px;overflow:auto;font-size:11px;white-space:pre-wrap;word-break:break-all;margin:6px 0;}
.diagnosis{background:#1e293b;padding:14px;border-radius:8px;border-left:4px solid #60a5fa;margin:14px 0;}
table{border-collapse:collapse;margin:8px 0;}
th,td{border:1px solid #334155;padding:4px 8px;text-align:left;font-size:12px;}
th{background:#020617;}
</style></head><body>
<h1>📱 SMS OTP delivery diagnostic</h1>
<p>Generated <?= date('Y-m-d H:i:s') ?></p>

<?php
// ── 1. Config sanity check ──────────────────────────────────────────────
echo '<h2>1. Config sanity</h2>';
$apiKeySet = defined('SMSMASIVOS_API_KEY') && SMSMASIVOS_API_KEY !== '' && SMSMASIVOS_API_KEY !== 'your_smsmasivos_api_key';
echo '<table>';
echo '<tr><th>SMSMASIVOS_API_KEY</th><td>' . ($apiKeySet
    ? '<span class="ok">SET</span> (length=' . strlen(SMSMASIVOS_API_KEY) . ', last 4: ' . htmlspecialchars(substr(SMSMASIVOS_API_KEY, -4)) . ')'
    : '<span class="bad">NOT SET or placeholder</span>') . '</td></tr>';
echo '<tr><th>logs/ writable</th><td>' . (is_dir(__DIR__ . '/logs') && is_writable(__DIR__ . '/logs')
    ? '<span class="ok">YES</span>'
    : (is_dir(__DIR__ . '/logs') ? '<span class="warn">exists but NOT writable</span>' : '<span class="bad">does not exist</span>')) . '</td></tr>';
echo '<tr><th>otp_temp/ writable</th><td>' . (is_dir(__DIR__ . '/otp_temp') && is_writable(__DIR__ . '/otp_temp')
    ? '<span class="ok">YES</span>'
    : '<span class="warn">missing or read-only</span>') . '</td></tr>';
echo '</table>';

// ── 2. Recent log entries ───────────────────────────────────────────────
echo '<h2>2. Last 20 SMS send attempts (most recent first)</h2>';
$logFile = __DIR__ . '/logs/sms-otp.log';
if (!file_exists($logFile)) {
    echo '<p class="bad">Log file does not exist: ' . htmlspecialchars($logFile) . '</p>';
    echo '<p>Either no SMS attempts have been made since the logs/ directory was last cleared, or enviar-otp.php cannot write to logs/.</p>';
    $entries = [];
} else {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        echo '<p class="warn">Log file exists but is empty.</p>';
        $entries = [];
    } else {
        $lines = array_reverse($lines);
        $lines = array_slice($lines, 0, 20);
        $entries = [];
        foreach ($lines as $line) {
            $e = json_decode($line, true);
            if ($e) $entries[] = $e;
        }
        foreach ($entries as $e) {
            $http = (int)($e['httpCode'] ?? 0);
            $curlErr = (string)($e['curlErr'] ?? '');
            $resp = (string)($e['response'] ?? '');
            $respJson = json_decode($resp, true);
            $apiSuccess = is_array($respJson) && !empty($respJson['success']);
            $cls = ($http >= 200 && $http < 300 && !$curlErr && $apiSuccess) ? 'ok'
                 : (($http >= 200 && $http < 300 && !$curlErr) ? 'warn' : 'bad');
            echo '<div class="entry ' . $cls . '">';
            echo '<strong>' . htmlspecialchars($e['timestamp'] ?? '?') . '</strong>';
            echo ' · phone <code>' . htmlspecialchars($e['telefono'] ?? '?') . '</code>';
            echo ' · attempt ' . (int)($e['send_count'] ?? 0);
            echo ' · HTTP <span class="' . $cls . '">' . $http . '</span>';
            if ($curlErr) echo ' · cURL <span class="bad">' . htmlspecialchars($curlErr) . '</span>';
            if ($resp) {
                echo '<pre>' . htmlspecialchars(substr($resp, 0, 600)) . '</pre>';
            }
            echo '</div>';
        }
    }
}

// ── 3. Diagnosis ───────────────────────────────────────────────────────
echo '<h2>3. Diagnosis</h2>';
echo '<div class="diagnosis">';
if (!$apiKeySet) {
    echo '<p class="bad"><strong>SMSMASIVOS_API_KEY is not configured.</strong> Set it in .env on the server. The fallback "testCode" mode is firing for every send (which is why no real SMS goes out, but the SPA shows "Modo prueba: usa el código…" — if this is showing in the OTP screen that confirms it).</p>';
} elseif (empty($entries)) {
    echo '<p class="warn">No log entries — either enviar-otp.php was never called, or the log file got rotated. Have the customer trigger a fresh OTP send and refresh this page.</p>';
} else {
    $latest = $entries[0];
    $http = (int)($latest['httpCode'] ?? 0);
    $resp = json_decode((string)($latest['response'] ?? ''), true);
    if ($http === 401 || $http === 403) {
        echo '<p class="bad"><strong>API key rejected (HTTP ' . $http . ').</strong> SMSMasivos is refusing to authenticate. The key in <code>SMSMASIVOS_API_KEY</code> is wrong, expired, or was rotated. Get a fresh key from the SMSMasivos dashboard and update .env.</p>';
    } elseif ($http >= 200 && $http < 300 && is_array($resp) && !empty($resp['success'])) {
        echo '<p class="ok"><strong>SMSMasivos accepted the send (HTTP 200, success=true).</strong> The bottleneck is downstream — the SMS was queued by SMSMasivos but didn\'t reach the device. Possible causes:</p>';
        echo '<ul>';
        echo '<li>The recipient number is on a carrier do-not-disturb list (Telcel/AT&T/Movistar block test/promotional senders).</li>';
        echo '<li>The phone is in airplane mode or out of coverage.</li>';
        echo '<li>SMSMasivos is queuing but the carrier short-code is delayed (check SMSMasivos dashboard <em>Sent messages</em> tab — does it show <strong>delivered</strong> or <strong>queued</strong>?).</li>';
        echo '<li>The message body triggered spam filters (the body uses "codigo de verificacion" — usually fine but worth checking).</li>';
        echo '</ul>';
        echo '<p>Recommended: verify on the SMSMasivos dashboard whether each send shows <em>delivered</em> for the test number. If they show queued/failed, contact SMSMasivos support with the reference IDs.</p>';
    } elseif ($http >= 400 && $http < 500) {
        echo '<p class="bad"><strong>SMSMasivos rejected the request (HTTP ' . $http . ').</strong> The error response above shows what they didn\'t like. Common: invalid number format, missing required field, or country_code mismatch.</p>';
    } elseif ($http >= 500) {
        echo '<p class="bad"><strong>SMSMasivos server error (HTTP ' . $http . ').</strong> Their service is having an outage. Retry in a few minutes; if it persists, contact SMSMasivos support.</p>';
    } elseif ($http === 0 && !empty($latest['curlErr'])) {
        echo '<p class="bad"><strong>Network error reaching SMSMasivos:</strong> <code>' . htmlspecialchars($latest['curlErr']) . '</code>. Possible DNS, TLS, or firewall issue from the server.</p>';
    } else {
        echo '<p class="warn">Unexpected response shape. Inspect the latest entry above to determine the issue.</p>';
    }
}
echo '</div>';
?>

<h2>4. What to do next</h2>
<ul>
    <li><strong>If section 3 says "API key rejected"</strong> → update <code>SMSMASIVOS_API_KEY</code> in <code>.env</code>.</li>
    <li><strong>If section 3 says "SMSMasivos accepted but didn't reach device"</strong> → check the <em>Sent messages</em> tab in the SMSMasivos dashboard for the actual delivery status of the test number.</li>
    <li><strong>If no entries</strong> → ask the customer to trigger a fresh OTP send right now, then refresh this page.</li>
</ul>

</body></html>
