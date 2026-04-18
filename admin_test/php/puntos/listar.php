<?php
/**
 * GET — List all Puntos Voltika
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$pdo = getDB();
// Inventory breakdown per punto: total, consignación (walk-in pool),
// para entrega web (online orders with cliente assigned), en ensamble, plus
// aging of the oldest unsold consignación unit (days since arrival).
$rows = $pdo->query("SELECT pv.*,
    (SELECT COUNT(*) FROM inventario_motos m WHERE m.punto_voltika_id=pv.id AND m.activo=1 AND m.estado NOT IN ('entregada','retenida')) as inventario_actual,
    (SELECT COUNT(*) FROM inventario_motos m WHERE m.punto_voltika_id=pv.id AND m.estado='lista_para_entrega') as listas_entrega,
    (SELECT COUNT(*) FROM envios e WHERE e.punto_destino_id=pv.id AND e.estado IN ('lista_para_enviar','enviada')) as envios_pendientes,
    (SELECT COUNT(*) FROM inventario_motos m WHERE m.punto_voltika_id=pv.id AND m.activo=1
        AND m.tipo_asignacion='consignacion'
        AND (m.cliente_nombre IS NULL OR m.cliente_nombre='')
        AND (m.pedido_num IS NULL OR m.pedido_num='')
        AND m.estado NOT IN ('entregada','retenida','por_llegar')
    ) as inv_consignacion,
    (SELECT COUNT(*) FROM inventario_motos m WHERE m.punto_voltika_id=pv.id AND m.activo=1
        AND (m.cliente_nombre IS NOT NULL AND m.cliente_nombre<>'')
        AND m.estado IN ('recibida','lista_para_entrega','por_validar_entrega')
    ) as inv_para_entrega,
    (SELECT COUNT(*) FROM inventario_motos m WHERE m.punto_voltika_id=pv.id AND m.activo=1
        AND m.estado IN ('en_ensamble','por_ensamblar')
    ) as inv_en_ensamble,
    (SELECT MAX(DATEDIFF(CURDATE(), COALESCE(m.fecha_estado, m.freg)))
        FROM inventario_motos m WHERE m.punto_voltika_id=pv.id AND m.activo=1
        AND m.tipo_asignacion='consignacion'
        AND (m.cliente_nombre IS NULL OR m.cliente_nombre='')
        AND (m.pedido_num IS NULL OR m.pedido_num='')
        AND m.estado NOT IN ('entregada','retenida','por_llegar')
    ) as aging_max_dias
    FROM puntos_voltika pv ORDER BY pv.nombre")->fetchAll(PDO::FETCH_ASSOC);

adminJsonOut(['puntos' => $rows]);
