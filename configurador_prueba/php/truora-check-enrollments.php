<?php
/**
 * Voltika — Truora enrollment / product activation diagnostic.
 *
 * Calls several known Truora API endpoints to determine which products
 * the account is enrolled in. Specifically checks whether Face
 * Validation / Liveness is active so the operator can decide whether
 * to (a) wait for activation, or (b) request it from Truora support.
 *
 * Method: probe the API with token-creation requests for each grant
 * type. Responses tell us what's enrolled:
 *   - 2xx              → enrolled, product available
 *   - 400 "enrollment not found" → product NOT enrolled
 *   - 401/403          → API key permissions issue
 *
 * Auth: ?token=voltika_diag_2026 (or admin session).
 */
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_name('VOLTIKA_ADMIN');
    @session_start();
}
$expectedToken = getenv('VOLTIKA_DIAG_TOKEN') ?: (defined('VOLTIKA_DIAG_TOKEN') ? VOLTIKA_DIAG_TOKEN : 'voltika_diag_2026');
$adminOk  = !empty($_SESSION['admin_user_id']);
$tokenOk  = isset($_GET['token']) && hash_equals($expectedToken, $_GET['token']);
if (!$adminOk && !$tokenOk) {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><body style="font-family:system-ui;padding:30px;">';
    echo '<h2>Truora enrollments diag — acceso protegido</h2>';
    echo '<p>Use: <code>?token=' . htmlspecialchars($expectedToken) . '</code></p>';
    echo '<p><a href="?token=' . urlencode($expectedToken) . '">▶ Abrir</a></p>';
    exit;
}

if (!defined('TRUORA_API_KEY') || !TRUORA_API_KEY) {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><body style="font-family:system-ui;padding:30px;color:#991b1b;">';
    echo '<h2>Falta TRUORA_API_KEY en config</h2>';
    echo '<p>No se puede consultar la API sin la API key.</p>';
    exit;
}

if (!defined('TRUORA_ACCOUNT_API_URL'))  define('TRUORA_ACCOUNT_API_URL',  'https://api.account.truora.com');
if (!defined('TRUORA_IDENTITY_API_URL')) define('TRUORA_IDENTITY_API_URL', 'https://api.identity.truora.com');

// Probe 1 — try to create a token for the digital-identity flow (the
// one we actually use). Response code + body tells us if it works.
function probeDigitalIdentity(string $flowId): array {
    $body = http_build_query([
        'key_type'     => 'web',
        'grant'        => 'digital-identity',
        'flow_id'      => $flowId,
        'country'      => 'MX',
        'account_id'   => 'voltika_probe_' . bin2hex(random_bytes(3)),
        'redirect_url' => 'https://voltika.mx/configurador_prueba/php/truora-redirect.php',
    ]);
    return curlPost(TRUORA_ACCOUNT_API_URL . '/v1/api-keys', $body);
}

// Probe 2 — list customer info (some Truora plans expose this)
function probeAccountInfo(): array {
    return curlGet(TRUORA_ACCOUNT_API_URL . '/v1/customer');
}

// Probe 3 — list products / enrollments (if endpoint exists)
function probeProducts(): array {
    return curlGet(TRUORA_ACCOUNT_API_URL . '/v1/products');
}

