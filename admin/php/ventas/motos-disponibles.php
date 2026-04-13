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

if (!empty($_GET['modelo'])) {
    $where[]  = "m.modelo = ?";
    $params[] = $_GET['modelo'];
}
if (!empty($_GET['color'])) {
    $where[]  = "m.color = ?";
    $params[] = $_GET['color'];
}

$sql = "SELECT m.id, m.vin, m.vin_display, m.modelo, m.color, m.estado,
               m.fecha_llegada, m.freg,
               pv.nombre AS punto_nombre
        FROM inventario_motos m
        LEFT JOIN puntos_voltika pv ON pv.id = m.punto_voltika_id
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
