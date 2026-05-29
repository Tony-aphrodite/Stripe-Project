<?php
/**
 * Quick check: lookup specific consultas_buro records by RFC to see if
 * their address columns are populated.
 */
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><style>
body{font-family:system-ui,sans-serif;padding:20px;max-width:1180px;margin:0 auto;}
table{border-collapse:collapse;width:100%;font-size:11px;}
th,td{padding:6px 9px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top;}
th{background:#f1f5f9;font-size:10px;text-transform:uppercase;}
.empty{color:#dc2626;font-style:italic;}
.has{color:#15803d;}
</style></head><body>';
echo '<h1>🔍 CDC records — address check</h1>';

$rfcs = ['PELJ900115', 'CECF901222', 'LOGR820622', 'VAPF690803', 'MOSA920417',
         'MAAF910725', 'AAPE660404', 'PEOM930524', 'SARR780521', 'MATC850301',
         'BUCA080413', 'LORA030522', 'BAGO000426'];

$qmarks = implode(',', array_fill(0, count($rfcs), 'CONCAT(?, \'%\')'));
$params = $rfcs;
$sql = "SELECT id, folio_consulta, nombre, apellido_paterno, apellido_materno, rfc,
        calle_numero, colonia, municipio, ciudad, estado, estado_geo, cp, freg
        FROM consultas_buro
        WHERE rfc LIKE " . implode(' OR rfc LIKE ', array_fill(0, count($rfcs), '?'))
        . " ORDER BY freg DESC";
// Use LIKE with prefix
$likeParams = array_map(fn($r) => $r . '%', $rfcs);
$st = $pdo->prepare($sql);
$st->execute($likeParams);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

echo '<p>Buscando ' . count($rfcs) . ' RFCs · encontrados ' . count($rows) . ' registros</p>';

echo '<table><thead><tr>'
   . '<th>id</th><th>folio</th><th>nombre</th><th>RFC</th>'
   . '<th>calle_numero</th><th>colonia</th><th>municipio</th>'
   . '<th>ciudad</th><th>estado</th><th>estado_geo</th><th>cp</th>'
   . '<th>freg</th></tr></thead><tbody>';
foreach ($rows as $r) {
    $cells = ['calle_numero','colonia','municipio','ciudad','estado','estado_geo','cp'];
    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td>' . htmlspecialchars((string)$r['folio_consulta']) . '</td>';
    echo '<td>' . htmlspecialchars(trim($r['nombre'] . ' ' . $r['apellido_paterno'] . ' ' . $r['apellido_materno'])) . '</td>';
    echo '<td>' . htmlspecialchars((string)$r['rfc']) . '</td>';
    foreach ($cells as $c) {
        $v = (string)($r[$c] ?? '');
        $cls = $v === '' ? 'empty' : 'has';
        echo '<td class="' . $cls . '">' . htmlspecialchars($v ?: '(vacío)') . '</td>';
    }
    echo '<td>' . htmlspecialchars((string)$r['freg']) . '</td>';
    echo '</tr>';
}
echo '</tbody></table></body></html>';
