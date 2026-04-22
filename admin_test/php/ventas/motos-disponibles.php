<?php
/**
 * GET — List available bikes for manual assignment
 * Params: ?modelo=M05&color=gris  (optional filters)
 * Returns bikes that have NO customer assigned (pedido_num IS NULL)
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$pdo = getDB();

$where  = [
    "m.activo = 1",
    "(m.pedido_num IS NULL OR m.pedido_num = '')",
    "(m.cliente_email IS NULL OR m.cliente_email = '')",
    "m.vin NOT REGEXP '^VK-[A-Z0-9]+-[0-9]+-[a-f0-9]+'",
    "m.estado IN ('recibida','lista_para_entrega')",
];
$params = [];

// Include CEDIS stock + optional punto consignación stock
$puntoId = (int)($_GET['punto_id'] ?? 0);
if ($puntoId > 0) {
    $where[] = "((m.punto_voltika_id IS NULL OR m.punto_voltika_id = 0) OR (m.punto_voltika_id = ? AND m.tipo_asignacion = 'consignacion'))";
    $params[] = $puntoId;
} else {
    $where[] = "(m.punto_voltika_id IS NULL OR m.punto_voltika_id = 0)";
}

if (!empty($_GET['modelo'])) {
    $where[]  = "m.modelo = ?";
    $params[] = $_GET['modelo'];
}
if (!empty($_GET['color'])) {
    $where[]  = "m.color = ?";
    $params[] = $_GET['color'];
}

// co_force: completado=1 but key inspection items still at 0 (bulk-force-completed
// without actual physical inspection). Helps the assignment modal flag bikes
// that need proper checklist review before handover.
$sql = "SELECT m.id, m.vin, m.vin_display, m.modelo, m.color, m.estado,
               m.fecha_llegada, m.freg,
               pv.nombre AS punto_nombre,
               co.id AS co_id,
               COALESCE(co.completado, 0) AS co_ok,
               CASE WHEN co.completado = 1
                     AND (COALESCE(co.frame_completo,0) = 0
                          OR COALESCE(co.validacion_final,0) = 0)
                    THEN 1 ELSE 0 END AS co_force
        FROM inventario_motos m
        LEFT JOIN puntos_voltika pv ON pv.id = m.punto_voltika_id
        LEFT JOIN (
            SELECT moto_id, id, completado, frame_completo, validacion_final
            FROM checklist_origen
            WHERE id IN (SELECT MAX(id) FROM checklist_origen GROUP BY moto_id)
        ) co ON co.moto_id = m.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY m.fecha_llegada ASC, m.freg ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

adminJsonOut([
    'ok'    => true,
    'motos' => $rows,
    'total' => count($rows),
]);
