<?php
/**
 * Voltika Admin — Trace where the missing CDC register people are in our DB.
 *
 * Customer brief 2026-05-29: CDC's portal shows queries (JUAN PEREZ LOPEZ etc.)
 * that are not in our consultas_buro table. We need to find out:
 *   1. Are these people Voltika customers? (preaprobaciones / transacciones / clientes)
 *   2. If yes — through which flow did they come?
 *   3. If yes — did they ever reach our consultar-buro.php step?
 *
 * This script searches every relevant table by RFC and by name.
 * Read-only.
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();

// People from the CDC yellow page (the records we know are missing from our DB)
$people = [
    ['name' => 'JUAN PEREZ LOPEZ',                    'rfc' => 'PELJ900115',    'folio' => '2033073630'],
    ['name' => 'FERNANDA PAOLA CEBALLOS CAMPANA',     'rfc' => 'CECF901222',    'folio' => '2035291622'],
    ['name' => 'RODRIGO LOPEZ GARCINI',               'rfc' => 'LOGR820622',    'folio' => '2035295320'],
    ['name' => 'FIDENCIO VALENZUELA PENALOZA',        'rfc' => 'VAPF690803',    'folio' => '2035297664'],
    ['name' => 'ANTONIO DE JESUS MORALES SANDOVAL',   'rfc' => 'MOSA920417',    'folio' => '2035309115'],
];

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>CDC missing — trace</title>';
echo '<style>
body{font-family:system-ui,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1200px;margin:0 auto;line-height:1.55;}
h1{font-size:20px;margin:0 0 12px;}
h2{font-size:15px;background:#0c2340;color:#fff;padding:8px 12px;margin:24px 0 0;border-radius:6px 6px 0 0;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:0 0 6px 6px;padding:12px 14px;margin-bottom:6px;}
table{width:100%;border-collapse:collapse;font-size:12px;}
th{background:#f1f5f9;padding:5px 8px;text-align:left;font-size:10.5px;font-weight:600;}
td{padding:5px 8px;border-top:1px solid #f1f5f9;vertical-align:top;}
.found{color:#15803d;font-weight:700;}
.missing{color:#b91c1c;font-weight:700;}
.muted{color:#94a3b8;}
.tag{display:inline-block;padding:2px 8px;border-radius:4px;background:#e2e8f0;color:#0c2340;font-size:10.5px;font-weight:600;}
code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:11.5px;}
</style></head><body>';
echo '<h1>CDC missing — who are these people in our DB?</h1>';
echo '<p class="muted" style="font-size:12.5px;">Searching every relevant table by RFC (first 10 chars) and by name fragments.</p>';

// Get all table names that might contain customer data
$candidateTables = ['preaprobaciones','transacciones','subscripciones_credito',
                    'clientes','checklist_entrega_v2','verificaciones_identidad',
                    'entregas','consultas_buro'];

// Filter to only tables that exist
$existingTables = [];
foreach ($candidateTables as $t) {
    try {
        $q = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t));
        if ($q && $q->fetchColumn()) $existingTables[] = $t;
    } catch (Throwable $e) {}
}

// For each table, get list of columns so we know what to search
$tableCols = [];
foreach ($existingTables as $t) {
    try {
        $q = $pdo->query("SHOW COLUMNS FROM `$t`");
        $cols = array_column($q->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $tableCols[$t] = $cols;
    } catch (Throwable $e) { $tableCols[$t] = []; }
}

foreach ($people as $p) {
    echo '<h2>' . htmlspecialchars($p['name']) . ' &middot; <span style="font-weight:400;font-size:12px;">RFC: ' . htmlspecialchars($p['rfc']) . ' &middot; Folio CDC: ' . htmlspecialchars($p['folio']) . '</span></h2>';
    echo '<div class="card">';

    $foundAnywhere = false;

    // Build name fragments — search by surnames (most distinctive)
    $nameTokens = preg_split('/\s+/', $p['name']);
    // RFC base = first 10 chars (without homoclave)
    $rfcBase = substr($p['rfc'], 0, 10);

    foreach ($existingTables as $t) {
        $cols = $tableCols[$t];
        $whereParts = [];
        $params = [];

        // Search by RFC
        if (in_array('rfc', $cols)) {
            $whereParts[] = "rfc LIKE ?";
            $params[] = $rfcBase . '%';
        }
        // Search by name columns
        if (in_array('nombre', $cols)) {
            $whereParts[] = "nombre LIKE ?";
            $params[] = '%' . $p['name'] . '%';
            // Also try last token (e.g. LOPEZ)
            if (count($nameTokens) > 0) {
                $whereParts[] = "nombre LIKE ?";
                $params[] = '%' . end($nameTokens) . '%';
            }
        }
        if (in_array('apellido_paterno', $cols)) {
            // Try with one of the surnames
            $apPat = $nameTokens[count($nameTokens) - 2] ?? '';
            if ($apPat) {
                $whereParts[] = "apellido_paterno LIKE ?";
                $params[] = '%' . $apPat . '%';
            }
        }
        if (in_array('email', $cols)) {
            // Skip email — we don't have it
        }

        if (empty($whereParts)) continue;

        $sql = "SELECT * FROM `$t` WHERE " . implode(' OR ', $whereParts) . " LIMIT 10";
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $foundAnywhere = true;
                echo '<div style="margin-bottom:10px;"><span class="tag" style="background:#dcfce7;color:#166534;">' . count($rows) . ' match' . (count($rows)>1?'es':'') . '</span> in <code>' . $t . '</code></div>';
                echo '<table><thead><tr>';
                // Pick interesting columns to show
                $showCols = array_intersect(['id','nombre','apellido_paterno','apellido_materno','rfc','curp','telefono','email','modelo','color','direccion','colonia','ciudad','estado','cp','estatus','freg','fecha_alta','fecha_creacion'], $cols);
                foreach ($showCols as $c) echo '<th>' . htmlspecialchars($c) . '</th>';
                echo '</tr></thead><tbody>';
                foreach ($rows as $r) {
                    echo '<tr>';
                    foreach ($showCols as $c) {
                        $v = (string)($r[$c] ?? '');
                        if (strlen($v) > 80) $v = substr($v, 0, 80) . '…';
                        echo '<td>' . htmlspecialchars($v) . '</td>';
                    }
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
        } catch (Throwable $e) {
            echo '<p class="muted" style="font-size:11px;">Error searching <code>' . $t . '</code>: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
    }

    if (!$foundAnywhere) {
        echo '<p class="missing">No matches found in any table. This person is NOT a Voltika customer (or never reached any of our flows).</p>';
    }

    echo '</div>';
}

echo '<h2 style="background:#039fe1;">Summary &amp; next steps</h2>';
echo '<div class="card">';
echo '<p>For each person above, look at the result:</p>';
echo '<ul style="font-size:13px;line-height:1.7;">';
echo '<li><strong style="color:#15803d;">Found in <code>preaprobaciones</code> only</strong> &rarr; They started a credit application but the CDC query happened outside our flow OR our consultar-buro.php failed silently after CDC responded</li>';
echo '<li><strong style="color:#15803d;">Found in <code>transacciones</code> / <code>subscripciones_credito</code></strong> &rarr; They are real customers, completed purchase. CDC query was made but our insert failed</li>';
echo '<li><strong style="color:#b91c1c;">No matches anywhere</strong> &rarr; Someone queried CDC from outside our system (manual portal login, another tool, etc.)</li>';
echo '</ul>';
echo '</div>';

echo '</body></html>';
