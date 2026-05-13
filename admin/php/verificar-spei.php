<?php
/**
 * Voltika Admin — SPEI deep-diagnostic + live test.
 *
 * Customer brief 2026-05-13 (Óscar, URGENT — "Spei payment still not
 * working. And we need urgently receive a payment"): the previous fixes
 * to create-payment-intent.php improved error handling but the customer
 * still can't pay. We need to know if:
 *   1. STRIPE_SECRET_KEY is configured + valid.
 *   2. The Stripe account has customer_balance / mx_bank_transfer
 *      capability ENABLED (this is the most common cause — Stripe
 *      requires explicit activation in Dashboard → Settings → Payment
 *      methods → Bank transfers).
 *   3. A live SPEI PaymentIntent creation actually works end-to-end.
 *
 * This page calls the Stripe API directly with admin credentials and
 * surfaces every detail — request body, raw response, error code, etc.
 * That way the admin can either fix Stripe Dashboard settings or copy
 * the response to Stripe support and resolve in minutes.
 *
 * URLs:
 *   GET  /admin/php/verificar-spei.php
 *        → dashboard with config status + "test SPEI" button
 *   POST /admin/php/verificar-spei.php
 *        Body: { action: "test_spei", amount?: cents, email?, nombre? }
 *        → creates a real test PI in your Stripe account
 */
require_once __DIR__ . '/bootstrap.php';
$adminId = adminRequireAuth(['admin']);
$pdo = getDB();

// Load Stripe config
foreach ([__DIR__ . '/../../configurador/php/config.php',
          __DIR__ . '/../../configurador_prueba_test/php/config.php'] as $cfg) {
    if (is_file($cfg)) { @require_once $cfg; break; }
}
$stripeKey = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : (getenv('STRIPE_SECRET_KEY') ?: '');
$keyMode   = '';
if (strpos($stripeKey, 'sk_live_') === 0) $keyMode = 'LIVE';
elseif (strpos($stripeKey, 'sk_test_') === 0) $keyMode = 'TEST';
elseif ($stripeKey) $keyMode = 'UNKNOWN';

function _stripeGet($endpoint, $key, $timeout = 12) {
    $ch = curl_init('https://api.stripe.com/v1/' . ltrim($endpoint, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key],
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $start = microtime(true);
    $res = curl_exec($ch);
    $took = round((microtime(true) - $start) * 1000);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch) ?: null;
    curl_close($ch);
    $body = is_string($res) ? json_decode($res, true) : null;
    return [
        'http' => $http, 'curl_err' => $err, 'took_ms' => $took,
        'body' => $body, 'raw' => is_string($res) ? substr($res, 0, 4000) : null,
    ];
}

function _stripePost($endpoint, $key, array $data, $timeout = 20) {
    $ch = curl_init('https://api.stripe.com/v1/' . ltrim($endpoint, '/'));
    // Stripe API wants application/x-www-form-urlencoded with nested keys.
    $body = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $start = microtime(true);
    $res = curl_exec($ch);
    $took = round((microtime(true) - $start) * 1000);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch) ?: null;
    curl_close($ch);
    $parsed = is_string($res) ? json_decode($res, true) : null;
    return [
        'http' => $http, 'curl_err' => $err, 'took_ms' => $took,
        'body' => $parsed, 'raw' => is_string($res) ? substr($res, 0, 4000) : null,
        'request_body' => $body,
    ];
}

