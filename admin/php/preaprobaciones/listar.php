<?php
/**
 * GET — List credit applications (preaprobaciones) with customer info
 * Filters: ?status=PREAPROBADO|CONDICIONAL|NO_VIABLE
 *          ?seguimiento=nuevo|contactado|vendido|descartado
 *          ?search=<email or name>
 *          ?source=real|estimado
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$pdo = getDB();

$status      = trim($_GET['status']      ?? '');
$seguimiento = trim($_GET['seguimiento'] ?? '');
$source      = trim($_GET['source']      ?? '');
$search      = trim($_GET['search']      ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$limit       = min(200, max(20, (int)($_GET['limit'] ?? 50)));
$offset      = ($page - 1) * $limit;

$where = ['1=1'];
$params = [];
if ($status      !== '') { $where[] = 'status = ?';         $params[] = $status; }
if ($seguimiento !== '') { $where[] = 'seguimiento = ?';    $params[] = $seguimiento; }
if ($source      !== '') { $where[] = 'circulo_source = ?'; $params[] = $source; }
if ($search      !== '') {
    $where[] = '(LOWER(email) LIKE ? OR LOWER(nombre) LIKE ? OR LOWER(apellido_paterno) LIKE ? OR telefono LIKE ?)';
    $like = '%' . strtolower($search) . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = '%' . $search . '%';
}
$whereSql = implode(' AND ', $where);

try {
    // Counts (KPIs)
    $kpi = $pdo->query("SELECT
        COUNT(*) AS total,
        SUM(status = 'PREAPROBADO')           AS preaprobado,
        SUM(status = 'CONDICIONAL')           AS condicional,
        SUM(status = 'NO_VIABLE')             AS no_viable,
        SUM(circulo_source = 'real')          AS con_cdc,
        SUM(circulo_source = 'estimado')      AS sin_cdc,
        SUM(seguimiento = 'nuevo')            AS pendiente_seguimiento
        FROM preaprobaciones")->fetch(PDO::FETCH_ASSOC);

    $total = (int)$pdo->prepare("SELECT COUNT(*) FROM preaprobaciones WHERE $whereSql");
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM preaprobaciones WHERE $whereSql");
    $cntStmt->execute($params);
    $totalFiltrado = (int)$cntStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT id, nombre, apellido_paterno, apellido_materno, email, telefono,
               fecha_nacimiento, cp, ciudad, estado,
               modelo, precio_contado, ingreso_mensual,
               pago_semanal, pago_mensual, pti_total,
               score, synth_score, circulo_source,
               enganche_pct, plazo_meses, status,
               enganche_requerido, plazo_max,
               truora_ok, seguimiento, notas_admin, freg
        FROM preaprobaciones
        WHERE $whereSql
        ORDER BY freg DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    adminJsonOut([
        'ok'    => true,
        'kpi'   => $kpi,
        'total' => $totalFiltrado,
        'page'  => $page,
        'pages' => max(1, (int)ceil($totalFiltrado / $limit)),
        'rows'  => $rows,
    ]);
} catch (Throwable $e) {
    error_log('preaprobaciones/listar: ' . $e->getMessage());
    adminJsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
}
