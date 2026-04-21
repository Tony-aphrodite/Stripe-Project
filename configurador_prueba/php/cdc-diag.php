<?php
/**
 * CDC diagnostic — verify the currently loaded production credentials
 * actually work against Círculo de Crédito's FICO Score API.
 *
 *   https://voltika.mx/configurador_prueba/php/cdc-diag.php?key=voltika_cdc_2026
 *
 * Protected by the ?key= parameter so random visitors can't trigger real
 * CDC calls (each call is billed in production).
 *
 * Sends a fake-person request so CDC's response tells us if auth works,
 * without charging for an actual credit report (CDC typically only bills
 * successful lookups — 400/401 errors are free).
 */

// ── Access gate ────────────────────────────────────────────────────────────
$expected = 'voltika_cdc_2026';
if (($_GET['key'] ?? '') !== $expected) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit("Forbidden. Add ?key=$expected to the URL.\n");
}

header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

// Make sure we read the same constants consultar-buro.php uses.
if (!defined('CDC_BASE_URL')) {
    define('CDC_BASE_URL', getenv('CDC_BASE_URL') ?: 'https://services.circulodecredito.com.mx/v2/rcc/ficoscore');
}
if (!defined('CDC_FOLIO')) {
    define('CDC_FOLIO', getenv('CDC_FOLIO') ?: '0000080008');
}
if (!defined('CDC_USER')) define('CDC_USER', getenv('CDC_USER') ?: '');
if (!defined('CDC_PASS')) define('CDC_PASS', getenv('CDC_PASS') ?: '');

$out = [
    'config' => [
        'CDC_BASE_URL'      => CDC_BASE_URL,
        'CDC_FOLIO'         => CDC_FOLIO,
        'CDC_USER'          => CDC_USER ? substr(CDC_USER, 0, 4) . '***' . substr(CDC_USER, -3) : '(vacío)',
        'CDC_PASS_set'      => CDC_PASS ? 'sí (oculto)' : 'no',
        'CDC_API_KEY_first6'=> defined('CDC_API_KEY') && CDC_API_KEY ? substr(CDC_API_KEY, 0, 6) : null,
        'CDC_API_KEY_last6' => defined('CDC_API_KEY') && CDC_API_KEY ? substr(CDC_API_KEY, -6) : null,
        'CDC_API_KEY_set'   => defined('CDC_API_KEY') && CDC_API_KEY ? 'sí' : 'no',
    ],
];

// Test person (fake data — CDC will return 404/error for a non-existent RFC,
// but auth is validated BEFORE the lookup so 401 vs 404 tells us what we need).
$body = [
    'folioOtorgante' => CDC_FOLIO,
    'persona' => [
        'primerNombre'    => 'JUAN',
        'apellidoPaterno' => 'PEREZ',
        'apellidoMaterno' => 'LOPEZ',
        'fechaNacimiento' => '1980-01-01',
        'RFC'             => '',
        'CURP'            => '',
        'nacionalidad'    => 'MX',
        'domicilio' => [
            'direccion'           => 'NO DISPONIBLE',
            'coloniaPoblacion'    => 'CENTRO',
            'delegacionMunicipio' => 'CUAUHTEMOC',
            'ciudad'              => 'CIUDAD DE MEXICO',
            'estado'              => 'DF',
            'CP'                  => '06000',
        ],
    ],
];

// ── Load private key and sign the body (CDC production requirement) ───────
session_start();
$signatureB64 = '';
$signatureErr = null;
$keyPem = $_SESSION['cdc_key_pem'] ?? null;
if (!$keyPem) {
    $keyFile = __DIR__ . '/certs/cdc_private.key';
    if (file_exists($keyFile)) $keyPem = @file_get_contents($keyFile);
}
$jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE);
if ($keyPem) {
    $priv = openssl_pkey_get_private($keyPem);
    if ($priv) {
        $sig = '';
        if (openssl_sign($jsonBody, $sig, $priv, OPENSSL_ALGO_SHA256)) {
            $signatureB64 = base64_encode($sig);
        } else {
            $signatureErr = openssl_error_string();
        }
    } else {
        $signatureErr = 'No se pudo cargar la llave privada';
    }
} else {
    $signatureErr = 'Archivo de llave privada no existe (/certs/cdc_private.key)';
}

