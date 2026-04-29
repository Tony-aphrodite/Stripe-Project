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

// account_id anchors the Truora process to our customer. Regex per Truora
// docs: [a-zA-Z0-9_.-]+
//
// IMPORTANT: must be UNIQUE per call. Empirically verified 2026-04-29:
//   - When the same account_id is reused, Truora returns a token that
//     points to the previous (stale/completed) process, and the iframe
//     loads BLANK with no error event.
//   - Diagnostic page worked because it embeds time() in the cliente_id.
//   - Test mode worked because state.telefono is empty so we hit the
//     random branch.
//   - Real flow failed because every retry by the same phone number
//     produced the same account_id voltika_c_<phone>.
//
// Fix: always append a random suffix. We still embed the cliente_id (phone)
// so admin/forensics can correlate; the cliente_id + nombre + email also
// land in our verificaciones_identidad stub row for the webhook.
$randomSuffix = bin2hex(random_bytes(4)); // 8 hex chars
$accountId = $clienteId !== ''
    ? 'voltika_c_' . preg_replace('/[^a-zA-Z0-9]/', '', $clienteId) . '_' . $randomSuffix
    : 'voltika_t_' . $randomSuffix;

// ── Build the redirect URL (where Truora sends the user after the flow) ─
//
// SECURITY/UX (2026-04-29 incident): redirect_url MUST NOT point at the
// SPA itself. When Truora's email-OTP step redirects the user back to
// `/configurador_prueba/#credito-identidad`, the SPA reloads, restores
// state from localStorage, and routes to whatever paso the persisted
// state holds (boss's case: landed on Círculo de Crédito consent screen
// mid-Truora-flow, breaking the verification).
//
// Use a static landing page instead: confirms the OTP completed and
// asks the user to return to the original tab. The SPA continues to
// own its own state, untouched by Truora's redirect.
//
// Also use voltika.mx (no www) — the .htaccess force-redirects www
// requests but using non-www directly avoids an extra hop and prevents
// a www→non-www redirect from breaking Truora's call.
$redirectBase = defined('VOLTIKA_BASE_URL') ? VOLTIKA_BASE_URL : (getenv('VOLTIKA_BASE_URL') ?: 'https://voltika.mx');
$redirectBase = preg_replace('#^https?://www\.#i', 'https://', $redirectBase);
$redirectUrl  = rtrim($redirectBase, '/') . '/configurador_prueba/php/truora-redirect.php';

// ── Call Truora: POST /v1/api-keys ───────────────────────────────────────
// Customer report 2026-04-28: when we prefilled phones[]/emails[], Truora's
// iframe rendered a "review your data" screen with an "Edit" button that
// did nothing — only the blue continue button worked. To dodge that broken
// step entirely we omit prefill and let Truora collect phone/email from
// the user inside the flow. (We still record them in our own stub row
// below for admin/forensics.)
$fields = [
    'key_type'     => 'web',
    'grant'        => 'digital-identity',
    'flow_id'      => TRUORA_FLOW_ID,
    'country'      => 'MX',
    'account_id'   => $accountId,
    'redirect_url' => $redirectUrl,
];

$bodyPairs = [];
foreach ($fields as $k => $v) {
    $bodyPairs[] = urlencode($k) . '=' . urlencode((string)$v);
}
$body = implode('&', $bodyPairs);

$callTruora = function(string $body): array {
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
    $r  = curl_exec($ch);
    $hc = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $er = curl_error($ch);
    curl_close($ch);
    return [$r, $hc, $er];
};

list($resp, $httpCode, $curlErr) = $callTruora($body);

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
//
// SECURITY: persist `expected_curp` (the CURP the customer used for the
// CDC bureau check). When Truora's webhook arrives with the verified
// document, we cross-check that the verified CURP matches expected_curp.
// Mismatch = different person was used for identity vs credit bureau =
// FRAUD. Without this row we would have no anchor to compare against.
try {
    $pdo = getDB();
    foreach ([
        "ALTER TABLE verificaciones_identidad ADD COLUMN truora_account_id VARCHAR(120) NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN truora_flow_id VARCHAR(64) NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN expected_curp VARCHAR(20) NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN verified_curp VARCHAR(20) NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN curp_match TINYINT(1) NULL",
    ] as $ddl) {
        try { $pdo->exec($ddl); } catch (Throwable $e) {}
    }
    $pdo->prepare("INSERT INTO verificaciones_identidad
            (nombre, apellidos, telefono, email, curp, expected_curp,
             truora_account_id, truora_flow_id, identity_status, approved)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 0)")
        ->execute([$nombre, $apellidos, $telefono, $email, $curp, $curp, $accountId, TRUORA_FLOW_ID]);
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
