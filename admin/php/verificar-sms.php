<?php
/**
 * Voltika Admin — SMS deliverability deep-diagnostic.
 *
 * Customer brief 2026-05-13 (Óscar, 13th round — "OTP never arrives"
 * even though admin_log shows SMS sent OK with HTTP 200): SMS gateway
 * acceptance ≠ carrier delivery. This page surfaces the FULL response
 * body from the last 10 SMS attempts AND offers a "Send test SMS"
 * action so the admin can verify deliverability live with a known
 * phone number (their own).
 *
 * URLs:
 *   GET  /admin/php/verificar-sms.php
 *        → diagnostic dashboard with last attempts + test form
 *   POST /admin/php/verificar-sms.php
 *        Body: { action: "send_test", telefono: "5512345678" }
 *        → sends a real test SMS, returns the full gateway response
 */
require_once __DIR__ . '/bootstrap.php';
$adminId = adminRequireAuth(['admin']);
$pdo = getDB();

// Load SMS config
foreach ([__DIR__ . '/../../configurador/php/config.php',
          __DIR__ . '/../../configurador_prueba_test/php/config.php'] as $cfg) {
    if (is_file($cfg)) { @require_once $cfg; break; }
}
$smsKey = defined('SMSMASIVOS_API_KEY') ? SMSMASIVOS_API_KEY : (getenv('SMSMASIVOS_API_KEY') ?: '');

// ── POST: send a test SMS ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = adminJsonIn();
    $action = (string)($body['action'] ?? '');

    if ($action === 'send_test') {
        $rawTel = trim((string)($body['telefono'] ?? ''));
        $tel = preg_replace('/\D/', '', $rawTel);
        $telOrig = $tel;
        $telWarn = null;
        if (strlen($tel) === 10) { $tel = '52' . $tel; }
        elseif (strlen($tel) === 12 && strpos($tel, '52') === 0) {}
        elseif (strlen($tel) === 11 && strpos($tel, '521') === 0) { $tel = '52' . substr($tel, 3); }
        else { $telWarn = 'Formato no estándar — esperado 10 dígitos México'; }

        if (!$smsKey) {
            echo json_encode([
                'ok' => false,
                'error' => 'SMSMASIVOS_API_KEY no está configurado en config.php',
            ]);
            exit;
        }

        $codigo = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $msg = "Voltika: Prueba de entrega SMS. Código de prueba: {$codigo}. (Este SMS no es real, sólo diagnóstico admin).";

        // Round 47 (2026-05-16): apikey/form-urlencoded auth scheme.
        // SMSmasivos returns HTTP 200 + body {"success":false,...} on auth
        // failure, so we MUST parse the body — HTTP code alone is misleading.
        $telNacional = $tel;
        if (strlen($telNacional) === 12 && strpos($telNacional, '52') === 0)  $telNacional = substr($telNacional, 2);
        if (strlen($telNacional) === 11 && strpos($telNacional, '521') === 0) $telNacional = substr($telNacional, 3);
        $ch = curl_init('https://api.smsmasivos.com.mx/sms/send');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'apikey: ' . $smsKey,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_POSTFIELDS => http_build_query([
                'message'      => $msg,
                'numbers'      => $telNacional,
                'country_code' => '52',
            ]),
            CURLOPT_TIMEOUT => 10,
        ]);
        $startMs = microtime(true);
        $res     = curl_exec($ch);
        $tookMs  = round((microtime(true) - $startMs) * 1000);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch) ?: null;
        curl_close($ch);

        $parsedBody = null;
        if (is_string($res)) {
            $tmp = json_decode($res, true);
            if (is_array($tmp)) $parsedBody = $tmp;
        }
        $bodyOk = is_array($parsedBody) && !empty($parsedBody['success']);

        adminLog('verificar_sms_test', [
            'telefono'   => $tel,
            'http'       => $httpCode,
            'took_ms'    => $tookMs,
            'curl_err'   => $curlErr,
            'body_ok'    => $bodyOk,
            'response'   => is_string($res) ? substr($res, 0, 1000) : null,
        ]);

        echo json_encode([
            'ok'            => $httpCode >= 200 && $httpCode < 300 && $bodyOk,
            'http_code'     => $httpCode,
            'tel_input'     => $rawTel,
            'tel_digits'    => $telOrig,
            'tel_normalized'=> $tel,
            'tel_warn'      => $telWarn,
            'took_ms'       => $tookMs,
            'curl_error'    => $curlErr,
            'response_raw'  => is_string($res) ? substr($res, 0, 1500) : null,
            'response_parsed' => $parsedBody,
            'test_code'     => $codigo,
            'message_sent'  => $msg,
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'acción desconocida']);
    exit;
}

