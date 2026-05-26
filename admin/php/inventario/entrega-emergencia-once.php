<?php
/**
 * EMERGENCY TOOL — Resuelve un caso de entrega físicamente completada
 * pero con datos del sistema atorados.
 *
 * Customer brief (Óscar, 2026-05-26): Carlos Ricardo Sánchez recibió su
 * moto físicamente pero el sistema muestra:
 *   - Entrega Incompleto en checklist
 *   - PAGARÉ no firmado (NO existe el PDF — el más importante)
 *   - Truora rejected (falso positivo)
 *   - No permite subir permiso provisional
 *
 * Esta herramienta permite al admin resolver las 4 cosas para un moto
 * específico en una sola pantalla:
 *   1. Capturar la firma del cliente del PAGARÉ (canvas) → genera PDF →
 *      aplica sello Cincel NOM-151 (Round 96)
 *   2. Marcar checklist_entrega_v2.completado=1 + todas las fases
 *   3. Override verificaciones_identidad approved=1 + clear rejection markers
 *   4. Set inventario_motos.estado='entregada'
 *
 * Toda acción logueada con flag forense + adminLog.
 *
 * Auth: admin.
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
header('Content-Type: text/html; charset=utf-8');

$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'lookup');
$motoId = (int)($_GET['moto_id'] ?? $_POST['moto_id'] ?? 0);
$search = trim((string)($_GET['q'] ?? ''));

echo '<!doctype html><html><head><meta charset="utf-8"><title>Emergency — Entrega</title>';
echo '<style>
body{font-family:system-ui,-apple-system,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:980px;margin:0 auto;line-height:1.5;}
h1{font-size:22px;margin:0 0 4px;}
h2{font-size:16px;color:#475569;margin:24px 0 8px;border-bottom:1px solid #cbd5e1;padding-bottom:4px;}
.sec{background:#fff;padding:14px 16px;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:14px;}
table{width:100%;border-collapse:collapse;font-size:12.5px;}
th{background:#f1f5f9;text-align:left;padding:8px 10px;font-size:11.5px;}
td{padding:8px 10px;border-top:1px solid #f1f5f9;vertical-align:top;}
.btn{padding:10px 18px;background:#039fe1;color:#fff;border:0;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;text-decoration:none;display:inline-block;margin:4px;}
.btn.ghost{background:#fff;color:#0c2340;border:1px solid #cbd5e1;}
.btn.danger{background:#dc2626;}
.btn.success{background:#16a34a;}
.btn.warning{background:#f59e0b;}
.ok{color:#15803d;font-weight:700;}
.warn{color:#a16207;font-weight:700;}
.err{color:#b91c1c;font-weight:700;}
.success-box{background:#d1fae5;border:1px solid #34d399;color:#065f46;padding:14px 18px;border-radius:10px;margin:14px 0;}
.hint{background:#fef9c3;border:1px solid #facc15;padding:10px 14px;border-radius:8px;margin:10px 0;font-size:13px;}
code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:11.5px;}
canvas{border:2px dashed #cbd5e1;border-radius:8px;background:#fafafa;touch-action:none;cursor:crosshair;width:100%;height:200px;display:block;}
input[type=text]{padding:8px 12px;border:1px solid #cbd5e1;border-radius:5px;font-size:13px;width:300px;}
</style></head><body>';
echo '<h1>🚨 Emergency — Resolver entrega bloqueada</h1>';
echo '<p style="color:#64748b;font-size:13px;margin-top:0;">Herramienta de emergencia para resolver casos donde la moto fue entregada físicamente pero el sistema está atorado. Acciones independientes — solo aplica las que necesites.</p>';
echo '<p><a href="?" class="btn ghost">← Buscar moto</a></p>';

// ──────────────────────────────────────────────────────────────────────────
// Resolve moto_id from search if needed
// ──────────────────────────────────────────────────────────────────────────
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
    echo '<div class="sec">';
    echo '<h2>Buscar moto</h2>';
    echo '<form method="get">';
    echo '<input type="text" name="q" placeholder="email, teléfono, VIN, o pedido_num" value="' . htmlspecialchars($search) . '">';
    echo ' <button type="submit" class="btn">Buscar</button>';
    echo '</form>';
    echo '<div class="hint">Ej: <code>xxmrdumontxx@gmail.com</code> (Carlos), <code>R4WPDTA15T8000072</code>, etc.</div>';
    echo '</div></body></html>';
    exit;
}

// ──────────────────────────────────────────────────────────────────────────
// Load moto + related state
// ──────────────────────────────────────────────────────────────────────────
$st = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ?");
$st->execute([$motoId]);
$moto = $st->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$moto) {
    echo '<div class="err">moto_id ' . $motoId . ' no existe.</div></body></html>'; exit;
}

$st = $pdo->prepare("SELECT * FROM checklist_entrega_v2 WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
$st->execute([$motoId]);
$cl = $st->fetch(PDO::FETCH_ASSOC) ?: null;

$st = $pdo->prepare("SELECT * FROM verificaciones_identidad WHERE telefono = ? OR email = ? ORDER BY id DESC LIMIT 1");
$st->execute([$moto['cliente_telefono'] ?? '', $moto['cliente_email'] ?? '']);
$vi = $st->fetch(PDO::FETCH_ASSOC) ?: null;

// ──────────────────────────────────────────────────────────────────────────
// Apply action
// ──────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg = '';
    try {
        if ($action === 'force_checklist') {
            $sql = "UPDATE checklist_entrega_v2
                SET fase1_completada = 1, fase1_fecha = COALESCE(fase1_fecha, NOW()),
                    fase2_completada = 1, fase2_fecha = COALESCE(fase2_fecha, NOW()),
                    fase3_completada = 1, fase3_fecha = COALESCE(fase3_fecha, NOW()),
                    completado = 1, fase_actual = 'completado',
                    forzado_admin = 1,
                    forzado_admin_motivo = ?,
                    forzado_admin_user_id = ?,
                    forzado_admin_fecha = NOW()
                WHERE moto_id = ? AND id = ?";
            $motivo = '[EMERGENCY] Entrega físicamente completada — admin marca checklist completo via /admin/php/inventario/entrega-emergencia-once.php';
            $pdo->prepare($sql)->execute([$motivo, $_SESSION['admin_user_id'] ?? null, $motoId, (int)($cl['id'] ?? 0)]);
            $msg = '✅ Checklist forzado a completado. moto_id=' . $motoId;
        }
        elseif ($action === 'override_truora') {
            if ($vi) {
                $pdo->prepare("UPDATE verificaciones_identidad
                    SET approved = 1,
                        truora_status = 'success',
                        truora_declined_reason = NULL,
                        truora_failure_status = NULL,
                        manual_review_required = 0,
                        manual_review_reason = NULL
                    WHERE id = ?")->execute([(int)$vi['id']]);
                $msg = '✅ Truora override aplicado. verif_id=' . $vi['id'];
            } else {
                $msg = '⚠ Sin fila en verificaciones_identidad para este cliente — nada que sobrescribir.';
            }
        }
        elseif ($action === 'mark_entregada') {
            $pdo->prepare("UPDATE inventario_motos
                SET estado = 'entregada', fecha_estado = NOW()
                WHERE id = ?")->execute([$motoId]);
            // Also mark cliente_acta_firmada if column exists (legacy data point)
            try { $pdo->prepare("UPDATE inventario_motos SET cliente_acta_firmada = 1, cliente_acta_fecha = NOW() WHERE id = ?")->execute([$motoId]); } catch (Throwable $e) {}
            $msg = '✅ inventario_motos.estado=entregada. moto_id=' . $motoId;
        }
        elseif ($action === 'sign_pagare' && !empty($_POST['firma_data'])) {
            $firmaB64 = (string)$_POST['firma_data'];
            if (strpos($firmaB64, 'data:image/png;base64,') !== 0) {
                $msg = '⚠ Firma inválida (no es data:image/png;base64,).';
            } else {
                // 1) Save firma to firmas_contratos
                $pdo->exec("CREATE TABLE IF NOT EXISTS firmas_contratos (
                    id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(200), email VARCHAR(200),
                    telefono VARCHAR(30), modelo VARCHAR(200), pdf_file VARCHAR(255),
                    firma_base64 MEDIUMTEXT, firma_sha256 CHAR(64), ip VARCHAR(64), user_agent VARCHAR(500),
                    freg DATETIME DEFAULT CURRENT_TIMESTAMP)");
                $sigHash = hash('sha256', $firmaB64);
                $pdo->prepare("INSERT INTO firmas_contratos (nombre, email, telefono, modelo, firma_base64, firma_sha256, ip, user_agent)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([
                        (string)($moto['cliente_nombre'] ?? ''),
                        (string)($moto['cliente_email'] ?? ''),
                        (string)($moto['cliente_telefono'] ?? ''),
                        (string)($moto['modelo'] ?? ''),
                        $firmaB64, $sigHash,
                        $_SERVER['REMOTE_ADDR'] ?? '',
                        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                    ]);

                // 2) Save firma to checklist_entrega_v2.firma_pagare_data
                if ($cl) {
                    try {
                        $pdo->prepare("UPDATE checklist_entrega_v2 SET firma_pagare_data = ? WHERE id = ?")
                            ->execute([$firmaB64, (int)$cl['id']]);
                    } catch (Throwable $e) { /* column may not exist */ }
                }

                // 3) Call generar-pagare.php (Round 96 stamps with Cincel)
                $url = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'voltika.mx') . '/admin/php/checklists/generar-pagare.php';
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Cookie: ' . ($_SERVER['HTTP_COOKIE'] ?? '')],
                    CURLOPT_POSTFIELDS => json_encode(['moto_id' => $motoId]),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT => 30,
                ]);
                $resp = curl_exec($ch);
                $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $r = json_decode((string)$resp, true) ?: [];
                if (!empty($r['ok'])) {
                    $msg = '✅ PAGARÉ firmado, generado'
                         . ($r['cincel_hash'] ? ' + sellado NOM-151' : ' (Cincel pendiente: ' . ($r['cincel_err'] ?? 'sin detalle') . ')')
                         . '. hash=' . substr((string)($r['pdf_hash'] ?? ''), 0, 16) . '…';
                } else {
                    $msg = '⚠ generar-pagare HTTP ' . $http . ' — ' . ($r['error'] ?? substr((string)$resp, 0, 200));
                }
            }
        }

        if (function_exists('adminLog')) {
            adminLog('entrega_emergencia_action', ['moto_id' => $motoId, 'action' => $action, 'msg' => $msg]);
        }
        if ($msg) echo '<div class="success-box">' . htmlspecialchars($msg) . '</div>';
        // Reload state after action
        $st = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ?");
        $st->execute([$motoId]); $moto = $st->fetch(PDO::FETCH_ASSOC) ?: $moto;
        $st = $pdo->prepare("SELECT * FROM checklist_entrega_v2 WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$motoId]); $cl = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $st = $pdo->prepare("SELECT * FROM verificaciones_identidad WHERE telefono = ? OR email = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$moto['cliente_telefono'] ?? '', $moto['cliente_email'] ?? '']); $vi = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        echo '<div class="err">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// ──────────────────────────────────────────────────────────────────────────
