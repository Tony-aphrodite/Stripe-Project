<?php
/**
 * CDC diagnostic v5 — final combination based on the OFFICIAL CDC
 * signature-manager-php source code:
 *   - SHA256 digest
 *   - HEX encoding (bin2hex, NOT base64)
 *   - Custom "username" / "password" headers (NOT HTTP Basic Auth)
 *   - x-api-key
 *   - Body is what gets signed
 *
 *   https://voltika.mx/configurador_prueba/php/cdc-diag-v5.php?key=voltika_cdc_2026
 */

$expected = 'voltika_cdc_2026';
if (($_GET['key'] ?? '') !== $expected) {
    http_response_code(403); exit("Forbidden. Add ?key=$expected\n");
}

header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/config.php';

if (!defined('CDC_BASE_URL')) define('CDC_BASE_URL', getenv('CDC_BASE_URL') ?: 'https://services.circulodecredito.com.mx/v2/rcc/ficoscore');
if (!defined('CDC_FOLIO'))    define('CDC_FOLIO', getenv('CDC_FOLIO') ?: '0000004694');
if (!defined('CDC_USER'))     define('CDC_USER', getenv('CDC_USER') ?: '');
if (!defined('CDC_PASS'))     define('CDC_PASS', getenv('CDC_PASS') ?: '');

// Load private key (session OR disk)
$keyPem = $_SESSION['cdc_key_pem'] ?? null;
if (!$keyPem) {
    $keyFile = __DIR__ . '/certs/cdc_private.key';
    if (file_exists($keyFile)) $keyPem = @file_get_contents($keyFile);
}
if (!$keyPem) {
    echo json_encode([
        'error' => 'Private key not found. Run generar-certificado-cdc.php first and keep the same browser session.',
    ]);
    exit;
}
$priv = openssl_pkey_get_private($keyPem);
if (!$priv) { echo json_encode(['error' => 'Could not parse private key']); exit; }

// Request body
$body = [
    'folioOtorgante' => CDC_FOLIO,
    'persona' => [
        'primerNombre'    => 'JUAN',
        'apellidoPaterno' => 'PEREZ',
        'apellidoMaterno' => 'LOPEZ',
        'fechaNacimiento' => '1980-01-01',
        'nacionalidad'    => 'MX',
        'domicilio'       => ['CP' => '06000'],
    ],
];
$jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE);

// Sign the body the CDC way: SHA256 -> bin2hex
$sigRaw = '';
if (!openssl_sign($jsonBody, $sigRaw, $priv, OPENSSL_ALGO_SHA256)) {
    echo json_encode(['error' => 'openssl_sign failed']); exit;
}
$sigHex = bin2hex($sigRaw);   // <-- HEX, not base64!

// Build the request exactly like the official CDC PHP client
$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'x-api-key: ' . CDC_API_KEY,
    'username: '  . CDC_USER,
    'password: '  . CDC_PASS,
    'x-signature: ' . $sigHex,
    'User-Agent: FicoscoreV2-Codegen/1.0.0/php',
];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => CDC_BASE_URL,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $jsonBody,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,  // capture response headers
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$err  = curl_error($ch);
curl_close($ch);

$respHeaders = substr((string)$resp, 0, $hdrSize);
$respBody    = substr((string)$resp, $hdrSize);
$parsed      = json_decode($respBody, true);

$verdict = ($code >= 200 && $code < 300) ? '✅✅✅ ÉXITO — CDC Production funcionando' :
           ($code === 404 ? '✅ AUTH OK (persona ficticia no existe — normal)' :
           ($code === 400 ? '✅ AUTH OK (body issue — puede requerir más campos)' :
           '❌ ' . $code));

echo json_encode([
    'config' => [
        'CDC_BASE_URL' => CDC_BASE_URL,
        'CDC_USER'     => CDC_USER ? substr(CDC_USER, 0, 4) . '***' . substr(CDC_USER, -3) : '',
        'CDC_FOLIO'    => CDC_FOLIO,
    ],
    'request' => [
        'sig_algorithm' => 'SHA256',
        'sig_encoding'  => 'hex (bin2hex)',
        'sig_length'    => strlen($sigHex),
        'auth_style'    => 'custom headers (username/password) + x-api-key + x-signature',
    ],
    'response' => [
        'http_code' => $code,
        'curl_err'  => $err ?: null,
        'body'      => $parsed ?: substr($respBody, 0, 500),
        'headers'   => substr($respHeaders, 0, 1000),
    ],
    'verdict' => $verdict,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