// ── GET: render dashboard with last SMS attempts + test form ────────────
$recent = [];
try {
    $stmt = $pdo->query("
        SELECT id, usuario_id, accion, detalle, freg
        FROM admin_log
        WHERE accion IN ('punto:entrega_otp_enviado',
                         'verificar_sms_test',
                         'admin_otp_enviado')
        ORDER BY id DESC LIMIT 15
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $d = json_decode((string)$row['detalle'], true) ?: [];
        $recent[] = [
            'id'      => $row['id'],
            'accion'  => $row['accion'],
            'freg'    => $row['freg'],
            'detalle' => $d,
        ];
    }
} catch (Throwable $e) {}

header('Content-Type: text/html; charset=UTF-8');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Voltika — Diagnóstico SMS</title>
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f5f7fb;color:#0c2340;padding:24px;max-width:1080px;margin:0 auto;}
  h1{font-size:22px;margin:0 0 6px;}
  h2{font-size:14px;color:#475569;margin:24px 0 10px;text-transform:uppercase;letter-spacing:.4px;}
  .sub{color:#64748b;font-size:13px;margin-bottom:18px;}
  .card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin-bottom:14px;}
  .alert{padding:12px 14px;border-radius:8px;font-size:13px;margin-bottom:14px;}
  .alert-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
  .alert-warn{background:#fef3c7;border:1px solid #fde68a;color:#92400e;}
  .alert-err{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
  input{padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;}
  .btn{background:#039fe1;color:#fff;border:0;padding:10px 18px;border-radius:6px;font-weight:600;cursor:pointer;font-size:13px;}
  .btn:hover{background:#0286c2;} .btn:disabled{opacity:.55;cursor:not-allowed;}
  table{width:100%;border-collapse:collapse;margin-top:8px;}
  th,td{padding:8px 10px;text-align:left;font-size:12px;border-bottom:1px solid #f1f5f9;vertical-align:top;}
  th{color:#475569;font-weight:700;text-transform:uppercase;font-size:10.5px;background:#f8fafc;}
  code{background:#1e293b;color:#e2e8f0;padding:1px 6px;border-radius:3px;font-size:11px;}
  pre{background:#1e293b;color:#e2e8f0;padding:10px;border-radius:6px;font-size:11px;overflow:auto;white-space:pre-wrap;word-break:break-all;}
  .pill{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10.5px;font-weight:700;}
  .pill-ok{background:#dcfce7;color:#166534;} .pill-warn{background:#fef3c7;color:#92400e;} .pill-err{background:#fee2e2;color:#991b1b;}
</style></head><body>

<h1>📲 Diagnóstico avanzado de entrega SMS</h1>
<div class="sub">Inspecciona la respuesta cruda del proveedor + envía un SMS de prueba a un número conocido.</div>

<h2>1. Estado de configuración</h2>
<div class="card">
  <?php if ($smsKey): ?>
    <div class="alert alert-ok">✓ <code>SMSMASIVOS_API_KEY</code> está configurado (longitud: <?= strlen($smsKey) ?> caracteres).</div>
  <?php else: ?>
    <div class="alert alert-err">✗ <code>SMSMASIVOS_API_KEY</code> NO está configurado. Sin esto, ningún SMS se envía. Agrega la línea <code>define('SMSMASIVOS_API_KEY', '…');</code> en <code>configurador/php/config.php</code>.</div>
  <?php endif; ?>
</div>

<h2>2. Enviar SMS de prueba a tu propio número</h2>
<div class="card">
  <div class="sub" style="margin-bottom:10px;">
    Si "✓ HTTP 200" pero el SMS no llega a tu propio celular, el problema está en SMSmasivos / carrier — no en el código.
    El SMS de prueba tiene texto identificable para que sepas que es de admin.
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
    <div>
      <label style="display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:4px;">TELÉFONO (10 dígitos México)</label>
      <input type="tel" id="smsTel" placeholder="5512345678" style="width:200px;">
    </div>
    <button class="btn" id="btnSendTest">📲 Enviar SMS de prueba</button>
    <span id="testStatus" style="font-size:13px;color:#64748b;"></span>
  </div>
  <div id="testResult" style="margin-top:14px;"></div>
</div>

<h2>3. Últimos 15 intentos de SMS (con respuesta cruda)</h2>
<div class="card">
  <?php if (!$recent): ?>
    <div class="sub">Sin intentos todavía. Envía un SMS de prueba arriba para empezar a ver datos.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Fecha</th><th>Tipo</th><th>Tel</th><th>HTTP</th><th>Skip</th><th>Resultado</th><th>Detalle</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $i => $a): ?>
        <?php
          $d = $a['detalle'];
          $http = $d['http'] ?? $d['sms_http'] ?? null;
          $tel  = $d['telefono'] ?? $d['tel_normalized'] ?? '—';
          $skip = $d['sms_skip'] ?? null;
          $resp = $d['response'] ?? $d['sms_response'] ?? null;
          $ok   = !empty($d['sms_ok']) || (is_numeric($http) && $http >= 200 && $http < 300);
          $err  = $d['curl_err'] ?? $d['sms_error'] ?? null;
        ?>
        <tr>
          <td><?= htmlspecialchars($a['freg']) ?></td>
          <td><code><?= htmlspecialchars(str_replace('punto:', '', $a['accion'])) ?></code></td>
          <td><code><?= htmlspecialchars((string)$tel) ?></code></td>
          <td><?= $http ? '<code>'.htmlspecialchars((string)$http).'</code>' : '—' ?></td>
          <td><?= $skip ? '<span class="pill pill-warn">'.htmlspecialchars($skip).'</span>' : '—' ?></td>
          <td><?= $ok ? '<span class="pill pill-ok">OK</span>' : '<span class="pill pill-err">FALLO</span>' ?></td>
          <td>
            <?php if ($err): ?>
              <div style="color:#dc2626;font-size:11px;"><?= htmlspecialchars($err) ?></div>
            <?php endif; ?>
            <?php if ($resp): ?>
              <details><summary style="cursor:pointer;font-size:11px;color:#64748b;">Response body</summary>
                <pre><?= htmlspecialchars((string)$resp) ?></pre>
              </details>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<h2>4. ¿Qué hacer si el SMS no llega?</h2>
<div class="card">
  <ol style="margin:0;padding-left:20px;font-size:13.5px;line-height:1.7;">
    <li><strong>Prueba con tu propio celular</strong> usando el formulario de arriba. Si NO llega, llama a SMSmasivos: <a href="https://www.smsmasivos.com.mx" target="_blank">www.smsmasivos.com.mx</a> y pregunta por el estado de la cuenta y los SMS bloqueados.</li>
    <li><strong>Revisa el "response body"</strong> en la tabla — SMSmasivos a veces devuelve <code>{"status":"queued"}</code> con HTTP 200 cuando el carrier rechaza después. Si ves <code>blocked</code> o <code>delivery_failed</code> ahí está la pista.</li>
    <li><strong>Verifica que el número esté bien</strong> — confirma con el cliente que el teléfono guardado en su pedido coincide con el suyo (10 dígitos, sin prefijo +52).</li>
    <li><strong>Habilita WhatsApp/email</strong> como respaldo — <code>voltikaNotify()</code> ya está disponible. Si el cliente prefiere WhatsApp, podemos priorizar ese canal.</li>
    <li><strong>Lee el código manualmente</strong> en última instancia — el panel del punto siempre muestra el OTP al operador cuando todos los canales fallan (test_code en la respuesta).</li>
  </ol>
</div>

<script>
document.getElementById('btnSendTest').addEventListener('click', function(){
  var tel = document.getElementById('smsTel').value.trim();
  if (!tel || tel.length < 10) { alert('Ingresa un teléfono válido de 10 dígitos.'); return; }
  var btn = this; btn.disabled = true;
  var stat = document.getElementById('testStatus');
  var res = document.getElementById('testResult');
  stat.textContent = 'Enviando...';
  res.innerHTML = '';
  fetch('/admin/php/verificar-sms.php', {
    method: 'POST',
    credentials: 'include',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action: 'send_test', telefono: tel})
  }).then(function(r){ return r.json(); }).then(function(j){
    btn.disabled = false;
    stat.textContent = '';
    var ok = !!j.ok;
    var html = '<div class="alert ' + (ok ? 'alert-ok' : 'alert-err') + '">' +
      (ok ? '✓ SMSmasivos aceptó el envío' : '✗ SMSmasivos rechazó el envío') +
      ' — HTTP ' + (j.http_code || '—') + ' — duró ' + (j.took_ms || '?') + 'ms</div>';
    html += '<table>';
    html += '<tr><td><strong>Tel ingresado</strong></td><td><code>' + (j.tel_input||'') + '</code></td></tr>';
    html += '<tr><td><strong>Tel normalizado</strong></td><td><code>' + (j.tel_normalized||'') + '</code></td></tr>';
    if (j.tel_warn) html += '<tr><td><strong>Aviso</strong></td><td style="color:#d97706;">' + j.tel_warn + '</td></tr>';
    html += '<tr><td><strong>Código generado</strong></td><td><code>' + (j.test_code||'') + '</code></td></tr>';
    if (j.curl_error) html += '<tr><td><strong>Error red</strong></td><td style="color:#dc2626;">' + j.curl_error + '</td></tr>';
    html += '<tr><td><strong>Response body cruda</strong></td><td><pre>' + (j.response_raw||'(vacío)') + '</pre></td></tr>';
    html += '</table>';
    html += '<div style="margin-top:10px;font-size:13px;color:#475569;">' +
      '<strong>Próximo paso:</strong> revisa tu celular ' + (j.tel_normalized||'') + ' por los próximos 60 segundos. ' +
      'Si llega el SMS con código <code>' + (j.test_code||'') + '</code>, el sistema funciona y el problema con clientes específicos es del número guardado o de su carrier. ' +
      'Si NO llega, contacta a SMSmasivos con HTTP ' + (j.http_code||'—') + ' y este response body.' +
      '</div>';
    res.innerHTML = html;
  }).catch(function(e){
    btn.disabled = false;
    stat.textContent = '';
    res.innerHTML = '<div class="alert alert-err">✗ Error de red: ' + e.message + '</div>';
  });
});
</script>

</body></html>
