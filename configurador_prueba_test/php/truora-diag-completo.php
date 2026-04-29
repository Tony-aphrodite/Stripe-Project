<?php
/**
 * Voltika — Truora Integration · Comprehensive Diagnostic
 *
 * Single-page diagnostic that runs EVERY possible failure check for the
 * Truora iframe integration and shows results in one consolidated view.
 *
 * Tests in order:
 *   1. Server-side: voltika-config (CSP headers, X-Frame-Options)
 *   2. Server-side: truora-token.php request — full response capture
 *   3. Server-side: HEAD request to identity.truora.com (network reachability)
 *   4. Server-side: GET to iframe URL with token (Truora response headers)
 *   5. Client-side: real iframe load test with multi-mechanism failure detection
 *      - load event timing
 *      - contentWindow access (cross-origin error = good)
 *      - postMessage capture
 *      - error event
 *   6. Client-side: CSP violation report listener
 *   7. Browser environment: cookies, mixed content, third-party cookie policy
 *
 * Access: admin only. URL:
 *   https://voltika.mx/configurador_prueba/php/truora-diag-completo.php
 */

require_once __DIR__ . '/config.php';

// Light token auth — this is a server-side diagnostic that needs to run
// even when dealer/admin auth isn't fully wired (the script's purpose is
// to debug auth/iframe failures). Default token is "voltika_diag_2026".
// Override with env VOLTIKA_DIAG_TOKEN.
$expectedToken = getenv('VOLTIKA_DIAG_TOKEN') ?: (defined('VOLTIKA_DIAG_TOKEN') ? VOLTIKA_DIAG_TOKEN : 'voltika_diag_2026');
$gotToken = $_GET['token'] ?? '';

// Also accept admin session if available (VOLTIKA_ADMIN session name).
if (session_status() === PHP_SESSION_NONE) {
    @session_name('VOLTIKA_ADMIN');
    @session_start();
}
$adminOk = !empty($_SESSION['admin_user_id']);