// ── POST: run the live test ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = adminJsonIn();
    $action = (string)($input['action'] ?? '');

    if ($action === 'test_spei') {
        if (!$stripeKey) {
            echo json_encode(['ok'=>false,'error'=>'STRIPE_SECRET_KEY no configurado']);
            exit;
        }

        // Defaults — small amount so we don't book real money.
        $amount = (int)($input['amount'] ?? 1000);   // $10 MXN
        if ($amount < 1000) $amount = 1000;
        if ($amount > 25000000) $amount = 25000000;
        $email  = trim((string)($input['email']  ?? 'test-spei-diag@voltika.mx'));
        $nombre = trim((string)($input['nombre'] ?? 'Voltika Diagnostic Test'));

        $log = [];
        // Step 1 — create a Stripe Customer (SPEI requires customer)
        $custRes = _stripePost('customers', $stripeKey, [
            'name'  => $nombre,
            'email' => $email,
        ]);
        $log[] = ['step' => 'create_customer', 'res' => $custRes];

        if ($custRes['http'] >= 400 || empty($custRes['body']['id'])) {
            echo json_encode([
                'ok' => false,
                'stage' => 'customer',
                'error' => 'Falló crear Stripe Customer',
                'detail' => $custRes['body']['error']['message'] ?? $custRes['raw'],
                'log' => $log,
            ]);
            exit;
        }
        $customerId = $custRes['body']['id'];

        // Step 2 — create + confirm the SPEI PaymentIntent
        $piData = [
            'amount'   => $amount,
            'currency' => 'mxn',
            'customer' => $customerId,
            'description' => 'Voltika SPEI diagnostic — admin#' . $adminId,
            'payment_method_types[]' => 'customer_balance',
            'payment_method_data[type]' => 'customer_balance',
            'payment_method_options[customer_balance][funding_type]' => 'bank_transfer',
            'payment_method_options[customer_balance][bank_transfer][type]' => 'mx_bank_transfer',
            'confirm' => 'true',
        ];
        $piRes = _stripePost('payment_intents', $stripeKey, $piData);
        $log[] = ['step' => 'create_pi', 'res' => $piRes];

        if ($piRes['http'] >= 400) {
            $err = $piRes['body']['error'] ?? [];
            // Hint detection — friendly explanation per common code.
            $hint = '';
            $code = (string)($err['code'] ?? '');
            $type = (string)($err['type'] ?? '');
            $msg  = (string)($err['message'] ?? '');
            if (stripos($msg, 'customer_balance') !== false || stripos($msg, 'bank_transfer') !== false || stripos($msg, 'mx_bank_transfer') !== false) {
                $hint = 'La cuenta de Stripe NO tiene activado "Transferencias bancarias (Mexico)". Entra al Dashboard → Settings → Payment methods → México y activa "Bank transfers" / customer_balance.';
            } elseif (stripos($msg, 'currency') !== false) {
                $hint = 'El currency MXN no está habilitado en esta cuenta. Verifica la configuración del país.';
            } elseif ($code === 'parameter_unknown') {
                $hint = 'Stripe rechazó un parámetro — puede ser que la API version del SDK sea muy antigua para SPEI.';
            } elseif (stripos($type, 'authentication') !== false) {
                $hint = 'La STRIPE_SECRET_KEY es inválida o fue revocada. Genera una nueva en Dashboard → Developers → API keys.';
            }
            echo json_encode([
                'ok' => false,
                'stage' => 'payment_intent',
                'error' => 'Stripe rechazó crear el PaymentIntent SPEI',
                'stripe_code'  => $code,
                'stripe_type'  => $type,
                'stripe_msg'   => $msg,
                'hint'         => $hint,
                'log'          => $log,
            ]);
            exit;
        }

        // Step 3 — parse the bank-transfer instructions
        $pi = $piRes['body'];
        $clabe = '';
        $bankInfo = $pi['next_action']['display_bank_transfer_instructions'] ?? null;
        if ($bankInfo) {
            foreach (($bankInfo['financial_addresses'] ?? []) as $addr) {
                if (isset($addr['spei_clabe']['clabe'])) { $clabe = $addr['spei_clabe']['clabe']; break; }
                if (isset($addr['clabe']))              { $clabe = $addr['clabe']; break; }
            }
            if (!$clabe) {
                // Recursive search
                array_walk_recursive($bankInfo, function($v) use (&$clabe) {
                    if (is_string($v) && preg_match('/^\d{18}$/', $v)) $clabe = $v;
                });
            }
        }

        echo json_encode([
            'ok' => true,
            'stage' => 'success',
            'pi_id' => $pi['id'],
            'pi_status' => $pi['status'] ?? null,
            'amount' => $amount,
            'currency' => 'mxn',
            'clabe' => $clabe,
            'reference' => $bankInfo['reference'] ?? null,
            'hosted_url' => $bankInfo['hosted_instructions_url'] ?? null,
            'next_action_full' => $pi['next_action'] ?? null,
            'log' => $log,
        ]);
        exit;
    }

    if ($action === 'check_account') {
        $acc = _stripeGet('account', $stripeKey);
        echo json_encode(['ok' => $acc['http'] === 200, 'result' => $acc]);
        exit;
    }

    if ($action === 'cancel_pi') {
        $piId = trim((string)($input['pi_id'] ?? ''));
        if (!$piId) { echo json_encode(['ok'=>false,'error'=>'pi_id requerido']); exit; }
        $r = _stripePost('payment_intents/' . urlencode($piId) . '/cancel', $stripeKey, []);
        echo json_encode(['ok'=>$r['http']<400, 'result'=>$r]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'acción desconocida']);
    exit;
}

