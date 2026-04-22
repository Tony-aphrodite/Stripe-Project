<?php
/**
 * CDC diagnostic v3 — test multiple signature / auth combinations in one
 * request so we can identify exactly which combo CDC accepts. Each attempt
 * is a real API call (CDC typically does NOT charge for 4xx errors, only
 * for successful lookups with real person data; we send fake data so even
 * a 200 would return an empty/no-match result, not a billable record).
 *
 *   https://voltika.mx/configurador_prueba/php/cdc-diag-v3.php?key=voltika_cdc_2026
 */

$expected = 'voltika_cdc_2026';
if (($_GET['key'] ?? '') !== $expected) {
    http_response_code(403); exit("Forbidden. Add ?key=$expected\n");
}

header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/config.php';

if (!defined('CDC_BASE_URL')) define('CDC_BASE_URL', getenv('CDC_BASE_URL') ?: 'https://services.circulodecredito.com.mx/v2/rcc/ficoscore');
if (!defined('CDC_FOLIO'))    define('CDC_FOLIO', getenv('CDC_FOLIO') ?: '0000080008');
if (!defined('CDC_USER'))     define('CDC_USER', getenv('CDC_USER') ?: '');
if (!defined('CDC_PASS'))     define('CDC_PASS', getenv('CDC_PASS') ?: '');

// ── Load private key (same resolution order as consultar-buro) ──────────────
$keyPem = $_SESSION['cdc_key_pem'] ?? null;
if (!$keyPem) {
    $keyFile = __DIR__ . '/certs/cdc_private.key';
    if (file_exists($keyFile)) $keyPem = @file_get_contents($keyFile);
}
if (!$keyPem) {
    echo json_encode(['error' => 'No private key on server. Run generar-certificado-cdc.php first.']);
    exit;
}
$priv = openssl_pkey_get_private($keyPem);
if (!$priv) { echo json_encode(['error' => 'Could not parse private key']); exit; }

// ── Build request body ──────────────────────────────────────────────────────
$body = [
    'folioOtorgante' => CDC_FOLIO,
    'persona' => [
        'primerNombre' => 'JUAN',
        'apellidoPaterno' => 'PEREZ',
        'apellidoMaterno' => 'LOPEZ',
        'fechaNacimiento' => '1980-01-01',
        'nacionalidad' => 'MX',
        'domicilio' => ['CP' => '06000'],
    ],
];
$jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE);

// ── Signature helpers ───────────────────────────────────────────────────────

// Standard openssl output (DER).
function sigDER($priv, $data, $algo) {
    $sig = '';
    if (!openssl_sign($data, $sig, $priv, $algo)) return null;
    return base64_encode($sig);
}

// Convert DER-encoded ECDSA signature to raw r||s format (IEEE P1363 / JWS).
// DER format: 0x30 len 0x02 rlen r 0x02 slen s
// Raw format: r (size bytes, zero-padded) || s (size bytes, zero-padded)
function derToRaw(string $der, int $size): ?string {
    if (strlen($der) < 8 || ord($der[0]) !== 0x30) return null;
    $p = 2; // skip seq tag+len
    if (ord($der[1]) & 0x80) $p = 2 + (ord($der[1]) & 0x7f);
    if (ord($der[$p]) !== 0x02) return null;
    $rLen = ord($der[$p + 1]);
    $r = substr($der, $p + 2, $rLen);
    $p = $p + 2 + $rLen;
    if (ord($der[$p]) !== 0x02) return null;
    $sLen = ord($der[$p + 1]);
    $s = substr($der, $p + 2, $sLen);
    // Strip leading zero bytes (DER may prepend 0x00 for positive sign)
    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");
    // Pad to size
    if (strlen($r) > $size || strlen($s) > $size) return null;
    $r = str_repeat("\x00", $size - strlen($r)) . $r;
    $s = str_repeat("\x00", $size - strlen($s)) . $s;
    return $r . $s;
}

function sigRaw($priv, $data, $algo, $coordSize) {
    $der = '';
    if (!openssl_sign($data, $der, $priv, $algo)) return null;
    $raw = derToRaw($der, $coordSize);
    return $raw ? base64_encode($raw) : null;
}

// ── Attempt helper ──────────────────────────────────────────────────────────
function attempt(string $label, string $body, string $signatureB64, bool $useBasicAuth): array {
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'x-api-key: ' . CDC_API_KEY,
        'x-signature: ' . $signatureB64,
    ];
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => CDC_BASE_URL,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($useBasicAuth && CDC_USER && CDC_PASS) {
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
        'label'     => $label,
        'http_code' => $code,
        'basic'     => $useBasicAuth,
        'error_msg' => $body2['errores'][0]['mensaje'] ?? ($err ?: null),
        'error_code'=> $body2['errores'][0]['codigo']  ?? null,
        'sig_len'   => strlen($signatureB64),
        'verdict'   => ($code >= 200 && $code < 300) ? '✅ OK' :
                       ($code === 404 ? '✅ OK (persona inexistente)' :
                       ($code === 400 ? '✅ AUTH OK, body issue' : '❌ ' . $code)),
    ];
}

// ── Run attempts ────────────────────────────────────────────────────────────
$sigSha256Der = sigDER($priv, $jsonBody, OPENSSL_ALGO_SHA256);
$sigSha384Der = sigDER($priv, $jsonBody, OPENSSL_ALGO_SHA384);
$sigSha384Raw = sigRaw($priv, $jsonBody, OPENSSL_ALGO_SHA384, 48); // secp384r1 coord size = 48
$sigSha256Raw = sigRaw($priv, $jsonBody, OPENSSL_ALGO_SHA256, 48);

$attempts = [];
if ($sigSha256Der) $attempts[] = attempt('A) SHA256 DER + basic',     $jsonBody, $sigSha256Der, true);
if ($sigSha384Der) $attempts[] = attempt('B) SHA384 DER + basic',     $jsonBody, $sigSha384Der, true);
if ($sigSha384Der) $attempts[] = attempt('C) SHA384 DER (no basic)',  $jsonBody, $sigSha384Der, false);
if ($sigSha384Raw) $attempts[] = attempt('D) SHA384 RAW + basic',     $jsonBody, $sigSha384Raw, true);
if ($sigSha384Raw) $attempts[] = attempt('E) SHA384 RAW (no basic)',  $jsonBody, $sigSha384Raw, false);
if ($sigSha256Raw) $attempts[] = attempt('F) SHA256 RAW + basic',     $jsonBody, $sigSha256Raw, true);

// Find the winning combo
$winner = null;
foreach ($attempts as $a) {
    if (in_array($a['http_code'], [200, 201, 400, 404], true)) { $winner = $a['label']; break; }
}

echo json_encode([
    'attempts'       => $attempts,
    'winner'         => $winner ?: 'Ningún combo pasó la verificación.',
    'recommendation' => $winner
        ? 'Configurar consultar-buro.php para usar la combinación ganadora.'
        : 'Ninguna combinación clásica funcionó. CDC puede requerir un esquema específico (ej. firma de headers / timestamp). Contactar a CDC.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
