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

// ── KPIs ──
$cobradoHoy = (float)$safeScalar(
    "SELECT COALESCE(SUM(monto),0) FROM ciclos_pago WHERE estado IN ('paid_auto','paid_manual') AND DATE(fecha_pago)=?",
    [$today]
);
$pendientesHoy = (int)$safeScalar(
    "SELECT COUNT(*) FROM ciclos_pago WHERE estado='pending' AND fecha_vencimiento=?",
    [$today]
);
$montoPendienteHoy = (float)$safeScalar(
    "SELECT COALESCE(SUM(monto),0) FROM ciclos_pago WHERE estado='pending' AND fecha_vencimiento=?",
    [$today]
);
$totalOverdue = (int)$safeScalar("SELECT COUNT(*) FROM ciclos_pago WHERE estado='overdue'");

// ── Overdue Buckets ──
$bucket1_7 = (int)$safeScalar(
    "SELECT COUNT(*) FROM ciclos_pago WHERE estado='overdue' AND DATEDIFF(?,fecha_vencimiento) BETWEEN 1 AND 7",
    [$today]
);
$bucket8_30 = (int)$safeScalar(
    "SELECT COUNT(*) FROM ciclos_pago WHERE estado='overdue' AND DATEDIFF(?,fecha_vencimiento) BETWEEN 8 AND 30",
    [$today]
);
$bucket30plus = (int)$safeScalar(
    "SELECT COUNT(*) FROM ciclos_pago WHERE estado='overdue' AND DATEDIFF(?,fecha_vencimiento) > 30",
    [$today]
);

// ── Failed payments (Stripe PI with failed status) ──
$pagosRechazados = (int)$safeScalar(
    "SELECT COUNT(*) FROM ciclos_pago WHERE estado='overdue' AND stripe_payment_intent IS NOT NULL AND stripe_payment_intent <> ''"
);

// ── Customers without active card ──
$sinTarjeta = (int)$safeScalar(
    "SELECT COUNT(*) FROM subscripciones_credito
     WHERE (stripe_payment_method_id IS NULL OR stripe_payment_method_id='')
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
} elseif ($bucket === 'all_pending') {
    $where = "c.estado='pending'";
}

$rows = [];
try {
    $sql = "SELECT c.id, c.subscripcion_id, c.cliente_id, c.monto, c.fecha_vencimiento,
                c.estado, c.stripe_payment_intent, c.fecha_pago, c.semana_num,
                COALESCE(cl.nombre, '') as nombre, s.email, s.telefono, s.modelo, s.color,
                s.stripe_customer_id, s.stripe_payment_method_id,
                DATEDIFF('{$today}', c.fecha_vencimiento) as dias_atraso
            FROM ciclos_pago c
            LEFT JOIN subscripciones_credito s ON c.subscripcion_id = s.id
            LEFT JOIN clientes cl ON c.cliente_id = cl.id
            WHERE {$where}
            ORDER BY c.fecha_vencimiento ASC
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
