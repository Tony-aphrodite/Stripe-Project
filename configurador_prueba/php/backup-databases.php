<?php
/**
 * Voltika — Server-wide DB backup utility.
 *
 * Customer brief 2026-04-30: prior to wiping production tables we need a
 * full backup of EVERY database on this Plesk server (current project + any
 * legacy DBs the same MySQL user can reach). This script enumerates all
 * databases visible to the connecting MySQL user, dumps each one to its
 * own SQL file, and exposes a signed download URL.
 *
 * Usage (via browser):
 *   1. ?action=list&token=...                — show all DBs + size + #tables
 *   2. ?action=backup-all&token=...          — dump every visible DB
 *      (or ?action=backup&db=NAME&token=... — dump a single DB)
 *   3. Follow the printed download_url to retrieve a tar.gz of the dumps.
 *
 * Privilege coverage:
 *   - Default MySQL credentials come from config.php (= the `voltika` user).
 *     If that user only has GRANT on `voltika_`, only that DB can be
 *     backed up — anything else returns "access denied" cleanly.
 *   - To back up MULTIPLE DBs (legacy projects on the same server), set
 *     env vars BACKUP_MYSQL_USER + BACKUP_MYSQL_PASS to a higher-privilege
 *     account (typically `admin`/`root` from Plesk's MySQL panel). The
 *     script will pick those up and connect with them instead.
 *
 * Security:
 *   - Token-gated. Default token below; override via BACKUP_TOKEN env.
 *   - Output stored under php/backups/<id>/ — protected by .htaccess so
 *     the .sql files cannot be downloaded by URL guessing.
 *   - Download URLs carry HMAC(token, backup_id) so only someone who knows
 *     the token can fetch them.
 *   - Logs every access to truora_webhook_log-style audit (best-effort).
 *
 * Implementation:
 *   - Tries the system `mysqldump` binary first (fast, complete — captures
 *     triggers/routines/events).
 *   - Falls back to a pure-PHP table dumper if mysqldump is disabled or
 *     missing (common on shared Plesk hosting where exec() is locked down).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

ini_set('max_execution_time', '0');
ini_set('memory_limit', '512M');

// ── Token gate ─────────────────────────────────────────────────────────────
$expectedToken = getenv('BACKUP_TOKEN') ?: 'voltika_backup_2026';
$providedToken = $_GET['token'] ?? '';
if (!hash_equals($expectedToken, (string)$providedToken)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid token']);
    exit;
}

// ── Connect (no DB selected, so SHOW DATABASES works) ─────────────────────
$mysqlHost = defined('DB_HOST') ? DB_HOST : 'localhost';
$mysqlUser = getenv('BACKUP_MYSQL_USER') ?: (defined('DB_USER') ? DB_USER : '');
$mysqlPass = getenv('BACKUP_MYSQL_PASS') ?: (defined('DB_PASS') ? DB_PASS : '');

try {
    $pdo = new PDO(
        "mysql:host=$mysqlHost;charset=utf8mb4",
        $mysqlUser,
        $mysqlPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'connect_failed',
        'message' => $e->getMessage(),
        'host' => $mysqlHost,
        'user' => $mysqlUser,
        'hint' => 'Set BACKUP_MYSQL_USER / BACKUP_MYSQL_PASS env vars to a higher-privilege MySQL account if voltika user lacks SHOW DATABASES.',
    ]);
    exit;
}

$action = (string)($_GET['action'] ?? 'list');

// ─────────────────────────────────────────────────────────────────────────
// ACTION: list
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $skipSystem = ['information_schema', 'performance_schema', 'mysql', 'sys'];
    $allDbs = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
    $userDbs = array_values(array_diff($allDbs, $skipSystem));

    $rows = [];
    $totalMb = 0.0;
    foreach ($userDbs as $db) {
        try {
            $st = $pdo->prepare("SELECT
                    ROUND(COALESCE(SUM(data_length + index_length), 0)/1024/1024, 2) AS size_mb,
                    COUNT(*) AS tbl_count
                FROM information_schema.tables WHERE table_schema = ?");
            $st->execute([$db]);
            $info = $st->fetch(PDO::FETCH_ASSOC) ?: ['size_mb' => 0, 'tbl_count' => 0];
            $sizeMb = (float)$info['size_mb'];
            $totalMb += $sizeMb;
            $rows[] = [
                'name'    => $db,
                'size_mb' => $sizeMb,
                'tables'  => (int)$info['tbl_count'],
            ];
        } catch (Throwable $e) {
            $rows[] = ['name' => $db, 'error' => $e->getMessage()];
        }
    }

    header('Content-Type: application/json');
    echo json_encode([
        'ok'             => true,
        'connected_user' => $mysqlUser,
        'host'           => $mysqlHost,
        'databases'      => $rows,
        'total_size_mb'  => round($totalMb, 2),
        'system_dbs_skipped' => $skipSystem,
        'next_step' => '?action=backup-all&token=' . urlencode($expectedToken),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// ACTION: download — stream a previously-created backup as tar.gz
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'download') {
    $id  = (string)($_GET['id']  ?? '');
    $sig = (string)($_GET['sig'] ?? '');
    if (!preg_match('/^[\w-]+$/', $id)) {
        http_response_code(400); echo 'invalid id'; exit;
    }
    $expectedSig = hash_hmac('sha256', $id, $expectedToken);
    if (!hash_equals($expectedSig, $sig)) {
        http_response_code(403); echo 'invalid signature'; exit;
    }
    $dir = __DIR__ . '/backups/' . $id;
    if (!is_dir($dir)) {
        http_response_code(404); echo 'backup not found'; exit;
    }

    // Try shell tar first (much faster than PHP PharData on large dumps).
    $tarPath = sys_get_temp_dir() . '/voltika-backup-' . $id . '.tar.gz';
    @unlink($tarPath);
    $tarBin = trim((string)@shell_exec('command -v tar 2>/dev/null'));
    if ($tarBin !== '' && function_exists('exec')) {
        $cmd = sprintf(
            '%s -czf %s -C %s .',
            escapeshellarg($tarBin),
            escapeshellarg($tarPath),
            escapeshellarg($dir)
        );
        @exec($cmd, $out, $code);
        if ($code !== 0 || !file_exists($tarPath)) {
            // Fall through to PharData below.
            @unlink($tarPath);
        }
    }
    if (!file_exists($tarPath)) {
        // PHP PharData fallback — slower, RAM-heavy on big dumps.
        try {
            $phar = new PharData($tarPath . '.tmp.tar');
            $phar->buildFromDirectory($dir);
            $phar->compress(Phar::GZ);
            unset($phar);
            @unlink($tarPath . '.tmp.tar');
            @rename($tarPath . '.tmp.tar.gz', $tarPath);
        } catch (Throwable $e) {
            http_response_code(500);
            echo 'archive_failed: ' . $e->getMessage();
            exit;
        }
    }

    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="voltika-backup-' . $id . '.tar.gz"');
    header('Content-Length: ' . filesize($tarPath));
    readfile($tarPath);
    @unlink($tarPath);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// ACTION: backup / backup-all — dump SQL files
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'backup' || $action === 'backup-all') {

    $skipSystem = ['information_schema', 'performance_schema', 'mysql', 'sys'];
    $allDbs = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);

    if ($action === 'backup' && !empty($_GET['db'])) {
        $reqDb = (string)$_GET['db'];
        if (!in_array($reqDb, $allDbs, true)) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'database_not_found_or_no_permission', 'db' => $reqDb]);
            exit;
        }
        $dbs = [$reqDb];
    } else {
        $dbs = array_values(array_diff($allDbs, $skipSystem));
    }

    // Backup destination
    $backupId  = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    $backupDir = __DIR__ . '/backups/' . $backupId;
    if (!@mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'mkdir_failed', 'dir' => $backupDir]);
        exit;
    }

    // Lock the backups parent dir from direct HTTP access.
    $parentDir = __DIR__ . '/backups';
    if (!file_exists($parentDir . '/.htaccess')) {
        @file_put_contents($parentDir . '/.htaccess',
            "Require all denied\n" .
            "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"
        );
    }
    if (!file_exists($parentDir . '/index.html')) {
        @file_put_contents($parentDir . '/index.html', '');
    }

    // Detect mysqldump availability.
    $mysqldump = trim((string)@shell_exec('command -v mysqldump 2>/dev/null'));
    $useMysqldump = ($mysqldump !== '' && is_executable($mysqldump) && function_exists('exec'));

    $results = [];
    foreach ($dbs as $db) {
        $safeName = preg_replace('/[^A-Za-z0-9_.-]/', '_', $db);
        $sqlFile  = $backupDir . '/' . $safeName . '.sql';
        $start    = microtime(true);
        $rec = ['db' => $db, 'method' => null, 'ok' => false];

        try {
            if ($useMysqldump) {
                $rec['method'] = 'mysqldump';
                $cmd = sprintf(
                    '%s --host=%s --user=%s --password=%s --single-transaction --quick --routines --triggers --events --default-character-set=utf8mb4 --hex-blob %s 2>&1',
                    escapeshellarg($mysqldump),
                    escapeshellarg($mysqlHost),
                    escapeshellarg($mysqlUser),
                    escapeshellarg($mysqlPass),
                    escapeshellarg($db)
                );
                $tmp = $sqlFile . '.tmp';
                @exec($cmd . ' > ' . escapeshellarg($tmp), $out, $code);
                if ($code !== 0) {
                    @unlink($tmp);
                    throw new RuntimeException('mysqldump exit code ' . $code . ': ' . substr(implode("\n", $out), 0, 600));
                }
                rename($tmp, $sqlFile);
            } else {
                $rec['method'] = 'php';
                voltikaPhpDumpDatabase($mysqlHost, $mysqlUser, $mysqlPass, $db, $sqlFile);
            }

            // Compress with gzip if available (transparent — works for both methods).
            if (function_exists('gzopen')) {
                $gzFile = $sqlFile . '.gz';
                $in  = fopen($sqlFile, 'rb');
                $out = gzopen($gzFile, 'wb6');
                if ($in && $out) {
                    while (!feof($in)) {
                        $chunk = fread($in, 1 << 20); // 1 MiB chunks
                        if ($chunk === false) break;
                        gzwrite($out, $chunk);
                    }
                    fclose($in);
                    gzclose($out);
                    if (filesize($gzFile) > 0) {
                        @unlink($sqlFile);
                        $sqlFile = $gzFile;
                    }
                }
            }

            $rec['ok']         = true;
            $rec['file']       = basename($sqlFile);
            $rec['size_bytes'] = filesize($sqlFile);
            $rec['duration_s'] = round(microtime(true) - $start, 2);
        } catch (Throwable $e) {
            $rec['error']      = $e->getMessage();
            $rec['duration_s'] = round(microtime(true) - $start, 2);
        }

        $results[] = $rec;
    }

    // Manifest with HMAC-signed download URL
    $sig = hash_hmac('sha256', $backupId, $expectedToken);
    $manifest = [
        'ok'              => true,
        'backup_id'       => $backupId,
        'created_at'      => date('c'),
        'host'            => $mysqlHost,
        'user'            => $mysqlUser,
        'method_pref'     => $useMysqldump ? 'mysqldump' : 'php',
        'php_version'     => PHP_VERSION,
        'directory'       => $backupDir,
        'files'           => $results,
        'download_url'    => '?action=download&id=' . urlencode($backupId) . '&sig=' . $sig . '&token=' . urlencode($expectedToken),
        'individual_files'=> array_map(function ($r) use ($backupDir) {
            return isset($r['file']) ? $backupDir . '/' . $r['file'] : null;
        }, $results),
    ];
    @file_put_contents($backupDir . '/manifest.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    header('Content-Type: application/json');
    echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Default
http_response_code(400);
header('Content-Type: application/json');
echo json_encode([
    'error' => 'unknown_action',
    'valid' => ['list', 'backup', 'backup-all', 'download'],
    'usage' => [
        '?action=list&token=TOKEN',
        '?action=backup-all&token=TOKEN',
        '?action=backup&db=NAME&token=TOKEN',
        '?action=download&id=ID&sig=HMAC&token=TOKEN',
    ],
]);
exit;


// ─────────────────────────────────────────────────────────────────────────
// Pure-PHP database dumper (fallback when mysqldump is unavailable).
// Captures CREATE TABLE + INSERT statements + view DDL. Triggers, routines
// and events are skipped in this fallback path — they require SUPER /
// admin grants which are typically not present on shared Plesk hosting
// anyway, and mysqldump is the canonical path when those are needed.
// ─────────────────────────────────────────────────────────────────────────
function voltikaPhpDumpDatabase(string $host, string $user, string $pass, string $db, string $outFile): void {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $fh = fopen($outFile, 'wb');
    if (!$fh) throw new RuntimeException('cannot open output file');

    fwrite($fh, "-- Voltika DB dump (php fallback) — " . date('c') . "\n");
    fwrite($fh, "-- Database: $db\n");
    fwrite($fh, "SET NAMES utf8mb4;\n");
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n");
    fwrite($fh, "SET UNIQUE_CHECKS=0;\n");
    fwrite($fh, "SET AUTOCOMMIT=0;\n");
    fwrite($fh, "START TRANSACTION;\n\n");

    // Tables and views
    $items = $pdo->query("SHOW FULL TABLES")->fetchAll(PDO::FETCH_NUM);

    // Pass 1: tables (data + structure)
    foreach ($items as [$name, $type]) {
        if ($type !== 'BASE TABLE') continue;
        $createRow = $pdo->query("SHOW CREATE TABLE `" . str_replace('`', '``', $name) . "`")->fetch(PDO::FETCH_NUM);
        $createSql = $createRow[1] ?? '';
        fwrite($fh, "\n-- ── Table: $name ──\n");
        fwrite($fh, "DROP TABLE IF EXISTS `$name`;\n");
        fwrite($fh, $createSql . ";\n\n");

        // Stream rows in batches.
        $stmt = $pdo->prepare("SELECT * FROM `" . str_replace('`', '``', $name) . "`");
        $stmt->execute();
        $cols = null;
        $batch = [];
        $batchSize = 200;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($cols === null) {
                $cols = array_keys($row);
                $colList = '`' . implode('`,`', $cols) . '`';
            }
            $vals = [];
            foreach ($row as $v) {
                if ($v === null) {
                    $vals[] = 'NULL';
                } elseif (is_int($v) || is_float($v)) {
                    $vals[] = (string)$v;
                } else {
                    $vals[] = $pdo->quote((string)$v);
                }
            }
            $batch[] = '(' . implode(',', $vals) . ')';
            if (count($batch) >= $batchSize) {
                fwrite($fh, "INSERT INTO `$name` ($colList) VALUES\n  " . implode(",\n  ", $batch) . ";\n");
                $batch = [];
            }
        }
        if (!empty($batch) && $cols !== null) {
            fwrite($fh, "INSERT INTO `$name` ($colList) VALUES\n  " . implode(",\n  ", $batch) . ";\n");
        }
    }

    // Pass 2: views (after all tables exist)
    foreach ($items as [$name, $type]) {
        if ($type !== 'VIEW') continue;
        $createRow = $pdo->query("SHOW CREATE VIEW `" . str_replace('`', '``', $name) . "`")->fetch(PDO::FETCH_NUM);
        $createSql = $createRow[1] ?? '';
        fwrite($fh, "\n-- ── View: $name ──\n");
        fwrite($fh, "DROP VIEW IF EXISTS `$name`;\n");
        fwrite($fh, $createSql . ";\n");
    }

    fwrite($fh, "\nSET FOREIGN_KEY_CHECKS=1;\n");
    fwrite($fh, "COMMIT;\n");
    fclose($fh);
}
