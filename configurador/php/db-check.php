<?php
/**
 * Voltika - DB Diagnostic Check
 * Run ONCE to verify DB connection and table status.
 * DELETE this file after verification.
 */

// Simple auth to prevent public access
$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika2024check') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=UTF-8');

$tables = [
    'pedidos',
    'transacciones',
    'facturacion',
    'consultas_buro',
    'preaprobaciones',
    'verificaciones_identidad',
];

echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>Voltika DB Check</title>
<style>
body{font-family:Arial,sans-serif;max-width:700px;margin:40px auto;padding:0 20px;}
h1{color:#22C55E;}
table{width:100%;border-collapse:collapse;margin-top:20px;}
th,td{padding:10px 14px;border:1px solid #ddd;text-align:left;}
th{background:#f5f5f5;}
.ok{color:#22C55E;font-weight:700;}
.missing{color:#C62828;font-weight:700;}
.err{color:#C62828;}
</style>
</head><body>';

echo '<h1>Voltika DB Check</h1>';

// 1. Test connection
try {
    $pdo = getDB();
    echo '<p class="ok">&#10004; DB connection OK (host: ' . DB_HOST . ', db: ' . DB_NAME . ')</p>';
} catch (Exception $e) {
    echo '<p class="err">&#10060; DB connection FAILED: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '</body></html>';
    exit;
}

// 2. Check tables and row counts
echo '<table>';
echo '<tr><th>Table</th><th>Status</th><th>Rows</th><th>Last record</th></tr>';

foreach ($tables as $tbl) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
        $last  = $pdo->query("SELECT freg FROM `$tbl` ORDER BY id DESC LIMIT 1")->fetchColumn();
        echo '<tr>';
        echo '<td><strong>' . $tbl . '</strong></td>';
        echo '<td class="ok">&#10004; exists</td>';
        echo '<td>' . $count . '</td>';
        echo '<td>' . ($last ?: '—') . '</td>';
        echo '</tr>';
    } catch (Exception $e) {
        echo '<tr>';
        echo '<td><strong>' . $tbl . '</strong></td>';
        echo '<td class="missing">&#10060; not created yet</td>';
        echo '<td colspan="2" style="color:#888;">Will be created on first use</td>';
        echo '</tr>';
    }
}

echo '</table>';

echo '<p style="margin-top:30px;font-size:13px;color:#888;">
&#9888; Delete this file after verification: <code>configurador/php/db-check.php</code></p>';

echo '</body></html>';
