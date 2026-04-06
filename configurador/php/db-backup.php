<?php
/**
 * Voltika - DB Backup Script
 * Exports all tables to a SQL file in /db/
 * Access: /php/db-backup.php?key=voltika-backup-2026
 */

$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika-backup-2026') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/config.php';

$tables = [
    'pedidos',
    'transacciones',
    'facturacion',
    'consultas_buro',
    'preaprobaciones',
    'verificaciones_identidad',
];

$date    = date('Y-m-d');
$outDir  = __DIR__ . '/../db';
$outFile = $outDir . '/backup_' . $date . '.sql';

if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

try {
    $pdo = getDB();
} catch (Exception $e) {
    http_response_code(500);
    exit('DB connection failed: ' . $e->getMessage());
}

$sql  = "-- Voltika DB Backup\n";
$sql .= "-- Date: " . date('Y-m-d H:i:s') . "\n";
$sql .= "-- Tables: " . implode(', ', $tables) . "\n\n";
$sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

foreach ($tables as $table) {
    // Check table exists
    $exists = $pdo->query("SHOW TABLES LIKE '$table'")->rowCount();
    if (!$exists) {
        $sql .= "-- Table `$table` does not exist — skipped\n\n";
        continue;
    }

    // CREATE TABLE statement
    $createRow = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
    $sql .= "-- --------------------------------------------------------\n";
    $sql .= "-- Table: `$table`\n";
    $sql .= "-- --------------------------------------------------------\n\n";
    $sql .= "DROP TABLE IF EXISTS `$table`;\n";
    $sql .= $createRow[1] . ";\n\n";

    // Data rows
    $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) === 0) {
        $sql .= "-- (no rows in `$table`)\n\n";
        continue;
    }

    $sql .= "INSERT INTO `$table` VALUES\n";
    $inserts = [];
    foreach ($rows as $row) {
        $vals = array_map(function($v) use ($pdo) {
            if ($v === null) return 'NULL';
            return $pdo->quote($v);
        }, array_values($row));
        $inserts[] = '(' . implode(', ', $vals) . ')';
    }
    $sql .= implode(",\n", $inserts) . ";\n\n";
}

$sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

file_put_contents($outFile, $sql);

header('Content-Type: text/html; charset=UTF-8');
echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>Voltika DB Backup</title>
<style>
body{font-family:Arial,sans-serif;max-width:600px;margin:60px auto;padding:0 20px;}
.ok{color:#22C55E;font-weight:700;}
code{background:#f5f5f5;padding:4px 8px;border-radius:4px;font-size:14px;}
</style></head><body>';
echo '<h2>Voltika DB Backup</h2>';
echo '<p class="ok">&#10004; Backup creado correctamente</p>';
echo '<p>Archivo: <code>db/backup_' . $date . '.sql</code></p>';
echo '<p>Tablas exportadas:</p><ul>';
foreach ($tables as $t) {
    echo '<li><code>' . $t . '</code></li>';
}
echo '</ul>';
echo '<p style="color:#C62828;font-weight:700;">&#9888; Elimina o protege este archivo después de usarlo.</p>';
echo '</body></html>';
