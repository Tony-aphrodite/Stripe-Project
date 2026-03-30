<?php
/**
 * Voltika Admin - Dashboard data
 * GET /php/admin-dashboard.php → { dealer, stats, voltika_entrega[], consignacion[] }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-auth.php';

$dealer = requireDealerAuth();

try {
    $pdo = getDB();

    // Recalculate dias_en_paso for all active motos of this dealer
    $pdo->prepare("
        UPDATE inventario_motos
        SET dias_en_paso = DATEDIFF(NOW(), fecha_estado)
        WHERE activo = 1
          AND dealer_id = ?
          AND estado NOT IN ('entregada','retenida')
          AND fecha_estado IS NOT NULL
    ")->execute([$dealer['id']]);

    // Fetch motos for this dealer
    $stmt = $pdo->prepare("
        SELECT id, vin, vin_display, modelo, color, tipo_asignacion, estado,
               cliente_nombre, cliente_email, cliente_telefono, pedido_num,
               pago_estado, dias_en_paso, notas, log_estados,
               fecha_llegada, fecha_estado, precio_venta
        FROM inventario_motos
        WHERE activo = 1
          AND dealer_id = ?
          AND estado NOT IN ('entregada')
        ORDER BY
          FIELD(estado,'por_validar_entrega','lista_para_entrega','en_ensamble',
                       'por_ensamblar','recibida','retenida','por_llegar'),
          dias_en_paso DESC
    ");
    $stmt->execute([$dealer['id']]);
    $motos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Decode log_estados JSON
    foreach ($motos as &$m) {
        $m['log_estados']   = $m['log_estados'] ? json_decode($m['log_estados'], true) : [];
        $m['dias_alerta']   = ($m['dias_en_paso'] > 1);
        $m['id']            = (int)$m['id'];
        $m['dias_en_paso']  = (int)$m['dias_en_paso'];
    }
    unset($m);

    $voltika   = array_values(array_filter($motos, fn($m) => $m['tipo_asignacion'] === 'voltika_entrega'));
    $consig    = array_values(array_filter($motos, fn($m) => $m['tipo_asignacion'] === 'consignacion'));

    // Stats
    $stats = [
        'entregas_pendientes' => count(array_filter($voltika, fn($m) => in_array($m['estado'], ['lista_para_entrega','por_validar_entrega']))),
        'unidades_en_piso'    => count($consig),
        'en_ensamble'         => count(array_filter($motos, fn($m) => $m['estado'] === 'en_ensamble')),
        'total_activas'       => count($motos),
    ];

    echo json_encode([
        'ok'             => true,
        'dealer'         => $dealer,
        'stats'          => $stats,
        'voltika_entrega'=> $voltika,
        'consignacion'   => $consig,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('Voltika dashboard error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error al cargar dashboard']);
}
