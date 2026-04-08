<?php
/**
 * Fix: Add stripe_pi column to transacciones table
 * ?key=voltika_fix_tx_2026
 */
$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika_fix_tx_2026') { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/php/config.php';
header('Content-Type: text/html; charset=UTF-8');
$pdo = getDB();

echo '<h1>Fix transacciones table</h1>';

$sqls = [
    "ALTER TABLE transacciones ADD COLUMN IF NOT EXISTS stripe_pi VARCHAR(100)",
];

foreach ($sqls as $sql) {
    try {
        $pdo->exec($sql);
        echo '<p style="color:green;">✅ OK</p>';
    } catch (PDOException $e) {
        echo '<p style="color:red;">❌ ' . $e->getMessage() . '</p>';
    }
}

// Verify
try {
    $rows = $pdo->query("SELECT id, nombre, modelo, tpago, pedido, stripe_pi, freg FROM transacciones ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    echo '<h3>Last 3 transacciones:</h3><pre>' . print_r($rows, true) . '</pre>';
} catch (PDOException $e) {
    echo '<p style="color:red;">❌ ' . $e->getMessage() . '</p>';
}

echo '<hr><p style="color:#C62828;">⚠️ Eliminar después de ejecutar.</p>';
