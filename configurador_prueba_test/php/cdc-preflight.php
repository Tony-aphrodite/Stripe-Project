<?php
/**
 * CDC Preflight v2 — comprehensive diagnostic that tests:
 *   1. SecurityTest endpoint (verifies our signing works without product)
 *   2. /v2/rccficoscore (the product we need)
 *   3. /v2/ficoscore + a few alt endpoints
 *   4. Basic Auth variant vs custom header variant
 *
 * Result lets us see WHICH endpoint CDC's Apigee accepts our request at,
 * and distinguishes "signing broken" from "product not subscribed".
 *
 * Access: ?key=voltika_cdc_2026
 */

$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika_cdc_2026') { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/config.php';
session_start();
@set_time_limit(300);

header('Content-Type: text/html; charset=UTF-8');
echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>CDC Preflight v2</title>';
echo '<style>body{font-family:Arial,sans-serif;max-width:1100px;margin:20px auto;padding:0 20px;color:#333}';
echo 'pre{background:#1a1a1a;color:#0f0;padding:10px;border-radius:6px;overflow-x:auto;font-size:11px;white-space:pre-wrap;word-break:break-all;max-height:240px;overflow-y:auto}';
echo '.ok{color:#10b981;font-weight:700}.err{color:#C62828;font-weight:700}.warn{color:#d97706;font-weight:700}';
echo '.step{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin:10px 0}';
echo '.step.pass{border-color:#10b981;background:#ecfdf5}.step.fail{border-color:#C62828;background:#fef2f2}';
echo 'table{border-collapse:collapse;width:100%;margin:10px 0}td,th{border:1px solid #ddd;padding:6px 10px;font-size:13px;text-align:left;vertical-align:top}</style></head><body>';
echo '<h1>🔐 CDC Preflight v2 — multi-endpoint diagnostic</h1>';

// ── Load keys from DB ───────────────────────────────────────────────────────
$pdo = getDB();
$row = $pdo->query("SELECT id, private_key, certificate, fingerprint, freg FROM cdc_certificates WHERE active=1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo '<div class="step fail">❌ No hay certificado activo en DB. Corre <a href="generar-certificado-cdc.php?key=voltika_cdc_cert_2026&regen=1">generar-certificado</a> primero.</div>';
    echo '</body></html>'; exit;
}
echo '<div class="step pass">✅ Cert #' . $row['id'] . ' — fingerprint <code>' . htmlspecialchars(substr($row['fingerprint'],0,32)) . '…</code> creado ' . $row['freg'] . '</div>';

$keyPem  = $row['private_key'];
$certPem = $row['certificate'];
$priv = openssl_pkey_get_private($keyPem);
if (!$priv) { echo '<div class="step fail">❌ Key no parsea</div></body></html>'; exit; }

// ── Helper: signed request ──────────────────────────────────────────────────
function call(string $label, string $url, string $body, string $encoding, array $authMode, $priv, string $certPem, string $keyPem, array $extraHeaders = []): array {
    $sig = '';
    openssl_sign($body, $sig, $priv, OPENSSL_ALGO_SHA256);
    $sigEnc = $encoding === 'hex' ? bin2hex($sig) : base64_encode($sig);
    $headers = ['Content-Type: application/json', 'Accept: application/json', 'x-api-key: ' . CDC_API_KEY, 'x-signature: ' . $sigEnc];
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($authMode['type'] === 'headers') {
        if (CDC_USER) $headers[] = 'username: ' . CDC_USER;
        if (CDC_PASS) $headers[] = 'password: ' . CDC_PASS;
    } else {
        $opts[CURLOPT_USERPWD] = CDC_USER . ':' . CDC_PASS;
        $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
    }
    // mTLS
    $tmpC = tempnam(sys_get_temp_dir(), 'c'); $tmpK = tempnam(sys_get_temp_dir(), 'k');
    file_put_contents($tmpC, $certPem); file_put_contents($tmpK, $keyPem);
    if (!empty($authMode['mtls'])) {
        $opts[CURLOPT_SSLCERT] = $tmpC;
        $opts[CURLOPT_SSLKEY]  = $tmpK;
    }
    $headers = array_merge($headers, $extraHeaders);
    $opts[CURLOPT_HTTPHEADER] = $headers;
    $ch = curl_init();
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);
    @unlink($tmpC); @unlink($tmpK);
    return [
        'label' => $label,
        'url' => $url,
        'http' => $code,
        'curl_err' => $err,
        'resp_headers' => substr((string)$resp, 0, $hdrSize),
        'resp_body' => substr((string)$resp, $hdrSize),
        'sig_enc' => $encoding,
        'auth' => $authMode,
    ];
}

function render($r) {
    $pass = $r['http'] >= 200 && $r['http'] < 500 && $r['http'] != 401 && $r['http'] != 403;
    $klass = $pass ? 'pass' : 'fail';
    $icon = $pass ? '✅' : '❌';
    $bodyExcerpt = trim($r['resp_body']) === '' ? '(vacío)' : substr($r['resp_body'], 0, 1500);
    echo '<div class="step ' . $klass . '"><div><strong>' . $icon . ' ' . htmlspecialchars($r['label']) . '</strong> → HTTP <code>' . $r['http'] . '</code></div>';
    echo '<div style="font-size:11px;color:#666">URL: ' . htmlspecialchars($r['url']) . ' · enc=' . $r['sig_enc'] . ' · auth=' . $r['auth']['type'] . ' · mtls=' . (empty($r['auth']['mtls']) ? 'no' : 'yes') . '</div>';
    if ($r['curl_err']) echo '<div class="err">curl: ' . htmlspecialchars($r['curl_err']) . '</div>';
    echo '<pre>' . htmlspecialchars($bodyExcerpt) . '</pre></div>';
}

