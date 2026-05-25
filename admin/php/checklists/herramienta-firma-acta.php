<?php
/**
 * Voltika Admin — Visual tool to generate direct ACTA signing links
 * (Round 80 helper, 2026-05-25).
 *
 * Visual wrapper around solicitar-firma-acta.php so admins don't have
 * to use DevTools console. Lists every moto that's eligible for direct
 * ACTA signing (has a customer assigned + acta not yet signed), and
 * provides a one-click "Generate signing link" button per row.
 *
 * The generated link can be copied with one click and pasted into
 * WhatsApp / SMS / email manually.
 *
 * URL: /admin/php/checklists/herramienta-firma-acta.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();

// ── Eligible motos: have a customer assigned and acta not yet signed ───
$motos = [];
try {
    $motos = $pdo->query("
        SELECT id, vin, vin_display, modelo, color, estado, punto_voltika_id,
               cliente_nombre, cliente_email, cliente_telefono,
               cliente_acta_firmada, cincel_acta_status
          FROM inventario_motos
         WHERE activo = 1
           AND cliente_nombre IS NOT NULL
           AND cliente_nombre <> ''
         ORDER BY (cliente_acta_firmada = 0 OR cliente_acta_firmada IS NULL) DESC,
                  fmod DESC
         LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $loadErr = $e->getMessage();
}

// Punto names for display
$puntoNames = [];
try {
    $rs = $pdo->query("SELECT id, nombre FROM puntos_voltika");
    foreach ($rs as $p) $puntoNames[(int)$p['id']] = $p['nombre'];
} catch (Throwable $e) {}

$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Voltika — Generar link de firma de ACTA</title>
<style>
* { box-sizing: border-box; }
body { font-family: system-ui, sans-serif; background: #f5f7fb; color: #0c2340;
       padding: 24px; max-width: 1100px; margin: 0 auto; line-height: 1.55; }
h1 { font-size: 22px; margin: 0 0 6px; }
h2 { font-size: 14px; color: #475569; margin: 22px 0 8px; text-transform: uppercase; letter-spacing: .4px; }
.muted { color: #94a3b8; font-size: 12px; }
.card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; margin-bottom: 12px; }
table { border-collapse: collapse; width: 100%; font-size: 12.5px; }
th, td { border-bottom: 1px solid #e2e8f0; padding: 8px 10px; text-align: left; vertical-align: top; }
th { background: #f1f5f9; color: #475569; font-weight: 600; font-size: 11.5px; text-transform: uppercase; letter-spacing: .4px; }
tr:hover td { background: #f8fafc; }
button.btn { display: inline-block; padding: 7px 14px; border-radius: 6px; font-size: 12.5px; font-weight: 700; cursor: pointer; border: 0; }
.btn-generate { background: #039fe1; color: #fff; }
.btn-generate:disabled { background: #cbd5e1; cursor: not-allowed; }
.btn-copy { background: #16a34a; color: #fff; margin-left: 4px; }
.btn-copy:disabled { background: #cbd5e1; }
.btn-whatsapp { background: #25d366; color: #fff; margin-left: 4px; text-decoration: none; display: inline-block; padding: 7px 14px; border-radius: 6px; font-size: 12.5px; font-weight: 700; }
code { background: #1e293b; color: #e2e8f0; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-family: ui-monospace, monospace; word-break: break-all; }
.banner { padding: 10px 12px; border-radius: 8px; font-size: 13px; margin-bottom: 10px; }
.banner-ok { background: #dcfce7; border: 1px solid #86efac; color: #166534; }
.banner-bad { background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; }
.banner-info { background: #dbeafe; border: 1px solid #93c5fd; color: #1e40af; }
.pill-signed { background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
.pill-pending { background: #fff7ed; color: #9a3412; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
.url-box { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px; font-size: 12px; word-break: break-all; margin-top: 6px; }
#manualForm { display: flex; gap: 8px; align-items: center; font-size: 13px; margin-top: 12px; }
#manualForm input { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; width: 150px; }
</style></head><body>

<h1>📝 Generar link de firma de ACTA DE ENTREGA</h1>
<div class="muted"><?= $h(date('Y-m-d H:i:s')) ?> · Servidor: <?= $h($_SERVER['HTTP_HOST'] ?? '') ?></div>

<h2>1. Cómo funciona</h2>
<div class="card" style="font-size: 13.5px;">
  El SPA del portal del cliente a veces se queda colgado en "Preparando documento…" por cache vieja en el iPhone Safari.
  Esta herramienta genera un link <strong>standalone</strong> (que no depende del SPA) que puedes enviar al cliente por WhatsApp.
  El cliente abre el link en su celular → ve los datos de su moto + canvas de firma → firma con el dedo → done.
  <br><br>
  El link expira en <strong>24 horas</strong> y solo se puede usar <strong>una vez</strong>.
</div>

<h2>2. Motos disponibles para firma</h2>

<?php if (!$motos): ?>
  <div class="card">
    <div class="banner banner-info">No hay motos con cliente asignado en este momento.</div>
  </div>
<?php else: ?>
  <div class="card">
    <table>
      <thead><tr>
        <th>id</th><th>VIN</th><th>Modelo · color</th><th>Estado</th>
        <th>Punto</th><th>Cliente</th><th>Acta</th><th>Acción</th>
      </tr></thead>
      <tbody>
      <?php foreach ($motos as $m):
        $firmada = !empty($m['cliente_acta_firmada']);
        $puntoName = !empty($m['punto_voltika_id']) ? ($puntoNames[(int)$m['punto_voltika_id']] ?? '#'.$m['punto_voltika_id']) : '—';
      ?>
        <tr>
          <td><code><?= (int)$m['id'] ?></code></td>
          <td><code style="font-size: 10px;"><?= $h($m['vin_display'] ?? $m['vin'] ?? '—') ?></code></td>
          <td><?= $h(($m['modelo'] ?? '') . ' · ' . ($m['color'] ?? '')) ?></td>
          <td><?= $h($m['estado'] ?? '—') ?></td>
          <td style="font-size: 11.5px;"><?= $h($puntoName) ?></td>
          <td style="font-size: 11.5px;">
            <strong><?= $h($m['cliente_nombre'] ?? '—') ?></strong>
            <?php if (!empty($m['cliente_telefono'])): ?>
              <br><span class="muted" style="font-size: 10.5px;"><?= $h($m['cliente_telefono']) ?></span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($firmada): ?>
              <span class="pill-signed">✓ Firmada</span>
            <?php else: ?>
              <span class="pill-pending">— Pendiente</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!$firmada): ?>
              <button class="btn btn-generate" data-moto="<?= (int)$m['id'] ?>" data-nombre="<?= $h($m['cliente_nombre'] ?? '') ?>" data-telefono="<?= $h($m['cliente_telefono'] ?? '') ?>">
                Generar link
              </button>
              <div class="result" id="result-<?= (int)$m['id'] ?>"></div>
            <?php else: ?>
              <span class="muted" style="font-size: 11px;">Ya firmada</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <form id="manualForm" onsubmit="return generateForId(event)">
      <label>O ingresa moto_id manualmente:</label>
      <input type="number" id="manualId" placeholder="ej: 143">
      <button class="btn btn-generate" type="submit">Generar</button>
      <div id="manualResult" style="margin-left: 10px;"></div>
    </form>
  </div>
<?php endif; ?>

<h2>3. Después de generar el link</h2>
<div class="card" style="font-size: 13px;">
  <ol style="margin: 0; padding-left: 18px; line-height: 1.7;">
    <li>Aparece el link y un botón <strong>Copiar</strong>. También un botón <strong>WhatsApp</strong> que abre WhatsApp Web con el mensaje pre-cargado.</li>
    <li>Mándale el link al cliente.</li>
    <li>El cliente abre el link en su celular (Safari / Chrome).</li>
    <li>Ve los datos de su moto + un recuadro para firmar con el dedo.</li>
    <li>Firma → en 10-20 segundos aparece "✓ Recibimos tu firma y sellamos tu ACTA con NOM-151".</li>
    <li>En el admin se actualiza solo: <code>cliente_acta_firmada=1</code>, sello NOM-151 aplicado al PDF.</li>
  </ol>
</div>

<script>
function showResult(containerId, j) {
  var div = document.getElementById(containerId);
  if (j && j.ok) {
    var url = j.signing_url;
    var copyText = j.copy_text || ('Voltika: firma tu entrega aquí: ' + url);
    var phone = j.telefono || '';
    var waUrl = 'https://wa.me/' + phone.replace(/\D/g,'') + '?text=' + encodeURIComponent(copyText);
    div.innerHTML =
      '<div class="banner banner-ok" style="margin-top:8px;">✓ Link generado, expira en 24 horas.</div>' +
      '<div class="url-box"><strong>URL:</strong><br>' + url + '</div>' +
      '<div style="margin-top:8px;">' +
        '<button class="btn btn-copy" onclick="copyText(this, ' + JSON.stringify(url).replace(/"/g,'&quot;') + ')">📋 Copiar URL</button>' +
        '<button class="btn btn-copy" onclick="copyText(this, ' + JSON.stringify(copyText).replace(/"/g,'&quot;') + ')">📋 Copiar mensaje completo</button>' +
        (phone ? ' <a class="btn-whatsapp" target="_blank" href="' + waUrl + '">📱 Abrir WhatsApp</a>' : '') +
      '</div>' +
      (j.email_err
        ? '<div class="muted" style="margin-top:6px;font-size:11.5px;">Email no enviado: ' + j.email_err + '</div>'
        : (j.sent_via && j.sent_via.length
            ? '<div class="muted" style="margin-top:6px;font-size:11.5px;">✓ Enviado por: ' + j.sent_via.join(', ') + '</div>'
            : ''));
  } else {
    div.innerHTML = '<div class="banner banner-bad" style="margin-top:8px;">⚠ ' + ((j && j.message) || 'Error desconocido') + '</div>';
  }
}

function copyText(btn, text) {
  navigator.clipboard.writeText(text).then(function(){
    var old = btn.textContent;
    btn.textContent = '✓ Copiado';
    setTimeout(function(){ btn.textContent = old; }, 1500);
  }).catch(function(err){
    alert('No se pudo copiar: ' + err.message);
  });
}

function callApi(motoId, phone, containerId, btn) {
  btn.disabled = true; btn.textContent = '...';
  fetch('/admin/php/checklists/solicitar-firma-acta.php', {
    method: 'POST', credentials: 'include',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ moto_id: motoId })
  })
  .then(function(r){ return r.json().catch(function(){ return { ok: false, message: 'Bad response' }; }); })
  .then(function(j){
    j.telefono = phone;
    showResult(containerId, j);
    btn.disabled = false; btn.textContent = 'Generar link';
  })
  .catch(function(err){
    showResult(containerId, { ok: false, message: 'Error de red: ' + err.message });
    btn.disabled = false; btn.textContent = 'Generar link';
  });
}

document.querySelectorAll('button.btn-generate[data-moto]').forEach(function(btn){
  btn.addEventListener('click', function(){
    var motoId = +btn.dataset.moto;
    var phone = btn.dataset.telefono || '';
    callApi(motoId, phone, 'result-' + motoId, btn);
  });
});

function generateForId(e){
  e.preventDefault();
  var id = +document.getElementById('manualId').value;
  if (!id) { alert('Ingresa un moto_id válido'); return false; }
  callApi(id, '', 'manualResult', e.target.querySelector('button.btn-generate'));
  return false;
}
</script>

</body></html>
