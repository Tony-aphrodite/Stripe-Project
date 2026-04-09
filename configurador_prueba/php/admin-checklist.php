<?php
/**
 * Voltika Admin - Checklist pre-entrega
 * GET  ?moto_id=N   → fetch existing checklist
 * POST { moto_id, items{}, notas, completar }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-auth.php';

$dealer = requireDealerAuth();

// ── GET ───────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $motoId = intval($_GET['moto_id'] ?? 0);
    if (!$motoId) { echo json_encode(['ok' => true, 'checklist' => null]); exit; }

    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT * FROM checklist_entrega WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$motoId]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'checklist' => $row ?: null]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'DB error']);
    }
    exit;
}

// ── POST ──────────────────────────────────────────────────────────────────────
$json   = json_decode(file_get_contents('php://input'), true) ?? [];
$motoId = intval($json['moto_id'] ?? 0);
$items  = $json['items']    ?? [];
$notas  = $json['notas']    ?? '';
$completar = !empty($json['completar']);

if (!$motoId) {
    http_response_code(400);
    echo json_encode(['error' => 'moto_id requerido']);
    exit;
}

$campos = [
    'revision_fisica', 'revision_electrica', 'carga_bateria', 'luces_ok',
    'frenos_ok', 'velocimetro_ok', 'documentos_completos', 'llaves_entregadas',
    'manual_entregado', 'identidad_verificada', 'datos_confirmados',
    'qr_pedido_ok', 'qr_moto_ok',
];

// All items checked?
$allOk = true;
foreach ($campos as $c) {
    if (empty($items[$c])) { $allOk = false; }
}

try {
    $pdo = getDB();

    // Verify moto belongs to dealer
    $stmt = $pdo->prepare("SELECT id FROM inventario_motos WHERE id = ? AND dealer_id = ? LIMIT 1");
    $stmt->execute([$motoId, $dealer['id']]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Acceso no autorizado']);
        exit;
    }

    // Upsert checklist
    $existing = $pdo->prepare("SELECT id FROM checklist_entrega WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
    $existing->execute([$motoId]);
    $row = $existing->fetch();

    $vals = array_map(fn($c) => empty($items[$c]) ? 0 : 1, $campos);
    $vals[] = $notas;
    $vals[] = ($completar && $allOk) ? 1 : 0;

    if ($row) {
        $sets = implode(', ', array_map(fn($c) => "$c = ?", $campos));
        $sql  = "UPDATE checklist_entrega SET $sets, notas = ?, completado = ? WHERE id = ?";
        $vals[] = $row['id'];
        $pdo->prepare($sql)->execute($vals);
        $checklistId = $row['id'];
    } else {
        $cols = implode(', ', $campos);
        $phs  = implode(', ', array_fill(0, count($campos), '?'));
        $sql  = "INSERT INTO checklist_entrega (moto_id, dealer_id, $cols, notas, completado)
                 VALUES (?, ?, $phs, ?, ?)";
        array_unshift($vals, $motoId, $dealer['id']);
        $pdo->prepare($sql)->execute($vals);
        $checklistId = $pdo->lastInsertId();
    }

    // If checklist completed → advance moto to 'por_validar_entrega'
    if ($completar && $allOk) {
        $pdo->prepare("
            UPDATE inventario_motos
            SET estado = 'por_validar_entrega', fecha_estado = NOW(), dias_en_paso = 0
            WHERE id = ? AND estado = 'lista_para_entrega'
        ")->execute([$motoId]);
    }

    echo json_encode([
        'ok'          => true,
        'checklist_id'=> (int)$checklistId,
        'completado'  => $completar && $allOk,
        'all_ok'      => $allOk,
    ]);

} catch (PDOException $e) {
    error_log('Voltika checklist error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error interno']);
}
