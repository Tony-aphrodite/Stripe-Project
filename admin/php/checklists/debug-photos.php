<?php
/**
 * Voltika Admin — Debug checklist photo URLs.
 *
 * Customer brief 2026-05-30: photos still showing as broken thumbnails on PoS
 * checklist page even after the serve-foto auth fix. Need to verify:
 *   1. What URLs are stored in checklist_ensamble JSON arrays
 *   2. Whether the file at each URL actually exists on disk
 *   3. What HTTP status code the URL returns
 *
 * Usage: debug-photos.php?moto_id=NNN  (defaults to most recent ensamble)
 *
 * Auth: admin only.
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$motoId = (int)($_GET['moto_id'] ?? 0);

// If no moto_id given, find most recent checklist with photos
if ($motoId === 0) {
    $row = $pdo->query("SELECT moto_id FROM checklist_ensamble
        WHERE fotos_fase1 IS NOT NULL AND fotos_fase1 != ''
           OR fotos_desembalaje IS NOT NULL OR fotos_3_1_frenos IS NOT NULL
        ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) $motoId = (int)$row['moto_id'];
}

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Debug photos</title>';
echo '<style>body{font-family:system-ui,sans-serif;padding:20px;max-width:1200px;margin:0 auto;}
table{border-collapse:collapse;width:100%;font-size:11.5px;margin-top:10px;}
th{background:#f1f5f9;padding:5px 8px;text-align:left;}
td{padding:5px 8px;border-top:1px solid #f1f5f9;vertical-align:top;word-break:break-all;}
.ok{color:#15803d;font-weight:700;}
.err{color:#b91c1c;font-weight:700;}
.muted{color:#94a3b8;}
code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:11px;}
.path{background:#fef9c3;padding:4px 8px;border-radius:4px;font-family:ui-monospace,monospace;font-size:11px;display:inline-block;margin:2px 0;}
.path-ok{background:#dcfce7;}
.path-bad{background:#fee2e2;}
</style></head><body>';
echo '<h1>Debug checklist photos · moto_id=' . $motoId . '</h1>';

if ($motoId === 0) {
    echo '<p>No moto found with photos. Use <code>?moto_id=NNN</code></p></body></html>';
    exit;
}

// Show all possible storage locations
$candidatePaths = [
    realpath(__DIR__ . '/../../uploads/checklists/'),
    sys_get_temp_dir() . '/voltika_checklists/',
    '/var/www/vhosts/voltika.mx/private_storage/checklists/',
    '/var/www/vhosts/voltika.mx/private_storage/uploads/checklists/',
    '/var/www/vhosts/voltika.mx/httpdocs/admin/uploads/checklists/',
];
echo '<h2>Storage directory check</h2><table><thead><tr><th>Path</th><th>Exists?</th><th>Writable?</th><th>File count</th></tr></thead><tbody>';
foreach ($candidatePaths as $p) {
    if (!$p) { echo '<tr><td colspan="4"><em>(null path)</em></td></tr>'; continue; }
    $exists = is_dir($p);
    $writable = $exists ? is_writable($p) : false;
    $count = 0;
    if ($exists) {
        $f = @glob($p . '/ensamble_*');
        $count = is_array($f) ? count($f) : 0;
    }
    echo '<tr>';
    echo '<td class="path' . ($exists ? ' path-ok' : ' path-bad') . '">' . htmlspecialchars($p) . '</td>';
    echo '<td>' . ($exists ? '<span class="ok">YES</span>' : '<span class="err">NO</span>') . '</td>';
    echo '<td>' . ($writable ? 'YES' : 'NO') . '</td>';
    echo '<td>' . $count . '</td>';
    echo '</tr>';
}
echo '</tbody></table>';

// Load checklist_ensamble row
$cl = $pdo->prepare("SELECT * FROM checklist_ensamble WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
$cl->execute([$motoId]);
$row = $cl->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo '<p>No checklist_ensamble row for moto_id ' . $motoId . '</p></body></html>';
    exit;
}

echo '<h2>Photos in checklist_ensamble (id=' . (int)$row['id'] . ')</h2>';

$fotoCols = ['fotos_fase1','fotos_fase3','fotos_desembalaje','fotos_base','fotos_manubrio','fotos_llanta','fotos_espejos','fotos_3_1_frenos','fotos_3_2_iluminacion','fotos_3_3_electrico','fotos_3_4_motor','fotos_3_5_acceso','fotos_3_6_mecanica'];

echo '<table><thead><tr><th>Section</th><th>URL stored in DB</th><th>Filename</th><th>Exists on disk?</th><th>Location</th></tr></thead><tbody>';
$totalChecked = 0; $totalFound = 0;
foreach ($fotoCols as $col) {
    if (empty($row[$col])) continue;
    $arr = json_decode((string)$row[$col], true);
    if (!is_array($arr)) continue;
    foreach ($arr as $url) {
        $filename = basename(parse_url((string)$url, PHP_URL_QUERY) ? (parse_url((string)$url, PHP_URL_QUERY)) : (string)$url);
        // Extract f= parameter
        $qs = parse_url((string)$url, PHP_URL_QUERY);
        if ($qs) {
            parse_str($qs, $qsArr);
            if (!empty($qsArr['f'])) $filename = (string)$qsArr['f'];
        }
        $foundAt = '';
        foreach ($candidatePaths as $p) {
            if (!$p) continue;
            $fp = rtrim($p, '/') . '/' . $filename;
            if (is_file($fp)) { $foundAt = $fp; break; }
        }
        $totalChecked++;
        if ($foundAt) $totalFound++;
        echo '<tr>';
        echo '<td><code>' . $col . '</code></td>';
        echo '<td class="path">' . htmlspecialchars((string)$url) . '</td>';
        echo '<td><code>' . htmlspecialchars($filename) . '</code></td>';
        echo '<td>' . ($foundAt ? '<span class="ok">YES</span>' : '<span class="err">NO</span>') . '</td>';
        echo '<td>' . htmlspecialchars($foundAt ?: '(not found in any candidate path)') . '</td>';
        echo '</tr>';
    }
}
echo '</tbody></table>';
echo '<p><strong>Summary:</strong> ' . $totalFound . ' / ' . $totalChecked . ' photos found on disk</p>';

echo '<h2>Quick test</h2>';
echo '<p>Click any URL above to open it directly. Expected: image displays. If you see 401 → auth issue. If 404 → file missing. If 403 → permission issue.</p>';

echo '</body></html>';
