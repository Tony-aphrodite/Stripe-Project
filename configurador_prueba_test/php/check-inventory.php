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
    $puntoId = 0;

    // If a punto referido code is provided, include its consignación inventory
    $referido = trim($_GET['referido'] ?? '');
    if ($referido) {
        // Detect which column exists for punto referral codes
        try {
            $pvCols = $pdo->query("SHOW COLUMNS FROM puntos_voltika")->fetchAll(PDO::FETCH_COLUMN);
            $conditions = [];
            $rParams = [];
            if (in_array('codigo_venta', $pvCols, true)) {
                $conditions[] = "UPPER(codigo_venta) = UPPER(?)";
                $rParams[] = $referido;
            }
            if (in_array('codigo_referido', $pvCols, true)) {
                $conditions[] = "UPPER(codigo_referido) = UPPER(?)";
                $rParams[] = $referido;
            }
            if ($conditions) {
                $rStmt = $pdo->prepare("SELECT id FROM puntos_voltika WHERE (" . implode(' OR ', $conditions) . ") AND activo = 1 LIMIT 1");
                $rStmt->execute($rParams);
                $puntoId = (int)($rStmt->fetchColumn() ?: 0);
            }
        } catch (Throwable $e) {
            error_log('check-inventory referido lookup: ' . $e->getMessage());
        }
    }

    $rows = contarDisponibles($pdo, $modelo, $color, $puntoId);

    // Build a convenient lookup map: modelo → color → count
    $map = [];
    foreach ($rows as $r) {
        $map[$r['modelo']][$r['color']] = (int)$r['disponibles'];
    }

    echo json_encode([
        'ok'          => true,
        'disponibles' => $rows,
        'mapa'        => $map,
        'total'       => array_sum(array_column($rows, 'disponibles')),
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno']);
}
