<?php
/**
 * Migration: Add missing columns to checklist_entrega_v2
 * - otp_code VARCHAR(10)
 * - otp_expires DATETIME
 * - firma_data LONGTEXT
 *
 * Safe to run multiple times — checks if columns exist first.
 */
require_once __DIR__ . '/php/config.php';
$pdo = getDB();

echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Voltika — Migración checklist</title></head><body>';
echo '<h1>Migración: columnas faltantes en checklist_entrega_v2</h1>';

$columnas = [
    'otp_code'    => 'VARCHAR(10) DEFAULT NULL AFTER otp_timestamp',
    'otp_expires'  => 'DATETIME DEFAULT NULL AFTER otp_code',
    'firma_data'   => 'LONGTEXT DEFAULT NULL AFTER firma_digital',
];

foreach ($columnas as $col => $def) {
    // Check if column already exists
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='checklist_entrega_v2' AND COLUMN_NAME=?");
    $check->execute([$col]);
    if ($check->fetchColumn() > 0) {
        echo "<p>✅ <b>$col</b> — ya existe, no se modifica.</p>";
        continue;
    }
    try {
        $pdo->exec("ALTER TABLE checklist_entrega_v2 ADD COLUMN $col $def");
        echo "<p>✅ <b>$col</b> — agregada correctamente.</p>";
    } catch (PDOException $e) {
        echo "<p>❌ <b>$col</b> — error: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

echo '<p style="margin-top:20px;font-weight:bold;color:green;">Migración completada.</p>';
echo '</body></html>';
