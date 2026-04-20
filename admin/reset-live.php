<?php
/**
 * Admin-only standalone page for wiping all live-mode data.
 *
 * Not linked from the sidebar on purpose — access only via the direct URL
 * when you really mean to reset production data.
 *
 *   https://voltika.mx/admin/reset-live.php
 */
require_once __DIR__ . '/php/bootstrap.php';
adminRequireAuth(['admin']);
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Reset live data · Voltika admin</title>
<style>
  body{font-family:-apple-system,Segoe UI,Arial,sans-serif;background:#f5f7fa;color:#1a3a5c;max-width:680px;margin:40px auto;padding:0 20px;line-height:1.55}
  h1{font-size:20px;color:#b91c1c;margin:0 0 6px}
  .lead{font-size:13px;color:#555;margin:0 0 24px}
  .box{background:#fff;border:1px solid #e1e8ee;border-radius:10px;padding:22px 24px;box-shadow:0 2px 6px rgba(0,0,0,.04)}
  .warn{background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #dc2626;border-radius:8px;padding:12px 14px;margin:12px 0;font-size:13.5px;color:#7f1d1d}
  .keep{background:#ecfdf5;border:1px solid #a7f3d0;border-left:4px solid #22c55e;border-radius:8px;padding:12px 14px;margin:12px 0;font-size:13px;color:#166534}
  label{display:block;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;margin:16px 0 6px}
  input[type=text]{width:100%;padding:12px 14px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:14px;font-family:ui-monospace,Menlo,Consolas,monospace;box-sizing:border-box}
  input[type=text]:focus{border-color:#dc2626;outline:none}
  button{background:#dc2626;color:#fff;border:0;border-radius:8px;padding:12px 24px;font-size:14px;font-weight:700;cursor:pointer;margin-top:16px;width:100%}
  button[disabled]{background:#94a3b8;cursor:not-allowed}
  pre{background:#0f1722;color:#e2e8f0;border-radius:6px;padding:14px;font-size:12px;margin-top:14px;overflow:auto;white-space:pre-wrap}
  ul{margin:6px 0 0 18px;padding:0;font-size:12.5px;color:#555}
  ul li{margin:2px 0}
</style>
</head>
<body>
  <h1>⚠️ Reset live data</h1>
  <p class="lead">Herramienta administrativa destructiva — borra toda la data operacional del panel.</p>

  <div class="box">
    <div class="warn">
      <strong>Esto TRUNCAR:</strong>
      <ul>
        <li>Clientes · Suscripciones · Ciclos de pago · Transacciones</li>
        <li>Inventario · Checklists · Envíos · Actas · Incidencias</li>
        <li>Puntos · Comisiones</li>
        <li>Sesiones del portal · Logs · OTPs · Preferencias · Descargas</li>
      </ul>
    </div>

    <div class="keep">
      <strong>NO se tocará:</strong>
      <ul>
        <li>Cuentas de administradores (<code>usuarios</code>)</li>
        <li>Configuración (<code>app_config</code>)</li>
        <li>Estructura de tablas (schema)</li>
        <li>Datos de Stripe (customers / payment_intents / subscriptions) — resetea desde el dashboard de Stripe</li>
      </ul>
    </div>

    <label for="confirm">Escribe <code>BORRAR TODO</code> exacto para proceder</label>
    <input type="text" id="confirm" placeholder="BORRAR TODO" autocomplete="off" spellcheck="false">

    <button id="btn" disabled>Esperando confirmación…</button>

    <pre id="out" style="display:none"></pre>
  </div>

<script>
(function(){
  var $c = document.getElementById('confirm');
  var $b = document.getElementById('btn');
  var $o = document.getElementById('out');
  $c.addEventListener('input', function(){
    var ok = $c.value.trim() === 'BORRAR TODO';
    $b.disabled = !ok;
    $b.textContent = ok ? 'BORRAR TODO AHORA' : 'Esperando confirmación…';
  });
  $b.addEventListener('click', function(){
    if ($c.value.trim() !== 'BORRAR TODO') return;
    if (!confirm('¿Seguro? Esta acción NO se puede deshacer.')) return;
    $b.disabled = true;
    $b.textContent = 'Borrando…';
    fetch('php/system/reset-live.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ confirm: 'BORRAR TODO' })
    }).then(function(r){ return r.json(); }).then(function(j){
      $o.style.display = 'block';
      $o.textContent = JSON.stringify(j, null, 2);
      if (j && j.ok) {
        $b.textContent = '✓ Listo — recarga el panel';
      } else {
        $b.disabled = false;
        $b.textContent = 'Reintentar';
      }
    }).catch(function(e){
      $o.style.display = 'block';
      $o.textContent = 'Error: ' + e.message;
      $b.disabled = false;
      $b.textContent = 'Reintentar';
    });
  });
})();
</script>
</body>
</html>
