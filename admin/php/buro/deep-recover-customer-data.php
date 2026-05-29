<?php
/**
 * Voltika Admin — Deep recovery of full customer address + contact info
 * for backfilled consultas_buro rows.
 *
 * Customer brief 2026-05-30 URGENT: "At the very least we should have the
 * customer's full address and contact information." Backfilled rows show
 * only ciudad/estado/CP (from preaprobaciones). The full street address
 * (calle + colonia) lives elsewhere — primarily in cdc_query_log.body_sent
 * which captures what the customer originally typed into the configurador
 * before CDC was called.
 *
 * Sources scanned (in priority order):
 *   1. cdc_query_log.body_sent — original address sent to CDC (best for
 *      backfilled rows because they all went through consultar-buro.php)
 *   2. checklist_entrega_v2.pagare_calle/colonia/alcaldia/cp
 *   3. verificaciones_identidad.raw_truora_payload — INE OCR address
 *   4. transacciones.direccion/colonia/ciudad/estado/cp
 *
 * Also adds telefono + email columns to consultas_buro (idempotent) and
 * populates them from preaprobaciones so the admin dashboard can show the
 * customer's contact info in one place.
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

// Add telefono + email columns to consultas_buro if missing
try { $pdo->exec("ALTER TABLE consultas_buro ADD COLUMN telefono VARCHAR(30) NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE consultas_buro ADD COLUMN email VARCHAR(200) NULL"); } catch (Throwable $e) {}

// Helper: extract address block from cdc body_sent JSON
function extractCdcBodyAddress(string $bodyJson): array {
    $out = ['calle'=>'','colonia'=>'','municipio'=>'','ciudad'=>'','estado'=>'','cp'=>''];
    if ($bodyJson === '') return $out;
    $data = json_decode($bodyJson, true);
    $dom = is_array($data) && isset($data['domicilio']) && is_array($data['domicilio'])
        ? $data['domicilio'] : null;
    if ($dom) {
        foreach (['direccion','calle','calleNumero'] as $k) {
            if (!empty($dom[$k]) && stripos((string)$dom[$k], 'NO DISPONIBLE') === false) {
                $out['calle'] = (string)$dom[$k];
                break;
            }
        }
        foreach (['coloniaPoblacion','colonia'] as $k) {
            if (!empty($dom[$k]) && stripos((string)$dom[$k], 'CENTRO') === false) {
                $out['colonia'] = (string)$dom[$k];
                break;
            }
            if (!empty($dom[$k])) $out['colonia'] = (string)$dom[$k];
        }
        if (!empty($dom['delegacionMunicipio'])) $out['municipio'] = (string)$dom['delegacionMunicipio'];
        if (!empty($dom['ciudad']))    $out['ciudad'] = (string)$dom['ciudad'];
        if (!empty($dom['estado']))    $out['estado'] = (string)$dom['estado'];
        if (!empty($dom['CP']))        $out['cp']     = (string)$dom['CP'];
    } else {
        // Regex fallback for truncated JSON
        if (preg_match('/"direccion"\s*:\s*"([^"]+)"/', $bodyJson, $m)) {
            if (stripos($m[1], 'NO DISPONIBLE') === false) $out['calle'] = $m[1];
        }
        if (preg_match('/"coloniaPoblacion"\s*:\s*"([^"]+)"/', $bodyJson, $m)) $out['colonia'] = $m[1];
        if (preg_match('/"delegacionMunicipio"\s*:\s*"([^"]+)"/', $bodyJson, $m)) $out['municipio'] = $m[1];
        if (preg_match('/"CP"\s*:\s*"([^"]+)"/', $bodyJson, $m)) $out['cp'] = $m[1];
    }
    return $out;
}

// Helper: parse Truora INE residence_address
function parseTruoraAddrV2(?string $jsonPayload): array {
    $out = ['calle'=>'','colonia'=>'','municipio'=>'','estado'=>'','cp'=>''];
    if (!$jsonPayload) return $out;
    $payload = json_decode($jsonPayload, true);
    if (!is_array($payload)) return $out;
    $findDoc = function($p) use (&$findDoc) {
        if (!is_array($p)) return null;
        if (isset($p['document_validation']) && is_array($p['document_validation'])) return $p['document_validation'];
        foreach ($p as $v) {
            if (is_array($v)) { $r = $findDoc($v); if ($r) return $r; }
        }
        return null;
    };
    $doc = $findDoc($payload);
    if (!$doc) return $out;
    $resAddr = trim((string)($doc['residence_address'] ?? ''));
    if ($resAddr !== '' && preg_match('/^(.+?)\s+COL\s+(.+?)\s+(\d{5})\s+(.+?),\s*(.+)$/i', $resAddr, $m)) {
        $out['calle'] = trim($m[1]);
        $out['colonia'] = trim($m[2]);
        $out['cp'] = trim($m[3]);
        $out['municipio'] = trim($m[4]);
        $out['estado'] = trim($m[5]);
    } elseif ($resAddr !== '') {
        $out['calle'] = $resAddr;
    }
    if ($out['cp'] === '' && !empty($doc['postal_code'])) $out['cp'] = (string)$doc['postal_code'];
    if ($out['municipio'] === '' && !empty($doc['municipality_name'])) $out['municipio'] = (string)$doc['municipality_name'];
    if ($out['estado'] === '' && !empty($doc['state_name'])) $out['estado'] = (string)$doc['state_name'];
    return $out;
}

// Find candidates — backfilled rows missing address or contact info
$candidates = $pdo->query("SELECT id, nombre, apellido_paterno, apellido_materno,
    fecha_nacimiento, rfc, telefono, email,
    calle_numero, colonia, municipio, ciudad, estado, cp, origen, freg
    FROM consultas_buro
    WHERE (calle_numero IS NULL OR calle_numero = ''
        OR colonia IS NULL OR colonia = ''
        OR telefono IS NULL OR telefono = ''
        OR email IS NULL OR email = '')
    ORDER BY freg DESC")->fetchAll(PDO::FETCH_ASSOC);

// Build proposed updates per row
$plan = [];
foreach ($candidates as $r) {
    $proposed = ['calle_numero'=>null,'colonia'=>null,'municipio'=>null,
                 'cp'=>null,'telefono'=>null,'email'=>null];
    $sources = [];

    // ── Source 0: preaprobaciones — best for telefono + email
    $preap = null;
    try {
        $dob = (string)($r['fecha_nacimiento'] ?? '');
        $pq = $pdo->prepare("SELECT * FROM preaprobaciones
            WHERE LOWER(nombre) = LOWER(?) AND LOWER(apellido_paterno) = LOWER(?)
              AND (fecha_nacimiento = ? OR ? = '')
            ORDER BY id DESC LIMIT 1");
        $pq->execute([(string)$r['nombre'], (string)$r['apellido_paterno'], $dob, $dob]);
        $preap = $pq->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    if ($preap) {
        if (empty($r['telefono']) && !empty($preap['telefono'])) {
            $proposed['telefono'] = (string)$preap['telefono'];
            $sources['telefono'] = 'preaprobaciones';
        }
        if (empty($r['email']) && !empty($preap['email'])) {
            $proposed['email'] = (string)$preap['email'];
            $sources['email'] = 'preaprobaciones';
        }
    }
    $linkedTel = $preap['telefono'] ?? '';
    $linkedEmail = $preap['email'] ?? '';

    // ── Source 1: cdc_query_log.body_sent — original address sent to CDC
    if (empty($r['calle_numero']) || empty($r['colonia'])) {
        try {
            $rfcBase = substr((string)($r['rfc'] ?? ''), 0, 10);
            $lq = $pdo->prepare("SELECT body_sent FROM cdc_query_log
                WHERE body_sent LIKE ?
                   OR body_sent LIKE ?
                ORDER BY ABS(TIMESTAMPDIFF(SECOND, freg, ?)) ASC LIMIT 1");
            $lq->execute([
                '%' . $rfcBase . '%',
                '%"primerNombre":"' . strtoupper((string)$r['nombre']) . '"%"apellidoPaterno":"' . strtoupper((string)$r['apellido_paterno']) . '"%',
                (string)$r['freg'],
            ]);
            $logRow = $lq->fetch(PDO::FETCH_ASSOC);
            if ($logRow) {
                $addr = extractCdcBodyAddress((string)$logRow['body_sent']);
                if (empty($r['calle_numero']) && $addr['calle'] !== '') {
                    $proposed['calle_numero'] = $addr['calle'];
                    $sources['calle_numero'] = 'cdc_query_log';
                }
                if (empty($r['colonia']) && $addr['colonia'] !== '') {
                    $proposed['colonia'] = $addr['colonia'];
                    $sources['colonia'] = 'cdc_query_log';
                }
                if (empty($r['municipio']) && $addr['municipio'] !== '') {
                    $proposed['municipio'] = $addr['municipio'];
                    $sources['municipio'] = 'cdc_query_log';
                }
                if (empty($r['cp']) && $addr['cp'] !== '') {
                    $proposed['cp'] = $addr['cp'];
                    $sources['cp'] = 'cdc_query_log';
                }
            }
        } catch (Throwable $e) {}
    }

    // ── Source 2: checklist_entrega_v2.pagare_*
    if (empty($proposed['calle_numero']) && empty($r['calle_numero']) && $linkedTel) {
        try {
            $cl = $pdo->prepare("SELECT cl.pagare_calle, cl.pagare_num_exterior,
                                        cl.pagare_colonia, cl.pagare_alcaldia, cl.pagare_cp
                FROM transacciones t
                JOIN inventario_motos im ON im.transaccion_id = t.id
                JOIN checklist_entrega_v2 cl ON cl.moto_id = im.id
                WHERE (t.telefono = ? OR t.email = ?)
                  AND cl.pagare_calle IS NOT NULL AND cl.pagare_calle != ''
                ORDER BY cl.id DESC LIMIT 1");
            $cl->execute([$linkedTel, $linkedEmail]);
            $clRow = $cl->fetch(PDO::FETCH_ASSOC);
            if ($clRow) {
                $combo = trim(($clRow['pagare_calle'] ?? '') . ' ' . ($clRow['pagare_num_exterior'] ?? ''));
                if (empty($r['calle_numero']) && $combo !== '') {
                    $proposed['calle_numero'] = $combo;
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
            }
        } catch (Throwable $e) {}
    }

    // ── Source 3: Truora INE OCR
    if (empty($proposed['calle_numero']) && empty($r['calle_numero']) && $linkedTel) {
        try {
            $vi = $pdo->prepare("SELECT raw_truora_payload FROM verificaciones_identidad
                WHERE (telefono = ? OR email = ?)
                  AND raw_truora_payload IS NOT NULL AND raw_truora_payload != ''
                ORDER BY id DESC LIMIT 1");
            $vi->execute([$linkedTel, $linkedEmail]);
            $viRow = $vi->fetch(PDO::FETCH_ASSOC);
            if ($viRow) {
                $addr = parseTruoraAddrV2((string)$viRow['raw_truora_payload']);
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
            }
        } catch (Throwable $e) {}
    }

    // ── Source 4: transacciones
    if (empty($proposed['calle_numero']) && empty($r['calle_numero']) && $linkedTel) {
        try {
            $tq = $pdo->prepare("SELECT direccion, colonia FROM transacciones
                WHERE (telefono = ? OR email = ?)
                  AND direccion IS NOT NULL AND direccion != ''
                ORDER BY id DESC LIMIT 1");
            $tq->execute([$linkedTel, $linkedEmail]);
            $txRow = $tq->fetch(PDO::FETCH_ASSOC);
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

    $updates = array_filter($proposed, fn($v) => $v !== null && $v !== '');
    if (empty($updates)) continue;

    $plan[] = [
        'id'      => (int)$r['id'],
        'name'    => trim($r['nombre'] . ' ' . $r['apellido_paterno'] . ' ' . ($r['apellido_materno'] ?? '')),
        'updates' => $updates,
        'sources' => $sources,
    ];
}

// COMMIT
$updateStats = null;
if ($commit && !empty($plan)) {
    $updated = 0; $errors = 0;
    foreach ($plan as $p) {
        try {
            $sets = []; $params = [];
            foreach ($p['updates'] as $f => $v) {
                $sets[] = "`$f` = ?";
                $params[] = $v;
            }
            $params[] = $p['id'];
            $up = $pdo->prepare("UPDATE consultas_buro SET " . implode(', ', $sets) . " WHERE id = ?");
            $up->execute($params);
            if ($up->rowCount() > 0) $updated++;
        } catch (Throwable $e) {
            $errors++;
            error_log('deep-recover UPDATE id ' . $p['id'] . ': ' . $e->getMessage());
        }
    }
    $updateStats = compact('updated','errors');
    if (function_exists('adminLog')) {
        adminLog('deep_recover_customer_data', ['updated'=>$updated,'errors'=>$errors,'plan'=>count($plan)]);
    }
}

// UI
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Deep recover customer data</title>';
echo '<style>body{font-family:system-ui,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1280px;margin:0 auto;line-height:1.55;}
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
.tag{display:inline-block;padding:1px 6px;border-radius:3px;font-size:10px;font-weight:600;margin-left:4px;}
.tag-cdc{background:#dbeafe;color:#1e3a8a;}
.tag-pre{background:#fef9c3;color:#854d0e;}
.tag-cl{background:#dcfce7;color:#166534;}
.tag-tx{background:#fce7f3;color:#9d174d;}
.tag-ti{background:#fed7aa;color:#9a3412;}
.muted{color:#94a3b8;}
</style></head><body>';
echo '<h1>Deep recovery of customer address + contact info</h1>';
echo '<p class="muted" style="font-size:12.5px;">Pulls calle/colonia from cdc_query_log.body_sent and telefono/email from preaprobaciones. FREE.</p>';

echo '<div class="banner banner-info">'
   . 'Candidates needing enrichment: <strong>' . count($candidates) . '</strong> &middot; '
   . 'Rows with at least one proposed fix: <strong>' . count($plan) . '</strong>'
   . '</div>';

if ($updateStats) {
    echo '<div class="banner banner-ok">Updated: <strong>' . $updateStats['updated'] . '</strong> rows &middot; Errors: <strong>' . $updateStats['errors'] . '</strong></div>';
    echo '<p><a class="btn" href="/admin/php/buro/exportar.php" target="_blank">Download CDC export CSV</a> ';
    echo '<a class="btn ghost" href="?">Re-scan</a></p>';
}

if (!empty($plan)) {
    echo '<div class="card">';
    echo '<h2>Proposed updates (' . count($plan) . ' rows)</h2>';
    echo '<table><thead><tr><th>id</th><th>Name</th><th>Telefono</th><th>Email</th><th>Calle</th><th>Colonia</th><th>Municipio</th><th>CP</th></tr></thead><tbody>';
    foreach ($plan as $p) {
        $tagMap = ['preaprobaciones'=>'tag-pre','cdc_query_log'=>'tag-cdc','checklist_pagare'=>'tag-cl','transacciones'=>'tag-tx','truora_ine'=>'tag-ti'];
        echo '<tr>';
        echo '<td>' . $p['id'] . '</td>';
        echo '<td>' . htmlspecialchars($p['name']) . '</td>';
        foreach (['telefono','email','calle_numero','colonia','municipio','cp'] as $f) {
            $v = $p['updates'][$f] ?? '';
            $src = $p['sources'][$f] ?? '';
            echo '<td>' . htmlspecialchars((string)$v);
            if ($src) echo '<span class="tag ' . ($tagMap[$src] ?? '') . '">' . htmlspecialchars($src) . '</span>';
            echo '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
    if (!$commit) {
        echo '<form method="post" style="margin-top:14px;">';
        echo '<input type="hidden" name="commit" value="1">';
        echo '<button class="btn" type="submit" onclick="return confirm(\'Apply ' . count($plan) . ' UPDATEs?\');">Commit updates (' . count($plan) . ' rows)</button>';
        echo '</form>';
    }
    echo '</div>';
}

echo '<div class="card" style="background:#dbeafe;border-color:#93c5fd;font-size:12px;">';
echo '<strong>Source legend:</strong> ';
echo '<span class="tag tag-pre">preaprobaciones</span> credit application ';
echo '<span class="tag tag-cdc">cdc_query_log</span> CDC request body ';
echo '<span class="tag tag-cl">checklist_pagare</span> PAGARÉ signing ';
echo '<span class="tag tag-ti">truora_ine</span> INE OCR ';
echo '<span class="tag tag-tx">transacciones</span> purchase';
echo '</div>';

echo '</body></html>';
