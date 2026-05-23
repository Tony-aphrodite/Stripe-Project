<?php
/**
 * Voltika Admin — Diagnóstico Cincel Timestamp (Round 69, 2026-05-23).
 *
 * Verifica que podemos integrar el servicio de Estampas de Tiempo NOM-151
 * de Cincel. Customer brief (Óscar): "We only need the timestamp from
 * Cincel". Esto reduce drásticamente el alcance — no necesitamos el flujo
 * completo de firma, solo el sello legal NOM-151 sobre documentos que ya
 * firmamos localmente con autógrafa.
 *
 * Hallazgo clave de la documentación de Cincel (docs.cincel.digital/v3/timestamps):
 *   • GET  /v3/timestamps/{hash}  →  NO requiere credenciales, NO consume créditos.
 *     Devuelve el timestamp NOM-151 ya existente para ese hash (si lo hay), o 404.
 *   • POST /v3/timestamps         →  Requiere JWT, consume 1 crédito c.Doc.
 *     Crea un nuevo timestamp para el hash proporcionado.
 *
 * Por lo tanto podemos verificar el endpoint AHORA MISMO sin resolver el
 * bloqueo de auth — basta hacer un GET con un hash conocido y observar
 * la respuesta. Si Cincel responde HTTP (200 con timestamp existente,
 * o 404 sin timestamp), entonces:
 *   - El WAF ya no nos bloquea para este endpoint.
 *   - La conectividad funciona.
 *   - El endpoint existe.
 *   - El siguiente paso es hacer un POST para crear timestamps reales
 *     una vez que tengamos un JWT válido.
 *
 * Si Cincel no responde (503/timeout/WAF block), el problema persiste.
 *
 * URL: /admin/php/diagnostico-cincel-timestamp.php?key=voltika_diag_2026
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

@require_once __DIR__ . '/../../configurador/php/config.php';

function _cincelApiRoot(): string {
    $u = defined('CINCEL_API_URL') ? rtrim(CINCEL_API_URL, '/') : 'https://api.cincel.digital/v3';
    return preg_replace('#/v\d+$#', '', $u) ?: 'https://api.cincel.digital';
}

function _runHttp(string $method, string $url, array $headers = [], ?string $body = null): array {
    $start = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
        CURLOPT_USERAGENT      => 'Voltika-Diagnostic-Timestamp/1.0',
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw    = curl_exec($ch);
    $code   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $ip     = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
    $err    = curl_error($ch);
    $errno  = curl_errno($ch);
    curl_close($ch);

    $headersStr = is_string($raw) ? substr($raw, 0, $hsize) : '';
    $bodyStr    = is_string($raw) ? substr($raw, $hsize)    : '';
    return [
        'method'   => $method,
        'url'      => $url,
        'http'     => $code,
        'time_ms'  => (int)((microtime(true) - $start) * 1000),
        'ip'       => $ip,
        'headers'  => trim($headersStr),
        'body'     => $bodyStr,
        'body_decoded' => is_string($bodyStr) ? json_decode($bodyStr, true) : null,
        'curl_err' => $err ?: null,
        'errno'    => $errno,
    ];
}

// ── Find an existing signed contract on disk to use for the test ─────────
function _findSampleContract(): ?array {
    $candidates = [
        __DIR__ . '/../../configurador/php/contratos',
        __DIR__ . '/../../configurador/php/uploads/contratos',
        __DIR__ . '/../../configurador_prueba_test/php/contratos',
    ];
    foreach ($candidates as $dir) {
        if (!is_dir($dir)) continue;
        foreach (scandir($dir) ?: [] as $f) {
            if (!preg_match('/\.pdf$/i', $f)) continue;
            $path = $dir . '/' . $f;
            if (is_file($path) && filesize($path) > 1024) {
                return [
                    'path'   => $path,
                    'name'   => $f,
                    'size'   => filesize($path),
                    'sha256' => hash_file('sha256', $path),
                ];
            }
        }
    }
    return null;
}

// ── Probe 1: GET timestamp endpoint with a KNOWN hash (no auth needed) ──
// Per Cincel docs, this should return 200 (existing timestamp) or 404
// (no timestamp yet). The fact that we get a clean HTTP response proves
// the endpoint is reachable and the WAF isn't blocking us anymore.

$rootHost = _cincelApiRoot();
$probes   = [];

// Probe A — Cincel's own example hash from their docs (might or might not exist).
$exampleHash = '2c5d36be542f8f0e7345d77753a5d7ea61a443ba6a9a86bb060332ad56dba38e';
$probes['A'] = [
    'label' => 'GET con hash de ejemplo de los docs Cincel (sin auth)',
    'desc'  => 'Si Cincel responde HTTP (200, 404 o similar) entonces el endpoint funciona y el WAF no nos bloquea. Este hash puede o no tener un timestamp previo, no importa — lo que importa es la respuesta.',
    'result' => _runHttp('GET', $rootHost . '/v3/timestamps/' . $exampleHash),
];

// Probe B — Hash of an actual local signed contract.
$sample = _findSampleContract();
if ($sample) {
    $probes['B'] = [
        'label' => 'GET con hash de un contrato local firmado: ' . $sample['name'],
        'desc'  => 'Hash SHA-256 calculado sobre ' . number_format($sample['size']) . ' bytes. Esperamos 404 — no debería existir un timestamp porque nunca lo hemos creado para este archivo. Eso confirma que el endpoint funciona pero el documento no tiene sello aún.',
        'sample' => $sample,
        'result' => _runHttp('GET', $rootHost . '/v3/timestamps/' . $sample['sha256']),
    ];
} else {
    $probes['B'] = [
        'label' => 'GET con hash de contrato local',
        'desc'  => 'No se encontró ningún contrato PDF en /uploads/contratos. Salta este test.',
        'skipped' => true,
    ];
}

// Probe C — Hash of a known string (sha256 of "voltika-timestamp-test").
$testHash = hash('sha256', 'voltika-timestamp-test-' . date('Y-m-d'));
$probes['C'] = [
    'label' => 'GET con hash sintético generado en tiempo real',
    'desc'  => 'sha256("voltika-timestamp-test-' . date('Y-m-d') . '") — garantizamos que no existe timestamp previo. Esperamos 404 limpio.',
    'hash'  => $testHash,
    'result' => _runHttp('GET', $rootHost . '/v3/timestamps/' . $testHash),
];

// ── Derive verdict ───────────────────────────────────────────────────────
$any2xx = false;
$any404 = false;
$any5xx = false;
$anyConnFail = false;
$wafSignals = [];
foreach ($probes as $p) {
    if (!empty($p['skipped'])) continue;
    if (!isset($p['result'])) continue;
    $c = (int)$p['result']['http'];
    if ($c >= 200 && $c < 300) $any2xx = true;
    if ($c === 404)            $any404 = true;
    if ($c >= 500)             $any5xx = true;
    if ($c === 0 || $p['result']['errno'] > 0) $anyConnFail = true;
    if ($c === 403 || $c === 429) $wafSignals[] = $c;
    if (stripos($p['result']['headers'] ?? '', 'cf-ray') !== false ||
        stripos($p['result']['headers'] ?? '', 'cloudflare') !== false) {
        // Just a marker that the response went through Cloudflare — not necessarily WAF block.
    }
}

$verdict = [];
if ($anyConnFail) {
    $verdict[] = ['bad', 'No pudimos conectar al endpoint. Posible problema de red o WAF activo. Detalles abajo.'];
} elseif (!empty($wafSignals)) {
    $verdict[] = ['warn', 'Detectamos signos de WAF (HTTP ' . implode(',', $wafSignals) . '). Esperar a que expire el back-off.'];
} elseif ($any5xx) {
    $verdict[] = ['warn', 'El servidor de Cincel devolvió 5xx en al menos un probe. Reintentar más tarde.'];
} elseif ($any2xx || $any404) {
    $verdict[] = ['ok', '✅ El endpoint de timestamps RESPONDE correctamente. Podemos integrar el servicio NOM-151 ahora.'];
    if ($any404 && !$any2xx) {
        $verdict[] = ['info', '404 es la respuesta esperada para hashes sin timestamp previo. Esto confirma que el endpoint funciona — solo falta crear timestamps reales (requiere JWT vía POST).'];
    }
    if ($any2xx) {
        $verdict[] = ['info', 'Recibimos al menos un timestamp existente. Cincel ya tenía un sello NOM-151 para ese hash — podemos recuperarlo sin créditos.'];
    }
} else {
    $verdict[] = ['warn', 'Resultado mixto / no concluyente. Revisar respuestas detalladas abajo.'];
}

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Voltika — Diagnóstico Cincel Timestamp</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fb;color:#0c2340;padding:24px;max-width:1080px;margin:0 auto;line-height:1.55;}
h1{font-size:22px;margin:0 0 4px;}
h2{font-size:14px;color:#475569;margin:24px 0 8px;text-transform:uppercase;letter-spacing:.4px;}
.muted{color:#94a3b8;font-size:12px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:14px;}
.banner{padding:10px 12px;border-radius:8px;font-size:13px;margin-bottom:10px;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;font-weight:600;}
.banner-bad{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;font-weight:600;}
.banner-warn{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-weight:600;}
.banner-info{background:#dbeafe;border:1px solid #93c5fd;color:#1e40af;}
.probe{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:12px;}
.probe-label{font-weight:700;font-size:14px;color:#0c2340;margin-bottom:4px;}
.probe-desc{font-size:12.5px;color:#475569;margin-bottom:8px;line-height:1.5;}
.probe-row{display:flex;gap:14px;font-size:12px;color:#64748b;margin-bottom:6px;}
.http-200{color:#15803d;font-weight:700;}
.http-404{color:#d97706;font-weight:700;}
.http-403, .http-429{color:#7c2d12;font-weight:700;}
.http-5xx{color:#dc2626;font-weight:700;}
.http-0{color:#94a3b8;}
code{background:#1e293b;color:#e2e8f0;padding:2px 6px;border-radius:3px;font-size:11px;font-family:ui-monospace,monospace;word-break:break-all;}
pre{background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px;font-size:11px;white-space:pre-wrap;word-break:break-all;max-height:280px;overflow:auto;margin:6px 0;}
.k{color:#475569;font-weight:600;}
</style></head><body>

<h1>🕐 Diagnóstico Cincel Timestamp (NOM-151)</h1>
<div class="muted"><?= date('Y-m-d H:i:s') ?> · Servidor: <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '') ?> · Host Cincel: <code><?= htmlspecialchars($rootHost) ?></code></div>

<h2>Veredicto</h2>
<div class="card">
  <?php foreach ($verdict as $v): list($cls, $msg) = $v; ?>
    <div class="banner banner-<?= $cls ?>"><?= htmlspecialchars($msg) ?></div>
  <?php endforeach; ?>
</div>

<h2>Detalles de cada probe</h2>

<?php foreach ($probes as $key => $p): ?>
  <div class="probe">
    <div class="probe-label">Probe <?= htmlspecialchars($key) ?> — <?= htmlspecialchars($p['label']) ?></div>
    <div class="probe-desc"><?= htmlspecialchars($p['desc']) ?></div>

    <?php if (!empty($p['skipped'])): ?>
      <div class="banner banner-info" style="font-weight:500;">Probe omitido.</div>
    <?php else:
      $r = $p['result'];
      $http = (int)$r['http'];
      $cls = $http === 0 ? 'http-0'
           : ($http >= 200 && $http < 300 ? 'http-200'
           : ($http === 404 ? 'http-404'
           : (($http === 403 || $http === 429) ? 'http-403'
           : ($http >= 500 ? 'http-5xx' : 'http-0'))));
    ?>
      <div class="probe-row">
        <span><span class="k">HTTP:</span> <span class="<?= $cls ?>"><?= htmlspecialchars((string)$r['http']) ?></span></span>
        <span><span class="k">Método:</span> <?= htmlspecialchars($r['method']) ?></span>
        <span><span class="k">Tiempo:</span> <?= (int)$r['time_ms'] ?> ms</span>
        <span><span class="k">IP:</span> <?= htmlspecialchars($r['ip'] ?: '—') ?></span>
      </div>
      <div class="probe-row" style="font-family:ui-monospace,monospace;font-size:11.5px;color:#0c2340;">
        <?= htmlspecialchars($r['url']) ?>
      </div>
      <?php if (!empty($p['sample'])): ?>
        <div class="muted" style="font-size:11.5px;margin-top:4px;">
          Archivo: <code><?= htmlspecialchars($p['sample']['path']) ?></code><br>
          SHA-256: <code><?= htmlspecialchars($p['sample']['sha256']) ?></code>
        </div>
      <?php endif; ?>
      <?php if (!empty($p['hash'])): ?>
        <div class="muted" style="font-size:11.5px;margin-top:4px;">
          Hash: <code><?= htmlspecialchars($p['hash']) ?></code>
        </div>
      <?php endif; ?>
      <?php if (!empty($r['curl_err'])): ?>
        <div class="banner banner-bad" style="margin-top:8px;font-weight:500;">cURL error: <?= htmlspecialchars($r['curl_err']) ?></div>
      <?php endif; ?>
      <div style="margin-top:6px;">
        <div class="muted" style="font-size:11.5px;">Body:</div>
        <pre><?= htmlspecialchars($r['body'] ?: '(vacío)') ?></pre>
      </div>
      <details>
        <summary class="muted" style="font-size:11.5px;cursor:pointer;">Response headers</summary>
        <pre><?= htmlspecialchars($r['headers'] ?: '(sin headers)') ?></pre>
      </details>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<h2>¿Qué significan los resultados?</h2>
<div class="card" style="font-size:13px;">
  <ul style="margin:0;padding-left:18px;line-height:1.7;">
    <li><strong>HTTP 200</strong> en algún probe → existe un timestamp NOM-151 para ese hash. Respuesta incluye certificados en formatos pdf/xml/asn1.</li>
    <li><strong>HTTP 404</strong> en cualquier probe → el endpoint funciona, pero no hay timestamp para ese hash. Esto es ESPERADO en el caso de Probe B (nuestro contrato local) y Probe C (hash sintético del día). Confirma que la integración es viable: solo necesitamos hacer POST con un JWT para crear timestamps reales.</li>
    <li><strong>HTTP 403 / 429</strong> → WAF de Cincel sigue bloqueando. Esperar más horas/días.</li>
    <li><strong>HTTP 5xx / timeout</strong> → problema temporal en Cincel. Reintentar más tarde.</li>
  </ul>
</div>

<h2>Próximo paso lógico</h2>
<div class="card" style="font-size:13px;">
  <?php if ($any2xx || $any404): ?>
    <div class="banner banner-ok">
      ✅ El endpoint de timestamps NOM-151 está vivo y respondiendo desde nuestro servidor.
      Podemos proceder a:
    </div>
    <ol style="padding-left:18px;line-height:1.7;">
      <li>Obtener un JWT (vía OTP flow o vía contraseña una vez que Cincel confirme).</li>
      <li>Hacer <code>POST /v3/timestamps</code> con el JWT + body que incluya el hash SHA-256 del PDF.</li>
      <li>Recibir el certificado NOM-151 y guardarlo asociado al documento.</li>
      <li>Adjuntar el certificado al contrato/acta en el panel del cliente.</li>
    </ol>
    <p>Solo falta resolver la autenticación. Esto se puede plantear a Cincel via mensaje (sin reunión):</p>
    <pre style="white-space:pre-wrap;">Hola, queremos integrar solo el servicio de Estampas de Tiempo NOM-151.
Confirmamos que el endpoint GET /v3/timestamps/{hash} ya nos responde
correctamente desde nuestro servidor. Para hacer el POST /v3/timestamps
y crear timestamps nuevos necesitamos:

1. ¿La cuenta oscar@riactor.com (Plan Start) tiene créditos suficientes
   para crear timestamps NOM-151?
2. Si no tiene, ¿cómo y cuánto cuesta comprar paquete de timestamps?
3. ¿El POST acepta el JWT obtenido vía /v3/tokens/jwt o necesita otra
   credencial?</pre>
  <?php else: ?>
    <div class="banner banner-warn">
      El endpoint no respondió como se esperaba. Esperar al menos 24 horas más para que el WAF de Cincel expire su back-off, luego volver a correr este script.
    </div>
  <?php endif; ?>
</div>

</body></html>
