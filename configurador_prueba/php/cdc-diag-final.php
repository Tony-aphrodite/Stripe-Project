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

// CORRECT body shape per /v2/ficoscore swagger: folio (not folioOtorgante)
// at top level + persona object.
$body = [
    'folio' => CDC_FOLIO,
    'persona' => [
        'primerNombre'    => 'JUAN',
        'apellidoPaterno' => 'PEREZ',
        'apellidoMaterno' => 'LOPEZ',
        'fechaNacimiento' => '1980-01-01',
        'RFC'             => 'PELJ800101AAA',
        'CURP'            => 'PELJ800101HDFXXX00',
        'nacionalidad'    => 'MX',
        'domicilio' => [
            'direccion'           => 'AVENIDA REFORMA 100',
            'coloniaPoblacion'    => 'CENTRO',
            'delegacionMunicipio' => 'CUAUHTEMOC',
            'ciudad'              => 'CIUDAD DE MEXICO',
            'estado'              => 'DIF',
            'CP'                  => '06000',
        ],
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

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://services.circulodecredito.com.mx/v2/ficoscore',
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
    'endpoint' => 'https://services.circulodecredito.com.mx/v2/ficoscore',
    'body_shape' => 'folio + persona (top-level)',
    'http_code' => $code,
    'response' => $parsed ?: substr((string)$resp, 0, 500),
    'verdict' => $verdict,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