// ── Test 1: SecurityTest — verifies our signing independent of product ──────
echo '<h2>Test 1: SecurityTest (verifica que nuestra firma funciona)</h2>';
$secBody = json_encode(['Peticion' => 'Esto es un mensaje de prueba']);
// SecurityTest is documented with base64 signature of the "Peticion" string
$stSig = '';
openssl_sign('Esto es un mensaje de prueba', $stSig, $priv, OPENSSL_ALGO_SHA256);
$ch = curl_init('https://services.circulodecredito.com.mx/v1/securitytest');
$tmpC = tempnam(sys_get_temp_dir(), 'c'); $tmpK = tempnam(sys_get_temp_dir(), 'k');
file_put_contents($tmpC, $certPem); file_put_contents($tmpK, $keyPem);
curl_setopt_array($ch, [
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $secBody,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json','Accept: application/json','x-api-key: '.CDC_API_KEY,'x-signature: '.base64_encode($stSig)],
    CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20,
    CURLOPT_SSLCERT => $tmpC, CURLOPT_SSLKEY => $tmpK,
]);
$stResp = curl_exec($ch);
$stCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$stHdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$stErr  = curl_error($ch);
curl_close($ch);
@unlink($tmpC); @unlink($tmpK);
$stBody = substr((string)$stResp, $stHdrSize);
render(['label' => '/v1/securitytest (base64 sig, mTLS)', 'url' => 'https://services.circulodecredito.com.mx/v1/securitytest', 'http' => $stCode, 'curl_err' => $stErr, 'resp_headers' => '', 'resp_body' => $stBody, 'sig_enc' => 'base64', 'auth' => ['type'=>'headers','mtls'=>true]]);

if ($stCode >= 200 && $stCode < 300) {
    echo '<div class="step pass">🎉 <strong>Nuestra firma es válida.</strong> El problema con rccficoscore es producto/permiso, NO firma.</div>';
} elseif ($stCode == 401 || $stCode == 403) {
    echo '<div class="step fail">❌ SecurityTest rechaza la firma — el certificado en CDC portal NO coincide con el que generamos. Re-subir el cert.</div>';
}

// ── Test 2: /v2/rccficoscore — variants ─────────────────────────────────────
echo '<h2>Test 2: /v2/rccficoscore (el producto real)</h2>';
$body = json_encode([
    'primerNombre' => 'JUAN', 'apellidoPaterno' => 'PEREZ', 'apellidoMaterno' => 'LOPEZ',
    'fechaNacimiento' => '1985-03-15', 'RFC' => 'PELJ850315AAA', 'nacionalidad' => 'MX',
    'domicilio' => ['direccion'=>'AV REFORMA 100','coloniaPoblacion'=>'JUAREZ','delegacionMunicipio'=>'CUAUHTEMOC','ciudad'=>'CIUDAD DE MEXICO','estado'=>'CDMX','CP'=>'03100'],
]);
$url = 'https://services.circulodecredito.com.mx/v2/rccficoscore';
render(call('hex+headers+mTLS',   $url, $body, 'hex',    ['type'=>'headers','mtls'=>true],  $priv, $certPem, $keyPem));
render(call('hex+headers (no mTLS)', $url, $body, 'hex',    ['type'=>'headers','mtls'=>false], $priv, $certPem, $keyPem));
render(call('base64+headers+mTLS', $url, $body, 'base64', ['type'=>'headers','mtls'=>true],  $priv, $certPem, $keyPem));
render(call('hex+basic+mTLS',      $url, $body, 'hex',    ['type'=>'basic','mtls'=>true],    $priv, $certPem, $keyPem));

// ── Test 3: alt endpoints ───────────────────────────────────────────────────
echo '<h2>Test 3: endpoints alternos</h2>';
foreach ([
    'https://services.circulodecredito.com.mx/v2/ficoscore',
    'https://services.circulodecredito.com.mx/v1/rcc',
    'https://services.circulodecredito.com.mx/v2/reportedecreditoconsolidado',
    'https://services.circulodecredito.com.mx/v1/ficoscore',
] as $alt) {
    render(call($alt, $alt, $body, 'hex', ['type'=>'headers','mtls'=>true], $priv, $certPem, $keyPem));
}

// ── Last rows of cdc_query_log (real customer calls) ───────────────────────
echo '<h2>Log de consultas reales (últimas 5)</h2>';
try {
    $rows = $pdo->query("SELECT endpoint, http_code, has_sig, LEFT(response,400) AS resp, freg FROM cdc_query_log ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        echo '<table><tr><th>Fecha</th><th>Endpoint</th><th>HTTP</th><th>Sig</th><th>Respuesta</th></tr>';
        foreach ($rows as $r) {
            echo '<tr><td>'.$r['freg'].'</td><td style="font-size:11px">'.htmlspecialchars($r['endpoint']).'</td><td>'.$r['http_code'].'</td><td>'.($r['has_sig']?'✅':'❌').'</td><td style="font-size:11px">'.htmlspecialchars($r['resp']).'</td></tr>';
        }
        echo '</table>';
    } else {
        echo '<div class="step">Sin consultas registradas todavía.</div>';
    }
} catch (Throwable $e) {
    echo '<div class="step">Tabla cdc_query_log aún no existe.</div>';
}

echo '</body></html>';
