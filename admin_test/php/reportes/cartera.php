<?php
/**
 * GET — Portfolio report: current vs overdue, aging buckets, recovery rate
 * Params: ?export=csv
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$pdo = getDB();
$today = date('Y-m-d');

$safeScalar = function($sql, $params = [], $default = 0) use ($pdo) {
    try { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchColumn(); }
    catch (Throwable $e) { return $default; }
};

// Detect status column
$subStatusCol = 'status';
try {
    $cols = $pdo->query("SHOW COLUMNS FROM subscripciones_credito")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('estado', $cols)) $subStatusCol = 'estado';
} catch (Throwable $e) {}

// Active subscriptions
$activeSubs = (int)$safeScalar("SELECT COUNT(*) FROM subscripciones_credito WHERE {$subStatusCol} IN ('activa','active')");

// Total cycles
$totalCiclos = (int)$safeScalar("SELECT COUNT(*) FROM ciclos_pago");
$ciclosPagados = (int)$safeScalar("SELECT COUNT(*) FROM ciclos_pago WHERE estado IN ('paid_auto','paid_manual')");
$ciclosPendientes = (int)$safeScalar("SELECT COUNT(*) FROM ciclos_pago WHERE estado='pending'");
$ciclosOverdue = (int)$safeScalar("SELECT COUNT(*) FROM ciclos_pago WHERE estado='overdue'");

// Amounts
$montoPagado = (float)$safeScalar("SELECT COALESCE(SUM(monto),0) FROM ciclos_pago WHERE estado IN ('paid_auto','paid_manual')");
$montoOverdue = (float)$safeScalar("SELECT COALESCE(SUM(monto),0) FROM ciclos_pago WHERE estado='overdue'");
$montoPendiente = (float)$safeScalar("SELECT COALESCE(SUM(monto),0) FROM ciclos_pago WHERE estado='pending'");

// Aging buckets
$bucket1_7 = (int)$safeScalar("SELECT COUNT(*) FROM ciclos_pago WHERE estado='overdue' AND DATEDIFF(?,fecha_vencimiento) BETWEEN 1 AND 7", [$today]);
$bucket8_30 = (int)$safeScalar("SELECT COUNT(*) FROM ciclos_pago WHERE estado='overdue' AND DATEDIFF(?,fecha_vencimiento) BETWEEN 8 AND 30", [$today]);
$bucket30plus = (int)$safeScalar("SELECT COUNT(*) FROM ciclos_pago WHERE estado='overdue' AND DATEDIFF(?,fecha_vencimiento) > 30", [$today]);

$monto1_7 = (float)$safeScalar("SELECT COALESCE(SUM(monto),0) FROM ciclos_pago WHERE estado='overdue' AND DATEDIFF(?,fecha_vencimiento) BETWEEN 1 AND 7", [$today]);
$monto8_30 = (float)$safeScalar("SELECT COALESCE(SUM(monto),0) FROM ciclos_pago WHERE estado='overdue' AND DATEDIFF(?,fecha_vencimiento) BETWEEN 8 AND 30", [$today]);
$monto30plus = (float)$safeScalar("SELECT COALESCE(SUM(monto),0) FROM ciclos_pago WHERE estado='overdue' AND DATEDIFF(?,fecha_vencimiento) > 30", [$today]);

// Recovery rate
$recoveryRate = ($totalCiclos > 0) ? round(($ciclosPagados / $totalCiclos) * 100, 1) : 0;

$export = $_GET['export'] ?? '';
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=reporte_cartera_' . $today . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Concepto','Cantidad','Monto']);
    fputcsv($out, ['Suscripciones activas', $activeSubs, '']);
    fputcsv($out, ['Ciclos pagados', $ciclosPagados, $montoPagado]);
    fputcsv($out, ['Ciclos pendientes', $ciclosPendientes, $montoPendiente]);
    fputcsv($out, ['Ciclos vencidos', $ciclosOverdue, $montoOverdue]);
    fputcsv($out, ['Bucket 1-7 días', $bucket1_7, $monto1_7]);
    fputcsv($out, ['Bucket 8-30 días', $bucket8_30, $monto8_30]);
    fputcsv($out, ['Bucket 30+ días', $bucket30plus, $monto30plus]);
    fputcsv($out, ['Tasa de recuperación', $recoveryRate . '%', '']);
    fclose($out);
    exit;
}

adminJsonOut([
    'ok'               => true,
    'suscripciones'    => $activeSubs,
    'ciclos_pagados'   => $ciclosPagados,
    'ciclos_pendientes'=> $ciclosPendientes,
    'ciclos_overdue'   => $ciclosOverdue,
    'monto_pagado'     => $montoPagado,
    'monto_pendiente'  => $montoPendiente,
    'monto_overdue'    => $montoOverdue,
    'buckets' => [
        ['label' => '1-7 días',  'ciclos' => $bucket1_7,    'monto' => $monto1_7],
        ['label' => '8-30 días', 'ciclos' => $bucket8_30,   'monto' => $monto8_30],
        ['label' => '30+ días',  'ciclos' => $bucket30plus, 'monto' => $monto30plus],
    ],
    'recovery_rate' => $recoveryRate,
]);
