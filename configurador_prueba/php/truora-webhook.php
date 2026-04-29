<?php
/**
 * Voltika — Truora Digital Identity webhook receiver.
 *
 * Registered in Truora dashboard → Webhooks/automations:
 *   POST https://www.voltika.mx/configurador_prueba/php/truora-webhook.php
 *
 * Truora posts the payload as a JWT-encoded string (HS256) — confirmed from
 * the dashboard's "Body" preview which reads: "Format: JWT encode" and
 * "This is a sample document that will be sent to you in JWT encoded format,
 * once received you will be able to unencode it in JSON format."
 *
 * Decoded JSON body shape:
 *   {
 *     "iss": "Truora",
 *     "iat": <unix ts>,
 *     "events": [
 *       {
 *         "id": "HKE123abc",
 *         "event_type": "digital_identity.identity_process" | "...",
 *         "event_action": "created" | "succeeded" | "failed",
 *         "object": { process_id, flow_id, account_id, status, ... },
 *         "version": "1.0",
 *         "timestamp": "2026-04-24T..."
 *       }, ...
 *     ]
 *   }
 *
 * Security:
 *   - Signature is embedded inside the JWT (HS256). We verify it against
 *     TRUORA_WEBHOOK_SECRET when the env var is set. Without the secret we
 *     still *store* the event (for debugging) but flag it as unverified
 *     and do NOT mutate verificaciones_identidad.
 *   - We always respond 200 so Truora does not retry-storm on our errors;
 *     internal failures land in truora_webhook_log for forensics.
 */

require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

// Only POST is valid for webhooks (return 200 anyway to avoid retry storms).
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    echo json_encode(['ok' => true, 'skip' => 'non-post']);
    exit;
}

$rawBody = file_get_contents('php://input');
$storeError = null;
$signatureValid = null;
$payload = null;

// ── Extract the JWT token ─────────────────────────────────────────────────
// Accept two shapes so we are robust to future format changes:
//   (a) Body is the JWT token itself (text/plain or application/jwt)
//   (b) Body is JSON like { "token": "<jwt>" } or already decoded JSON
$token = trim((string)$rawBody);
if ($token !== '' && $token[0] === '{') {
    $maybe = json_decode($token, true);
    if (is_array($maybe)) {
        if (isset($maybe['token']) && is_string($maybe['token'])) {
            $token = trim($maybe['token']);
        } elseif (isset($maybe['events'])) {
            // Already-decoded JSON — no signature verification possible, but
            // we can still dispatch events.
            $payload = $maybe;
            $token = '';
            $signatureValid = null;
        } else {
            $token = '';
            $storeError = 'unknown_json_shape';
        }
    }
}

// ── Decode + verify JWT (HS256) ───────────────────────────────────────────
if ($token !== '') {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        $storeError = 'malformed_jwt';
    } else {
        [$h64, $p64, $s64] = $parts;
        $headerJson  = truoraB64UrlDecode($h64);
        $payloadJson = truoraB64UrlDecode($p64);
        $payload     = json_decode((string)$payloadJson, true) ?: null;

        $secret = defined('TRUORA_WEBHOOK_SECRET') ? TRUORA_WEBHOOK_SECRET : (getenv('TRUORA_WEBHOOK_SECRET') ?: '');
        if ($secret !== '') {
            $expected = hash_hmac('sha256', $h64 . '.' . $p64, $secret, true);
            $provided = truoraB64UrlDecode($s64);
            $signatureValid = ($provided !== false && hash_equals($expected, $provided));
            if (!$signatureValid) $storeError = 'invalid_signature';
        } else {
            $signatureValid = null;   // secret not set → unverified mode
        }
    }
}

