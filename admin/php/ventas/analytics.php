<?php
/**
 * GET — Sales analytics with filters and aggregation
 * Params: ?periodo=day|week|month&modelo=&ciudad=&canal=&tipo_pago=&fecha_inicio=&fecha_fin=
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador','dealer']);

$pdo = getDB();
$today = date('Y-m-d');

$periodo     = $_GET['periodo'] ?? 'month';
$modelo      = $_GET['modelo'] ?? '';
$ciudad      = $_GET['ciudad'] ?? '';
$canal       = $_GET['canal'] ?? '';
$tipoPago    = $_GET['tipo_pago'] ?? '';
$fechaInicio = $_GET['fecha_inicio'] ?? '';
$fechaFin    = $_GET['fecha_fin'] ?? '';

// Date range
if (!$fechaInicio) {
    switch ($periodo) {
        case 'day':   $fechaInicio = $today; break;
        case 'week':  $fechaInicio = date('Y-m-d', strtotime('monday this week')); break;
        default:      $fechaInicio = date('Y-m-01'); break;
    }
}
if (!$fechaFin) $fechaFin = $today;

// Build WHERE clause
$where = "DATE(t.freg) BETWEEN ? AND ?";
$params = [$fechaInicio, $fechaFin];

if ($modelo) {
    $where .= " AND t.modelo = ?";
    $params[] = $modelo;
}
if ($tipoPago) {
    $where .= " AND t.tpago = ?";
    $params[] = $tipoPago;
}

$safeAll = function($sql, $params = []) use ($pdo) {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('analytics: ' . $e->getMessage()); return []; }
};
$safeRow = function($sql, $params = []) use ($pdo) {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
};

// ── Summary KPIs ──
$summary = $safeRow(
    "SELECT COUNT(*) as total_ventas,
            COALESCE(SUM(total),0) as ingresos_totales,
            COALESCE(AVG(total),0) as ticket_promedio,
            COUNT(DISTINCT modelo) as modelos_vendidos
     FROM transacciones t WHERE {$where}",
    $params
);

// ── Units sold per model ──
$porModelo = $safeAll(
    "SELECT t.modelo, COUNT(*) as unidades, COALESCE(SUM(t.total),0) as ingresos
     FROM transacciones t WHERE {$where}
     GROUP BY t.modelo ORDER BY unidades DESC",
    $params
);

// ── Sales per payment type ──
$porTipoPago = $safeAll(
    "SELECT t.tpago as tipo_pago, COUNT(*) as unidades, COALESCE(SUM(t.total),0) as ingresos
     FROM transacciones t WHERE {$where}
     GROUP BY t.tpago ORDER BY unidades DESC",
    $params
);

// ── Top selling models ──
$topModelos = $safeAll(
    "SELECT t.modelo, t.color, COUNT(*) as unidades, COALESCE(SUM(t.total),0) as ingresos
     FROM transacciones t WHERE {$where}
     GROUP BY t.modelo, t.color ORDER BY unidades DESC LIMIT 10",
    $params
);

// ── Sales per point (punto) ──
$porPunto = $safeAll(
    "SELECT COALESCE(m.punto_nombre, 'Sin punto') as punto, COUNT(*) as unidades, COALESCE(SUM(t.total),0) as ingresos
     FROM transacciones t
     LEFT JOIN inventario_motos m ON m.stripe_pi = t.stripe_pi
     WHERE {$where}
     GROUP BY punto ORDER BY unidades DESC",
    $params
);

// ── Daily trend for the period ──
$tendencia = $safeAll(
    "SELECT DATE(t.freg) as fecha, COUNT(*) as unidades, COALESCE(SUM(t.total),0) as ingresos
     FROM transacciones t WHERE {$where}
     GROUP BY DATE(t.freg) ORDER BY fecha ASC",
    $params
);

// ── Comparison vs previous period ──
$periodDays = max(1, (strtotime($fechaFin) - strtotime($fechaInicio)) / 86400 + 1);
$prevInicio = date('Y-m-d', strtotime($fechaInicio . " - {$periodDays} days"));
$prevFin    = date('Y-m-d', strtotime($fechaInicio . " - 1 day"));

$prevSummary = $safeRow(
    "SELECT COUNT(*) as total_ventas, COALESCE(SUM(total),0) as ingresos_totales
     FROM transacciones WHERE DATE(freg) BETWEEN ? AND ?",
    [$prevInicio, $prevFin]
);

// ── Available filter values ──
$modelos = $safeAll("SELECT DISTINCT modelo FROM transacciones WHERE modelo IS NOT NULL AND modelo <> '' ORDER BY modelo");
$tiposPago = $safeAll("SELECT DISTINCT tpago FROM transacciones WHERE tpago IS NOT NULL AND tpago <> '' ORDER BY tpago");

adminJsonOut([
    'ok'             => true,
    'periodo'        => $periodo,
    'fecha_inicio'   => $fechaInicio,
    'fecha_fin'      => $fechaFin,
    'summary'        => $summary,
    'por_modelo'     => $porModelo,
    'por_tipo_pago'  => $porTipoPago,
    'top_modelos'    => $topModelos,
    'por_punto'      => $porPunto,
    'tendencia'      => $tendencia,
    'comparacion'    => [
        'periodo_anterior' => $prevSummary,
        'prev_inicio'      => $prevInicio,
        'prev_fin'         => $prevFin,
    ],
    'filtros' => [
        'modelos'    => array_column($modelos, 'modelo'),
        'tipos_pago' => array_column($tiposPago, 'tpago'),
    ],
]);
