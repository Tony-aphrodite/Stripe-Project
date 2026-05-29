<?php
/**
 * Voltika Admin — Backfill consultas_buro from preaprobaciones.
 *
 * Customer brief 2026-05-29: CDC's register page (yellow page) shows queries
 * for customers like JUAN PEREZ LOPEZ, FERNANDA CEBALLOS, RODRIGO LOPEZ etc.
 * that came through our configurador, but our consultas_buro table is empty
 * for them. Root cause: consultar-buro.php exited early without INSERT on
 * three failure paths (404 person-not-found, 5xx unreachable, malformed JSON).
 *
 * That bug is now fixed in consultar-buro.php for future queries. This tool
 * recovers the historical gap by walking through every preaprobaciones row
 * (which is where the configurador stores the customer's submitted data)
 * and creating a matching consultas_buro row when one doesn't exist.
 *
 * The match key is: nombre + apellido_paterno + fecha_nacimiento. Any row
 * already present in consultas_buro (under any RFC) is skipped.
 *
 * Two-stage flow:
 *   1. GET: dry-run. Show what would be inserted, no DB writes.
 *   2. POST commit=1: apply the inserts.
 *
 * Address enrichment: preaprobaciones only has cp/ciudad/estado. We try to
 * fill direccion/colonia/municipio from transacciones (the configurador
 * collects them at checkout) keyed on the same customer.
 *
 * Auth: admin only. Read-only by default.
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$commit  = isset($_POST['commit']) && $_POST['commit'] === '1';
$daysBack = max(1, min(365, (int)($_GET['days'] ?? $_POST['days'] ?? 90)));

// Voltika's folio_otorgante assigned by CDC. Same constant used by
// configurador/php/consultar-buro.php (CDC_FOLIO).
const VOLTIKA_CDC_FOLIO_BFILL = '0000004694';

// RFC computation — duplicated from consultar-buro.php::cdcComputeRFC()
// so backfilled rows have a valid 13-char RFC (with XXX placeholder
// homoclave) instead of NULL.
function _bfill_ascii(string $s): string {
    if ($s === '') return '';
    $s = strtoupper($s);
    $s = strtr($s, [
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
        'á'=>'A','é'=>'E','í'=>'I','ó'=>'O','ú'=>'U','ü'=>'U','ñ'=>'N',
    ]);
    return trim((string)preg_replace('/[^\x20-\x7E]/', '', $s));
}
function _bfill_computeRFC(string $nombre, string $paterno, string $materno, string $fechaNac): string {
    $nombre = _bfill_ascii($nombre);
    $paterno = _bfill_ascii($paterno);
    $materno = _bfill_ascii($materno);
    if ($paterno === '' || $nombre === '') return '';
    $l1 = substr($paterno, 0, 1);
    $l2 = 'X';
    for ($i = 1; $i < strlen($paterno); $i++) {
        $c = $paterno[$i];
        if (in_array($c, ['A','E','I','O','U'], true)) { $l2 = $c; break; }
    }
    $l3 = $materno !== '' ? substr($materno, 0, 1) : 'X';
    $nameParts = preg_split('/\s+/', $nombre);
    $first = $nameParts[0];
    if (in_array($first, ['JOSE','MARIA','MA','J'], true) && isset($nameParts[1])) {
        $first = $nameParts[1];
    }
    $l4 = substr($first, 0, 1);
    $digits = '000000';
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fechaNac, $m)) {
        $digits = substr($m[1], 2, 2) . $m[2] . $m[3];
    }
    return $l1 . $l2 . $l3 . $l4 . $digits . 'XXX';
}

// Ensure target table exists with the columns we'll write into
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS consultas_buro (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(200), apellido_paterno VARCHAR(100), apellido_materno VARCHAR(100),
        fecha_nacimiento VARCHAR(20), cp VARCHAR(10),
        score INT, pago_mensual DECIMAL(12,2), dpd90_flag TINYINT(1), dpd_max INT,
        num_cuentas INT, folio_consulta VARCHAR(100),
        freg DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    foreach ([
        'rfc'              => 'VARCHAR(20) NULL',
        'curp'             => 'VARCHAR(20) NULL',
        'calle_numero'     => 'VARCHAR(200) NULL',
        'colonia'          => 'VARCHAR(150) NULL',
        'municipio'        => 'VARCHAR(150) NULL',
        'ciudad'           => 'VARCHAR(100) NULL',
        'estado'           => 'VARCHAR(50) NULL',
        'tipo_consulta'    => "VARCHAR(5) NOT NULL DEFAULT 'PF'",
        'fecha_aprobacion_consulta' => 'DATE NULL',
        'hora_aprobacion_consulta'  => 'TIME NULL',
        'fecha_consulta'   => 'DATE NULL',
        'hora_consulta'    => 'TIME NULL',
        'usuario_api'      => 'VARCHAR(100) NULL',
        'ingreso_nip_ciec' => "VARCHAR(5) DEFAULT 'SI'",
        'respuesta_leyenda'=> "VARCHAR(5) DEFAULT 'SI'",
        'aceptacion_tyc'   => "VARCHAR(5) DEFAULT 'SI'",
        'status'           => 'VARCHAR(40) NULL',
        'http_code'        => 'INT NULL',
        'origen'           => "VARCHAR(40) NULL DEFAULT 'configurador'",
    ] as $col => $def) {
        try { $pdo->exec("ALTER TABLE consultas_buro ADD COLUMN `$col` $def"); }
        catch (Throwable $e) {}
    }
} catch (Throwable $e) {
    die('Init error: ' . htmlspecialchars($e->getMessage()));
}

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Backfill consultas_buro</title>';
echo '<style>
body{font-family:system-ui,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1180px;margin:0 auto;line-height:1.55;}
h1{font-size:20px;margin:0 0 6px;}
h2{font-size:14px;color:#475569;margin:18px 0 8px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;margin-bottom:12px;}
table{width:100%;border-collapse:collapse;font-size:12px;}
th{background:#f1f5f9;padding:6px 9px;text-align:left;font-size:10.5px;}
td{padding:6px 9px;border-top:1px solid #f1f5f9;vertical-align:top;}
.banner{padding:11px 14px;border-radius:8px;font-size:13.5px;margin-bottom:12px;font-weight:600;}
.banner-info{background:#dbeafe;border:1px solid #93c5fd;color:#1e3a8a;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.banner-warn{background:#fef3c7;border:1px solid #fcd34d;color:#92400e;}
.btn{padding:8px 16px;background:#039fe1;color:#fff;border:0;border-radius:5px;cursor:pointer;font-weight:600;font-size:13px;text-decoration:none;display:inline-block;}
.btn.success{background:#16a34a;}
.btn.ghost{background:#fff;color:#0c2340;border:1px solid #cbd5e1;}
.ok{color:#15803d;font-weight:700;}
.warn{color:#a16207;}
.err{color:#b91c1c;font-weight:700;}
.muted{color:#94a3b8;}
code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:11.5px;}
</style></head><body>';
echo '<h1>Backfill <code>consultas_buro</code> from <code>preaprobaciones</code></h1>';
echo '<p class="muted" style="font-size:12.5px;">For every preaprobacion in the last ' . $daysBack . ' day(s), create a matching <code>consultas_buro</code> row if one does not already exist. Address is enriched from <code>transacciones</code> when available.</p>';

// Build candidate list
$cutoff = date('Y-m-d H:i:s', strtotime('-' . $daysBack . ' days'));
// Exclude test customers: same filter the production dashboards use.
// Phone 5500000000 and the Voltika Diag synthetic profile are the canonical
// test identities; rejecting them keeps the CDC NIP-CIEC report clean.
$candidates = $pdo->prepare("SELECT p.id, p.nombre, p.apellido_paterno, p.apellido_materno,
        p.fecha_nacimiento, p.cp, p.ciudad, p.estado, p.email, p.telefono, p.modelo,
        p.status AS pa_status, p.score AS pa_score, p.freg
    FROM preaprobaciones p
    WHERE p.freg >= ?
      AND p.nombre IS NOT NULL AND p.nombre != ''
      AND p.apellido_paterno IS NOT NULL AND p.apellido_paterno != ''
      AND (p.telefono IS NULL OR p.telefono != '5500000000')
      AND p.nombre NOT LIKE '%TEST%'
      AND p.nombre NOT LIKE '%Voltika Diag%'
      AND p.apellido_paterno NOT LIKE '%Voltika Diag%'
      AND p.apellido_paterno NOT LIKE '%Diag%'
    ORDER BY p.freg DESC");
$candidates->execute([$cutoff]);
$rows = $candidates->fetchAll(PDO::FETCH_ASSOC);

// For each candidate, check if a consultas_buro row already exists
$matchStmt = $pdo->prepare("SELECT id FROM consultas_buro
    WHERE LOWER(nombre) = LOWER(?) AND LOWER(apellido_paterno) = LOWER(?)
      AND (fecha_nacimiento = ? OR ? = '')
    LIMIT 1");

// For each candidate, try to find address from transacciones
$txStmt = $pdo->prepare("SELECT direccion, colonia, ciudad, estado, cp FROM transacciones
    WHERE (telefono = ? OR email = ? OR nombre = ?)
      AND (direccion IS NOT NULL AND direccion != '')
    ORDER BY id DESC LIMIT 1");

$toInsert = [];
$alreadyHave = 0;
foreach ($rows as $r) {
    $dob = (string)($r['fecha_nacimiento'] ?? '');
    $matchStmt->execute([(string)$r['nombre'], (string)$r['apellido_paterno'], $dob, $dob]);
    if ($matchStmt->fetchColumn()) { $alreadyHave++; continue; }

    // Enrich address from transacciones
    $addr = ['direccion'=>'','colonia'=>'','municipio'=>'','ciudad'=>'','estado'=>'','cp'=>''];
    $fullName = trim($r['nombre'] . ' ' . $r['apellido_paterno'] . ' ' . ($r['apellido_materno'] ?? ''));
    try {
        $txStmt->execute([(string)$r['telefono'], (string)$r['email'], $fullName]);
        $tx = $txStmt->fetch(PDO::FETCH_ASSOC);
        if ($tx) {
            if (!empty($tx['direccion'])) $addr['direccion'] = (string)$tx['direccion'];
            if (!empty($tx['colonia']))   $addr['colonia']   = (string)$tx['colonia'];
            if (!empty($tx['ciudad']))    $addr['ciudad']    = (string)$tx['ciudad'];
            if (!empty($tx['estado']))    $addr['estado']    = (string)$tx['estado'];
            if (!empty($tx['cp']))        $addr['cp']        = (string)$tx['cp'];
        }
    } catch (Throwable $e) {}
    // Fall back to preaprobaciones fields
    if ($addr['ciudad'] === '' && !empty($r['ciudad'])) $addr['ciudad'] = (string)$r['ciudad'];
    if ($addr['estado'] === '' && !empty($r['estado'])) $addr['estado'] = (string)$r['estado'];
    if ($addr['cp']     === '' && !empty($r['cp']))     $addr['cp']     = (string)$r['cp'];

    // Compute RFC from name + DOB so the row has a valid identifier in the
    // CDC export (otherwise the RFC column is empty for backfilled rows).
    $computedRfc = _bfill_computeRFC(
        (string)$r['nombre'],
        (string)$r['apellido_paterno'],
        (string)($r['apellido_materno'] ?? ''),
        $dob
    );

    $toInsert[] = [
        'preap_id' => $r['id'],
        'nombre'   => $r['nombre'],
        'apellido_paterno' => $r['apellido_paterno'],
        'apellido_materno' => $r['apellido_materno'] ?? '',
        'fecha_nacimiento' => $dob,
        'telefono' => $r['telefono'],
        'modelo'   => $r['modelo'],
        'freg'     => $r['freg'],
        'addr'     => $addr,
        'rfc'      => $computedRfc,
        'has_addr' => trim($addr['direccion'] . $addr['ciudad']) !== '',
    ];
}

echo '<div class="banner banner-info">'
   . 'Preaprobaciones in last ' . $daysBack . ' days: <strong>' . count($rows) . '</strong> &middot; '
   . 'Already have consultas_buro row: <strong>' . $alreadyHave . '</strong> &middot; '
   . 'To insert: <strong>' . count($toInsert) . '</strong>'
   . '</div>';

// Commit phase
if ($commit) {
    $inserted = 0; $errors = 0;
    // INSERT now includes rfc and usuario_api so the CDC export CSV has
    // complete data for backfilled rows. Both fields were missing before
    // (customer brief 2026-05-29 round 2: yellow rows in CDC export had
    // empty RFC + USUARIO columns).
    $ins = $pdo->prepare("INSERT INTO consultas_buro
        (nombre, apellido_paterno, apellido_materno, fecha_nacimiento, rfc,
         cp, ciudad, estado, calle_numero, colonia, usuario_api,
         tipo_consulta, ingreso_nip_ciec, respuesta_leyenda, aceptacion_tyc,
         fecha_consulta, hora_consulta, fecha_aprobacion_consulta, hora_aprobacion_consulta,
         origen, status, freg)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PF', 'SI', 'SI', 'SI', ?, ?, ?, ?,
                'backfill_preaprobaciones', 'backfilled', ?)");
    foreach ($toInsert as $row) {
        try {
            $f = (string)$row['freg'];
            $fd = substr($f, 0, 10);
            $ft = substr($f, 11, 8);
            $ins->execute([
                $row['nombre'], $row['apellido_paterno'], $row['apellido_materno'],
                $row['fecha_nacimiento'] ?: null,
                $row['rfc'] ?: null,
                $row['addr']['cp'] ?: null, $row['addr']['ciudad'] ?: null, $row['addr']['estado'] ?: null,
                $row['addr']['direccion'] ?: null, $row['addr']['colonia'] ?: null,
                VOLTIKA_CDC_FOLIO_BFILL,
                $fd, $ft, $fd, $ft, $f,
            ]);
            $inserted++;
        } catch (Throwable $e) {
            $errors++;
            error_log('backfill consultas_buro: ' . $e->getMessage());
        }
    }
    if (function_exists('adminLog')) {
        adminLog('backfill_consultas_buro', ['inserted'=>$inserted,'errors'=>$errors,'days'=>$daysBack]);
    }
    echo '<div class="banner banner-ok">'
       . 'Inserted: <strong>' . $inserted . '</strong> &middot; '
       . 'Errors: <strong>' . $errors . '</strong>'
       . '</div>';
    echo '<div class="card" style="background:#fef9c3;border-color:#facc15;">'
       . '<strong>Next step:</strong> Open the Buro CDC dashboard or download the CDC export to confirm the rows are there.'
       . '<br><br>'
       . '<a class="btn success" href="/admin/php/buro/exportar.php" target="_blank">Download CDC export CSV</a> '
       . '<a class="btn ghost" href="?days=' . $daysBack . '">&larr; Re-scan</a>'
       . '</div>';
}

// Preview table
echo '<div class="card">';
echo '<form method="get" style="margin-bottom:10px;">';
echo 'Window: <input type="number" name="days" value="' . $daysBack . '" min="1" max="365" style="width:80px;padding:5px;border:1px solid #cbd5e1;border-radius:4px;"> days &nbsp; ';
echo '<button class="btn ghost" type="submit">Re-scan</button>';
echo '</form>';

if (empty($toInsert)) {
    echo '<p class="ok">All preaprobaciones in the last ' . $daysBack . ' days already have a matching consultas_buro row. Nothing to do.</p>';
} else {
    echo '<h2>Rows that will be inserted (' . count($toInsert) . ')</h2>';
    echo '<table><thead><tr>'
       . '<th>preap_id</th><th>Nombre</th><th>DOB</th><th>Tel/Modelo</th>'
       . '<th>CP/Ciudad/Estado</th><th>Direccion/Colonia</th><th>Address?</th><th>Freg</th>'
       . '</tr></thead><tbody>';
    foreach ($toInsert as $r) {
        echo '<tr>';
        echo '<td>' . (int)$r['preap_id'] . '</td>';
        echo '<td>' . htmlspecialchars(trim($r['nombre'] . ' ' . $r['apellido_paterno'] . ' ' . $r['apellido_materno'])) . '</td>';
        echo '<td>' . htmlspecialchars($r['fecha_nacimiento']) . '</td>';
        echo '<td>' . htmlspecialchars((string)$r['telefono']) . '<br><small class="muted">' . htmlspecialchars((string)$r['modelo']) . '</small></td>';
        echo '<td>' . htmlspecialchars(trim(($r['addr']['cp'] ?? '') . ' ' . ($r['addr']['ciudad'] ?? '') . ' ' . ($r['addr']['estado'] ?? ''))) . '</td>';
        echo '<td style="max-width:260px;font-size:11px;">' . htmlspecialchars(trim(($r['addr']['direccion'] ?? '') . ' / ' . ($r['addr']['colonia'] ?? ''))) . '</td>';
        echo '<td>' . ($r['has_addr'] ? '<span class="ok">yes</span>' : '<span class="warn">partial</span>') . '</td>';
        echo '<td>' . htmlspecialchars((string)$r['freg']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    if (!$commit) {
        echo '<form method="post" style="margin-top:14px;">';
        echo '<input type="hidden" name="days" value="' . $daysBack . '">';
        echo '<input type="hidden" name="commit" value="1">';
        echo '<button class="btn" type="submit" onclick="return confirm(\'Insert ' . count($toInsert) . ' rows into consultas_buro?\');">Commit insert (' . count($toInsert) . ' rows)</button> ';
        echo '<span class="muted" style="font-size:12px;margin-left:8px;">This is the dry-run preview. Click commit to write to the DB.</span>';
        echo '</form>';
    }
}
echo '</div>';

echo '</body></html>';