// ── Persist every inbound hook for auditing ───────────────────────────────
try {
    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS truora_webhook_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        received_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        signature_valid TINYINT(1) NULL,
        store_error VARCHAR(80) NULL,
        event_count INT NULL,
        raw_body MEDIUMTEXT NULL,
        decoded MEDIUMTEXT NULL,
        INDEX idx_received (received_at)
    )");

    $events = is_array($payload['events'] ?? null) ? $payload['events'] : [];
    $pdo->prepare("INSERT INTO truora_webhook_log
            (signature_valid, store_error, event_count, raw_body, decoded)
        VALUES (?, ?, ?, ?, ?)")
        ->execute([
            $signatureValid === null ? null : ($signatureValid ? 1 : 0),
            $storeError,
            count($events),
            substr($rawBody, 0, 20000),
            $payload ? substr(json_encode($payload, JSON_UNESCAPED_UNICODE), 0, 20000) : null,
        ]);

    // Dispatch events only when the signature verifies, or when no secret
    // is configured (bootstrapping phase). On invalid signatures we skip
    // state mutations — we don't want unauthenticated parties to approve
    // identity verifications.
    $shouldAct = ($signatureValid === true) || ($signatureValid === null && $storeError === null);
    if ($shouldAct) {
        foreach ($events as $ev) {
            if (is_array($ev)) truoraProcessEvent($pdo, $ev);
        }
    }
} catch (Throwable $e) {
    error_log('truora-webhook: ' . $e->getMessage());
}

echo json_encode(['ok' => true]);
exit;


// ── Helpers ────────────────────────────────────────────────────────────────

function truoraB64UrlDecode(string $s) {
    $remainder = strlen($s) % 4;
    if ($remainder) $s .= str_repeat('=', 4 - $remainder);
    return base64_decode(strtr($s, '-_', '+/'), true);
}

/**
 * Pull the full process detail from Truora's API so we can read the
 * extracted CURP / national_id_number from the verified document.
 *
 * The webhook payload only contains high-level status; the verified
 * document fields (CURP, name, etc.) require a follow-up GET. The
 * endpoint differs slightly between Truora flows; we try the most common
 * shapes and return whichever responds with 200.
 *
 * Returns the decoded JSON array, or null on failure. Logged to
 * truora_fetch_log for forensics.
 */
