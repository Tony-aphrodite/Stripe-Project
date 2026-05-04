<?php
/**
 * Voltika — Full DB backup, streamed direct to browser download.
 *
 * Why this file exists separately from backup-databases.php:
 *   backup-databases.php writes intermediate .sql files under
 *   php/backups/<id>/ and serves a signed download URL. On Plesk hosting
 *   the PHP-FPM vhost user often lacks write permission inside the code
 *   tree — the directory create fails with "mkdir_failed" and no backup
 *   is produced. This file bypasses disk entirely: it auto-discovers
 *   every table in the current DB and streams the SQL dump straight to
 *   the HTTP response so the browser writes it to the user's local
 *   Downloads folder. No temp files, no permissions to grant.
 *
 * Usage:
 *   https://voltika.mx/configurador/php/db-backup.php?key=voltika-backup-2026
 *
 * Optional query params:
 *   ?tables=transacciones,inventario_motos   — back up only these tables
 *   ?key=...                                  — required token (override
 *                                               via VOLTIKA_BACKUP_KEY env)
 */

declare(strict_types=1);

@set_time_limit(0);
@ini_set('memory_limit', '512M');

$expectedKey = getenv('VOLTIKA_BACKUP_KEY') ?: 'voltika-backup-2026';
$secret = $_GET['key'] ?? '';
if (!hash_equals($expectedKey, (string)$secret)) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/config.php';

$pdo = getDB();
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

// Auto-discover every table in the current schema. The previous version
// hard-coded 6 tables which silently dropped 90 % of the data on
// production (inventario_motos, firmas_contratos, dossiers_defensa,
// envios, checklists, admin_users, …).
$onlyTables = [];
if (!empty($_GET['tables'])) {
    $onlyTables = array_filter(array_map('trim', explode(',', (string)$_GET['tables'])));
}
$tables = [];
$stmt = $pdo->query("SELECT table_name FROM information_schema.tables
                     WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
                     ORDER BY table_name");
foreach ($stmt as $r) {
    $name = $r['table_name'] ?? $r['TABLE_NAME'] ?? null;
    if (!$name) continue;
    if ($onlyTables && !in_array($name, $onlyTables, true)) continue;
    $tables[] = $name;
}

if (!$tables) {
    http_response_code(500);
    exit('No tables found in DB ' . $dbName);
}

// ── Stream as downloadable .sql ────────────────────────────────────────
$filename = 'voltika_backup_' . $dbName . '_' . date('Ymd_His') . '.sql';
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
// Force flush as we go so the browser sees download progress and the
// vhost doesn't buffer 100 MB+ in memory before sending the first byte.
while (ob_get_level()) ob_end_flush();

function out(string $s): void {
    echo $s;
    @flush();
}

out("-- ════════════════════════════════════════════════════════════════\n");
out("-- Voltika full DB backup\n");
out("-- Database: $dbName\n");
out("-- Generated: " . date('Y-m-d H:i:s T') . "\n");
out("-- Tables (" . count($tables) . "): " . implode(', ', $tables) . "\n");
out("-- ════════════════════════════════════════════════════════════════\n\n");
out("SET FOREIGN_KEY_CHECKS=0;\n");
out("SET NAMES utf8mb4;\n");
out("SET @OLD_TIME_ZONE=@@TIME_ZONE; SET TIME_ZONE='+00:00';\n\n");

foreach ($tables as $table) {
    out("-- ───────────────────────────────────────────────────────────\n");
    out("-- Table: `$table`\n");
    out("-- ───────────────────────────────────────────────────────────\n\n");

    try {
        $createRow = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
        out("DROP TABLE IF EXISTS `$table`;\n");
        out($createRow[1] . ";\n\n");
    } catch (Throwable $e) {
        out("-- skip create: " . $e->getMessage() . "\n\n");
        continue;
    }

    // Stream rows in chunks so memory stays bounded for tables with
    // hundreds of thousands of rows (transacciones, inventario_motos,
    // log tables can grow large over time).
    $countRow = (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    if ($countRow === 0) {
        out("-- (no rows)\n\n");
        continue;
    }

    out("LOCK TABLES `$table` WRITE;\n");
    $chunk    = 500;
    $offset   = 0;
    $colsRow  = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    $colsList = '`' . implode('`,`', $colsRow) . '`';

    while ($offset < $countRow) {
        $rows = $pdo->query("SELECT * FROM `$table` LIMIT $chunk OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) break;
        $values = [];
        foreach ($rows as $row) {
            $vals = [];
            foreach ($row as $v) {
                if ($v === null)         $vals[] = 'NULL';
                elseif (is_int($v)
                    || is_float($v))    $vals[] = (string)$v;
                else                     $vals[] = $pdo->quote((string)$v);
            }
            $values[] = '(' . implode(',', $vals) . ')';
        }
        out("INSERT INTO `$table` ($colsList) VALUES\n  " . implode(",\n  ", $values) . ";\n");
        $offset += $chunk;
    }
    out("UNLOCK TABLES;\n\n");
}

out("SET FOREIGN_KEY_CHECKS=1;\n");
out("SET TIME_ZONE=@OLD_TIME_ZONE;\n\n");
out("-- Dump completed: " . date('Y-m-d H:i:s T') . "\n");
out("-- Total tables: " . count($tables) . "\n");
