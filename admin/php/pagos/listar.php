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

// Customer brief 2026-05-02:
//   1. Add date-range filter (desde/hasta) so admins can scope the
//      payments view to any window — defaults to all-time when omitted.
//   2. Always compute a "current month" net summary (Stripe-successful
//      minus refunds and failed) to show as a prominent KPI.
//
// Both are read-only additions to the response; existing fields are
// untouched so older clients remain compatible.
$desde = trim((string)($_GET['desde'] ?? ''));
$hasta = trim((string)($_GET['hasta'] ?? ''));
// Validate to YYYY-MM-DD; if invalid, ignore (preserve old behavior).
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) $desde = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) $hasta = '';

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
                '' as pedido_num, COALESCE(s.nombre, cl.nombre, '') as nombre, s.email, s.telefono,
                " . (in_array('modelo', $subCols) ? 's.modelo' : "'' as modelo") . ",
                " . (in_array('color', $subCols) ? 's.color' : "'' as color") . ",
                'credito' as tipo_pago,
                {$subMontoExpr} as monto,
                {$subStripeExpr} as stripe_pi,
                s.{$subStatusCol} as pago_estado, s.freg
                FROM subscripciones_credito s
                LEFT JOIN clientes cl ON s.cliente_id = cl.id
                ORDER BY s.freg DESC LIMIT $limit OFFSET $offset";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $sql = "SELECT 'orden' as fuente, t.id, t.pedido as pedido_num, t.nombre, t.email, t.telefono,
            t.modelo, t.color, t.tpago as tipo_pago, t.total as monto, t.stripe_pi,
            COALESCE(t.pago_estado, m.pago_estado, 'pendiente') as pago_estado, t.freg
            FROM transacciones t
            LEFT JOIN inventario_motos m ON m.stripe_pi=t.stripe_pi";
        $where  = [];
        $params = [];
        if (!empty($tipo))  { $where[] = "t.tpago=?";        $params[] = $tipo; }
        // Date range filter — inclusive on both ends. Customer brief
        // 2026-05-02: scope the table to any "from–to" window picked in
        // the UI. We compare against the order's freg (when it was placed).
        if ($desde !== '') { $where[] = "t.freg >= ?";       $params[] = $desde . ' 00:00:00'; }
        if ($hasta !== '') { $where[] = "t.freg <= ?";       $params[] = $hasta . ' 23:59:59'; }
        if (!empty($where)) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY t.freg DESC LIMIT $limit OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('pagos/listar rows: ' . $e->getMessage());
    $rows = [];
}

// Summary — orders (respects the same date/tipo filters so KPIs match
// what the table is showing).
$summary = ['total_ordenes' => 0, 'total_ingresos' => 0];
try {
    $sumWhere  = [];
    $sumParams = [];
    if (!empty($tipo))  { $sumWhere[] = "tpago=?";   $sumParams[] = $tipo; }
    if ($desde !== '') { $sumWhere[] = "freg >= ?"; $sumParams[] = $desde . ' 00:00:00'; }
    if ($hasta !== '') { $sumWhere[] = "freg <= ?"; $sumParams[] = $hasta . ' 23:59:59'; }
    $sumWhereSql = $sumWhere ? (" WHERE " . implode(' AND ', $sumWhere)) : "";
    $sumStmt = $pdo->prepare("SELECT
        COUNT(*) as total_ordenes,
        COALESCE(SUM(total),0) as total_ingresos
        FROM transacciones $sumWhereSql");
    $sumStmt->execute($sumParams);
    $summary = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: $summary;
} catch (Throwable $e) { error_log('pagos/listar summary: ' . $e->getMessage()); }

// ── Current-month NET summary (customer brief 2026-05-02) ──────────────
// "Real Stripe payments successfully made minus failed/not-successful
//  payments AND minus refunds." Computed from transacciones.pago_estado
// (kept in sync by stripe-webhook.php → 'pagada' on succeeded, 'reembolsado'
// on charge.refunded). Independent of the table's date filter — always
// reports current calendar month (1st → today inclusive).
$mesActual = ['desde' => date('Y-m-01'), 'hasta' => date('Y-m-d')];
$resumenMes = [
    'desde'              => $mesActual['desde'],
    'hasta'              => $mesActual['hasta'],
    'pagados_count'      => 0,
    'pagados_monto'      => 0,
    'reembolsados_count' => 0,
    'reembolsados_monto' => 0,
    'fallidos_count'     => 0,
    'fallidos_monto'     => 0,
    'neto'               => 0,
];
try {
    // Cohort: orders created this calendar month with a stripe_pi (real
    // Stripe attempts only — exclude phantom/orphan rows).
    $stmtMes = $pdo->prepare("SELECT
        SUM(CASE WHEN pago_estado = 'pagada'         THEN 1 ELSE 0 END) AS pagados_count,
        SUM(CASE WHEN pago_estado = 'pagada'         THEN total ELSE 0 END) AS pagados_monto,
        SUM(CASE WHEN pago_estado = 'reembolsado'    THEN 1 ELSE 0 END) AS reembolsados_count,
        SUM(CASE WHEN pago_estado = 'reembolsado'    THEN total ELSE 0 END) AS reembolsados_monto,
        SUM(CASE WHEN pago_estado IN ('error','fallido','orfano') OR pago_estado IS NULL OR pago_estado = ''
                 THEN 1 ELSE 0 END) AS fallidos_count,
        SUM(CASE WHEN pago_estado IN ('error','fallido','orfano') OR pago_estado IS NULL OR pago_estado = ''
                 THEN total ELSE 0 END) AS fallidos_monto
        FROM transacciones
        WHERE freg >= ? AND freg <= ?
          AND stripe_pi IS NOT NULL
          AND stripe_pi <> ''");
    $stmtMes->execute([$mesActual['desde'] . ' 00:00:00', $mesActual['hasta'] . ' 23:59:59']);
    $r = $stmtMes->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $resumenMes['pagados_count']      = (int)$r['pagados_count'];
        $resumenMes['pagados_monto']      = (float)$r['pagados_monto'];
        $resumenMes['reembolsados_count'] = (int)$r['reembolsados_count'];
        $resumenMes['reembolsados_monto'] = (float)$r['reembolsados_monto'];
        $resumenMes['fallidos_count']     = (int)$r['fallidos_count'];
        $resumenMes['fallidos_monto']     = (float)$r['fallidos_monto'];
        // Neto = exitosos - reembolsados. Failed/pending are simply
        // EXCLUDED (not subtracted) since they were never collected.
        $resumenMes['neto']               = $resumenMes['pagados_monto'] - $resumenMes['reembolsados_monto'];
    }
} catch (Throwable $e) { error_log('pagos/listar resumen mes: ' . $e->getMessage()); }

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
    'resumen_mes_actual' => $resumenMes,   // customer brief 2026-05-02
    'filtros_aplicados'  => [
        'tipo'  => $tipo,
        'desde' => $desde,
        'hasta' => $hasta,
    ],
    'page' => $page
]);
