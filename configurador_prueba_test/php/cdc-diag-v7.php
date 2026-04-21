<?php
/**
 * CDC diagnostic v7 — try every plausible endpoint variant so we can
 * identify which specific product is actually subscribed to this Consumer
 * Key. Different CDC products live at different URLs.
 *
 *   https://voltika.mx/configurador_prueba/php/cdc-diag-v7.php?key=voltika_cdc_2026
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

$body = [
    'primerNombre'    => 'JUAN',
    'apellidoPaterno' => 'PEREZ',
    'apellidoMaterno' => 'LOPEZ',
    'fechaNacimiento' => '1980-01-01',
    'RFC'             => 'PELJ800101AAA',
    'CURP'            => 'PELJ800101HDFXXX00',
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
$jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE);

function tryUrl($url, $jsonBody, $priv) {
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
        'User-Agent: Voltika/1.0',
    ];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonBody,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $parsed = json_decode($resp, true);

    $errMsg = null;
    if ($parsed) {
        $errMsg = $parsed['fault']['faultstring']
               ?? $parsed['errores'][0]['mensaje']
               ?? $parsed['mensaje']
               ?? null;
    }

    $diagnosis = '';
    if ($code >= 200 && $code < 300) $diagnosis = '🎉 SUCCESS';
    elseif ($code == 404 && stripos((string)$errMsg, 'proxy') !== false) $diagnosis = '❌ URL no existe';
    elseif ($code == 404) $diagnosis = '✅ Auth OK (persona inexistente)';
    elseif ($code == 400) $diagnosis = '✅ Auth OK (body issue)';
    elseif ($code == 401 && stripos((string)$errMsg, 'Invalid ApiKey') !== false) $diagnosis = '❌ Key válida, SIN suscripción a este producto';
    elseif ($code == 401) $diagnosis = '❌ Auth rechazado';
    else $diagnosis = '❌ ' . $code;

    return [
        'url' => $url,
        'http_code' => $code,
        'error' => $errMsg ?: ($err ?: null),
        'diagnosis' => $diagnosis,
    ];
}

$urls = [
    // FICO Score (standalone)
    '/v1/ficoscore',
    '/v2/ficoscore',
    // Reporte de Crédito + FICO Score (lo que habíamos probado)
    '/v1/rcficoscore',
    '/v2/rcficoscore',
    '/v2/rcc/ficoscore',
    // Reporte de Crédito Consolidado
    '/v1/rcc-ficoscore',
    '/v2/rcc-ficoscore',
    '/v1/consolidado/ficoscore',
    '/v2/consolidado/ficoscore',
    '/v1/reporteCreditoConsolidadoFicoScore',
    '/v2/reporteCreditoConsolidadoFicoScore',
    // Variantes adicionales con PLD
    '/v1/rcc-ficoscore-pld',
    '/v2/rcc-ficoscore-pld',
    // Report simple
    '/v1/reporteCredito',
    '/v2/reporteCredito',
];

$host = 'https://services.circulodecredito.com.mx';
$results = [];
foreach ($urls as $path) {
    $results[] = tryUrl($host . $path, $jsonBody, $priv);
}

// Find any endpoint that does NOT return "Invalid ApiKey for given resource"
$promising = [];
foreach ($results as $r) {
    if ($r['http_code'] == 404 && strpos((string)$r['error'], 'proxy') !== false) continue;
    if ($r['http_code'] == 401 && strpos((string)$r['error'], 'Invalid ApiKey') !== false) continue;
    $promising[] = $r;
}

echo json_encode([
    'tested_urls' => count($urls),
    'all_results' => $results,
    'promising'   => $promising,
    'hint'        => empty($promising)
        ? 'Todos los endpoints rechazaron la key. Pedir a CDC que confirme el URL exacto que activaron.'
        : 'Hay endpoints que responden distinto — revisa "promising".',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
