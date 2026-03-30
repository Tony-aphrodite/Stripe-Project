<?php
/**
 * Voltika Admin - Actualizar estado de una moto
 * POST { moto_id, accion }
 * Acciones: recibir, iniciar_ensamble, terminar_ensamble,
 *           marcar_lista, iniciar_validacion, retener, liberar
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-auth.php';

$dealer = requireDealerAuth();

$json   = json_decode(file_get_contents('php://input'), true) ?? [];
$motoId = intval($json['moto_id'] ?? 0);
$accion = trim($json['accion'] ?? '');
$notas  = trim($json['notas']  ?? '');

if (!$motoId || !$accion) {
    http_response_code(400);
    echo json_encode(['error' => 'moto_id y accion requeridos']);
    exit;
}

// Estado transitions
$transitions = [
    'recibir'            => ['from' => 'por_llegar',            'to' => 'recibida'],
    'iniciar_ensamble'   => ['from' => 'por_ensamblar',         'to' => 'en_ensamble'],
    'terminar_ensamble'  => ['from' => 'en_ensamble',           'to' => 'lista_para_entrega'],
    'marcar_lista'       => ['from' => 'recibida',              'to' => 'lista_para_entrega'],
    'iniciar_validacion' => ['from' => 'lista_para_entrega',    'to' => 'por_validar_entrega'],
    'retener'            => ['from' => null,                    'to' => 'retenida'],
    'liberar'            => ['from' => 'retenida',              'to' => 'recibida'],
    'iniciar_venta'      => ['from' => 'lista_para_entrega',    'to' => 'por_validar_entrega'],
    'por_ensamblar'      => ['from' => 'recibida',              'to' => 'por_ensamblar'],
];

if (!isset($transitions[$accion])) {
    http_response_code(400);
    echo json_encode(['error' => 'Acción no reconocida: ' . $accion]);
    exit;
}

$trans = $transitions[$accion];

try {
    $pdo = getDB();

    // Fetch moto and verify ownership
    $stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ? AND dealer_id = ? AND activo = 1 LIMIT 1");
    $stmt->execute([$motoId, $dealer['id']]);
    $moto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$moto) {
        http_response_code(404);
        echo json_encode(['error' => 'Moto no encontrada']);
        exit;
    }

    // Validate transition
    if ($trans['from'] !== null && $moto['estado'] !== $trans['from']) {
        http_response_code(409);
        echo json_encode(['error' => "Estado actual '{$moto['estado']}' no permite esta acción"]);
        exit;
    }

    $nuevoEstado = $trans['to'];

    // Build log entry
    $logEntry = [
        'estado'    => $nuevoEstado,
        'accion'    => $accion,
        'dealer'    => $dealer['nombre'],
        'timestamp' => date('Y-m-d H:i:s'),
        'notas'     => $notas ?: null,
    ];

    $logActual = $moto['log_estados'] ? json_decode($moto['log_estados'], true) : [];
    $logActual[] = $logEntry;

    // Update moto
    $updateStmt = $pdo->prepare("
        UPDATE inventario_motos
        SET estado = ?, fecha_estado = NOW(), dias_en_paso = 0,
            log_estados = ?, notas = IF(? != '', ?, notas)
        WHERE id = ?
    ");
    $updateStmt->execute([
        $nuevoEstado,
        json_encode($logActual, JSON_UNESCAPED_UNICODE),
        $notas, $notas,
        $motoId
    ]);

    // Log en ventas_log si es venta
    if (in_array($accion, ['iniciar_venta','iniciar_validacion'])) {
        $pdo->prepare("
            INSERT INTO ventas_log (moto_id, tipo, dealer_id, cliente_nombre, cliente_email,
                                    cliente_telefono, pedido_num, modelo, color, vin, notas)
            VALUES (?, 'entrega_voltika', ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $motoId, $dealer['id'],
            $moto['cliente_nombre'], $moto['cliente_email'],
            $moto['cliente_telefono'], $moto['pedido_num'],
            $moto['modelo'], $moto['color'], $moto['vin'],
            'Acción: ' . $accion
        ]);
    }

    echo json_encode([
        'ok'          => true,
        'nuevo_estado'=> $nuevoEstado,
        'moto_id'     => $motoId,
    ]);

} catch (PDOException $e) {
    error_log('Voltika moto-accion error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error interno']);
}
