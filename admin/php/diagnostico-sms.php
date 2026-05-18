<?php
/**
 * Voltika Admin — Round 46C SMS diagnostic (2026-05-16).
 *
 * Standalone diagnostic for "OTP never arrives" incidents. Mirrors
 * /admin/php/verificar-sms.php but bypasses adminRequireAuth() via the
 * same shared-secret key as diagnostico-rol.php — the admin currently
 * can't log in, so we can't require admin auth for the diagnostic.
 *
 * URL:
 *   https://voltika.mx/admin/php/diagnostico-sms.php?key=voltika_diag_2026
 *
 * Reports:
 *   1. SMSMASIVOS_API_KEY presence (length, last 6 chars — not the full key)
 *   2. SMSmasivos account info (calls /sms/balance if available)
 *   3. Test SMS form: send a real SMS to a phone you control
 *   4. Last 20 OTP send attempts from admin_log
 *   5. Last 20 SMS-related entries from notificaciones_log
 *
 * Delete this file once SMS is verified working.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// Load config so SMSMASIVOS_API_KEY constant is defined.
foreach ([__DIR__ . '/../../configurador/php/config.php',
          __DIR__ . '/../../configurador_prueba_test/php/config.php'] as $cfg) {
    if (is_file($cfg)) { @require_once $cfg; break; }
}
$smsKey = defined('SMSMASIVOS_API_KEY') ? SMSMASIVOS_API_KEY : (getenv('SMSMASIVOS_API_KEY') ?: '');

$expected = 'voltika_diag_2026';
$key = $_GET['key'] ?? $_POST['key'] ?? '';
if (!hash_equals($expected, (string)$key)) {
    // Allow POST without ?key= if body has it (form-style).
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($expected, (string)($_POST['key'] ?? ''))) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Acceso denegado. Usa ?key=<secret>";
        exit;
    }
}

$pdo = getDB();

// ─────────────────────────────────────────────────────────────────────────
// POST: send a test SMS
// ─────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'send_test')) {
    header('Content-Type: application/json; charset=utf-8');

    $rawTel = trim((string)($_POST['telefono'] ?? ''));
    $tel = preg_replace('/\D/', '', $rawTel);
    $telWarn = null;
    if (strlen($tel) === 10) { $tel = '52' . $tel; }
    elseif (strlen($tel) === 12 && strpos($tel, '52') === 0) {}
    elseif (strlen($tel) === 11 && strpos($tel, '521') === 0) { $tel = '52' . substr($tel, 3); }
    else { $telWarn = 'Formato no estándar — esperado 10 dígitos México. Recibido: ' . $rawTel; }

    if (!$smsKey) {
        echo json_encode(['ok' => false, 'error' => 'SMSMASIVOS_API_KEY no está definido en config.php / env']);
        exit;
    }

    // Round 55 (2026-05-18): SMSmasivos response warning "test_message_detected"
    // showed that Mexican carriers silently filter SMS containing "Prueba" /
    // "test". Diagnostic now uses real OTP-style text so the test SMS actually
    // reaches the operator's phone.
    $codigo = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $msg = "Voltika: Tu codigo de verificacion es {$codigo}. Vigente 10 minutos.";

    // Round 47 (2026-05-16): apikey + form-urlencoded matches the real
    // SMSmasivos auth scheme. Same fix as voltika-notify.php; see there.
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
        CURLOPT_TIMEOUT => 12,
    ]);
    $startMs = microtime(true);
    $res = curl_exec($ch);
    $tookMs = round((microtime(true) - $startMs) * 1000);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch) ?: null;
    curl_close($ch);

    $parsedBody = null;
    if (is_string($res)) {
        $tmp = json_decode($res, true);
        if (is_array($tmp)) $parsedBody = $tmp;
    }

    // Audit so we can correlate later.
    try {
        $pdo->prepare("INSERT INTO admin_log (usuario_id, accion, detalle, ip)
                       VALUES (?, 'diagnostico_sms_test', ?, ?)")
            ->execute([
                0, // no admin session — diag tool
                json_encode([
                    'tel_normalized'=> $tel,
                    'tel_raw'       => $rawTel,
                    'http'          => $httpCode,
                    'curl_err'      => $curlErr,
                    'took_ms'       => $tookMs,
                    'response'      => substr((string)$res, 0, 1000),
                    'tel_warn'      => $telWarn,
                ], JSON_UNESCAPED_UNICODE),
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
    } catch (Throwable $e) {}

    // Round 47: parse body.success — SMSmasivos returns HTTP 200 + body
    // {"success":false,...} on auth failure; HTTP code alone is misleading.
    $bodyOk = is_array($parsedBody) && !empty($parsedBody['success']);
    echo json_encode([
        'ok'             => ($httpCode >= 200 && $httpCode < 300) && !$curlErr && $bodyOk,
        'http'           => $httpCode,
        'body_ok'        => $bodyOk,
        'tel_warn'       => $telWarn,
        'tel_normalized' => $tel,
        'codigo_prueba'  => $codigo,
        'curl_err'       => $curlErr,
        'took_ms'        => $tookMs,
        'response_body'  => $res,
        'parsed_body'    => $parsedBody,
        'gateway_says_ok'=> $parsedBody['success'] ?? null,
        'gateway_status' => $parsedBody['status']  ?? null,
        'gateway_code'   => $parsedBody['code']    ?? null,
        'hint'           => !$bodyOk && is_array($parsedBody)
                              ? (($parsedBody['code'] ?? '') === 'auth_01'
                                  ? 'auth_01 = API key inválida o headers/body en formato incorrecto. Verifica que el código use apikey: + form-urlencoded (no Bearer/JSON).'
                                  : ('Gateway rechazó: ' . ($parsedBody['message'] ?? '—')))
                           : ($httpCode === 401 ? 'API key inválida o revocada.'
                           : ($httpCode === 402 ? 'Cuenta sin créditos.'
                           : ($httpCode === 429 ? 'Rate limit.'
                           : (($httpCode === 200 && empty($parsedBody)) ? 'Respuesta vacía/extraña.' : null)))),
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// GET: dashboard
// ─────────────────────────────────────────────────────────────────────────

// Try /sms/balance for account info (SMSmasivos may or may not expose this).
$balanceInfo = null;
$balanceErr  = null;
if ($smsKey) {
    $ch = curl_init('https://api.smsmasivos.com.mx/sms/balance');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $smsKey],
        CURLOPT_TIMEOUT => 6,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $balanceInfo = ['http' => $code, 'body' => $resp, 'curl_err' => $err ?: null];
}

// Recent OTP delivery attempts from admin_log.
$otpAttempts = [];
try {
    $st = $pdo->prepare(
        "SELECT freg, usuario_id, accion, detalle, ip
           FROM admin_log
          WHERE accion IN ('punto:entrega_otp_enviado','entrega_otp_revelado',
                           'verificar_sms_test','diagnostico_sms_test')
          ORDER BY freg DESC LIMIT 20"
    );
    $st->execute();
    $otpAttempts = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $otpErr = $e->getMessage(); }

// Recent notificaciones_log for SMS canal.
$notifLog = [];
try {
    $st = $pdo->prepare(
        "SELECT freg, cliente_id, tipo, canal, destino, status, error, LEFT(mensaje, 80) as msg_short
           FROM notificaciones_log
          WHERE canal = 'sms'
          ORDER BY freg DESC LIMIT 20"
    );
    $st->execute();
    $notifLog = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* tabla puede no existir */ }

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Voltika — Diagnóstico SMS</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fb;color:#0c2340;padding:24px;max-width:1080px;margin:0 auto;line-height:1.45;}
h1{font-size:22px;margin:0 0 4px;}
h2{font-size:14px;color:#475569;margin:24px 0 10px;text-transform:uppercase;letter-spacing:.4px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:14px;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th{text-align:left;padding:8px 6px;border-bottom:2px solid #cbd5e1;color:#475569;font-weight:700;font-size:12px;}
td{padding:8px 6px;border-bottom:1px solid #f1f5f9;vertical-align:top;}
code{background:#f1f5f9;color:#1e293b;padding:1px 5px;border-radius:3px;font-size:11.5px;font-family:ui-monospace,monospace;word-break:break-all;}
.ok{color:#16a34a;font-weight:700;}
.bad{color:#dc2626;font-weight:700;}
.warn{color:#d97706;font-weight:700;}
.muted{color:#94a3b8;font-size:11.5px;}
.banner{padding:12px 14px;border-radius:8px;font-size:13px;margin:12px 0;}
.banner-warn{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;}
.banner-bad{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.kv td:first-child{width:200px;color:#64748b;font-weight:700;font-size:12px;}
input,button{font-family:inherit;}
input[type=tel]{padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;width:240px;}
button{background:#039fe1;color:#fff;border:0;padding:9px 18px;border-radius:6px;font-weight:700;cursor:pointer;font-size:13px;}
pre{background:#0b1322;color:#e2e8f0;padding:10px;border-radius:6px;font-size:11.5px;overflow-x:auto;max-height:280px;}
</style></head><body>

<h1>📩 Diagnóstico SMS — Voltika</h1>
<div class="muted">Round 46C · servidor <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '') ?> · generado <?= date('Y-m-d H:i:s') ?></div>

<h2>1. Configuración SMSmasivos</h2>
<div class="card">
  <table class="kv">
    <tr>
      <td>API key definida</td>
      <td>
        <?php if ($smsKey): ?>
          <span class="ok">✓ Sí</span> · <code>longitud <?= strlen($smsKey) ?></code> · <code>termina en ...<?= htmlspecialchars(substr($smsKey, -6)) ?></code>
        <?php else: ?>
          <span class="bad">✗ No</span> — define <code>SMSMASIVOS_API_KEY</code> en config.php o env. SIN ESTO, NINGÚN SMS PUEDE SALIR.
        <?php endif; ?>
      </td>
    </tr>
    <tr>
      <td>Endpoint balance</td>
      <td>
        <?php if ($balanceInfo): ?>
          HTTP <strong><?= htmlspecialchars((string)$balanceInfo['http']) ?></strong>
          <?php if ($balanceInfo['http'] === 200): ?>
            <pre style="margin-top:6px;"><?= htmlspecialchars(substr((string)$balanceInfo['body'], 0, 600)) ?></pre>
          <?php elseif ($balanceInfo['http'] === 401): ?>
            <div class="banner banner-bad" style="margin-top:6px;">✗ <strong>API key inválida o revocada</strong> (HTTP 401). Verifica con SMSmasivos.</div>
          <?php elseif ($balanceInfo['http'] === 404): ?>
            <div class="muted">El endpoint /sms/balance no existe — normal si SMSmasivos no lo expone. No bloquea funcionalidad.</div>
          <?php else: ?>
            <pre style="margin-top:6px;"><?= htmlspecialchars(substr((string)$balanceInfo['body'], 0, 600)) ?></pre>
          <?php endif; ?>
          <?php if (!empty($balanceInfo['curl_err'])): ?>
            <div class="bad">curl_error: <?= htmlspecialchars($balanceInfo['curl_err']) ?></div>
          <?php endif; ?>
        <?php else: ?>
          <span class="muted">(no consultado — falta API key)</span>
        <?php endif; ?>
      </td>
    </tr>
  </table>
</div>

<h2>2. Enviar SMS de prueba</h2>
<div class="card">
  <p style="margin:0 0 12px;color:#475569;font-size:13px;">
    Escribe TU número de teléfono (con o sin lada 52). Se envía un SMS real con un código de prueba. Si llega → SMSmasivos funciona. Si no → revisa la respuesta del gateway abajo.
  </p>
  <form id="testForm" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
    <input type="hidden" name="key" value="<?= htmlspecialchars($expected) ?>">
    <input type="hidden" name="action" value="send_test">
    <input type="tel" id="telPrueba" name="telefono" placeholder="5512345678" required>
    <button type="submit">📤 Enviar SMS de prueba</button>
    <span id="testStatus" style="font-size:12px;color:#475569;"></span>
  </form>
  <div id="testResult" style="margin-top:14px;"></div>
</div>

<h2>3. Últimos 20 intentos de envío de OTP (admin_log)</h2>
<div class="card">
  <?php if (empty($otpAttempts)): ?>
    <div class="muted">Sin registros recientes de OTP/SMS.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Fecha</th><th>Acción</th><th>Detalle (200 chars)</th><th>IP</th></tr></thead>
      <tbody>
        <?php foreach ($otpAttempts as $r): ?>
          <tr>
            <td class="muted" style="white-space:nowrap;"><?= htmlspecialchars((string)$r['freg']) ?></td>
            <td><strong><?= htmlspecialchars((string)$r['accion']) ?></strong></td>
            <td><code><?= htmlspecialchars(substr((string)$r['detalle'], 0, 200)) ?></code></td>
            <td class="muted"><?= htmlspecialchars((string)($r['ip'] ?? '—')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<h2>4. Últimos 20 registros canal SMS (notificaciones_log)</h2>
<div class="card">
  <?php if (empty($notifLog)): ?>
    <div class="muted">Sin registros. (La tabla notificaciones_log puede no existir aún o no haber tenido tráfico SMS.)</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Fecha</th><th>Cliente</th><th>Tipo</th><th>Destino</th><th>Status</th><th>Error</th><th>Mensaje</th></tr></thead>
      <tbody>
        <?php foreach ($notifLog as $r): ?>
          <tr>
            <td class="muted" style="white-space:nowrap;"><?= htmlspecialchars((string)$r['freg']) ?></td>
            <td><?= htmlspecialchars((string)($r['cliente_id'] ?? '—')) ?></td>
            <td><?= htmlspecialchars((string)$r['tipo']) ?></td>
            <td><code><?= htmlspecialchars((string)($r['destino'] ?? '—')) ?></code></td>
            <td>
              <?php
                $st = (string)$r['status'];
                $col = $st === 'enviado' ? '#16a34a' : ($st === 'error' ? '#dc2626' : '#94a3b8');
              ?>
              <strong style="color:<?= $col ?>"><?= htmlspecialchars($st) ?></strong>
            </td>
            <td class="muted"><?= htmlspecialchars((string)($r['error'] ?? '—')) ?></td>
            <td class="muted"><?= htmlspecialchars((string)$r['msg_short']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<h2>Interpretación rápida</h2>
<div class="card" style="font-size:13.5px;line-height:1.6;">
  <ul style="padding-left:20px;margin:0;">
    <li><strong>Test devuelve HTTP 200 + cliente recibe SMS</strong> → SMSmasivos OK. El problema es por número específico (carrier/portabilidad).</li>
    <li><strong>HTTP 200 pero cliente no recibe</strong> → gateway acepta pero carrier descarta. Pide a SMSmasivos un reporte de entrega (DLR) por <code>id_message</code>.</li>
    <li><strong>HTTP 401</strong> → API key revocada. Pide una nueva a SMSmasivos y actualiza <code>config.php</code> o env <code>SMSMASIVOS_API_KEY</code>.</li>
    <li><strong>HTTP 402</strong> → cuenta sin créditos. Recarga.</li>
    <li><strong>HTTP 429</strong> → rate limit. Espera + reduce volumen.</li>
    <li><strong>curl error timeout</strong> → red del servidor bloquea el outbound. Habla con Plesk/hosting.</li>
  </ul>
</div>

<script>
document.getElementById('testForm').addEventListener('submit', function(e){
  e.preventDefault();
  var tel = document.getElementById('telPrueba').value.trim();
  if (!tel) return;
  var status = document.getElementById('testStatus');
  var result = document.getElementById('testResult');
  status.textContent = '⏳ Enviando...';
  result.innerHTML = '';
  var fd = new FormData();
  fd.append('key', <?= json_encode($expected) ?>);
  fd.append('action', 'send_test');
  fd.append('telefono', tel);
  fetch(location.pathname, { method: 'POST', credentials: 'include', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(j){
      status.textContent = '';
      var cls = j.ok ? 'banner-ok' : 'banner-bad';
      var head = j.ok
        ? '✓ Gateway aceptó (HTTP ' + j.http + ') · tomó ' + j.took_ms + ' ms · código de prueba <strong>' + j.codigo_prueba + '</strong>'
        : '✗ Falló (HTTP ' + (j.http || '—') + ') · ' + (j.error || j.hint || '');
      var html = '<div class="banner ' + cls + '">' + head + '</div>';
      if (j.tel_warn) html += '<div class="banner banner-warn">⚠ ' + j.tel_warn + '</div>';
      if (j.hint) html += '<div class="banner banner-warn">💡 ' + j.hint + '</div>';
      html += '<div><strong>Respuesta cruda del gateway:</strong><pre>' + (j.response_body || '(vacía)').replace(/[<>&]/g, function(c){return {'<':'&lt;','>':'&gt;','&':'&amp;'}[c]}) + '</pre></div>';
      result.innerHTML = html;
    })
    .catch(function(e){
      status.textContent = '';
      result.innerHTML = '<div class="banner banner-bad">✗ ' + e.message + '</div>';
    });
});
</script>

</body></html>
