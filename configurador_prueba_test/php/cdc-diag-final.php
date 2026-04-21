<?php
/**
 * CDC diagnostic FINAL — verify /v2/ficoscore with the correct body shape.
 *
 *   https://voltika.mx/configurador_prueba/php/cdc-diag-final.php?key=voltika_cdc_2026
 */

$expected = 'voltika_cdc_2026';
if (($_GET['key'] ?? '') !== $expected) { http_response_code(403); exit("Forbidden"); }

header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/config.php';

if (!defined('CDC_USER')) define('CDC_USER', getenv('CDC_USER') ?: '');
if (!defined('CDC_PASS')) define('CDC_PASS', getenv('CDC_PASS') ?: '');
if (!defined('CDC_FOLIO')) define('CDC_FOLIO', getenv('CDC_FOLIO') ?: '0000004694');

$keyPem = $_SESSION['cdc_key_pem'] ?? null;
if (!$keyPem) {
    $keyFile = __DIR__ . '/certs/cdc_private.key';
    if (file_exists($keyFile)) $keyPem = @file_get_contents($keyFile);
}
if (!$keyPem) { echo json_encode(['error'=>'Private key not found']); exit; }
$priv = openssl_pkey_get_private($keyPem);
if (!$priv) { echo json_encode(['error'=>'Bad key']); exit; }

// Schema of /v1/rccficoscore (Reporte de Crédito Consolidado + FICO Score V1):
//   - No "folio" wrapper, no "persona" wrapper — fields are at top level
//   - primerNombre (singular, NOT "nombres")
//   - rfc (lowercase, NOT "RFC")
//   - domicilio uses codigoPostal / municipio / colonia (not CP / delegacionMunicipio / coloniaPoblacion)
$body = [
    'primerNombre'    => 'JUAN',
    'apellidoPaterno' => 'PEREZ',
    'apellidoMaterno' => 'LOPEZ',
    'fechaNacimiento' => '1980-01-01',
    'rfc'             => 'PELJ800101AAA',
    'domicilio' => [
        'direccion'    => 'AVENIDA REFORMA 100',
        'colonia'      => 'CENTRO',
        'municipio'    => 'CUAUHTEMOC',
        'ciudad'       => 'CIUDAD DE MEXICO',
        'estado'       => 'CDMX',
        'codigoPostal' => '06000',
    ],
];
$jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE);

$sig = '';
openssl_sign($jsonBody, $sig, $priv, OPENSSL_ALGO_SHA256);
$sigHex = bin2hex($sig);

$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'x-api-key: ' . CDC_API_KEY,
    'username: '  . CDC_USER,
    'password: '  . CDC_PASS,
    'x-signature: ' . $sigHex,
];

// The endpoint that evidence shows our subscription actually maps to is
// /v1/consolidado/ficoscore — it returned 429 (rate-limit) which only
// happens after CDC's auth and subscription checks pass. Its sibling URLs
// /v1/rccficoscore and /v2/rcc/ficoscore return 401.2 (auth rejected) and
// /v1/rcficoscore returns "Invalid ApiKey" (Apigee-level rejection).
$target = $_GET['url'] ?? 'https://services.circulodecredito.com.mx/v1/consolidado/ficoscore';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $target,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $jsonBody,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 25,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

$parsed = json_decode($resp, true);

$verdict = '';
if ($code >= 200 && $code < 300) $verdict = '🎉🎉🎉 ÉXITO TOTAL — CDC Production funcional';
elseif ($code == 404) $verdict = '✅ Auth + body OK (persona ficticia no existe — normal)';
elseif ($code == 400) $verdict = '⚠️ Auth OK, body necesita ajuste';
elseif ($code == 429) $verdict = '⚠️ Rate limit — éxito anterior, esperando';
elseif ($code == 401) $verdict = '❌ Auth aún rechazado';
else $verdict = '❓ ' . $code;

echo json_encode([
    'endpoint' => $target,
    'body_shape' => 'folio + persona (top-level, nombres plural)',
    'http_code' => $code,
    'response' => $parsed ?: substr((string)$resp, 0, 500),
    'verdict' => $verdict,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
