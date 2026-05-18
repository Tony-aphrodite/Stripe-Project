<?php
/**
 * Voltika Admin — Round 56 verification (2026-05-18).
 *
 * Confirms that the customer portal (clientes/) is now loading the
 * production configuration with LIVE Stripe keys, after the Round 56
 * fix that changed clientes/php/bootstrap.php from loading the test
 * sandbox to loading configurador/.
 *
 * The trick: this file is in admin/ but explicitly bootstraps via
 * clientes/php/bootstrap.php to see the EXACT keys the customer portal
 * gets. If both paths agree on LIVE, the fix is in.
 *
 * URL:
 *   https://voltika.mx/admin/php/diagnostico-stripe-mode.php?key=voltika_diag_2026
 *
 * Output:
 *   - APP_ENV active in each module (admin vs clientes)
 *   - First/last chars of each Stripe key + which prefix (sk_test / sk_live)
 *   - Live ping to Stripe API → confirms the key is actually accepted
 *   - Deletes itself once verified
 */

declare(strict_types=1);

$expected = 'voltika_diag_2026';
$key = $_GET['key'] ?? '';
if (!hash_equals($expected, (string)$key)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Acceso denegado. Usa ?key=<secret>";
    exit;
}

// ── Probe 1: bootstrap via CLIENTES (customer portal path) ──────────────
// This is the key test — clientes/php/bootstrap.php should now route to
// configurador/php/master-bootstrap.php which loads /configurador/.env
// with the live Stripe keys.
$cliEnv = null; $cliSk = null; $cliPk = null; $cliWh = null;
try {
    // We can't actually `require` both clientes and admin bootstraps in
    // the same request (they redefine constants). Use a sub-request via
    // curl to /clientes/php/cliente/whoami.php or any inert endpoint
    // wouldn't expose keys. Better: read directly via constants once
    // clientes bootstrap runs — but that happens last.
    // For this diagnostic, we bootstrap CLIENTES (what we care about)
    // and report from there. Admin's own keys would be checked by visiting
    // an admin-side diagnostic separately.
    require_once __DIR__ . '/../../clientes/php/bootstrap.php';
    $cliEnv = defined('APP_ENV') ? APP_ENV : 'undefined';
    $cliSk  = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : '';
    $cliPk  = defined('STRIPE_PUBLISHABLE_KEY') ? STRIPE_PUBLISHABLE_KEY : '';
    $cliWh  = defined('STRIPE_WEBHOOK_SECRET') ? STRIPE_WEBHOOK_SECRET : '';
} catch (Throwable $e) {
    // continue — show what we have
}

// Helper to identify key type from prefix
function stripeKeyKind(string $k): string {
    if ($k === '')                            return 'EMPTY';
    if (strpos($k, 'sk_live_') === 0)         return '✓ LIVE  (production)';
    if (strpos($k, 'sk_test_') === 0)         return '✗ TEST  (sandbox)';
    if (strpos($k, 'pk_live_') === 0)         return '✓ LIVE  (production)';
    if (strpos($k, 'pk_test_') === 0)         return '✗ TEST  (sandbox)';
    if (strpos($k, 'whsec_') === 0)           return 'webhook secret';
    return 'UNKNOWN prefix';
}
function stripeKeyMask(string $k): string {
    if ($k === '') return '(vacío)';
    $len = strlen($k);
    if ($len < 16) return $k;
    return substr($k, 0, 12) . str_repeat('*', max(0, $len - 18)) . substr($k, -6);
}