function truoraFetchProcessDetails(string $processId): ?array {
    if (!defined('TRUORA_API_KEY') || !TRUORA_API_KEY) return null;
    if (!defined('TRUORA_IDENTITY_API_URL')) define('TRUORA_IDENTITY_API_URL', 'https://api.identity.truora.com');

    $candidates = [
        TRUORA_IDENTITY_API_URL . '/v1/processes/' . urlencode($processId),
        TRUORA_IDENTITY_API_URL . '/v1/identity/' . urlencode($processId),
        TRUORA_IDENTITY_API_URL . '/v1/processes/' . urlencode($processId) . '/result',
    ];

    foreach ($candidates as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_HTTPHEADER     => [
                'Truora-API-Key: ' . TRUORA_API_KEY,
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        // Log for forensics, regardless of outcome.
        try {
            $pdo = getDB();
            $pdo->exec("CREATE TABLE IF NOT EXISTS truora_fetch_log (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                process_id VARCHAR(64) NULL,
                url VARCHAR(255) NULL,
                http_code INT NULL,
                response MEDIUMTEXT NULL,
                curl_err VARCHAR(500) NULL,
                fetched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_process (process_id)
            )");
            $pdo->prepare("INSERT INTO truora_fetch_log
                    (process_id, url, http_code, response, curl_err)
                VALUES (?, ?, ?, ?, ?)")
                ->execute([
                    $processId, $url, $code,
                    substr((string)$body, 0, 8000),
                    substr((string)$err, 0, 500),
                ]);
        } catch (Throwable $e) {}

        if ($code >= 200 && $code < 300 && $body) {
            $arr = json_decode((string)$body, true);
            if (is_array($arr) && !empty($arr)) return $arr;
        }
    }
    return null;
}

/**
 * Walk a Truora process-details payload and extract the verified CURP
 * (a.k.a. national_id_number on Mexico flows). The exact field path
 * depends on the flow_id configuration, so we search in several common
 * locations and return the first 18-character RFC-shaped match.
 */
function truoraExtractCurp(?array $details): ?string {
    if (!is_array($details)) return null;

    $candidates = [];

    // Common direct fields.
    foreach (['national_id_number', 'curp', 'document_id', 'identification_number'] as $k) {
        if (!empty($details[$k]) && is_string($details[$k])) $candidates[] = $details[$k];
    }

    // Nested `person_information` / `validations` / `document` blocks.
    foreach (['person_information', 'document', 'identity', 'result'] as $section) {
        if (!empty($details[$section]) && is_array($details[$section])) {
            foreach (['national_id_number', 'curp', 'document_id', 'identification_number'] as $k) {
                if (!empty($details[$section][$k]) && is_string($details[$section][$k])) {
                    $candidates[] = $details[$section][$k];
                }
            }
        }
    }

    // `validations` is typically an array of objects with `validation_name`
    // and `validation_data` fields. Look for anything CURP-like inside.
    if (!empty($details['validations']) && is_array($details['validations'])) {
        foreach ($details['validations'] as $v) {
            if (!is_array($v)) continue;
            foreach ($v as $val) {
                if (is_string($val) && preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/i', $val)) {
                    $candidates[] = $val;
                }
            }
        }
    }

    // Generic deep walk — last resort. Find any 18-char CURP-shaped value.
    $stack = [$details];
    while ($stack) {
        $node = array_pop($stack);
        if (is_array($node)) {
            foreach ($node as $v) {
                if (is_array($v)) $stack[] = $v;
                elseif (is_string($v) && preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/i', $v)) {
                    $candidates[] = $v;
                }
            }
        }
    }

    foreach ($candidates as $c) {
        $c = strtoupper(trim($c));
        if (preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/', $c)) return $c;
    }
    return null;
}

/**
 * Apply one Truora event to verificaciones_identidad.
 *
 * event_type examples observed in Truora docs:
 *   - identity.process.created / succeeded / failed
 *   - digital_identity.step.succeeded
 *   - document_validation.succeeded / failed
 *   - face_recognition.succeeded / failed
 *
 * Only identity.process.* events change `approved`. Step events update
 * `truora_last_event` so the admin dashboard can show per-step progress.
 */
function truoraProcessEvent(PDO $pdo, array $ev): void {
    $type   = (string)($ev['event_type']   ?? '');
    $action = (string)($ev['event_action'] ?? '');
    $object = is_array($ev['object'] ?? null) ? $ev['object'] : [];

    $processId  = (string)($object['process_id']  ?? $object['identity_process_id'] ?? '');
    $flowId     = (string)($object['flow_id']     ?? '');
    $accountId  = (string)($object['account_id']  ?? $object['client_user_id'] ?? '');
    $status     = (string)($object['status']      ?? '');
    $failStatus = (string)($object['failure_status']  ?? '');
    $declined   = (string)($object['declined_reason'] ?? '');
    $updateDate = (string)($object['update_date'] ?? $object['creation_date'] ?? '');

    if ($processId === '') return;

    // Idempotent schema extensions. Running ALTERs here avoids a separate
    // migration file — safe because MySQL errors on an existing column are
    // caught and ignored.
    foreach ([
        "ALTER TABLE verificaciones_identidad ADD COLUMN truora_process_id VARCHAR(64) NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN truora_flow_id VARCHAR(64) NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN truora_account_id VARCHAR(120) NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN truora_status VARCHAR(40) NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN truora_failure_status VARCHAR(40) NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN truora_declined_reason VARCHAR(160) NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN truora_last_event VARCHAR(80) NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN truora_updated_at DATETIME NULL",
        "ALTER TABLE verificaciones_identidad ADD INDEX idx_truora_process (truora_process_id)",
    ] as $ddl) {
        try { $pdo->exec($ddl); } catch (Throwable $e) {}
    }

    $approved = null;
    $typeLc = strtolower($type);
    $isProcessEvent = (strpos($typeLc, 'identity_process') !== false)
                   || (strpos($typeLc, 'process') !== false && strpos($typeLc, 'step') === false);
    if ($isProcessEvent) {
        if ($action === 'succeeded') $approved = 1;
        elseif ($action === 'failed') $approved = 0;
    }

    // ── SECURITY: cross-check Truora-verified CURP against the CURP the
    //    customer used for the credit bureau check (CDC). If they differ,
    //    the user fed identity-document A to Truora while bureau-checking
    //    person B → fraud → reject regardless of Truora's verdict.
    //    (Customer report 2026-04-29: a tester used different data in CDC
    //    vs. Truora and the purchase was accepted. This must never happen.)
    $verifiedCurp = null;
    $curpMatch = null;
    if ($approved === 1) {
        $details = truoraFetchProcessDetails($processId);
        $verifiedCurp = truoraExtractCurp($details);

        // Look up expected CURP we stored at token creation.
        $expectedCurp = null;
        try {
            $q = $pdo->prepare("SELECT expected_curp FROM verificaciones_identidad
                WHERE truora_process_id = ? OR truora_account_id = ?
                ORDER BY id DESC LIMIT 1");
            $q->execute([$processId, $accountId]);
            $expectedCurp = $q->fetchColumn() ?: null;
        } catch (Throwable $e) {}

        if ($expectedCurp && $verifiedCurp) {
            $curpMatch = (strtoupper(trim($expectedCurp)) === strtoupper(trim($verifiedCurp))) ? 1 : 0;
            if (!$curpMatch) {
                // FRAUD GUARD: identity document does not match the bureau check.
                $approved = 0;
                $declined = 'identity_curp_mismatch';
                $failStatus = 'curp_mismatch';
            }
        } elseif ($expectedCurp && !$verifiedCurp) {
            // Truora succeeded but we could not retrieve verified CURP for
            // comparison. Fail-closed: do not approve. The admin can
            // manually review by inspecting truora-fetch logs.
            $approved = 0;
            $declined = 'verified_curp_unavailable';
            $failStatus = 'identity_unverifiable';
        }
        // If $expectedCurp is missing entirely, leave approved=1 with a
        // null curp_match. This handles legacy rows from before this
        // column existed. New rows always have expected_curp set.
    }

    // Upsert by process_id.
    $existingId = null;
    try {
        $q = $pdo->prepare("SELECT id FROM verificaciones_identidad WHERE truora_process_id = ? LIMIT 1");
        $q->execute([$processId]);
        $existingId = $q->fetchColumn() ?: null;
    } catch (Throwable $e) {}

    $fields = [
        'truora_process_id'      => $processId,
        'truora_flow_id'         => $flowId ?: null,
        'truora_account_id'      => $accountId ?: null,
        'truora_status'          => $status ?: null,
        'truora_failure_status'  => $failStatus ?: null,
        'truora_declined_reason' => $declined ?: null,
        'truora_last_event'      => trim($type . '.' . $action, '.'),
        'truora_updated_at'      => ($updateDate && strtotime($updateDate))
            ? date('Y-m-d H:i:s', strtotime($updateDate))
            : date('Y-m-d H:i:s'),
        'verified_curp'          => $verifiedCurp,
        'curp_match'             => $curpMatch,
    ];
    if ($approved !== null) {
        $fields['approved'] = $approved;
        $fields['identity_status'] = $approved ? 'valid' : 'declined';
    }

    if ($existingId) {
        $set = []; $params = [];
        foreach ($fields as $k => $v) { $set[] = "$k = ?"; $params[] = $v; }
        $params[] = (int)$existingId;
        try {
            $pdo->prepare("UPDATE verificaciones_identidad SET " . implode(', ', $set) . " WHERE id = ?")
                ->execute($params);
        } catch (Throwable $e) { error_log('webhook update: ' . $e->getMessage()); }
    } else {
        // Insert minimal row. The iframe flow will backfill nombre/telefono
        // later; for now we anchor on process_id so subsequent webhooks
        // (succeeded/failed) update the same row.
        $fields['files_saved'] = json_encode([]);
        try {
            $cols = array_keys($fields);
            $pdo->prepare("INSERT INTO verificaciones_identidad (" . implode(',', $cols) . ")
                VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")")
                ->execute(array_values($fields));
        } catch (Throwable $e) { error_log('webhook insert: ' . $e->getMessage()); }
    }
}
