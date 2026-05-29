<?php
/**
 * GET — Collections dashboard data
 * Returns overdue bucketing, failed payments, no-card customers, pending OXXO/transfers
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$pdo = getDB();
$today = date('Y-m-d');

$safeScalar = function($sql, $params = [], $default = 0) use ($pdo) {
    try {
        if ($params) { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchColumn(); }
        return $pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) { return $default; }
};

// Exclude test customers (Voltika Diag fixture) from all collection queries.
// Test markers: phone=5500000000, email contains 'diag-test' or 'voltika.mx'.
$excludeTest = " AND subscripcion_id NOT IN (
    SELECT id FROM subscripciones_credito
    WHERE telefono = '5500000000'
       OR email LIKE '%diag-test%'
       OR LOWER(nombre) LIKE '%voltika diag%'
       OR LOWER(nombre) LIKE '%test%'
) ";

// ── KPIs ──
$cobradoHoy = (float)$safeScalar(
    "SELECT COALESCE(SUM(monto),0) FROM ciclos_pago WHERE estado IN ('paid_auto','paid_manual') AND DATE(fecha_pago)=?" . $excludeTest,
    [$today]
);
$pendientesHoy = (int)$safeScalar(
    "SELECT COUNT(*) FROM ciclos_pago WHERE estado='pending' AND fecha_vencimiento=?" . $excludeTest,
    [$today]
);
$montoPendienteHoy = (float)$safeScalar(
    "SELECT COALESCE(SUM(monto),0) FROM ciclos_pago WHERE estado='pending' AND fecha_vencimiento=?" . $excludeTest,
    [$today]
);
$totalOverdue = (int)$safeScalar("SELECT COUNT(*) FROM ciclos_pago WHERE estado='overdue'" . $excludeTest);

// ── Overdue Buckets ──
$bucket1_7 = (int)$safeScalar(
    "SELECT COUNT(*) FROM ciclos_pago WHERE estado='overdue' AND DATEDIFF(?,fecha_vencimiento) BETWEEN 1 AND 7" . $excludeTest,
    [$today]
);
$bucket8_30 = (int)$safeScalar(
    "SELECT COUNT(*) FROM ciclos_pago WHERE estado='overdue' AND DATEDIFF(?,fecha_vencimiento) BETWEEN 8 AND 30" . $excludeTest,
    [$today]
);
$bucket30plus = (int)$safeScalar(
    "SELECT COUNT(*) FROM ciclos_pago WHERE estado='overdue' AND DATEDIFF(?,fecha_vencimiento) > 30" . $excludeTest,
    [$today]
);

// ── Failed payments (Stripe PI with failed status) ──
$pagosRechazados = (int)$safeScalar(
    "SELECT COUNT(*) FROM ciclos_pago WHERE estado='overdue' AND stripe_payment_intent IS NOT NULL AND stripe_payment_intent <> ''" . $excludeTest
);

// ── Customers without active card ──
$sinTarjeta = (int)$safeScalar(
    "SELECT COUNT(*) FROM subscripciones_credito
     WHERE (stripe_payment_method_id IS NULL OR stripe_payment_method_id='')
     AND telefono != '5500000000'
     AND (email IS NULL OR email NOT LIKE '%diag-test%')
     AND (LOWER(nombre) NOT LIKE '%voltika diag%' AND LOWER(nombre) NOT LIKE '%test%')
     AND " . (function() use ($pdo) {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM subscripciones_credito")->fetchAll(PDO::FETCH_COLUMN);
            return in_array('estado', $cols) ? "estado IN ('activa','active')" : "status IN ('activa','active')";
        } catch (Throwable $e) { return "1=1"; }
    })()
);

// ── Overdue list with customer details ──
$page = max(1, (int)($_GET['page'] ?? 1));
$bucket = $_GET['bucket'] ?? '';
$limit = 50;
$offset = ($page - 1) * $limit;

$where = "c.estado='overdue'";
$params = [];
$orderBy = 'c.fecha_vencimiento ASC';
if ($bucket === '1-7') {
    $where .= " AND DATEDIFF(?,c.fecha_vencimiento) BETWEEN 1 AND 7";
    $params[] = $today;
} elseif ($bucket === '8-30') {
    $where .= " AND DATEDIFF(?,c.fecha_vencimiento) BETWEEN 8 AND 30";
    $params[] = $today;
} elseif ($bucket === '30+') {
    $where .= " AND DATEDIFF(?,c.fecha_vencimiento) > 30";
    $params[] = $today;
} elseif ($bucket === 'pending') {
    $where = "c.estado='pending' AND c.fecha_vencimiento=?";
    $params[] = $today;
} elseif ($bucket === 'next7') {
    // Cycles due in the next 7 days (including today). Helps the collection
    // team plan the upcoming week and see customers like Carlos before their
    // payment is overdue.
    $where = "c.estado='pending' AND c.fecha_vencimiento BETWEEN ? AND DATE_ADD(?, INTERVAL 7 DAY)";
    $params[] = $today;
    $params[] = $today;
    $orderBy = 'c.fecha_vencimiento ASC';
} elseif ($bucket === 'all_pending') {
    $where = "c.estado='pending'";
    $orderBy = 'c.fecha_vencimiento ASC';
} elseif ($bucket === 'paid') {
    // Historical payments (auto-charge or manually marked)
    $where = "c.estado IN ('paid_auto','paid_manual')";
    $orderBy = 'c.fecha_pago DESC, c.id DESC';
}

$rows = [];
try {
    // Filter out test customers from list (same markers as KPIs)
    $testFilter = " AND (s.telefono IS NULL OR s.telefono != '5500000000')"
                . " AND (s.email IS NULL OR s.email NOT LIKE '%diag-test%')"
                . " AND (s.nombre IS NULL OR (LOWER(s.nombre) NOT LIKE '%voltika diag%' AND LOWER(s.nombre) NOT LIKE '%test%'))";
    $sql = "SELECT c.id, c.subscripcion_id, c.cliente_id, c.monto, c.fecha_vencimiento,
                c.estado, c.stripe_payment_intent, c.fecha_pago, c.semana_num,
                COALESCE(cl.nombre, '') as nombre, s.email, s.telefono, s.modelo, s.color,
                s.stripe_customer_id, s.stripe_payment_method_id,
                DATEDIFF('{$today}', c.fecha_vencimiento) as dias_atraso
            FROM ciclos_pago c
            LEFT JOIN subscripciones_credito s ON c.subscripcion_id = s.id
            LEFT JOIN clientes cl ON c.cliente_id = cl.id
            WHERE {$where}{$testFilter}
            ORDER BY {$orderBy}
            LIMIT {$limit} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('cobranza list: ' . $e->getMessage());
}

adminJsonOut([
    'cobrado_hoy'         => $cobradoHoy,
    'pendientes_hoy'      => $pendientesHoy,
    'monto_pendiente_hoy' => $montoPendienteHoy,
    'total_overdue'        => $totalOverdue,
    'bucket_1_7'           => $bucket1_7,
    'bucket_8_30'          => $bucket8_30,
    'bucket_30_plus'       => $bucket30plus,
    'pagos_rechazados'     => $pagosRechazados,
    'sin_tarjeta'          => $sinTarjeta,
    'ciclos'               => $rows,
    'page'                 => $page,
]);
