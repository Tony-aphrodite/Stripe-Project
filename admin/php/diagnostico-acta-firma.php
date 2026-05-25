<?php
/**
 * Voltika Admin — Debug "Preparando documento…" stuck issue on Acta de Entrega
 * (Round 76 diag, 2026-05-25).
 *
 * The customer reports that the "Iniciar firma con Cincel" button stays on
 * "Preparando documento…" forever. We don't know whether the bug is in:
 *   (a) the customer's cached JS (Round 73 not running)
 *   (b) the server-side PHP hanging (PDF generation slow, Cincel auth not
 *       returning fallback, etc.)
 *   (c) the response shape returned to JS doesn't match what JS expects
 *
 * This page bypasses the customer browser entirely. Given a moto_id, it
 * fires the same POST that the customer portal would, captures the timing
 * + HTTP status + full response body, and shows it in a single screen.
 *
 * URL: /admin/php/diagnostico-acta-firma.php?key=voltika_diag_2026&moto_id=N
 *
 * Strategy: server-side cURL to the customer endpoint, with a manually
 * crafted portal session cookie so portalRequireAuth() accepts the call.
 * If anything in the chain hangs / times out, we surface it instead of
 * leaving the customer staring at a blue button.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$adminId = adminRequireAuth(['admin']);

$expected = 'voltika_diag_2026';
$key = $_GET['key'] ?? '';
if (!hash_equals($expected, (string)$key)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Acceso denegado. Usa ?key=voltika_diag_2026";
    exit;
}

$motoId = (int)($_GET['moto_id'] ?? 0);
$run    = isset($_GET['run']);

$pdo = getDB();

// Look up the moto + its owner so we can simulate the customer's session.
$moto = null;
$cliente = null;
if ($motoId > 0) {
    try {
        $st = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ? LIMIT 1");
        $st->execute([$motoId]);
        $moto = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($moto) {
            $cid = (int)($moto['cliente_id'] ?? 0);
            if ($cid > 0) {
                $cs = $pdo->prepare("SELECT id, nombre, email, telefono FROM clientes WHERE id=? LIMIT 1");
                $cs->execute([$cid]);
                $cliente = $cs->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            // Backfill cliente by phone/email if cliente_id is null
            if (!$cliente) {
                $tel = trim((string)($moto['cliente_telefono'] ?? ''));
                $em  = trim((string)($moto['cliente_email']    ?? ''));
                if ($tel !== '' || $em !== '') {
                    $w = []; $a = [];
                    if ($tel !== '') { $w[] = "telefono = ?"; $a[] = $tel; }
                    if ($em  !== '') { $w[] = "email = ?";    $a[] = $em; }
                    $sql = "SELECT id, nombre, email, telefono FROM clientes WHERE (" . implode(' OR ', $w) . ") ORDER BY id DESC LIMIT 1";
                    $st = $pdo->prepare($sql);
                    $st->execute($a);
                    $cliente = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                }
            }
        }
    } catch (Throwable $e) {
        $loadErr = $e->getMessage();
    }
}

// Recent motos that are "near delivery" so the admin can pick one with one click.
$candidates = [];
try {
    $candidates = $pdo->query("SELECT id, modelo, color, vin,
              cliente_nombre, cliente_telefono, cliente_email,
              cliente_acta_firmada, cincel_acta_status,
              cincel_acta_pdf_path, freg
       FROM inventario_motos
       WHERE estado IN ('recibida','lista_para_entrega','por_validar_entrega','en_ensamble','por_ensamblar','retenida','entregada')
       ORDER BY id DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { /* schema-tolerant */ }

