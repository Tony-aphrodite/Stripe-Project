<?php
/**
 * Voltika Admin — One-shot fix: fill JUAN PEREZ LOPEZ address from the
 * diagnostic test data.
 *
 * Customer brief 2026-05-29: JUAN PEREZ LOPEZ shows on the CDC dashboard
 * (folio 2033073630, RFC PELJ900115) but the address column is empty.
 * Root cause: JUAN is not a real customer — he is the default test data
 * baked into admin/php/diagnostico-cdc.php. The diagnostic tool sent these
 * exact address values to CDC at the time of the test query:
 *
 *   direccion           = 'CALLE FALSA 123'
 *   coloniaPoblacion    = 'CENTRO'
 *   delegacionMunicipio = 'BENITO JUAREZ'
 *   ciudad              = 'CIUDAD DE MEXICO'
 *   estado              = 'CDMX'
 *   CP                  = '03100'
 *
 * This script UPDATEs his consultas_buro row with the same values so the
 * dashboard and CDC export show a complete record. Idempotent: only fills
 * NULL/empty fields.
 *
 * Auth: admin only.
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Fix JUAN address</title>';
echo '<style>body{font-family:system-ui,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:780px;margin:0 auto;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;margin-bottom:12px;}
table{width:100%;border-collapse:collapse;font-size:12px;}
th{background:#f1f5f9;padding:6px 9px;text-align:left;}
td{padding:6px 9px;border-top:1px solid #f1f5f9;}
.btn{padding:8px 16px;background:#039fe1;color:#fff;border:0;border-radius:5px;cursor:pointer;font-weight:600;}
.ok{color:#15803d;font-weight:700;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;padding:11px 14px;border-radius:8px;margin-bottom:12px;color:#166534;font-weight:600;}
</style></head><body>';
echo '<h1>Fix JUAN PEREZ LOPEZ address (diagnostic test data)</h1>';

$row = $pdo->query("SELECT id, nombre, apellido_paterno, apellido_materno, rfc,
    calle_numero, colonia, municipio, ciudad, estado, cp, folio_consulta, origen
    FROM consultas_buro
    WHERE folio_consulta = '2033073630'
       OR (rfc LIKE 'PELJ900115%' AND nombre = 'JUAN')
    ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo '<div class="card"><p>No JUAN PEREZ LOPEZ row found in consultas_buro. Use the import-cdc-register tool first.</p></div>';
    echo '</body></html>';
    exit;
}

echo '<div class="card"><h2>Current state (id ' . (int)$row['id'] . ')</h2>';
echo '<table><tr><th>Field</th><th>Value</th></tr>';
foreach (['nombre','apellido_paterno','apellido_materno','rfc','folio_consulta','origen',
          'calle_numero','colonia','municipio','ciudad','estado','cp'] as $f) {
    $v = (string)($row[$f] ?? '');
    echo '<tr><td>' . $f . '</td><td>' . htmlspecialchars($v === '' ? '(empty)' : $v) . '</td></tr>';
}
echo '</table></div>';

if (isset($_POST['commit'])) {
    $updates = [
        'calle_numero' => 'CALLE FALSA 123',
        'colonia'      => 'CENTRO',
        'municipio'    => 'BENITO JUAREZ',
        'ciudad'       => 'CIUDAD DE MEXICO',
        'estado'       => 'CDMX',
        'cp'           => '03100',
    ];
    // Only fill empty fields
    $sets = [];
    $params = [];
    foreach ($updates as $f => $v) {
        if (empty($row[$f])) {
            $sets[] = "`$f` = ?";
            $params[] = $v;
        }
    }
    if (empty($sets)) {
        echo '<div class="banner-ok">All address fields already filled. Nothing to do.</div>';
    } else {
        $params[] = $row['id'];
        $sql = "UPDATE consultas_buro SET " . implode(', ', $sets) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo '<div class="banner-ok">Updated ' . count($sets) . ' field(s) on JUAN PEREZ LOPEZ row.</div>';
        echo '<p><a class="btn" href="?">Reload to verify</a> ';
        echo '<a class="btn" href="/admin/php/buro/exportar.php" target="_blank" style="background:#16a34a;">Download CDC export CSV</a></p>';
        if (function_exists('adminLog')) {
            adminLog('fix_juan_address', ['id'=>$row['id'], 'fields'=>array_keys($updates)]);
        }
    }
} else {
    echo '<div class="card"><h2>Proposed updates</h2>';
    echo '<p>Will write these values (only to fields currently empty):</p>';
    echo '<table><tr><th>Field</th><th>Will set to</th></tr>';
    foreach ([
        'calle_numero' => 'CALLE FALSA 123',
        'colonia'      => 'CENTRO',
        'municipio'    => 'BENITO JUAREZ',
        'ciudad'       => 'CIUDAD DE MEXICO',
        'estado'       => 'CDMX',
        'cp'           => '03100',
    ] as $f => $v) {
        $current = (string)($row[$f] ?? '');
        $will = $current === '' ? '<span class="ok">' . htmlspecialchars($v) . '</span>' : '<em>skipped (already filled: ' . htmlspecialchars($current) . ')</em>';
        echo '<tr><td>' . $f . '</td><td>' . $will . '</td></tr>';
    }
    echo '</table>';
    echo '<form method="post" style="margin-top:14px;">';
    echo '<button class="btn" type="submit" name="commit" value="1">Commit update</button>';
    echo '</form></div>';
}

echo '</body></html>';
