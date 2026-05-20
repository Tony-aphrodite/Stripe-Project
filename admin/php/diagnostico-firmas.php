<?php
/**
 * Voltika Admin — Round 59 (2026-05-19).
 *
 * Diagnostic + backfill tool for contract signatures.
 *
 * The contract PDF is generated ONCE at signing time. If the code had bugs
 * back then (Round 15 name duplication, Round 42 signature embedding,
 * etc.), the PDF on disk is permanently broken — running today's fixed
 * code on future contracts doesn't retroactively fix old PDFs.
 *
 * THIS TOOL: classifies every existing contract into:
 *   ✅ OK              — PDF on disk + has signature in DB + post-fix era
 *   🔄 RECOVERABLE     — firma_base64 in DB, just needs PDF regeneration
 *   ⚠ PENDING REAL    — customer truly never signed (firma_base64 NULL)
 *   ❌ DATA-LOST      — has firmas_contratos row but firma_base64 invalid/empty
 *
 * Per-row "Regenerar" button calls generar-contrato-pdf.php via internal
 * HTTP POST with the saved firma_base64, then updates the contrato_pdf_path
 * column so the Sales panel marks it "Firmado".
 *
 * URL:
 *   https://voltika.mx/admin/php/diagnostico-firmas.php?key=voltika_diag_2026
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

foreach ([__DIR__ . '/../../configurador/php/config.php',
          __DIR__ . '/../../configurador_prueba_test/php/config.php'] as $cfg) {
    if (is_file($cfg)) { @require_once $cfg; break; }
}

$expected = 'voltika_diag_2026';
$key = $_GET['key'] ?? $_POST['key'] ?? '';
if (!hash_equals($expected, (string)$key)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Acceso denegado. Usa ?key=<secret>";
    exit;
}

$pdo = getDB();

// Round 42 deploy date — anything before this is "legacy era".
$round42Date = '2026-05-16 00:00:00';

// ─────────────────────────────────────────────────────────────────────────
// POST: regenerate one contract via internal HTTP to generar-contrato-pdf.php
// ─────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'regenerar_one')) {
    // Suppress PHP notices/warnings so they never corrupt our JSON body.
    // The previous "Unexpected end of JSON input" came from stray output here.
    @ini_set('display_errors', '0');
    error_reporting(0);
    // Allow up to 120s for the internal cURL — PDF generation can take 15-30s.
    @set_time_limit(120);
    // Buffer everything; we discard whatever leaks before our JSON.
    ob_start();
    header('Content-Type: application/json; charset=utf-8');

    $respond = function(array $payload) {
        // Throw away any unintended output (warnings, BOM, etc.)
        while (ob_get_level() > 0) { @ob_end_clean(); }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    };

    try {
        $txId = (int)($_POST['tx_id'] ?? 0);
        if (!$txId) { $respond(['ok' => false, 'error' => 'tx_id requerido']); }

        $tq = $pdo->prepare("SELECT t.id AS tx_id, t.pedido, t.nombre, t.email, t.telefono, t.modelo, t.color,
                                    t.tpago, t.total, t.ciudad, t.estado, t.cp, t.curp, t.domicilio,
                                    t.contrato_pdf_path
                               FROM transacciones t WHERE t.id = ?");
        $tq->execute([$txId]);
        $tx = $tq->fetch(PDO::FETCH_ASSOC);
        if (!$tx) { $respond(['ok' => false, 'error' => 'Transacción no encontrada', 'tx_id' => $txId]); }

        $fq = $pdo->prepare("SELECT firma_base64 FROM firmas_contratos
                              WHERE email = ? OR telefono = ?
                              ORDER BY freg DESC LIMIT 1");
        $fq->execute([$tx['email'], $tx['telefono']]);
        $firma = $fq->fetchColumn();
        if (!$firma) {
            $respond(['ok' => false, 'error' => 'No hay firma_base64 en firmas_contratos para este cliente', 'tx_id' => $txId]);
        }
        if (strpos($firma, 'data:image/png;base64,') !== 0) {
            $firma = 'data:image/png;base64,' . preg_replace('/^data:image\/[^;]+;base64,/', '', $firma);
        }

        $credito = [];
        if (in_array($tx['tpago'], ['credito','enganche','parcial','msi'], true)) {
            $credito = [
                'enganchePct'     => 0.30,
                'plazoMeses'      => 12,
                'pagoSemanal'     => 0,
                'montoFinanciado' => (float)($tx['total'] ?? 0) * 0.70,
            ];
        }
        $payload = [
            'nombre'     => $tx['nombre'],
            'email'      => $tx['email'],
            'telefono'   => $tx['telefono'],
            'modelo'     => $tx['modelo'],
            'color'      => $tx['color'],
            'metodoPago' => $tx['tpago']  ?: 'contado',
            'ciudad'     => $tx['ciudad'] ?: '',
            'estado'     => $tx['estado'] ?: '',
            'cp'         => $tx['cp']     ?: '',
            'curp'       => $tx['curp']   ?: '',
            'domicilio'  => $tx['domicilio'] ?: '',
            'total'      => (float)($tx['total'] ?? 0),
            'credito'    => $credito,
            'firmaData'  => $firma,
            'contrato'   => true,
        ];

        $host = $_SERVER['HTTP_HOST'] ?? 'voltika.mx';
        $url  = 'https://' . $host . '/configurador/php/generar-contrato-pdf.php';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $code === 0) {
            $respond([
                'ok'       => false,
                'error'    => 'cURL falló al llamar generar-contrato-pdf.php',
                'tx_id'    => $txId,
                'url'      => $url,
                'curl_err' => $err ?: '(empty)',
                'http'     => $code,
            ]);
        }

        $parsed = is_string($resp) ? json_decode($resp, true) : null;

        if ($code < 200 || $code >= 300 || !is_array($parsed) || empty($parsed['ok'])) {
            $respond([
                'ok'             => false,
                'error'          => 'generar-contrato-pdf.php devolvió respuesta inesperada',
                'tx_id'          => $txId,
                'http'           => $code,
                'curl_err'       => $err ?: null,
                'response_short' => substr((string)$resp, 0, 800),
                'parsed_keys'    => is_array($parsed) ? array_keys($parsed) : null,
            ]);
        }

        if (function_exists('adminLog')) {
            try {
                adminLog('contrato_regenerado_backfill', [
                    'tx_id'    => $txId,
                    'pedido'   => $tx['pedido'],
                    'cliente'  => $tx['nombre'],
                    'pdf_path' => $parsed['pdf_path'] ?? null,
                ]);
            } catch (Throwable $logErr) { /* ignore */ }
        }

        $respond([
            'ok'       => true,
            'tx_id'    => $txId,
            'pdf_path' => $parsed['pdf_path'] ?? null,
            'http'     => $code,
            'message'  => 'Contrato regenerado con firma + Round 15/42 aplicados',
        ]);
    } catch (Throwable $e) {
        $respond([
            'ok'    => false,
            'error' => 'Excepción PHP: ' . $e->getMessage(),
            'file'  => basename($e->getFile()) . ':' . $e->getLine(),
        ]);
    }
}

