<?php
/**
 * GET — Financial report: collected, projected, margins
 * Params: ?fecha_inicio=&fecha_fin=&export=csv
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$today = date('Y-m-d');
$fechaInicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fechaFin    = $_GET['fecha_fin'] ?? $today;
$export      = $_GET['export'] ?? '';

$safeScalar = function($sql, $params = [], $default = 0) use ($pdo) {
    try { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchColumn(); }
    catch (Throwable $e) { return $default; }
};
$safeAll = function($sql, $params = []) use ($pdo) {
    try { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { return []; }
};

// Collected revenue (from transacciones)
$ingresoContado = (float)$safeScalar(
    "SELECT COALESCE(SUM(total),0) FROM transacciones WHERE DATE(freg) BETWEEN ? AND ?",
    [$fechaInicio, $fechaFin]
);

// Collected from cycles
$ingresoCredito = (float)$safeScalar(
    "SELECT COALESCE(SUM(monto),0) FROM ciclos_pago
     WHERE estado IN ('paid_auto','paid_manual') AND DATE(fecha_pago) BETWEEN ? AND ?",
    [$fechaInicio, $fechaFin]
);

// Projected revenue (pending cycles next 30 days)
$proyectado = (float)$safeScalar(
    "SELECT COALESCE(SUM(monto),0) FROM ciclos_pago
     WHERE estado='pending' AND fecha_vencimiento BETWEEN ? AND DATE_ADD(?, INTERVAL 30 DAY)",
    [$today, $today]
);

// Overdue amount
$montoVencido = (float)$safeScalar(
    "SELECT COALESCE(SUM(monto),0) FROM ciclos_pago WHERE estado='overdue'"
);

// Daily collection trend
$tendencia = $safeAll(
    "SELECT DATE(fecha_pago) as fecha,
            COALESCE(SUM(monto),0) as cobrado,
            COUNT(*) as pagos
     FROM ciclos_pago
     WHERE estado IN ('paid_auto','paid_manual') AND DATE(fecha_pago) BETWEEN ? AND ?
     GROUP BY DATE(fecha_pago) ORDER BY fecha ASC",
    [$fechaInicio, $fechaFin]
);

if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=reporte_financiero_' . $fechaInicio . '_' . $fechaFin . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Concepto','Monto']);
    fputcsv($out, ['Ingresos contado/MSI', $ingresoContado]);
    fputcsv($out, ['Cobros de crédito', $ingresoCredito]);
    fputcsv($out, ['Proyección 30 días', $proyectado]);
    fputcsv($out, ['Monto vencido', $montoVencido]);
    fputcsv($out, []);
    fputcsv($out, ['Fecha','Cobrado','Pagos']);
    foreach ($tendencia as $t) { fputcsv($out, [$t['fecha'], $t['cobrado'], $t['pagos']]); }
    fclose($out);
    exit;
}

adminJsonOut([
    'ok'               => true,
    'fecha_inicio'     => $fechaInicio,
    'fecha_fin'        => $fechaFin,
    'ingreso_contado'  => $ingresoContado,
    'ingreso_credito'  => $ingresoCredito,
    'total_cobrado'    => $ingresoContado + $ingresoCredito,
    'proyectado_30d'   => $proyectado,
    'monto_vencido'    => $montoVencido,
    'tendencia'        => $tendencia,
]);
