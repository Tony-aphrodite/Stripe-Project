<?php
/**
 * Diagnostic: Test inserting into inventario_motos
 * ?key=voltika_diag_2026
 */
$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika_diag_2026') { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/php/config.php';
header('Content-Type: text/html; charset=UTF-8');

echo '<h2>Diagnostic: inventario_motos INSERT test</h2>';

$pdo = getDB();

// 1. Check table structure
echo '<h3>1. Table columns:</h3>';
try {
    $cols = $pdo->query("DESCRIBE inventario_motos")->fetchAll(PDO::FETCH_ASSOC);
    echo '<pre>';
    foreach ($cols as $c) {
        echo $c['Field'] . ' — ' . $c['Type'] . ' — ' . ($c['Null'] === 'YES' ? 'NULL OK' : 'NOT NULL') . ' — Default: ' . ($c['Default'] ?? 'none') . "\n";
    }
    echo '</pre>';
} catch (PDOException $e) {
    echo '<p style="color:red;">ERROR: ' . $e->getMessage() . '</p>';
}

// 2. Check if stripe_pi column exists
echo '<h3>2. Key columns check:</h3>';
$checkCols = ['stripe_pi', 'transaccion_id', 'stripe_payment_status', 'cedis_origen'];
foreach ($checkCols as $col) {
    try {
        $pdo->query("SELECT $col FROM inventario_motos LIMIT 1");
        echo '<p style="color:green;">✅ ' . $col . ' — exists</p>';
    } catch (PDOException $e) {
        echo '<p style="color:red;">❌ ' . $col . ' — MISSING: ' . $e->getMessage() . '</p>';
    }
}

// 3. Try test INSERT
echo '<h3>3. Test INSERT:</h3>';
try {
    $testVin = 'TEST-DIAG-' . time();
    $pdo->prepare("
        INSERT INTO inventario_motos
            (vin, vin_display, modelo, color, tipo_asignacion, estado,
             cliente_nombre, cliente_email, cliente_telefono,
             pedido_num, pago_estado, fecha_estado, log_estados, precio_venta, notas,
             stripe_pi, transaccion_id)
        VALUES
            (?, ?, ?, ?, 'voltika_entrega', 'por_llegar',
             ?, ?, ?,
             ?, ?, NOW(), ?, ?, ?,
             ?, ?)
    ")->execute([
        $testVin, '****TEST', 'MO5', 'Negro',
        'Test User', 'test@test.com', '5512345678',
        'VK-TEST-' . time(),
        'pagada',
        json_encode([['estado'=>'por_llegar','accion'=>'test','timestamp'=>date('c')]]),
        48260,
        'Diagnostic test insert',
        null, null
    ]);
    $newId = $pdo->lastInsertId();
    echo '<p style="color:green;">✅ INSERT OK — new ID: ' . $newId . '</p>';

    // Clean up
    $pdo->prepare("DELETE FROM inventario_motos WHERE id = ?")->execute([$newId]);
    echo '<p style="color:green;">✅ Cleanup OK — test row deleted</p>';

} catch (PDOException $e) {
    echo '<p style="color:red;">❌ INSERT FAILED: ' . $e->getMessage() . '</p>';
}

// 4. Check recent transacciones
echo '<h3>4. Last 3 transacciones:</h3>';
try {
    $rows = $pdo->query("SELECT id, nombre, modelo, tpago, pedido, stripe_pi, freg FROM transacciones ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    echo '<pre>' . print_r($rows, true) . '</pre>';
} catch (PDOException $e) {
    echo '<p style="color:red;">ERROR: ' . $e->getMessage() . '</p>';
}

// 5. Check recent inventario_motos
echo '<h3>5. Last 3 motos in inventario:</h3>';
try {
    $rows = $pdo->query("SELECT id, vin, modelo, pedido_num, freg FROM inventario_motos ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    echo '<pre>' . print_r($rows, true) . '</pre>';
} catch (PDOException $e) {
    echo '<p style="color:red;">ERROR: ' . $e->getMessage() . '</p>';
}
