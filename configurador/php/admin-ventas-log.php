<?php
/**
 * Voltika Admin - Log de ventas y operaciones
 * GET ?dealer_id=&tipo=&q=&limit=
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
    $pdo    = getDB();
    $where  = ['1=1'];
    $params = [];

    if ($dealer['rol'] !== 'admin') {
        $where[]  = 'v.dealer_id = ?';
        $params[] = $dealer['id'];
    }
    if (!empty($_GET['tipo'])) {
        $where[]  = 'v.tipo = ?';
        $params[] = $_GET['tipo'];
    }
    if (!empty($_GET['q'])) {
        $q = '%' . $_GET['q'] . '%';
        $where[]  = '(v.cliente_nombre LIKE ? OR v.vin LIKE ? OR v.pedido_num LIKE ? OR v.modelo LIKE ?)';
        $params[] = $q; $params[] = $q; $params[] = $q; $params[] = $q;
    }

    $limit  = min(intval($_GET['limit'] ?? 100), 500);
    $offset = intval($_GET['offset'] ?? 0);
    $where  = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT v.*, d.nombre AS dealer_nombre, d.punto_nombre,
               r.nombre AS referido_nombre
        FROM ventas_log v
        LEFT JOIN dealer_usuarios d ON d.id = v.dealer_id
        LEFT JOIN referidos r ON r.id = v.referido_id
        WHERE $where
        ORDER BY v.freg DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Total count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ventas_log v WHERE $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Summary by tipo
    $summary = [];
    $sumStmt = $pdo->prepare("SELECT tipo, COUNT(*) AS cnt, SUM(monto) AS total FROM ventas_log v WHERE $where GROUP BY tipo");
    $sumStmt->execute($params);
    foreach ($sumStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $summary[$s['tipo']] = ['count' => (int)$s['cnt'], 'total' => floatval($s['total'])];
    }

    echo json_encode([
        'ok'      => true,
        'ventas'  => $rows,
        'total'   => $total,
        'summary' => $summary,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('Voltika ventas-log error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error interno']);
}