// ─────────────────────────────────────────────────────────────────────────
// GET: dashboard with classification of every contract
// ─────────────────────────────────────────────────────────────────────────

// Pull transactions that should have contracts (credito, enganche, contado).
$rows = [];
try {
    $st = $pdo->query("
        SELECT t.id AS tx_id, t.pedido, t.nombre, t.email, t.telefono, t.modelo, t.color,
               t.tpago, t.pago_estado, t.total, t.freg AS tx_freg,
               t.contrato_pdf_path,
               (SELECT fc.id           FROM firmas_contratos fc
                 WHERE fc.email = t.email OR fc.telefono = t.telefono
                 ORDER BY fc.freg DESC LIMIT 1) AS firma_id,
               (SELECT fc.freg         FROM firmas_contratos fc
                 WHERE fc.email = t.email OR fc.telefono = t.telefono
                 ORDER BY fc.freg DESC LIMIT 1) AS firma_freg,
               (SELECT LENGTH(fc.firma_base64) FROM firmas_contratos fc
                 WHERE fc.email = t.email OR fc.telefono = t.telefono
                 ORDER BY fc.freg DESC LIMIT 1) AS firma_base64_len
          FROM transacciones t
         WHERE t.tpago IN ('credito','enganche','parcial','contado','msi','tarjeta','spei','oxxo')
         ORDER BY t.id DESC LIMIT 100
    ");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $rowsErr = $e->getMessage(); }

// Possible PDF directories.
$pdfDirs = array_filter([
    realpath(__DIR__ . '/../../configurador/php/contratos'),
    realpath(__DIR__ . '/../../configurador/php/uploads/contratos'),
    realpath(__DIR__ . '/../../configurador_prueba_test/php/contratos'),
    realpath(__DIR__ . '/../../configurador_prueba_test/php/uploads/contratos'),
]);

// Classify each row.
function classify(array $r, array $pdfDirs, string $round42Date): array {
    $hasFirma   = !empty($r['firma_id']);
    $firmaLen   = (int)($r['firma_base64_len'] ?? 0);
    $firmaValid = $hasFirma && $firmaLen > 1000;     // arbitrary minimum for a real PNG
    $pdfPath    = $r['contrato_pdf_path'] ?? null;
    $pdfOnDisk  = false;
    $pdfSize    = 0;
    if ($pdfPath) {
        $base = basename($pdfPath);
        foreach ($pdfDirs as $d) {
            $f = $d . '/' . $base;
            if (is_file($f)) {
                $pdfOnDisk = true;
                $pdfSize   = filesize($f) ?: 0;
                break;
            }
        }
    }
    $isLegacy = !empty($r['tx_freg']) && $r['tx_freg'] < $round42Date;

    if (!$hasFirma) {
        return ['status' => 'PENDING_REAL', 'label' => '⚠ Cliente no ha firmado',
                'recoverable' => false, 'class' => 'warn', 'is_legacy' => $isLegacy];
    }
    if (!$firmaValid) {
        return ['status' => 'DATA_LOST', 'label' => '❌ firma_base64 vacía/corrupta',
                'recoverable' => false, 'class' => 'bad', 'is_legacy' => $isLegacy];
    }
    if ($pdfOnDisk && !$isLegacy) {
        return ['status' => 'OK', 'label' => '✅ OK (post-Round-42)',
                'recoverable' => false, 'class' => 'ok', 'is_legacy' => false,
                'pdf_size' => $pdfSize];
    }
    return ['status' => 'RECOVERABLE', 'label' => '🔄 Regenerable',
            'recoverable' => true, 'class' => 'warn', 'is_legacy' => $isLegacy,
            'pdf_size' => $pdfSize];
}

$counts = ['OK' => 0, 'RECOVERABLE' => 0, 'PENDING_REAL' => 0, 'DATA_LOST' => 0];
$classifications = [];
foreach ($rows as $r) {
    $c = classify($r, $pdfDirs, $round42Date);
    $classifications[$r['tx_id']] = $c;
    $counts[$c['status']]++;
}

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Voltika — Diagnóstico de firmas en contratos</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fb;color:#0c2340;padding:24px;max-width:1280px;margin:0 auto;line-height:1.5;}
h1{font-size:22px;margin:0 0 4px;} h2{font-size:14px;color:#475569;margin:24px 0 10px;text-transform:uppercase;letter-spacing:.4px;}
.muted{color:#94a3b8;font-size:11.5px;} .ok{color:#16a34a;font-weight:700;} .bad{color:#dc2626;font-weight:700;} .warn{color:#d97706;font-weight:700;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:14px;}
table{width:100%;border-collapse:collapse;font-size:12px;}
th{text-align:left;padding:7px 5px;border-bottom:2px solid #cbd5e1;color:#475569;font-weight:700;font-size:11px;}
td{padding:6px 5px;border-bottom:1px solid #f1f5f9;vertical-align:top;}
code{background:#f1f5f9;color:#1e293b;padding:1px 5px;border-radius:3px;font-size:11px;font-family:ui-monospace,monospace;word-break:break-all;}
.banner{padding:10px 12px;border-radius:8px;font-size:13px;margin:8px 0;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.banner-bad{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
.banner-warn{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;}
button{background:#039fe1;color:#fff;border:0;padding:6px 12px;border-radius:5px;font-size:11.5px;cursor:pointer;font-weight:600;}
button:disabled{background:#94a3b8;cursor:not-allowed;}
.summary{display:flex;gap:14px;flex-wrap:wrap;}
.summary-card{flex:1;min-width:160px;padding:14px;border-radius:10px;text-align:center;}
.summary-card .n{font-size:32px;font-weight:800;}
.s-ok{background:#dcfce7;color:#166534;}
.s-recov{background:#fff7ed;color:#9a3412;}
.s-pending{background:#fef3c7;color:#854d0e;}
.s-lost{background:#fee2e2;color:#991b1b;}
</style></head><body>

<h1>📝 Diagnóstico de firmas en contratos</h1>
<div class="muted">Round 59 · <?= date('Y-m-d H:i:s') ?> · <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '') ?> · Round 42 corte: <?= $round42Date ?></div>

<h2>1. Resumen — últimas 100 transacciones</h2>
<div class="summary">
  <div class="summary-card s-ok">
    <div class="n"><?= $counts['OK'] ?></div>
    <div>✅ OK<br><span style="font-size:11px;opacity:.7">PDF con firma + post Round 42</span></div>
  </div>
  <div class="summary-card s-recov">
    <div class="n"><?= $counts['RECOVERABLE'] ?></div>
    <div>🔄 Regenerables<br><span style="font-size:11px;opacity:.7">firma_base64 en DB, falta solo rehacer el PDF</span></div>
  </div>
  <div class="summary-card s-pending">
    <div class="n"><?= $counts['PENDING_REAL'] ?></div>
    <div>⚠ Pendientes reales<br><span style="font-size:11px;opacity:.7">Cliente nunca firmó — falta re-solicitar</span></div>
  </div>
  <div class="summary-card s-lost">
    <div class="n"><?= $counts['DATA_LOST'] ?></div>
    <div>❌ Datos perdidos<br><span style="font-size:11px;opacity:.7">firma_base64 inválido — re-firma requerida</span></div>
  </div>
</div>

<?php if ($counts['RECOVERABLE'] > 0): ?>
  <div class="banner banner-warn" style="margin-top:14px;">
    <strong>👉 Acción:</strong> hay <?= $counts['RECOVERABLE'] ?> contratos regenerables. Haz click en
    <strong>"Regenerar todos los regenerables"</strong> abajo o usa el botón por fila.
    Una vez regenerados, el panel marcará "Firmado" y el PDF mostrará la firma autógrafa.
  </div>
<?php endif; ?>

<h2>2. Detalle por transacción</h2>
<div class="card">
  <table>
    <thead>
      <tr>
        <th>TX</th><th>Pedido</th><th>Cliente</th><th>Tpago</th><th>Pago</th>
        <th>Fecha tx</th><th>Firma DB</th><th>firma_base64 bytes</th>
        <th>PDF disk</th><th>Estado</th><th>Acción</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
      $c = $classifications[$r['tx_id']];
      $era = $c['is_legacy'] ? '<span class="muted">legacy</span>' : '';
    ?>
      <tr>
        <td><strong><?= (int)$r['tx_id'] ?></strong></td>
        <td><?= htmlspecialchars((string)($r['pedido'] ?? '—')) ?></td>
        <td><?= htmlspecialchars((string)($r['nombre'] ?? '—')) ?></td>
        <td><?= htmlspecialchars((string)($r['tpago'] ?? '—')) ?></td>
        <td><?= htmlspecialchars((string)($r['pago_estado'] ?? '—')) ?></td>
        <td class="muted" style="white-space:nowrap;"><?= htmlspecialchars(substr((string)($r['tx_freg'] ?? '—'), 0, 16)) ?><br><?= $era ?></td>
        <td><?= !empty($r['firma_id']) ? '<span class="ok">✓</span>' : '<span class="muted">no</span>' ?></td>
        <td><?= !empty($r['firma_base64_len']) ? number_format((int)$r['firma_base64_len']) : '—' ?></td>
        <td>
          <?php if (!empty($r['contrato_pdf_path'])): ?>
            <code style="font-size:10px;"><?= htmlspecialchars(basename((string)$r['contrato_pdf_path'])) ?></code>
            <?php if (!empty($c['pdf_size'])): ?><br><span class="muted"><?= number_format((int)$c['pdf_size']) ?> b</span><?php endif; ?>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td><span class="<?= $c['class'] ?>"><?= htmlspecialchars($c['label']) ?></span></td>
        <td>
          <?php if ($c['recoverable']): ?>
            <button class="rg-btn" data-tx="<?= (int)$r['tx_id'] ?>">Regenerar</button>
            <span class="rg-status" data-tx="<?= (int)$r['tx_id'] ?>" style="font-size:10.5px;display:block;margin-top:3px;"></span>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($counts['RECOVERABLE'] > 0): ?>
<h2>3. Regenerar TODOS los regenerables (uno a uno)</h2>
<div class="card">
  <button id="bulk-btn" style="background:#16a34a;font-size:14px;padding:10px 18px;">
    🔄 Regenerar todos los <?= $counts['RECOVERABLE'] ?> contratos regenerables
  </button>
  <div id="bulk-status" style="margin-top:10px;"></div>
  <div id="bulk-log" style="margin-top:8px;max-height:240px;overflow-y:auto;font-size:11.5px;font-family:ui-monospace,monospace;"></div>
</div>
<?php endif; ?>

<h2>Próximos pasos</h2>
<div class="card" style="font-size:13.5px;">
  <ul>
    <li><strong>✅ OK</strong> — nada que hacer. Estos contratos ya tienen firma visible y PDF correcto.</li>
    <li><strong>🔄 Regenerables</strong> — usa los botones. Cada uno reproduce el PDF con código actual (Round 15 + Round 42), embebiendo la firma autógrafa que ya está guardada en <code>firmas_contratos.firma_base64</code>.</li>
    <li><strong>⚠ Pendientes reales</strong> — el cliente debe firmar. Envía el link de firma (Admin → Sales → Sin Firma → Reenviar link).</li>
    <li><strong>❌ Datos perdidos</strong> — firma_base64 está corrupto. Re-solicita firma como en el caso "Pendiente real".</li>
  </ul>
</div>

<script>
function regenerarOne(txId, statusEl, btnEl) {
  return new Promise(function(resolve){
    if (statusEl) { statusEl.innerHTML = '⏳'; statusEl.style.color = '#1e40af'; }
    if (btnEl)    btnEl.disabled = true;
    var fd = new FormData();
    fd.append('key', <?= json_encode($expected) ?>);
    fd.append('action', 'regenerar_one');
    fd.append('tx_id', txId);
    fetch(location.pathname, { method: 'POST', credentials: 'include', body: fd })
      .then(function(r){
        return r.text().then(function(text){ return { http: r.status, body: text }; });
      })
      .then(function(raw){
        var j = null, parseErr = null;
        try { j = JSON.parse(raw.body); } catch(e){ parseErr = e.message; }
        if (j && j.ok) {
          if (statusEl) { statusEl.innerHTML = '<span style="color:#15803d">✓ OK</span>'; }
          if (btnEl)    btnEl.textContent = '✓ Regenerado';
          resolve({ tx: txId, ok: true, pdf: j.pdf_path });
          return;
        }
        // Build a helpful error: server error JSON, parse error, or raw body sample.
        var msg, fullDetail;
        if (j && !j.ok) {
          msg = (j.error || 'sin detalle').substring(0, 60);
          fullDetail = 'HTTP ' + raw.http + ' · ' + JSON.stringify(j, null, 2);
        } else {
          // Body is not JSON — show first part to diagnose.
          msg = 'respuesta no-JSON (HTTP ' + raw.http + ')';
          fullDetail = 'HTTP ' + raw.http + ' · parse error: ' + (parseErr || 'n/a')
                     + '\n\nBody (first 800 chars):\n' + (raw.body || '(empty)').substring(0, 800);
        }
        if (statusEl) {
          statusEl.innerHTML = '<span style="color:#b91c1c">✗ ' + msg.replace(/[<>&]/g, function(c){
            return {'<':'&lt;','>':'&gt;','&':'&amp;'}[c]; }) + '</span>'
            + ' <a href="#" onclick="event.preventDefault(); var n=this.nextElementSibling; n.style.display=n.style.display===\'block\'?\'none\':\'block\';" style="font-size:10px;">▼ detalles</a>'
            + '<pre style="display:none;background:#fef2f2;border:1px solid #fecaca;padding:6px;font-size:10px;white-space:pre-wrap;margin-top:4px;max-height:300px;overflow:auto;">'
            + fullDetail.replace(/[<>&]/g, function(c){ return {'<':'&lt;','>':'&gt;','&':'&amp;'}[c]; })
            + '</pre>';
        }
        if (btnEl) btnEl.disabled = false;
        resolve({ tx: txId, ok: false, error: msg, detail: fullDetail });
      })
      .catch(function(e){
        if (statusEl) { statusEl.innerHTML = '<span style="color:#b91c1c">✗ fetch error: ' + e.message + '</span>'; }
        if (btnEl) btnEl.disabled = false;
        resolve({ tx: txId, ok: false, error: 'fetch: ' + e.message });
      });
  });
}

document.querySelectorAll('.rg-btn').forEach(function(btn){
  btn.addEventListener('click', function(){
    var txId = btn.getAttribute('data-tx');
    var statusEl = document.querySelector('.rg-status[data-tx="' + txId + '"]');
    regenerarOne(txId, statusEl, btn);
  });
});

var bulkBtn = document.getElementById('bulk-btn');
if (bulkBtn) {
  bulkBtn.addEventListener('click', async function(){
    if (!confirm('Regenerar todos los contratos regenerables? Esto puede tomar varios minutos.')) return;
    bulkBtn.disabled = true;
    var bulkStatus = document.getElementById('bulk-status');
    var bulkLog    = document.getElementById('bulk-log');
    var rgButtons  = Array.from(document.querySelectorAll('.rg-btn:not(:disabled)'));
    var total = rgButtons.length;
    var done = 0, okCount = 0, failCount = 0;
    bulkStatus.innerHTML = '⏳ Procesando 0/' + total + '...';
    for (var i = 0; i < rgButtons.length; i++) {
      var btn = rgButtons[i];
      var txId = btn.getAttribute('data-tx');
      var statusEl = document.querySelector('.rg-status[data-tx="' + txId + '"]');
      var res = await regenerarOne(txId, statusEl, btn);
      done++;
      if (res.ok) okCount++; else failCount++;
      bulkStatus.innerHTML = '⏳ Procesando ' + done + '/' + total + ' (✓ ' + okCount + ' · ✗ ' + failCount + ')';
      var line = (res.ok ? '✓ ' : '✗ ') + 'TX ' + res.tx + (res.error ? ' — ' + res.error : ' — ' + (res.pdf || ''));
      var el = document.createElement('div');
      el.textContent = line;
      el.style.color = res.ok ? '#15803d' : '#b91c1c';
      bulkLog.appendChild(el);
    }
    bulkStatus.innerHTML = '<strong style="color:' + (failCount === 0 ? '#15803d' : '#d97706') + '">'
                        + '✓ Listo: ' + okCount + ' regenerados, ' + failCount + ' fallaron.</strong>';
    setTimeout(function(){ location.reload(); }, 3000);
  });
}
</script>

</body></html>
