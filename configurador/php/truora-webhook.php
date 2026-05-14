<?php
/**
 * Voltika — Truora Digital Identity webhook receiver.
 *
 * Registered in Truora dashboard → Webhooks/automations:
 *   POST https://www.voltika.mx/configurador/php/truora-webhook.php
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
require_once __DIR__ . '/truora-api-helpers.php';
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

if (!function_exists('truoraB64UrlDecode')) {
function truoraB64UrlDecode(string $s) {
    $remainder = strlen($s) % 4;
    if ($remainder) $s .= str_repeat('=', 4 - $remainder);
    return base64_decode(strtr($s, '-_', '+/'), true);
}
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
if (!function_exists('truoraFetchProcessDetails')) {
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
}

/**
 * Walk a Truora process-details payload and extract the verified CURP
 * (a.k.a. national_id_number on Mexico flows). The exact field path
 * depends on the flow_id configuration, so we search in several common
 * locations and return the first 18-character RFC-shaped match.
 */
if (!function_exists('truoraExtractCurp')) {
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
        "ALTER TABLE verificaciones_identidad ADD COLUMN expected_name VARCHAR(220) NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN verified_name VARCHAR(220) NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN name_match TINYINT(1) NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN manual_review_required TINYINT(1) NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN manual_review_reason VARCHAR(160) NULL",
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
    //
    // CURP extraction strategy (most reliable first):
    //   1. Walk the webhook event's `object` directly — Truora ships the
    //      verified document fields here for completed identity processes
    //      and this avoids any dependency on a separate REST endpoint.
    //   2. Fall back to GET /v1/processes/<id> only if the webhook
    //      payload has no usable CURP.
    //   3. Strict block on mismatch OR if no CURP could be obtained.
    // CURP cross-check — runs REGARDLESS of Truora's own status.
    // Customer brief 2026-04-30 (false-manual-review fix): when a user
    // typed CURP X and uploaded an INE for CURP Y, Truora's RENAPO/face
    // check often returns `failed` outright (not "success with curp
    // mismatch"). Our old code gated the curp-compare on
    // `$approved === 1`, so the actionable mismatch was hidden behind
    // a generic manual-review path. We now extract the verified CURP
    // and compare even when Truora itself failed — if expected ≠
    // verified, classify as identity_curp_mismatch (user-recoverable)
    // instead of routing through the crew. Truora's failure with no
    // CURP available still falls through to manual review at the
    // escalation block below.
    $verifiedCurp = null;
    $curpMatch    = null;
    $curpSource   = null;

    // Try webhook payload first (cheapest, most reliable).
    $verifiedCurp = truoraExtractCurp($object);
    if ($verifiedCurp) $curpSource = 'webhook_object';
    if (!$verifiedCurp) {
        $verifiedCurp = truoraExtractCurp($ev);
        if ($verifiedCurp) $curpSource = 'webhook_event';
    }
    if (!$verifiedCurp) {
        $details = truoraFetchProcessDetails($processId);
        $verifiedCurp = truoraExtractCurp($details);
        if ($verifiedCurp) $curpSource = 'api_fetch';
    }

    // Look up expected CURP we stored at token creation.
    $expectedCurp = null;
    try {
        $q = $pdo->prepare("SELECT expected_curp FROM verificaciones_identidad
            WHERE truora_process_id = ? OR truora_account_id = ?
            ORDER BY id DESC LIMIT 1");
        $q->execute([$processId, $accountId]);
        $expectedCurp = $q->fetchColumn() ?: null;
    } catch (Throwable $e) {}

    // Log every comparison decision so admin can audit fraud rejections.
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS truora_curp_audit (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            process_id VARCHAR(64) NULL,
            expected_curp VARCHAR(20) NULL,
            verified_curp VARCHAR(20) NULL,
            curp_source VARCHAR(40) NULL,
            decision VARCHAR(40) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_process (process_id)
        )");
        $decision = ($expectedCurp && $verifiedCurp)
            ? (strtoupper(trim($expectedCurp)) === strtoupper(trim($verifiedCurp)) ? 'match' : 'mismatch')
            : ($expectedCurp ? 'no_verified_curp' : 'no_expected_curp');
        $pdo->prepare("INSERT INTO truora_curp_audit
                (process_id, expected_curp, verified_curp, curp_source, decision)
            VALUES (?, ?, ?, ?, ?)")
            ->execute([$processId, $expectedCurp, $verifiedCurp, $curpSource, $decision]);
    } catch (Throwable $e) {}

    if ($expectedCurp && $verifiedCurp) {
        $curpMatch = (strtoupper(trim($expectedCurp)) === strtoupper(trim($verifiedCurp))) ? 1 : 0;
        if (!$curpMatch) {
            // FRAUD GUARD / RECOVERABLE INPUT MISTAKE: classify both
            // Truora-success-with-mismatch AND Truora-failure-with-mismatch
            // as identity_curp_mismatch so the SPA shows the clean
            // "regresa, corrige tu CURP" CTA.
            $approved   = 0;
            $declined   = 'identity_curp_mismatch';
            $failStatus = 'curp_mismatch';
        }
    } elseif ($approved === 1 && $expectedCurp && !$verifiedCurp) {
        // Truora succeeded but we could not retrieve verified CURP.
        // STRICT mode: do not approve. Customer requirement 2026-04-29:
        // an order MUST NOT appear in admin until identity is fully
        // cross-checked. The admin can review via truora_curp_audit +
        // truora_fetch_log to find the missing field.
        $approved   = 0;
        $declined   = 'verified_curp_unavailable';
        $failStatus = 'identity_unverifiable';
    }
    // If $expectedCurp is missing entirely (legacy rows or test mode),
    // leave $approved unchanged with a null $curpMatch.

    // Legacy `if ($approved === 1)` block kept for the name cross-check
    // below — that section reads expected_name and is conceptually a
    // different branch from CURP. Wrap it without re-fetching things.
    if ($approved === 1) {
        // If $expectedCurp is missing entirely (legacy rows or test mode
        // where the customer didn't provide CURP), leave approved=1 with a
        // null curp_match. New rows always have expected_curp set when
        // CDC ran on real customer data.
    }

    // ── Name cross-check (customer brief 2026-04-30) ────────────────────────
    // "If the name is different from the CDC validation, we need to send a
    //  message: use the same information of the previous screen and restart
    //  truora validation."
    //
    // Compare Truora-verified document name against the name we anchored at
    // token creation time (expected_name = nombre + apellidos from the
    // previous CDC screen). A mismatch is recoverable — typically a typo
    // or the user grabbing the wrong INE — so we surface a specific
    // declined_reason the frontend translates into a "use same info, retry"
    // CTA, distinct from the manual-review path.
    $verifiedName = null;
    $nameMatch    = null;
    if ($approved === 1) {
        $expectedName = null;
        try {
            $q = $pdo->prepare("SELECT expected_name FROM verificaciones_identidad
                WHERE truora_process_id = ? OR truora_account_id = ?
                ORDER BY id DESC LIMIT 1");
            $q->execute([$processId, $accountId]);
            $expectedName = $q->fetchColumn() ?: null;
        } catch (Throwable $e) {}

        $nameInfo = truoraExtractName($object);
        if (!$nameInfo) $nameInfo = truoraExtractName($ev);
        if (!$nameInfo) {
            $details = truoraFetchProcessDetails($processId);
            if (is_array($details)) $nameInfo = truoraExtractName($details);
        }
        if (is_array($nameInfo)) {
            $verifiedName = $nameInfo['full_name'] ?: trim(
                ($nameInfo['first_name'] ?? '') . ' ' .
                ($nameInfo['last_name']  ?? '') . ' ' .
                ($nameInfo['second_last_name'] ?? '')
            );
            $verifiedName = trim((string)$verifiedName);
        }

        if ($expectedName && $verifiedName) {
            // Normalise expected to the same upper/no-accent shape used by
            // truoraExtractName so the comparison is symmetric.
            $expectedNorm = strtoupper(strtr(
                preg_replace('/\s+/', ' ', trim($expectedName)),
                ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
                 'á'=>'A','é'=>'E','í'=>'I','ó'=>'O','ú'=>'U','ü'=>'U','ñ'=>'N']
            ));
            $nameMatch = truoraNamesMatch($expectedNorm, $verifiedName) ? 1 : 0;
            // CURP-anchor revision (customer brief 2026-04-30):
            // name_match is recorded but DOES NOT BLOCK. Accent /
            // whitespace / homoclave-letter differences caused valid
            // customers to be refused and burn bureau queries on every
            // retry. The CURP comparison above is the strict gate.
        }
    }

    // ── Manual-review escalation (customer brief 2026-04-30) ────────────────
    // "If the validation fails, ... because truora detect false information,
    //  we need to send a message for manual validation for our crew."
    //
    // Any time the process ends in failure for a non-data-mismatch reason
    // (Truora's own fraud / liveness / document-tampering checks), we set
    // manual_review_required=1. The admin dashboard surfaces these so the
    // crew can call the customer back. CURP/name-mismatch failures are
    // user-recoverable and stay out of the manual queue.
    $manualReview       = null;
    $manualReviewReason = null;
    if ($approved === 0 && $isProcessEvent && $action === 'failed') {
        $userRecoverable = in_array($declined, [
            'identity_curp_mismatch',
            'identity_name_mismatch',
            'verified_curp_unavailable',
        ], true);
        if (!$userRecoverable) {
            $manualReview = 1;
            $manualReviewReason = $declined ?: ($failStatus ?: 'truora_validation_failed');
        }
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
        'verified_name'          => $verifiedName,
        'name_match'             => $nameMatch,
    ];
    if ($manualReview !== null) {
        $fields['manual_review_required'] = $manualReview;
        $fields['manual_review_reason']   = $manualReviewReason;
    }
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
            $existingId = (int)$pdo->lastInsertId();
        } catch (Throwable $e) { error_log('webhook insert: ' . $e->getMessage()); }
    }

    // ── Round 22 (2026-05-14, Óscar) — Auto-capture INE + selfie photos ────
    // Truora returns the document/selfie images in /v1/processes/<id>/result
    // as 15-minute presigned S3 URLs. After ~15 min those URLs rotate to a
    // CDN form on `files.truora.com` that we cannot download from backend.
    // Webhook arrives at completion time → URLs are fresh → this is the
    // only window where we can pull the photos into our own storage.
    //
    // ONLY runs on the final success event so we don't waste requests on
    // every intermediate step event. Failed processes have no photos worth
    // archiving (Truora discards rejected documents per their TOS).
    if ($approved === 1 && $existingId) {
        try {
            truoraCaptureProcessPhotos($pdo, $processId, (int)$existingId, $accountId);
        } catch (Throwable $e) {
            error_log('webhook photo-capture failed (process=' . $processId . '): ' . $e->getMessage());
        }
    }
}

