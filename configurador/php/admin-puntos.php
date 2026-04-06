<?php
/**
 * Voltika Admin — Puntos (delivery/sale points) CRUD
 * GET             → list all puntos with moto counts
 * POST accion=agregar    → add new punto (dealer_usuario)
 * POST accion=actualizar → edit punto_nombre / nombre
 * POST accion=eliminar   → deactivate punto
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-auth.php';

$dealer = requireDealerAuth();
if (!in_array($dealer['rol'], ['admin', 'cedis'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Solo administradores']);
    exit;
}

$pdo = getDB();

// ── GET ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query("
        SELECT d.id, d.nombre, d.email, d.rol, d.punto_id, d.punto_nombre, d.activo,
               COUNT(m.id)                                                    AS total_motos,
               SUM(CASE WHEN m.estado != 'entregada' AND m.activo=1 THEN 1 ELSE 0 END) AS activas,
               SUM(CASE WHEN m.estado  = 'entregada' AND m.activo=1 THEN 1 ELSE 0 END) AS entregadas
        FROM dealer_usuarios d
        LEFT JOIN inventario_motos m ON m.dealer_id = d.id
        WHERE d.activo = 1
        GROUP BY d.id
        ORDER BY d.punto_nombre, d.nombre
    ");
    echo json_encode(['ok' => true, 'puntos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ── POST ─────────────────────────────────────────────────────────────────────
$data   = json_decode(file_get_contents('php://input'), true) ?? [];
$accion = $data['accion'] ?? '';

// ── AGREGAR ───────────────────────────────────────────────────────────────────
if ($accion === 'agregar') {
    $nombre      = trim($data['nombre']      ?? '');
    $puntoNombre = trim($data['punto_nombre'] ?? '');
    $puntoId     = trim($data['punto_id']     ?? strtoupper(preg_replace('/\s+/', '_', $puntoNombre)));
    $email       = trim($data['email']        ?? '');
    $rol         = in_array($data['rol'] ?? '', ['dealer','cedis','admin']) ? $data['rol'] : 'dealer';

    if (!$nombre || !$puntoNombre) {
        http_response_code(400);
        echo json_encode(['error' => 'nombre y punto_nombre son requeridos']);
        exit;
    }
    if (!$email) {
        // Generate placeholder email
        $email = strtolower(preg_replace('/\s+/', '.', $puntoNombre)) . '@voltika.interno';
    }

    // Temporary password — admin must update later
    $passHash = password_hash('Voltika2026!', PASSWORD_DEFAULT);

    try {
        $pdo->prepare("
            INSERT INTO dealer_usuarios (nombre, email, password, rol, punto_id, punto_nombre, activo)
            VALUES (?, ?, ?, ?, ?, ?, 1)
        ")->execute([$nombre, $email, $passHash, $rol, $puntoId, $puntoNombre]);
        echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'Punto creado']);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            http_response_code(409);
            echo json_encode(['error' => 'Email duplicado']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Error interno: ' . $e->getMessage()]);
        }
    }
    exit;
}

// ── ACTUALIZAR ────────────────────────────────────────────────────────────────
if ($accion === 'actualizar') {
    $id          = intval($data['id'] ?? 0);
    $nombre      = trim($data['nombre']       ?? '');
    $puntoNombre = trim($data['punto_nombre']  ?? '');
    $puntoId     = trim($data['punto_id']      ?? '');

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'id requerido']);
        exit;
    }

    $sets = []; $vals = [];
    if ($nombre)      { $sets[] = 'nombre = ?';       $vals[] = $nombre; }
    if ($puntoNombre) { $sets[] = 'punto_nombre = ?'; $vals[] = $puntoNombre; }
    if ($puntoId)     { $sets[] = 'punto_id = ?';     $vals[] = $puntoId; }

    if (empty($sets)) {
        echo json_encode(['ok' => true, 'message' => 'Sin cambios']);
        exit;
    }

    $vals[] = $id;
    $pdo->prepare("UPDATE dealer_usuarios SET " . implode(', ', $sets) . " WHERE id = ?")
        ->execute($vals);

    // Also update punto_nombre in inventario_motos for this dealer
    if ($puntoNombre) {
        $pdo->prepare("UPDATE inventario_motos SET punto_nombre = ? WHERE dealer_id = ?")
            ->execute([$puntoNombre, $id]);
    }

    echo json_encode(['ok' => true, 'message' => 'Punto actualizado']);
    exit;
}

// ── ELIMINAR ──────────────────────────────────────────────────────────────────
if ($accion === 'eliminar') {
    $id = intval($data['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'id requerido']);
        exit;
    }
    $pdo->prepare("UPDATE dealer_usuarios SET activo = 0 WHERE id = ?")->execute([$id]);
    echo json_encode(['ok' => true, 'message' => 'Punto desactivado']);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Acción no reconocida']);