if (!$adminOk && !hash_equals($expectedToken, $gotToken)) {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Truora Diagnostic</title>';
    echo '<style>body{font-family:system-ui,sans-serif;background:#f0f4f8;padding:32px;max-width:600px;margin:0 auto;color:#0c2340;}';
    echo 'h1{font-size:20px;}.card{background:#fff;padding:18px;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-top:14px;}';
    echo 'code{background:#1e293b;color:#e2e8f0;padding:2px 8px;border-radius:4px;font-family:ui-monospace,Menlo,monospace;}';
    echo 'a{color:#039fe1;font-weight:600;text-decoration:none;}</style></head><body>';
    echo '<h1>🔬 Truora Diagnostic — acceso protegido</h1>';
    echo '<div class="card">';
    echo '<p>Esta página de diagnóstico está protegida. Accede de una de estas dos formas:</p>';
    echo '<p><b>Opción 1 — con token (rápido)</b><br>';
    echo 'Agrega <code>?token=' . htmlspecialchars($expectedToken) . '</code> al final de la URL:</p>';
    echo '<p><a href="?token=' . urlencode($expectedToken) . '">▶ Abrir con token</a></p>';
    echo '<p style="margin-top:20px;"><b>Opción 2 — login como admin</b><br>';
    echo 'Inicia sesión en <a href="/admin/" target="_blank">/admin/</a> primero, luego vuelve a esta URL.</p>';
    echo '</div></body></html>';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');

// ─────────────────────────────────────────────────────────────────────────
// Server-side checks executed before render
// ─────────────────────────────────────────────────────────────────────────

$checks = [];

// Check 1: HTTP response headers from voltika.mx itself (loop-back)
$selfUrl = ($_SERVER['HTTPS'] ?? '') === 'on'
    ? 'https://' . ($_SERVER['HTTP_HOST'] ?? 'voltika.mx') . '/configurador_prueba/'
    : 'http://' . ($_SERVER['HTTP_HOST'] ?? 'voltika.mx') . '/configurador_prueba/';
$selfHeaders = [];
$ch = curl_init($selfUrl);
curl_setopt_array($ch, [
    CURLOPT_NOBODY         => true,
    CURLOPT_HEADER         => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$rawHeaders = curl_exec($ch);
$selfHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$cspHeader = '';
$xfoHeader = '';
$frameSrcDirective = '';
$frameAncestorsDirective = '';
foreach (explode("\n", $rawHeaders) as $line) {
    $line = trim($line);
    if (stripos($line, 'content-security-policy:') === 0) {
        $cspHeader = trim(substr($line, strlen('content-security-policy:')));
    }
    if (stripos($line, 'x-frame-options:') === 0) {
        $xfoHeader = trim(substr($line, strlen('x-frame-options:')));
    }
}
if ($cspHeader) {
    if (preg_match('/frame-src\s+([^;]+)/i', $cspHeader, $m))      $frameSrcDirective = trim($m[1]);
    if (preg_match('/frame-ancestors\s+([^;]+)/i', $cspHeader, $m)) $frameAncestorsDirective = trim($m[1]);
}
$cspBlocksTruoraFrame = false;
if ($frameSrcDirective !== '') {
    // frame-src controls what voltika.mx is allowed to embed.
    if (stripos($frameSrcDirective, 'identity.truora.com') === false &&
        stripos($frameSrcDirective, 'truora.com') === false &&
        stripos($frameSrcDirective, '*') === false &&
        stripos($frameSrcDirective, 'https:') === false) {
        $cspBlocksTruoraFrame = true;
    }
}
$checks[] = [
    'name'  => 'voltika.mx CSP / X-Frame-Options',
    'ok'    => !$cspBlocksTruoraFrame && $xfoHeader === '',
    'detail' => [
        'http_code'        => $selfHttpCode,
        'csp'              => $cspHeader ?: '(ninguno)',
        'frame-src'        => $frameSrcDirective ?: '(no especificado — permite todo)',
        'frame-ancestors'  => $frameAncestorsDirective ?: '(no especificado)',
        'X-Frame-Options'  => $xfoHeader ?: '(ninguno)',
        'csp_bloquea_truora' => $cspBlocksTruoraFrame ? 'SÍ ⚠️' : 'no',
    ],
    'note'  => $cspBlocksTruoraFrame
        ? 'El header CSP de voltika.mx restringe frame-src y NO incluye truora.com. Esto bloquea el iframe. Ajustar el servidor o agregar `frame-src https: identity.truora.com;`.'
        : 'voltika.mx no tiene restricciones CSP/XFO sobre frames. ✓ correcto.',
];

// Check 2: identity.truora.com network reachability
$truoraReachable = false;
$truoraStatusCode = null;
$truoraSrvHeaders = [];
$ch = curl_init('https://identity.truora.com/');
curl_setopt_array($ch, [
    CURLOPT_NOBODY         => true,
    CURLOPT_HEADER         => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_FOLLOWLOCATION => false,
]);
$truoraHeadResp = curl_exec($ch);
$truoraStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$truoraErr = curl_error($ch);
curl_close($ch);

$truoraXfo = '';
$truoraCsp = '';
foreach (explode("\n", (string)$truoraHeadResp) as $line) {
    $line = trim($line);
    if (stripos($line, 'x-frame-options:') === 0)        $truoraXfo = trim(substr($line, strlen('x-frame-options:')));
    if (stripos($line, 'content-security-policy:') === 0) $truoraCsp = trim(substr($line, strlen('content-security-policy:')));
}
$truoraReachable = $truoraStatusCode > 0 && empty($truoraErr);
$truoraFrameAncestors = '';
if ($truoraCsp && preg_match('/frame-ancestors\s+([^;]+)/i', $truoraCsp, $m)) {
    $truoraFrameAncestors = trim($m[1]);
}
$truoraBlocksVoltika = false;
if ($truoraFrameAncestors !== '') {
    $host = $_SERVER['HTTP_HOST'] ?? 'voltika.mx';
    if (stripos($truoraFrameAncestors, $host) === false &&
        stripos($truoraFrameAncestors, '*') === false &&
        stripos($truoraFrameAncestors, 'https:') === false &&
        stripos($truoraFrameAncestors, 'voltika.mx') === false) {
        $truoraBlocksVoltika = true;
    }
}
$checks[] = [
    'name' => 'identity.truora.com — alcance + headers de embebido',
    'ok'   => $truoraReachable && !$truoraBlocksVoltika && (stripos($truoraXfo, 'DENY') === false && stripos($truoraXfo, 'SAMEORIGIN') === false),
    'detail' => [
        'reachable'         => $truoraReachable ? "✓ HTTP $truoraStatusCode" : '✗ ' . ($truoraErr ?: 'sin respuesta'),
        'X-Frame-Options'   => $truoraXfo ?: '(ninguno — permite embebido)',
        'frame-ancestors'   => $truoraFrameAncestors ?: '(no especificado o permite todo)',
        'bloquea_voltika'   => $truoraBlocksVoltika ? 'SÍ ⚠️' : 'no',
    ],
    'note'  => !$truoraReachable
        ? 'identity.truora.com no responde desde este servidor. Verifica firewall/red.'
        : ($truoraBlocksVoltika
            ? 'Truora envía frame-ancestors que NO incluye este dominio. Pedir whitelist en Truora dashboard.'
            : 'Truora permite el embebido desde este dominio. ✓'),
];

// Check 3: truora-token.php endpoint test
$tokenUrl = $selfUrl . 'php/truora-token.php';
$tokenPayload = [
    'cliente_id' => 'diag_' . time(),
    'nombre'     => 'Diagnostico Voltika',
    'apellidos'  => 'Test Diag',
    'telefono'   => '5512345678',
    'email'      => 'diag@voltika.mx',
    'curp'       => 'XAXX010101HDFAAA01',
];
$ch = curl_init($tokenUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($tokenPayload),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$tokenRaw  = curl_exec($ch);
$tokenCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$tokenErr  = curl_error($ch);
curl_close($ch);

$tokenJson = json_decode($tokenRaw, true) ?: [];
$tokenOk   = !empty($tokenJson['ok']) && !empty($tokenJson['iframe_url']);
$iframeUrlReturned = $tokenJson['iframe_url'] ?? '';
$accountIdReturned = $tokenJson['account_id'] ?? '';
$flowIdReturned    = $tokenJson['flow_id'] ?? '';

$checks[] = [
    'name' => 'truora-token.php — generación de token',
    'ok'   => $tokenOk,
    'detail' => [
        'url'         => $tokenUrl,
        'http_code'   => $tokenCode,
        'curl_error'  => $tokenErr ?: '(ninguno)',
        'token_ok'    => $tokenOk ? '✓' : '✗',
        'iframe_url'  => $iframeUrlReturned ? substr($iframeUrlReturned, 0, 90) . '…' : '(vacío)',
        'flow_id'     => $flowIdReturned ?: '(vacío)',
        'account_id'  => $accountIdReturned ?: '(vacío)',
        'response_raw' => substr($tokenRaw, 0, 500),
    ],
    'note' => $tokenOk
        ? 'El backend genera tokens correctamente con flow_id ' . $flowIdReturned . '.'
        : 'truora-token.php falló — revisar TRUORA_API_KEY y TRUORA_FLOW_ID en config.',
];

// Check 4: GET the actual iframe URL and capture Truora's response headers
//          for THIS specific token (frame-ancestors may be per-flow!)
$iframeRespHeaders = '';
$iframeStatus = null;
$iframeXfo = '';
$iframeFrameAncestors = '';
if ($iframeUrlReturned) {
    $ch = curl_init($iframeUrlReturned);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_HEADER         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['Referer: ' . $selfUrl],
    ]);
    $iframeRespHeaders = curl_exec($ch);
    $iframeStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    foreach (explode("\n", (string)$iframeRespHeaders) as $line) {
        $line = trim($line);
        if (stripos($line, 'x-frame-options:') === 0) $iframeXfo = trim(substr($line, strlen('x-frame-options:')));
        if (stripos($line, 'content-security-policy:') === 0) {
            $csp = trim(substr($line, strlen('content-security-policy:')));
            if (preg_match('/frame-ancestors\s+([^;]+)/i', $csp, $m)) $iframeFrameAncestors = trim($m[1]);
        }
    }
}
$host = $_SERVER['HTTP_HOST'] ?? 'voltika.mx';
$wwwOk = stripos($iframeFrameAncestors, $host) !== false || stripos($iframeFrameAncestors, 'voltika.mx') !== false;
$starOk = stripos($iframeFrameAncestors, '*') !== false || stripos($iframeFrameAncestors, 'https:') !== false;
$iframeOk = $iframeStatus === 200 && stripos($iframeXfo, 'DENY') === false &&
            stripos($iframeXfo, 'SAMEORIGIN') === false &&
            ($iframeFrameAncestors === '' || $wwwOk || $starOk);

$checks[] = [
    'name' => 'Token-specific iframe URL — headers de embebido reales',
    'ok'   => $iframeOk,
    'detail' => [
        'http_code'           => $iframeStatus,
        'X-Frame-Options'     => $iframeXfo ?: '(ninguno)',
        'frame-ancestors'     => $iframeFrameAncestors ?: '(ninguno)',
        'incluye_'.$host      => $wwwOk ? '✓ sí' : 'no',
        'wildcard'            => $starOk ? '✓ sí' : 'no',
        'iframe_url_corta'    => substr($iframeUrlReturned, 0, 80) . '…',
    ],
    'note' => $iframeOk
        ? 'Los headers de Truora permiten embebido desde ' . $host . ' para ESTE token. ✓'
        : ($iframeFrameAncestors !== '' && !$wwwOk && !$starOk
            ? '⚠️ frame-ancestors NO incluye ' . $host . '. Decirle a Truora support que agregue exactamente "' . $host . '" (sin slash) al whitelist del flow_id ' . $flowIdReturned . '.'
            : 'Falló la consulta del iframe URL (HTTP ' . $iframeStatus . ').'),
];

// ─────────────────────────────────────────────────────────────────────────
// Render
// ─────────────────────────────────────────────────────────────────────────

$allOk = true;
foreach ($checks as $c) if (!$c['ok']) { $allOk = false; break; }
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Voltika · Truora Diagnostic</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f0f4f8;color:#0c2340;padding:24px;max-width:1100px;margin:0 auto;line-height:1.5;}
h1{font-size:22px;margin:0 0 6px;}
h2{font-size:13px;color:#64748b;margin:0 0 24px;text-transform:uppercase;letter-spacing:.5px;font-weight:500;}
.banner{padding:14px 18px;border-radius:10px;margin:14px 0;font-size:14px;}
.banner.ok{background:#dcfce7;color:#14532d;border:1px solid #22c55e;}
.banner.warn{background:#fef3c7;color:#78350f;border:1px solid #f59e0b;}
.banner.err{background:#fee2e2;color:#991b1b;border:1px solid #ef4444;}
.check{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:14px;}
.check h3{margin:0 0 8px;font-size:15px;}
.check .status{font-weight:700;font-size:13px;padding:3px 10px;border-radius:4px;display:inline-block;margin-left:8px;vertical-align:middle;}
.status.pass{background:#dcfce7;color:#14532d;}
.status.fail{background:#fee2e2;color:#991b1b;}
table{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:8px;}
td{padding:6px 10px;border-bottom:1px solid #f1f5f9;vertical-align:top;}
td:first-child{color:#64748b;width:32%;font-weight:500;}
.note{margin-top:10px;padding:10px 12px;background:#eff6ff;border-left:3px solid #039fe1;border-radius:6px;font-size:13px;}
.note.warn{background:#fef3c7;border-color:#f59e0b;color:#78350f;}
code,.path{background:#1e293b;color:#e2e8f0;padding:2px 7px;border-radius:4px;font-size:11.5px;font-family:ui-monospace,Menlo,monospace;word-break:break-all;}
.iframe-test{background:#fff;padding:18px;border-radius:10px;border:2px solid #039fe1;margin-top:18px;}
.iframe-test iframe{width:100%;height:300px;border:1px solid #ccc;border-radius:6px;background:#f8fafc;display:block;margin-top:10px;}
.console{font-family:ui-monospace,Menlo,monospace;background:#0c2340;color:#a7f3d0;padding:12px;border-radius:6px;font-size:11.5px;margin-top:12px;max-height:240px;overflow-y:auto;line-height:1.6;}
.console .info{color:#60a5fa;}
.console .ok  {color:#22c55e;}
.console .err {color:#ef4444;}
.console .warn{color:#fbbf24;}
.btn{display:inline-block;padding:9px 16px;background:#039fe1;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;font-size:13px;border:none;cursor:pointer;margin-right:6px;}
.btn.green{background:#22c55e;}
.btn.gray{background:#64748b;}
</style>
</head>
<body>

<h1>🔬 Truora Integration · Diagnóstico Completo</h1>
<h2>Detecta automáticamente el motivo por el que el iframe no carga</h2>

<?php if ($allOk): ?>
  <div class="banner ok">
    ✓ <strong>Todos los chequeos del lado servidor pasaron.</strong> Si el iframe sigue fallando en el navegador, es un problema de cliente: caché, CSP en cabeceras de aplicación, o cookie de tercer-tipo. Mira el test del iframe abajo.
  </div>
<?php else: ?>
  <div class="banner err">
    ⚠ <strong>Se detectaron problemas.</strong> Revisa la columna NOTE de cada chequeo abajo y aplica la acción indicada.
  </div>
<?php endif; ?>

<?php foreach ($checks as $c): ?>
  <div class="check">
    <h3>
      <?= htmlspecialchars($c['name']) ?>
      <span class="status <?= $c['ok'] ? 'pass' : 'fail' ?>"><?= $c['ok'] ? '✓ PASA' : '✗ FALLA' ?></span>
    </h3>
    <table>
      <?php foreach ($c['detail'] as $k => $v): ?>
        <tr>
          <td><?= htmlspecialchars($k) ?></td>
          <td><code><?= htmlspecialchars((string)$v) ?></code></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <div class="note <?= $c['ok'] ? '' : 'warn' ?>"><?= htmlspecialchars($c['note']) ?></div>
  </div>
<?php endforeach; ?>

<!-- ═══════════════════════════════════════════════════════════════════════
     CLIENT-SIDE LIVE IFRAME TEST
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="iframe-test">
  <h3>🧪 Test en vivo del iframe (cliente)</h3>
  <p style="font-size:13px;color:#475569;margin:6px 0 12px;">
    Este test carga el iframe REAL y captura cada evento (load, error, postMessage, CSP violations).
    Si el iframe se rinde correctamente, lo verás abajo. Si no, el panel de la consola te dice exactamente por qué.
  </p>
  <div>
    <button class="btn green" onclick="runIframeTest()">▶ Iniciar test del iframe</button>
    <button class="btn gray" onclick="document.getElementById('diagConsole').innerHTML='';">Limpiar consola</button>
    <a class="btn" href="<?= htmlspecialchars($iframeUrlReturned) ?>" target="_blank" rel="noopener">↗ Abrir iframe URL en nueva pestaña</a>
  </div>

  <div id="iframeContainer"></div>
  <div id="diagConsole" class="console">[Consola lista — presiona "Iniciar test"]</div>
</div>

<script>
(function(){
  var iframeUrl  = <?= json_encode($iframeUrlReturned) ?>;
  var truoraHost = 'identity.truora.com';
  var con = document.getElementById('diagConsole');
  function log(level, msg) {
    var t = new Date().toISOString().substr(11,12);
    con.innerHTML += '<div class="' + level + '">[' + t + '] ' + msg + '</div>';
    con.scrollTop = con.scrollHeight;
  }

  // CSP violation listener — captures any CSP-blocked iframe load
  document.addEventListener('securitypolicyviolation', function(e) {
    log('err', 'CSP VIOLATION: directive=' + e.violatedDirective +
                ' · blocked=' + e.blockedURI +
                ' · effective=' + e.effectiveDirective);
  });

  // postMessage listener — Truora signals readiness via postMessage
  window.addEventListener('message', function(e) {
    if (e.origin && e.origin.indexOf('truora') !== -1) {
      log('ok', 'postMessage de Truora recibido. origin=' + e.origin +
              ' · data=' + (typeof e.data === 'string' ? e.data : JSON.stringify(e.data).substr(0,120)));
    }
  });

  window.runIframeTest = function() {
    if (!iframeUrl) {
      log('err', 'iframe_url vacío — el token no se generó. Revisa el chequeo #3.');
      return;
    }
    log('info', 'Construyendo iframe → ' + iframeUrl.substr(0, 80) + '…');
    var c = document.getElementById('iframeContainer');
    c.innerHTML = '';
    var f = document.createElement('iframe');
    f.id = 'diag-truora-iframe';
    f.src = iframeUrl;
    f.allow = 'camera; microphone; geolocation';
    f.style.cssText = 'width:100%;height:300px;border:1px solid #ccc;border-radius:6px;background:#f8fafc;display:block;margin-top:10px;';
    var t0 = Date.now();
    f.addEventListener('load', function() {
      var dt = Date.now() - t0;
      log('info', 'Evento `load` disparado en ' + dt + 'ms.');
      try {
        var href = this.contentWindow && this.contentWindow.location && this.contentWindow.location.href;
        if (!href || href === 'about:blank') {
          log('err', 'iframe.contentWindow.location.href = "' + href + '" — el embebido fue BLOQUEADO por X-Frame-Options o frame-ancestors. El iframe se cargó pero quedó vacío.');
          log('warn', 'SOLUCIÓN: pídele a Truora support que agregue "' + location.host + '" al whitelist del flow_id (puede no ser el mismo que el dominio principal).');
        } else {
          log('ok', 'iframe leyó href = "' + href + '" (mismo origen — algo extraño)');
        }
      } catch (e) {
        log('ok', 'SecurityError al leer contentWindow.location → contenido cross-origin de Truora cargado correctamente. ✓');
        log('info', 'El iframe está mostrando contenido. Si lo ves vacío, ahora es un problema visual interno de Truora, no de embebido.');
      }
    });
    f.addEventListener('error', function() {
      log('err', 'Evento `error` disparado en ' + (Date.now()-t0) + 'ms — fallo de red o conexión rechazada.');
    });
    c.appendChild(f);
    log('info', 'iframe insertado en el DOM. Esperando carga…');

    // Hard timeout: if neither load nor error fires in 12s, network is dead.
    setTimeout(function() {
      log('warn', 'Timeout 12s — sin eventos. Probable bloqueo de red o el navegador rechazó la conexión silenciosamente.');
    }, 12000);
  };

  // Browser environment quick checks
  log('info', 'Page origin: ' + location.origin);
  log('info', 'Page host: ' + location.host);
  log('info', 'Protocol: ' + location.protocol + (location.protocol === 'http:' ? ' ⚠️ MIXED CONTENT (Truora es HTTPS)' : ' ✓'));
  log('info', 'navigator.cookieEnabled: ' + navigator.cookieEnabled);
  log('info', 'document.cookie length: ' + document.cookie.length + ' chars');
  if (window.crossOriginIsolated !== undefined) {
    log('info', 'crossOriginIsolated: ' + window.crossOriginIsolated);
  }
})();
</script>

<div style="margin-top:24px;padding:14px 18px;background:#fef3c7;border-radius:10px;border:1px solid #f59e0b;">
  <strong>📌 Cómo usar este diagnóstico:</strong>
  <ol style="margin:8px 0 0 18px;padding:0;font-size:13px;">
    <li>Mira los 4 chequeos del servidor arriba — cualquier ✗ FALLA tiene la solución exacta en su NOTE.</li>
    <li>Si todos pasan ✓ pero el iframe sigue fallando, presiona <b>Iniciar test del iframe</b> abajo.</li>
    <li>La consola te dirá si el iframe se cargó pero quedó bloqueado, si hubo CSP violation, o si el navegador rechazó la conexión.</li>
    <li>Comparte la captura de la consola con soporte si nada funciona — tienen toda la info para resolverlo.</li>
  </ol>
</div>

</body>
</html>
