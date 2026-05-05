<?php
/**
 * Voltika — Post-payment digital signing for contado/MSI/SPEI/OXXO orders.
 *
 * Customer brief 2026-05-04 round 3: "in purchase done, there is no
 * contract signed by the client." Until now contado/MSI flows generated
 * an unsigned PDF at confirmar-orden.php and called it done. This page
 * closes that gap — after payment the customer receives a link by
 * email+SMS pointing here, signs on a canvas, and the backend stamps
 * the signature onto the contract PDF and persists everything to
 * firmas_contratos (same table the credit flow uses).
 *
 * Flow:
 *   /configurador/firmar-contrato-checkout.php?t=<id.expires.firma.hmac>
 *   1. Validate HMAC + 7-day window.
 *   2. Resolve pedido → load transacciones row.
 *   3. Render contract summary + canvas + checkbox + submit.
 *   4. JS POSTs base64 signature to firmar-contrato-checkout-submit.php.
 *   5. Submit returns redirect URL to a thank-you page.
 *
 * Token format: id.expires.firma.hmac  (id = transaccion id)
 */

declare(strict_types=1);

require_once __DIR__ . '/php/config.php';

$token = (string)($_GET['t'] ?? '');
$parts = explode('.', $token);
if (count($parts) !== 4) {
    http_response_code(400);
    echo "Enlace inválido.";
    exit;
}
[$txId, $expires, $action, $sig] = $parts;
$txId    = (int)$txId;
$expires = (int)$expires;

if ($action !== 'firma') {
    http_response_code(400);
    echo "Acción no válida.";
    exit;
}
if ($expires < time()) {
    http_response_code(410);
    echo "El enlace de firma expiró. Por favor solicita uno nuevo a soporte.";
    exit;
}

$secret = defined('VOLTIKA_RECOVER_SECRET')
    ? VOLTIKA_RECOVER_SECRET
    : (getenv('VOLTIKA_RECOVER_SECRET') ?: 'voltika_recover_2026_default');
$expected = hash_hmac('sha256', $txId . '.' . $expires . '.' . $action, $secret);
if (!hash_equals($expected, $sig)) {
    http_response_code(403);
    echo "Enlace inválido (firma no coincide).";
    exit;
}

// ── Load order + check it isn't already signed ─────────────────────────────
try {
    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT * FROM transacciones WHERE id = ? LIMIT 1");
    $stmt->execute([$txId]);
    $tx   = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tx) {
        http_response_code(404);
        echo "Pedido no encontrado.";
        exit;
    }

    $existing = $pdo->prepare("
        SELECT id, freg FROM firmas_contratos
        WHERE (telefono <> '' AND telefono = ?)
           OR (email    <> '' AND email    = ?)
        ORDER BY id DESC LIMIT 1");
    $existing->execute([$tx['telefono'] ?? '', $tx['email'] ?? '']);
    $alreadySigned = $existing->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo "Error interno.";
    exit;
}

$pedido = $tx['pedido_corto'] ?: ('VK-' . ($tx['pedido'] ?? $tx['id']));
$total  = (float)($tx['total'] ?? 0);
$tpago  = strtolower(trim($tx['tpago'] ?? ''));
$nombre = trim((string)($tx['nombre'] ?? ''));

