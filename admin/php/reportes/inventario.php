<?php
/**
 * GET — Inventory report: stock by model, location, turnover
 * Params: ?export=csv
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$pdo = getDB();
$today = date('Y-m-d');

$safeAll = function($sql, $params = []) use ($pdo) {
    try { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { return []; }
};
$safeScalar = function($sql, $params = [], $default = 0) use ($pdo) {
    try { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchColumn(); }
    catch (Throwable $e) { return $default; }
};

// Stock by model
$porModelo = $safeAll(
    "SELECT modelo,
            SUM(estado IN ('recibida','lista_para_entrega')) as disponible,
            SUM(cliente_nombre IS NOT NULL AND cliente_nombre<>'' AND estado NOT IN ('entregada')) as reservado,
            SUM(estado='entregada') as entregado,
            SUM(estado = 'por_llegar') as en_transito,
            COUNT(*) as total
     FROM inventario_motos
     WHERE modelo IS NOT NULL AND modelo <> ''
     GROUP BY modelo ORDER BY total DESC"
);

// Stock by location (punto)
$porPunto = $safeAll(
    "SELECT COALESCE(punto_nombre, 'CEDIS / Sin asignar') as ubicacion,
            SUM(estado IN ('recibida','lista_para_entrega')) as disponible,
            SUM(estado='entregada') as entregado,
            COUNT(*) as total
     FROM inventario_motos
     GROUP BY punto_nombre ORDER BY total DESC"
);

// Turnover: delivered in last 30 days vs current stock
$entregadas30 = (int)$safeScalar(
    "SELECT COUNT(*) FROM inventario_motos WHERE estado='entregada' AND fecha_estado >= DATE_SUB(?, INTERVAL 30 DAY)",
    [$today]
);
$stockActual = (int)$safeScalar(
    "SELECT COUNT(*) FROM inventario_motos WHERE estado IN ('recibida','lista_para_entrega')"
);
$rotacion = ($stockActual > 0) ? round($entregadas30 / $stockActual, 2) : 0;

// Summary
$totalUnidades = (int)$safeScalar("SELECT COUNT(*) FROM inventario_motos");
$totalDisponible = (int)$safeScalar("SELECT COUNT(*) FROM inventario_motos WHERE estado IN ('recibida','lista_para_entrega')");
$totalEntregado = (int)$safeScalar("SELECT COUNT(*) FROM inventario_motos WHERE estado='entregada'");

$export = $_GET['export'] ?? '';
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=reporte_inventario_' . $today . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Modelo','Disponible','Reservado','Entregado','En Tránsito','Total']);
    foreach ($porModelo as $m) {
        fputcsv($out, [$m['modelo'], $m['disponible'], $m['reservado'], $m['entregado'], $m['en_transito'], $m['total']]);
    }
    fclose($out);
    exit;
}

adminJsonOut([
    'ok'               => true,
    'total_unidades'   => $totalUnidades,
    'total_disponible' => $totalDisponible,
    'total_entregado'  => $totalEntregado,
    'rotacion_30d'     => $rotacion,
    'entregadas_30d'   => $entregadas30,
    'por_modelo'       => $porModelo,
    'por_ubicacion'    => $porPunto,
]);