// ─────────────────────────────────────────────────────────────────────────
// "Run" mode: actually fire the POST.
// We use server-side cURL to the customer endpoint, supplying a portal
// session cookie we materialise here on the admin's behalf so the customer
// endpoint's portalRequireAuth() accepts our call without re-logging-in
// as the actual customer.
// ─────────────────────────────────────────────────────────────────────────
$result = null;
if ($run && $motoId > 0 && $cliente) {
    // Persist a one-shot portal session targeting this customer, then send
    // its session id as a cookie on the cURL request.
    //
    // CRITICAL: the customer portal uses a custom session cookie name
    // (VOLTIKA_PORTAL) — confirmed by the Set-Cookie header on every portal
    // response. If we leave the default PHPSESSID, the customer endpoint
    // opens a fresh empty session and returns 401 even though we wrote
    // portal_cliente_id correctly. Switch the cookie name BEFORE
    // session_start() so the session-id we read out is the right one.
    if (session_status() === PHP_SESSION_ACTIVE) {
        // Close any pre-existing session opened by the admin bootstrap so we
        // can re-open under a different cookie name. The admin auth uses
        // its OWN session (which we already validated above), so we don't
        // need it anymore for the cURL call.
        session_write_close();
    }
    session_name('VOLTIKA_PORTAL');
    @session_start();
    $_SESSION['portal_cliente_id'] = (int)$cliente['id'];
    $_SESSION['portal_telefono']   = $cliente['telefono'] ?? null;
    $_SESSION['portal_email']      = $cliente['email']    ?? null;
    $sid         = session_id();
    $sessionName = session_name();   // now 'VOLTIKA_PORTAL'
    // PHP's auto session_write_close happens at the end of this script —
    // but the cURL below needs the data to be flushed BEFORE the call.
    session_write_close();

    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'voltika.mx';
    $url    = $scheme . '://' . $host . '/clientes/php/entrega/cincel-firma-acta.php';

    $start = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Cookie: ' . $sessionName . '=' . $sid,
        ],
        CURLOPT_POSTFIELDS     => json_encode(['moto_id' => $motoId]),
        CURLOPT_TIMEOUT        => 60,    // give it longer than the 30s the customer sees
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw  = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err  = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    $tookMs = (int) ((microtime(true) - $start) * 1000);
    $hsize  = (int)($info['header_size'] ?? 0);
    $headers = is_string($raw) ? substr($raw, 0, $hsize) : '';
    $body    = is_string($raw) ? substr($raw, $hsize)    : '';
    $parsed  = is_string($body) ? json_decode($body, true) : null;

    $result = [
        'took_ms' => $tookMs,
        'http'    => (int)($info['http_code'] ?? 0),
        'err'     => $err ?: null,
        'errno'   => $errno,
        'url'     => $url,
        'sid_used'=> substr($sid, 0, 8) . '…',
        'headers' => trim($headers),
        'body'    => $body,
        'parsed'  => $parsed,
    ];

    // Don't reopen — we're done with sessions. The page below is read-only
    // HTML rendering. Reopening with mixed cookie names confuses PHP.
}

