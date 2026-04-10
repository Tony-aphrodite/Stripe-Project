<?php
/**
 * GET — List all shipments with filters
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$pdo = getDB();
$where = []; $params = [];
if (!empty($_GET['estado'])) { $where[] = "e.estado=?"; $params[] = $_GET['estado']; }
if (!empty($_GET['punto_id'])) { $where[] = "e.punto_destino_id=?"; $params[] = $_GET['punto_id']; }

$sql = "SELECT e.*, m.vin, m.vin_display, m.modelo, m.color, m.cliente_nombre,
    m.pedido_num,
    pv.nombre AS punto_nombre, pv.ciudad AS punto_ciudad
    FROM envios e
    JOIN inventario_motos m ON m.id=e.moto_id
    LEFT JOIN puntos_voltika pv ON pv.id=e.punto_destino_id";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY e.freg DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
adminJsonOut(['envios' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