$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'x-api-key: ' . (defined('CDC_API_KEY') ? CDC_API_KEY : ''),
];
if ($signatureB64) $headers[] = 'x-signature: ' . $signatureB64;

$ch = curl_init();
$curlOpts = [
    CURLOPT_URL            => CDC_BASE_URL,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $jsonBody,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
];
// Allow toggling basic auth via ?auth=0 so we can test both combos.
$useBasic = ($_GET['auth'] ?? '1') !== '0';
if ($useBasic && CDC_USER && CDC_PASS) {
    $curlOpts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
    $curlOpts[CURLOPT_USERPWD]  = CDC_USER . ':' . CDC_PASS;
}
curl_setopt_array($ch, $curlOpts);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

$out['signature'] = [
    'x_signature_sent' => $signatureB64 ? 'sí (' . strlen($signatureB64) . ' chars)' : 'no',
    'error'            => $signatureErr,
    'used_basic_auth'  => $useBasic && CDC_USER && CDC_PASS,
];

$parsed = json_decode($response, true);

$out['request'] = [
    'url'     => CDC_BASE_URL,
    'used_basic_auth' => (CDC_USER && CDC_PASS) ? true : false,
    'used_x_api_key'  => defined('CDC_API_KEY') && CDC_API_KEY ? true : false,
];
$out['response'] = [
    'http_code' => $httpCode,
    'curl_err'  => $curlErr ?: null,
    'body'      => $parsed ?: substr((string)$response, 0, 500),
];

// ── Interpretation ──────────────────────────────────────────────────────────
$interpret = '';
if ($curlErr) {
    $interpret = 'Error de conexión (red/DNS/firewall). Revisa conectividad del servidor.';
} elseif ($httpCode >= 200 && $httpCode < 300) {
    $interpret = '✅ AUTENTICACIÓN OK. CDC respondió exitosamente. Production operativo.';
} elseif ($httpCode === 401) {
    $err = $parsed['errores'][0] ?? null;
    $codigo = $err['codigo'] ?? '';
    $mensaje = $err['mensaje'] ?? '';
    if (strpos($mensaje, 'x-api-key') !== false) {
        $interpret = '❌ x-api-key es inválida o no coincide con la cuenta. Verifica que copiaste el Consumer Key correcto del portal.';
    } elseif (strpos($mensaje, 'producto') !== false || strpos($mensaje, 'aprobación') !== false) {
        $interpret = '⚠️ Las credenciales son correctas PERO el producto FICO Score no está activado en esta cuenta. Contacta a tu ejecutivo CDC para activarlo en la cuenta 4694.';
    } elseif (strpos($mensaje, 'firma') !== false || strpos($mensaje, 'signature') !== false) {
        $interpret = '⚠️ CDC requiere x-signature (firma con llave privada). El código actual no firma — se necesita agregar esa capa.';
    } else {
        $interpret = '❌ 401 no autorizado. Mensaje CDC: ' . $mensaje;
    }
} elseif ($httpCode === 403) {
    $interpret = '❌ 403 prohibido. Posible problema de IP / certificado / permiso en CDC.';
} elseif ($httpCode === 404) {
    $interpret = '✅ AUTENTICACIÓN OK pero la persona de prueba no existe (esperado con datos ficticios). Production operativo.';
} elseif ($httpCode === 400) {
    $interpret = '⚠️ 400 bad request. Auth pasó pero el body tiene algún problema. Revisa el cuerpo enviado.';
} else {
    $interpret = '❓ Código HTTP ' . $httpCode . ' inesperado.';
}
$out['interpretacion'] = $interpret;

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