header('Content-Type: text/html; charset=utf-8');
$h = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Voltika — Diagnóstico ACTA "Preparando documento…"</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fb;color:#0c2340;padding:24px;max-width:1080px;margin:0 auto;line-height:1.55;}
h1{font-size:22px;margin:0 0 6px;}
h2{font-size:14px;color:#475569;margin:22px 0 8px;text-transform:uppercase;letter-spacing:.4px;}
.muted{color:#94a3b8;font-size:12px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:12px;}
table{border-collapse:collapse;width:100%;font-size:12.5px;}
th,td{border-bottom:1px solid #e2e8f0;padding:6px 8px;text-align:left;}
th{background:#f1f5f9;color:#475569;font-weight:600;font-size:11.5px;text-transform:uppercase;letter-spacing:.4px;}
tr:hover td{background:#f8fafc;}
a.btn{display:inline-block;padding:6px 12px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;margin-right:4px;}
.btn-go{background:#039fe1;color:#fff;border:1px solid #039fe1;}
.btn-pick{background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;}
code{background:#1e293b;color:#e2e8f0;padding:2px 6px;border-radius:3px;font-size:11px;font-family:ui-monospace,monospace;word-break:break-all;}
pre{background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px;font-size:11.5px;white-space:pre-wrap;word-break:break-all;max-height:340px;overflow:auto;margin:6px 0;}
.banner{padding:10px 12px;border-radius:8px;font-size:13px;margin-bottom:10px;font-weight:600;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.banner-bad{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
.banner-warn{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;}
.banner-info{background:#dbeafe;border:1px solid #93c5fd;color:#1e40af;}
.kv{display:grid;grid-template-columns:160px 1fr;gap:4px 12px;font-size:13px;}
.kv .k{color:#64748b;}
.kv .v{font-weight:600;color:#0c2340;word-break:break-all;}
</style></head><body>

<h1>🔬 Diagnóstico ACTA "Preparando documento…"</h1>
<div class="muted"><?= $h(date('Y-m-d H:i:s')) ?> · Servidor: <?= $h($_SERVER['HTTP_HOST'] ?? '') ?></div>

<h2>1. ¿Qué hace esta página?</h2>
<div class="card" style="font-size:13.5px;">
  El cliente reporta que tras pulsar <strong>"Iniciar firma con Cincel"</strong> el botón se queda en
  <code>Preparando documento…</code> sin avanzar. Esta página dispara la <em>misma petición</em> que dispara
  el portal del cliente (POST a <code>/clientes/php/entrega/cincel-firma-acta.php</code>), pero desde el
  servidor con autenticación de admin, y muestra la respuesta exacta + tiempo. Así sabemos en 1 click
  si el problema es:
  <ul style="margin:6px 0 0 18px;">
    <li><strong>HTTP timeout (60s+)</strong> → el backend se cuelga al generar el PDF o consultar a Cincel.</li>
    <li><strong>HTTP 200 con <code>fallback_autograph: true</code></strong> → el backend responde rápido y correcto → el bug está en el JS del cliente (caché).</li>
    <li><strong>HTTP 500</strong> → error PHP. El body mostrará la causa.</li>
    <li><strong>HTTP 200 con <code>signing_url</code> (sin fallback_autograph)</strong> → el archivo en el servidor es la versión vieja (Round 73 no aplicado).</li>
  </ul>
</div>

<h2>2. Elige el moto a probar</h2>
<div class="card">
  <?php if (!$candidates): ?>
    <div class="banner banner-warn">No se encontraron motos en estado near-delivery. Puedes ingresar un moto_id manualmente abajo.</div>
  <?php else: ?>
    <table>
      <thead><tr>
        <th>id</th><th>VIN</th><th>Modelo</th><th>Cliente</th><th>Acta firmada</th><th>Estado Cincel</th><th>Acción</th>
      </tr></thead>
      <tbody>
      <?php foreach ($candidates as $c): ?>
        <tr>
          <td><code><?= (int)$c['id'] ?></code></td>
          <td><code style="font-size:10px;"><?= $h($c['vin'] ?? '—') ?></code></td>
          <td><?= $h(($c['modelo'] ?? '') . ' · ' . ($c['color'] ?? '')) ?></td>
          <td><?= $h($c['cliente_nombre'] ?? '—') ?></td>
          <td><?= !empty($c['cliente_acta_firmada']) ? '✓' : '—' ?></td>
          <td><?= $h($c['cincel_acta_status'] ?? '—') ?></td>
          <td>
            <a class="btn btn-pick" href="?key=<?= urlencode($expected) ?>&moto_id=<?= (int)$c['id'] ?>">Inspeccionar</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <form method="get" style="margin-top:12px;display:flex;gap:8px;align-items:center;font-size:13px;">
    <input type="hidden" name="key" value="<?= $h($expected) ?>">
    <label>O ingresa moto_id manualmente:</label>
    <input type="number" name="moto_id" value="<?= (int)$motoId ?>" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;width:140px;">
    <button type="submit" class="btn btn-pick" style="cursor:pointer;border:1px solid #cbd5e1;background:#f1f5f9;color:#475569;padding:6px 12px;border-radius:6px;">Cargar</button>
  </form>
</div>

<?php if ($motoId > 0 && $moto): ?>
  <h2>3. Datos del moto + cliente</h2>
  <div class="card">
    <div class="kv">
      <span class="k">moto_id</span><span class="v"><?= (int)$moto['id'] ?></span>
      <span class="k">VIN</span><span class="v"><?= $h($moto['vin'] ?? '—') ?></span>
      <span class="k">Modelo · color</span><span class="v"><?= $h(($moto['modelo'] ?? '') . ' · ' . ($moto['color'] ?? '')) ?></span>
      <span class="k">Punto asignado</span><span class="v"><?= $h($moto['punto_voltika_id'] ?? '—') ?></span>
      <span class="k">cliente_id</span><span class="v"><?= $h($moto['cliente_id'] ?? '—') ?></span>
      <span class="k">cliente_nombre</span><span class="v"><?= $h($moto['cliente_nombre'] ?? '—') ?></span>
      <span class="k">cliente_email</span><span class="v"><?= $h($moto['cliente_email'] ?? '—') ?></span>
      <span class="k">cliente_telefono</span><span class="v"><?= $h($moto['cliente_telefono'] ?? '—') ?></span>
      <span class="k">cliente_acta_firmada</span><span class="v"><?= !empty($moto['cliente_acta_firmada']) ? '✓ sí' : '— no' ?></span>
      <span class="k">cincel_acta_status</span><span class="v"><?= $h($moto['cincel_acta_status'] ?? '—') ?></span>
      <span class="k">cincel_acta_pdf_path</span><span class="v" style="font-size:11px;"><?= $h($moto['cincel_acta_pdf_path'] ?? '—') ?></span>
    </div>

    <?php if (!empty($moto['cliente_acta_firmada'])): ?>
      <div class="banner banner-info" style="margin-top:12px;">⚠ Esta moto ya tiene <code>cliente_acta_firmada=1</code>. La firma de la acta ya está marcada. El endpoint devolverá <code>already_signed:true</code>.</div>
    <?php endif; ?>

    <?php if (!$cliente): ?>
      <div class="banner banner-bad" style="margin-top:12px;">No pude resolver el cliente del moto. No puedo simular la sesión del portal. Verifica <code>cliente_id</code> en <code>inventario_motos</code>.</div>
    <?php else: ?>
      <div style="margin-top:14px;">
        <a class="btn btn-go" href="?key=<?= urlencode($expected) ?>&moto_id=<?= (int)$motoId ?>&run=1">
          🚀 Disparar POST a cincel-firma-acta.php (server-side)
        </a>
        <span class="muted" style="font-size:11.5px;margin-left:8px;">
          Simula la sesión del cliente <code><?= $h($cliente['nombre'] ?? '') ?></code> y POSTea como lo haría el portal.
        </span>
      </div>
    <?php endif; ?>
  </div>
<?php elseif ($motoId > 0 && !$moto): ?>
  <div class="banner banner-bad">No existe la moto <?= (int)$motoId ?> en <code>inventario_motos</code>.</div>
<?php endif; ?>

<?php if ($result): ?>
  <h2>4. Resultado del POST</h2>
  <div class="card">
    <?php
      $http = (int)$result['http'];
      $isOk = $http >= 200 && $http < 300;
      $banner = 'banner-info'; $bannerMsg = '';
      $diag = '';
      if ($result['err']) {
          $banner = 'banner-bad';
          $bannerMsg = '❌ Error de transporte: ' . $result['err'];
          $diag = 'Posible timeout del backend (PHP colgado). El cliente ve "Preparando documento…" hasta que su browser corta.';
      } elseif ($result['took_ms'] > 30000) {
          $banner = 'banner-bad';
          $bannerMsg = '⚠ Latencia muy alta: ' . $result['took_ms'] . ' ms';
          $diag = 'El backend tardó más de 30 segundos. El JS del cliente NO tiene timeout — se queda esperando indefinidamente, mostrando "Preparando documento…".';
      } elseif (!$isOk) {
          $banner = 'banner-bad';
          $bannerMsg = "❌ HTTP $http en " . $result['took_ms'] . ' ms';
          $diag = 'El servidor devolvió un error. Mira el body para entender la causa.';
      } else {
          $parsed = $result['parsed'];
          if (!is_array($parsed)) {
              $banner = 'banner-bad';
              $bannerMsg = '⚠ HTTP 200 pero el body no es JSON';
              $diag = 'Probable PHP warning o salida HTML antes del JSON. El JS hará JSON.parse() y fallará silenciosamente.';
          } elseif (!empty($parsed['already_signed'])) {
              $banner = 'banner-info';
              $bannerMsg = 'ℹ️ already_signed: la moto ya tiene su acta firmada';
              $diag = 'El JS llamará render() para refrescar. No es bug.';
          } elseif (!empty($parsed['fallback_autograph'])) {
              $banner = 'banner-ok';
              $bannerMsg = '✅ Round 73 OK: fallback_autograph=true en ' . $result['took_ms'] . ' ms';
              $diag = 'El servidor responde correcto y rápido. Si el cliente sigue viendo "Preparando documento…" es CACHÉ DEL JS DEL NAVEGADOR. Solución: hard-refresh / incógnito / limpiar caché.';
          } elseif (!empty($parsed['signing_url'])) {
              $banner = 'banner-warn';
              $bannerMsg = '⚠ signing_url sin fallback_autograph — el archivo en el servidor es la versión VIEJA';
              $diag = 'Round 73 no está aplicado en cincel-firma-acta.php. Verifica el verificador.';
          } elseif (!empty($parsed['error'])) {
              $banner = 'banner-bad';
              $bannerMsg = '❌ ' . $parsed['error'];
              $diag = $parsed['detail'] ?? '';
          } else {
              $banner = 'banner-warn';
              $bannerMsg = '⚠ Respuesta inesperada — body abajo';
          }
      }
    ?>
    <div class="banner <?= $banner ?>"><?= $h($bannerMsg) ?></div>
    <?php if ($diag): ?>
      <div style="font-size:13px;color:#0c2340;margin-bottom:10px;"><strong>Diagnóstico:</strong> <?= $h($diag) ?></div>
    <?php endif; ?>

    <div class="kv" style="margin-bottom:10px;">
      <span class="k">HTTP</span><span class="v"><?= $h((string)$result['http']) ?></span>
      <span class="k">Tiempo</span><span class="v"><?= (int)$result['took_ms'] ?> ms</span>
      <span class="k">URL</span><span class="v" style="font-size:11px;"><?= $h($result['url']) ?></span>
      <span class="k">Session id usada</span><span class="v"><?= $h($result['sid_used']) ?></span>
      <?php if ($result['err']): ?>
        <span class="k">cURL error</span><span class="v"><?= $h($result['err']) ?> (errno <?= (int)$result['errno'] ?>)</span>
      <?php endif; ?>
    </div>

    <?php if (is_array($result['parsed'])): ?>
      <div class="muted" style="font-size:11.5px;">Body parsed:</div>
      <pre><?= $h(json_encode($result['parsed'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
    <?php else: ?>
      <div class="muted" style="font-size:11.5px;">Body crudo:</div>
      <pre><?= $h(substr((string)$result['body'], 0, 4000)) ?></pre>
    <?php endif; ?>

    <details style="margin-top:10px;">
      <summary class="muted" style="font-size:11.5px;cursor:pointer;">Response headers</summary>
      <pre><?= $h(substr((string)$result['headers'], 0, 2000)) ?></pre>
    </details>
  </div>
<?php endif; ?>

<h2>5. Cómo interpretar el resultado</h2>
<div class="card" style="font-size:13px;">
  <table>
    <thead><tr><th>Banner</th><th>Significa</th><th>Acción</th></tr></thead>
    <tbody>
      <tr><td><span class="banner banner-ok" style="display:inline-block;padding:3px 8px;font-size:11px;margin:0;">✅ Round 73 OK</span></td>
          <td>El backend responde rápido y correcto.</td>
          <td>Bug en el navegador del cliente. Limpiar caché.</td></tr>
      <tr><td><span class="banner banner-bad" style="display:inline-block;padding:3px 8px;font-size:11px;margin:0;">❌ HTTP timeout</span></td>
          <td>PHP colgado (PDF lento, FPDF stuck, etc).</td>
          <td>Investigar PDF generation — quizá filesystem lleno o FPDF problemático.</td></tr>
      <tr><td><span class="banner banner-bad" style="display:inline-block;padding:3px 8px;font-size:11px;margin:0;">❌ HTTP 500</span></td>
          <td>Error PHP en el backend.</td>
          <td>Leer el body crudo para entender el fatal.</td></tr>
      <tr><td><span class="banner banner-warn" style="display:inline-block;padding:3px 8px;font-size:11px;margin:0;">⚠ signing_url</span></td>
          <td>El archivo en el servidor es la versión ANTERIOR a Round 73.</td>
          <td>Re-subir cincel-firma-acta.php.</td></tr>
    </tbody>
  </table>
</div>

</body></html>
