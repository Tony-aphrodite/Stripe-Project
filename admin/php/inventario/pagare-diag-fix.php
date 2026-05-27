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

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>PAGARÉ diag + fix</title>';
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
echo '<h1>🔎 PAGARÉ diag + fix</h1>';
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
if ($action === 'gen_from_firma' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $firmaId = (int)$_POST['firma_id'];
    try {
        $fq = $pdo->prepare("SELECT firma_base64 FROM firmas_contratos WHERE id = ?");
        $fq->execute([$firmaId]);
        $firmaB64 = (string)($fq->fetchColumn() ?: '');
        if ($firmaB64 === '') throw new RuntimeException('firma_base64 vacío en firmas_contratos id=' . $firmaId);
        if (strpos($firmaB64, 'data:image/png;base64,') !== 0) {
            $firmaB64 = 'data:image/png;base64,' . $firmaB64;
        }
        // Save signature to checklist (this is what generar-pagare uses to embed)
        $cq = $pdo->prepare("SELECT id FROM checklist_entrega_v2 WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
        $cq->execute([$motoId]);
        $clId = (int)($cq->fetchColumn() ?: 0);
        if ($clId > 0) {
            try { $pdo->prepare("UPDATE checklist_entrega_v2 SET firma_pagare_data = ? WHERE id = ?")
                ->execute([$firmaB64, $clId]); } catch (Throwable $e) {}
        }
        // Call generar-pagare via internal HTTP (passes admin cookie)
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
            echo '<div class="success-box">✅ PAGARÉ generado desde firma existente · pdf_hash=' . htmlspecialchars(substr((string)($r['pdf_hash'] ?? ''), 0, 16)) . '…'
               . ' · cincel=' . (!empty($r['cincel_hash']) ? '<span class="ok">sellado</span>' : '<span class="warn">' . htmlspecialchars((string)($r['cincel_err'] ?? 'pendiente')) . '</span>') . '</div>';
        } else {
            echo '<div class="err">HTTP ' . $http . ' — ' . htmlspecialchars((string)($r['error'] ?? substr((string)$resp, 0, 200))) . '</div>';
        }
    } catch (Throwable $e) {
        echo '<div class="err">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}
elseif ($action === 'link_pdf' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fname = basename((string)$_POST['filename']);
    try {
        $cq = $pdo->prepare("SELECT id FROM checklist_entrega_v2 WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
        $cq->execute([$motoId]);
        $clId = (int)($cq->fetchColumn() ?: 0);
        if ($clId > 0) {
            // Compute hash from file if it exists
            $candidatePath = sys_get_temp_dir() . '/voltika_pagares/' . $fname;
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
                echo '<form method="post" style="margin:0;display:inline;">';
                echo '<input type="hidden" name="action" value="gen_from_firma">';
                echo '<input type="hidden" name="moto_id" value="' . $motoId . '">';
                echo '<input type="hidden" name="firma_id" value="' . (int)$f['id'] . '">';
                echo '<button type="submit" class="btn success" onclick="return confirm(\'¿Generar PAGARÉ usando esta firma?\')">▶ Generar PAGARÉ con esta firma</button>';
                echo '</form>';
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

echo '</body></html>';
