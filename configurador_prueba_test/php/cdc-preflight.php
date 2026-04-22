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

// ── Test 1: SecurityTest — try multiple hash algos + signing subjects ──────
echo '<h2>Test 1: SecurityTest — probando combinaciones (hash × contenido firmado × encoding)</h2>';

function secTest($label, $signSubject, $algo, $encoding, $priv, $certPem, $keyPem) {
    $sig = '';
    openssl_sign($signSubject, $sig, $priv, $algo);
    $sigEnc = $encoding === 'hex' ? bin2hex($sig) : base64_encode($sig);
    $body = json_encode(['Peticion' => 'Esto es un mensaje de prueba']);
    $tmpC = tempnam(sys_get_temp_dir(), 'c'); $tmpK = tempnam(sys_get_temp_dir(), 'k');
    file_put_contents($tmpC, $certPem); file_put_contents($tmpK, $keyPem);
    $ch = curl_init('https://services.circulodecredito.com.mx/v1/securitytest');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json','Accept: application/json','x-api-key: '.CDC_API_KEY,'x-signature: '.$sigEnc],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_SSLCERT => $tmpC, CURLOPT_SSLKEY => $tmpK,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);
    @unlink($tmpC); @unlink($tmpK);
    $respBody = substr((string)$resp, $hdrSize);
    return [
        'label' => $label, 'url' => 'https://services.circulodecredito.com.mx/v1/securitytest',
        'http' => $code, 'curl_err' => $err, 'resp_headers' => '', 'resp_body' => $respBody,
        'sig_enc' => $encoding, 'auth' => ['type'=>'headers','mtls'=>true],
    ];
}

// Detect key type so we know whether to test IEEE P1363 conversion
$keyDetails = openssl_pkey_get_details($priv);
$isECDSA = ($keyDetails['type'] === OPENSSL_KEYTYPE_EC);
$curveSize = 48; // P-384 → 48 bytes per coordinate

// Convert ECDSA DER signature to IEEE P1363 (raw r||s) format.
// Many APIs expect this form; OpenSSL always emits DER.
function derToP1363(string $der, int $curveSize): string {
    // DER: 0x30 <totalLen> 0x02 <rLen> <r> 0x02 <sLen> <s>
    if (strlen($der) < 8 || ord($der[0]) !== 0x30) return $der;
    $offset = 2; if (ord($der[1]) & 0x80) $offset = 2 + (ord($der[1]) & 0x7F);
    if (ord($der[$offset]) !== 0x02) return $der;
    $rLen = ord($der[$offset + 1]);
    $r = substr($der, $offset + 2, $rLen);
    $offset = $offset + 2 + $rLen;
    if (ord($der[$offset]) !== 0x02) return $der;
    $sLen = ord($der[$offset + 1]);
    $s = substr($der, $offset + 2, $sLen);
    // Strip leading 0x00 sign byte if present
    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");
    // Pad to curve size
    $r = str_pad($r, $curveSize, "\x00", STR_PAD_LEFT);
    $s = str_pad($s, $curveSize, "\x00", STR_PAD_LEFT);
    return $r . $s;
}

$subject = 'Esto es un mensaje de prueba';
$winnerLabel = null;

// Build variants — DER (default openssl output) + P1363 (raw r||s) for ECDSA
function secTestFmt($label, $signSubject, $algo, $format, $encoding, $priv, $certPem, $keyPem, $isECDSA, $curveSize) {
    $sig = '';
    openssl_sign($signSubject, $sig, $priv, $algo);
    if ($format === 'p1363' && $isECDSA) $sig = derToP1363($sig, $curveSize);
    $sigEnc = $encoding === 'hex' ? bin2hex($sig) : base64_encode($sig);
    $body = json_encode(['Peticion' => 'Esto es un mensaje de prueba']);
    $tmpC = tempnam(sys_get_temp_dir(), 'c'); $tmpK = tempnam(sys_get_temp_dir(), 'k');
    file_put_contents($tmpC, $certPem); file_put_contents($tmpK, $keyPem);
    $ch = curl_init('https://services.circulodecredito.com.mx/v1/securitytest');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json','Accept: application/json','x-api-key: '.CDC_API_KEY,'x-signature: '.$sigEnc],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_SSLCERT => $tmpC, CURLOPT_SSLKEY => $tmpK,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);
    @unlink($tmpC); @unlink($tmpK);
    return ['label' => $label, 'url' => 'https://services.circulodecredito.com.mx/v1/securitytest',
        'http' => $code, 'curl_err' => $err, 'resp_headers' => '', 'resp_body' => substr((string)$resp, $hdrSize),
        'sig_enc' => $encoding, 'auth' => ['type'=>'headers','mtls'=>true]];
}

