<?php
/**
 * GET — List all Puntos Voltika
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$pdo = getDB();
$rows = $pdo->query("SELECT pv.*,
    (SELECT COUNT(*) FROM inventario_motos m WHERE m.punto_voltika_id=pv.id AND m.estado NOT IN ('entregada','retenida')) as inventario_actual,
    (SELECT COUNT(*) FROM inventario_motos m WHERE m.punto_voltika_id=pv.id AND m.estado='lista_para_entrega') as listas_entrega,
    (SELECT COUNT(*) FROM envios e WHERE e.punto_destino_id=pv.id AND e.estado IN ('lista_para_enviar','enviada')) as envios_pendientes
    FROM puntos_voltika pv ORDER BY pv.nombre")->fetchAll(PDO::FETCH_ASSOC);

adminJsonOut(['puntos' => $rows]);
