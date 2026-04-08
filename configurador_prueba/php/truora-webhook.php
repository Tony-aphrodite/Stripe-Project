<?php
/**
 * Voltika — Truora webhook receiver
 *
 * Receives asynchronous notifications from Truora when a check, document
 * validation, or face recognition finishes. Updates the corresponding row
 * in `verificaciones_identidad` so admins can see the final verdict without
 * waiting for the in-request polling loop in verificar-identidad.php.
 *
 * Webhook URL to register in the Truora dashboard:
 *   https://<your-domain>/configurador/php/truora-webhook.php
 *
 * Security:
 *   Truora signs each webhook payload with an HMAC-SHA256 using a shared
 *   secret. We verify it against TRUORA_WEBHOOK_SECRET. If the header name
 *   your Truora dashboard uses differs from the one below, update
 *   $signatureHeader accordingly.
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

$logFile = __DIR__ . '/logs/truora-webhook.log';
@file_put_contents($logFile, str_repeat('─', 60) . "\n", FILE_APPEND | LOCK_EX);

// ── Read raw body ────────────────────────────────────────────────────────────
$rawBody = file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];

// Log every incoming call so we can debug payload shape
@file_put_contents($logFile, json_encode([
    'timestamp' => date('c'),
    'method'    => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
    'headers'   => $headers,
    'body'      => substr($rawBody, 0, 2000),
]) . "\n", FILE_APPEND | LOCK_EX);

// Only POST is valid for webhooks
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// ── Verify signature ─────────────────────────────────────────────────────────
// Truora sends the signature in a header (confirm exact name in dashboard).
$signatureHeader = null;
foreach ($headers as $k => $v) {
    $lk = strtolower($k);
    if (in_array($lk, ['truora-signature', 'x-truora-signature', 'signature'], true)) {
        $signatureHeader = $v;
        break;
    }
}

if (TRUORA_WEBHOOK_SECRET !== '') {
    if (!$signatureHeader) {
        http_response_code(401);
        @file_put_contents($logFile, "[" . date('c') . "] Missing signature header\n", FILE_APPEND | LOCK_EX);
        echo json_encode(['ok' => false, 'error' => 'Missing signature']);
        exit;
    }
    $expected = hash_hmac('sha256', $rawBody, TRUORA_WEBHOOK_SECRET);
    if (!hash_equals($expected, trim((string)$signatureHeader))) {
        http_response_code(401);
        @file_put_contents($logFile, "[" . date('c') . "] Invalid signature\n", FILE_APPEND | LOCK_EX);
        echo json_encode(['ok' => false, 'error' => 'Invalid signature']);
        exit;
    }
}
// If TRUORA_WEBHOOK_SECRET is empty we accept the payload but log a warning
// so the integration can be tested before the secret is configured.

// ── Parse payload ────────────────────────────────────────────────────────────
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

// Truora payload shape varies by event. Try the common fields:
//   check_id, validation_id, document_validation_id, face_recognition_id
$checkId = $payload['check_id']
        ?? $payload['validation_id']
        ?? $payload['document_validation_id']
        ?? $payload['face_recognition_id']
        ?? $payload['data']['check_id']
        ?? null;

$eventType = $payload['event_type']
          ?? $payload['type']
          ?? $payload['event']
          ?? 'unknown';

$status = $payload['status']
       ?? $payload['data']['status']
       ?? null;

if (!$checkId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No check_id in payload']);
    exit;
}

// ── Update the matching row ──────────────────────────────────────────────────
try {
    $pdo = getDB();

    // Look up by any of the 3 possible ID columns
    $stmt = $pdo->prepare("
        SELECT id FROM verificaciones_identidad
        WHERE truora_check_id = :cid
           OR face_check_id   = :cid
           OR doc_check_id    = :cid
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([':cid' => $checkId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $update = $pdo->prepare("
            UPDATE verificaciones_identidad
            SET webhook_payload     = :payload,
                webhook_received_at = NOW(),
                identity_status     = COALESCE(:status, identity_status)
            WHERE id = :id
        ");
        $update->execute([
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ':status'  => $status,
            ':id'      => $row['id'],
        ]);
        @file_put_contents($logFile, "[" . date('c') . "] Updated verif #{$row['id']} event=$eventType status=$status\n", FILE_APPEND | LOCK_EX);
    } else {
        @file_put_contents($logFile, "[" . date('c') . "] No matching row for check_id=$checkId\n", FILE_APPEND | LOCK_EX);
    }
} catch (PDOException $e) {
    error_log('Voltika truora-webhook DB error: ' . $e->getMessage());
    @file_put_contents($logFile, "[" . date('c') . "] DB error: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error']);
    exit;
}

http_response_code(200);
echo json_encode(['ok' => true]);
