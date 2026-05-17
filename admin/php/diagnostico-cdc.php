<?php
/**
 * Voltika Admin — Round 50 comprehensive CDC diagnostic (2026-05-16).
 *
 * Standalone diagnostic for "CDC disconnected" incident. Verifies every
 * CDC requirement (credentials, certificate, signature) AND runs a live
 * test query against /v2/rccficoscore so the admin sees the EXACT HTTP
 * response CDC returns. Bypasses adminRequireAuth via the same shared
 * secret as the other diag tools.
 *
 * URL:
 *   https://voltika.mx/admin/php/diagnostico-cdc.php?key=voltika_diag_2026
 *
 * Sections:
 *   1. All CDC config: api_key length, user, pass structure, folio, base URL
 *   2. Private key + certificate presence (DB and disk)
 *   3. Live test query — sends a real /v2/rccficoscore call with synthetic
 *      but well-formed data and captures full request/response
 *   4. Recent cdc_query_log entries (last 10)
 *
 * Once CDC is verified working, this file can be deleted.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// Load config so CDC_* constants are defined.
foreach ([__DIR__ . '/../../configurador/php/config.php',
          __DIR__ . '/../../configurador_prueba_test/php/config.php'] as $cfg) {
    if (is_file($cfg)) { @require_once $cfg; break; }
}

$expected = 'voltika_diag_2026';
$key = $_GET['key'] ?? $_POST['key'] ?? '';
if (!hash_equals($expected, (string)$key)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Acceso denegado. Usa ?key=<secret>";
    exit;
}

$pdo = getDB();

// Helpers
function maskValue(string $v, int $tail = 6): string {
    $len = strlen($v);
    if ($len === 0) return '(vacío)';
    if ($len <= $tail) return str_repeat('*', $len);
    return str_repeat('*', $len - $tail) . substr($v, -$tail);
}

function passStructure(string $p): array {
    $upper = 0; $lower = 0; $digit = 0; $special = 0; $other = 0;
    $len = strlen($p);
    for ($i = 0; $i < $len; $i++) {
        $c = ord($p[$i]);
        if ($c >= 0x30 && $c <= 0x39)      $digit++;
        elseif ($c >= 0x41 && $c <= 0x5A)  $upper++;
        elseif ($c >= 0x61 && $c <= 0x7A)  $lower++;
        elseif ($c >= 0x20 && $c < 0x7F)   $special++;
        else                                $other++;
    }
    return compact('len','upper','lower','digit','special','other');
}

// ─────────────────────────────────────────────────────────────────────────
// POST: run a live CDC test query
// ─────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'live_test')) {
    header('Content-Type: application/json; charset=utf-8');

    // Build synthetic but well-formed test data. Using a real-looking
    // sample so CDC doesn't reject for validation reasons — that way the
    // only failure modes left are auth-related.
    $body = [
        'primerNombre'    => trim((string)($_POST['primerNombre']    ?? 'JUAN')),
        'apellidoPaterno' => trim((string)($_POST['apellidoPaterno'] ?? 'PEREZ')),
        'apellidoMaterno' => trim((string)($_POST['apellidoMaterno'] ?? 'LOPEZ')),
        'fechaNacimiento' => trim((string)($_POST['fechaNacimiento'] ?? '1990-01-15')),
        'nacionalidad'    => 'MX',
        'domicilio' => [
            'direccion'           => 'CALLE FALSA 123',
            'coloniaPoblacion'    => 'CENTRO',
            'delegacionMunicipio' => 'BENITO JUAREZ',
            'ciudad'              => 'CIUDAD DE MEXICO',
            'estado'              => 'CDMX',
            'CP'                  => '03100',
        ],
    ];
    $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE);

    // Load private key (same resolution order as consultar-buro.php).
    $keyPem  = null;
    $certPem = null;
    try {
        $row = $pdo->query("SELECT private_key, certificate FROM cdc_certificates WHERE active = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) { $keyPem = $row['private_key']; $certPem = $row['certificate']; }
    } catch (Throwable $e) {}
    $kFile = __DIR__ . '/../../configurador/php/certs/cdc_private.key';
    $cFile = __DIR__ . '/../../configurador/php/certs/cdc_certificate.pem';
    if (!$keyPem  && file_exists($kFile))  $keyPem  = @file_get_contents($kFile);
    if (!$certPem && file_exists($cFile)) $certPem = @file_get_contents($cFile);

    if (!$keyPem) {
        echo json_encode(['ok' => false, 'error' => 'PRIVATE KEY MISSING — no se encontró en DB ni en disco',
                          'hint' => 'Sin private key NO se puede firmar x-signature → CDC rechazará con 403/503']);
        exit;
    }

    // Sign the body with SHA256 + RSA (CDC's x-signature spec).
    $sig = '';
    $signErr = null;
    $ok = @openssl_sign($jsonBody, $sigBin, $keyPem, OPENSSL_ALGO_SHA256);
    if (!$ok) {
        $signErr = openssl_error_string() ?: 'unknown openssl error';
    } else {
        $sig = bin2hex($sigBin);
    }

    if (!$sig) {
        echo json_encode(['ok' => false, 'error' => 'No se pudo firmar el body',
                          'openssl_error' => $signErr]);
        exit;
    }

    // Build headers exactly like consultar-buro.php does.
    $apiKey = defined('CDC_API_KEY') ? CDC_API_KEY : '';
    $user   = defined('CDC_USER')    ? CDC_USER    : '';
    $pass   = defined('CDC_PASS')    ? CDC_PASS    : '';
    $url    = defined('CDC_BASE_URL') ? CDC_BASE_URL : 'https://services.circulodecredito.com.mx/v2/rccficoscore';

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'x-api-key: ' . $apiKey,
    ];
    $headersInfo = [
        'Content-Type' => 'application/json',
        'Accept'       => 'application/json',
        'x-api-key'    => maskValue($apiKey),
        'username'     => $user !== '' ? maskValue($user) : '(NO ENVIADO — vacío)',
        'password'     => $pass !== '' ? maskValue($pass, 4) : '(NO ENVIADO — vacío)',
        'x-signature'  => substr($sig, 0, 16) . '... (' . strlen($sig) . ' hex chars)',
    ];
    if ($user !== '') $headers[] = 'username: ' . $user;
    if ($pass !== '') $headers[] = 'password: ' . $pass;
    $headers[] = 'x-signature: ' . $sig;

    // Capture response headers too.
    $respHeaderLines = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $jsonBody,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HEADERFUNCTION => function ($_c, $h) use (&$respHeaderLines) {
            $respHeaderLines[] = trim($h); return strlen($h);
        },
    ]);
    $start = microtime(true);
    $resp  = curl_exec($ch);
    $took  = round((microtime(true) - $start) * 1000);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $parsed = is_string($resp) ? json_decode($resp, true) : null;

    // Audit log
    try {
        $pdo->prepare("INSERT INTO admin_log (usuario_id, accion, detalle, ip)
                       VALUES (0, 'diagnostico_cdc_test', ?, ?)")
            ->execute([json_encode([
                'http'         => $httpCode,
                'took_ms'      => $took,
                'curl_err'     => $curlErr,
                'resp_short'   => substr((string)$resp, 0, 800),
                'user_sent'    => $user !== '',
                'pass_sent'    => $pass !== '',
                'sig_present'  => $sig !== '',
                'url'          => $url,
            ], JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) {}

    // Interpret the failure mode
    $diagnosis = null;
    if ($curlErr) {
        $diagnosis = 'NETWORK/SSL: curl falló antes de conectar — ' . $curlErr;
    } elseif ($httpCode === 401) {
        $diagnosis = 'AUTH (401): CDC rechazó las credenciales. La password / username / api-key no es válida en su sistema.';
    } elseif ($httpCode === 403) {
        $diagnosis = 'AUTH (403): CDC reconoció las credenciales pero las rechazó (firma inválida, IP bloqueada, o cuenta deshabilitada).';
    } elseif ($httpCode === 503) {
        $diagnosis = 'SERVER (503): CDC no disponible — puede ser firma faltante, proxy rechazando headers especiales, o problema temporal de CDC.';
    } elseif ($httpCode === 200 && is_array($parsed) && ($parsed['success'] ?? null) === true) {
        $diagnosis = '✓ CDC RESPONDIÓ OK — credenciales aceptadas, score recibido.';
    } elseif ($httpCode === 200) {
        $diagnosis = 'CDC respondió 200 pero el body no indica success — revisa el cuerpo abajo.';
    } else {
        $diagnosis = 'HTTP ' . $httpCode . ' — revisa cuerpo de respuesta abajo.';
    }

    echo json_encode([
        'ok'                => $httpCode >= 200 && $httpCode < 300 && !$curlErr && is_array($parsed) && !empty($parsed['success']),
        'http'              => $httpCode,
        'took_ms'           => $took,
        'diagnosis'         => $diagnosis,
        'request' => [
            'url'         => $url,
            'headers'     => $headersInfo,
            'body'        => $body,
            'body_json'   => $jsonBody,
        ],
        'response' => [
            'http'         => $httpCode,
            'curl_err'     => $curlErr,
            'headers_raw'  => $respHeaderLines,
            'body_raw'     => $resp,
            'body_parsed'  => $parsed,
        ],
    ], JSON_PRETTY_PRINT);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// GET: dashboard
// ─────────────────────────────────────────────────────────────────────────

$apiKey = defined('CDC_API_KEY') ? CDC_API_KEY : '';
$user   = defined('CDC_USER')    ? CDC_USER    : '';
$pass   = defined('CDC_PASS')    ? CDC_PASS    : '';
$folio  = defined('CDC_FOLIO')   ? CDC_FOLIO   : '';
$url    = defined('CDC_BASE_URL') ? CDC_BASE_URL : 'https://services.circulodecredito.com.mx/v2/rccficoscore';

$passS = passStructure($pass);

// Certificate / private key status
$kFile = realpath(__DIR__ . '/../../configurador/php/certs/cdc_private.key');
$cFile = realpath(__DIR__ . '/../../configurador/php/certs/cdc_certificate.pem');
$keyOnDisk  = $kFile && is_file($kFile);
$certOnDisk = $cFile && is_file($cFile);

$keyInDb = false;
$certInDb = false;
try {
    $row = $pdo->query("SELECT private_key, certificate FROM cdc_certificates WHERE active = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) { $keyInDb = !empty($row['private_key']); $certInDb = !empty($row['certificate']); }
} catch (Throwable $e) {}

// Recent CDC query log
$recentCdc = [];
try {
    $st = $pdo->query("SELECT id, freg, url, http_code, signature_sent, request_body, response_body, curl_error
                         FROM cdc_query_log
                        ORDER BY id DESC LIMIT 10");
    if ($st) $recentCdc = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Voltika — Diagnóstico CDC</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fb;color:#0c2340;padding:24px;max-width:1080px;margin:0 auto;line-height:1.5;}
h1{font-size:22px;margin:0 0 4px;} h2{font-size:14px;color:#475569;margin:24px 0 10px;text-transform:uppercase;letter-spacing:.4px;}
.muted{color:#94a3b8;font-size:11.5px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:14px;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th{text-align:left;padding:8px 6px;border-bottom:2px solid #cbd5e1;color:#475569;font-weight:700;font-size:12px;}
td{padding:8px 6px;border-bottom:1px solid #f1f5f9;vertical-align:top;}
code{background:#f1f5f9;color:#1e293b;padding:1px 5px;border-radius:3px;font-size:11.5px;font-family:ui-monospace,monospace;word-break:break-all;}
.ok{color:#16a34a;font-weight:700;} .bad{color:#dc2626;font-weight:700;} .warn{color:#d97706;font-weight:700;}
.banner{padding:12px 14px;border-radius:8px;font-size:13px;margin:12px 0;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.banner-bad{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
.banner-warn{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;}
.kv td:first-child{width:200px;color:#64748b;font-weight:700;font-size:12px;}
button{background:#039fe1;color:#fff;border:0;padding:10px 22px;border-radius:6px;font-weight:700;cursor:pointer;font-size:13px;}
input{padding:7px 11px;border:1px solid #cbd5e1;border-radius:5px;font-size:13px;width:200px;font-family:inherit;}
pre{background:#0b1322;color:#e2e8f0;padding:10px;border-radius:6px;font-size:11px;overflow-x:auto;max-height:320px;}
</style></head><body>

<h1>🔬 Diagnóstico CDC (Círculo de Crédito)</h1>
<div class="muted">Round 50 · servidor <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '') ?> · generado <?= date('Y-m-d H:i:s') ?></div>

<h2>1. Credenciales en runtime</h2>
<div class="card">
  <table class="kv">
    <tr><td>URL endpoint</td><td><code><?= htmlspecialchars($url) ?></code></td></tr>
    <tr><td>CDC_API_KEY</td><td>
      <?php if ($apiKey): ?>
        <span class="ok">✓</span> longitud <?= strlen($apiKey) ?> · <code><?= htmlspecialchars(maskValue($apiKey)) ?></code>
      <?php else: ?>
        <span class="bad">✗ VACÍO</span> — CDC rechazará la petición sin el header x-api-key
      <?php endif; ?>
    </td></tr>
    <tr><td>CDC_USER (header username:)</td><td>
      <?php if ($user): ?>
        <span class="ok">✓</span> longitud <?= strlen($user) ?> · <code><?= htmlspecialchars(maskValue($user, 3)) ?></code>
      <?php else: ?>
        <span class="bad">✗ VACÍO</span> — header <code>username:</code> NO se envía → CDC v2 lo rechaza
      <?php endif; ?>
    </td></tr>
    <tr><td>CDC_PASS (header password:)</td><td>
      <?php if ($pass): ?>
        <span class="ok">✓</span> longitud <?= $passS['len'] ?> · estructura:
        <?= $passS['upper'] ?> upper · <?= $passS['lower'] ?> lower · <?= $passS['digit'] ?> digit · <?= $passS['special'] ?> special<?= $passS['other'] ? ' · ' . $passS['other'] . ' OTRO' : '' ?>
        <?php if ($passS['len'] === 12 && $passS['upper'] === 2 && $passS['lower'] === 4 && $passS['digit'] === 4 && $passS['special'] === 2): ?>
          <br><span class="ok">✓ Coincide con "VoltiK2026#$" (12 chars, 2U+4L+4D+2S)</span>
        <?php elseif ($passS['len'] === 14 && $passS['upper'] === 6 && $passS['lower'] === 2 && $passS['digit'] === 4 && $passS['special'] === 2): ?>
          <br><span class="warn">⚠ Coincide con la contraseña vieja "#KbC%Ro5XMM046" (14 chars)</span>
        <?php else: ?>
          <br><span class="warn">⚠ No coincide con "VoltiK2026#$" ni con la vieja</span>
        <?php endif; ?>
      <?php else: ?>
        <span class="bad">✗ VACÍO</span> — header password: NO se envía → CDC rechaza
      <?php endif; ?>
    </td></tr>
    <tr><td>CDC_FOLIO</td><td>
      <?php if ($folio): ?>
        <code><?= htmlspecialchars(maskValue($folio, 4)) ?></code>
      <?php else: ?>
        <span class="warn">vacío</span> — no es estrictamente requerido para la API v2 pero se usa en logs
      <?php endif; ?>
    </td></tr>
  </table>
</div>

<h2>2. Llave privada + certificado (para x-signature)</h2>
<div class="card">
  <table class="kv">
    <tr><td>cdc_private.key en disco</td><td><?= $keyOnDisk ? '<span class="ok">✓ ' . htmlspecialchars($kFile) . '</span>' : '<span class="warn">no</span>' ?></td></tr>
    <tr><td>cdc_certificate.pem en disco</td><td><?= $certOnDisk ? '<span class="ok">✓ ' . htmlspecialchars($cFile) . '</span>' : '<span class="warn">no</span>' ?></td></tr>
    <tr><td>private_key en cdc_certificates DB</td><td><?= $keyInDb ? '<span class="ok">✓ presente</span>' : '<span class="warn">no</span>' ?></td></tr>
    <tr><td>certificate en cdc_certificates DB</td><td><?= $certInDb ? '<span class="ok">✓ presente</span>' : '<span class="warn">no</span>' ?></td></tr>
  </table>
  <?php if (!$keyOnDisk && !$keyInDb): ?>
    <div class="banner banner-bad" style="margin-top:10px;">
      ✗ <strong>SIN PRIVATE KEY:</strong> x-signature no se puede generar → CDC responderá 403 garantizado.
      Regenera con <code>generar-certificado-cdc.php?key=voltika_cdc_cert_2026&amp;regen=1</code>.
    </div>
  <?php endif; ?>
</div>

<h2>3. Prueba en vivo — enviar una consulta real a CDC</h2>
<div class="card">
  <p style="margin-top:0;color:#475569;font-size:13px;">
    Se envía una petición sintética bien-formada a <code>/v2/rccficoscore</code> con tus credenciales actuales.
    La respuesta exacta de CDC te dirá si el problema es <strong>password incorrecto</strong>,
    <strong>username faltante</strong>, <strong>firma inválida</strong>, o <strong>cuenta deshabilitada</strong>.
  </p>
  <form id="testForm" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
    <input type="hidden" name="key" value="<?= htmlspecialchars($expected) ?>">
    <input type="hidden" name="action" value="live_test">
    <label>Nombre <input name="primerNombre" value="JUAN"></label>
    <label>Apellido P <input name="apellidoPaterno" value="PEREZ"></label>
    <label>Apellido M <input name="apellidoMaterno" value="LOPEZ"></label>
    <label>Nacimiento <input name="fechaNacimiento" value="1990-01-15"></label>
    <button type="submit">🔍 Consultar CDC ahora</button>
  </form>
  <div id="testStatus" style="margin-top:14px;font-size:13px;"></div>
  <div id="testResult"></div>
</div>

<h2>4. Últimos 10 intentos de consulta CDC (cdc_query_log)</h2>
<div class="card">
  <?php if (empty($recentCdc)): ?>
    <div class="muted">Sin registros en cdc_query_log.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Fecha</th><th>HTTP</th><th>Firma</th><th>Respuesta (corta)</th></tr></thead>
      <tbody>
        <?php foreach ($recentCdc as $r): ?>
          <tr>
            <td class="muted" style="white-space:nowrap;"><?= htmlspecialchars((string)$r['freg']) ?></td>
            <td>
              <?php $h = (int)$r['http_code']; ?>
              <strong style="color:<?= $h >= 200 && $h < 300 ? '#16a34a' : '#dc2626' ?>"><?= $h ?></strong>
            </td>
            <td><?= ((int)$r['signature_sent'] === 1) ? '<span class="ok">✓</span>' : '<span class="bad">✗</span>' ?></td>
            <td><code style="font-size:10.5px;"><?= htmlspecialchars(substr((string)$r['response_body'], 0, 250)) ?></code></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<script>
document.getElementById('testForm').addEventListener('submit', function(e){
  e.preventDefault();
  var form = e.target;
  var status = document.getElementById('testStatus');
  var result = document.getElementById('testResult');
  status.textContent = '⏳ Consultando CDC...'; result.innerHTML = '';
  var fd = new FormData(form);
  fetch(location.pathname, { method: 'POST', credentials: 'include', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(j){
      var cls = j.ok ? 'banner-ok' : (j.http === 401 || j.http === 403 ? 'banner-bad' : 'banner-warn');
      var head = '<div class="banner ' + cls + '">' +
        '<strong>HTTP ' + j.http + '</strong> · ' + j.took_ms + ' ms<br>' +
        (j.diagnosis || '') + '</div>';
      var sections = '';
      sections += '<div style="margin-top:14px;"><strong>Request enviado a CDC:</strong><pre>' + JSON.stringify(j.request, null, 2).replace(/[<>&]/g, function(c){return {'<':'&lt;','>':'&gt;','&':'&amp;'}[c]}) + '</pre></div>';
      sections += '<div><strong>Response recibida:</strong><pre>' + JSON.stringify(j.response, null, 2).replace(/[<>&]/g, function(c){return {'<':'&lt;','>':'&gt;','&':'&amp;'}[c]}) + '</pre></div>';
      status.textContent = '';
      result.innerHTML = head + sections;
    })
    .catch(function(e){
      status.textContent = '✗ ' + e.message;
    });
});
</script>

</body></html>
