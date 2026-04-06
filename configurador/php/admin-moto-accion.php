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

// ── Role helpers ─────────────────────────────────────────────────────────────
$isCedis  = in_array($dealer['rol'], ['admin', 'cedis']);
$isDealer = in_array($dealer['rol'], ['dealer', 'admin']);

// ── Special action: enviar_a_punto (CEDIS only, handled separately) ──────────
if ($accion === 'enviar_a_punto') {
    if (!$isCedis) {
        http_response_code(403);
        echo json_encode(['error' => 'Solo CEDIS puede enviar motos a un punto']);
        exit;
    }

    $dealerDestinoId  = intval($json['dealer_destino_id']  ?? 0);
    $puntoNombreDest  = trim($json['punto_nombre_destino'] ?? '');
    $puntoIdDest      = trim($json['punto_id_destino']     ?? '');

    if (!$dealerDestinoId || !$puntoNombreDest) {
        http_response_code(400);
        echo json_encode(['error' => 'dealer_destino_id y punto_nombre_destino son requeridos']);
        exit;
    }

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ? AND activo = 1 LIMIT 1");
        $stmt->execute([$motoId]);
        $moto = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$moto) { http_response_code(404); echo json_encode(['error' => 'Moto no encontrada']); exit; }

        if ($moto['estado'] !== 'lista_para_entrega') {
            http_response_code(409);
            echo json_encode(['error' => 'La moto debe estar en "Lista para Entrega" para enviarla a un punto']);
            exit;
        }

        $logActual   = $moto['log_estados'] ? json_decode($moto['log_estados'], true) : [];
        $logActual[] = [
            'estado'    => 'en_envio',
            'accion'    => 'enviar_a_punto',
            'dealer'    => $dealer['nombre'],
            'timestamp' => date('Y-m-d H:i:s'),
            'notas'     => "Enviado a: $puntoNombreDest" . ($notas ? " — $notas" : ''),
        ];

        $pdo->prepare("
            UPDATE inventario_motos
            SET estado = 'en_envio', fecha_estado = NOW(), dias_en_paso = 0,
                dealer_id = ?, punto_nombre = ?, punto_id = ?,
                cedis_origen = IFNULL(cedis_origen, punto_nombre),
                log_estados = ?
            WHERE id = ?
        ")->execute([
            $dealerDestinoId,
            $puntoNombreDest,
            $puntoIdDest ?: null,
            json_encode($logActual, JSON_UNESCAPED_UNICODE),
            $motoId,
        ]);

        echo json_encode(['ok' => true, 'nuevo_estado' => 'en_envio', 'moto_id' => $motoId,
                          'message' => "Moto en camino a $puntoNombreDest"]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error interno: ' . $e->getMessage()]);
    }
    exit;
}

// ── Special action: confirmar_recepcion (Punto/dealer only) ──────────────────
if ($accion === 'confirmar_recepcion') {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ? AND activo = 1 AND dealer_id = ? LIMIT 1");
        $stmt->execute([$motoId, $dealer['id']]);
        $moto = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$moto) { http_response_code(404); echo json_encode(['error' => 'Moto no encontrada o no asignada a tu punto']); exit; }

        if ($moto['estado'] !== 'en_envio') {
            http_response_code(409);
            echo json_encode(['error' => 'La moto debe estar "En Envío" para confirmar recepción']);
            exit;
        }

        $logActual   = $moto['log_estados'] ? json_decode($moto['log_estados'], true) : [];
        $logActual[] = [
            'estado'    => 'en_punto',
            'accion'    => 'confirmar_recepcion',
            'dealer'    => $dealer['nombre'],
            'timestamp' => date('Y-m-d H:i:s'),
            'notas'     => 'Recepción confirmada en ' . $dealer['punto_nombre'] . ($notas ? " — $notas" : ''),
        ];

        $pdo->prepare("
            UPDATE inventario_motos
            SET estado = 'en_punto', fecha_estado = NOW(), dias_en_paso = 0, log_estados = ?
            WHERE id = ?
        ")->execute([json_encode($logActual, JSON_UNESCAPED_UNICODE), $motoId]);

        echo json_encode(['ok' => true, 'nuevo_estado' => 'en_punto', 'moto_id' => $motoId,
                          'message' => 'Moto ingresada al inventario del punto']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error interno: ' . $e->getMessage()]);
    }
    exit;
}

// Estado transitions (standard)
$transitions = [
    'recibir'            => ['from' => 'por_llegar',            'to' => 'recibida',              'rol' => ['admin','cedis']],
    'iniciar_ensamble'   => ['from' => 'por_ensamblar',         'to' => 'en_ensamble',           'rol' => ['admin','cedis']],
    'terminar_ensamble'  => ['from' => 'en_ensamble',           'to' => 'lista_para_entrega',    'rol' => ['admin','cedis']],
    'marcar_lista'       => ['from' => 'recibida',              'to' => 'lista_para_entrega',    'rol' => ['admin','cedis']],
    'iniciar_validacion' => ['from' => 'en_punto',              'to' => 'por_validar_entrega',   'rol' => ['admin','dealer']],
    'retener'            => ['from' => null,                    'to' => 'retenida',              'rol' => ['admin','cedis']],
    'liberar'            => ['from' => 'retenida',              'to' => 'recibida',              'rol' => ['admin','cedis']],
    'iniciar_venta'      => ['from' => 'en_punto',              'to' => 'por_validar_entrega',   'rol' => ['admin','dealer']],
    'por_ensamblar'      => ['from' => 'recibida',              'to' => 'por_ensamblar',         'rol' => ['admin','cedis']],
];

