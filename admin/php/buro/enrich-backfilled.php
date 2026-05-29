<?php
/**
 * Voltika Admin — Fill missing fields on backfilled consultas_buro rows.
 *
 * Customer brief 2026-05-29 (round 2): the CDC export CSV showed yellow-
 * highlighted (backfilled) rows with empty columns:
 *   - RFC                — empty because preaprobaciones does not store it
 *   - CALLE_NUMERO        — empty because preaprobaciones has only cp/ciudad/estado
 *   - COLONIA             — same reason
 *   - USUARIO             — backfill INSERT forgot to set usuario_api
 *
 * This tool fixes existing rows in place. It does NOT call CDC again, so it
 * is free of charge and read-only against the CDC API. It only WRITES to
 * consultas_buro to fill in the missing fields.
 *
 * Sources used to enrich each row (in priority order):
 *   1. checklist_entrega_v2 (pagare_calle, pagare_colonia, pagare_alcaldia,
 *      pagare_cp, pagare_curp) — collected at PAGARÉ signing, most accurate
 *   2. verificaciones_identidad (raw_truora_payload JSON — residence_address
 *      parsed from INE OCR)
 *   3. transacciones (direccion, colonia, ciudad, estado, cp)
 *   4. preaprobaciones (cp, ciudad, estado only)
 *
 * RFC: computed from nombre + apellido_paterno + apellido_materno + DOB
 * using the SAT algorithm (same code as configurador/php/consultar-buro.php
 * cdcComputeRFC()). 10-char + XXX placeholder homoclave.
 *
 * Two-stage flow:
 *   1. GET: dry-run preview. No writes.
 *   2. POST commit=1: apply UPDATEs.
 *
 * Auth: admin only. Safe to re-run: only fills NULL/empty fields, never
 * overwrites existing data.
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$commit = isset($_POST['commit']) && $_POST['commit'] === '1';

const VOLTIKA_CDC_FOLIO = '0000004694';

// ── RFC computation (copy of cdcComputeRFC from consultar-buro.php) ──────
function _ascii(string $s): string {
    if ($s === '') return '';
    $s = strtoupper($s);
    $map = [
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
        'á'=>'A','é'=>'E','í'=>'I','ó'=>'O','ú'=>'U','ü'=>'U','ñ'=>'N',
        'À'=>'A','È'=>'E','Ì'=>'I','Ò'=>'O','Ù'=>'U',
        'Â'=>'A','Ê'=>'E','Î'=>'I','Ô'=>'O','Û'=>'U',
    ];
    $s = strtr($s, $map);
    $s = preg_replace('/[^\x20-\x7E]/', '', $s);
    return trim((string)$s);
}
function _computeRFC(string $nombre, string $paterno, string $materno, string $fechaNac): string {
    $nombre  = _ascii($nombre);
    $paterno = _ascii($paterno);
    $materno = _ascii($materno);
    if ($paterno === '' || $nombre === '') return '';
    $l1 = substr($paterno, 0, 1);
    $l2 = 'X';
    for ($i = 1; $i < strlen($paterno); $i++) {
        $c = $paterno[$i];
        if (in_array($c, ['A','E','I','O','U'], true)) { $l2 = $c; break; }
    }
    $l3 = $materno !== '' ? substr($materno, 0, 1) : 'X';
    $nombreParts = preg_split('/\s+/', $nombre);
    $firstName = $nombreParts[0];
    if (in_array($firstName, ['JOSE','MARIA','MA','J'], true) && isset($nombreParts[1])) {
        $firstName = $nombreParts[1];
    }
    $l4 = substr($firstName, 0, 1);
    $digits = '000000';
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fechaNac, $m)) {
        $digits = substr($m[1], 2, 2) . $m[2] . $m[3];
    }
    return $l1 . $l2 . $l3 . $l4 . $digits . 'XXX';
}

// ── Truora INE address parser (same logic as pagare-prefill.php) ──────────
function _findDocValidation($p) {
    if (!is_array($p)) return null;
    if (isset($p['document_validation']) && is_array($p['document_validation'])) return $p['document_validation'];
    foreach ($p as $v) {
        if (is_array($v)) {
            $r = _findDocValidation($v);
            if ($r) return $r;
        }
    }
    return null;
}
function _parseTruoraAddress(?string $jsonPayload): array {
    $out = ['calle'=>'','colonia'=>'','municipio'=>'','estado'=>'','cp'=>''];
    if (!$jsonPayload) return $out;
    $payload = json_decode($jsonPayload, true);
    if (!is_array($payload)) return $out;
    $doc = _findDocValidation($payload);
    if (!$doc) return $out;
    $resAddr = trim((string)($doc['residence_address'] ?? ''));
    $cp      = trim((string)($doc['postal_code']       ?? ''));
    $alc     = trim((string)($doc['municipality_name'] ?? ''));
    $est     = trim((string)($doc['state_name']        ?? ''));
    // INE format: "<street> COL <colonia> <CP> <alcaldia>, <state>"
    if ($resAddr !== '' && preg_match('/^(.+?)\s+COL\s+(.+?)\s+(\d{5})\s+(.+?),\s*(.+)$/i', $resAddr, $m)) {
        $out['calle']     = trim($m[1]);
        $out['colonia']   = trim($m[2]);
        $out['cp']        = trim($m[3]);
        $out['municipio'] = trim($m[4]);
        $out['estado']    = trim($m[5]);
    } elseif ($resAddr !== '') {
        $out['calle']     = $resAddr;
        $out['cp']        = $cp;
        $out['municipio'] = $alc;
        $out['estado']    = $est;
    }
    if ($out['cp']        === '' && $cp  !== '') $out['cp']        = $cp;
    if ($out['municipio'] === '' && $alc !== '') $out['municipio'] = $alc;
    if ($out['estado']    === '' && $est !== '') $out['estado']    = $est;
    return $out;
}

// Detect rows that need enrichment: any consultas_buro row with NULL/empty
// RFC, calle_numero, colonia, or usuario_api. Typically these will be the
// backfilled rows (origen='backfill_preaprobaciones') but we include all so
// we never miss a stray pre-fix row.
$candidates = $pdo->query("SELECT id, nombre, apellido_paterno, apellido_materno,
    fecha_nacimiento, rfc, calle_numero, colonia, municipio, ciudad, estado,
    cp, usuario_api, origen, freg
    FROM consultas_buro
    WHERE (rfc IS NULL OR rfc = ''
        OR calle_numero IS NULL OR calle_numero = ''
        OR colonia IS NULL OR colonia = ''
        OR usuario_api IS NULL OR usuario_api = '')
    ORDER BY freg DESC")->fetchAll(PDO::FETCH_ASSOC);

// For each candidate, find enrichment data
$preapStmt = $pdo->prepare("SELECT id, telefono, email FROM preaprobaciones
    WHERE LOWER(nombre) = LOWER(?) AND LOWER(apellido_paterno) = LOWER(?)
      AND (fecha_nacimiento = ? OR ? = '')
    ORDER BY id DESC LIMIT 1");

$plan = [];
foreach ($candidates as $r) {
    $proposed = [
        'rfc'          => null,
        'calle_numero' => null,
        'colonia'      => null,
        'municipio'    => null,
        'ciudad'       => null,
        'estado'       => null,
        'cp'           => null,
        'usuario_api'  => null,
    ];
    $sources = [];

    // 1. RFC — compute from name + DOB if missing
    if (empty($r['rfc'])) {
        $rfc = _computeRFC(
            (string)$r['nombre'],
            (string)$r['apellido_paterno'],
            (string)($r['apellido_materno'] ?? ''),
            (string)($r['fecha_nacimiento'] ?? '')
        );
        if ($rfc !== '') {
            $proposed['rfc'] = $rfc;
            $sources['rfc'] = 'computed';
        }
    }

    // 2. usuario_api — set to Voltika's CDC folio
    if (empty($r['usuario_api'])) {
        $proposed['usuario_api'] = VOLTIKA_CDC_FOLIO;
        $sources['usuario_api'] = 'CDC_FOLIO';
    }

    // 3. Find linked preaprobacion to get telefono/email for joins
    $linkedTel = null; $linkedEmail = null; $linkedPreapId = null;
    try {
        $dob = (string)($r['fecha_nacimiento'] ?? '');
        $preapStmt->execute([
            (string)$r['nombre'], (string)$r['apellido_paterno'], $dob, $dob,
        ]);
        $p = $preapStmt->fetch(PDO::FETCH_ASSOC);
        if ($p) {
            $linkedPreapId = (int)$p['id'];
            $linkedTel = (string)($p['telefono'] ?? '');
            $linkedEmail = (string)($p['email'] ?? '');
        }
    } catch (Throwable $e) {}

    // 4. checklist_entrega_v2 — pagare_calle/colonia/alcaldia/cp (most accurate)
    if (empty($r['calle_numero']) || empty($r['colonia'])) {
        try {
            // Path: preaprobacion → transacciones (by telefono/email) → inventario_motos (by transaccion_id) → checklist_entrega_v2
            $sql = "SELECT cl.pagare_calle, cl.pagare_num_exterior, cl.pagare_colonia,
                           cl.pagare_alcaldia, cl.pagare_estado_dir, cl.pagare_cp
                FROM transacciones t
                JOIN inventario_motos im ON im.transaccion_id = t.id
                JOIN checklist_entrega_v2 cl ON cl.moto_id = im.id
                WHERE (t.telefono = ? OR t.email = ?)
                  AND (cl.pagare_calle IS NOT NULL AND cl.pagare_calle != '')
                ORDER BY cl.id DESC LIMIT 1";
            $cl = $pdo->prepare($sql);
            $cl->execute([$linkedTel ?: '', $linkedEmail ?: '']);
            $clRow = $cl->fetch(PDO::FETCH_ASSOC);
            if ($clRow) {
                $calleCombo = trim(($clRow['pagare_calle'] ?? '') . ' ' . ($clRow['pagare_num_exterior'] ?? ''));
                if (empty($r['calle_numero']) && $calleCombo !== '') {
                    $proposed['calle_numero'] = $calleCombo;
                    $sources['calle_numero'] = 'checklist_pagare';
                }
                if (empty($r['colonia']) && !empty($clRow['pagare_colonia'])) {
                    $proposed['colonia'] = (string)$clRow['pagare_colonia'];
                    $sources['colonia'] = 'checklist_pagare';
                }
                if (empty($r['municipio']) && !empty($clRow['pagare_alcaldia'])) {
                    $proposed['municipio'] = (string)$clRow['pagare_alcaldia'];
                    $sources['municipio'] = 'checklist_pagare';
                }
                if (empty($r['cp']) && !empty($clRow['pagare_cp'])) {
                    $proposed['cp'] = (string)$clRow['pagare_cp'];
                    $sources['cp'] = 'checklist_pagare';
                }
            }
        } catch (Throwable $e) {}
    }

    // 5. verificaciones_identidad — parse Truora INE OCR
    if (empty($proposed['calle_numero']) && empty($r['calle_numero'])) {
        try {
            $vi = $pdo->prepare("SELECT raw_truora_payload FROM verificaciones_identidad
                WHERE telefono = ? OR email = ?
                  AND raw_truora_payload IS NOT NULL AND raw_truora_payload != ''
                ORDER BY id DESC LIMIT 1");
            $vi->execute([$linkedTel ?: '', $linkedEmail ?: '']);
            $viRow = $vi->fetch(PDO::FETCH_ASSOC);
            if ($viRow) {
                $addr = _parseTruoraAddress($viRow['raw_truora_payload']);
                if (empty($r['calle_numero']) && $addr['calle'] !== '') {
                    $proposed['calle_numero'] = $addr['calle'];
                    $sources['calle_numero'] = 'truora_ine';
                }
                if (empty($r['colonia']) && $addr['colonia'] !== '') {
                    $proposed['colonia'] = $addr['colonia'];
                    $sources['colonia'] = 'truora_ine';
                }
                if (empty($r['municipio']) && $addr['municipio'] !== '') {
                    $proposed['municipio'] = $addr['municipio'];
                    $sources['municipio'] = 'truora_ine';
                }
                if (empty($r['cp']) && $addr['cp'] !== '') {
                    $proposed['cp'] = $addr['cp'];
                    $sources['cp'] = 'truora_ine';
                }
            }
        } catch (Throwable $e) {}
    }

    // 6. transacciones — fallback for direccion/colonia
    if (empty($proposed['calle_numero']) && empty($r['calle_numero'])) {
        try {
            $tx = $pdo->prepare("SELECT direccion, colonia, ciudad, estado, cp FROM transacciones
                WHERE (telefono = ? OR email = ?)
                  AND direccion IS NOT NULL AND direccion != ''
                ORDER BY id DESC LIMIT 1");
            $tx->execute([$linkedTel ?: '', $linkedEmail ?: '']);
            $txRow = $tx->fetch(PDO::FETCH_ASSOC);
            if ($txRow) {
                if (empty($r['calle_numero']) && !empty($txRow['direccion'])) {
                    $proposed['calle_numero'] = (string)$txRow['direccion'];
                    $sources['calle_numero'] = 'transacciones';
                }
                if (empty($r['colonia']) && !empty($txRow['colonia'])) {
                    $proposed['colonia'] = (string)$txRow['colonia'];
                    $sources['colonia'] = 'transacciones';
                }
            }
        } catch (Throwable $e) {}
    }

    // Build the actual UPDATE field list (only non-null proposed fields)
    $updates = [];
    foreach ($proposed as $field => $val) {
        if ($val !== null && $val !== '') $updates[$field] = $val;
    }
    if (empty($updates)) continue;

    $plan[] = [
        'id'      => (int)$r['id'],
        'name'    => trim($r['nombre'] . ' ' . $r['apellido_paterno'] . ' ' . ($r['apellido_materno'] ?? '')),
        'origen'  => (string)($r['origen'] ?? ''),
        'updates' => $updates,
        'sources' => $sources,
        'preap'   => $linkedPreapId,
    ];
}

// ── COMMIT phase ───────────────────────────────────────────────────────────
$updateStats = null;
if ($commit && !empty($plan)) {
    $updated = 0; $errors = 0;
    foreach ($plan as $row) {
        $setParts = [];
        $params = [];
        foreach ($row['updates'] as $field => $val) {
            $setParts[] = "`$field` = ?";
            $params[] = $val;
        }
        $params[] = $row['id'];
        try {
            $up = $pdo->prepare("UPDATE consultas_buro SET " . implode(', ', $setParts) . " WHERE id = ?");
            $up->execute($params);
            $updated++;
        } catch (Throwable $e) {
            $errors++;
            error_log('enrich-backfilled UPDATE id ' . $row['id'] . ': ' . $e->getMessage());
        }
    }
    $updateStats = compact('updated','errors');
    if (function_exists('adminLog')) {
        adminLog('enrich_backfilled_consultas', [
            'updated' => $updated, 'errors' => $errors, 'plan_size' => count($plan),
        ]);
    }
}

// ── UI ──
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Enrich backfilled consultas_buro</title>';
echo '<style>
body{font-family:system-ui,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1280px;margin:0 auto;line-height:1.55;}
h1{font-size:20px;margin:0 0 6px;}
h2{font-size:14px;color:#475569;margin:18px 0 8px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;margin-bottom:12px;}
table{width:100%;border-collapse:collapse;font-size:11.5px;}
th{background:#f1f5f9;padding:5px 8px;text-align:left;font-size:10.5px;font-weight:600;}
td{padding:5px 8px;border-top:1px solid #f1f5f9;vertical-align:top;}
.banner{padding:11px 14px;border-radius:8px;font-size:13.5px;margin-bottom:12px;font-weight:600;}
.banner-info{background:#dbeafe;border:1px solid #93c5fd;color:#1e3a8a;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.btn{padding:8px 16px;background:#039fe1;color:#fff;border:0;border-radius:5px;cursor:pointer;font-weight:600;font-size:13px;text-decoration:none;display:inline-block;}
.btn.success{background:#16a34a;}
.btn.ghost{background:#fff;color:#0c2340;border:1px solid #cbd5e1;}
.tag{display:inline-block;padding:1px 6px;border-radius:3px;font-size:10px;font-weight:600;}
.tag-computed{background:#dbeafe;color:#1e3a8a;}
.tag-checklist{background:#dcfce7;color:#166534;}
.tag-truora{background:#fef9c3;color:#854d0e;}
.tag-tx{background:#fce7f3;color:#9d174d;}
.tag-cdc{background:#e0e7ff;color:#3730a3;}
.muted{color:#94a3b8;}
code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:11px;}
</style></head><body>';
echo '<h1>Enrich backfilled <code>consultas_buro</code> rows</h1>';
echo '<p class="muted" style="font-size:12.5px;">Fill missing RFC, address, and usuario_api fields on existing rows. Does NOT call CDC. Only writes fields that are currently NULL/empty.</p>';

if ($updateStats) {
    echo '<div class="banner banner-ok">'
       . 'Updated: <strong>' . $updateStats['updated'] . '</strong> &middot; '
       . 'Errors: <strong>' . $updateStats['errors'] . '</strong>'
       . '</div>';
    echo '<div class="card" style="background:#fef9c3;border-color:#facc15;">'
       . '<strong>Next step:</strong> Re-download the CDC export to verify the previously empty columns are now filled.'
       . '<br><br>'
       . '<a class="btn success" href="/admin/php/buro/exportar.php" target="_blank">Download CDC export CSV</a> '
       . '<a class="btn ghost" href="?">&larr; Re-scan</a>'
       . '</div>';
}

echo '<div class="banner banner-info">'
   . 'Rows needing enrichment: <strong>' . count($candidates) . '</strong> &middot; '
   . 'Rows with at least one proposed fix: <strong>' . count($plan) . '</strong>'
   . '</div>';

if (empty($plan)) {
    echo '<div class="card"><p>No rows need enrichment. All consultas_buro rows already have RFC, address, and usuario_api filled.</p></div>';
} else {
    echo '<div class="card">';
    echo '<h2>Proposed updates (' . count($plan) . ' rows)</h2>';
    echo '<table><thead><tr><th>id</th><th>Origen</th><th>Nombre</th><th>RFC</th><th>Calle</th><th>Colonia</th><th>Municipio</th><th>CP</th><th>Usuario</th></tr></thead><tbody>';
    foreach ($plan as $p) {
        echo '<tr>';
        echo '<td>' . $p['id'] . '</td>';
        echo '<td><code>' . htmlspecialchars($p['origen']) . '</code></td>';
        echo '<td>' . htmlspecialchars($p['name']) . '</td>';
        foreach (['rfc','calle_numero','colonia','municipio','cp','usuario_api'] as $f) {
            $v = $p['updates'][$f] ?? '';
            $src = $p['sources'][$f] ?? '';
            $tagClass = ['computed'=>'tag-computed','CDC_FOLIO'=>'tag-computed','checklist_pagare'=>'tag-checklist','truora_ine'=>'tag-truora','transacciones'=>'tag-tx','consultas_buro'=>'tag-cdc'][$src] ?? '';
            echo '<td>' . htmlspecialchars((string)$v);
            if ($src) echo ' <span class="tag ' . $tagClass . '">' . htmlspecialchars($src) . '</span>';
            echo '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';

    if (!$commit) {
        echo '<form method="post" style="margin-top:14px;">';
        echo '<input type="hidden" name="commit" value="1">';
        echo '<button class="btn" type="submit" onclick="return confirm(\'Apply ' . count($plan) . ' UPDATEs to consultas_buro?\');">Commit updates (' . count($plan) . ' rows)</button> ';
        echo '<span class="muted" style="font-size:12px;margin-left:8px;">This is the dry-run preview. Click commit to write to the DB. Each row only fills NULL/empty fields, never overwrites.</span>';
        echo '</form>';
    }
    echo '</div>';
}

echo '<div class="card" style="background:#dbeafe;border-color:#93c5fd;font-size:12.5px;">';
echo '<strong>Legend (source of each value):</strong> ';
echo '<span class="tag tag-computed">computed</span> RFC algorithm or CDC_FOLIO constant &middot; ';
echo '<span class="tag tag-checklist">checklist_pagare</span> from PAGARÉ signing &middot; ';
echo '<span class="tag tag-truora">truora_ine</span> parsed from INE OCR &middot; ';
echo '<span class="tag tag-tx">transacciones</span> from purchase &middot; ';
echo '<span class="tag tag-cdc">consultas_buro</span> from another CDC row';
echo '</div>';

echo '</body></html>';