/**
 * Round 22 — Download INE front/reverse + selfie from Truora's process
 * result while the presigned S3 URLs are still valid (~15 min window).
 *
 * Saves files to configurador/php/uploads/ with the legacy naming
 * convention (`<prefix>_ine_frente.png`, `_ine_reverso.png`, `_selfie.png`)
 * so admin-identidad.php's substring matcher keeps working unchanged.
 * Updates verificaciones_identidad.files_saved with the JSON array of
 * filenames the admin Documentos modal already knows how to render.
 *
 * Returns the number of photos successfully saved (0..3). All failures
 * are logged to truora_fetch_log + error_log so future investigations
 * can replay them.
 */
function truoraCaptureProcessPhotos(PDO $pdo, string $processId, int $verifId, string $accountId = ''): int {
    if ($processId === '' || $verifId <= 0) return 0;

    // Step 1 — fetch /v1/processes/<id>/result (this is where presigned URLs
    // live). truoraFetchProcessDetails returns the FIRST 200; explicitly
    // call /result so we don't depend on candidate ordering.
    if (!defined('TRUORA_IDENTITY_API_URL')) define('TRUORA_IDENTITY_API_URL', 'https://api.identity.truora.com');
    $resultUrl = TRUORA_IDENTITY_API_URL . '/v1/processes/' . urlencode($processId) . '/result';

    $ch = curl_init($resultUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPHEADER     => [
            'Truora-API-Key: ' . (defined('TRUORA_API_KEY') ? TRUORA_API_KEY : ''),
            'Accept: application/json',
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Audit-log so we can replay this fetch later if photos fail.
    try {
        $pdo->prepare("INSERT INTO truora_fetch_log
                (process_id, url, http_code, response, curl_err)
            VALUES (?, ?, ?, ?, NULL)")
            ->execute([$processId, '/result (webhook capture)', $code, substr((string)$body, 0, 8000)]);
    } catch (Throwable $e) {}

    if ($code < 200 || $code >= 300 || !$body) {
        error_log('truoraCaptureProcessPhotos: /result returned ' . $code . ' for ' . $processId);
        return 0;
    }
    $result = json_decode((string)$body, true);
    if (!is_array($result)) return 0;

    // Step 2 — walk the response for image URLs. Truora returns:
    //   { "front_image": "...", "reverse_image": "...", ... selfie under
    //     face validation block ... }
    // Same classifier we use in sync-truora.php — recognise `front_image`,
    // `reverse_image`, and any selfie/face URL pointing at a Truora-owned
    // host (AWS S3 presigned OR files.truora.com — though the latter
    // shouldn't appear yet at webhook time).
    $imageUrls = [];
    $walk = function ($node, $contextKey = '', $parentKey = '') use (&$walk, &$imageUrls) {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                $cur = is_string($k) ? strtolower((string)$k) : '';
                $walk($v, $cur ?: $contextKey, $contextKey);
            }
            return;
        }
        if (!is_string($node)) return;
        if (!preg_match('#^https?://#i', $node)) return;
        $hayKey  = $contextKey . ' ' . $parentKey;
        $isImg   = preg_match('#\.(jpe?g|png|webp|heic)(\?|$)#i', $node);
        $isTru   = (stripos($node, 'truora') !== false);
        $isAws   = (stripos($node, 'x-amz-signature') !== false || stripos($node, 'amazonaws.com') !== false);
        $isImgK  = preg_match('/(url|link|image|picture|document|photo|file)/', $hayKey);
        if (!$isImg && !$isTru && !$isAws && !$isImgK) return;
        $hay = $hayKey . ' ' . strtolower($node);
        $key = null;
        if (preg_match('/(selfie|liveness|face|portrait|user_picture)/', $hay))      $key = 'selfie';
        elseif (preg_match('/(reverse|reverso|back\b|trasera|trasero|verso)/', $hay)) $key = 'ine_reverso';
        elseif (preg_match('/(front|frente|delantera|delantero|obverse|anverso)/', $hay)) $key = 'ine_frente';
        elseif (preg_match('/(document_image|id_card_image|ine_image|identification_image)/', $hay)) $key = 'ine_frente';
        if ($key && empty($imageUrls[$key])) $imageUrls[$key] = $node;
    };
    $walk($result);
    if (empty($imageUrls)) {
        error_log('truoraCaptureProcessPhotos: no image URLs found in /result for ' . $processId);
        return 0;
    }

    // Step 3 — pick a writable uploads dir + persisted-filename prefix.
    $uploadDir = __DIR__ . '/uploads';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
    $prefix = 'truorahook_' . preg_replace('/[^a-zA-Z0-9]/', '', $accountId ?: $processId) . '_' . time();

    // Step 4 — download each URL with the right auth scheme per domain
    // (presigned S3 → no headers; truora.com → API key). Same as the
    // sync-truora.php downloader to keep behavior consistent.
    $apiKey  = defined('TRUORA_API_KEY') ? TRUORA_API_KEY : '';
    $download = function (string $url) use ($apiKey) {
        $isTruoraDomain = (stripos($url, 'truora.com') !== false);
        $isS3Domain     = (stripos($url, 'amazonaws.com') !== false);
        $hdrs = ['Accept: image/*,*/*'];
        if ($isTruoraDomain && !$isS3Domain && $apiKey !== '') {
            $hdrs[] = 'Truora-API-Key: ' . $apiKey;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 18,
            CURLOPT_HTTPHEADER     => $hdrs,
        ]);
        $bin  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct   = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        return [$code, $bin, $ct];
    };

    $saved = [];
    foreach ($imageUrls as $kind => $url) {
        list($code, $bin, $ct) = $download($url);
        if ($code < 200 || $code >= 300 || !$bin || strlen($bin) < 1024) {
            error_log("truoraCaptureProcessPhotos: $kind download HTTP=$code bytes=" . strlen((string)$bin) . " url=$url");
            continue;
        }
        $ext = 'jpg';
        if (stripos($ct, 'png')  !== false) $ext = 'png';
        if (stripos($ct, 'webp') !== false) $ext = 'webp';
        if (preg_match('/\.(jpe?g|png|webp|heic)(\?|$)/i', $url, $m)) $ext = strtolower($m[1]);
        $fname = $prefix . '_' . $kind . '.' . $ext;
        if (@file_put_contents($uploadDir . '/' . $fname, $bin) !== false) {
            $saved[] = $fname;
        }
    }

    if (!empty($saved)) {
        // Merge with anything that was already on the row (defensive against
        // retried webhooks for the same process — never blow away existing
        // good captures).
        try {
            $existing = [];
            $q = $pdo->prepare("SELECT files_saved FROM verificaciones_identidad WHERE id = ?");
            $q->execute([$verifId]);
            $raw = $q->fetchColumn();
            if ($raw) {
                $decoded = json_decode((string)$raw, true);
                if (is_array($decoded)) $existing = $decoded;
            }
            // Drop legacy entries for the same kinds we just refreshed.
            $existing = array_values(array_filter($existing, function ($fn) use ($saved) {
                if (!is_string($fn)) return false;
                $l = strtolower($fn);
                foreach (['_selfie', '_ine_frente', '_ine_reverso'] as $needle) {
                    foreach ($saved as $newFn) {
                        if (strpos(strtolower($newFn), $needle) !== false && strpos($l, $needle) !== false) {
                            return false;
                        }
                    }
                }
                return true;
            }));
            $merged = array_values(array_unique(array_merge($existing, $saved)));
            $pdo->prepare("UPDATE verificaciones_identidad SET files_saved = ? WHERE id = ?")
                ->execute([json_encode($merged, JSON_UNESCAPED_SLASHES), $verifId]);
        } catch (Throwable $e) {
            error_log('truoraCaptureProcessPhotos persist: ' . $e->getMessage());
        }
        error_log('truoraCaptureProcessPhotos: captured ' . count($saved) . ' photos for process ' . $processId);
    }
    return count($saved);
}
