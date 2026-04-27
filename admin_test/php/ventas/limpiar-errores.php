<?php
/**
 * Clean up unrecovered error rows in transacciones_errores.
 *
 * Usage (all via GET — just paste the URL in the browser):
 *   limpiar-errores.php              → preview (list what will be cleaned)
 *   limpiar-errores.php?run=1        → clean ALL unrecovered errors
 *   limpiar-errores.php?run=1&ids=53,54,55 → clean specific IDs only
 *
 * Does NOT delete rows — sets recuperado_tx_id = -1 so they stop
 * appearing in the dashboard but remain for audit.
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();

// Ensure column exists
try {
    $pdo->exec("ALTER TABLE transacciones_errores ADD COLUMN recuperado_tx_id INT NULL");
} catch (Throwable $e) { /* already exists */ }

$run = !empty($_GET['run']);

if (!$run) {
    // Preview mode
    $rows = $pdo->query("
        SELECT id, nombre, email, telefono, modelo, stripe_pi, error_msg, freg
        FROM transacciones_errores
        WHERE recuperado_tx_id IS NULL
        ORDER BY id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    adminJsonOut([
        'ok'    => true,
        'mode'  => 'preview',
        'count' => count($rows),
        'hint'  => 'Add ?run=1 to clean all, or ?run=1&ids=53,54,55 for specific IDs',
        'rows'  => $rows,
    ]);
}

// Execute cleanup
$idsParam = trim($_GET['ids'] ?? '');

if ($idsParam !== '') {
    $ids = array_map('intval', explode(',', $idsParam));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        UPDATE transacciones_errores
        SET recuperado_tx_id = -1
        WHERE id IN ($placeholders) AND recuperado_tx_id IS NULL
    ");
    $stmt->execute($ids);
    $affected = $stmt->rowCount();
} else {
    $affected = $pdo->exec("
        UPDATE transacciones_errores
        SET recuperado_tx_id = -1
        WHERE recuperado_tx_id IS NULL
    ");
}

adminJsonOut([
    'ok'       => true,
    'mode'     => 'cleaned',
    'affected' => (int)$affected,
    'msg'      => $affected . ' error(s) descartados.',
]);
