<?php
/**
 * Voltika Admin - Referidos
 * GET  → lista de referidos con sus ventas
 * POST { accion: 'agregar'|'actualizar'|'eliminar', ... }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-auth.php';

$dealer = requireDealerAuth();
$pdo = getDB();

// ── GET ───────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query("
        SELECT r.*,
               COUNT(v.id) AS ventas_registradas,
               SUM(v.monto) AS total_ventas
        FROM referidos r
        LEFT JOIN ventas_log v ON v.referido_id = r.id
        WHERE r.activo = 1
        GROUP BY r.id
        ORDER BY r.ventas_count DESC, r.nombre ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'referidos' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST ──────────────────────────────────────────────────────────────────────
$json   = json_decode(file_get_contents('php://input'), true) ?? [];
$accion = $json['accion'] ?? 'agregar';

if ($accion === 'agregar') {
    $nombre  = trim($json['nombre']  ?? '');
    $email   = trim($json['email']   ?? '');
    $tel     = trim($json['telefono']?? '');

    if (!$nombre) { http_response_code(400); echo json_encode(['error' => 'Nombre requerido']); exit; }

    // Generate unique code
    $codigo = strtoupper(substr(preg_replace('/\s+/', '', $nombre), 0, 4)) . rand(100, 999);

    try {
        $pdo->prepare("INSERT INTO referidos (nombre, email, telefono, codigo_referido) VALUES (?,?,?,?)")
            ->execute([$nombre, $email, $tel, $codigo]);
        echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'codigo' => $codigo]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al agregar referido']);
    }
    exit;
}

if ($accion === 'asignar_venta') {
    $ventaId   = intval($json['venta_id']   ?? 0);
    $referidoId= intval($json['referido_id']?? 0);
    $comision  = floatval($json['comision'] ?? 0);

    if (!$ventaId || !$referidoId) { http_response_code(400); echo json_encode(['error' => 'IDs requeridos']); exit; }

    try {
        $pdo->prepare("UPDATE ventas_log SET referido_id = ? WHERE id = ?")->execute([$referidoId, $ventaId]);
        $pdo->prepare("UPDATE referidos SET ventas_count = ventas_count + 1, comision_total = comision_total + ? WHERE id = ?")
            ->execute([$comision, $referidoId]);
        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al asignar venta']);
    }
    exit;
}

if ($accion === 'eliminar') {
    $id = intval($json['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID requerido']); exit; }
    $pdo->prepare("UPDATE referidos SET activo = 0 WHERE id = ?")->execute([$id]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Acción no reconocida']);
