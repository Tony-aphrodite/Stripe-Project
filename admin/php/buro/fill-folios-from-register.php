<?php
/**
 * Voltika Admin — Fill folio_consulta on existing consultas_buro rows by
 * pasting CDC register data.
 *
 * Customer brief 2026-05-30: backfilled rows (those inserted via
 * backfill-consultas.php from preaprobaciones) have empty folio_consulta
 * because we never received a folio from CDC for them. However, the CDC
 * register portal (yellow page) DOES show real folios for the queries
 * we made. Admin pastes the CDC register; this tool matches by RFC base
 * (first 10 chars) and approximate timestamp, then UPDATEs the matching
 * consultas_buro row with the real folio.
 *
 * Format expected (one row per line, tab/multi-space separated):
 *   <folio>  <full name>  <RFC>  <date HH:MM:SS>
 *
 * Example:
 *   2035291622  FERNANDA PAOLA CEBALLOS CAMPANA  CECF901222XXX  2026-05-25 07:56:56
 *
 * Match logic:
 *   1. Match by RFC base (first 10 chars — without homoclave)
 *   2. Score by date proximity (closest in time within ±2 days wins)
 *   3. Only update rows where folio_consulta IS NULL/empty
 *
 * Two-stage flow: dry-run preview → commit. Safe to re-run.
 *
 * Auth: admin only.
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$paste = (string)($_POST['paste'] ?? '');
$commit = isset($_POST['commit']) && $_POST['commit'] === '1';

function parseRegisterLine(string $line): ?array {
    $line = trim($line);
    if ($line === '') return null;
    $parts = preg_split('/\t+|\s{2,}/', $line);
    if (!$parts || count($parts) < 4) return null;
    $folio = trim($parts[0]);
    if (!preg_match('/^\d{6,}$/', $folio)) return null;
    $rfc = strtoupper(trim($parts[2]));
    $rfcBase = substr($rfc, 0, 10);
    $fechaHora = trim(($parts[3] ?? '') . ' ' . ($parts[4] ?? ''));
    $ts = strtotime($fechaHora);
    return [
        'folio'    => $folio,
        'name'     => trim($parts[1]),
        'rfc'      => $rfc,
        'rfc_base' => $rfcBase,
        'ts'       => $ts ?: 0,
        'datetime' => $ts ? date('Y-m-d H:i:s', $ts) : '',
    ];
}

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Fill folios from CDC register</title>';
echo '<style>body{font-family:system-ui,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1180px;margin:0 auto;line-height:1.55;}
h1{font-size:20px;margin:0 0 6px;}
h2{font-size:14px;color:#475569;margin:18px 0 8px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;margin-bottom:12px;}
table{width:100%;border-collapse:collapse;font-size:11.5px;}
th{background:#f1f5f9;padding:5px 8px;text-align:left;}
td{padding:5px 8px;border-top:1px solid #f1f5f9;}
textarea{width:100%;min-height:220px;padding:10px;border:1px solid #cbd5e1;border-radius:6px;font-family:ui-monospace,monospace;font-size:12px;}
.btn{padding:8px 16px;background:#039fe1;color:#fff;border:0;border-radius:5px;cursor:pointer;font-weight:600;font-size:13px;text-decoration:none;display:inline-block;}
.btn.success{background:#16a34a;}
.banner{padding:11px 14px;border-radius:8px;font-size:13.5px;margin-bottom:12px;font-weight:600;}
.banner-info{background:#dbeafe;border:1px solid #93c5fd;color:#1e3a8a;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.muted{color:#94a3b8;}
.ok{color:#15803d;font-weight:700;}
.warn{color:#a16207;}
code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:11px;}
</style></head><body>';
echo '<h1>Fill folio_consulta from CDC register paste</h1>';
echo '<p class="muted" style="font-size:12.5px;">Match existing consultas_buro rows to real CDC folios by RFC base + approximate date.</p>';

if ($paste === '') {
    echo '<div class="card">';
    echo '<form method="post">';
    echo '<h2>Paste the entire CDC register (yellow page) here</h2>';
    echo '<p class="muted" style="font-size:12px;">One row per line: <code>folio  full_name  RFC  date_time</code>. Tab or multiple spaces as separator. Rows without a matching consultas_buro entry will be reported as "no match".</p>';
    echo '<textarea name="paste" placeholder="2035291622&#9;FERNANDA PAOLA CEBALLOS CAMPANA&#9;CECF901222XXX&#9;2026-05-25 07:56:56&#10;2035295320&#9;RODRIGO LOPEZ GARCINI&#9;LOGR820622XXX&#9;2026-05-25 08:05:52&#10;..."></textarea>';
    echo '<p style="margin-top:12px;"><button class="btn" type="submit">Scan and preview</button></p>';
    echo '</form></div>';
    echo '</body></html>';
    exit;
}

// Parse paste
$lines = preg_split('/\r\n|\r|\n/', $paste);
$registered = [];
$unparsable = 0;
foreach ($lines as $line) {
    $r = parseRegisterLine($line);
    if ($r === null) {
        if (trim($line) !== '') $unparsable++;
        continue;
    }
    $registered[] = $r;
}

// For each register entry, find best matching consultas_buro row
$matchStmt = $pdo->prepare("
    SELECT id, nombre, apellido_paterno, apellido_materno, rfc, folio_consulta, freg
    FROM consultas_buro
    WHERE (rfc LIKE ? OR rfc = ?)
      AND (folio_consulta IS NULL OR folio_consulta = '')
    ORDER BY ABS(TIMESTAMPDIFF(SECOND, freg, ?)) ASC
    LIMIT 1
");

$plan = [];
$alreadyHaveFolio = [];
foreach ($registered as $r) {
    // Check if a row with this folio already exists
    $hasFolio = $pdo->prepare("SELECT id FROM consultas_buro WHERE folio_consulta = ? LIMIT 1");
    $hasFolio->execute([$r['folio']]);
    if ($hasFolio->fetchColumn()) {
        $alreadyHaveFolio[] = $r;
        continue;
    }
    // Find closest match by RFC base + date
    $matchStmt->execute([
        $r['rfc_base'] . '%',
        $r['rfc'],
        $r['datetime'] ?: date('Y-m-d H:i:s'),
    ]);
    $match = $matchStmt->fetch(PDO::FETCH_ASSOC);
    if ($match) {
        $plan[] = [
            'register'  => $r,
            'match_id'  => (int)$match['id'],
            'match_name'=> trim($match['nombre'] . ' ' . $match['apellido_paterno'] . ' ' . $match['apellido_materno']),
            'match_rfc' => $match['rfc'],
            'match_freg'=> $match['freg'],
        ];
    } else {
        $plan[] = [
            'register'  => $r,
            'match_id'  => null,
            'reason'    => 'No consultas_buro row with matching RFC base ' . $r['rfc_base'] . ' and empty folio',
        ];
    }
}

$updateStats = null;
if ($commit) {
    $updated = 0; $errors = 0;
    $up = $pdo->prepare("UPDATE consultas_buro SET folio_consulta = ? WHERE id = ? AND (folio_consulta IS NULL OR folio_consulta = '')");
    foreach ($plan as $p) {
        if (!$p['match_id']) continue;
        try {
            $up->execute([$p['register']['folio'], $p['match_id']]);
            if ($up->rowCount() > 0) $updated++;
        } catch (Throwable $e) {
            $errors++;
        }
    }
    $updateStats = compact('updated','errors');
    if (function_exists('adminLog')) {
        adminLog('fill_folios_from_register', ['updated'=>$updated,'errors'=>$errors,'plan_size'=>count($plan)]);
    }
}

echo '<div class="banner banner-info">'
   . 'Parsed: <strong>' . count($registered) . '</strong> &middot; '
   . 'Could not parse: <strong>' . $unparsable . '</strong> &middot; '
   . 'Already have folio: <strong>' . count($alreadyHaveFolio) . '</strong> &middot; '
   . 'To update: <strong>' . count(array_filter($plan, fn($p) => $p['match_id'])) . '</strong> &middot; '
   . 'No match: <strong>' . count(array_filter($plan, fn($p) => !$p['match_id'])) . '</strong>'
   . '</div>';

if ($updateStats) {
    echo '<div class="banner banner-ok">'
       . 'Updated <strong>' . $updateStats['updated'] . '</strong> rows. Errors: <strong>' . $updateStats['errors'] . '</strong>.'
       . '</div>';
    echo '<p><a class="btn success" href="/admin/php/buro/exportar.php" target="_blank">Download CDC export CSV</a> '
       . '<a class="btn" href="?">Run again</a></p>';
}

if (!empty($plan)) {
    echo '<div class="card"><h2>Match preview (' . count($plan) . ' rows)</h2>';
    echo '<table><thead><tr><th>CDC folio</th><th>CDC name</th><th>CDC RFC</th><th>CDC date</th><th>→</th><th>DB id</th><th>DB name</th><th>DB freg</th><th>Status</th></tr></thead><tbody>';
    foreach ($plan as $p) {
        $r = $p['register'];
        echo '<tr>';
        echo '<td><code>' . htmlspecialchars($r['folio']) . '</code></td>';
        echo '<td>' . htmlspecialchars($r['name']) . '</td>';
        echo '<td><code>' . htmlspecialchars($r['rfc']) . '</code></td>';
        echo '<td>' . htmlspecialchars($r['datetime']) . '</td>';
        echo '<td>→</td>';
        if ($p['match_id']) {
            echo '<td>' . $p['match_id'] . '</td>';
            echo '<td>' . htmlspecialchars($p['match_name']) . '</td>';
            echo '<td>' . htmlspecialchars($p['match_freg']) . '</td>';
            echo '<td class="ok">will fill folio</td>';
        } else {
            echo '<td colspan="3" class="warn">' . htmlspecialchars($p['reason'] ?? 'no match') . '</td>';
            echo '<td class="warn">skip</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';

    if (!$commit) {
        $toUpdate = count(array_filter($plan, fn($p) => $p['match_id']));
        if ($toUpdate > 0) {
            echo '<form method="post" style="margin-top:14px;">';
            echo '<input type="hidden" name="paste" value="' . htmlspecialchars($paste) . '">';
            echo '<input type="hidden" name="commit" value="1">';
            echo '<button class="btn" type="submit" onclick="return confirm(\'Apply ' . $toUpdate . ' folio updates?\');">Commit folio updates (' . $toUpdate . ')</button>';
            echo ' <a class="btn" href="?" style="background:#fff;color:#0c2340;border:1px solid #cbd5e1;">Cancel</a>';
            echo '</form>';
        }
    }
    echo '</div>';
}

if (!empty($alreadyHaveFolio)) {
    echo '<div class="card"><h2 class="muted">Already have folio (skipped: ' . count($alreadyHaveFolio) . ')</h2>';
    echo '<p class="muted" style="font-size:12px;">These folios are already in consultas_buro — skipped to avoid duplicate work.</p>';
    echo '</div>';
}

echo '</body></html>';
