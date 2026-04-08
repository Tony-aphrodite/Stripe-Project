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

    // Recalculate dias_en_paso for all active motos of this dealer (including unassigned)
    $pdo->prepare("
        UPDATE inventario_motos
        SET dias_en_paso = DATEDIFF(NOW(), fecha_estado)
        WHERE activo = 1
          AND (dealer_id = ? OR dealer_id IS NULL)
          AND estado NOT IN ('entregada','retenida')
          AND fecha_estado IS NOT NULL
    ")->execute([$dealer['id']]);

    // Fetch motos: admin/cedis see all; dealer sees only their punto
    $isAdmin = in_array($dealer['rol'], ['admin', 'cedis']);
    $filterClause = $isAdmin
        ? '1=1'
        : '(m.dealer_id = ? OR m.dealer_id IS NULL OR m.punto_id = ?)';
    $filterParams = $isAdmin ? [] : [$dealer['id'], $dealer['punto_id'] ?? ''];

    $stmt = $pdo->prepare("
        SELECT m.id, m.vin, m.vin_display, m.modelo, m.color, m.tipo_asignacion, m.estado,
               m.cliente_nombre, m.cliente_email, m.cliente_telefono, m.pedido_num,
               m.pago_estado, m.dias_en_paso, m.notas, m.log_estados,
               m.fecha_llegada, m.fecha_estado, m.precio_venta, m.punto_nombre,
               m.stripe_payment_status, m.stripe_pi
        FROM inventario_motos m
        WHERE m.activo = 1
          AND $filterClause
          AND m.estado NOT IN ('entregada')
        ORDER BY
          FIELD(m.estado,'por_validar_entrega','lista_para_entrega','en_ensamble',
                       'por_ensamblar','recibida','retenida','por_llegar'),
          m.dias_en_paso DESC
    ");
    $stmt->execute($filterParams);
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
