<?php
/**
 * Voltika Admin — Import CDC register entries that are missing from our DB,
 * then re-export the CDC compliance CSV with full address data.
 *
 * Customer brief 2026-05-29: CDC's registry shows queries we made (or that
 * were made through other channels) that are not present in our consultas_buro
 * table. Result: our admin dashboard cannot see them, and we cannot include
 * address data when CDC asks for compliance reports.
 *
 * Tool flow:
 *   1. Admin pastes CDC register data (folio, name, RFC, date)
 *   2. Tool parses each row
 *   3. For each row not already in consultas_buro (by RFC + folio match):
 *      - INSERT a new consultas_buro row with the basic identifiers
 *      - Backfill address from preaprobaciones / transacciones by RFC/name match
 *      - Mark as imported (origen = 'cdc_register_import')
 *   4. Show summary of imported / matched / unmatched
 *   5. Offer "re-download CDC export" link with addresses included
 *
 * Auth: admin only.
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$action = (string)($_POST['action'] ?? 'paste');
$paste  = (string)($_POST['paste'] ?? '');

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Import CDC register</title>';
echo '<style>
body{font-family:system-ui,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1080px;margin:0 auto;line-height:1.55;}
h1{font-size:20px;margin:0 0 4px;}
h2{font-size:14px;color:#475569;margin:18px 0 8px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;margin-bottom:12px;}
table{width:100%;border-collapse:collapse;font-size:12px;}
th{background:#f1f5f9;padding:6px 9px;text-align:left;font-size:10.5px;}
td{padding:6px 9px;border-top:1px solid #f1f5f9;vertical-align:top;}
textarea{width:100%;min-height:220px;padding:10px;border:1px solid #cbd5e1;border-radius:6px;font-family:ui-monospace,monospace;font-size:12px;}
.banner{padding:11px 14px;border-radius:8px;font-size:13.5px;margin-bottom:12px;font-weight:600;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.banner-warn{background:#fef3c7;border:1px solid #fcd34d;color:#92400e;}
.btn{padding:8px 16px;background:#039fe1;color:#fff;border:0;border-radius:5px;cursor:pointer;font-weight:600;font-size:13px;text-decoration:none;display:inline-block;}
.btn.danger{background:#dc2626;}
.btn.success{background:#16a34a;}
.btn.ghost{background:#fff;color:#0c2340;border:1px solid #cbd5e1;}
.ok{color:#15803d;font-weight:700;}
.warn{color:#a16207;}
.err{color:#b91c1c;font-weight:700;}
.muted{color:#94a3b8;}
.step{display:inline-block;width:22px;height:22px;border-radius:50%;background:#039fe1;color:#fff;text-align:center;font-weight:700;line-height:22px;margin-right:6px;}
</style></head><body>';
echo '<h1>Import CDC register &rarr; DB &middot; Re-export with addresses</h1>';
echo '<p style="color:#64748b;font-size:12.5px;margin-top:0;">Paste the rows from the CDC register (the yellow page). The tool imports them into our database and fills in the address if it finds the customer in preaprobaciones or transacciones. Then you can re-download the CDC NIP-CIEC CSV with full addresses included.</p>';

// Ensure schema has origen column for tracking imported rows
try { $pdo->exec("ALTER TABLE consultas_buro ADD COLUMN origen VARCHAR(40) NULL DEFAULT 'configurador'"); } catch (Throwable $e) {}

// Helper: parse a pasted line of CDC data
function parseCdcLine(string $line): ?array {
    $line = trim($line);
    if ($line === '') return null;
    $parts = preg_split('/\t+|\s{2,}/', $line);
    if (!$parts || count($parts) < 4) return null;
    $folio = trim($parts[0]);
    if (!preg_match('/^\d{6,}$/', $folio)) return null;
    $nombreCompleto = trim($parts[1]);
    $rfc = strtoupper(trim($parts[2]));
    $fechaHora = trim(($parts[3] ?? '') . ' ' . ($parts[4] ?? ''));
    $ts = strtotime($fechaHora);
    return [
        'folio'           => $folio,
        'nombre_completo' => $nombreCompleto,
        'rfc'             => $rfc,
        'fecha_hora'      => $ts ? date('Y-m-d H:i:s', $ts) : null,
    ];
}

function splitNombre(string $fullName): array {
    $parts = preg_split('/\s+/', trim($fullName));
    if (!$parts || count($parts) === 0) return ['', '', ''];
    if (count($parts) === 1) return [$parts[0], '', ''];
    if (count($parts) === 2) return [$parts[0], $parts[1], ''];
    if (count($parts) === 3) return [$parts[0], $parts[1], $parts[2]];
    $apellidoMaterno = array_pop($parts);
    $apellidoPaterno = array_pop($parts);
    $nombre = implode(' ', $parts);
    return [$nombre, $apellidoPaterno, $apellidoMaterno];
}

function lookupAddress(PDO $pdo, string $rfc, string $nombreCompleto): array {
    $address = ['calle_numero'=>'','colonia'=>'','municipio'=>'','ciudad'=>'','estado'=>'','cp'=>''];
    try {
        $rfcBase = substr($rfc, 0, 10);
        $st = $pdo->prepare("SELECT * FROM preaprobaciones WHERE rfc LIKE ? OR rfc = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$rfcBase . '%', $rfc]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if ($p) {
            if (!empty($p['direccion']))  $address['calle_numero'] = (string)$p['direccion'];
            if (!empty($p['colonia']))    $address['colonia']      = (string)$p['colonia'];
            if (!empty($p['municipio']))  $address['municipio']    = (string)$p['municipio'];
            if (!empty($p['ciudad']))     $address['ciudad']       = (string)$p['ciudad'];
            if (!empty($p['estado']))     $address['estado']       = (string)$p['estado'];
            if (!empty($p['cp']))         $address['cp']           = (string)$p['cp'];
        }
    } catch (Throwable $e) {}
    if ($address['calle_numero'] === '' || $address['cp'] === '') {
        try {
            $st = $pdo->prepare("SELECT * FROM transacciones WHERE nombre LIKE ? ORDER BY id DESC LIMIT 1");
            $st->execute(['%' . trim($nombreCompleto) . '%']);
            $t = $st->fetch(PDO::FETCH_ASSOC);
            if ($t) {
                if ($address['calle_numero'] === '' && !empty($t['direccion'])) $address['calle_numero'] = (string)$t['direccion'];
                if ($address['ciudad']       === '' && !empty($t['ciudad']))    $address['ciudad']       = (string)$t['ciudad'];
                if ($address['estado']       === '' && !empty($t['estado']))    $address['estado']       = (string)$t['estado'];
                if ($address['cp']           === '' && !empty($t['cp']))        $address['cp']           = (string)$t['cp'];
            }
        } catch (Throwable $e) {}
    }
    return $address;
}

$importStats = null;
if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST' && $paste !== '') {
    $lines = preg_split('/\r\n|\r|\n/', $paste);
    $imported = 0; $skipped = 0; $unparsable = 0; $details = [];
    foreach ($lines as $line) {
        $row = parseCdcLine($line);
        if (!$row) { if (trim($line) !== '') $unparsable++; continue; }
        $exists = $pdo->prepare("SELECT id FROM consultas_buro WHERE rfc = ? OR folio_consulta = ? LIMIT 1");
        $exists->execute([$row['rfc'], $row['folio']]);
        if ($exists->fetchColumn()) {
            $skipped++;
            $details[] = ['action'=>'skip','folio'=>$row['folio'],'rfc'=>$row['rfc'],'msg'=>'Already in DB'];
            continue;
        }
        [$nombre, $apPat, $apMat] = splitNombre($row['nombre_completo']);
        $addr = lookupAddress($pdo, $row['rfc'], $row['nombre_completo']);
        try {
            $ins = $pdo->prepare("INSERT INTO consultas_buro
                (folio_consulta, nombre, apellido_paterno, apellido_materno, rfc,
                 calle_numero, colonia, municipio, ciudad, estado, cp,
                 tipo_consulta, ingreso_nip_ciec, respuesta_leyenda, aceptacion_tyc,
                 fecha_consulta, hora_consulta, fecha_aprobacion_consulta, hora_aprobacion_consulta,
                 origen, freg)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PF', 'SI', 'SI', 'SI', ?, ?, ?, ?, 'cdc_register_import', ?)");
            $fdate = $row['fecha_hora'] ? substr($row['fecha_hora'], 0, 10) : null;
            $fhora = $row['fecha_hora'] ? substr($row['fecha_hora'], 11, 8) : null;
            $ins->execute([
                $row['folio'], $nombre, $apPat, $apMat, $row['rfc'],
                $addr['calle_numero'] ?: null, $addr['colonia'] ?: null, $addr['municipio'] ?: null,
                $addr['ciudad'] ?: null, $addr['estado'] ?: null, $addr['cp'] ?: null,
                $fdate, $fhora, $fdate, $fhora, $row['fecha_hora'] ?: null,
            ]);
            $imported++;
            $hasAddr = trim(($addr['calle_numero'] ?: '') . ($addr['ciudad'] ?: '')) !== '';
            $details[] = ['action'=>'import','folio'=>$row['folio'],'rfc'=>$row['rfc'],
                'msg'=>'Inserted &middot; address: ' . ($hasAddr ? '<span class="ok">found</span>' : '<span class="warn">not found (manual entry needed)</span>')];
        } catch (Throwable $e) {
            $details[] = ['action'=>'err','folio'=>$row['folio'],'rfc'=>$row['rfc'],'msg'=>$e->getMessage()];
        }
    }
    $importStats = compact('imported','skipped','unparsable','details');
    if (function_exists('adminLog')) {
        adminLog('cdc_register_import', [
            'imported'=>$imported,'skipped'=>$skipped,'unparsable'=>$unparsable,
            'admin'=>$_SESSION['admin_user_id'] ?? null,
        ]);
    }
}

if ($importStats) {
    echo '<div class="banner banner-ok">'
       . 'Imported: <strong>' . $importStats['imported'] . '</strong> &middot; '
       . 'Already existed: <strong>' . $importStats['skipped'] . '</strong> &middot; '
       . 'Could not parse: <strong>' . $importStats['unparsable'] . '</strong>'
       . '</div>';
    echo '<div class="card"><table><thead><tr><th>Action</th><th>Folio</th><th>RFC</th><th>Result</th></tr></thead><tbody>';
    foreach ($importStats['details'] as $d) {
        $cls = $d['action'] === 'import' ? 'ok' : ($d['action'] === 'skip' ? 'warn' : 'err');
        echo '<tr><td class="' . $cls . '">' . htmlspecialchars($d['action']) . '</td>'
           . '<td>' . htmlspecialchars($d['folio']) . '</td>'
           . '<td>' . htmlspecialchars($d['rfc']) . '</td>'
           . '<td>' . $d['msg'] . '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<div class="card" style="background:#fef9c3;border-color:#facc15;">'
       . '<strong>Next step (Task C):</strong> Download the CDC NIP-CIEC CSV with all addresses (it now includes the records you just imported):'
       . '<br><br>'
       . '<a class="btn success" href="/admin/php/buro/exportar.php" target="_blank">Download CDC export CSV</a>'
       . ' <a class="btn ghost" href="?">&larr; Import more</a>'
       . '</div>';
} else {
    echo '<div class="card"><h2><span class="step">A</span> Paste CDC register rows</h2>';
    echo '<form method="post">';
    echo '<input type="hidden" name="action" value="import">';
    echo '<p class="muted" style="font-size:12px;">Format: one row per line &middot; Folio &middot; Full name &middot; RFC &middot; Date [Time]. Tab or multiple spaces as separator. Lines that cannot be parsed are ignored.</p>';
    echo '<textarea name="paste" placeholder="2033073630&#9;JUAN PEREZ LOPEZ&#9;PELJ900115&#9;2026-05-23 13:13:09&#10;2035291622&#9;FERNANDA PAOLA CEBALLOS CAMPANA&#9;CECF901222XXX&#9;2026-05-25 07:56:56&#10;2035295320&#9;RODRIGO LOPEZ GARCINI&#9;LOGR820622XXX&#9;2026-05-25 08:05:52"></textarea>';
    echo '<p style="margin-top:12px;"><button class="btn" type="submit">Import rows</button></p>';
    echo '</form></div>';

    echo '<div class="card" style="background:#dbeafe;border-color:#93c5fd;font-size:13px;">';
    echo '<strong>How it works:</strong>';
    echo '<ol style="margin:8px 0 0;padding-left:20px;">';
    echo '<li><span class="step">A</span> Copy the rows from the yellow CDC register page and paste them above</li>';
    echo '<li>The tool parses each row and checks if the RFC is already in our DB</li>';
    echo '<li>For new rows, it searches <code>preaprobaciones</code> and <code>transacciones</code> for matching customer address and copies it</li>';
    echo '<li>Rows are inserted into <code>consultas_buro</code> with <code>origen = cdc_register_import</code> so you can tell them apart</li>';
    echo '<li><span class="step">C</span> Click the green button on the result page to download the CDC NIP-CIEC CSV with all addresses included</li>';
    echo '</ol></div>';
}

echo '</body></html>';
