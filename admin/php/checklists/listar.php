<?php
/**
 * GET — List motos with their checklist completion status
 * Optional filters: ?estado=&vin=&page=
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$pdo = getDB();

$where = ['1=1'];
$params = [];

if (!empty($_GET['vin'])) {
    $where[] = "(m.vin LIKE ? OR m.vin_display LIKE ?)";
    $params[] = '%' . $_GET['vin'] . '%';
    $params[] = '%' . $_GET['vin'] . '%';
}
// Filter by checklist progress
$filtro = $_GET['filtro'] ?? '';
if ($filtro === 'sin_origen') {
    $where[] = "co.id IS NULL";
} elseif ($filtro === 'con_origen') {
    // "Real" completed: completado=1 AND key items also marked
    $where[] = "co.completado = 1 AND COALESCE(co.frame_completo,0)=1 AND COALESCE(co.validacion_final,0)=1";
} elseif ($filtro === 'origen_forzado') {
    // Force-completed: completado=1 but key items still 0
    $where[] = "co.completado = 1 AND (COALESCE(co.frame_completo,0)=0 OR COALESCE(co.validacion_final,0)=0)";
} elseif ($filtro === 'sin_ensamble') {
    $where[] = "co.completado = 1 AND ce.id IS NULL";
} elseif ($filtro === 'con_ensamble') {
    $where[] = "ce.completado = 1";
} elseif ($filtro === 'completos') {
    $where[] = "co.completado = 1 AND COALESCE(co.frame_completo,0)=1 AND ce.completado = 1 AND cv.completado = 1";
}

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// co_force = 1 when completado=1 but representative items are still 0
// (i.e. checklist was force-completed via bulk-complete button without
// real inspection). Used to show "Pendiente inspección" instead of "Completo".
$sql = "
    SELECT m.id, m.vin, m.vin_display, m.modelo, m.color, m.estado, m.anio_modelo,
           m.cliente_nombre, m.pedido_num,
           pv.nombre AS punto_nombre,
           co.id AS co_id, co.completado AS co_ok, co.freg AS co_fecha,
           CASE WHEN co.completado=1 AND (COALESCE(co.frame_completo,0)=0 OR COALESCE(co.validacion_final,0)=0)
                THEN 1 ELSE 0 END AS co_force,
           ce.id AS ce_id, ce.completado AS ce_ok, ce.fase_actual AS ce_fase, ce.freg AS ce_fecha,
           cv.id AS cv_id, cv.completado AS cv_ok, cv.fase_actual AS cv_fase, cv.freg AS cv_fecha
    FROM inventario_motos m
    LEFT JOIN puntos_voltika pv ON pv.id = m.punto_voltika_id
    LEFT JOIN (SELECT moto_id, id, completado, freg, frame_completo, validacion_final FROM checklist_origen WHERE id IN (SELECT MAX(id) FROM checklist_origen GROUP BY moto_id)) co ON co.moto_id = m.id
    LEFT JOIN (SELECT moto_id, id, completado, fase_actual, freg FROM checklist_ensamble WHERE id IN (SELECT MAX(id) FROM checklist_ensamble GROUP BY moto_id)) ce ON ce.moto_id = m.id
    LEFT JOIN (SELECT moto_id, id, completado, fase_actual, freg FROM checklist_entrega_v2 WHERE id IN (SELECT MAX(id) FROM checklist_entrega_v2 GROUP BY moto_id)) cv ON cv.moto_id = m.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY m.fmod DESC
    LIMIT $limit OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Counts
$cntSql = "
    SELECT COUNT(*) FROM inventario_motos m
    LEFT JOIN (SELECT moto_id, id, completado FROM checklist_origen WHERE id IN (SELECT MAX(id) FROM checklist_origen GROUP BY moto_id)) co ON co.moto_id = m.id
    LEFT JOIN (SELECT moto_id, id, completado FROM checklist_ensamble WHERE id IN (SELECT MAX(id) FROM checklist_ensamble GROUP BY moto_id)) ce ON ce.moto_id = m.id
    LEFT JOIN (SELECT moto_id, id, completado FROM checklist_entrega_v2 WHERE id IN (SELECT MAX(id) FROM checklist_entrega_v2 GROUP BY moto_id)) cv ON cv.moto_id = m.id
    WHERE " . implode(' AND ', $where);
$cStmt = $pdo->prepare($cntSql);
$cStmt->execute($params);
$total = (int)$cStmt->fetchColumn();

// Summary KPIs — distinguish real-completed vs force-completed origen
$kpi = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN co.completado=1 AND COALESCE(co.frame_completo,0)=1 AND COALESCE(co.validacion_final,0)=1 THEN 1 ELSE 0 END) AS con_origen,
        SUM(CASE WHEN co.completado=1 AND (COALESCE(co.frame_completo,0)=0 OR COALESCE(co.validacion_final,0)=0) THEN 1 ELSE 0 END) AS origen_forzado,
        SUM(CASE WHEN ce.completado=1 THEN 1 ELSE 0 END) AS con_ensamble,
        SUM(CASE WHEN cv.completado=1 THEN 1 ELSE 0 END) AS con_entrega,
        SUM(CASE WHEN co.completado=1 AND COALESCE(co.frame_completo,0)=1 AND ce.completado=1 AND cv.completado=1 THEN 1 ELSE 0 END) AS completos
    FROM inventario_motos m
    LEFT JOIN (SELECT moto_id, completado, frame_completo, validacion_final FROM checklist_origen WHERE id IN (SELECT MAX(id) FROM checklist_origen GROUP BY moto_id)) co ON co.moto_id = m.id
    LEFT JOIN (SELECT moto_id, completado FROM checklist_ensamble WHERE id IN (SELECT MAX(id) FROM checklist_ensamble GROUP BY moto_id)) ce ON ce.moto_id = m.id
    LEFT JOIN (SELECT moto_id, completado FROM checklist_entrega_v2 WHERE id IN (SELECT MAX(id) FROM checklist_entrega_v2 GROUP BY moto_id)) cv ON cv.moto_id = m.id
")->fetch(PDO::FETCH_ASSOC);

adminJsonOut([
    'ok'      => true,
    'motos'   => $rows,
    'total'   => $total,
    'page'    => $page,
    'pages'   => ceil($total / $limit),
    'resumen' => $kpi,
]);
