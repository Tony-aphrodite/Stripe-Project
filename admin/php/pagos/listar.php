<?php
/**
 * GET — List orders/payments with status
 * Filters: ?tipo=contado|msi|credito&estado=&page=
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$pdo = getDB();
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// Detect actual schema for subscripciones_credito (supports both legacy and portal variants)
$subCols = [];
try { $subCols = $pdo->query("SHOW COLUMNS FROM subscripciones_credito")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
$hasSub        = !empty($subCols);
$subStatusCol  = in_array('estado', $subCols) ? 'estado' : (in_array('status', $subCols) ? 'status' : null);
$subModeloExpr = in_array('modelo', $subCols) ? 's.modelo' : "'' as modelo_x";
$subColorExpr  = in_array('color', $subCols) ? 's.color' : "'' as color_x";
$subMontoExpr  = in_array('precio_contado', $subCols) ? 's.precio_contado'
                : (in_array('monto_semanal', $subCols) ? 's.monto_semanal' : '0');
$subStripeExpr = in_array('stripe_setup_intent_id', $subCols) ? 's.stripe_setup_intent_id'
                : (in_array('stripe_customer_id', $subCols) ? 's.stripe_customer_id' : "''");

$rows = [];

try {
    $tipo = $_GET['tipo'] ?? '';
    if ($tipo === 'credito' && $hasSub && $subStatusCol) {
        $sql = "SELECT 'credito' as fuente, s.id,
                '' as pedido_num, s.nombre, s.email, s.telefono,
                " . (in_array('modelo', $subCols) ? 's.modelo' : "'' as modelo") . ",
                " . (in_array('color', $subCols) ? 's.color' : "'' as color") . ",
                'credito' as tipo_pago,
                {$subMontoExpr} as monto,
                {$subStripeExpr} as stripe_pi,
                s.{$subStatusCol} as pago_estado, s.freg
                FROM subscripciones_credito s
                ORDER BY s.freg DESC LIMIT $limit OFFSET $offset";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $sql = "SELECT 'orden' as fuente, t.id, t.pedido as pedido_num, t.nombre, t.email, t.telefono,
            t.modelo, t.color, t.tpago as tipo_pago, t.total as monto, t.stripe_pi,
            COALESCE(m.pago_estado,'pendiente') as pago_estado, t.freg
            FROM transacciones t
            LEFT JOIN inventario_motos m ON m.stripe_pi=t.stripe_pi";
        $params = [];
        if (!empty($tipo)) { $sql .= " WHERE t.tpago=?"; $params[] = $tipo; }
        $sql .= " ORDER BY t.freg DESC LIMIT $limit OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('pagos/listar rows: ' . $e->getMessage());
    $rows = [];
}

// Summary — orders
$summary = ['total_ordenes' => 0, 'total_ingresos' => 0];
try {
    $summary = $pdo->query("SELECT
        COUNT(*) as total_ordenes,
        COALESCE(SUM(total),0) as total_ingresos
        FROM transacciones")->fetch(PDO::FETCH_ASSOC) ?: $summary;
} catch (Throwable $e) { error_log('pagos/listar summary: ' . $e->getMessage()); }

// Summary — credit
$creditSummary = ['total_creditos' => 0, 'total_credito_monto' => 0];
if ($hasSub && $subStatusCol) {
    try {
        $montoSumExpr = in_array('precio_contado', $subCols) ? 'COALESCE(SUM(precio_contado),0)'
                       : (in_array('monto_semanal', $subCols) ? 'COALESCE(SUM(monto_semanal),0)' : '0');
        $creditSummary = $pdo->query("SELECT
            COUNT(*) as total_creditos,
            {$montoSumExpr} as total_credito_monto
            FROM subscripciones_credito WHERE {$subStatusCol} IN ('activa','active')")
            ->fetch(PDO::FETCH_ASSOC) ?: $creditSummary;
    } catch (Throwable $e) { error_log('pagos/listar credit: ' . $e->getMessage()); }
}

adminJsonOut([
    'pagos' => $rows,
    'resumen_ordenes' => $summary,
    'resumen_credito' => $creditSummary,
    'page' => $page
]);
