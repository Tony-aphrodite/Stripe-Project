<?php
/**
 * Voltika Admin — Round 21 v3 (2026-05-14).
 *
 * Quick diagnostic: list customers that DO have INE/selfie photos
 * captured (verificaciones_identidad.files_saved is a non-empty JSON
 * array). Used to find a test target for the Identidad photo display
 * flow when the customer at hand (e.g. Carlos Ricardo VK-1826-0001)
 * was rejected by Truora and therefore has no documents.
 *
 * GET /admin/php/preaprobaciones/clientes-con-fotos.php?limit=20
 *   → HTML table (default) OR ?format=json for raw rows.
 *
 * Columns shown: telefono, email, nombre, files_saved photo count,
 * truora_status, approved, last update, and a one-click "Abrir en
 * Ventas" link to jump straight to the order in admin.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$limit  = max(1, min(200, (int)($_GET['limit'] ?? 30)));
$format = strtolower((string)($_GET['format'] ?? 'html'));

$pdo = getDB();

try {
    // Round 21 v3 (2026-05-14): nobody had local photos, so widen the
    // query to ALSO return rows where Truora reported approved=1 even
    // when files_saved is empty. Those are the candidates for the
    // sync-truora.php backfill — Truora may still have the documents
    // in their cloud even if we never persisted them locally.
    //
    // Returns 3 buckets:
    //   - con_fotos       : files_saved non-empty (locally available now)
    //   - aprobados_sin   : approved=1 but no local photos (candidates)
    //   - todos_recientes : last N rows for general inspection
    $hasPhotosClause = "vi.files_saved IS NOT NULL
                        AND vi.files_saved <> ''
                        AND vi.files_saved <> '[]'
                        AND vi.files_saved LIKE '%\"%'";

    $sqlBase = "
        SELECT
            vi.id                AS verif_id,
            vi.telefono,
            vi.email,
            CONCAT_WS(' ', vi.nombre, vi.apellidos) AS nombre,
            vi.truora_status,
            vi.approved,
            vi.truora_process_id,
            vi.truora_account_id,
            vi.files_saved,
            vi.freg,
            vi.truora_updated_at,
            (SELECT t.pedido_corto FROM transacciones t
              WHERE t.telefono = vi.telefono OR t.email = vi.email
              ORDER BY t.id DESC LIMIT 1)              AS pedido_corto,
            (SELECT t.id FROM transacciones t
              WHERE t.telefono = vi.telefono OR t.email = vi.email
              ORDER BY t.id DESC LIMIT 1)              AS tx_id
        FROM verificaciones_identidad vi
        WHERE ";

    $rowsConFotos = $pdo->query($sqlBase . $hasPhotosClause .
                                " ORDER BY vi.id DESC LIMIT $limit")
                        ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $rowsAprobSin = $pdo->query($sqlBase .
                                "vi.approved = 1 AND NOT (" . $hasPhotosClause . ")
                                 ORDER BY vi.id DESC LIMIT $limit")
                        ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $rowsRecent   = $pdo->query($sqlBase . "1=1 ORDER BY vi.id DESC LIMIT $limit")
                        ->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Legacy alias so existing JSON consumers don't break.
    $rows = $rowsConFotos;

    // Decode files_saved + classify each photo by suffix.
    $decoratePhotos = function (array &$set) {
        foreach ($set as &$r) {
            $files = @json_decode((string)($r['files_saved'] ?? ''), true);
            $r['photos'] = ['selfie' => null, 'ine_frente' => null, 'ine_reverso' => null];
            $r['photos_count'] = 0;
            if (is_array($files)) {
                foreach ($files as $fn) {
                    if (!is_string($fn)) continue;
                    $r['photos_count']++;
                    $l = strtolower($fn);
                    if (strpos($l, '_selfie')      !== false) $r['photos']['selfie']      = $fn;
                    if (strpos($l, '_ine_frente')  !== false) $r['photos']['ine_frente']  = $fn;
                    if (strpos($l, '_ine_reverso') !== false) $r['photos']['ine_reverso'] = $fn;
                }
            }
        }
        unset($r);
    };
    $decoratePhotos($rowsConFotos);
    $decoratePhotos($rowsAprobSin);
    $decoratePhotos($rowsRecent);
} catch (Throwable $e) {
    if ($format === 'json') {
        adminJsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    http_response_code(500);
    echo "<pre>Error: " . htmlspecialchars($e->getMessage()) . "</pre>";
    exit;
}

if ($format === 'json') {
    adminJsonOut([
        'ok'                => true,
        'rows'              => $rows,                       // legacy alias = con_fotos
        'total'             => count($rows),
        'con_fotos'         => $rowsConFotos,
        'aprobados_sin_fotos'=> $rowsAprobSin,
        'recientes'         => $rowsRecent,
    ]);
}

// ── HTML render ──────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=UTF-8');

$esc = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8">
<title>Clientes con fotos INE/Selfie — diagnóstico</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fb;color:#0c2340;padding:20px;}
h1{font-size:20px;margin:0 0 6px;}
.sub{color:#64748b;font-size:13px;margin-bottom:18px;}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);}
th{text-align:left;padding:10px 12px;background:#1a3a5c;color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:.4px;}
td{padding:10px 12px;font-size:13px;border-bottom:1px solid #f1f5f9;vertical-align:top;}
tr:last-child td{border-bottom:0;}
.thumb{display:inline-block;width:48px;height:48px;border-radius:6px;overflow:hidden;border:1px solid #cbd5e1;margin-right:4px;vertical-align:middle;}
.thumb img{width:100%;height:100%;object-fit:cover;}
.ok{color:#16a34a;font-weight:700;}
.bad{color:#b91c1c;font-weight:700;}
.muted{color:#94a3b8;}
a{color:#0ea5e9;text-decoration:none;font-weight:600;}
a:hover{text-decoration:underline;}
.empty{padding:20px;text-align:center;color:#64748b;background:#fff;border-radius:10px;font-size:13px;}
h2{font-size:14px;margin:18px 0 4px;}
table+h2{margin-top:24px;}
.notice{padding:12px 14px;background:#dbeafe;border:1px solid #93c5fd;color:#1e40af;border-radius:8px;font-size:13px;margin-bottom:14px;line-height:1.5;}
</style></head><body>

<h1>📷 Diagnóstico Truora — clientes y fotos</h1>
<div class="sub">Round 21 v3 — buckets para encontrar candidatos de prueba y backfill de fotos.</div>

<?php
$renderTable = function (array $set, string $emptyMsg, bool $showSync = false) use ($esc) {
    if (empty($set)) {
        echo '<div class="empty">' . htmlspecialchars($emptyMsg) . '</div>';
        return;
    }
    echo '<table><thead><tr>'.
         '<th>#</th><th>Cliente</th><th>Teléfono</th><th>Email</th>'.
         '<th>Truora</th><th>Process ID</th><th>Fotos</th><th>Pedido</th><th>Acción</th>'.
         '</tr></thead><tbody>';
    foreach ($set as $r) {
        $approved = $r['approved'];
        $approvedTxt = ($approved == 1) ? '<span class="ok">✓ Aprobado</span>'
                     : (($approved === 0 || $approved === '0') ? '<span class="bad">✗ Rechazado</span>'
                     : '<span class="muted">—</span>');
        $thumbs = '';
        foreach (['ine_frente','ine_reverso','selfie'] as $k) {
            $fn = $r['photos'][$k] ?? null;
            if ($fn) {
                $url = '/configurador/php/uploads/' . rawurlencode($fn);
                $thumbs .= '<a href="'.$url.'" target="_blank" class="thumb"><img src="'.$url.'" alt="'.$k.'"></a>';
            }
        }
        $pidShort = $r['truora_process_id']
            ? '<code style="font-size:10px;">' . $esc(substr($r['truora_process_id'], 0, 16)) . '…</code>'
            : '<span class="muted">—</span>';
        $abrirUrl = $r['pedido_corto']
            ? '/admin/#ventas?q=' . rawurlencode($r['pedido_corto'])
            : ($r['tx_id'] ? '/admin/#ventas?q=' . (int)$r['tx_id'] : '/admin/#ventas');
        echo '<tr>'.
             '<td>'   . $esc($r['verif_id']) . '</td>'.
             '<td><strong>' . $esc(trim($r['nombre'])) . '</strong></td>'.
             '<td>'   . $esc($r['telefono']) . '</td>'.
             '<td>'   . $esc($r['email'])    . '</td>'.
             '<td>'   . $approvedTxt . '<br><span class="muted" style="font-size:11px;">' .
                       $esc($r['truora_status'] ?? '—') . '</span></td>'.
             '<td>'   . $pidShort . '</td>'.
             '<td>'   . ($thumbs ?: '<span class="muted">— sin fotos</span>')
                     . '<br><span class="muted" style="font-size:11px;">' . (int)$r['photos_count'] . ' archivos</span></td>'.
             '<td>'   . ($r['pedido_corto'] ? $esc($r['pedido_corto']) : '<span class="muted">—</span>') . '</td>'.
             '<td>'.
                '<a href="' . $esc($abrirUrl) . '">Abrir →</a>';
        if ($showSync) {
            $tel = $esc($r['telefono']);
            $eml = $esc($r['email']);
            $vid = (int)$r['verif_id'];
            // Round 21 v5: pass verif_id to target THIS exact row instead
            // of the most-recent for the phone (which often is a rejected
            // retry for repeat customers).
            echo ' · <button class="sync-btn ad-btn primary" '.
                 'data-verif-id="'.$vid.'" '.
                 'data-tel="'.$tel.'" data-email="'.$eml.'" '.
                 'style="font-size:11px;padding:3px 8px;background:#0ea5e9;color:#fff;border:0;border-radius:4px;cursor:pointer;">'.
                 '🔄 Sync</button> <span class="sync-result muted" style="font-size:10px;"></span>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';
};
?>

<h2 style="font-size:15px;margin:18px 0 8px;color:#0c2340;">🟢 Bucket 1 — Clientes con fotos LOCALES</h2>
<div class="sub">Estos clientes ya tienen sus fotos guardadas en <code>configurador/php/uploads/</code>. Listos para probar la visualización en el modal.</div>
<?php $renderTable($rowsConFotos, 'Sin clientes en este bucket — nadie tiene fotos guardadas localmente todavía.'); ?>

<h2 style="font-size:15px;margin:24px 0 8px;color:#0c2340;">🟡 Bucket 2 — Aprobados en Truora SIN fotos locales</h2>
<div class="sub">Truora aprobó la verificación pero nuestro DB nunca recibió las fotos. Estos son los candidatos para el backfill via <code>sync-truora.php</code>. Click "🔄 Sync" para intentar traer las fotos desde Truora.</div>
<?php $renderTable($rowsAprobSin, 'Sin clientes aprobados sin fotos — todos los aprobados ya tienen fotos locales (o no hay aprobados todavía).', true); ?>

<h2 style="font-size:15px;margin:24px 0 8px;color:#0c2340;">⚪ Bucket 3 — Últimas verificaciones (cualquier estado)</h2>
<div class="sub">Vista general de las verificaciones más recientes para entender el panorama.</div>
<?php $renderTable($rowsRecent, 'Tabla <code>verificaciones_identidad</code> vacía — ningún cliente ha iniciado Truora.'); ?>

<script>
document.querySelectorAll('.sync-btn').forEach(function(btn){
  btn.addEventListener('click', function(){
    var tel = btn.dataset.tel || '';
    var email = btn.dataset.email || '';
    var statusEl = btn.nextElementSibling;
    btn.disabled = true;
    statusEl.textContent = '⏳ consultando Truora…';
    statusEl.style.color = '#1e40af';
    var vid = parseInt(btn.dataset.verifId || '0', 10) || 0;
    fetch('/admin/php/preaprobaciones/sync-truora.php', {
      method: 'POST',
      credentials: 'include',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({verif_id: vid, telefono: tel, email: email})
    }).then(function(r){return r.json();}).then(function(j){
      if (j && j.ok) {
        statusEl.textContent = '✓ ' + (j.photos_count||0) + ' fotos · ' + (j.fetched && j.fetched.status || '?');
        statusEl.style.color = '#15803d';
        // Round 21 v5: surface diagnostic info on hover so we can spot
        // why downloads fail without re-running the page. Auto-reload is
        // skipped when photos_count===0 so the Network panel keeps the
        // sync-truora.php Response body for inspection.
        if (j._debug) {
          var summary = 'classified=' + JSON.stringify(Object.keys(j._debug.classified || {})) +
                        ' downloads=' + JSON.stringify((j._debug.downloads||[]).map(function(d){
                            return d.kind + ':' + d.http_code + (d.saved_as?'(saved)':'(skip)') ;
                        }));
          statusEl.title = summary;
          console.log('sync-truora _debug:', JSON.stringify(j._debug, null, 2));
          // Also dump to a fixed pre on the page so the admin can copy
          // the entire JSON without DevTools.
          var dbg = document.getElementById('vk-sync-debug-dump');
          if (!dbg) {
            dbg = document.createElement('pre');
            dbg.id = 'vk-sync-debug-dump';
            dbg.style.cssText = 'position:fixed;bottom:10px;right:10px;width:560px;max-height:50vh;overflow:auto;background:#0f172a;color:#94a3b8;font-size:11px;padding:10px;border-radius:6px;box-shadow:0 4px 14px rgba(0,0,0,.3);white-space:pre-wrap;word-break:break-all;z-index:9999;';
            document.body.appendChild(dbg);
            var close = document.createElement('button');
            close.textContent = '✕';
            close.style.cssText = 'position:absolute;top:6px;right:8px;background:#1e293b;color:#fff;border:0;border-radius:4px;cursor:pointer;font-size:12px;padding:2px 6px;';
            close.onclick = function(){ dbg.remove(); };
            dbg.appendChild(close);
          }
          dbg.insertBefore(document.createTextNode(JSON.stringify(j, null, 2) + '\n\n'), dbg.firstChild);
        }
        // Only auto-reload when sync actually downloaded photos. Otherwise
        // keep the page so the operator can inspect Network/Console.
        if ((j.photos_count || 0) > 0) {
          setTimeout(function(){ location.reload(); }, 1500);
        }
      } else {
        statusEl.textContent = '✗ ' + (j.message || j.reason || 'falló');
        statusEl.style.color = '#b91c1c';
        btn.disabled = false;
      }
    }).catch(function(e){
      statusEl.textContent = '✗ ' + e.message;
      statusEl.style.color = '#b91c1c';
      btn.disabled = false;
    });
  });
});
</script>

<div style="margin-top:18px;font-size:11px;color:#94a3b8;">
  Tip: si necesitas el JSON crudo, agrega <code>?format=json</code> a la URL.
</div>
</body></html>
