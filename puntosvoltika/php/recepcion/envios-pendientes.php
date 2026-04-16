<?php
/**
 * GET — List pending shipments arriving to this point
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$pdo = getDB();
$stmt = $pdo->prepare("SELECT e.*, m.vin, m.vin_display, m.modelo, m.color,
    m.cliente_nombre, m.cliente_telefono, m.cliente_email, m.pedido_num
    FROM envios e
    JOIN inventario_motos m ON m.id=e.moto_id
    WHERE e.punto_destino_id=? AND e.estado IN ('lista_para_enviar','enviada')
    ORDER BY e.freg DESC");
$stmt->execute([$ctx['punto_id']]);

puntoJsonOut(['envios' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
