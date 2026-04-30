<?php
/**
 * Voltika — production data wipe utility.
 *
 * Customer brief 2026-04-30: "Please update the inventory and wipe the
 * information, except cdc and Puntos."
 *
 * This script wipes EVERY table in the current DB except those that match
 * the keep-list (CDC + Puntos). It is plan-first by default: running it
 * without explicit confirm flags only PRINTS what would happen. Actual
 * truncation only runs with both `confirm=YES_WIPE_NOW` and
 * `i_have_backup=YES`.
 *
 * ⚠️ TRUNCATE IS NOT TRANSACTIONAL ON MYSQL. If this script fails partway
 * through, half the tables will be wiped and half won't. There is no undo.
 * The only safety net is the backup taken via php/backup-databases.php
 * BEFORE running this. Hence the `i_have_backup=YES` requirement.
 *
 * URL examples:
 *   ?action=plan&token=TOKEN
 *       → JSON plan: each table marked KEEP / WIPE with row counts.
 *
 *   ?action=execute&token=TOKEN&confirm=YES_WIPE_NOW&i_have_backup=YES
 *       → actually performs the wipe. Returns per-table result log.
 *
 *   ?action=plan&token=TOKEN&keep=table_a,table_b&wipe=table_x
 *       → override defaults: explicit keep / wipe lists merge with the
 *         pattern-based defaults.
 *
 * KEEP rules (default):
 *   - exact match: consultas_buro, cdc_query_log, cdc_certificates,
 *                  puntos_voltika, dealer_usuarios
 *   - regex match: ^cdc_, ^punto, ^puntos_
 *   - inventario_motos KEPT by default — customer wants to UPDATE it,
 *     not wipe. A separate inventory-replace action will handle the
 *     replacement once the new Excel is provided.
 *
 * Audit:
 *   - Every execute writes to `wipe_audit_log` (auto-created on first run).
 *   - Includes: timestamp, IP, user-agent, full plan, full result, who
 *     authorised (= the matched token name).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

ini_set('max_execution_time', '0');
ini_set('memory_limit', '256M');

header('Content-Type: application/json');

// ── Token gate ─────────────────────────────────────────────────────────────
$expectedToken = getenv('WIPE_TOKEN') ?: 'voltika_wipe_2026';
$providedToken = $_GET['token'] ?? '';
if (!hash_equals($expectedToken, (string)$providedToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid_token']);
    exit;
}

// ── Connect ────────────────────────────────────────────────────────────────
try {
    $pdo = getDB();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db_connect_failed', 'message' => $e->getMessage()]);
    exit;
}

$action = (string)($_GET['action'] ?? 'plan');

// ── Build the plan: classify every table as KEEP or WIPE ──────────────────
$defaultKeepExact = [
    'consultas_buro',
    'cdc_query_log',
    'cdc_certificates',
    'puntos_voltika',
    'dealer_usuarios',     // real staff accounts attached to puntos
    'inventario_motos',    // updated separately via inventory action
    'wipe_audit_log',      // never wipe our own audit trail
];
$defaultKeepPatterns = [
    '/^cdc_/i',
    '/^punto[s]?_/i',
];

// Optional CLI-style overrides via query string
$cliKeep = array_filter(array_map('trim', explode(',', (string)($_GET['keep'] ?? ''))));
$cliWipe = array_filter(array_map('trim', explode(',', (string)($_GET['wipe'] ?? ''))));

// Get all base tables in the current DB
$allTables = [];
$rs = $pdo->query("SHOW FULL TABLES");
foreach ($rs as $row) {
    $name = array_values($row)[0];
    $type = array_values($row)[1] ?? 'BASE TABLE';
    if ($type === 'BASE TABLE') $allTables[] = $name;
}
sort($allTables);

$plan = [];
$totalRowsToWipe = 0;
$totalRowsToKeep = 0;
foreach ($allTables as $t) {
    $reason = null;
    $keep = false;
    if (in_array($t, $cliKeep, true)) {
        $keep = true; $reason = 'cli_keep';
    } elseif (in_array($t, $cliWipe, true)) {
        $keep = false; $reason = 'cli_wipe';
    } elseif (in_array($t, $defaultKeepExact, true)) {
        $keep = true; $reason = 'default_keep_exact';
    } else {
        foreach ($defaultKeepPatterns as $pat) {
            if (preg_match($pat, $t)) { $keep = true; $reason = 'default_keep_pattern:' . $pat; break; }
        }
    }

    // Get row count (best-effort — may be slow on huge tables but our DBs
    // are small enough that COUNT(*) on each is acceptable).
    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM `" . str_replace('`', '``', $t) . "`")->fetchColumn();
    } catch (Throwable $e) {
        $count = -1;
    }

    if ($keep) $totalRowsToKeep += max(0, $count);
    else       $totalRowsToWipe += max(0, $count);

    $plan[] = [
        'table'  => $t,
        'action' => $keep ? 'KEEP' : 'WIPE',
        'rows'   => $count,
        'reason' => $reason ?: 'default_wipe',
    ];
}

// ─────────────────────────────────────────────────────────────────────────
// ACTION: plan (dry-run, the default)
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'plan') {
    $keepCount = count(array_filter($plan, fn($r) => $r['action'] === 'KEEP'));
    $wipeCount = count($plan) - $keepCount;

    echo json_encode([
        'ok'                => true,
        'mode'              => 'PLAN (dry-run, no changes made)',
        'database'          => DB_NAME,
        'total_tables'      => count($plan),
        'tables_to_keep'    => $keepCount,
        'tables_to_wipe'    => $wipeCount,
        'rows_to_keep'      => $totalRowsToKeep,
        'rows_to_wipe'      => $totalRowsToWipe,
        'plan'              => $plan,
        'execute_url'       => '?action=execute&token=' . urlencode($expectedToken)
                             . '&confirm=YES_WIPE_NOW&i_have_backup=YES',
        'warnings'          => [
            '⚠️ TRUNCATE is NOT transactional on MySQL — partial failure leaves DB half-wiped.',
            '⚠️ The ONLY safety net is your backup. Verify it works before executing.',
            '⚠️ Tables marked KEEP will be untouched. Verify the list before executing.',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// ACTION: execute
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'execute') {
    $confirm    = (string)($_GET['confirm']        ?? '');
    $hasBackup  = (string)($_GET['i_have_backup']  ?? '');

    if ($confirm !== 'YES_WIPE_NOW') {
        http_response_code(400);
        echo json_encode([
            'error'   => 'missing_confirm',
            'message' => 'Pass &confirm=YES_WIPE_NOW to proceed.',
        ]);
        exit;
    }
    if ($hasBackup !== 'YES') {
        http_response_code(400);
        echo json_encode([
            'error'   => 'missing_backup_acknowledgement',
            'message' => 'Pass &i_have_backup=YES to confirm you have a working DB backup. THIS WIPE CANNOT BE UNDONE.',
            'hint'    => 'Run php/backup-databases.php first and verify the .sql.gz file is valid.',
        ]);
        exit;
    }

    // Auto-create audit log table BEFORE wiping anything.
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wipe_audit_log (
            id           BIGINT AUTO_INCREMENT PRIMARY KEY,
            run_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
            run_by_ip    VARCHAR(45) NULL,
            user_agent   VARCHAR(500) NULL,
            tables_total INT NULL,
            tables_kept  INT NULL,
            tables_wiped INT NULL,
            rows_wiped   BIGINT NULL,
            plan         MEDIUMTEXT NULL,
            result       MEDIUMTEXT NULL,
            INDEX idx_run_at (run_at)
        )");
    } catch (Throwable $e) {
        // Non-fatal — the wipe can still proceed without an audit row,
        // but log to error_log so operators see it.
        error_log('wipe-database: audit table create failed: ' . $e->getMessage());
    }

    // ── DO IT ─────────────────────────────────────────────────────────────
    $result = [];
    $rowsActuallyWiped = 0;
    $startedAt = microtime(true);

    try { $pdo->exec("SET FOREIGN_KEY_CHECKS=0"); } catch (Throwable $e) {}

    foreach ($plan as $row) {
        if ($row['action'] !== 'WIPE') continue;
        $t = $row['table'];
        $tEsc = '`' . str_replace('`', '``', $t) . '`';
        $start = microtime(true);
        $rec = ['table' => $t, 'rows_before' => $row['rows']];
        try {
            // Use TRUNCATE for speed + auto_increment reset. If the table
            // is the target of a foreign-key constraint, TRUNCATE may fail
            // even with FK_CHECKS=0 on some MySQL versions — fall back to
            // a plain DELETE in that case.
            try {
                $pdo->exec("TRUNCATE TABLE $tEsc");
                $rec['method'] = 'truncate';
            } catch (Throwable $eTrunc) {
                $deleted = $pdo->exec("DELETE FROM $tEsc");
                $rec['method'] = 'delete';
                $rec['delete_returned'] = (int)$deleted;
                // Reset auto-increment manually
                try { $pdo->exec("ALTER TABLE $tEsc AUTO_INCREMENT = 1"); } catch (Throwable $e) {}
            }
            $rec['ok']         = true;
            $rec['duration_s'] = round(microtime(true) - $start, 3);
            if ($row['rows'] > 0) $rowsActuallyWiped += $row['rows'];
        } catch (Throwable $e) {
            $rec['ok']         = false;
            $rec['error']      = $e->getMessage();
            $rec['duration_s'] = round(microtime(true) - $start, 3);
        }
        $result[] = $rec;
    }

    try { $pdo->exec("SET FOREIGN_KEY_CHECKS=1"); } catch (Throwable $e) {}

    $totalDuration = round(microtime(true) - $startedAt, 2);

    // Persist audit record
    try {
        $stmt = $pdo->prepare("INSERT INTO wipe_audit_log
                (run_by_ip, user_agent, tables_total, tables_kept, tables_wiped, rows_wiped, plan, result)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SERVER['REMOTE_ADDR']     ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            count($plan),
            count(array_filter($plan, fn($r) => $r['action'] === 'KEEP')),
            count($result),
            $rowsActuallyWiped,
            json_encode($plan,   JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        error_log('wipe-database: audit insert failed: ' . $e->getMessage());
    }

    echo json_encode([
        'ok'                  => true,
        'mode'                => 'EXECUTED',
        'database'            => DB_NAME,
        'total_duration_s'    => $totalDuration,
        'tables_processed'    => count($result),
        'rows_wiped_estimate' => $rowsActuallyWiped,
        'result'              => $result,
        'next_steps' => [
            'Run ?action=plan again to verify all WIPE tables now have rows=0.',
            'For inventory replacement, share the Excel file → I will build a loader script.',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// Default
// ─────────────────────────────────────────────────────────────────────────
http_response_code(400);
echo json_encode([
    'error' => 'unknown_action',
    'valid' => ['plan', 'execute'],
    'usage' => [
        'plan (default — dry-run, prints what would happen):',
        '  ?action=plan&token=TOKEN',
        '',
        'execute (actually performs the wipe — REQUIRES backup):',
        '  ?action=execute&token=TOKEN&confirm=YES_WIPE_NOW&i_have_backup=YES',
        '',
        'override defaults:',
        '  &keep=table_a,table_b   (force-keep, in addition to defaults)',
        '  &wipe=table_x,table_y   (force-wipe, in addition to defaults)',
    ],
]);
exit;
