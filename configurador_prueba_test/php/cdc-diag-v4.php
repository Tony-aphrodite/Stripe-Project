<?php
/**
 * CDC diagnostic v4 — test with user/password as HEADERS (not Basic Auth).
 * This is what the official CDC PHP client library does.
 *
 *   https://voltika.mx/configurador_prueba/php/cdc-diag-v4.php?key=voltika_cdc_2026
 */

$expected = 'voltika_cdc_2026';
if (($_GET['key'] ?? '') !== $expected) {
    http_response_code(403); exit("Forbidden. Add ?key=$expected\n");
}

header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

if (!defined('CDC_BASE_URL')) define('CDC_BASE_URL', getenv('CDC_BASE_URL') ?: 'https://services.circulodecredito.com.mx/v2/rcc/ficoscore');
if (!defined('CDC_FOLIO'))    define('CDC_FOLIO', getenv('CDC_FOLIO') ?: '0000004694');
if (!defined('CDC_USER'))     define('CDC_USER', getenv('CDC_USER') ?: '');
if (!defined('CDC_PASS'))     define('CDC_PASS', getenv('CDC_PASS') ?: '');

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

function attempt(string $label, string $body, array $extraHeaders, bool $basicAuth = false): array {
    $headers = array_merge([
        'Content-Type: application/json',
        'Accept: application/json',
        'x-api-key: ' . CDC_API_KEY,
        'User-Agent: FicoscoreV2-Codegen/1.0.0/php',
    ], $extraHeaders);

    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => CDC_BASE_URL,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($basicAuth && CDC_USER && CDC_PASS) {
        $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        $opts[CURLOPT_USERPWD]  = CDC_USER . ':' . CDC_PASS;
    }
    curl_setopt_array($ch, $opts);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $body2 = json_decode($resp, true);

    return [
        'label'      => $label,
        'http_code'  => $code,
        'error_msg'  => $body2['errores'][0]['mensaje'] ?? ($err ?: null),
        'error_code' => $body2['errores'][0]['codigo']  ?? null,
        'body'       => $body2 ?: substr((string)$resp, 0, 300),
        'verdict'    => ($code >= 200 && $code < 300) ? '✅ OK' :
                        ($code === 404 ? '✅ 인증 OK (persona inexistente)' :
                        ($code === 400 ? '✅ 인증 OK (body issue)' : '❌ ' . $code)),
    ];
}

// Build attempts — key insight: username/password as HEADERS, not Basic Auth
$attempts = [];

// A. Headers only (the official CDC client way)
$attempts[] = attempt('A) username+password como headers, SIN basic auth, SIN signature', $jsonBody, [
    'username: ' . CDC_USER,
    'password: ' . CDC_PASS,
]);

// B. Headers + signature
$keyPem = null;
$keyFile = __DIR__ . '/certs/cdc_private.key';
if (file_exists($keyFile)) $keyPem = @file_get_contents($keyFile);

if ($keyPem) {
    $priv = openssl_pkey_get_private($keyPem);
    if ($priv) {
        $sigB64 = '';
        $sigRaw = '';
        if (openssl_sign($jsonBody, $sigRaw, $priv, OPENSSL_ALGO_SHA256)) {
            $sigB64 = base64_encode($sigRaw);
        }
        if ($sigB64) {
            $attempts[] = attempt('B) headers + signature SHA256', $jsonBody, [
                'username: ' . CDC_USER,
                'password: ' . CDC_PASS,
                'x-signature: ' . $sigB64,
            ]);
        }
        $sigB384 = '';
        if (openssl_sign($jsonBody, $sigRaw, $priv, OPENSSL_ALGO_SHA384)) {
            $sigB384 = base64_encode($sigRaw);
        }
        if ($sigB384) {
            $attempts[] = attempt('C) headers + signature SHA384', $jsonBody, [
                'username: ' . CDC_USER,
                'password: ' . CDC_PASS,
                'x-signature: ' . $sigB384,
            ]);
        }
    }
}

// D. Without headers, only basic auth (what we tried before — should still fail)
$attempts[] = attempt('D) basic auth solo (referencia)', $jsonBody, [], true);

// E. Just x-api-key, no user/pass at all
$attempts[] = attempt('E) solo x-api-key (referencia)', $jsonBody, []);

$winner = null;
foreach ($attempts as $a) {
    if (in_array($a['http_code'], [200, 201, 400, 404], true)) { $winner = $a['label']; break; }
}

echo json_encode([
    'config' => [
        'CDC_BASE_URL' => CDC_BASE_URL,
        'CDC_USER'     => CDC_USER ? substr(CDC_USER, 0, 4) . '***' . substr(CDC_USER, -3) : '',
        'CDC_FOLIO'    => CDC_FOLIO,
    ],
    'attempts' => $attempts,
    'winner'   => $winner ?: 'Todavía ningún combo funciona.',
    'analisis' => $winner
        ? 'Combo ganador encontrado — aplicar en consultar-buro.php'
        : 'Si ninguno funciona, el problema NO es el método de auth — es la cuenta/IP/producto en el lado de CDC.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
