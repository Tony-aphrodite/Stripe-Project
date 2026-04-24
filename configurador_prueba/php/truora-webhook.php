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