if (!isset($transitions[$accion])) {
    http_response_code(400);
    echo json_encode(['error' => 'Acción no reconocida: ' . $accion]);
    exit;
}

$trans = $transitions[$accion];

try {
    $pdo = getDB();

    // Fetch moto — allow unassigned motos (dealer_id IS NULL) to be claimed
    $stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ? AND activo = 1 AND (dealer_id = ? OR dealer_id IS NULL) LIMIT 1");
    $stmt->execute([$motoId, $dealer['id']]);
    $moto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$moto) {
        http_response_code(404);
        echo json_encode(['error' => 'Moto no encontrada']);
        exit;
    }

    // Assign dealer_id if not set
    if (empty($moto['dealer_id'])) {
        $pdo->prepare("UPDATE inventario_motos SET dealer_id = ? WHERE id = ?")->execute([$dealer['id'], $motoId]);
    }

    // Validate transition
    if ($trans['from'] !== null && $moto['estado'] !== $trans['from']) {
        http_response_code(409);
        echo json_encode(['error' => "Estado actual '{$moto['estado']}' no permite esta acción"]);
        exit;
    }

    $nuevoEstado = $trans['to'];

    // ── Checklist validation — block transition if checklist not complete ────
    $checklistRequired = [
        'recibir'            => ['table' => 'checklist_origen',     'field' => 'completado', 'msg' => 'Debe completar el Checklist de Origen antes de registrar la llegada.'],
        'iniciar_ensamble'   => ['table' => 'checklist_ensamble',   'field' => 'fase1_completada', 'msg' => 'Debe completar la Fase 1 del Ensamble (Inicio) antes de iniciar.'],
        'terminar_ensamble'  => ['table' => 'checklist_ensamble',   'field' => 'completado', 'msg' => 'Debe completar todas las fases del Ensamble (incluyendo validación final) antes de liberar.'],
        'iniciar_validacion' => ['table' => 'checklist_entrega_v2', 'field' => 'fase3_completada', 'msg' => 'Debe completar las Fases 1-3 del Checklist de Entrega antes de iniciar validación.'],
    ];

    if (isset($checklistRequired[$accion])) {
        $req = $checklistRequired[$accion];
        $chkStmt = $pdo->prepare("SELECT {$req['field']} FROM {$req['table']} WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
        $chkStmt->execute([$motoId]);
        $chkRow = $chkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$chkRow || !$chkRow[$req['field']]) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => $req['msg']]);
            exit;
        }
    }

    // ── Payment validation — block delivery if payment not confirmed ────────
    $paymentRequiredActions = ['iniciar_validacion', 'iniciar_venta'];
    if (in_array($accion, $paymentRequiredActions)) {
        $payOk = ($moto['pago_estado'] === 'pagada');

        // Try to verify via Stripe if we have a payment intent
        if (!$payOk && !empty($moto['stripe_pi']) && STRIPE_SECRET_KEY) {
            $ch = curl_init('https://api.stripe.com/v1/payment_intents/' . urlencode($moto['stripe_pi']));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . STRIPE_SECRET_KEY],
                CURLOPT_TIMEOUT => 10,
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
            $piData = json_decode($resp, true);
            if ($piData && ($piData['status'] ?? '') === 'succeeded') {
                $payOk = true;
                $pdo->prepare("UPDATE inventario_motos SET pago_estado = 'pagada', stripe_payment_status = 'succeeded', stripe_verified_at = NOW() WHERE id = ?")->execute([$motoId]);
            }
        }

        // Also check existing stripe_payment_status column
        if (!$payOk && ($moto['stripe_payment_status'] ?? '') === 'succeeded') {
            $payOk = true;
        }

        if (!$payOk) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'No se puede proceder: el pago no ha sido confirmado. Verifique el estado de pago en Stripe.']);
            exit;
        }
    }

    // ── Acta signature validation — block delivery without signed acta ────
    if ($accion === 'iniciar_validacion' || $accion === 'iniciar_venta') {
        $actaStmt = $pdo->prepare("SELECT cliente_acta_firmada, otp_validado FROM checklist_entrega_v2 WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
        $actaStmt->execute([$motoId]);
        $actaRow = $actaStmt->fetch(PDO::FETCH_ASSOC);

        if (!$actaRow || !$actaRow['cliente_acta_firmada']) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'El cliente debe firmar el Acta de Entrega antes de proceder. Sin acta firmada = no existe entrega.']);
            exit;
        }
        if (!$actaRow['otp_validado']) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'El OTP del cliente no ha sido validado. Sin OTP = no existe entrega.']);
            exit;
        }
    }

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
