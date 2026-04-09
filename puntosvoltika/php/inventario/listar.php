<?php
/**
 * GET — List inventory filtered BY the current point only
 * Dual inventory: entrega vs venta
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$pdo = getDB();
$pid = $ctx['punto_id'];

// All motos assigned to this point
$stmt = $pdo->prepare("SELECT m.*,
    (SELECT estado FROM envios WHERE moto_id=m.id ORDER BY freg DESC LIMIT 1) as envio_estado,
    (SELECT id FROM recepcion_punto WHERE moto_id=m.id ORDER BY freg DESC LIMIT 1) as recepcion_id
    FROM inventario_motos m
    WHERE m.punto_voltika_id=?
    ORDER BY m.fmod DESC");
$stmt->execute([$pid]);
$motos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Split into two inventories per rule
$paraEntrega = []; // already assigned to an order (tiene cliente)
$paraVenta   = []; // stock libre, sin cliente
foreach ($motos as $m) {
    if (!empty($m['cliente_nombre']) || !empty($m['pedido_num'])) {
        $paraEntrega[] = $m;
    } else {
        $paraVenta[] = $m;
    }
}

puntoJsonOut([
    'punto_id' => $pid,
    'inventario_entrega' => $paraEntrega,
    'inventario_venta'   => $paraVenta,
    'total' => count($motos)
]);