// Render moto state
// ──────────────────────────────────────────────────────────────────────────
echo '<div class="sec"><h2>📋 Estado actual — moto ' . $motoId . '</h2>';
echo '<table>';
echo '<tr><th>Cliente</th><td>' . htmlspecialchars((string)$moto['cliente_nombre']) . '</td></tr>';
echo '<tr><th>Email / Tel</th><td>' . htmlspecialchars((string)$moto['cliente_email']) . ' / ' . htmlspecialchars((string)$moto['cliente_telefono']) . '</td></tr>';
echo '<tr><th>Modelo</th><td>' . htmlspecialchars((string)$moto['modelo']) . ' / ' . htmlspecialchars((string)$moto['color']) . '</td></tr>';
echo '<tr><th>VIN</th><td><code>' . htmlspecialchars((string)($moto['vin_display'] ?: $moto['vin'])) . '</code></td></tr>';
echo '<tr><th>Estado inventario</th><td>' . ($moto['estado'] === 'entregada' ? '<span class="ok">' . htmlspecialchars((string)$moto['estado']) . '</span>' : '<span class="warn">' . htmlspecialchars((string)$moto['estado']) . '</span>') . '</td></tr>';
echo '<tr><th>Checklist entrega</th><td>' . ($cl ? ('completado=' . (int)$cl['completado'] . ' · F1=' . (int)$cl['fase1_completada'] . ' F2=' . (int)$cl['fase2_completada'] . ' F3=' . (int)$cl['fase3_completada']) : '<span class="err">no existe</span>') . '</td></tr>';
echo '<tr><th>PAGARÉ</th><td>' . ($cl && !empty($cl['pagare_pdf_path']) ? '<span class="ok">PDF: ' . htmlspecialchars((string)$cl['pagare_pdf_path']) . '</span>' : '<span class="err">SIN GENERAR</span>') . '</td></tr>';
echo '<tr><th>Sello Cincel pagaré</th><td>' . (!empty($cl['cincel_pagare_timestamp_hash']) ? '<span class="ok">✓ NOM-151</span>' : '<span class="warn">sin sello</span>') . '</td></tr>';
echo '<tr><th>Truora</th><td>' . ($vi ? ('approved=' . (int)$vi['approved'] . ' · status=' . htmlspecialchars((string)$vi['truora_status']) . ' · manual_review=' . (int)$vi['manual_review_required']) : '<span class="warn">sin fila</span>') . '</td></tr>';
echo '</table></div>';

