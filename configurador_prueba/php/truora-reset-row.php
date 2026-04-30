<?php
/**
 * Voltika — One-shot admin tool: reset polluted verificaciones_identidad
 * row(s) so the next status.php poll re-runs the API fallback with the
 * (now-strict) account_id matching helper.
 *
 * Background: an earlier draft of truoraFindProcessByAccountId() did not
 * filter strictly by account_id, so it occasionally wrote a different
 * customer's process_id / verdict onto the polluted row. After the
 * helper was fixed (2026-04-30), this script clears the wrong data on
 * specific rows so the next poll fetches the correct process for the
 * row's actual account_id.
 *
 * Auth: ?token=voltika_diag_2026 (or admin session).
 *
 * Usage:
 *   ?account_id=voltika_c_xxx_yyy        — reset row(s) by account_id
 *   ?row_id=123                          — reset row by primary key id
 *   ?account_id=...&dry_run=1            — preview only, no UPDATE
 *
 * The reset clears: truora_process_id, truora_status, approved (0),
 * curp_match, verified_curp, name_match, verified_name, declined_reason,
 * failure_status, manual_review_required. expected_curp / expected_name
 * are PRESERVED (those came from the user's CDC input).
 */
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_name('VOLTIKA_ADMIN');
    @session_start();
}
$expectedToken = getenv('VOLTIKA_DIAG_TOKEN') ?: (defined('VOLTIKA_DIAG_TOKEN') ? VOLTIKA_DIAG_TOKEN : 'voltika_diag_2026');
$adminOk = !empty($_SESSION['admin_user_id']);
$tokenOk = isset($_GET['token']) && hash_equals($expectedToken, $_GET['token']);
if (!$adminOk && !$tokenOk) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "Forbidden — use ?token=$expectedToken\n";
    exit;
}

header('Content-Type: application/json');

$accountId = trim((string)($_GET['account_id'] ?? ''));
$rowId     = (int)($_GET['row_id'] ?? 0);
$dryRun    = !empty($_GET['dry_run']);

if ($accountId === '' && $rowId <= 0) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Specify ?account_id=… or ?row_id=…',
    ], JSON_PRETTY_PRINT);
    exit;
}

try {
    $pdo = getDB();

    // Look up matching rows first.
    if ($rowId > 0) {
        $sel = $pdo->prepare("SELECT id, freg, telefono, truora_account_id,
                truora_process_id, approved, name_match, curp_match
            FROM verificaciones_identidad WHERE id = ?");
        $sel->execute([$rowId]);
    } else {
        $sel = $pdo->prepare("SELECT id, freg, telefono, truora_account_id,
                truora_process_id, approved, name_match, curp_match
            FROM verificaciones_identidad WHERE truora_account_id = ?
            ORDER BY id DESC");
        $sel->execute([$accountId]);
    }
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo json_encode([
            'ok' => true,
            'matched' => 0,
            'message' => 'No rows matched the criterion.',
        ], JSON_PRETTY_PRINT);
        exit;
    }

    // Show what we'd reset.
    $beforeSnapshot = $rows;

    if ($dryRun) {
        echo json_encode([
            'ok' => true,
            'dry_run' => true,
            'matched' => count($rows),
            'rows_before' => $beforeSnapshot,
            'message' => 'Dry-run only — no UPDATE executed. Remove ?dry_run=1 to apply.',
        ], JSON_PRETTY_PRINT);
        exit;
    }

    // Build IN clause for the UPDATE.
    $ids = array_map('intval', array_column($rows, 'id'));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $upd = $pdo->prepare("UPDATE verificaciones_identidad SET
            truora_process_id      = NULL,
            truora_status          = NULL,
            truora_failure_status  = NULL,
            truora_declined_reason = NULL,
            truora_last_event      = NULL,
            truora_updated_at      = NULL,
            verified_curp          = NULL,
            curp_match             = NULL,
            verified_name          = NULL,
            name_match             = NULL,
            manual_review_required = NULL,
            manual_review_reason   = NULL,
            approved               = 0,
            identity_status        = 'pending'
        WHERE id IN ($placeholders)");
    $upd->execute($ids);
    $affected = $upd->rowCount();

    // Re-read for confirmation.
    $sel2 = $pdo->prepare("SELECT id, freg, telefono, truora_account_id,
            truora_process_id, approved, name_match, curp_match,
            identity_status
        FROM verificaciones_identidad WHERE id IN ($placeholders)");
    $sel2->execute($ids);
    $afterRows = $sel2->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'matched' => count($rows),
        'affected' => $affected,
        'rows_before' => $beforeSnapshot,
        'rows_after'  => $afterRows,
        'next_step' => 'Now hit truora-status.php with the same account_id — '
                     . 'API fallback will re-run with the strict-match helper.',
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'db_error',
        'detail' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}
