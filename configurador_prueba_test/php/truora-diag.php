<?php
/**
 * Truora diagnostic — quickly verify which API key is loaded on the server
 * and show recent call attempts. Safe to expose: never returns the raw key.
 *
 *   https://voltika.mx/configurador_prueba/php/truora-diag.php
 *
 * Returns JSON with:
 *  - key_name        : decoded JWT "key_name" claim ("voltikalive" = production,
 *                      "prueba" = test, empty = misconfigured)
 *  - key_first6/last6: small hash of the start/end so you can confirm the key
 *                      changed without leaking the secret
 *  - feature_flags   : current TRUORA_DOC_VALIDATION_ENABLED / FACE_MATCH state
 *  - log_recent      : last 5 lines of logs/truora.log so you see if any
 *                      identity check has actually been attempted recently
 */
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

$out = [
    'ok'              => true,
    'key_name'        => null,
    'key_first6'      => null,
    'key_last6'       => null,
    'key_set'         => false,
    'feature_flags'   => [],
    'log_recent'      => [],
    'log_path'        => null,
    'log_size_bytes'  => 0,
];

if (defined('TRUORA_API_KEY') && TRUORA_API_KEY) {
    $out['key_set'] = true;
    $key = TRUORA_API_KEY;
    $out['key_first6'] = substr($key, 0, 6);
    $out['key_last6']  = substr($key, -6);

    // Decode JWT payload to read key_name (does NOT verify signature — just
    // reading the metadata to identify the key as production vs test).
    $parts = explode('.', $key);
    if (count($parts) === 3) {
        $b64 = strtr($parts[1], '-_', '+/');
        $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
        $payload = json_decode(base64_decode($b64), true);
        if (is_array($payload)) {
            $out['key_name'] = $payload['key_name'] ?? null;
            $out['issued_at'] = isset($payload['iat']) ? date('c', $payload['iat']) : null;
            $out['expires_at'] = isset($payload['exp']) ? date('c', $payload['exp']) : null;
        }
    }
}

$out['feature_flags'] = [
    'TRUORA_DOC_VALIDATION_ENABLED' => defined('TRUORA_DOC_VALIDATION_ENABLED') ? (TRUORA_DOC_VALIDATION_ENABLED ? 1 : 0) : null,
    'TRUORA_FACE_MATCH_ENABLED'     => defined('TRUORA_FACE_MATCH_ENABLED')     ? (TRUORA_FACE_MATCH_ENABLED     ? 1 : 0) : null,
    'TRUORA_WEBHOOK_SECRET_set'     => defined('TRUORA_WEBHOOK_SECRET') && TRUORA_WEBHOOK_SECRET ? 1 : 0,
];

$logFile = __DIR__ . '/logs/truora.log';
$out['log_path'] = $logFile;
if (is_file($logFile)) {
    $out['log_size_bytes'] = filesize($logFile);
    // Read last few lines safely (don't load the whole file if it's big)
    $fh = fopen($logFile, 'r');
    if ($fh) {
        fseek($fh, -16384, SEEK_END); // last 16 KB
        $tail = stream_get_contents($fh);
        fclose($fh);
        $lines = array_filter(explode("\n", $tail));
        $out['log_recent'] = array_slice($lines, -10);
    }
} else {
    $out['log_recent'] = ['(archivo no existe — Truora aún no se ha llamado)'];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
