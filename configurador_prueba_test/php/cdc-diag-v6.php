<?php
/**
 * CDC diagnostic v6 — CORRECT endpoint from the official swagger file:
 *   https://services.circulodecredito.com.mx/v1/rcficoscore
 *
 * Plus all other corrections gathered so far:
 *   - SHA256 + bin2hex encoding
 *   - username/password as custom headers (NOT Basic Auth)
 *   - x-api-key header
 *   - Valid RFC in body (required by swagger)
 *
 *   https://voltika.mx/configurador_prueba/php/cdc-diag-v6.php?key=voltika_cdc_2026
 */

$expected = 'voltika_cdc_2026';
if (($_GET['key'] ?? '') !== $expected) {
    http_response_code(403); exit("Forbidden. Add ?key=$expected\n");
}

header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/config.php';

// Override base URL with the correct production endpoint discovered from
// the official CDC swagger file.
define('CDC_V6_URL', 'https://services.circulodecredito.com.mx/v1/rcficoscore');

if (!defined('CDC_USER')) define('CDC_USER', getenv('CDC_USER') ?: '');
if (!defined('CDC_PASS')) define('CDC_PASS', getenv('CDC_PASS') ?: '');

$keyPem = $_SESSION['cdc_key_pem'] ?? null;
if (!$keyPem) {
    $keyFile = __DIR__ . '/certs/cdc_private.key';
    if (file_exists($keyFile)) $keyPem = @file_get_contents($keyFile);
}
if (!$keyPem) { echo json_encode(['error' => 'Private key not found']); exit; }
$priv = openssl_pkey_get_private($keyPem);
if (!$priv) { echo json_encode(['error' => 'Bad key format']); exit; }

// Body matching swagger's PersonaPeticion schema (all required fields filled).
$body = [
    'primerNombre'    => 'JUAN',
    'apellidoPaterno' => 'PEREZ',
    'apellidoMaterno' => 'LOPEZ',
    'fechaNacimiento' => '1980-01-01',
    'RFC'             => 'PELJ800101AAA',
    'nacionalidad'    => 'MX',
    'domicilio'       => [
        'direccion'           => 'AVENIDA REFORMA 100',
        'coloniaPoblacion'    => 'CENTRO',
        'delegacionMunicipio' => 'CUAUHTEMOC',
        'ciudad'              => 'CIUDAD DE MEXICO',
        'estado'              => 'DIF',
        'CP'                  => '06000',
    ],
];

function callCdc(string $url, string $jsonBody, array $extraHeaders, $priv): array {
    $sig = '';
    openssl_sign($jsonBody, $sig, $priv, OPENSSL_ALGO_SHA256);
    $sigHex = bin2hex($sig);

    $headers = array_merge([
        'Content-Type: application/json',
        'Accept: application/json',
        'x-api-key: ' . CDC_API_KEY,
        'username: '  . CDC_USER,
        'password: '  . CDC_PASS,
        'x-signature: ' . $sigHex,
        'User-Agent: FicoscoreV2-Codegen/1.0.0/php',
    ], $extraHeaders);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonBody,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [
        'url'       => $url,
        'http_code' => $code,
        'curl_err'  => $err ?: null,
        'body'      => json_decode($resp, true) ?: substr((string)$resp, 0, 500),
    ];
}

$jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE);

// Primary endpoint — the one the swagger officially documents
$attempts = [
    'A_v1_rcficoscore'   => callCdc('https://services.circulodecredito.com.mx/v1/rcficoscore', $jsonBody, [], $priv),
    // Also retry the previously-used URL with the corrected auth just in case
    'B_v2_rcc_ficoscore' => callCdc('https://services.circulodecredito.com.mx/v2/rcc/ficoscore', $jsonBody, [], $priv),
    // Variants worth sanity-checking
    'C_v2_rcficoscore'   => callCdc('https://services.circulodecredito.com.mx/v2/rcficoscore', $jsonBody, [], $priv),
    'D_ficoscore'        => callCdc('https://services.circulodecredito.com.mx/v1/ficoscore', $jsonBody, [], $priv),
];

$winner = null;
foreach ($attempts as $label => $a) {
    if (in_array($a['http_code'], [200, 201, 400, 404], true)) {
        $winner = ['label' => $label, 'url' => $a['url'], 'code' => $a['http_code']];
        break;
    }
}

echo json_encode([
    'config' => [
        'CDC_USER' => CDC_USER ? substr(CDC_USER, 0, 4) . '***' . substr(CDC_USER, -3) : '',
        'auth_mode'=> 'custom headers (username/password) + x-api-key + x-signature hex',
    ],
    'attempts' => $attempts,
    'winner'   => $winner,
    'verdict'  => $winner
        ? '✅ ÉXITO en ' . $winner['label'] . ' con HTTP ' . $winner['code']
        : '❌ Ningún endpoint aceptó las credenciales.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
