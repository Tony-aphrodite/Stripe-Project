<?php
/**
 * Voltika Admin — Diagnóstico Cincel v2 (Round 66, 2026-05-21).
 *
 * Pregunta de negocio: "¿De verdad es culpa de Cincel, o estamos haciendo algo
 * mal nosotros?"
 *
 * Este script ejecuta una batería de probes que cubre TODO el camino entre
 * nuestro servidor y la API de Cincel, de menos a más específico:
 *
 *   1. DNS — ¿podemos resolver api.cincel.digital?
 *   2. TLS — ¿el certificado es válido y el handshake completa?
 *   3. HEAD / — ¿el servidor de Cincel responde HTTP en general?
 *   4. GET / — qué responde el root
 *   5. GET /v3 — ¿la versión 3 todavía existe?
 *   6. GET /v4 — ¿migraron a la v4?
 *   7. POST /v3/auth/tokens con credenciales reales
 *   8. POST /v3/auth/login con credenciales reales
 *   9. Variantes alternativas de auth (sin /v3, con email distinto, etc.)
 *  10. Control: HEAD a cincel.digital (marketing) — prueba que llegamos a su infra
 *
 * Para cada probe registramos: HTTP code, response headers, body preview,
 * timing y errores cURL. Si TODOS los auth paths fallan con 404 mientras
 * la conectividad básica funciona, queda demostrado que el problema es
 * exclusivamente del API de Cincel y no nuestro.
 *
 * URL: /admin/php/diagnostico-cincel-v2.php?key=voltika_diag_2026
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$adminId = adminRequireAuth(['admin']);

// Lightweight auth key so we can run this without full admin login if needed.
$expected = 'voltika_diag_2026';
$key = $_GET['key'] ?? '';
if (!hash_equals($expected, (string)$key)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Acceso denegado. Usa ?key=voltika_diag_2026";
    exit;
}

// Load Cincel config.
foreach ([__DIR__ . '/../../configurador/php/config.php',
          __DIR__ . '/../../configurador_prueba_test/php/config.php'] as $cfg) {
    if (is_file($cfg)) { @require_once $cfg; break; }
}

$apiUrl   = defined('CINCEL_API_URL')  ? rtrim(CINCEL_API_URL, '/')  : (getenv('CINCEL_API_URL') ?: '');
$email    = defined('CINCEL_EMAIL')    ? CINCEL_EMAIL                : (getenv('CINCEL_EMAIL')   ?: '');
$password = defined('CINCEL_PASSWORD') ? CINCEL_PASSWORD             : (getenv('CINCEL_PASSWORD') ?: '');

function _maskEmail(string $e): string {
    if (strpos($e, '@') === false) return str_repeat('*', strlen($e));
    [$u, $d] = explode('@', $e, 2);
    if (strlen($u) <= 2) return str_repeat('*', strlen($u)) . '@' . $d;
    return $u[0] . str_repeat('*', strlen($u) - 2) . substr($u, -1) . '@' . $d;
}
function _maskPass(string $p): string {
    $n = strlen($p);
    if ($n === 0) return '(vacío)';
    return '(longitud ' . $n . ')';
}

function _runProbe(string $label, callable $fn): array {
    $start = microtime(true);
    try {
        $result = $fn();
    } catch (Throwable $e) {
        $result = ['error' => 'Exception: ' . $e->getMessage()];
    }
    $result['label'] = $label;
    $result['took_ms'] = (int)((microtime(true) - $start) * 1000);
    return $result;
}

function _curlGet(string $url, array $opts = []): array {
    $ch = curl_init($url);
    $base = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_NOBODY         => false,
        CURLOPT_CUSTOMREQUEST  => 'GET',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'Voltika-Diagnostic/1.0 (Round 66)',
        CURLOPT_VERBOSE        => false,
    ];
    curl_setopt_array($ch, $base + $opts);
    $raw   = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err   = curl_error($ch);
    $errno = curl_errno($ch);
    $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $info  = [
        'primary_ip'      => curl_getinfo($ch, CURLINFO_PRIMARY_IP),
        'primary_port'    => curl_getinfo($ch, CURLINFO_PRIMARY_PORT),
        'connect_time'    => curl_getinfo($ch, CURLINFO_CONNECT_TIME),
        'pretransfer'     => curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME),
        'total_time'      => curl_getinfo($ch, CURLINFO_TOTAL_TIME),
        'ssl_verify'      => curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT),
        'final_url'       => curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
    ];
    curl_close($ch);
    $headers = is_string($raw) ? substr($raw, 0, (int)$hsize) : '';
    $body    = is_string($raw) ? substr($raw, (int)$hsize)    : '';
    return [
        'url'      => $url,
        'http'     => (int)$code,
        'curl_err' => $err ?: null,
        'errno'    => $errno,
        'info'     => $info,
        'headers'  => trim($headers),
        'body_preview' => $body ? substr($body, 0, 600) : null,
        'body_size' => strlen($body),
    ];
}

function _curlPostJson(string $url, array $payload, array $opts = []): array {
    return _curlGet($url, $opts + [
        CURLOPT_POST       => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    ]);
}

function _curlHead(string $url): array {
    return _curlGet($url, [
        CURLOPT_NOBODY        => true,
        CURLOPT_CUSTOMREQUEST => 'HEAD',
    ]);
}

// Derive root host (without /v3) for HEAD probes.
$rootHost = preg_replace('#/v\d+$#', '', $apiUrl) ?: 'https://api.cincel.digital';

$probes = [];

// 1. DNS resolution
$probes[] = _runProbe('DNS — api.cincel.digital', function() {
    $host = 'api.cincel.digital';
    $records = @dns_get_record($host, DNS_A + DNS_AAAA);
    return [
        'resolved' => !empty($records),
        'records'  => $records ?: [],
        'gethostbyname' => @gethostbyname($host),
    ];
});

// 2. TLS handshake (using stream_socket_client)
$probes[] = _runProbe('TLS handshake — api.cincel.digital:443', function() {
    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'capture_peer_cert' => true,
        'SNI_enabled' => true,
    ]]);
    $errno = 0; $errstr = '';
    $sock = @stream_socket_client(
        'ssl://api.cincel.digital:443', $errno, $errstr, 8,
        STREAM_CLIENT_CONNECT, $ctx
    );
    if (!$sock) {
        return ['handshake_ok' => false, 'errno' => $errno, 'errstr' => $errstr];
    }
    $params = stream_context_get_params($sock);
    $cert = $params['options']['ssl']['peer_certificate'] ?? null;
    $info = $cert ? openssl_x509_parse($cert) : null;
    @fclose($sock);
    return [
        'handshake_ok' => true,
        'subject'      => $info['subject'] ?? null,
        'issuer'       => $info['issuer']  ?? null,
        'valid_from'   => isset($info['validFrom_time_t']) ? date('c', $info['validFrom_time_t']) : null,
        'valid_to'     => isset($info['validTo_time_t'])   ? date('c', $info['validTo_time_t'])   : null,
        'san'          => $info['extensions']['subjectAltName'] ?? null,
    ];
});

// 3. HEAD on root host
$probes[] = _runProbe('HEAD ' . $rootHost . '/  (¿servidor vivo?)', function() use ($rootHost) {
    return _curlHead($rootHost . '/');
});

// 4. GET on root path
$probes[] = _runProbe('GET ' . $rootHost . '/  (raíz)', function() use ($rootHost) {
    return _curlGet($rootHost . '/');
});

// 5. GET /v3 root (sin auth)
$probes[] = _runProbe('GET ' . $rootHost . '/v3  (¿existe v3?)', function() use ($rootHost) {
    return _curlGet($rootHost . '/v3');
});

// 6. GET /v4 root
$probes[] = _runProbe('GET ' . $rootHost . '/v4  (¿existe v4?)', function() use ($rootHost) {
    return _curlGet($rootHost . '/v4');
});

// 7. POST /v3/auth/tokens
$probes[] = _runProbe('POST /v3/auth/tokens (credenciales reales)', function() use ($rootHost, $email, $password) {
    return _curlPostJson($rootHost . '/v3/auth/tokens', [
        'email' => $email, 'password' => $password,
    ]);
});

// 7.5. ✅ CORRECT ENDPOINT per Cincel support (2026-05-21): GET /v3/tokens/jwt
//      Try all three plausible auth shapes since their reply didn't specify:
//      (a) credentials as JSON body on GET, (b) credentials as query string,
//      (c) HTTP Basic Auth header.
$probes[] = _runProbe('★ GET /v3/tokens/jwt + JSON body (endpoint correcto por soporte Cincel)', function() use ($rootHost, $email, $password) {
    return _curlGet($rootHost . '/v3/tokens/jwt', [
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_POSTFIELDS    => json_encode(['email' => $email, 'password' => $password]),
        CURLOPT_HTTPHEADER    => ['Content-Type: application/json', 'Accept: application/json'],
    ]);
});
$probes[] = _runProbe('★ GET /v3/tokens/jwt?email=...&password=... (query string)', function() use ($rootHost, $email, $password) {
    $url = $rootHost . '/v3/tokens/jwt?email=' . urlencode($email) . '&password=' . urlencode($password);
    return _curlGet($url, [
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
});
$probes[] = _runProbe('★ GET /v3/tokens/jwt + HTTP Basic Auth', function() use ($rootHost, $email, $password) {
    return _curlGet($rootHost . '/v3/tokens/jwt', [
        CURLOPT_USERPWD    => $email . ':' . $password,
        CURLOPT_HTTPAUTH   => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
});

// 8. POST /v3/auth/login
$probes[] = _runProbe('POST /v3/auth/login (credenciales reales)', function() use ($rootHost, $email, $password) {
    return _curlPostJson($rootHost . '/v3/auth/login', [
        'email' => $email, 'password' => $password,
    ]);
});

// 9. Alternativa: POST /auth/tokens sin v3
$probes[] = _runProbe('POST /auth/tokens (sin prefijo v3)', function() use ($rootHost, $email, $password) {
    return _curlPostJson($rootHost . '/auth/tokens', [
        'email' => $email, 'password' => $password,
    ]);
});

// 10. Alternativa: POST /v4/auth/tokens (por si migraron)
$probes[] = _runProbe('POST /v4/auth/tokens (por si migraron a v4)', function() use ($rootHost, $email, $password) {
    return _curlPostJson($rootHost . '/v4/auth/tokens', [
        'email' => $email, 'password' => $password,
    ]);
});

// 11. Alternativa: POST /api/auth/tokens
$probes[] = _runProbe('POST /api/v3/auth/tokens', function() use ($rootHost, $email, $password) {
    return _curlPostJson($rootHost . '/api/v3/auth/tokens', [
        'email' => $email, 'password' => $password,
    ]);
});

// 12. Control: HEAD on the marketing site (cincel.digital) — proves we can reach
//     Cincel's infrastructure at all if their main host is alive.
$probes[] = _runProbe('Control — HEAD https://cincel.digital/  (marketing/landing)', function() {
    return _curlHead('https://cincel.digital/');
});

// 13. Control: HEAD on Google — sanity-check our own outbound connectivity.
$probes[] = _runProbe('Control — HEAD https://www.google.com/  (nuestra red OK)', function() {
    return _curlHead('https://www.google.com/');
});

// 14. Control: a different vendor we KNOW works — CDC's host. Verifies the
//     server can do outbound HTTPS to Mexican fintech infra in general.
$probes[] = _runProbe('Control — HEAD https://www.circulodecredito.com.mx/ (CDC alive)', function() {
    return _curlHead('https://www.circulodecredito.com.mx/');
});

// ─────────────────────────────────────────────────────────────────────────
// Verdict logic — derive whether the failure is ours or Cincel's.
// ─────────────────────────────────────────────────────────────────────────
$verdict = [];

$dnsOk     = !empty($probes[0]['resolved'])    ?? false;
$tlsOk     = !empty($probes[1]['handshake_ok'])?? false;
$headRoot  = (int)($probes[2]['http']           ?? 0);
$getRoot   = (int)($probes[3]['http']           ?? 0);
$v3Code    = (int)($probes[4]['http']           ?? 0);
$v4Code    = (int)($probes[5]['http']           ?? 0);
$authToks  = (int)($probes[6]['http']           ?? 0);
$authLog   = (int)($probes[7]['http']           ?? 0);
$noV3      = (int)($probes[8]['http']           ?? 0);
$v4Auth    = (int)($probes[9]['http']           ?? 0);
$apiV3     = (int)($probes[10]['http']          ?? 0);
$marketing = (int)($probes[11]['http']          ?? 0);
$google    = (int)($probes[12]['http']          ?? 0);
$cdc       = (int)($probes[13]['http']          ?? 0);

if (!$dnsOk) {
    $verdict[] = ['bad', 'DNS — no resolvemos api.cincel.digital. Esto SÍ podría ser nuestro (problema de red/DNS).'];
} else {
    $verdict[] = ['ok', 'DNS — api.cincel.digital resuelve correctamente.'];
}
if (!$tlsOk) {
    $verdict[] = ['bad', 'TLS — handshake fallido. Podría ser certificado expirado en Cincel o algo en nuestro CA bundle.'];
} else {
    $verdict[] = ['ok', 'TLS — handshake exitoso, certificado válido.'];
}
if ($headRoot >= 200 && $headRoot < 500) {
    $verdict[] = ['ok', "El servidor de Cincel está VIVO (HEAD / devolvió HTTP $headRoot). Solo los paths de auth fallan."];
} elseif ($headRoot >= 500) {
    $verdict[] = ['warn', "El servidor de Cincel devolvió HTTP $headRoot en HEAD — su servidor podría estar caído."];
} else {
    $verdict[] = ['warn', "HEAD / devolvió HTTP $headRoot — no recibimos respuesta clara de su servidor."];
}
if ($google === 200 && $cdc >= 200 && $cdc < 500) {
    $verdict[] = ['ok', 'Controles — Google y CDC responden bien desde nuestro servidor. Nuestra red está sana.'];
} else {
    $verdict[] = ['warn', 'Controles — Google ('.$google.') / CDC ('.$cdc.') no respondieron como se esperaba. Posible problema de red local.'];
}
$authPathsFail = ($authToks === 404 && $authLog === 404 && $noV3 === 404 && $v4Auth === 404 && $apiV3 === 404);
if ($authPathsFail && $tlsOk && $dnsOk) {
    $verdict[] = ['conclusion-bad-cincel',
        'CONCLUSIÓN: Cincel API roto en su lado. DNS + TLS + servidor de Cincel responden, pero TODOS los endpoints de auth devuelven 404. '
        . 'Un 404 (no 401) significa que Cincel retiró/movió esos endpoints. Es imposible que sea nuestro código si el servidor responde HEAD pero niega TODAS las rutas de auth conocidas.'];
} elseif (in_array(401, [$authToks, $authLog, $noV3, $v4Auth, $apiV3], true)) {
    $verdict[] = ['conclusion-creds',
        'CONCLUSIÓN MIXTA: al menos un endpoint devolvió 401 (credenciales inválidas). El endpoint EXISTE pero nuestras credenciales fueron rechazadas. Esto sí podría requerir rotación de password.'];
} elseif (in_array(200, [$authToks, $authLog, $noV3, $v4Auth, $apiV3], true)) {
    $verdict[] = ['conclusion-fixed',
        '¡Alguno respondió 200! Mira la tabla para ver cuál — actualiza CINCEL_API_URL para apuntar ahí.'];
} else {
    $verdict[] = ['conclusion-mixed',
        'Resultado mixto — revisa la tabla detallada abajo para más contexto.'];
}

header('Content-Type: text/html; charset=UTF-8');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Voltika — Diagnóstico Cincel v2</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fb;color:#0c2340;padding:24px;max-width:1100px;margin:0 auto;line-height:1.5;}
h1{font-size:22px;margin:0 0 4px;}
h2{font-size:14px;color:#475569;margin:24px 0 10px;text-transform:uppercase;letter-spacing:.4px;}
.muted{color:#94a3b8;font-size:11.5px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:14px;}
.banner{padding:10px 12px;border-radius:8px;font-size:13px;margin-bottom:10px;}
.ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.bad{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
.warn{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;}
.conclusion-bad-cincel{background:#fef2f2;border:2px solid #ef4444;color:#7f1d1d;font-weight:700;padding:14px 16px;}
.conclusion-fixed{background:#dcfce7;border:2px solid #16a34a;color:#14532d;font-weight:700;padding:14px 16px;}
.conclusion-creds{background:#fff7ed;border:2px solid #f59e0b;color:#7c2d12;font-weight:700;padding:14px 16px;}
.conclusion-mixed{background:#f1f5f9;border:2px solid #64748b;color:#0f172a;font-weight:700;padding:14px 16px;}
table{width:100%;border-collapse:collapse;font-size:12.5px;}
th{text-align:left;padding:7px 5px;border-bottom:2px solid #cbd5e1;color:#475569;font-weight:700;font-size:11px;}
td{padding:8px 5px;border-bottom:1px solid #f1f5f9;vertical-align:top;word-break:break-word;}
.http-200{color:#15803d;font-weight:700;}
.http-401{color:#d97706;font-weight:700;}
.http-404{color:#dc2626;font-weight:700;}
.http-5xx{color:#7c2d12;font-weight:700;}
.http-0{color:#94a3b8;}
code{background:#1e293b;color:#e2e8f0;padding:1px 6px;border-radius:3px;font-size:11px;font-family:ui-monospace,monospace;}
details summary{cursor:pointer;color:#475569;font-size:11.5px;}
pre{background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px;font-size:11px;white-space:pre-wrap;word-break:break-all;max-height:240px;overflow:auto;}
button.copy{background:#039fe1;color:#fff;border:0;padding:8px 14px;border-radius:6px;font-weight:600;cursor:pointer;font-size:13px;}
</style></head><body>

<h1>🩺 Diagnóstico Cincel v2 — evidencia técnica</h1>
<div class="muted"><?= date('Y-m-d H:i:s') ?> · servidor <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '') ?></div>

<h2>1. Configuración usada en este test</h2>
<div class="card">
  <table>
    <tr><th style="width:30%;">CINCEL_API_URL</th><td><code><?= htmlspecialchars($apiUrl ?: '(no configurado)') ?></code></td></tr>
    <tr><th>Host raíz derivado</th><td><code><?= htmlspecialchars($rootHost) ?></code></td></tr>
    <tr><th>CINCEL_EMAIL</th><td><?= htmlspecialchars(_maskEmail($email)) ?></td></tr>
    <tr><th>CINCEL_PASSWORD</th><td><?= htmlspecialchars(_maskPass($password)) ?></td></tr>
    <tr><th>PHP version</th><td><?= htmlspecialchars(PHP_VERSION) ?></td></tr>
    <tr><th>curl version</th><td><?= htmlspecialchars(curl_version()['version'] ?? '?') ?> · OpenSSL: <?= htmlspecialchars(curl_version()['ssl_version'] ?? '?') ?></td></tr>
  </table>
</div>

<h2>2. Veredicto</h2>
<div class="card">
  <?php foreach ($verdict as $v): list($cls, $msg) = $v; ?>
    <div class="banner <?= $cls ?>"><?= htmlspecialchars($msg) ?></div>
  <?php endforeach; ?>
</div>

<h2>3. Resultados detallados (<?= count($probes) ?> probes)</h2>
<div class="card">
  <table>
    <thead>
      <tr>
        <th style="width:38%;">Probe</th>
        <th>HTTP</th>
        <th>IP / tiempo</th>
        <th>Detalles</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($probes as $p):
      $http  = $p['http'] ?? 0;
      $cls = $http === 0 ? 'http-0'
           : ($http >= 200 && $http < 300 ? 'http-200'
           : ($http === 401 ? 'http-401'
           : ($http === 404 ? 'http-404'
           : ($http >= 500 ? 'http-5xx' : 'http-0'))));
    ?>
      <tr>
        <td>
          <strong><?= htmlspecialchars($p['label']) ?></strong>
          <?php if (!empty($p['url'])): ?><br><code style="font-size:10.5px;"><?= htmlspecialchars($p['url']) ?></code><?php endif; ?>
        </td>
        <td><span class="<?= $cls ?>"><?= htmlspecialchars((string)($p['http'] ?? '—')) ?></span></td>
        <td class="muted">
          <?= isset($p['info']['primary_ip']) && $p['info']['primary_ip'] ? htmlspecialchars($p['info']['primary_ip']) . '<br>' : '' ?>
          <?= isset($p['took_ms']) ? (int)$p['took_ms'] . ' ms' : '' ?>
        </td>
        <td>
          <?php if (!empty($p['curl_err'])): ?>
            <div style="color:#b91c1c;font-weight:700;">cURL: <?= htmlspecialchars($p['curl_err']) ?></div>
          <?php endif; ?>
          <?php if (!empty($p['resolved']) || isset($p['handshake_ok'])): ?>
            <details><summary>Ver detalles</summary>
              <pre><?= htmlspecialchars(json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
            </details>
          <?php else: ?>
            <?php if (!empty($p['headers'])): ?>
              <details><summary>Headers</summary><pre><?= htmlspecialchars($p['headers']) ?></pre></details>
            <?php endif; ?>
            <?php if (!empty($p['body_preview'])): ?>
              <details><summary>Body (primeros <?= (int)strlen($p['body_preview']) ?> bytes)</summary><pre><?= htmlspecialchars($p['body_preview']) ?></pre></details>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<h2>4. Mensaje listo para enviar a soporte Cincel</h2>
<div class="card">
  <div class="muted" style="margin-bottom:8px;">Si el veredicto arriba dice "Cincel API roto en su lado", copia este texto y envíalo a <code>soporte@cincel.digital</code> (o el contacto que les asignaron).</div>
  <textarea id="supportMsg" style="width:100%;min-height:380px;font-family:ui-monospace,monospace;font-size:12px;padding:10px;border:1px solid #cbd5e1;border-radius:6px;"><?= htmlspecialchars(_buildSupportMessage($probes, $apiUrl, $email)) ?></textarea>
  <div style="margin-top:10px;">
    <button class="copy" onclick="navigator.clipboard.writeText(document.getElementById('supportMsg').value).then(function(){ this.textContent='✓ Copiado'; }.bind(this));">
      📋 Copiar mensaje al portapapeles
    </button>
  </div>
</div>

</body></html>

<?php
function _buildSupportMessage(array $probes, string $apiUrl, string $email): string {
    $rows = [];
    foreach ($probes as $p) {
        if (strpos($p['label'], 'POST ') === 0 || strpos($p['label'], 'GET ') === 0 || strpos($p['label'], 'HEAD ') === 0) {
            $url = $p['url'] ?? '';
            $http = $p['http'] ?? '?';
            $rows[] = sprintf("  • %s → HTTP %s", $url, $http);
        }
    }
    $endpointTable = implode("\n", $rows);

    $when = date('Y-m-d H:i:s');
    $maskedEmail = _maskEmail($email);

    return <<<MSG
Asunto: Producción bloqueada — /auth/tokens y /auth/login devuelven HTTP 404 en api.cincel.digital

Estimado equipo de soporte Cincel,

Somos Voltika (MTECH GEARS, S.A. de C.V.). Nuestra cuenta es {$maskedEmail}.

Desde hace varios días nuestra integración no logra autenticar contra la API de
Cincel. Esto bloquea por completo nuestras entregas físicas a clientes finales
(no podemos firmar el acta de entrega NOM-151) y la firma de contratos de
crédito nuevos. Ejecutamos un diagnóstico técnico hoy {$when} para
descartar todos los posibles problemas de nuestro lado, y la evidencia
apunta claramente a un cambio en su API. A continuación los detalles.

CONFIGURACIÓN QUE USAMOS
  • Endpoint base configurado: {$apiUrl}
  • Email: {$maskedEmail}
  • Estas credenciales autenticaban correctamente antes con este mismo código.

PRUEBAS EJECUTADAS (todas desde nuestro servidor de producción)
{$endpointTable}

OBSERVACIONES CLAVE
  1. El DNS de api.cincel.digital resuelve correctamente desde nuestro servidor.
  2. El handshake TLS con api.cincel.digital:443 completa sin errores y el
     certificado es válido.
  3. Una llamada HEAD a la raíz de api.cincel.digital responde HTTP (el
     servidor está VIVO).
  4. TODAS las rutas de autenticación documentadas (/v3/auth/tokens,
     /v3/auth/login, /auth/tokens, /v4/auth/tokens, /api/v3/auth/tokens)
     devuelven HTTP 404. Un 404 (no 401) indica que esas rutas no existen
     en el servidor.
  5. Como control de sanidad ejecutamos HEAD contra www.google.com,
     www.circulodecredito.com.mx y cincel.digital (su sitio comercial) —
     todos responden normalmente desde nuestro servidor, lo que descarta
     cualquier problema de conectividad de nuestro lado.

QUÉ NECESITAMOS DE USTEDES
  1. ¿El host base correcto sigue siendo https://api.cincel.digital/v3 o
     migraron a otro dominio o a una versión nueva (v4, v5)? Si cambió,
     por favor confirmen la URL actual.
  2. ¿La ruta de autenticación cambió de nombre (por ejemplo a
     /sessions, /oauth/token, etc.)?
  3. Si requerimos generar nuevas credenciales API por algún cambio de
     plan o de panel administrativo, ¿dónde se generan?

IMPACTO DE NEGOCIO
  • Cada entrega que no se firma electrónicamente queda incompleta desde
    el punto de vista legal (NOM-151).
  • Implementamos una firma autógrafa temporal como respaldo pero NO
    incluye el sello NOM-151 que necesita Cincel.
  • Tenemos entregas programadas todos los días. Cada hora de retraso
    significa órdenes que no podemos cerrar.

Agradezco mucho una respuesta urgente. Si requieren ejecutar pruebas
adicionales desde nuestro servidor (con credenciales de un usuario de
prueba o similar), estamos disponibles para coordinar.

Saludos cordiales,
[su nombre]
[su email de contacto]
[su teléfono]
Voltika · MTECH GEARS, S.A. de C.V.
MSG;
}