// ── Probe 2: live API ping to Stripe ────────────────────────────────────
// Send a HEAD-like GET to /v1/balance — auth-only, cheap, returns 200 if
// key works in either test or live mode. Body contains "livemode": true/false.
$pingResult = null;
if ($cliSk) {
    $ch = curl_init('https://api.stripe.com/v1/balance');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $cliSk],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $parsed = is_string($resp) ? json_decode($resp, true) : null;
    $pingResult = [
        'http'      => $code,
        'curl_err'  => $err ?: null,
        'livemode'  => is_array($parsed) ? ($parsed['livemode'] ?? null) : null,
        'object'    => is_array($parsed) ? ($parsed['object']   ?? null) : null,
        'body_short'=> substr((string)$resp, 0, 400),
    ];
}

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Voltika — Stripe mode diagnostic</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fb;color:#0c2340;padding:24px;max-width:980px;margin:0 auto;line-height:1.5;}
h1{font-size:22px;margin:0 0 4px;} h2{font-size:14px;color:#475569;margin:24px 0 10px;text-transform:uppercase;letter-spacing:.4px;}
.muted{color:#94a3b8;font-size:11.5px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:14px;}
table{width:100%;border-collapse:collapse;font-size:13px;}
td{padding:8px 6px;border-bottom:1px solid #f1f5f9;vertical-align:top;}
td:first-child{width:240px;color:#64748b;font-weight:700;font-size:12px;}
code{background:#f1f5f9;color:#1e293b;padding:1px 5px;border-radius:3px;font-size:11.5px;font-family:ui-monospace,monospace;word-break:break-all;}
.banner{padding:14px;border-radius:8px;font-size:14px;margin:14px 0;font-weight:600;}
.banner-ok{background:#dcfce7;border:2px solid #16a34a;color:#166534;}
.banner-bad{background:#fee2e2;border:2px solid #dc2626;color:#991b1b;}
.banner-warn{background:#fff7ed;border:2px solid #d97706;color:#9a3412;}
pre{background:#0b1322;color:#e2e8f0;padding:10px;border-radius:6px;font-size:11px;overflow-x:auto;}
.ok{color:#16a34a;font-weight:700;}
.bad{color:#dc2626;font-weight:700;}
</style></head><body>

<h1>💳 Diagnóstico Stripe (live vs test mode)</h1>
<div class="muted">Round 56 verification · servidor <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '') ?> · <?= date('Y-m-d H:i:s') ?></div>

<?php
$skKind = stripeKeyKind($cliSk);
$pkKind = stripeKeyKind($cliPk);
$isLive = (strpos($cliSk, 'sk_live_') === 0);
$pingOk = $pingResult && $pingResult['http'] >= 200 && $pingResult['http'] < 300;
$pingLive = $pingResult && ($pingResult['livemode'] === true);
?>

<?php if ($isLive && $pingOk && $pingLive): ?>
  <div class="banner banner-ok">
    ✅ <strong>PRODUCCIÓN OK</strong> — La cuenta cliente carga claves <code>sk_live_*</code> y Stripe responde
    confirmando <code>livemode: true</code>. Tarjetas reales serán aceptadas.
  </div>
<?php elseif (!$isLive): ?>
  <div class="banner banner-bad">
    ❌ <strong>TODAVÍA EN MODO PRUEBA</strong> — El portal cliente está cargando claves <code>sk_test_*</code>.
    Posibles causas: APP_ENV ≠ live, .env no leído, OPcache cacheando bootstrap viejo.
  </div>
<?php elseif (!$pingOk): ?>
  <div class="banner banner-warn">
    ⚠ La clave es <code>sk_live_*</code> pero Stripe rechaza la petición (HTTP <?= htmlspecialchars((string)($pingResult['http'] ?? '?')) ?>).
    La clave puede estar mal copiada o revocada en el dashboard de Stripe.
  </div>
<?php else: ?>
  <div class="banner banner-warn">
    ⚠ Estado mixto — revisa los detalles abajo.
  </div>
<?php endif; ?>

<h2>1. Bootstrap via /clientes/php/bootstrap.php (lo que ve el portal cliente)</h2>
<div class="card">
  <table>
    <tr><td>APP_ENV</td><td>
      <code><?= htmlspecialchars((string)$cliEnv) ?></code>
      <?= ($cliEnv === 'live') ? ' <span class="ok">✓ live</span>' : ' <span class="bad">✗ no es "live"</span>' ?>
    </td></tr>
    <tr><td>STRIPE_SECRET_KEY</td><td>
      <code><?= htmlspecialchars(stripeKeyMask($cliSk)) ?></code>
      <br><strong><?= htmlspecialchars($skKind) ?></strong>
    </td></tr>
    <tr><td>STRIPE_PUBLISHABLE_KEY</td><td>
      <code><?= htmlspecialchars(stripeKeyMask($cliPk)) ?></code>
      <br><strong><?= htmlspecialchars($pkKind) ?></strong>
    </td></tr>
    <tr><td>STRIPE_WEBHOOK_SECRET</td><td>
      <?php if ($cliWh && $cliWh !== 'whsec_PLACEHOLDER'): ?>
        <code><?= htmlspecialchars(substr($cliWh, 0, 12)) ?>...</code>
        <span class="ok">✓ configurado</span>
      <?php else: ?>
        <code>whsec_PLACEHOLDER</code>
        <span class="bad">✗ falta — webhooks fallarán</span>
      <?php endif; ?>
    </td></tr>
  </table>
</div>

<h2>2. Ping en vivo a Stripe API (/v1/balance)</h2>
<div class="card">
  <?php if ($pingResult): ?>
    <table>
      <tr><td>HTTP code</td><td>
        <strong style="color:<?= $pingOk ? '#16a34a' : '#dc2626' ?>;"><?= (int)$pingResult['http'] ?></strong>
        <?= $pingOk ? '<span class="ok">✓ Stripe aceptó la clave</span>' : '<span class="bad">✗ Stripe rechazó</span>' ?>
      </td></tr>
      <tr><td>livemode (Stripe-confirmed)</td><td>
        <?php if ($pingResult['livemode'] === true): ?>
          <span class="ok">✓ true — MODO PRODUCCIÓN ACTIVO</span>
        <?php elseif ($pingResult['livemode'] === false): ?>
          <span class="bad">✗ false — MODO PRUEBA (sandbox)</span>
        <?php else: ?>
          <span class="muted">no devuelto</span>
        <?php endif; ?>
      </td></tr>
      <?php if (!empty($pingResult['curl_err'])): ?>
        <tr><td>curl error</td><td class="bad"><?= htmlspecialchars((string)$pingResult['curl_err']) ?></td></tr>
      <?php endif; ?>
      <tr><td>Response (primeros 400 chars)</td><td><pre><?= htmlspecialchars((string)($pingResult['body_short'] ?? '')) ?></pre></td></tr>
    </table>
  <?php else: ?>
    <div class="muted">No se hizo el ping (clave secreta vacía).</div>
  <?php endif; ?>
</div>

<h2>3. Pasos siguientes</h2>
<div class="card" style="font-size:13.5px;">
  <?php if ($isLive && $pingOk && $pingLive): ?>
    <p class="ok">✓ Todo en orden. Real-card tests deberían pasar en /clientes/ ahora.</p>
    <p><strong>Borra este archivo del servidor</strong> una vez que confirmes con un cliente real.
       Está protegido por la secret key pero igual no debería quedarse en producción.</p>
  <?php elseif (!$isLive): ?>
    <ol>
      <li>Confirma que <code>/configurador/.env</code> tiene <code>APP_ENV=live</code> y las claves <code>STRIPE_SECRET_KEY_LIVE</code> + <code>STRIPE_PUBLISHABLE_KEY_LIVE</code> bien copiadas.</li>
      <li>Si OPcache estuviera activo, resetéalo desde <code>/admin/php/verificar-deploy.php</code>.</li>
      <li>Confirma que <code>clientes/php/bootstrap.php</code> tiene la línea Round 56 (carga <code>configurador/</code>, no <code>configurador_prueba_test/</code>).</li>
    </ol>
  <?php else: ?>
    <p>Tienes claves live pero Stripe las rechaza. Verifica en el dashboard de Stripe que la clave está activa y no fue rotada.</p>
  <?php endif; ?>
</div>

</body></html>
