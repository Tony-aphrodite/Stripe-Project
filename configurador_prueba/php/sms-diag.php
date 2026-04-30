<?php
/**
 * Voltika — SMS OTP delivery diagnostic + auto-repair.
 *
 * Diagnoses why SMS isn't arriving when 5,144+ credits are available.
 *
 * Tests (in order):
 *   1. logs/ directory existence + writability + auto-create if missing
 *   2. SMSMASIVOS_API_KEY env var configured + non-placeholder
 *   3. Recent log entries (if any)
 *   4. Live SMSMasivos balance check (read-only, no SMS sent)
 *      — confirms API key authenticates correctly
 *   5. Diagnosis with concrete next-step action
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
.diag.success{border-left-color:#10b981;}
.diag.fail{border-left-color:#ef4444;}
code{background:#020617;padding:1px 5px;border-radius:3px;color:#fbbf24;}
.row{padding:6px 0;border-bottom:1px solid #334155;}
.row:last-child{border-bottom:none;}
.label{display:inline-block;min-width:200px;color:#94a3b8;}
</style></head><body>
<h1>SMS OTP diagnostic + auto-repair</h1>
<p class="muted">Generated <?= date('Y-m-d H:i:s') ?></p>

<?php
$findings = [];      // accumulator: each is ['status'=>'ok|warn|bad', 'msg'=>...]
$canSend = true;     // overall verdict

// ── 1. logs/ directory ─────────────────────────────────────────
echo '<h2>1. Log directory</h2>';
$logDir = __DIR__ . '/logs';
$logFile = $logDir . '/sms-otp.log';

echo '<div class="row"><span class="label">Path:</span> <code>' . htmlspecialchars($logDir) . '</code></div>';
$dirExists = is_dir($logDir);
$dirWritable = $dirExists && is_writable($logDir);
echo '<div class="row"><span class="label">Exists:</span> ' . ($dirExists ? '<span class="ok">YES</span>' : '<span class="bad">NO</span>') . '</div>';
echo '<div class="row"><span class="label">Writable:</span> ' . ($dirWritable ? '<span class="ok">YES</span>' : '<span class="bad">NO</span>') . '</div>';

// Auto-repair: create the directory if missing.
if (!$dirExists) {
    echo '<p class="warn">Auto-repair: attempting to create <code>logs/</code>…</p>';
    $created = @mkdir($logDir, 0775, true);
    if ($created) {
        @chmod($logDir, 0775);
        echo '<p class="ok">[OK] Directory created with 0775 permissions.</p>';
        $findings[] = ['ok', 'logs/ directory was missing — created automatically.'];
        $dirExists = true;
        $dirWritable = is_writable($logDir);
    } else {
        $err = error_get_last();
        echo '<p class="bad">[FAIL] Could not create directory. Last error: ' .
             htmlspecialchars($err['message'] ?? '(unknown)') . '</p>';
    }
}

// Test write a probe entry; if it fails, attempt chmod auto-repair.
if ($dirExists && !$dirWritable) {
    echo '<p class="warn">Auto-repair: directory exists but not writable. Attempting <code>chmod 0775</code>…</p>';
    $chmodOk = @chmod($logDir, 0775);
    $dirWritable = is_writable($logDir);
    if ($dirWritable) {
        echo '<p class="ok">[OK] chmod succeeded — directory now writable.</p>';
        $findings[] = ['ok', 'logs/ permissions auto-repaired (chmod 0775).'];
    } else {
        echo '<p class="bad">[FAIL] chmod also failed — PHP-FPM is not the owner of this directory.</p>';
        echo '<p>Fix from SSH (run as root or with sudo):</p>';
        echo '<pre>chown -R $(stat -c %u ' . htmlspecialchars(dirname($logDir)) . '):$(stat -c %g ' . htmlspecialchars(dirname($logDir)) . ') ' . htmlspecialchars($logDir) . '
chmod -R 0775 ' . htmlspecialchars($logDir) . '</pre>';
        echo '<p>Or, on Plesk shared hosting, the more common fix:</p>';
        echo '<pre>chown -R psaadm:psacln ' . htmlspecialchars($logDir) . '
chmod -R 0775 ' . htmlspecialchars($logDir) . '</pre>';
        $findings[] = ['warn', 'logs/ not writable but enviar-otp.php now also writes to /tmp fallback — diagnostics work via that path.'];
    }
}
if ($dirExists && $dirWritable) {
    $probe = $logDir . '/diag-probe.tmp';
    $writeOk = @file_put_contents($probe, 'probe ' . date('c'));
    if ($writeOk !== false) {
        @unlink($probe);
        echo '<p class="ok">[OK] Probe write succeeded — directory is fully writable.</p>';
    }
}

// ── 2. API key configuration ───────────────────────────────────
echo '<h2>2. SMSMasivos API key</h2>';
$apiKey = defined('SMSMASIVOS_API_KEY') ? SMSMASIVOS_API_KEY : (getenv('SMSMASIVOS_API_KEY') ?: '');
$keyLen = strlen($apiKey);
$keyMasked = $keyLen > 0 ? substr($apiKey, 0, 4) . str_repeat('•', max(0, $keyLen - 8)) . substr($apiKey, -4) : '(empty)';

echo '<div class="row"><span class="label">SMSMASIVOS_API_KEY:</span> <code>' . htmlspecialchars($keyMasked) . '</code> (' . $keyLen . ' chars)</div>';

if ($keyLen === 0) {
    echo '<p class="bad">[FAIL] API key is empty. Set <code>SMSMASIVOS_API_KEY</code> in <code>.env</code>.</p>';
    $findings[] = ['bad', 'SMSMASIVOS_API_KEY is empty in env. Cannot send SMS.'];
    $canSend = false;
} elseif (in_array($apiKey, ['your_smsmasivos_api_key', 'CHANGEME', 'PLACEHOLDER'], true) ||
          stripos($apiKey, 'placeholder') !== false || stripos($apiKey, 'your_') !== false) {
    echo '<p class="bad">[FAIL] API key looks like a placeholder. Replace with the real key from your SMSMasivos account.</p>';
    $findings[] = ['bad', 'SMSMASIVOS_API_KEY contains a placeholder value, not the real key.'];
    $canSend = false;
} else {
    echo '<p class="ok">[OK] API key is set (length looks plausible).</p>';
}

// ── 3. Recent log entries ──────────────────────────────────────
echo '<h2>3. Recent send attempts</h2>';
// Read both canonical AND /tmp fallback log, merge by timestamp.
$tmpLog = sys_get_temp_dir() . '/voltika-sms-otp.log';
$rawCanon = file_exists($logFile) ? (@file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
$rawTmp   = file_exists($tmpLog)  ? (@file($tmpLog,  FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
echo '<p>Canonical log: ' . (file_exists($logFile)
    ? number_format(filesize($logFile)) . ' bytes (' . count($rawCanon) . ' lines, last ' . date('H:i:s', filemtime($logFile)) . ')'
    : '<span class="warn">absent</span>') . '</p>';
echo '<p>/tmp fallback: ' . (file_exists($tmpLog)
    ? number_format(filesize($tmpLog)) . ' bytes (' . count($rawTmp) . ' lines, last ' . date('H:i:s', filemtime($tmpLog)) . ')'
    : '<span class="warn">absent</span>') . '</p>';
$raw = array_unique(array_merge($rawCanon, $rawTmp));
if (empty($raw)) {
    echo '<p class="warn">[INFO] No log entries in either location yet — either no sends attempted, OR enviar-otp.php hasn\'t been redeployed with /tmp fallback. Run a fresh OTP send and refresh this page.</p>';
} else {
    // Sort by timestamp (entries are JSON; first key = timestamp).
    usort($raw, function($a, $b) {
        $da = json_decode($a, true);
        $db = json_decode($b, true);
        return strcmp($db['timestamp'] ?? '', $da['timestamp'] ?? '');
    });
    $entries = array_slice($raw, 0, 8);
    $successCount = 0; $apiErrorCount = 0; $curlErrorCount = 0;
    foreach ($entries as $line) {
        $e = json_decode($line, true);
        if (!is_array($e)) continue;
        $http = (int)($e['httpCode'] ?? 0);
        $curl = (string)($e['curlErr'] ?? '');
        $resp = (string)($e['response'] ?? '');
        $respDecoded = json_decode($resp, true);
        $apiSuccess = is_array($respDecoded) && !empty($respDecoded['success']);

        $cls = 'warn'; $status = '?';
        if ($curl !== '') { $status = 'cURL error'; $cls = 'bad'; $curlErrorCount++; }
        elseif ($http >= 200 && $http < 300 && $apiSuccess) { $status = 'API success'; $cls = 'ok'; $successCount++; }
        else { $status = 'API error'; $cls = 'bad'; $apiErrorCount++; }

        echo '<div class="entry">';
        echo '<strong>' . htmlspecialchars($e['timestamp'] ?? '?') . '</strong>';
        echo ' · <code>' . htmlspecialchars($e['telefono'] ?? '?') . '</code>';
        echo ' · HTTP <span class="' . $cls . '">' . $http . '</span>';
        echo ' · <span class="' . $cls . '">' . $status . '</span>';
        if ($curl !== '') echo '<br><span class="bad">cURL:</span> ' . htmlspecialchars($curl);
        if ($resp !== '') echo '<pre>' . htmlspecialchars(substr($resp, 0, 400)) . '</pre>';
        echo '</div>';
    }
    if (count($entries) === 0) {
        echo '<p class="muted">(log file exists but no parseable entries)</p>';
    } else {
        echo '<p class="muted">Last ' . count($entries) . ': ' .
             '<span class="ok">' . $successCount . ' OK</span>, ' .
             '<span class="bad">' . $apiErrorCount . ' API err</span>, ' .
             '<span class="bad">' . $curlErrorCount . ' cURL err</span></p>';
        if ($apiErrorCount > 0 && $successCount === 0) {
            $findings[] = ['bad', 'All recent sends failed at the API. See response body above.'];
        }
        if ($curlErrorCount > 0 && $successCount === 0) {
            $findings[] = ['bad', 'All recent sends had cURL errors — server cannot reach SMSMasivos.'];
        }
        if ($successCount > 0 && $apiErrorCount === 0 && $curlErrorCount === 0) {
            $findings[] = ['warn', 'SMSMasivos confirms send success but customer says SMS not arriving. Issue is downstream of SMSMasivos (carrier filtering or recipient blocked SMS).'];
        }
    }
}

// ── 4. Live API auth probe (read-only) ─────────────────────────
echo '<h2>4. Live SMSMasivos API auth check (no SMS sent)</h2>';
if ($keyLen === 0) {
    echo '<p class="muted">Skipped — no API key configured.</p>';
} else {
    // Try the credit/balance endpoint — read-only, costs nothing.
    // SMSMasivos exposes: GET https://api.smsmasivos.com.mx/credits/consult
    // (per the v2 API docs at app.smsmasivos.com.mx/api-docs/v2)
    $probeUrl = 'https://api.smsmasivos.com.mx/credits/consult';
    $ch = curl_init($probeUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['apikey: ' . $apiKey],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $probeResp = curl_exec($ch);
    $probeHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $probeErr  = curl_error($ch);
    curl_close($ch);

    echo '<div class="row"><span class="label">URL:</span> <code>' . htmlspecialchars($probeUrl) . '</code></div>';
    echo '<div class="row"><span class="label">HTTP:</span> <span class="' . ($probeHttp >= 200 && $probeHttp < 300 ? 'ok' : 'bad') . '">' . $probeHttp . '</span></div>';
    if ($probeErr) echo '<div class="row"><span class="label">cURL:</span> <span class="bad">' . htmlspecialchars($probeErr) . '</span></div>';
    if ($probeResp) echo '<pre>' . htmlspecialchars(substr($probeResp, 0, 600)) . '</pre>';

    if ($probeErr) {
        $findings[] = ['bad', 'Cannot reach api.smsmasivos.com.mx from this server. Check outbound firewall / DNS.'];
        $canSend = false;
    } elseif ($probeHttp === 401 || $probeHttp === 403) {
        $findings[] = ['bad', 'API key is rejected by SMSMasivos (HTTP ' . $probeHttp . '). Verify the key is active and has SMS-send permission.'];
        $canSend = false;
    } elseif ($probeHttp === 404) {
        echo '<p class="warn">Credits endpoint returned 404 — the v2 path may differ for your account. Try alternate endpoint:</p>';
        $alt = 'https://api.smsmasivos.com.mx/account';
        $ch2 = curl_init($alt);
        curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['apikey: '.$apiKey], CURLOPT_TIMEOUT=>10]);
        $r2 = curl_exec($ch2); $h2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE); curl_close($ch2);
        echo '<pre>HTTP ' . $h2 . "\n" . htmlspecialchars(substr((string)$r2, 0, 400)) . '</pre>';
        if ($h2 === 401 || $h2 === 403) {
            $findings[] = ['bad', 'API key rejected by alternate endpoint too — key is bad.'];
            $canSend = false;
        }
    } elseif ($probeHttp >= 200 && $probeHttp < 300) {
        $findings[] = ['ok', 'API key authenticates successfully against SMSMasivos. Send pipeline is live.'];
    } else {
        $findings[] = ['warn', 'SMSMasivos returned HTTP ' . $probeHttp . ' on the auth probe. Inspect response body for clue.'];
    }
}

// ── 5. Diagnosis ───────────────────────────────────────────────
echo '<h2>5. Diagnosis & action</h2>';
$cls = $canSend ? 'success' : 'fail';
echo '<div class="diag ' . $cls . '">';
if (count($findings) === 0) {
    echo '<p>No specific findings. Default action: check SMSMasivos dashboard\'s "Sent messages" tab for the recipient number to see actual delivery status.</p>';
} else {
    echo '<ul>';
    foreach ($findings as $f) {
        $cls2 = $f[0] === 'ok' ? 'ok' : ($f[0] === 'warn' ? 'warn' : 'bad');
        echo '<li><span class="' . $cls2 . '">[' . strtoupper($f[0]) . ']</span> ' . htmlspecialchars($f[1]) . '</li>';
    }
    echo '</ul>';
}

// Pragmatic next-step guidance based on findings.
echo '<h3>Concrete next step</h3>';
$hasSendFail = false; $hasAuthOk = false; $hasAuthFail = false; $hasCarrierSuspect = false;
foreach ($findings as $f) {
    if (strpos($f[1], 'authenticates successfully') !== false) $hasAuthOk = true;
    if (strpos($f[1], 'rejected by SMSMasivos') !== false || strpos($f[1], 'key is bad') !== false) $hasAuthFail = true;
    if (strpos($f[1], 'failed at the API') !== false || strpos($f[1], 'cURL') !== false) $hasSendFail = true;
    if (strpos($f[1], 'carrier filtering') !== false) $hasCarrierSuspect = true;
}

if ($hasAuthFail) {
    echo '<p>Fix: log in to <a href="https://app.smsmasivos.com.mx" target="_blank">app.smsmasivos.com.mx</a>, copy the current API key, paste it into <code>.env</code> as <code>SMSMASIVOS_API_KEY=...</code>, save. Then refresh this page.</p>';
} elseif ($hasCarrierSuspect) {
    echo '<p>API is healthy but SMS isn\'t arriving — that\'s a Mexican carrier filtering issue or recipient block. Test with a different phone number first to confirm. If it\'s carrier-wide, contact SMSMasivos support to register a sender ID with Telcel/AT&T/Movistar.</p>';
} elseif ($hasAuthOk && !$hasSendFail) {
    echo '<p>Pipeline looks healthy on our side. The SMS is leaving our server. Check the SMSMasivos dashboard\'s send-history for the test number — it will show "delivered", "failed", or "blocked" per carrier. That tells us whether the issue is upstream (us → SMSMasivos) or downstream (SMSMasivos → carrier → phone).</p>';
} else {
    echo '<p>Run a test send through the configurador and refresh this page. The new log entry will pinpoint where the request fails.</p>';
}
echo '</div>';
?>
</body></html>
