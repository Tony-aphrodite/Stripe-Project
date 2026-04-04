<?php
/**
 * Voltika — Client-side Acta de Entrega operations (no dealer auth required)
 *
 * POST { accion: 'buscar', pedido_num }        → find order + moto
 * POST { accion: 'checklist', pedido_num, items }  → save client checklist
 * POST { accion: 'firmar', pedido_num, otp }   → sign acta (requires validated OTP)
 * GET  ?pedido_num=X&acta=pdf                  → download signed acta PDF
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';
session_start();

$pdo = getDB();

// ── GET: download acta PDF ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['acta'])) {
    $pedidoNum = trim($_GET['pedido_num'] ?? '');
    if (!$pedidoNum) { http_response_code(400); exit('pedido_num requerido'); }

    // Find moto
    $stmt = $pdo->prepare("SELECT id FROM inventario_motos WHERE pedido_num = ? OR pedido_num = ? LIMIT 1");
    $stmt->execute([$pedidoNum, 'VK-' . $pedidoNum]);
    $moto = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($moto) {
        header('Location: admin-generar-acta-pdf.php?moto_id=' . $moto['id'] . '&key=voltika_acta_2026');
    } else {
        http_response_code(404);
        echo 'Pedido no encontrado';
    }
    exit;
}

// ── POST ─────────────────────────────────────────────────────────────────────
$json   = json_decode(file_get_contents('php://input'), true) ?? [];
$accion = $json['accion'] ?? '';
$pedidoNum = trim($json['pedido_num'] ?? '');

if (!$pedidoNum) {
    echo json_encode(['ok' => false, 'error' => 'Número de pedido requerido']);
    exit;
}

// Find moto by pedido_num
$stmt = $pdo->prepare("
    SELECT m.id, m.vin, m.vin_display, m.modelo, m.color, m.estado,
           m.cliente_nombre, m.cliente_email, m.cliente_telefono,
           m.pedido_num, m.pago_estado, m.punto_nombre
    FROM inventario_motos m
    WHERE (m.pedido_num = ? OR m.pedido_num = ?) AND m.activo = 1
    LIMIT 1
");
$stmt->execute([$pedidoNum, 'VK-' . $pedidoNum]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$moto) {
    echo json_encode(['ok' => false, 'error' => 'No se encontró un pedido con ese número']);
    exit;
}

$motoId = $moto['id'];

// ── BUSCAR ───────────────────────────────────────────────────────────────────
if ($accion === 'buscar') {
    // Get entrega checklist status
    $chkStmt = $pdo->prepare("SELECT fase_actual, cliente_acta_firmada, cliente_checklist_completado FROM checklist_entrega_v2 WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
    $chkStmt->execute([$motoId]);
    $chk = $chkStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'moto' => $moto,
        'checklist' => [
            'fase_actual' => $chk['fase_actual'] ?? 'fase1',
            'acta_firmada' => (bool)($chk['cliente_acta_firmada'] ?? false),
            'checklist_completado' => (bool)($chk['cliente_checklist_completado'] ?? false),
        ],
    ]);
    exit;
}

// ── CHECKLIST (client fills) ─────────────────────────────────────────────────
if ($accion === 'checklist') {
    $items = $json['items'] ?? [];

    $clienteItems = [
        'cl_vin_correcto', 'cl_modelo_correcto', 'cl_color_correcto',
        'cl_sin_danos', 'cl_accesorios_completos', 'cl_llaves_recibidas',
        'cl_cargador_recibido', 'cl_manual_recibido', 'cl_funcionamiento_mostrado',
    ];

    $allOk = true;
    foreach ($clienteItems as $c) {
        if (empty($items[$c])) $allOk = false;
    }

    // Update checklist_entrega_v2
    $chkStmt = $pdo->prepare("SELECT id FROM checklist_entrega_v2 WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
    $chkStmt->execute([$motoId]);
    $chkRow = $chkStmt->fetch();

    if ($chkRow) {
        $pdo->prepare("UPDATE checklist_entrega_v2 SET cliente_checklist_completado = ? WHERE id = ?")
            ->execute([$allOk ? 1 : 0, $chkRow['id']]);
    }

    echo json_encode(['ok' => true, 'completado' => $allOk, 'message' => $allOk ? 'Checklist completado' : 'Faltan items']);
    exit;
}

// ── FIRMAR ACTA ──────────────────────────────────────────────────────────────
if ($accion === 'firmar') {
    // Verify OTP was validated in this session
    $otpValidado = $_SESSION['otp_verified_' . $motoId] ?? false;

    // Also check if OTP was validated in the checklist_entrega_v2
    $chkStmt = $pdo->prepare("SELECT id, otp_validado FROM checklist_entrega_v2 WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
    $chkStmt->execute([$motoId]);
    $chkRow = $chkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$otpValidado && (!$chkRow || !$chkRow['otp_validado'])) {
        echo json_encode(['ok' => false, 'error' => 'Debe validar el OTP primero antes de firmar el acta']);
        exit;
    }

    // Mark acta as signed by client
    if ($chkRow) {
        $pdo->prepare("UPDATE checklist_entrega_v2 SET cliente_acta_firmada = 1, cliente_acta_fecha = NOW() WHERE id = ?")
            ->execute([$chkRow['id']]);
    }

    echo json_encode([
        'ok' => true,
        'firmada' => true,
        'message' => 'Acta de entrega firmada exitosamente',
        'pdf_url' => 'php/cliente-acta.php?pedido_num=' . urlencode($pedidoNum) . '&acta=pdf',
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Acción no reconocida']);
