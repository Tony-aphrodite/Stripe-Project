<?php
/**
 * Voltika - Public Inventory Availability Check
 * GET ?modelo=M05&color=negro  → available units for that model/color
 * GET ?modelo=M05              → all colors available for that model
 * GET                          → all available units grouped by model+color
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inventory-utils.php';

try {
    $pdo    = getDB();
    $modelo = trim($_GET['modelo'] ?? '');
    $color  = trim($_GET['color']  ?? '');

    $rows = contarDisponibles($pdo, $modelo, $color);

    // Build a convenient lookup map: modelo → color → count
    $map = [];
    foreach ($rows as $r) {
        $map[$r['modelo']][$r['color']] = (int)$r['disponibles'];
    }

    $result = [
        'ok'          => true,
        'disponibles' => $rows,
        'mapa'        => $map,
        'total'       => array_sum(array_column($rows, 'disponibles')),
    ];

    // Debug mode: ?debug=1 shows why motos are filtered out
    if (!empty($_GET['debug']) && $modelo) {
        $dbg = $pdo->prepare("
            SELECT m.id, m.vin, m.modelo, m.color, m.estado, m.activo,
                   m.pedido_num, m.cliente_email, m.bloqueado_venta,
                   (SELECT co.completado FROM checklist_origen co WHERE co.moto_id = m.id ORDER BY co.id DESC LIMIT 1) AS checklist_completado
            FROM inventario_motos m
            WHERE LOWER(TRIM(m.modelo)) = LOWER(TRIM(?)) AND m.activo = 1
        ");
        $dbg->execute([$modelo]);
        $result['debug_motos'] = $dbg->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno']);
}
