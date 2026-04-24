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

$processId = trim((string)($_GET['process_id'] ?? ''));
if ($processId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'process_id requerido']);
    exit;
}

try {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT approved, truora_status, truora_failure_status,
            truora_declined_reason, truora_updated_at, truora_last_event
        FROM verificaciones_identidad
        WHERE truora_process_id = ?
        ORDER BY id DESC LIMIT 1");
    $stmt->execute([$processId]);
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
        'process_id'      => $processId,
        'approved'        => is_null($row['approved']) ? null : (int)$row['approved'],
        'status'          => $row['truora_status'] ?: 'pending',
        'failure_status'  => $row['truora_failure_status'],
        'declined_reason' => $row['truora_declined_reason'],
        'last_event'      => $row['truora_last_event'],
        'updated_at'      => $row['truora_updated_at'],
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'db_error', 'detail' => $e->getMessage()]);
}
