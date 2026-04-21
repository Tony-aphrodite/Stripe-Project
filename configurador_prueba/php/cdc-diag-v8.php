<?php
/**
 * CDC diagnostic v8 — exhaustive trace with verbose curl output so we
 * can pinpoint WHY the response is 503 with empty body (normally CDC
 * returns structured JSON even on errors).
 *
 * Tests multiple signature algorithms, scopes, and body encodings to
 * identify which combination CDC actually accepts.
 *
 *   https://voltika.mx/configurador_prueba/php/cdc-diag-v8.php?key=voltika_cdc_2026
 */

$expected = 'voltika_cdc_2026';
if (($_GET['key'] ?? '') !== $expected) { http_response_code(403); exit("Forbidden"); }

header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/config.php';

if (!defined('CDC_USER')) define('CDC_USER', getenv('CDC_USER') ?: '');
if (!defined('CDC_PASS')) define('CDC_PASS', getenv('CDC_PASS') ?: '');

$keyPem = $_SESSION['cdc_key_pem'] ?? null;
if (!$keyPem) {
    $keyFile = __DIR__ . '/certs/cdc_private.key';
    if (file_exists($keyFile)) $keyPem = @file_get_contents($keyFile);
}
if (!$keyPem) { echo json_encode(['error'=>'Private key not found']); exit; }
$priv = openssl_pkey_get_private($keyPem);
if (!$priv) { echo json_encode(['error'=>'Bad key']); exit; }

// Body exactly per v2 swagger
$body = [
    'primerNombre'    => 'JUAN',
    'apellidoPaterno' => 'PEREZ',
    'apellidoMaterno' => 'LOPEZ',
    'fechaNacimiento' => '1980-01-01',
    'RFC'             => 'PELJ800101AAA',
    'nacionalidad'    => 'MX',
    'domicilio' => [
        'direccion'           => 'AVENIDA REFORMA 100',
        'coloniaPoblacion'    => 'CENTRO',
        'delegacionMunicipio' => 'CUAUHTEMOC',
        'ciudad'              => 'CIUDAD DE MEXICO',
        'estado'              => 'CDMX',
        'CP'                  => '06000',
    ],
];
$jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE);

function doCall(string $label, string $url, string $body, array $extraHeaders, $priv, int $algo, string $encoding) {
    $sig = '';
    openssl_sign($body, $sig, $priv, $algo);
    $sigEncoded = ($encoding === 'hex') ? bin2hex($sig) : base64_encode($sig);

    $baseHeaders = [
        'Content-Type: application/json',
        'Accept: application/json',
        'x-api-key: ' . CDC_API_KEY,
        'username: '  . CDC_USER,
        'password: '  . CDC_PASS,
        'x-signature: ' . $sigEncoded,
    ];
    $headers = array_merge($baseHeaders, $extraHeaders);

    $verboseStream = fopen('php://temp', 'w+');

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_VERBOSE        => true,
        CURLOPT_STDERR         => $verboseStream,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'Voltika/1.0 PHP/' . PHP_VERSION,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    rewind($verboseStream);
    $verbose = stream_get_contents($verboseStream);
    fclose($verboseStream);

    $respHeaders = substr((string)$resp, 0, $hdrSize);
    $respBody    = substr((string)$resp, $hdrSize);
    $parsed      = json_decode($respBody, true);

    return [
        'label'       => $label,
        'http_code'   => $code,
        'curl_err'    => $curlErr ?: null,
        'sig_algo'    => $algo === OPENSSL_ALGO_SHA256 ? 'SHA256' : 'SHA384',
        'sig_encoding'=> $encoding,
        'sig_length'  => strlen($sigEncoded),
        'response_body'=> $parsed ?: substr($respBody, 0, 800),
        'response_headers' => substr($respHeaders, 0, 1500),
        'curl_verbose'=> substr($verbose, 0, 3000),
    ];
}

$url = 'https://services.circulodecredito.com.mx/v2/rccficoscore';

$attempts = [];
$attempts[] = doCall('A_SHA256_hex',    $url, $jsonBody, [], $priv, OPENSSL_ALGO_SHA256, 'hex');
$attempts[] = doCall('B_SHA384_hex',    $url, $jsonBody, [], $priv, OPENSSL_ALGO_SHA384, 'hex');
$attempts[] = doCall('C_SHA256_base64', $url, $jsonBody, [], $priv, OPENSSL_ALGO_SHA256, 'base64');
$attempts[] = doCall('D_SHA384_base64', $url, $jsonBody, [], $priv, OPENSSL_ALGO_SHA384, 'base64');

// Also test WITHOUT signature at all, to see CDC's response
$ch = curl_init();
$verboseStream = fopen('php://temp', 'w+');
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $jsonBody,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'x-api-key: ' . CDC_API_KEY,
        'username: '  . CDC_USER,
        'password: '  . CDC_PASS,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_VERBOSE        => true,
    CURLOPT_STDERR         => $verboseStream,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_USERAGENT      => 'Voltika/1.0 PHP/' . PHP_VERSION,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);
rewind($verboseStream);
$verbose = stream_get_contents($verboseStream);
fclose($verboseStream);

$attempts[] = [
    'label'     => 'E_NO_signature',
    'http_code' => $code,
    'response_body'    => json_decode(substr((string)$resp, $hdrSize), true) ?: substr((string)$resp, $hdrSize, 800),
    'response_headers' => substr((string)$resp, 0, $hdrSize),
    'curl_verbose'     => substr($verbose, 0, 2000),
];

$winner = null;
foreach ($attempts as $a) {
    $c = $a['http_code'] ?? 0;
    if (in_array($c, [200, 201, 400, 404], true)) { $winner = $a['label']; break; }
}

echo json_encode([
    'url_tested' => $url,
    'body_sent'  => $body,
    'attempts'   => $attempts,
    'winner'     => $winner,
    'hint'       => $winner
        ? 'Combo ' . $winner . ' pasó la auth. Usar esta en consultar-buro.php.'
        : 'Ningún combo pasó — revisar curl_verbose de cada intento para ver dónde falla la conexión.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
