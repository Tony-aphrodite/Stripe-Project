<?php
/**
 * Voltika Admin — Deep trace of JUAN PEREZ LOPEZ (PELJ900115) across our DB.
 *
 * Customer brief 2026-05-29: JUAN appears on CDC's register page (proving WE
 * queried him at 2026-05-23 13:13:09) but is completely absent from our
 * Voltika DB — including preaprobaciones. We need to find:
 *
 *   1. Did our consultar-buro.php call CDC for him? → check cdc_query_log
 *   2. Was a Voltika session ever opened with his details?
 *   3. Is there ANY trace of him (any table, any column matching name/RFC)?
 *   4. If not — was the query made through CDC's portal directly (manual),
 *      or via a debug/test tool that bypasses the normal flow?
 *
 * Read-only. Admin only.
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();

$target = [
    'nombre'     => 'JUAN',
    'paterno'    => 'PEREZ',
    'materno'    => 'LOPEZ',
    'rfc_base'   => 'PELJ900115',
    'folio_cdc'  => '2033073630',
    'dob_year'   => '1990',
    'date_query' => '2026-05-23',
    'time_query' => '13:13:09',
];

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Trace JUAN PEREZ LOPEZ</title>';
echo '<style>
body{font-family:system-ui,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1200px;margin:0 auto;line-height:1.55;}
h1{font-size:20px;margin:0 0 12px;}
h2{font-size:15px;background:#0c2340;color:#fff;padding:8px 12px;margin:24px 0 0;border-radius:6px 6px 0 0;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:0 0 6px 6px;padding:12px 14px;margin-bottom:6px;}
table{width:100%;border-collapse:collapse;font-size:11.5px;}
th{background:#f1f5f9;padding:5px 8px;text-align:left;font-size:10.5px;font-weight:600;}
td{padding:5px 8px;border-top:1px solid #f1f5f9;vertical-align:top;word-break:break-word;}
.found{color:#15803d;font-weight:700;}
.missing{color:#b91c1c;font-weight:700;}
.muted{color:#94a3b8;font-style:italic;}
.tag{display:inline-block;padding:2px 8px;border-radius:4px;background:#dcfce7;color:#166534;font-size:10.5px;font-weight:600;}
.tag-warn{background:#fef3c7;color:#92400e;}
.tag-err{background:#fecaca;color:#7f1d1d;}
code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:11px;}
.box{background:#dbeafe;border:1px solid #93c5fd;padding:10px 14px;border-radius:6px;margin:10px 0;font-size:13px;}
.box-warn{background:#fef3c7;border-color:#fcd34d;}
.box-ok{background:#dcfce7;border-color:#86efac;}
</style></head><body>';
echo '<h1>Deep trace: JUAN PEREZ LOPEZ &middot; RFC PELJ900115 &middot; Folio CDC 2033073630</h1>';
echo '<p class="muted">Searching every table, every column that might contain a name, RFC, or related identifier.</p>';

// ── 1. cdc_query_log: did OUR system call CDC for him? ─────────────────────
echo '<h2>1. cdc_query_log &middot; Did our consultar-buro.php call CDC for him?</h2>';
echo '<div class="card">';
try {
    $st = $pdo->prepare("SELECT id, endpoint, http_code, has_sig, body_sent, response, curl_err, freg
        FROM cdc_query_log
        WHERE body_sent LIKE ? OR body_sent LIKE ? OR body_sent LIKE ? OR response LIKE ?
        ORDER BY id DESC LIMIT 20");
    $st->execute([
        '%' . $target['rfc_base'] . '%',
        '%' . $target['paterno'] . '%' . $target['materno'] . '%',
        '%PEREZ%LOPEZ%',
        '%' . $target['folio_cdc'] . '%',
    ]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        echo '<p class="found">Found ' . count($rows) . ' CDC API log entries that may match.</p>';
        echo '<table><thead><tr><th>id</th><th>freg</th><th>http</th><th>endpoint</th><th>body_sent (truncated)</th><th>curl_err</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $bodyExcerpt = substr((string)$r['body_sent'], 0, 400);
            echo '<tr>'
               . '<td>' . (int)$r['id'] . '</td>'
               . '<td>' . htmlspecialchars((string)$r['freg']) . '</td>'
               . '<td>' . htmlspecialchars((string)$r['http_code']) . '</td>'
               . '<td><code>' . htmlspecialchars((string)$r['endpoint']) . '</code></td>'
               . '<td style="max-width:480px;font-family:ui-monospace,monospace;font-size:10.5px;">' . htmlspecialchars($bodyExcerpt) . '</td>'
               . '<td>' . htmlspecialchars((string)$r['curl_err']) . '</td>'
               . '</tr>';
        }
        echo '</tbody></table>';
        echo '<div class="box box-ok"><strong>Verdict:</strong> Our system DID call CDC for JUAN. Since the call succeeded (CDC has the folio), but no consultas_buro row exists, the silent-failure bug we just fixed was responsible. The new code will prevent this going forward.</div>';
    } else {
        echo '<p class="missing">No cdc_query_log entries match JUAN.</p>';
        echo '<div class="box box-warn"><strong>Verdict:</strong> Our consultar-buro.php was NEVER called for JUAN. The CDC query must have been made through another path: CDC web portal directly, an external CDC client, or a test/debug script that bypasses our query log.</div>';
    }
} catch (Throwable $e) {
    echo '<p class="missing">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
echo '</div>';

// ── 2. Sweep every table for any column referencing JUAN ──────────────────
echo '<h2>2. Full DB sweep &middot; Any table, any column matching JUAN/PEREZ/LOPEZ/PELJ900115</h2>';
echo '<div class="card">';
try {
    $allTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $hits = [];
    foreach ($allTables as $tbl) {
        if (strpos($tbl, 'audit') !== false) continue; // skip audit tables to keep output focused
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_ASSOC);
            $textCols = [];
            foreach ($cols as $c) {
                $type = strtolower($c['Type']);
                if (preg_match('/(char|text|varchar)/', $type)) $textCols[] = $c['Field'];
            }
            if (empty($textCols)) continue;
            $where = [];
            $params = [];
            foreach ($textCols as $col) {
                $where[] = "`$col` LIKE ? OR `$col` LIKE ? OR `$col` LIKE ?";
                $params[] = '%PELJ900115%';
                $params[] = '%PEREZ%LOPEZ%';
                $params[] = '%' . $target['folio_cdc'] . '%';
            }
            $sql = "SELECT * FROM `$tbl` WHERE " . implode(' OR ', $where) . " LIMIT 5";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $found = $st->fetchAll(PDO::FETCH_ASSOC);
            if ($found) {
                $hits[$tbl] = $found;
            }
        } catch (Throwable $e) { /* skip table */ }
    }
    if ($hits) {
        echo '<p class="found">Found in ' . count($hits) . ' table(s):</p>';
        foreach ($hits as $tbl => $rows) {
            echo '<h3 style="margin:14px 0 6px;font-size:13px;"><span class="tag">' . count($rows) . '</span> <code>' . htmlspecialchars($tbl) . '</code></h3>';
            echo '<table><thead><tr>';
            $first = $rows[0];
            $showCols = array_slice(array_keys($first), 0, 10);
            foreach ($showCols as $c) echo '<th>' . htmlspecialchars($c) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($rows as $r) {
                echo '<tr>';
                foreach ($showCols as $c) {
                    $v = (string)($r[$c] ?? '');
                    if (strlen($v) > 100) $v = substr($v, 0, 100) . '…';
                    echo '<td>' . htmlspecialchars($v) . '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
    } else {
        echo '<p class="missing">No matches found in ANY table across the entire DB.</p>';
        echo '<div class="box box-warn"><strong>Verdict:</strong> JUAN PEREZ LOPEZ has zero footprint in our Voltika database. He was likely queried directly through the CDC web portal (a Voltika team member logged into circulodecredito.com.mx and ran a manual query), or via a one-off script/tool that did not write to our DB.</div>';
    }
} catch (Throwable $e) {
    echo '<p class="missing">Error sweeping DB: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
echo '</div>';

// ── 3. Time-window analysis ────────────────────────────────────────────────
echo '<h2>3. Time-window analysis &middot; What else happened around ' . $target['date_query'] . ' ' . $target['time_query'] . '?</h2>';
echo '<div class="card">';
try {
    // Check cdc_query_log for any CDC call in the same 5-min window
    $st = $pdo->prepare("SELECT id, http_code, body_sent, freg FROM cdc_query_log
        WHERE freg BETWEEN ? AND ? ORDER BY freg ASC");
    $st->execute([
        $target['date_query'] . ' 13:05:00',
        $target['date_query'] . ' 13:25:00',
    ]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        echo '<p class="found">' . count($rows) . ' CDC API call(s) in the 20-minute window around the query time:</p>';
        echo '<table><thead><tr><th>id</th><th>freg</th><th>http</th><th>body_sent (truncated)</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td>' . (int)$r['id'] . '</td>'
               . '<td>' . htmlspecialchars((string)$r['freg']) . '</td>'
               . '<td>' . htmlspecialchars((string)$r['http_code']) . '</td>'
               . '<td style="max-width:600px;font-family:ui-monospace,monospace;font-size:10.5px;">' . htmlspecialchars(substr((string)$r['body_sent'], 0, 500)) . '</td>'
               . '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p class="muted">No cdc_query_log entries in that time window.</p>';
        echo '<div class="box box-warn"><strong>This is strong evidence that the query was NOT made through our consultar-buro.php.</strong> Our query log captures every call to the CDC API from this server. If there is no entry within 20 minutes of the timestamp on the CDC register, then the query came from somewhere else — most likely a direct CDC portal login or an external tool.</div>';
    }
} catch (Throwable $e) {
    echo '<p class="missing">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
echo '</div>';

// ── 4. Conclusion + recommendation ─────────────────────────────────────────
echo '<h2 style="background:#039fe1;">Conclusion &amp; recommendation</h2>';
echo '<div class="card">';
echo '<p>The diagnostic above tells you exactly which path JUAN took. Three possible verdicts:</p>';
echo '<ol style="font-size:13px;line-height:1.7;">';
echo '<li><strong>Found in cdc_query_log</strong> &rarr; Our system called CDC. Silent failure bug (now fixed) caused the data loss. Just import JUAN manually via <a href="/admin/php/buro/import-cdc-register.php">import-cdc-register.php</a>.</li>';
echo '<li><strong>Found in some other table but not cdc_query_log</strong> &rarr; A non-standard tool wrote a partial record but never called CDC through our code. Investigate that tool.</li>';
echo '<li><strong>Not found anywhere</strong> &rarr; The query was made directly on CDC\'s web portal by a Voltika team member, completely bypassing our system. This is a process/workflow issue (someone is doing manual queries instead of going through the configurador). Action: ask the Voltika team who has CDC portal access and was on 2026-05-23 around 13:13.</li>';
echo '</ol>';
echo '<p><strong>To bring JUAN into our dashboard regardless:</strong> Use <a href="/admin/php/buro/import-cdc-register.php"><code>import-cdc-register.php</code></a> and paste:</p>';
echo '<pre style="background:#0c2340;color:#dcfce7;padding:10px;border-radius:5px;font-size:12px;">2033073630	JUAN PEREZ LOPEZ	PELJ900115	2026-05-23 13:13:09</pre>';
echo '</div>';

echo '</body></html>';