// ──────────────────────────────────────────────────────────────────────────
// Actions
// ──────────────────────────────────────────────────────────────────────────
echo '<div class="sec"><h2>🚨 Acción 1 — Firmar Pagaré + Cincel NOM-151</h2>';
echo '<div class="hint">Pide al cliente que firme con el dedo abajo. Al guardar: se persiste la firma, se genera el PDF del PAGARÉ, y se aplica el sello Cincel NOM-151 automáticamente (Round 96).</div>';
echo '<canvas id="pagSig" width="500" height="200"></canvas>';
echo '<div style="margin-top:8px;">';
echo '<button class="btn ghost" type="button" id="clearSig">Limpiar firma</button> ';
echo '<button class="btn success" type="button" id="submitSig" disabled>▶ Firmar Pagaré y generar PDF</button>';
echo '</div>';
echo '<form method="post" id="signForm" style="display:none;">';
echo '<input type="hidden" name="action" value="sign_pagare">';
echo '<input type="hidden" name="moto_id" value="' . $motoId . '">';
echo '<input type="hidden" name="firma_data" id="firmaDataInput">';
echo '</form>';
echo '</div>';

echo '<div class="sec"><h2>🛠 Acción 2 — Forzar checklist completado</h2>';
echo '<form method="post"><input type="hidden" name="action" value="force_checklist"><input type="hidden" name="moto_id" value="' . $motoId . '">';
echo '<button type="submit" class="btn warning" onclick="return confirm(\'¿Forzar checklist a completado=1 con todas las fases? La entrega física ya ocurrió.\')">⚙ Forzar checklist completado</button>';
echo '</form></div>';

