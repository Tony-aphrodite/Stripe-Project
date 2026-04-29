<?php
/**
 * Voltika — Truora process status polling endpoint.
 *
 * Called by paso-credito-identidad.js while showing the
 * "Validando concordancia…" screen. Returns the latest known state of
 * the verification so the frontend can settle the flow.
 *
 * Sources of truth (in priority order):
 *   1. truora-webhook.php has already stored the final result. Done.
 *   2. The webhook hasn't arrived but we have a process_id → poll Truora's
 *      REST API directly to self-heal. Customer report 2026-04-29:
 *      webhook config in Truora's dashboard kept silently dropping events,
 *      leaving customers stuck forever. With the API fallback the SPA
 *      advances even when the webhook never fires.
 *
 * GET /truora-status.php?process_id=<IDP...>
 * Returns: { ok, process_id, approved, status, curp_match, ... }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/truora-api-helpers.php';

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
    // Lazy schema: ensure all columns we read exist on legacy installs.
    foreach ([
        "ALTER TABLE verificaciones_identidad ADD COLUMN truora_account_id VARCHAR(120) NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN truora_process_id VARCHAR(64) NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN truora_status VARCHAR(40) NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN truora_failure_status VARCHAR(40) NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN truora_declined_reason VARCHAR(160) NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN truora_last_event VARCHAR(80) NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN truora_updated_at DATETIME NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN expected_curp VARCHAR(20) NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN verified_curp VARCHAR(20) NULL",
        "ALTER TABLE verificaciones_identidad ADD COLUMN curp_match TINYINT(1) NULL",
    ] as $ddl) { try { $pdo->exec($ddl); } catch (Throwable $e) {} }

    $where = '';
    $param = '';
    if ($processId !== '')      { $where = 'truora_process_id = ?';  $param = $processId; }
    elseif ($accountId !== '')  { $where = 'truora_account_id = ?';  $param = $accountId; }
    else                         { $where = 'email = ?';              $param = $email;     }

    $sql = "SELECT id, freg, approved, truora_status, truora_failure_status,
            truora_declined_reason, truora_updated_at, truora_last_event,
            truora_process_id, truora_account_id,
            curp_match, expected_curp, verified_curp
        FROM verificaciones_identidad
        WHERE $where
        ORDER BY id DESC LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$param]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // ── Fallback: webhook never fired → fetch state from Truora API ──────
    // Triggers when we have a process_id (or account_id leading to one) but
    // approved is still null. Polls Truora's REST API and mirrors the
    // result into the DB so subsequent polls return the cached value.
    $usedApiFallback = false;
    $apiResolvedProcessId = $processId;
    if ($apiResolvedProcessId === '' && $row && !empty($row['truora_process_id'])) {
        $apiResolvedProcessId = (string)$row['truora_process_id'];
    }
    if ($apiResolvedProcessId !== '' && (!$row || is_null($row['approved']))) {
        $details = truoraFetchProcessDetails($apiResolvedProcessId);
        if (is_array($details)) {
            $usedApiFallback = true;
            $tStatus = truoraExtractStatus($details);
            $verifiedCurp = truoraExtractCurp($details);
            $newApproved = null;
            if ($tStatus === 'valid' || $tStatus === 'success' || $tStatus === 'succeeded' || $tStatus === 'approved') {
                $newApproved = 1;
            } elseif ($tStatus === 'invalid' || $tStatus === 'failed' || $tStatus === 'rejected') {
                $newApproved = 0;
            }

            // Compare against expected CURP if we have one.
            $expectedCurp = $row['expected_curp'] ?? null;
            $newCurpMatch = null;
            $newDeclined  = null;
            $newFailStatus = null;
            if ($newApproved === 1) {
                if ($expectedCurp && $verifiedCurp) {
                    $newCurpMatch = (strtoupper(trim($expectedCurp)) === strtoupper(trim($verifiedCurp))) ? 1 : 0;
                    if (!$newCurpMatch) {
                        $newApproved = 0;
                        $newDeclined = 'identity_curp_mismatch';
                        $newFailStatus = 'curp_mismatch';
                    }
                } elseif ($expectedCurp && !$verifiedCurp) {
                    $newApproved = 0;
                    $newDeclined = 'verified_curp_unavailable';
                    $newFailStatus = 'identity_unverifiable';
                }
            }

            // Audit log of the comparison decision.
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
                    ->execute([$apiResolvedProcessId, $expectedCurp, $verifiedCurp, 'api_fallback', $decision]);
            } catch (Throwable $e) {}

            // Update row (or insert anchor if it didn't exist yet).
            if ($row) {
                try {
                    $pdo->prepare("UPDATE verificaciones_identidad SET
                            approved = COALESCE(?, approved),
                            truora_process_id = COALESCE(truora_process_id, ?),
                            truora_status = COALESCE(?, truora_status),
                            truora_failure_status = COALESCE(?, truora_failure_status),
                            truora_declined_reason = COALESCE(?, truora_declined_reason),
                            truora_updated_at = NOW(),
                            verified_curp = COALESCE(?, verified_curp),
                            curp_match = COALESCE(?, curp_match),
                            identity_status = CASE WHEN ? IS NOT NULL THEN (CASE WHEN ?=1 THEN 'valid' ELSE 'declined' END) ELSE identity_status END
                        WHERE id = ?")
                        ->execute([
                            $newApproved,
                            $apiResolvedProcessId,
                            $tStatus,
                            $newFailStatus,
                            $newDeclined,
                            $verifiedCurp,
                            $newCurpMatch,
                            $newApproved, $newApproved,
                            (int)$row['id'],
                        ]);
                } catch (Throwable $e) { error_log('status.php update fallback: ' . $e->getMessage()); }

                // Re-read row.
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$param]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }
    }

    if (!$row) {
        echo json_encode([
            'ok'         => true,
            'process_id' => $processId,
            'approved'   => null,
            'status'     => 'pending',
            'hint'       => 'Esperando webhook de Truora o respuesta de API',
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
        'curp_match'      => is_null($row['curp_match']) ? null : (int)$row['curp_match'],
        'source'          => $usedApiFallback ? 'api_fallback' : 'webhook',
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'db_error', 'detail' => $e->getMessage()]);
}