// ── GET: render dashboard ──────────────────────────────────────────────
// Pre-fetch the account capabilities so the page shows status at load.
$accountInfo = null;
$capabilities = [];
$accountCountry = null;
$accountDetailsSubmitted = null;
if ($stripeKey) {
    $accResp = _stripeGet('account', $stripeKey);
    if ($accResp['http'] === 200 && is_array($accResp['body'])) {
        $accountInfo = $accResp['body'];
        $capabilities = $accountInfo['capabilities'] ?? [];
        $accountCountry = $accountInfo['country'] ?? null;
        $accountDetailsSubmitted = $accountInfo['details_submitted'] ?? null;
    }
}

$customerBalanceCap = $capabilities['mx_bank_transfer_payments']
                   ?? $capabilities['customer_balance_payments']
                   ?? null;

header('Content-Type: text/html; charset=UTF-8');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Voltika — Diagnóstico SPEI</title>
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f5f7fb;color:#0c2340;padding:24px;max-width:1100px;margin:0 auto;}
  h1{font-size:24px;margin:0 0 4px;}
  h2{font-size:14px;color:#475569;margin:24px 0 10px;text-transform:uppercase;letter-spacing:.4px;font-weight:700;}
  .sub{color:#64748b;font-size:13px;margin-bottom:18px;}
  .card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin-bottom:14px;}
  .kpi-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
  .kpi{flex:1;min-width:170px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;}
  .kpi-label{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;}
  .kpi-value{font-size:24px;font-weight:800;color:#0c2340;margin-top:4px;}
  .kpi.ok    .kpi-value,.kpi.ok    {border-color:#86efac;}.kpi.ok .kpi-value{color:#16a34a;}
  .kpi.warn  .kpi-value{color:#d97706;}
  .kpi.error .kpi-value{color:#dc2626;}
  .alert{padding:12px 14px;border-radius:8px;font-size:13.5px;margin-bottom:14px;line-height:1.6;}
  .alert-ok  {background:#dcfce7;border:1px solid #86efac;color:#166534;}
  .alert-warn{background:#fef3c7;border:1px solid #fde68a;color:#92400e;}
  .alert-err {background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
  input,select{padding:9px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;}
  .btn{background:#039fe1;color:#fff;border:0;padding:10px 18px;border-radius:6px;font-weight:600;cursor:pointer;font-size:13px;}
  .btn:hover{background:#0286c2;}.btn:disabled{opacity:.55;cursor:not-allowed;}
  pre{background:#1e293b;color:#e2e8f0;padding:12px;border-radius:6px;font-size:11px;overflow:auto;white-space:pre-wrap;word-break:break-all;}
  code{background:#1e293b;color:#e2e8f0;padding:1px 6px;border-radius:3px;font-size:11px;}
  table{width:100%;border-collapse:collapse;}
  th,td{padding:8px 10px;text-align:left;font-size:12.5px;border-bottom:1px solid #f1f5f9;}
  th{color:#475569;font-weight:700;text-transform:uppercase;font-size:10.5px;background:#f8fafc;}
  .pill{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10.5px;font-weight:700;}
  .pill-ok{background:#dcfce7;color:#166534;}.pill-warn{background:#fef3c7;color:#92400e;}.pill-err{background:#fee2e2;color:#991b1b;}
</style></head><body>

<h1>💳 Diagnóstico SPEI — Voltika</h1>
<div class="sub">Verifica configuración Stripe, capabilities de la cuenta y prueba en vivo crear un PaymentIntent SPEI.</div>

<!-- 1) Configuration -->
<h2>1. Configuración de Stripe</h2>
<div class="card">
  <div class="kpi-row">
    <div class="kpi <?= $stripeKey ? 'ok' : 'error' ?>">
      <div class="kpi-label">STRIPE_SECRET_KEY</div>
      <div class="kpi-value" style="font-size:16px;"><?= $stripeKey ? '✓ Configurado' : '✗ FALTA' ?></div>
    </div>
    <div class="kpi <?= $keyMode === 'LIVE' ? 'ok' : ($keyMode === 'TEST' ? 'warn' : 'error') ?>">
      <div class="kpi-label">Modo de la llave</div>
      <div class="kpi-value" style="font-size:18px;"><?= $keyMode ?: '—' ?></div>
    </div>
    <div class="kpi <?= $accountInfo ? 'ok' : 'error' ?>">
      <div class="kpi-label">Stripe API alcanzable</div>
      <div class="kpi-value" style="font-size:16px;"><?= $accountInfo ? '✓ Sí' : '✗ No' ?></div>
    </div>
    <div class="kpi <?= $accountCountry === 'MX' ? 'ok' : 'warn' ?>">
      <div class="kpi-label">País de la cuenta</div>
      <div class="kpi-value" style="font-size:18px;"><?= htmlspecialchars((string)$accountCountry ?: '—') ?></div>
    </div>
  </div>

  <?php if (!$stripeKey): ?>
    <div class="alert alert-err">
      <strong>⚠ Bloqueante:</strong> sin <code>STRIPE_SECRET_KEY</code> no se puede enviar nada a Stripe.
      Agrega <code>define('STRIPE_SECRET_KEY', 'sk_live_…');</code> en <code>configurador/php/config.php</code>.
    </div>
  <?php endif; ?>

  <?php if ($accountCountry && $accountCountry !== 'MX'): ?>
    <div class="alert alert-warn">
      <strong>⚠ Aviso:</strong> la cuenta de Stripe está en país <code><?= htmlspecialchars($accountCountry) ?></code>.
      SPEI <code>mx_bank_transfer</code> requiere una cuenta de Stripe Mexico.
    </div>
  <?php endif; ?>
</div>

<!-- 2) Capabilities -->
<h2>2. Capabilities de la cuenta</h2>
<div class="card">
  <div class="sub" style="margin-bottom:10px;">
    Para que SPEI funcione, la cuenta tiene que tener <code>mx_bank_transfer_payments</code> = <strong>active</strong>.
    Si dice <code>inactive</code> o no aparece, ve a Dashboard → Settings → Payment methods → México → activa "Transferencias bancarias / SPEI".
  </div>
  <?php if (!empty($capabilities)): ?>
    <?php
      $relevant = ['card_payments','customer_balance_payments','mx_bank_transfer_payments','oxxo_payments','spei_payments'];
      $shown = false;
    ?>
    <table>
      <thead><tr><th>Capability</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($relevant as $cap): ?>
        <?php
          $st = $capabilities[$cap] ?? null;
          if ($st === null) continue;
          $shown = true;
          $isOk = $st === 'active';
          $pill = $isOk ? 'pill-ok' : ($st === 'pending' ? 'pill-warn' : 'pill-err');
        ?>
        <tr>
          <td><code><?= htmlspecialchars($cap) ?></code></td>
          <td><span class="pill <?= $pill ?>"><?= htmlspecialchars($st) ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$shown): ?>
        <tr><td colspan="2">Ninguna capability relevante encontrada en la cuenta. Configurarlas en el Dashboard.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    <?php
      $mxCap = $capabilities['mx_bank_transfer_payments'] ?? null;
      if ($mxCap !== 'active'):
    ?>
      <div class="alert alert-err" style="margin-top:14px;">
        <strong>🚨 ESTA ES LA CAUSA MÁS PROBABLE DEL ERROR.</strong><br>
        <code>mx_bank_transfer_payments</code> = <code><?= htmlspecialchars((string)($mxCap ?: 'no existe')) ?></code> (debería ser <code>active</code>).<br><br>
        <strong>Cómo arreglar:</strong><br>
        1. Inicia sesión en <a href="https://dashboard.stripe.com/settings/payment_methods" target="_blank">dashboard.stripe.com/settings/payment_methods</a>.<br>
        2. Busca la sección "Transferencias bancarias" (Bank transfers) para México.<br>
        3. Click "Activar" / "Enable".<br>
        4. Stripe pedirá información del negocio (RFC, dirección, datos bancarios) — completa lo que falte.<br>
        5. Una vez activado (puede ser instantáneo o tardar 1-2 días) regresa aquí y vuelve a probar.<br>
      </div>
    <?php endif; ?>
  <?php else: ?>
    <div class="alert alert-warn">No se pudo leer las capabilities (sin API key o Stripe inalcanzable).</div>
  <?php endif; ?>
</div>

<!-- 3) Live test -->
<h2>3. Crear PaymentIntent SPEI de prueba (en vivo)</h2>
<div class="card">
  <div class="sub" style="margin-bottom:10px;">
    Esta prueba <strong>crea un PaymentIntent REAL</strong> en tu cuenta Stripe por el monto que indiques (mínimo $10 MXN).
    El cliente no llega a pagar — la prueba solo verifica que Stripe te entrega la CLABE.
    Si la prueba pasa, SPEI funciona end-to-end. Si falla, vas a ver el error EXACTO de Stripe abajo.
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
    <div>
      <label style="display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:4px;">MONTO (centavos)</label>
      <input type="number" id="speiAmount" value="1000" min="1000" max="25000000" style="width:130px;"> <span style="font-size:11px;color:#64748b;">= $10.00 MXN</span>
    </div>
    <div>
      <label style="display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:4px;">EMAIL CLIENTE</label>
      <input type="email" id="speiEmail" value="test-spei-diag@voltika.mx" style="width:280px;">
    </div>
    <button class="btn" id="btnTestSpei">⚡ Probar SPEI ahora</button>
    <span id="speiStatus" style="font-size:13px;color:#64748b;"></span>
  </div>
  <div id="speiResult" style="margin-top:14px;"></div>
</div>

<!-- 4) Account info -->
<?php if ($accountInfo): ?>
<h2>4. Información completa de la cuenta Stripe (debug)</h2>
<div class="card">
  <details>
    <summary style="cursor:pointer;font-size:13px;color:#475569;">Ver JSON completo (capabilities, business profile, etc.)</summary>
    <pre><?= htmlspecialchars(json_encode($accountInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
  </details>
</div>
<?php endif; ?>

<script>
document.getElementById('btnTestSpei').addEventListener('click', function(){
  var amount = parseInt(document.getElementById('speiAmount').value, 10);
  var email  = document.getElementById('speiEmail').value.trim();
  if (!amount || amount < 1000) { alert('Monto mínimo $10 MXN (1000 centavos)'); return; }
  if (!email || email.indexOf('@') < 0) { alert('Ingresa un email válido'); return; }
  var btn = this; btn.disabled = true;
  var st = document.getElementById('speiStatus');
  var res = document.getElementById('speiResult');
  st.textContent = 'Llamando a Stripe...';
  res.innerHTML = '';

  fetch('/admin/php/verificar-spei.php', {
    method:'POST', credentials:'include',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'test_spei', amount:amount, email:email})
  }).then(function(r){ return r.json(); }).then(function(j){
    btn.disabled = false; st.textContent = '';
    if (j.ok && j.stage === 'success') {
      var h = '<div class="alert alert-ok"><strong>✅ ÉXITO — SPEI funciona correctamente.</strong></div>';
      h += '<table>';
      h += '<tr><td><strong>PaymentIntent ID</strong></td><td><code>'+j.pi_id+'</code></td></tr>';
      h += '<tr><td><strong>Status</strong></td><td><code>'+(j.pi_status||'—')+'</code></td></tr>';
      h += '<tr><td><strong>Monto</strong></td><td>$'+(j.amount/100).toLocaleString('es-MX',{minimumFractionDigits:2})+' MXN</td></tr>';
      h += '<tr><td><strong>CLABE</strong></td><td><code style="font-size:13px;color:#22c55e;">'+(j.clabe||'(vacío!)')+'</code></td></tr>';
      h += '<tr><td><strong>Referencia</strong></td><td><code>'+(j.reference||'—')+'</code></td></tr>';
      if (j.hosted_url) h += '<tr><td><strong>Hosted instructions</strong></td><td><a href="'+j.hosted_url+'" target="_blank">'+j.hosted_url+'</a></td></tr>';
      h += '</table>';
      h += '<div style="margin-top:10px;">'+
        '<button class="btn" style="background:#dc2626;" id="cancelPi">🗑 Cancelar este PaymentIntent</button>'+
        '<span style="font-size:12px;color:#64748b;margin-left:10px;">Recomendado — esta fue solo una prueba.</span>'+
      '</div>';
      h += '<details style="margin-top:12px;"><summary style="cursor:pointer;font-size:11px;color:#64748b;">Ver log completo</summary><pre>'+
        escapeJson(j) + '</pre></details>';
      res.innerHTML = h;
      document.getElementById('cancelPi').addEventListener('click', function(){
        if (!confirm('¿Cancelar el PaymentIntent ' + j.pi_id + ' en Stripe?')) return;
        fetch('/admin/php/verificar-spei.php', {
          method:'POST', credentials:'include',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({action:'cancel_pi', pi_id:j.pi_id})
        }).then(function(r){ return r.json(); }).then(function(j2){
          alert(j2.ok ? '✓ Cancelado' : '✗ Falló: ' + JSON.stringify(j2));
        });
      });
    } else {
      var h = '<div class="alert alert-err"><strong>✗ FALLO — Stripe rechazó la operación</strong></div>';
      h += '<table>';
      h += '<tr><td><strong>Etapa</strong></td><td><code>'+(j.stage||'—')+'</code></td></tr>';
      if (j.stripe_code) h += '<tr><td><strong>Código Stripe</strong></td><td><code>'+j.stripe_code+'</code></td></tr>';
      if (j.stripe_type) h += '<tr><td><strong>Tipo Stripe</strong></td><td><code>'+j.stripe_type+'</code></td></tr>';
      if (j.stripe_msg)  h += '<tr><td><strong>Mensaje Stripe</strong></td><td style="color:#dc2626;">'+escapeHtml(j.stripe_msg)+'</td></tr>';
      h += '</table>';
      if (j.hint) {
        h += '<div class="alert alert-warn" style="margin-top:14px;">'+
          '<strong>💡 Pista:</strong> '+escapeHtml(j.hint)+
        '</div>';
      }
      h += '<details style="margin-top:12px;"><summary style="cursor:pointer;font-size:11px;color:#64748b;">Ver log completo</summary><pre>'+
        escapeJson(j) + '</pre></details>';
      res.innerHTML = h;
    }
  }).catch(function(e){
    btn.disabled = false; st.textContent = '';
    res.innerHTML = '<div class="alert alert-err">✗ Error de red al llamar al backend: ' + escapeHtml(e.message) + '</div>';
  });
});

function escapeHtml(s){ return String(s||'').replace(/[&<>"']/g, function(c){
  return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
});}
function escapeJson(o){ try { return escapeHtml(JSON.stringify(o,null,2)); } catch(e){ return ''; } }
</script>

</body></html>
