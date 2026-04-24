<?php
/**
 * Voltika — Truora Digital Identity iframe token generator.
 *
 * The iframe integration flow:
 *   1. Frontend (paso-credito-identidad.js) asks our server for a token
 *      when the customer reaches the identity-verification step.
 *   2. This endpoint calls POST https://api.identity.truora.com/v1/api-keys
 *      with the *permanent* Truora-API-Key in headers and
 *      `grant=digital-identity` + `flow_id=<ours>` in the body.
 *   3. Truora returns a *temporary* web API key (JWT) that we pass back to
 *      the frontend. The frontend embeds an <iframe> pointing to
 *      https://identity.truora.com/?token=<that-jwt>.
 *   4. Truora hosts the full capture flow (INE, selfie, liveness, RENAPO)
 *      and posts results to our webhook.
 *
 * Request body (JSON from paso-credito-identidad.js):
 *   { cliente_id, nombre, apellidos, telefono, email, curp }
 *
 * Response:
 *   { ok, iframe_url, token, account_id }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

// ── Required env ─────────────────────────────────────────────────────────
// TRUORA_API_KEY: permanent key created in the Truora dashboard
// TRUORA_FLOW_ID: the IPFxxxxx flow_id for the Voltika identity flow (Flow
//                 Builder → My Flows). Without it, the iframe has no
//                 script to run.
// NOTE: /v1/api-keys lives on api.account.truora.com — NOT
//       api.identity.truora.com (even though the docs put "Generate Token"
//       under the Digital Identity section). Calling the wrong host
//       returns 403 "Missing Authentication Token" from AWS API Gateway.
if (!defined('TRUORA_FLOW_ID')) define('TRUORA_FLOW_ID', getenv('TRUORA_FLOW_ID') ?: '');
if (!defined('TRUORA_IDENTITY_API_URL')) define('TRUORA_IDENTITY_API_URL', 'https://api.identity.truora.com');
if (!defined('TRUORA_ACCOUNT_API_URL'))  define('TRUORA_ACCOUNT_API_URL',  'https://api.account.truora.com');

if (!TRUORA_API_KEY || !TRUORA_FLOW_ID) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Missing Truora config',
        'hint'  => 'Set TRUORA_API_KEY and TRUORA_FLOW_ID in env',
    ]);
    exit;
}

// ── Parse input ──────────────────────────────────────────────────────────
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];

$clienteId = trim((string)($in['cliente_id'] ?? ''));
$nombre    = trim((string)($in['nombre']     ?? ''));
$apellidos = trim((string)($in['apellidos']  ?? ''));
$telefono  = trim((string)($in['telefono']   ?? ''));
$email     = trim((string)($in['email']      ?? ''));
$curp      = strtoupper(trim((string)($in['curp'] ?? '')));

// account_id anchors the Truora process to our customer. Use a stable,
// URL-safe identifier. Regex per Truora docs: [a-zA-Z0-9_.-]+
$accountId = $clienteId !== '' ? 'voltika_c_' . preg_replace('/[^a-zA-Z0-9]/', '', $clienteId)
                               : 'voltika_t_' . bin2hex(random_bytes(6));

// ── Build the redirect URL (where Truora sends the user after the flow) ─
$redirectBase = defined('VOLTIKA_BASE_URL') ? VOLTIKA_BASE_URL : (getenv('VOLTIKA_BASE_URL') ?: 'https://www.voltika.mx');
$redirectUrl  = rtrim($redirectBase, '/') . '/configurador_prueba/#credito-identidad';

// ── Phone normalization (E.164 — +52 + 10 digits for MX) ─────────────────
$phoneE164 = '';
if ($telefono) {
    $d = preg_replace('/\D/', '', $telefono);
    if (strlen($d) === 12 && substr($d, 0, 2) === '52')  $d = substr($d, 2);
    if (strlen($d) === 13 && substr($d, 0, 3) === '521') $d = substr($d, 3);
    if (strlen($d) === 11 && $d[0] === '1')              $d = substr($d, 1);
    if (preg_match('/^[2-9]\d{9}$/', $d)) $phoneE164 = '+52' . $d;
}

// ── Call Truora: POST /v1/api-keys ───────────────────────────────────────
$fields = [
    'key_type'     => 'web',
    'grant'        => 'digital-identity',
    'flow_id'      => TRUORA_FLOW_ID,
    'country'      => 'MX',
    'account_id'   => $accountId,
    'redirect_url' => $redirectUrl,
];
if ($phoneE164) $fields['phones[]'] = $phoneE164;
if ($email)     $fields['emails[]'] = $email;

$body = http_build_query($fields);
// http_build_query uses phones%5B%5D — Truora expects repeated `phones`
// without the bracket encoding. Rebuild manually:
$bodyPairs = [];
foreach ($fields as $k => $v) {
    $k = str_replace('[]', '', $k);
    if (is_array($v)) {
        foreach ($v as $vv) $bodyPairs[] = urlencode($k) . '=' . urlencode((string)$vv);
    } else {
        $bodyPairs[] = urlencode($k) . '=' . urlencode((string)$v);
    }
}
$body = implode('&', $bodyPairs);

$ch = curl_init(TRUORA_ACCOUNT_API_URL . '/v1/api-keys');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_HTTPHEADER     => [
        'Truora-API-Key: ' . TRUORA_API_KEY,
        'Content-Type: application/x-www-form-urlencoded',
    ],
]);
$resp     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

// ── Log the call for forensics ───────────────────────────────────────────
try {
    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS truora_token_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        account_id VARCHAR(120) NULL,
        flow_id VARCHAR(64) NULL,
        http_code INT NULL,
        response MEDIUMTEXT NULL,
        curl_err VARCHAR(500) NULL,
        freg DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_account (account_id)
    )");
    $pdo->prepare("INSERT INTO truora_token_log (account_id, flow_id, http_code, response, curl_err) VALUES (?,?,?,?,?)")
        ->execute([$accountId, TRUORA_FLOW_ID, $httpCode, substr((string)$resp, 0, 5000), substr((string)$curlErr, 0, 500)]);
} catch (Throwable $e) {}

if ($curlErr || $httpCode < 200 || $httpCode >= 300) {
    http_response_code(502);
    echo json_encode([
        'ok'       => false,
        'error'    => 'Truora API key creation failed',
        'http'     => $httpCode,
        'curl_err' => $curlErr ?: null,
        'body'     => substr((string)$resp, 0, 600),
    ]);
    exit;
}

$data  = json_decode((string)$resp, true) ?: [];
$token = (string)($data['api_key'] ?? $data['code'] ?? '');

if ($token === '') {
    http_response_code(502);
    echo json_encode([
        'ok'   => false,
        'error'=> 'No api_key in Truora response',
        'raw'  => $data,
    ]);
    exit;
}

// ── Pre-create a verificaciones_identidad stub so the webhook can upsert ─
// by process_id. Even before Truora decides on a process_id, we record the
// account_id so admin dashboards link the user to any incoming webhook.
try {
    $pdo = getDB();
    foreach ([
        "ALTER TABLE verificaciones_identidad ADD COLUMN truora_account_id VARCHAR(120) NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN truora_flow_id VARCHAR(64) NULL",
    ] as $ddl) {
        try { $pdo->exec($ddl); } catch (Throwable $e) {}
    }
    $pdo->prepare("INSERT INTO verificaciones_identidad
            (nombre, apellidos, telefono, email, truora_account_id, truora_flow_id, identity_status, approved)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', 0)")
        ->execute([$nombre, $apellidos, $telefono, $email, $accountId, TRUORA_FLOW_ID]);
} catch (Throwable $e) { error_log('truora-token stub row: ' . $e->getMessage()); }

// ── Respond ───────────────────────────────────────────────────────────────
$iframeUrl = 'https://identity.truora.com/?token=' . urlencode($token);

echo json_encode([
    'ok'         => true,
    'token'      => $token,
    'iframe_url' => $iframeUrl,
    'account_id' => $accountId,
    'flow_id'    => TRUORA_FLOW_ID,
]);
