<?php
/**
 * Voltika — Truora process status polling endpoint.
 *
 * Called by paso-credito-identidad.js after it receives a
 * `truora.steps.completed` message (meaning async validation is still
 * running in Truora). We check our `verificaciones_identidad` row — which
 * the webhook updates — so the frontend can settle the flow without
 * hitting Truora's API directly.
 *
 * GET /truora-status.php?process_id=<IDP...>
 * Returns: { ok, process_id, approved, status, declined_reason, updated_at }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config.php';

// Accept process_id (preferred — used by iframe flow) OR account_id/email
// (popup fallback flow where the JS doesn't have process_id yet because
// it's embedded in the JWT that opened in another tab). The webhook
// stores both, so either lookup works.
$processId = trim((string)($_GET['process_id'] ?? ''));
$accountId = trim((string)($_GET['account_id'] ?? ''));
$email     = trim((string)($_GET['email']      ?? ''));

if ($processId === '' && $accountId === '' && $email === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'process_id, account_id o email requerido']);
    exit;
}

try {
    $pdo = getDB();
    // Lazy schema: ensure account_id column exists for popup-flow lookups.
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM verificaciones_identidad LIKE 'truora_account_id'")->fetch();
        if (!$cols) $pdo->exec("ALTER TABLE verificaciones_identidad ADD COLUMN truora_account_id VARCHAR(120) NULL");
    } catch (Throwable $e) {}

    if ($processId !== '') {
        $stmt = $pdo->prepare("SELECT approved, truora_status, truora_failure_status,
                truora_declined_reason, truora_updated_at, truora_last_event,
                truora_process_id, curp_match, expected_curp, verified_curp
            FROM verificaciones_identidad
            WHERE truora_process_id = ?
            ORDER BY id DESC LIMIT 1");
        $stmt->execute([$processId]);
    } elseif ($accountId !== '') {
        $stmt = $pdo->prepare("SELECT approved, truora_status, truora_failure_status,
                truora_declined_reason, truora_updated_at, truora_last_event,
                truora_process_id, curp_match, expected_curp, verified_curp
            FROM verificaciones_identidad
            WHERE truora_account_id = ?
            ORDER BY id DESC LIMIT 1");
        $stmt->execute([$accountId]);
    } else {
        $stmt = $pdo->prepare("SELECT approved, truora_status, truora_failure_status,
                truora_declined_reason, truora_updated_at, truora_last_event,
                truora_process_id, curp_match, expected_curp, verified_curp
            FROM verificaciones_identidad
            WHERE email = ?
            ORDER BY id DESC LIMIT 1");
        $stmt->execute([$email]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode([
            'ok'         => true,
            'process_id' => $processId,
            'approved'   => null,
            'status'     => 'pending',
            'hint'       => 'Esperando webhook de Truora',
        ]);
        exit;
    }
    echo json_encode([
        'ok'              => true,
        'process_id'      => $row['truora_process_id'] ?: $processId,
        'approved'        => is_null($row['approved']) ? null : (int)$row['approved'],
        'status'          => $row['truora_status'] ?: 'pending',
        'failure_status'  => $row['truora_failure_status'],
        'declined_reason' => $row['truora_declined_reason'],
        'last_event'      => $row['truora_last_event'],
        'updated_at'      => $row['truora_updated_at'],
        // Identity-vs-bureau cross-check (anti-fraud guard added 2026-04-29)
        'curp_match'      => is_null($row['curp_match']) ? null : (int)$row['curp_match'],
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'db_error', 'detail' => $e->getMessage()]);
}
