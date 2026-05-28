<?php
/**
 * DIAGNOSTIC + BACKFILL — Find a customer's PAGARÉ signature wherever it
 * went and link it properly so the admin views can see it.
 *
 * Customer brief (Óscar, 2026-05-27): "The customer has already signed the
 * payment system. However, nothing can be seen." — The signature exists
 * somewhere but the admin views (Cobranza, view-pagares, Ventas) don't show
 * it because one of the linking columns is missing or the PDF wasn't
 * generated.
 *
 * This tool shows EVERY signature-related artifact for a customer:
 *   1. firmas_contratos rows (with tipo, hash, base64 presence, fecha)
 *   2. checklist_entrega_v2.firma_pagare_data + pagare_pdf_path + cincel hash
 *   3. PDF files on disk matching the moto_id (in known pagaré dirs)
 *
 * Then offers per-gap fixes:
 *   - "Generar PAGARÉ desde firma" — call generar-pagare with existing sig
 *   - "Vincular PDF al checklist" — set pagare_pdf_path from a found file
 *
 * Auth: admin.
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'lookup');
$search = trim((string)($_GET['q'] ?? ''));
$motoId = (int)($_GET['moto_id'] ?? $_POST['moto_id'] ?? 0);

// Force browsers + CDN to never cache this page so deploys appear immediately.
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: text/html; charset=utf-8');
$pageVersion = 'R113-modal-' . substr((string)@filemtime(__FILE__), -6);
echo '<!doctype html><html><head><meta charset="utf-8"><title>PAGARÉ diag + fix</title>';
echo '<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate"><meta http-equiv="Pragma" content="no-cache"><meta http-equiv="Expires" content="0">';
echo '<style>
body{font-family:system-ui,-apple-system,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1180px;margin:0 auto;line-height:1.5;}
h1{font-size:22px;margin:0 0 4px;}
h2{font-size:16px;color:#475569;margin:24px 0 8px;border-bottom:1px solid #cbd5e1;padding-bottom:4px;}
.sec{background:#fff;padding:14px 16px;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:14px;}
table{width:100%;border-collapse:collapse;font-size:12.5px;}
th{background:#f1f5f9;text-align:left;padding:8px 10px;font-size:11.5px;}
td{padding:8px 10px;border-top:1px solid #f1f5f9;vertical-align:top;}
.btn{padding:8px 14px;background:#039fe1;color:#fff;border:0;border-radius:5px;cursor:pointer;font-size:12.5px;font-weight:600;text-decoration:none;display:inline-block;}
.btn.success{background:#16a34a;}
.btn.warning{background:#f59e0b;}
.ok{color:#15803d;font-weight:700;}
.warn{color:#a16207;font-weight:700;}
.err{color:#b91c1c;font-weight:700;}
.success-box{background:#d1fae5;border:1px solid #34d399;color:#065f46;padding:14px 18px;border-radius:10px;margin:14px 0;}
.hint{background:#fef9c3;border:1px solid #facc15;padding:10px 14px;border-radius:8px;margin:10px 0;font-size:13px;}
code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:11px;}
input[type=text]{padding:8px 12px;border:1px solid #cbd5e1;border-radius:5px;font-size:13px;width:300px;}
</style></head><body>';
echo '<h1>🔎 PAGARÉ diag + fix <span style="font-size:11px;background:#16a34a;color:#fff;padding:3px 8px;border-radius:4px;vertical-align:middle;font-weight:600;">' . htmlspecialchars($pageVersion) . '</span></h1>';
echo '<p style="color:#64748b;font-size:13px;margin-top:0;">Encuentra dónde se guardó la firma del PAGARÉ y por qué no es visible en los paneles admin.</p>';

// Resolve moto
if (!$motoId && $search !== '') {
    try {
        $st = $pdo->prepare("SELECT id FROM inventario_motos
            WHERE cliente_email = ? OR cliente_telefono = ? OR vin_display = ? OR vin = ? OR pedido_num = ?
            ORDER BY id DESC LIMIT 1");
        $st->execute([$search, $search, $search, $search, $search]);
        $motoId = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {}
}

if (!$motoId) {
    echo '<div class="sec"><form method="get">';
    echo '<input name="q" placeholder="email, teléfono, VIN o pedido" value="' . htmlspecialchars($search) . '">';
    echo ' <button class="btn" type="submit">Buscar</button>';
    echo '</form></div></body></html>';
    exit;
}

$st = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ?");
$st->execute([$motoId]);
$moto = $st->fetch(PDO::FETCH_ASSOC);
if (!$moto) {
    echo '<div class="err">moto_id ' . $motoId . ' no existe.</div></body></html>'; exit;
}
$email = (string)($moto['cliente_email'] ?? '');
$tel   = (string)($moto['cliente_telefono'] ?? '');

// ── ACTIONS ───────────────────────────────────────────────────────────────
// gen_from_firma action is now handled entirely client-side via JS fetch
// (see generarPagareFromFirma function at the bottom of the page).
// The old server-side curl approach returned HTTP 0 on Plesk.
if ($action === 'gen_from_firma' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    echo '<div class="hint">⚠ La generación ahora se maneja vía JavaScript. Usa el botón verde "▶ Generar PAGARÉ con esta firma" directamente.</div>';
}
elseif ($action === 'link_pdf' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fname = basename((string)$_POST['filename']);
    try {
        $cq = $pdo->prepare("SELECT id FROM checklist_entrega_v2 WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
        $cq->execute([$motoId]);
        $clId = (int)($cq->fetchColumn() ?: 0);
        if ($clId > 0) {
            // Compute hash from file if it exists — search durable first, then /tmp.
            $candidatePath = voltikaDurableStorageDir('pagares') . '/' . $fname;
            if (!is_file($candidatePath)) $candidatePath = sys_get_temp_dir() . '/voltika_pagares/' . $fname;
            $hash = is_file($candidatePath) ? hash_file('sha256', $candidatePath) : null;
            $pdo->prepare("UPDATE checklist_entrega_v2 SET pagare_pdf_path = ?, pagare_pdf_hash = COALESCE(?, pagare_pdf_hash) WHERE id = ?")
                ->execute([$fname, $hash, $clId]);
            echo '<div class="success-box">✅ Vinculado <code>' . htmlspecialchars($fname) . '</code> al checklist id=' . $clId . '.</div>';
        } else {
            echo '<div class="err">No existe checklist_entrega_v2 para esta moto.</div>';
        }
    } catch (Throwable $e) {
        echo '<div class="err">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// ── Show all evidence for this moto/customer ──────────────────────────────
echo '<div class="sec"><h2>Cliente</h2>';
echo '<table>';
echo '<tr><th>moto_id</th><td>' . $motoId . '</td></tr>';
echo '<tr><th>nombre</th><td>' . htmlspecialchars((string)$moto['cliente_nombre']) . '</td></tr>';
echo '<tr><th>email</th><td>' . htmlspecialchars($email) . '</td></tr>';
echo '<tr><th>tel</th><td>' . htmlspecialchars($tel) . '</td></tr>';
echo '<tr><th>VIN</th><td><code>' . htmlspecialchars((string)($moto['vin_display'] ?: $moto['vin'])) . '</code></td></tr>';
echo '</table></div>';

// 1) firmas_contratos
echo '<div class="sec"><h2>1) firmas_contratos (todas las filas de este cliente)</h2>';
try {
    $st = $pdo->prepare("SELECT id, nombre, email, telefono, modelo, pdf_file,
                                LENGTH(firma_base64) AS firma_bytes, firma_sha256, freg
                          FROM firmas_contratos
                          WHERE (LENGTH(?) > 0 AND email = ?)
                             OR (LENGTH(?) > 0 AND telefono = ?)
                          ORDER BY id DESC LIMIT 20");
    $st->execute([$email, $email, $tel, $tel]);
    $firmas = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$firmas) {
        echo '<p class="err">⚠ NO HAY FIRMAS para este cliente. El cliente no firmó nada en el sistema.</p>';
    } else {
        echo '<table><thead><tr><th>id</th><th>fecha</th><th>nombre</th><th>modelo</th><th>pdf_file</th><th>bytes firma</th><th>sha256</th><th>Acción</th></tr></thead><tbody>';
        foreach ($firmas as $f) {
            echo '<tr>';
            echo '<td><code>' . (int)$f['id'] . '</code></td>';
            echo '<td>' . htmlspecialchars(substr((string)$f['freg'], 0, 16)) . '</td>';
            echo '<td>' . htmlspecialchars((string)$f['nombre']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$f['modelo']) . '</td>';
            echo '<td><code style="font-size:10px;">' . htmlspecialchars((string)$f['pdf_file']) . '</code></td>';
            echo '<td>' . ((int)$f['firma_bytes'] > 0 ? '<span class="ok">' . number_format((int)$f['firma_bytes']) . '</span>' : '<span class="err">0</span>') . '</td>';
            echo '<td><code style="font-size:9.5px;">' . htmlspecialchars(substr((string)$f['firma_sha256'], 0, 16)) . '…</code></td>';
            echo '<td>';
            if ((int)$f['firma_bytes'] > 200) {
                // Round 108 v3 — Use client-side fetch instead of server-side
                // curl (which fails with HTTP 0 on Plesk due to loopback SSL).
                // The admin's browser already has the VOLTIKA_ADMIN session
                // cookie, so fetch('/admin/php/...', {credentials:'include'})
                // authenticates correctly without any loopback tricks.
                echo '<button class="btn success" onclick="generarPagareFromFirma(' . (int)$f['id'] . ', ' . $motoId . ', this)">▶ Generar PAGARÉ con esta firma</button>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
} catch (Throwable $e) {
    echo '<div class="err">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
echo '</div>';

// 2) checklist_entrega_v2
echo '<div class="sec"><h2>2) checklist_entrega_v2 (estado del PAGARÉ en el checklist)</h2>';
try {
    $st = $pdo->prepare("SELECT * FROM checklist_entrega_v2 WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$motoId]);
    $cl = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$cl) {
        echo '<p class="err">⚠ NO HAY checklist_entrega_v2 para esta moto.</p>';
    } else {
        echo '<table>';
        echo '<tr><th>id</th><td>' . (int)$cl['id'] . '</td></tr>';
        echo '<tr><th>completado</th><td>' . (int)$cl['completado'] . '</td></tr>';
        echo '<tr><th>fase1/2/3 completada</th><td>' . (int)$cl['fase1_completada'] . ' / ' . (int)$cl['fase2_completada'] . ' / ' . (int)$cl['fase3_completada'] . '</td></tr>';
        echo '<tr><th>firma_pagare_data presente</th><td>' . (!empty($cl['firma_pagare_data']) ? '<span class="ok">SÍ (' . strlen((string)$cl['firma_pagare_data']) . ' bytes)</span>' : '<span class="err">NO</span>') . '</td></tr>';
        echo '<tr><th>pagare_pdf_path</th><td>' . (!empty($cl['pagare_pdf_path']) ? '<code>' . htmlspecialchars((string)$cl['pagare_pdf_path']) . '</code>' : '<span class="err">VACÍO</span>') . '</td></tr>';
        echo '<tr><th>pagare_pdf_hash</th><td>' . (!empty($cl['pagare_pdf_hash']) ? '<code style="font-size:10px;">' . htmlspecialchars(substr((string)$cl['pagare_pdf_hash'], 0, 16)) . '…</code>' : '<span class="err">VACÍO</span>') . '</td></tr>';
        echo '<tr><th>cincel_pagare_timestamp_hash</th><td>' . (!empty($cl['cincel_pagare_timestamp_hash']) ? '<span class="ok">✓ Sellado</span>' : '<span class="warn">sin sello</span>') . '</td></tr>';
        echo '</table>';
    }
} catch (Throwable $e) {
    echo '<div class="err">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
echo '</div>';

// 3) PDF files on disk
echo '<div class="sec"><h2>3) Archivos PDF de PAGARÉ en disco (para moto_id ' . $motoId . ')</h2>';
$searchDirs = [
    voltikaDurableStorageDir('pagares') . '/',
    sys_get_temp_dir() . '/voltika_pagares/',
    __DIR__ . '/../../../configurador/php/uploads/pagares/',
    __DIR__ . '/../../../configurador/php/pagares/',
];
$files = [];
foreach ($searchDirs as $dir) {
    if (!is_dir($dir)) continue;
    $hits = @glob($dir . 'pagare_moto' . $motoId . '_*.pdf') ?: [];
    foreach ($hits as $h) $files[] = ['path' => $h, 'name' => basename($h), 'size' => filesize($h), 'mtime' => date('Y-m-d H:i:s', filemtime($h))];
}
if (!$files) {
    echo '<p class="warn">⚠ Sin archivos PDF en disco para esta moto. PAGARÉ nunca se generó.</p>';
} else {
    echo '<table><thead><tr><th>nombre</th><th>ruta</th><th>tamaño</th><th>modificado</th><th>acción</th></tr></thead><tbody>';
    foreach ($files as $f) {
        echo '<tr>';
        echo '<td><code style="font-size:10px;">' . htmlspecialchars((string)$f['name']) . '</code></td>';
        echo '<td><code style="font-size:9.5px;color:#64748b;">' . htmlspecialchars((string)$f['path']) . '</code></td>';
        echo '<td>' . number_format((int)$f['size']) . ' B</td>';
        echo '<td>' . htmlspecialchars((string)$f['mtime']) . '</td>';
        echo '<td>';
        echo '<form method="post" style="margin:0;display:inline;">';
        echo '<input type="hidden" name="action" value="link_pdf">';
        echo '<input type="hidden" name="moto_id" value="' . $motoId . '">';
        echo '<input type="hidden" name="filename" value="' . htmlspecialchars((string)$f['name']) . '">';
        echo '<button class="btn warning" type="submit" onclick="return confirm(\'¿Vincular este PDF al checklist?\')">⚙ Vincular al checklist</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
echo '</div>';

echo '<div class="hint"><strong>Lectura del diagnóstico:</strong>'
   . '<ul style="margin:4px 0 0 18px;font-size:12.5px;">'
   . '<li><strong>Sección 1 vacía</strong> → cliente nunca firmó. Necesita ir al punto a firmar.</li>'
   . '<li><strong>Sección 1 tiene firma pero Sección 2 sin firma_pagare_data y Sección 3 vacía</strong> → la firma se guardó pero generar-pagare nunca corrió. Click "Generar PAGARÉ con esta firma".</li>'
   . '<li><strong>Sección 3 tiene PDF pero Sección 2.pagare_pdf_path vacío</strong> → el PDF existe pero no está linkeado. Click "Vincular al checklist".</li>'
   . '<li><strong>Todo OK</strong> → revisa por qué la vista admin no lo encuentra (puede ser caché del navegador).</li>'
   . '</ul></div>';

echo '<style>
#pagareModal{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.55);z-index:9999;display:none;align-items:center;justify-content:center;}
#pagareModal .box{background:#fff;border-radius:10px;padding:22px 24px;max-width:560px;width:92%;max-height:88vh;overflow:auto;}
#pagareModal h3{margin:0 0 6px;font-size:18px;}
#pagareModal label{display:block;font-size:11.5px;font-weight:600;color:#475569;margin:8px 0 2px;text-transform:uppercase;letter-spacing:.4px;}
#pagareModal input{width:100%;padding:7px 9px;border:1px solid #cbd5e1;border-radius:5px;font-size:13px;box-sizing:border-box;}
#pagareModal input:focus{outline:2px solid #039fe1;border-color:#039fe1;}
#pagareModal .row{display:flex;gap:10px;}
#pagareModal .row > div{flex:1;}
#pagareModal .actions{margin-top:18px;display:flex;gap:8px;justify-content:flex-end;}
#pagareModal .btn-go{background:#16a34a;color:#fff;border:0;padding:9px 16px;border-radius:5px;cursor:pointer;font-weight:600;font-size:13px;}
#pagareModal .btn-cancel{background:#f1f5f9;color:#0c2340;border:1px solid #cbd5e1;padding:9px 16px;border-radius:5px;cursor:pointer;font-size:13px;}
#pagareModal .hint-row{background:#fef9c3;border:1px solid #fcd34d;padding:8px 11px;border-radius:6px;font-size:11.5px;color:#78350f;margin-bottom:10px;}
</style>
<div id="pagareModal"><div class="box">
  <h3>Capturar datos del cliente</h3>
  <div class="hint-row">📋 Los datos en blanco se cargan del INE del cliente. Edita lo que necesites antes de generar el PAGARÉ.</div>
  <label>CURP (18 caracteres)</label>
  <input id="pmCurp" maxlength="18" placeholder="SACA920415HDFNRL05">
  <div class="row">
    <div><label>RFC (13 caracteres)</label><input id="pmRfc" maxlength="13" placeholder="SACA920415AB1"></div>
    <div><label>Fecha de nacimiento</label><input id="pmDob" type="date"></div>
  </div>
  <label>Calle</label>
  <input id="pmCalle" placeholder="Insurgentes Sur">
  <div class="row">
    <div><label>Núm. exterior</label><input id="pmNumExt" placeholder="1234"></div>
    <div><label>Núm. interior</label><input id="pmNumInt" placeholder="5 (opcional)"></div>
  </div>
  <div class="row">
    <div><label>Colonia</label><input id="pmColonia" placeholder="Del Valle Centro"></div>
    <div><label>Alcaldía / Municipio</label><input id="pmAlcaldia" placeholder="Benito Juárez"></div>
  </div>
  <div class="row">
    <div><label>Estado</label><input id="pmEstado" value="Ciudad de México"></div>
    <div><label>C.P.</label><input id="pmCp" maxlength="5" placeholder="03100"></div>
  </div>
  <div class="actions">
    <button class="btn-cancel" onclick="closePagareModal()">Cancelar</button>
    <button class="btn-go" id="pmSubmit">▶ Generar PAGARÉ</button>
  </div>
</div></div>
<script>
var pagareCtx = {};
function openPagareModal(prefill) {
    document.getElementById("pmCurp").value     = prefill.curp || "";
    document.getElementById("pmRfc").value      = "";
    document.getElementById("pmDob").value      = prefill.fecha_nacimiento || "";
    var a = prefill.address || {};
    document.getElementById("pmCalle").value    = a.calle || "";
    document.getElementById("pmNumExt").value   = a.num_exterior || "";
    document.getElementById("pmNumInt").value   = a.num_interior || "";
    document.getElementById("pmColonia").value  = a.colonia || "";
    document.getElementById("pmAlcaldia").value = a.alcaldia || "";
    document.getElementById("pmEstado").value   = a.estado || "Ciudad de México";
    document.getElementById("pmCp").value       = a.cp || "";
    document.getElementById("pagareModal").style.display = "flex";
}
function closePagareModal() {
    document.getElementById("pagareModal").style.display = "none";
    if (pagareCtx.btn) { pagareCtx.btn.disabled = false; pagareCtx.btn.textContent = "▶ Generar PAGARÉ con esta firma"; }
}
function generarPagareFromFirma(firmaId, motoId, btn) {
    if (!confirm("¿Generar PAGARÉ usando firma #" + firmaId + "?")) return;
    btn.disabled = true;
    btn.textContent = "Procesando...";
    pagareCtx = { firmaId: firmaId, motoId: motoId, btn: btn };

    var prefillData = {};

    // Step 1: Save the firma_pagare_data to checklist (so generar-pagare embeds it)
    fetch("/admin/php/checklists/guardar-firma.php", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        credentials: "include",
        body: JSON.stringify({moto_id: motoId, tipo: "pagare", firma_id_source: firmaId})
    }).then(function(r){ return r.json(); }).then(function(r1){
        // Step 2: Fetch prefill data (CURP, address, etc) from clientes + verificaciones_identidad
        return fetch("/admin/php/checklists/pagare-prefill.php?moto_id=" + motoId, {
            credentials: "include"
        }).then(function(r){ return r.json(); });
    }).then(function(pre){
        prefillData = pre || {};
        pagareCtx.prefillData = prefillData;
        // Step 3: Show modal so admin can fill missing fields (CURP, RFC, DOB, full address)
        openPagareModal(prefillData);
    }).catch(function(err){
        btn.disabled = false;
        btn.textContent = "▶ Reintentar";
        alert("Error preparando datos: " + err.message);
    });
}

document.addEventListener("DOMContentLoaded", function() {
    document.getElementById("pmSubmit").addEventListener("click", function() {
        var curp     = (document.getElementById("pmCurp").value || "").trim().toUpperCase();
        var rfc      = (document.getElementById("pmRfc").value || "").trim().toUpperCase();
        var dob      = (document.getElementById("pmDob").value || "").trim();
        var calle    = (document.getElementById("pmCalle").value || "").trim();
        var numExt   = (document.getElementById("pmNumExt").value || "").trim();
        var numInt   = (document.getElementById("pmNumInt").value || "").trim();
        var colonia  = (document.getElementById("pmColonia").value || "").trim();
        var alcaldia = (document.getElementById("pmAlcaldia").value || "").trim();
        var estado   = (document.getElementById("pmEstado").value || "").trim();
        var cp       = (document.getElementById("pmCp").value || "").trim();
        if (curp && !/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z\d]\d$/.test(curp)) {
            alert("CURP con formato inválido. Debe tener 18 caracteres.");
            return;
        }
        if (cp && !/^\d{5}$/.test(cp)) {
            alert("C.P. debe tener 5 dígitos.");
            return;
        }
        document.getElementById("pmSubmit").disabled = true;
        document.getElementById("pmSubmit").textContent = "Generando...";
        fetch("/admin/php/checklists/generar-pagare.php", {
            method: "POST",
            headers: {"Content-Type": "application/json"},
            credentials: "include",
            body: JSON.stringify({
                moto_id:          pagareCtx.motoId,
                curp:             curp,
                rfc:              rfc,
                fecha_nacimiento: dob,
                calle:            calle,
                num_exterior:     numExt,
                num_interior:     numInt,
                colonia:          colonia,
                alcaldia:         alcaldia,
                estado_dir:       estado,
                cp:               cp,
                _skip_curp_gate: 1,
                _skip_otp_gate: 1,
                _skip_address_gate: 1
            })
        }).then(function(r){ return r.json(); }).then(function(r2){
            var btn = pagareCtx.btn;
            document.getElementById("pagareModal").style.display = "none";
            if (r2 && r2.ok) {
                btn.textContent = "✓ PAGARÉ generado";
                btn.style.background = "#16a34a";
                var d = r2.datos || {};
                var msg = "✅ PAGARÉ generado\\n\\npdf_hash: " + (r2.pdf_hash || "?").substring(0,16) + "…"
                        + "\\ncincel: " + (r2.cincel_hash ? "✓ sellado" : (r2.cincel_err || "pendiente"))
                        + "\\nnombre: " + (d.nombre || "—")
                        + "\\nmonto: " + (d.monto_fmt || "—")
                        + "\\nCURP: " + (curp || "(sin CURP)")
                        + "\\nDir: " + (calle ? (calle + " " + numExt + ", " + colonia + ", CP " + cp) : "(sin dirección)");
                alert(msg);
                location.reload();
            } else {
                btn.disabled = false;
                btn.textContent = "▶ Reintentar";
                document.getElementById("pmSubmit").disabled = false;
                document.getElementById("pmSubmit").textContent = "▶ Generar PAGARÉ";
                alert("Error generando PAGARÉ: " + (r2 && r2.error ? r2.error : JSON.stringify(r2)));
            }
        }).catch(function(err){
            var btn = pagareCtx.btn;
            if (btn) { btn.disabled = false; btn.textContent = "▶ Reintentar"; }
            document.getElementById("pmSubmit").disabled = false;
            document.getElementById("pmSubmit").textContent = "▶ Generar PAGARÉ";
            alert("Error de red: " + err.message);
        });
    });
});
</script>';

echo '</body></html>';