$variants = [
    // DER format (OpenSSL default)
    ['DER SHA256+base64 sign=string', $subject, OPENSSL_ALGO_SHA256, 'der', 'base64'],
    ['DER SHA384+base64 sign=string', $subject, OPENSSL_ALGO_SHA384, 'der', 'base64'],
    ['DER SHA256+hex    sign=string', $subject, OPENSSL_ALGO_SHA256, 'der', 'hex'],
    ['DER SHA384+hex    sign=string', $subject, OPENSSL_ALGO_SHA384, 'der', 'hex'],
];
if ($isECDSA) {
    $variants = array_merge($variants, [
        // IEEE P1363 raw r||s — common ECDSA alternative
        ['P1363 SHA256+base64 sign=string', $subject, OPENSSL_ALGO_SHA256, 'p1363', 'base64'],
        ['P1363 SHA384+base64 sign=string', $subject, OPENSSL_ALGO_SHA384, 'p1363', 'base64'],
        ['P1363 SHA256+hex    sign=string', $subject, OPENSSL_ALGO_SHA256, 'p1363', 'hex'],
        ['P1363 SHA384+hex    sign=string', $subject, OPENSSL_ALGO_SHA384, 'p1363', 'hex'],
    ]);
}
foreach ($variants as $v) {
    $r = secTestFmt($v[0], $v[1], $v[2], $v[3], $v[4], $priv, $certPem, $keyPem, $isECDSA, $curveSize);
    render($r);
    $isPass = ($r['http'] >= 200 && $r['http'] < 300) || $r['http'] == 429;
    if ($isPass && !$winnerLabel) $winnerLabel = $v[0];
    usleep(350000);
}

if ($winnerLabel) {
    echo '<div class="step pass">🎉 <strong>Combos con firma válida encontrados — incluyendo: ' . htmlspecialchars($winnerLabel) . '</strong>. El 429 significa rate limit, NO firma mal. Nuestra firma ya es aceptada por CDC.</div>';
} else {
    echo '<div class="step fail">❌ Ningún combo pasa. Cert en CDC portal no coincide con DB o aún no activo.</div>';
}

// ── Test 2: /v2/rccficoscore — variants ─────────────────────────────────────
echo '<h2>Test 2: /v2/rccficoscore (el producto real)</h2>';
$body = json_encode([
    'primerNombre' => 'JUAN', 'apellidoPaterno' => 'PEREZ', 'apellidoMaterno' => 'LOPEZ',
    'fechaNacimiento' => '1985-03-15', 'RFC' => 'PELJ850315AAA', 'nacionalidad' => 'MX',
    'domicilio' => ['direccion'=>'AV REFORMA 100','coloniaPoblacion'=>'JUAREZ','delegacionMunicipio'=>'CUAUHTEMOC','ciudad'=>'CIUDAD DE MEXICO','estado'=>'CDMX','CP'=>'03100'],
]);
$url = 'https://services.circulodecredito.com.mx/v2/rccficoscore';
render(call('hex+headers+mTLS',   $url, $body, 'hex',    ['type'=>'headers','mtls'=>true],  $priv, $certPem, $keyPem)); usleep(400000);
render(call('hex+headers (no mTLS)', $url, $body, 'hex',    ['type'=>'headers','mtls'=>false], $priv, $certPem, $keyPem)); usleep(400000);
render(call('base64+headers+mTLS', $url, $body, 'base64', ['type'=>'headers','mtls'=>true],  $priv, $certPem, $keyPem)); usleep(400000);
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
    usleep(400000);
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
