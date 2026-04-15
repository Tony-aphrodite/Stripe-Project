<?php
/**
 * GET — Sales report
 * Params: ?tipo=diario|semanal|mensual&fecha_inicio=&fecha_fin=&modelo=&export=csv
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$pdo = getDB();
$today = date('Y-m-d');
$tipo        = $_GET['tipo'] ?? 'mensual';
$modelo      = $_GET['modelo'] ?? '';
$fechaInicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fechaFin    = $_GET['fecha_fin'] ?? $today;
$export      = $_GET['export'] ?? '';

$where = "DATE(t.freg) BETWEEN ? AND ?";
$params = [$fechaInicio, $fechaFin];
if ($modelo) { $where .= " AND t.modelo = ?"; $params[] = $modelo; }

// Group by expression
$groupExpr = "DATE(t.freg)";
$labelExpr = "DATE(t.freg) as periodo";
if ($tipo === 'semanal') {
    $groupExpr = "YEARWEEK(t.freg, 1)";
    $labelExpr = "CONCAT(YEAR(t.freg),'-S',LPAD(WEEK(t.freg,1),2,'0')) as periodo";
} elseif ($tipo === 'mensual') {
    $groupExpr = "DATE_FORMAT(t.freg, '%Y-%m')";
    $labelExpr = "DATE_FORMAT(t.freg, '%Y-%m') as periodo";
}

try {
    $sql = "SELECT {$labelExpr},
                   COUNT(*) as unidades,
                   COALESCE(SUM(t.total),0) as ingresos,
                   COALESCE(AVG(t.total),0) as ticket_promedio,
                   COUNT(DISTINCT t.modelo) as modelos
            FROM transacciones t
            WHERE {$where}
            GROUP BY {$groupExpr}
            ORDER BY periodo ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('reportes/ventas: ' . $e->getMessage());
    $rows = [];
}

// Detail by model
try {
    $sqlModelo = "SELECT t.modelo, COUNT(*) as unidades, COALESCE(SUM(t.total),0) as ingresos
                  FROM transacciones t WHERE {$where}
                  GROUP BY t.modelo ORDER BY unidades DESC";
    $stmt2 = $pdo->prepare($sqlModelo);
    $stmt2->execute($params);
    $porModelo = $stmt2->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $porModelo = []; }

if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=reporte_ventas_' . $fechaInicio . '_' . $fechaFin . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Periodo','Unidades','Ingresos','Ticket Promedio','Modelos']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['periodo'], $r['unidades'], $r['ingresos'], round($r['ticket_promedio'],2), $r['modelos']]);
    }
    fclose($out);
    exit;
}

adminJsonOut([
    'ok'           => true,
    'tipo'         => $tipo,
    'fecha_inicio' => $fechaInicio,
    'fecha_fin'    => $fechaFin,
    'periodos'     => $rows,
    'por_modelo'   => $porModelo,
]);
