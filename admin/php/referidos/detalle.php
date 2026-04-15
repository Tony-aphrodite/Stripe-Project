<?php
/**
 * GET — Detail view for a referido or punto code
 * ?tipo=referido&id=5  OR  ?tipo=punto&id=3
 *
 * Returns all transactions made with this code + commission log
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$tipo = trim($_GET['tipo'] ?? '');
$id   = (int)($_GET['id'] ?? 0);

if (!$id || !in_array($tipo, ['referido', 'punto'])) {
    adminJsonOut(['ok' => false, 'error' => 'tipo and id required'], 400);
}

// Get transactions for this referido/punto
$txns = $pdo->prepare("
    SELECT t.id, t.pedido_num, t.nombre, t.telefono, t.modelo, t.color,
           t.monto, t.metodo_pago, t.estatus_pago, t.referido, t.freg,
           t.punto_nombre
    FROM transacciones t
    WHERE t.referido_id = ? AND t.referido_tipo = ?
    ORDER BY t.freg DESC
    LIMIT 200
");
$txns->execute([$id, $tipo]);
$transactions = $txns->fetchAll(PDO::FETCH_ASSOC);

// Get commission log
$comLog = [];
try {
    $col = ($tipo === 'punto') ? 'punto_id' : 'referido_id';
    $stmt = $pdo->prepare("
        SELECT cl.id, cl.pedido_num, cl.modelo, cl.monto_venta,
               cl.comision_pct, cl.comision_monto, cl.tipo, cl.freg
        FROM comisiones_log cl
        WHERE cl.$col = ?
        ORDER BY cl.freg DESC
        LIMIT 200
    ");
    $stmt->execute([$id]);
    $comLog = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Summary stats
$summary = [
    'total_operaciones' => count($transactions),
    'total_ventas' => array_sum(array_column($transactions, 'monto')),
    'total_comision' => array_sum(array_column($comLog, 'comision_monto')),
];

adminJsonOut([
    'ok' => true,
    'transacciones' => $transactions,
    'comisiones' => $comLog,
    'summary' => $summary
]);
