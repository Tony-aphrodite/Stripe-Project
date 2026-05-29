<?php
/**
 * Voltika Admin — Recover folio + score from the file log
 *   configurador/php/logs/circulo-credito.log
 *
 * Companion to recover-from-logs.php. The DB cdc_query_log may have failed
 * to insert for some early CDC calls, but consultar-buro.php ALSO writes
 * every request/response to a JSON-lines file log. We parse it here to
 * recover folio + score for backfilled rows that the DB log couldn't help.
 *
 * Each log line is a JSON object:
 *   {
 *     "timestamp":"2026-05-25T07:56:56+00:00",
 *     "nombre":"FERNANDA PAOLA CEBALLOS CAMPANA",
 *     "rfc_used":"CECF901222XXX",
 *     "body_sent": "... json ...",
 *     "response":  "... json ..." (with folioConsulta + scores)
 *   }
 *
 * Idempotent UPDATE — only fills NULL/empty fields. Two-stage flow.
 *
 * Auth: admin only. Free (no CDC calls).
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$commit = isset($_POST['commit']) && $_POST['commit'] === '1';

// Common log file locations to try
$logPaths = [
    __DIR__ . '/../../../configurador/php/logs/circulo-credito.log',
    __DIR__ . '/../../../configurador_prueba_test/php/logs/circulo-credito.log',
    '/var/www/vhosts/voltika.mx/configurador/php/logs/circulo-credito.log',
    '/var/www/vhosts/voltika.mx/private_storage/cdc_logs/circulo-credito.log',
];

$logLines = [];
$usedPath = '';
foreach ($logPaths as $p) {
    if (is_file($p) && is_readable($p)) {
        $usedPath = $p;
        $contents = @file_get_contents($p);
        if ($contents === false) continue;
        $lines = preg_split('/\r\n|\r|\n/', $contents);
        foreach ($lines as $ln) {
            $ln = trim($ln);
            if ($ln === '' || $ln[0] !== '{') continue;
            $obj = json_decode($ln, true);
            if (is_array($obj)) $logLines[] = $obj;
        }
        if (!empty($logLines)) break;
    }
}

// Build recovery plan
$plan = [];
$matched = 0; $unmatched = 0;
foreach ($logLines as $log) {
    $resp = (string)($log['response'] ?? '');
    $body = (string)($log['body_sent'] ?? '');
    if ($resp === '') continue;

    // Extract folio + score from response (handles truncated JSON via regex)
    $folio = null; $score = null;
    if (preg_match('/"folioConsulta"\s*:\s*"([^"]+)"/', $resp, $m)) $folio = $m[1];
    if (preg_match('/"scores"\s*:\s*\[\s*\{[^}]*"valor"\s*:\s*"?(\d+)"?/', $resp, $m)) $score = (int)$m[1];
    if (!$folio && $score === null) continue;

    // Extract name + RFC from body OR top-level log fields
    $name = (string)($log['nombre'] ?? '');
    $rfc  = (string)($log['rfc_used'] ?? '');
    if ($name === '' && $body !== '') {
        if (preg_match('/"primerNombre"\s*:\s*"([^"]+)"/', $body, $m)) {
            $name = $m[1];
            if (preg_match('/"apellidoPaterno"\s*:\s*"([^"]+)"/', $body, $m)) {
                $name .= ' ' . $m[1];
            }
        }
    }
    if ($rfc === '' && $body !== '') {
        if (preg_match('/"RFC"\s*:\s*"([^"]+)"/', $body, $m)) $rfc = $m[1];
    }
    if ($name === '' || $rfc === '') continue;

    // Find matching consultas_buro row (empty folio + matching RFC base)
    $rfcBase = substr($rfc, 0, 10);
    $nameParts = preg_split('/\s+/', trim($name));
    $primer = $nameParts[0] ?? '';
    $paterno = $nameParts[1] ?? '';
    $logTs = isset($log['timestamp']) ? strtotime((string)$log['timestamp']) : null;
    $logTsStr = $logTs ? date('Y-m-d H:i:s', $logTs) : date('Y-m-d H:i:s');

    $st = $pdo->prepare("SELECT id, nombre, apellido_paterno, folio_consulta, score, freg
        FROM consultas_buro
        WHERE (rfc LIKE ? OR rfc = ?
              OR (LOWER(nombre) = LOWER(?) AND LOWER(apellido_paterno) = LOWER(?)))
          AND (folio_consulta IS NULL OR folio_consulta = '' OR score IS NULL OR score = 0)
        ORDER BY ABS(TIMESTAMPDIFF(SECOND, freg, ?)) ASC LIMIT 1");
    $st->execute([$rfcBase . '%', $rfc, $primer, $paterno, $logTsStr]);
    $match = $st->fetch(PDO::FETCH_ASSOC);
    if (!$match) { $unmatched++; continue; }
    $matched++;
    $plan[] = [
        'cb_id'   => (int)$match['id'],
        'cb_name' => trim($match['nombre'] . ' ' . $match['apellido_paterno']),
        'cb_freg' => (string)$match['freg'],
        'log_ts'  => $logTsStr,
        'folio'   => $folio,
        'score'   => $score,
        'cur_folio' => (string)($match['folio_consulta'] ?? ''),
        'cur_score' => $match['score'],
    ];
}

// Dedup by cb_id, keeping most complete (folio AND score)
$bestByRow = [];
foreach ($plan as $p) {
    $rid = $p['cb_id'];
    if (!isset($bestByRow[$rid])) { $bestByRow[$rid] = $p; continue; }
    $cur = $bestByRow[$rid];
    $curScore = ($cur['folio'] ? 2 : 0) + ($cur['score'] !== null ? 1 : 0);
    $newScore = ($p['folio'] ? 2 : 0) + ($p['score'] !== null ? 1 : 0);
    if ($newScore > $curScore) $bestByRow[$rid] = $p;
}
$plan = array_values($bestByRow);

// COMMIT
$updateStats = null;
if ($commit && !empty($plan)) {
    $updated = 0; $errors = 0;
    foreach ($plan as $p) {
        try {
            $sets = []; $params = [];
            if ($p['folio']) { $sets[] = "folio_consulta = COALESCE(NULLIF(folio_consulta, ''), ?)"; $params[] = $p['folio']; }
            if ($p['score'] !== null) { $sets[] = "score = COALESCE(score, ?)"; $params[] = $p['score']; }
            $sets[] = "status = COALESCE(NULLIF(status, ''), 'recovered_from_file_log')";
            if (empty($sets)) continue;
            $params[] = $p['cb_id'];
            $up = $pdo->prepare("UPDATE consultas_buro SET " . implode(', ', $sets) . " WHERE id = ?");
            $up->execute($params);
            if ($up->rowCount() > 0) $updated++;
        } catch (Throwable $e) {
            $errors++;
            error_log('recover-from-file-log id ' . $p['cb_id'] . ': ' . $e->getMessage());
        }
    }
    $updateStats = compact('updated','errors');
    if (function_exists('adminLog')) {
        adminLog('recover_from_file_log', ['updated'=>$updated,'errors'=>$errors,'plan'=>count($plan)]);
    }
}

// UI
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Recover from file log</title>';
echo '<style>body{font-family:system-ui,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1180px;margin:0 auto;line-height:1.55;}
h1{font-size:20px;margin:0 0 6px;}
h2{font-size:14px;color:#475569;margin:18px 0 8px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;margin-bottom:12px;}
table{width:100%;border-collapse:collapse;font-size:11.5px;}
th{background:#f1f5f9;padding:5px 8px;text-align:left;}
td{padding:5px 8px;border-top:1px solid #f1f5f9;}
.btn{padding:8px 16px;background:#16a34a;color:#fff;border:0;border-radius:5px;cursor:pointer;font-weight:600;font-size:13px;text-decoration:none;display:inline-block;}
.btn.ghost{background:#fff;color:#0c2340;border:1px solid #cbd5e1;}
.banner{padding:11px 14px;border-radius:8px;font-size:13.5px;margin-bottom:12px;font-weight:600;}
.banner-info{background:#dbeafe;border:1px solid #93c5fd;color:#1e3a8a;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.banner-warn{background:#fef3c7;border:1px solid #fcd34d;color:#92400e;}
.ok{color:#15803d;font-weight:700;}
.muted{color:#94a3b8;}
code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:11px;}
</style></head><body>';
echo '<h1>Recover folio + score from file log — FREE</h1>';

if (!$usedPath) {
    echo '<div class="banner banner-warn">'
       . 'No log file found. Tried:<br>'
       . '<ul style="margin:6px 0;">';
    foreach ($logPaths as $p) echo '<li><code>' . htmlspecialchars($p) . '</code></li>';
    echo '</ul></div>';
    echo '<div class="card"><p>If the log file exists elsewhere, edit this script\'s $logPaths array.</p></div>';
    echo '</body></html>';
    exit;
}

echo '<div class="banner banner-info">'
   . 'Log file: <code>' . htmlspecialchars($usedPath) . '</code><br>'
   . 'Entries parsed: <strong>' . count($logLines) . '</strong> &middot; '
   . 'Matched to consultas_buro: <strong>' . $matched . '</strong> &middot; '
   . 'Unmatched: <strong>' . $unmatched . '</strong> &middot; '
   . 'Unique rows after dedup: <strong>' . count($plan) . '</strong>'
   . '</div>';

if ($updateStats) {
    echo '<div class="banner banner-ok">Recovered: <strong>' . $updateStats['updated'] . '</strong> rows &middot; Errors: <strong>' . $updateStats['errors'] . '</strong></div>';
    echo '<p><a class="btn" href="/admin/php/buro/exportar.php" target="_blank">Download CDC export CSV</a> ';
    echo '<a class="btn ghost" href="?">Re-scan</a></p>';
}

if (empty($plan)) {
    echo '<div class="card"><p>No matching log entries found that could fill any consultas_buro row.</p></div>';
} else {
    echo '<div class="card">';
    echo '<h2>Recovery plan (' . count($plan) . ' rows)</h2>';
    echo '<table><thead><tr><th>cb_id</th><th>Name</th><th>Log timestamp</th><th>Folio</th><th>Score</th><th>Currently empty?</th></tr></thead><tbody>';
    foreach ($plan as $p) {
        $needsFolio = $p['cur_folio'] === '';
        $needsScore = $p['cur_score'] === null;
        echo '<tr>';
        echo '<td>' . $p['cb_id'] . '</td>';
        echo '<td>' . htmlspecialchars($p['cb_name']) . '</td>';
        echo '<td>' . htmlspecialchars($p['log_ts']) . '</td>';
        echo '<td>' . htmlspecialchars((string)$p['folio']) . '</td>';
        echo '<td>' . htmlspecialchars((string)($p['score'] ?? '')) . '</td>';
        echo '<td>' . ($needsFolio ? 'folio ' : '') . ($needsScore ? 'score' : '') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    if (!$commit) {
        echo '<form method="post" style="margin-top:14px;">';
        echo '<input type="hidden" name="commit" value="1">';
        echo '<button class="btn" type="submit" onclick="return confirm(\'Recover ' . count($plan) . ' rows? FREE — no CDC charges.\');">Recover ' . count($plan) . ' rows (FREE)</button>';
        echo '</form>';
    }
    echo '</div>';
}

echo '</body></html>';
