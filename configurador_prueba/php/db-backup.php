<?php
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

$pdo  = getDB();
$date = date('Y-m-d');
$sql  = "-- Voltika DB Backup\n";
$sql .= "-- Date: " . date('Y-m-d H:i:s') . "\n";
$sql .= "-- Tables: " . implode(', ', $tables) . "\n\n";
$sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

foreach ($tables as $table) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->execute([$table]);
    if (!(int)$stmt->fetchColumn()) {
        $sql .= "-- Table `$table` not found — skipped\n\n";
        continue;
    }

    $createRow = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
    $sql .= "-- --------------------------------------------------------\n";
    $sql .= "-- Table: `$table`\n";
    $sql .= "-- --------------------------------------------------------\n\n";
    $sql .= "DROP TABLE IF EXISTS `$table`;\n";
    $sql .= $createRow[1] . ";\n\n";

    $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        $sql .= "-- (no rows)\n\n";
        continue;
    }

    $sql .= "INSERT INTO `$table` VALUES\n";
    $inserts = [];
    foreach ($rows as $row) {
        $vals = array_map(function($v) use ($pdo) {
            return $v === null ? 'NULL' : $pdo->quote($v);
        }, array_values($row));
        $inserts[] = '(' . implode(', ', $vals) . ')';
    }
    $sql .= implode(",\n", $inserts) . ";\n\n";
}

$sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

// Send as downloadable file
$filename = 'backup_' . $date . '.sql';
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($sql));
echo $sql;