?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Voltika — Firmar contrato</title>
<link rel="icon" type="image/svg+xml" href="img/favicon.svg">
<style>
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#F8FAFC;color:#1f2937;min-height:100vh;}
  .header{background:#1a3a5c;color:#fff;padding:18px;text-align:center;}
  .header img{height:32px;}
  .container{max-width:560px;margin:0 auto;padding:20px;}
  h1{font-size:22px;line-height:1.25;color:#1a3a5c;margin:8px 0 6px;}
  .lead{font-size:14px;color:#475569;margin-bottom:16px;line-height:1.5;}
  .summary{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px;margin-bottom:18px;}
  .summary-title{font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#64748b;font-weight:700;margin-bottom:12px;}
  .row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:14px;}
  .row:last-child{border-bottom:none;}
  .row-label{color:#475569;}
  .row-value{font-weight:700;color:#1f2937;text-align:right;}
  .canvas-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px;margin-bottom:16px;}
  .canvas-label{font-size:12px;color:#475569;margin-bottom:8px;font-weight:600;}
  .canvas-wrap{border:2px dashed #cbd5e1;border-radius:8px;background:#f8fafc;height:160px;position:relative;overflow:hidden;}
  canvas{display:block;width:100%;height:100%;cursor:crosshair;touch-action:none;}
  .canvas-hint{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);color:#cbd5e1;font-size:13px;pointer-events:none;}
  .canvas-hint.hidden{display:none;}
  .canvas-actions{display:flex;justify-content:space-between;align-items:center;margin-top:8px;font-size:12px;}
  .clear-btn{background:none;border:none;color:#039fe1;font-size:13px;font-weight:600;cursor:pointer;padding:0;}
  .legal{background:#fef3c7;border:1px solid #fde68a;color:#78350f;padding:12px 14px;border-radius:8px;font-size:12px;line-height:1.5;margin-bottom:14px;display:flex;gap:10px;align-items:flex-start;}
  .legal input{margin-top:2px;flex-shrink:0;}
  .submit-btn{display:block;width:100%;padding:16px;background:#1a6b1a;color:#fff;border:none;border-radius:12px;font-size:16px;font-weight:800;cursor:pointer;box-shadow:0 4px 14px rgba(26,107,26,.25);}
  .submit-btn:disabled{background:#cbd5e1;cursor:not-allowed;box-shadow:none;}
  .help{text-align:center;font-size:13px;color:#64748b;margin-top:18px;}
  .help a{color:#039fe1;text-decoration:none;font-weight:700;}
  .already-signed{background:#dcfce7;border:1px solid #86efac;color:#14532d;padding:18px;border-radius:12px;margin-bottom:16px;text-align:center;}
  .already-signed strong{display:block;font-size:16px;margin-bottom:4px;}
  #vk-result{margin-top:14px;font-size:13px;text-align:center;}
  #vk-result.ok{color:#15803d;}
  #vk-result.err{color:#b91c1c;}
</style>
</head><body>
<div class="header"><img src="img/voltika_logo_h_white.svg" alt="Voltika"></div>
<div class="container">
<?php if ($alreadySigned): ?>
  <div class="already-signed">
    <strong>✓ Ya firmaste este contrato</strong>
    <span>Firma registrada el <?= htmlspecialchars(substr($alreadySigned['freg'] ?? '', 0, 16)) ?>. No es necesario volver a firmar.</span>
  </div>
  <div class="help">¿Necesitas ayuda? Escríbenos por <a href="https://wa.me/525513416370">WhatsApp</a></div>
<?php else: ?>
  <h1>Firma tu contrato Voltika</h1>
  <p class="lead">Ya recibimos tu pago — solo falta tu firma para cerrar el contrato y liberar la entrega de tu Voltika.</p>

  <div class="summary">
    <div class="summary-title">Resumen del pedido</div>
    <div class="row"><span class="row-label">Pedido</span><span class="row-value"><?= htmlspecialchars($pedido) ?></span></div>
    <div class="row"><span class="row-label">Cliente</span><span class="row-value"><?= htmlspecialchars($nombre ?: '—') ?></span></div>
    <div class="row"><span class="row-label">Modelo</span><span class="row-value"><?= htmlspecialchars($tx['modelo'] ?? '—') ?></span></div>
    <div class="row"><span class="row-label">Color</span><span class="row-value"><?= htmlspecialchars($tx['color'] ?? '—') ?></span></div>
    <div class="row"><span class="row-label">Forma de pago</span><span class="row-value"><?= htmlspecialchars(strtoupper($tpago) ?: '—') ?></span></div>
    <div class="row"><span class="row-label">Total pagado</span><span class="row-value">$<?= number_format($total, 2, '.', ',') ?> MXN</span></div>
  </div>

  <div class="canvas-card">
    <div class="canvas-label">Firma con el dedo o el mouse en el recuadro</div>
    <div class="canvas-wrap">
      <canvas id="vk-firma" width="500" height="160"></canvas>
      <div class="canvas-hint" id="vk-firma-hint">Firma aquí ↗</div>
    </div>
    <div class="canvas-actions">
      <button type="button" class="clear-btn" id="vk-firma-clear">↺ Borrar y firmar de nuevo</button>
      <span style="color:#9ca3af;font-size:11px;">El trazo se guarda como evidencia con timestamp e IP.</span>
    </div>
  </div>

  <label class="legal">
    <input type="checkbox" id="vk-firma-accept">
    <span>He leído y acepto los términos del contrato Voltika para mi pedido <strong><?= htmlspecialchars($pedido) ?></strong>. Mi firma anterior y este aviso constituyen mi consentimiento expreso conforme a la NOM-151 y la Ley Federal de Protección al Consumidor.</span>
  </label>

  <button type="button" class="submit-btn" id="vk-firma-submit" disabled>Firmar y aceptar contrato</button>

  <div id="vk-result"></div>

  <div class="help">¿Necesitas ayuda? Escríbenos por <a href="https://wa.me/525513416370">WhatsApp</a></div>
<?php endif; ?>
</div>

<?php if (!$alreadySigned): ?>
<script>
(function(){
  var canvas = document.getElementById('vk-firma');
  var hint   = document.getElementById('vk-firma-hint');
  var clear  = document.getElementById('vk-firma-clear');
  var accept = document.getElementById('vk-firma-accept');
  var submit = document.getElementById('vk-firma-submit');
  var result = document.getElementById('vk-result');
  var hasInk = false;

  // Resize to physical pixels for crisp lines on retina displays.
  function fit(){
    var rect = canvas.getBoundingClientRect();
    var dpr = window.devicePixelRatio || 1;
    canvas.width  = Math.floor(rect.width  * dpr);
    canvas.height = Math.floor(rect.height * dpr);
    var ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);
    ctx.lineWidth   = 2.5;
    ctx.lineCap     = 'round';
    ctx.lineJoin    = 'round';
    ctx.strokeStyle = '#0f172a';
  }
  fit();
  window.addEventListener('resize', fit);

  var drawing = false, lastX = 0, lastY = 0;
  function pos(e){
    var rect = canvas.getBoundingClientRect();
    var t = e.touches ? e.touches[0] : e;
    return { x: t.clientX - rect.left, y: t.clientY - rect.top };
  }
  function start(e){
    e.preventDefault();
    drawing = true;
    var p = pos(e);
    lastX = p.x; lastY = p.y;
    if (!hasInk) { hasInk = true; hint.classList.add('hidden'); refreshSubmit(); }
  }
  function move(e){
    if (!drawing) return;
    e.preventDefault();
    var p = pos(e);
    var ctx = canvas.getContext('2d');
    ctx.beginPath();
    ctx.moveTo(lastX, lastY);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
    lastX = p.x; lastY = p.y;
  }
  function end(e){ e && e.preventDefault && e.preventDefault(); drawing = false; }

  canvas.addEventListener('mousedown',  start);
  canvas.addEventListener('mousemove',  move);
  canvas.addEventListener('mouseup',    end);
  canvas.addEventListener('mouseleave', end);
  canvas.addEventListener('touchstart', start, {passive:false});
  canvas.addEventListener('touchmove',  move,  {passive:false});
  canvas.addEventListener('touchend',   end,   {passive:false});

  clear.addEventListener('click', function(){
    var ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    hasInk = false;
    hint.classList.remove('hidden');
    refreshSubmit();
  });

  function refreshSubmit(){
    submit.disabled = !(hasInk && accept.checked);
  }
  accept.addEventListener('change', refreshSubmit);

  submit.addEventListener('click', function(){
    if (submit.disabled) return;
    submit.disabled = true; submit.textContent = 'Guardando firma...';
    result.className = ''; result.textContent = '';
    var dataUrl = canvas.toDataURL('image/png');
    fetch('/configurador/php/firmar-contrato-checkout-submit.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      credentials: 'include',
      body: JSON.stringify({
        token:        <?= json_encode($token) ?>,
        firma_base64: dataUrl
      })
    }).then(function(r){ return r.json(); }).then(function(j){
      if (j && j.ok) {
        result.className = 'ok';
        result.textContent = '✓ Firma guardada. Recibirás el contrato firmado por correo.';
        setTimeout(function(){ window.location.href = '/configurador/firmar-contrato-checkout.php?t=' + encodeURIComponent(<?= json_encode($token) ?>); }, 1500);
      } else {
        result.className = 'err';
        result.textContent = (j && j.error) || 'No se pudo guardar la firma.';
        submit.disabled = false; submit.textContent = 'Firmar y aceptar contrato';
      }
    }).catch(function(e){
      result.className = 'err';
      result.textContent = 'Error de conexión. Intenta de nuevo.';
      submit.disabled = false; submit.textContent = 'Firmar y aceptar contrato';
    });
  });
})();
</script>
<?php endif; ?>
</body></html>