echo '<div class="sec"><h2>🛡 Acción 3 — Override Truora a aprobado</h2>';
echo '<form method="post"><input type="hidden" name="action" value="override_truora"><input type="hidden" name="moto_id" value="' . $motoId . '">';
echo '<button type="submit" class="btn warning" onclick="return confirm(\'¿Sobrescribir verificación de identidad a aprobado=1?\')">⚙ Override Truora a aprobado</button>';
echo '</form></div>';

echo '<div class="sec"><h2>🏍 Acción 4 — Marcar moto como entregada</h2>';
echo '<form method="post"><input type="hidden" name="action" value="mark_entregada"><input type="hidden" name="moto_id" value="' . $motoId . '">';
echo '<button type="submit" class="btn danger" onclick="return confirm(\'¿Marcar inventario_motos.estado=entregada?\')">⚙ Marcar entregada</button>';
echo '</form></div>';

// Signature canvas JS
echo '<script>
(function(){
    var canvas = document.getElementById("pagSig");
    var ctx = canvas.getContext("2d");
    var drawing = false, hasInk = false;
    function resize() {
        var rect = canvas.getBoundingClientRect();
        var dpr = window.devicePixelRatio || 1;
        canvas.width = rect.width * dpr;
        canvas.height = rect.height * dpr;
        ctx.scale(dpr, dpr);
        ctx.strokeStyle = "#0c2340";
        ctx.lineWidth = 2.5;
        ctx.lineCap = "round";
    }
    resize();
    function pt(e) {
        var rect = canvas.getBoundingClientRect();
        var t = (e.touches && e.touches[0]) || e;
        return { x: t.clientX - rect.left, y: t.clientY - rect.top };
    }
    canvas.addEventListener("mousedown", function(e){ e.preventDefault(); drawing=true; var p=pt(e); ctx.beginPath(); ctx.moveTo(p.x,p.y); });
    canvas.addEventListener("mousemove", function(e){ if(!drawing) return; e.preventDefault(); var p=pt(e); ctx.lineTo(p.x,p.y); ctx.stroke(); hasInk=true; document.getElementById("submitSig").disabled=false; });
    canvas.addEventListener("mouseup",   function(e){ e.preventDefault(); drawing=false; });
    canvas.addEventListener("mouseleave",function(e){ drawing=false; });
    canvas.addEventListener("touchstart",function(e){ e.preventDefault(); drawing=true; var p=pt(e); ctx.beginPath(); ctx.moveTo(p.x,p.y); });
    canvas.addEventListener("touchmove", function(e){ if(!drawing) return; e.preventDefault(); var p=pt(e); ctx.lineTo(p.x,p.y); ctx.stroke(); hasInk=true; document.getElementById("submitSig").disabled=false; });
    canvas.addEventListener("touchend",  function(e){ e.preventDefault(); drawing=false; });
    document.getElementById("clearSig").addEventListener("click", function(){ ctx.clearRect(0,0,canvas.width,canvas.height); hasInk=false; document.getElementById("submitSig").disabled=true; });
    document.getElementById("submitSig").addEventListener("click", function(){
        if(!hasInk){ alert("Pide al cliente que firme primero."); return; }
        if(!confirm("¿Generar Pagaré con esta firma y aplicar sello Cincel NOM-151?")) return;
        document.getElementById("firmaDataInput").value = canvas.toDataURL("image/png");
        document.getElementById("signForm").submit();
    });
})();
</script>';

echo '</body></html>';
