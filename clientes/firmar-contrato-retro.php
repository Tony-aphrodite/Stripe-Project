<?php
/**
 * Voltika Customer — Retroactive contract signing page (Round 75, 2026-05-25).
 *
 * Public, tokenized signing page. The customer opens a one-time URL emailed
 * to them by the admin (or pasted into WhatsApp), draws their signature on
 * a canvas, and submits. The server-side handler (firmar-contrato-retro-
 * guardar.php) saves the signature, regenerates the contract PDF with the
 * autograph embedded, and applies a fresh Cincel NOM-151 timestamp.
 *
 * URL:    GET /clientes/firmar-contrato-retro.php?token=<40hex>
 * Submit: POST /clientes/php/firmar-contrato-retro-guardar.php
 *
 * Why standalone (not part of the SPA): the customer doesn't need to log
 * in — the token IS the auth. We render plain HTML so a simple link tap
 * from email / WhatsApp works on any mobile browser.
 */

declare(strict_types=1);

require_once __DIR__ . '/php/bootstrap.php';

$token = trim((string)($_GET['token'] ?? ''));
$errMsg = null;
$req = null;
$txn = null;

if ($token === '' || !preg_match('/^[a-f0-9]{40}$/i', $token)) {
    $errMsg = 'El enlace no es válido. Verifica que esté completo.';
} else {
    try {
        $pdo = getDB();

        // Lookup the token + transaccion in one go. Schema-tolerant on
        // missing tables (e.g. fresh install) so we surface a clear error
        // instead of a 500.
        try {
            $st = $pdo->prepare("SELECT r.*, t.pedido, t.nombre, t.email, t.telefono,
                                        t.modelo, t.precio_unitario, t.total,
                                        t.contrato_pdf_path, t.contrato_aceptado_at
                                 FROM firma_contrato_requests r
                                 JOIN transacciones t ON t.id = r.transaccion_id
                                 WHERE r.token = ? LIMIT 1");
            $st->execute([$token]);
            $req = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            $errMsg = 'El sistema de firmas no está disponible. Contacta a Voltika.';
        }

        if (!$req) {
            $errMsg = $errMsg ?: 'El enlace no se reconoce. Quizás expiró o ya fue usado.';
        } elseif ((string)$req['estado'] === 'signed') {
            $errMsg = 'Este contrato ya fue firmado. Si tienes dudas, contacta a Voltika.';
        } elseif ((string)$req['estado'] === 'expired') {
            $errMsg = 'Este enlace expiró. Pide a Voltika que te envíe uno nuevo.';
        } elseif ((int)$req['expires_at'] < time()) {
            // Token still 'pending' in DB but the clock ran out — flip to expired.
            try {
                $pdo->prepare("UPDATE firma_contrato_requests SET estado='expired' WHERE id=?")
                    ->execute([(int)$req['id']]);
            } catch (Throwable $e) {}
            $errMsg = 'Este enlace expiró (vigencia de 48 horas). Pide a Voltika que te envíe uno nuevo.';
        } else {
            $txn = $req;
        }
    } catch (Throwable $e) {
        error_log('firmar-contrato-retro lookup: ' . $e->getMessage());
        $errMsg = 'Error temporal. Intenta de nuevo en un minuto.';
    }
}

// Light header / styling — keep it inline so this file is fully self-contained.
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>Voltika — Firmar contrato</title>
<style>
* { box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
       background: linear-gradient(135deg, #f0f4f8 0%, #e5edf5 100%);
       color: #0c2340; margin: 0; padding: 0; min-height: 100vh; }
.wrap { max-width: 540px; margin: 0 auto; padding: 22px 18px 40px; }
.logo { font-size: 22px; font-weight: 800; color: #0c2340; letter-spacing: -0.5px;
        text-align: center; margin: 8px 0 22px; }
.card { background: #fff; border-radius: 14px; padding: 22px 20px;
        box-shadow: 0 6px 24px rgba(12, 35, 64, 0.08); margin-bottom: 14px; }
h1 { font-size: 22px; margin: 0 0 6px; color: #0c2340; }
.muted { color: #64748b; font-size: 13.5px; line-height: 1.55; }
.label { font-size: 12px; color: #64748b; text-transform: uppercase;
         letter-spacing: 0.6px; margin-bottom: 3px; }
.value { font-size: 15px; font-weight: 600; color: #0c2340; margin-bottom: 14px; }
.summary-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 18px;
                margin-top: 16px; }
.banner { padding: 14px 16px; border-radius: 10px; font-size: 14px;
          margin-bottom: 16px; line-height: 1.5; }
.banner-err { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
.banner-info { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
.banner-ok { background: #f0fdf4; border: 1px solid #bbf7d0; color: #14532d;
             font-weight: 600; }
canvas#sig { width: 100%; height: 220px; background: #fff;
             border: 2px dashed #94a3b8; border-radius: 10px; touch-action: none;
             cursor: crosshair; }
.canvas-actions { display: flex; gap: 10px; margin-top: 10px; }
.btn { display: inline-block; padding: 12px 18px; border-radius: 10px;
       font-weight: 700; font-size: 15px; cursor: pointer; border: 0;
       text-decoration: none; text-align: center; }
.btn-primary { background: #039fe1; color: #fff; flex: 2; }
.btn-primary:disabled { background: #cbd5e1; cursor: not-allowed; }
.btn-ghost { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; flex: 1; }
.contract-link { display: inline-block; background: #e0f2fe; color: #075985;
                 padding: 10px 14px; border-radius: 8px; font-size: 14px;
                 text-decoration: none; font-weight: 600; margin-top: 4px; }
.footer { text-align: center; font-size: 11.5px; color: #94a3b8;
          margin-top: 30px; line-height: 1.6; }
#status { margin-top: 14px; font-size: 13.5px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="logo">⚡ Voltika</div>

  <?php if ($errMsg): ?>
    <div class="card">
      <div class="banner banner-err">❌ <?= htmlspecialchars($errMsg) ?></div>
      <p class="muted">Si necesitas ayuda, escribe a
        <a href="mailto:ventas@voltika.mx" style="color:#039fe1;">ventas@voltika.mx</a>
        y te ayudamos a generar un nuevo enlace.</p>
    </div>
  <?php else: ?>
    <div class="card">
      <h1>Firma tu contrato</h1>
      <p class="muted">Hola <strong><?= htmlspecialchars((string)$txn['nombre'] ?: 'cliente') ?></strong>,
        para completar la documentación de tu compra Voltika necesitamos tu
        firma autógrafa. Toma menos de 1 minuto: dibuja tu firma con el dedo
        abajo y toca <strong>Firmar</strong>. Tu firma quedará sellada con
        <strong>NOM-151</strong> a través de Cincel — tiene plena validez legal.</p>

      <div class="summary-grid">
        <div>
          <div class="label">Pedido</div>
          <div class="value"><?= htmlspecialchars((string)$txn['pedido'] ?: '—') ?></div>
        </div>
        <div>
          <div class="label">Modelo</div>
          <div class="value"><?= htmlspecialchars((string)$txn['modelo'] ?: '—') ?></div>
        </div>
        <?php if (!empty($txn['total'])): ?>
        <div>
          <div class="label">Total</div>
          <div class="value">$<?= number_format((float)$txn['total'], 2) ?> MXN</div>
        </div>
        <?php endif; ?>
        <?php if (!empty($txn['contrato_aceptado_at'])): ?>
        <div>
          <div class="label">Aceptado</div>
          <div class="value" style="font-size:13px;"><?= htmlspecialchars((string)$txn['contrato_aceptado_at']) ?></div>
        </div>
        <?php endif; ?>
      </div>

      <?php if (!empty($txn['contrato_pdf_path'])): ?>
        <?php
          // Build a public-ish URL for read-only preview. The /configurador/ root
          // serves contracts/contado/<file>.pdf directly. If the stored path is
          // already a /tmp absolute, we won't render the preview link (file is
          // not web-accessible).
          $rel = (string)$txn['contrato_pdf_path'];
          $previewHref = null;
          if (strpos($rel, '/') !== 0 && strpos($rel, 'contratos/') === 0) {
              $previewHref = '/configurador/' . $rel;
          }
        ?>
        <?php if ($previewHref): ?>
          <p style="margin-top:6px;">
            <a class="contract-link" href="<?= htmlspecialchars($previewHref) ?>" target="_blank" rel="noopener">
              📄 Ver mi contrato actual
            </a>
          </p>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="label">Firma aquí</div>
      <p class="muted" style="margin:4px 0 12px;">Dibuja tu firma con el dedo en el recuadro.</p>
      <canvas id="sig" width="900" height="440"></canvas>
      <div class="canvas-actions">
        <button type="button" id="clearBtn" class="btn btn-ghost">Borrar</button>
        <button type="button" id="signBtn" class="btn btn-primary" disabled>Firmar</button>
      </div>
      <div id="status"></div>
    </div>
  <?php endif; ?>

  <div class="footer">
    Voltika · MTECH GEARS, S.A. de C.V.<br>
    Tu firma será sellada con NOM-151 a través de Cincel para validez legal.
  </div>
</div>

<?php if (!$errMsg): ?>
<script>
(function(){
  var token = <?= json_encode($token) ?>;
  var canvas = document.getElementById('sig');
  var ctx = canvas.getContext('2d');
  var clearBtn = document.getElementById('clearBtn');
  var signBtn = document.getElementById('signBtn');
  var status = document.getElementById('status');

  // High-DPI: scale the drawing surface so the canvas isn't blurry on retina.
  function resizeCanvas() {
    var rect = canvas.getBoundingClientRect();
    var dpr = window.devicePixelRatio || 1;
    canvas.width  = Math.round(rect.width * dpr);
    canvas.height = Math.round(rect.height * dpr);
    ctx.scale(dpr, dpr);
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, rect.width, rect.height);
    ctx.lineWidth = 2.4;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#0c2340';
  }
  resizeCanvas();
  window.addEventListener('resize', function(){
    // Preserve current strokes when resizing: snapshot → resize → restore.
    var snap = canvas.toDataURL();
    var img = new Image();
    img.onload = function(){ resizeCanvas();
      var rect = canvas.getBoundingClientRect();
      ctx.drawImage(img, 0, 0, rect.width, rect.height);
    };
    img.src = snap;
  });

  var drawing = false;
  var hasInk = false;
  function getXY(e){
    var rect = canvas.getBoundingClientRect();
    var t = e.touches ? e.touches[0] : e;
    return { x: t.clientX - rect.left, y: t.clientY - rect.top };
  }
  function start(e){ e.preventDefault(); drawing = true; var p = getXY(e);
    ctx.beginPath(); ctx.moveTo(p.x, p.y); }
  function move(e){ if (!drawing) return; e.preventDefault();
    var p = getXY(e); ctx.lineTo(p.x, p.y); ctx.stroke();
    hasInk = true; signBtn.disabled = false; }
  function end(e){ drawing = false; }

  canvas.addEventListener('mousedown', start);
  canvas.addEventListener('mousemove', move);
  canvas.addEventListener('mouseup', end);
  canvas.addEventListener('mouseleave', end);
  canvas.addEventListener('touchstart', start, { passive: false });
  canvas.addEventListener('touchmove',  move,  { passive: false });
  canvas.addEventListener('touchend',   end);

  clearBtn.addEventListener('click', function(){
    var rect = canvas.getBoundingClientRect();
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, rect.width, rect.height);
    hasInk = false; signBtn.disabled = true;
    status.textContent = '';
  });

  signBtn.addEventListener('click', function(){
    if (!hasInk) { status.innerHTML = '<span style="color:#991b1b;">⚠ Dibuja tu firma primero.</span>'; return; }
    signBtn.disabled = true;
    clearBtn.disabled = true;
    status.innerHTML = '<span style="color:#1e40af;">⏳ Guardando tu firma y sellando con NOM-151… (puede tardar 10-20 segundos)</span>';

    var dataUrl = canvas.toDataURL('image/png');

    fetch('/clientes/php/firmar-contrato-retro-guardar.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: token, signature_data: dataUrl })
    })
    .then(function(r){ return r.json().catch(function(){ return { ok: false, error: 'bad_response' }; }); })
    .then(function(j){
      if (j && j.ok) {
        status.innerHTML = '<div class="banner banner-ok" style="margin-top:14px;">'
                         + '✓ ¡Listo! Tu firma quedó sellada con NOM-151. '
                         + 'Hemos actualizado tu contrato. Ya puedes cerrar esta pestaña.'
                         + (j.new_pdf_url
                              ? '<br><br><a class="contract-link" href="' + j.new_pdf_url + '" target="_blank">📄 Descargar contrato firmado</a>'
                              : '')
                         + '</div>';
        signBtn.style.display = 'none';
        clearBtn.style.display = 'none';
      } else {
        status.innerHTML = '<div class="banner banner-err">⚠ No pudimos guardar tu firma: '
                         + ((j && j.message) || 'error desconocido')
                         + '</div>';
        signBtn.disabled = false;
        clearBtn.disabled = false;
      }
    })
    .catch(function(err){
      status.innerHTML = '<div class="banner banner-err">⚠ Error de red. Verifica tu conexión y vuelve a intentar.</div>';
      signBtn.disabled = false;
      clearBtn.disabled = false;
    });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