function curlPost(string $url, string $body): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPHEADER     => [
            'Truora-API-Key: ' . TRUORA_API_KEY,
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['url' => $url, 'http_code' => $code, 'body' => (string)$resp, 'curl_err' => $err];
}
function curlGet(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPHEADER     => [
            'Truora-API-Key: ' . TRUORA_API_KEY,
            'Accept: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['url' => $url, 'http_code' => $code, 'body' => (string)$resp, 'curl_err' => $err];
}

$flowId = defined('TRUORA_FLOW_ID') ? TRUORA_FLOW_ID : '';
$probe1 = $flowId ? probeDigitalIdentity($flowId) : ['url' => '(no flow_id)', 'http_code' => 0, 'body' => 'TRUORA_FLOW_ID is empty'];
$probe2 = probeAccountInfo();
$probe3 = probeProducts();

// Heuristic for face-validation enrollment status from probe 1 result.
$faceStatus = 'unknown';
$faceDetail = '';
$body1 = $probe1['body'] ?? '';
if ($probe1['http_code'] >= 200 && $probe1['http_code'] < 300) {
    // The token endpoint accepts our flow → if our flow has Face match,
    // that means Face is enrolled. We can't know what the flow contains
    // from API alone, but we know the call succeeds.
    $faceStatus = 'unclear';
    $faceDetail = 'El flujo actual genera token correctamente. Si el flujo contiene un bloque de Face match y la prueba sigue funcionando → Face Validation está habilitada. Si el flujo NO tiene Face → este probe no concluye.';
} elseif ($probe1['http_code'] === 400 && stripos($body1, 'enrollment') !== false) {
    $faceStatus = 'NO';
    $faceDetail = '🔴 enrollment not found → la cuenta NO está enrolada en uno de los productos del flujo (Face Validation o similar). Hay que pedírselo a Truora support o activarlo en el dashboard.';
} elseif ($probe1['http_code'] === 401 || $probe1['http_code'] === 403) {
    $faceStatus = 'AUTH-FAIL';
    $faceDetail = '🔑 La API key no tiene permiso o expiró. Revisa TRUORA_API_KEY en config.';
} else {
    $faceDetail = 'HTTP ' . $probe1['http_code'] . ' — respuesta inesperada. Revisa el cuerpo más abajo.';
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="es"><head>
<meta charset="utf-8">
<title>Voltika · Truora Enrollments</title>
<style>
body{font-family:system-ui,-apple-system,sans-serif;background:#f0f4f8;color:#0c2340;padding:24px;max-width:1100px;margin:0 auto;line-height:1.55;}
h1{font-size:22px;margin:0 0 6px;}
h2{font-size:13px;color:#64748b;margin:0 0 22px;text-transform:uppercase;letter-spacing:.5px;}
.card{background:#fff;padding:16px 18px;border-radius:10px;border:1px solid #e2e8f0;margin-bottom:14px;}
.banner{padding:14px 16px;border-radius:10px;font-weight:600;font-size:14px;margin-bottom:14px;}
.banner.ok{background:#dcfce7;color:#14532d;border:1px solid #22c55e;}
.banner.no{background:#fee2e2;color:#991b1b;border:1px solid #ef4444;}
.banner.warn{background:#fef3c7;color:#78350f;border:1px solid #f59e0b;}
table{width:100%;border-collapse:collapse;font-size:12.5px;font-family:ui-monospace,Menlo,monospace;margin-top:8px;}
th{background:#f1f5f9;text-align:left;padding:6px 10px;font-size:11px;}
td{padding:6px 10px;border-top:1px solid #f1f5f9;vertical-align:top;word-break:break-all;}
code{background:#1e293b;color:#e2e8f0;padding:2px 7px;border-radius:4px;font-family:ui-monospace,Menlo,monospace;font-size:11.5px;}
</style></head><body>

<h1>🔬 Truora · Enrollment Status</h1>
<h2>¿Está habilitada Face Validation en esta cuenta?</h2>

<?php if ($faceStatus === 'NO'): ?>
  <div class="banner no">❌ <strong>Face Validation NO está habilitada</strong> — pídelo a Truora support.</div>
<?php elseif ($faceStatus === 'AUTH-FAIL'): ?>
  <div class="banner warn">🔑 <strong>API key inválida o sin permisos</strong>.</div>
<?php elseif ($faceStatus === 'unclear'): ?>
  <div class="banner warn">⚠ <strong>Resultado inconcluso.</strong> Si el flujo actual incluye Face match y este probe pasa → Face SÍ está habilitada. Si no → necesitas hacer un probe específico (ver abajo).</div>
<?php else: ?>
  <div class="banner warn">⚠ Resultado desconocido. Revisa los detalles abajo.</div>
<?php endif; ?>

<div class="card">
  <strong>Probe 1 — POST /v1/api-keys (flow actual)</strong>
  <table>
    <tr><th>flow_id</th><td><code><?= htmlspecialchars($flowId ?: '(vacío)') ?></code></td></tr>
    <tr><th>HTTP code</th><td><code><?= htmlspecialchars((string)$probe1['http_code']) ?></code></td></tr>
    <tr><th>Body (300 char)</th><td><code><?= htmlspecialchars(substr($probe1['body'], 0, 300)) ?></code></td></tr>
    <tr><th>curl_err</th><td><code><?= htmlspecialchars($probe1['curl_err'] ?? '') ?></code></td></tr>
    <tr><th>Diagnóstico</th><td><?= htmlspecialchars($faceDetail) ?></td></tr>
  </table>
</div>

<div class="card">
  <strong>Probe 2 — GET /v1/customer (info de la cuenta)</strong>
  <table>
    <tr><th>HTTP code</th><td><code><?= htmlspecialchars((string)$probe2['http_code']) ?></code></td></tr>
    <tr><th>Body (500 char)</th><td><code><?= htmlspecialchars(substr($probe2['body'], 0, 500)) ?></code></td></tr>
  </table>
  <div style="font-size:12.5px;color:#475569;margin-top:8px;">
    Si responde 200 con la lista de productos / planes → ahí verás si Face Validation aparece.
    Si 404, el endpoint no existe en este plan; usa Probe 1 para inferir.
  </div>
</div>

<div class="card">
  <strong>Probe 3 — GET /v1/products (productos disponibles)</strong>
  <table>
    <tr><th>HTTP code</th><td><code><?= htmlspecialchars((string)$probe3['http_code']) ?></code></td></tr>
    <tr><th>Body (500 char)</th><td><code><?= htmlspecialchars(substr($probe3['body'], 0, 500)) ?></code></td></tr>
  </table>
</div>

<div class="card" style="background:#eff6ff;border-color:#3b82f6;">
<strong style="color:#1e40af;">Cómo interpretar:</strong>
<ul style="font-size:13px;line-height:1.7;margin:8px 0;padding-left:20px;">
<li><b>Probe 1 = 400 + "enrollment not found"</b> → Face Validation (u otro producto del flujo) NO está enrolada. Pídelo a soporte de Truora.</li>
<li><b>Probe 1 = 200</b> + flujo contiene Face match → Face Validation SÍ está enrolada y funciona.</li>
<li><b>Probe 1 = 200</b> + flujo NO contiene Face match → no podemos saber por este probe; agrega Face match al flujo y vuelve a correr.</li>
<li><b>Probe 2 / 3 = 200</b> con JSON → leer la lista de productos en el body para confirmación directa.</li>
<li><b>Probe 2 / 3 = 404</b> → endpoint no soportado en este plan; usa Probe 1 como autoridad.</li>
</ul>
</div>

</body></html>
