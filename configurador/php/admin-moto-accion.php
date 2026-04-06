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
require_once __DIR__ . '/envia-api.php';

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

    $dealerDestinoId   = intval($json['dealer_destino_id']     ?? 0);
    $puntoNombreDest   = trim($json['punto_nombre_destino']    ?? '');
    $puntoIdDest       = trim($json['punto_id_destino']        ?? '');
    $fechaLlegada      = trim($json['fecha_estimada_llegada']  ?? '');
    $fechaRecogida     = trim($json['fecha_estimada_recogida'] ?? '');

    if (!$dealerDestinoId || !$puntoNombreDest) {
        http_response_code(400);
        echo json_encode(['error' => 'dealer_destino_id y punto_nombre_destino son requeridos']);
        exit;
    }

    try {
        $pdo = getDB();

        // Ensure tracking columns exist (safe migration)
        foreach ([
            "ALTER TABLE inventario_motos ADD COLUMN fecha_estimada_llegada  DATE         NULL",
            "ALTER TABLE inventario_motos ADD COLUMN fecha_estimada_recogida DATE         NULL",
            "ALTER TABLE inventario_motos ADD COLUMN envia_tracking_number   VARCHAR(100) NULL",
            "ALTER TABLE inventario_motos ADD COLUMN envia_tracking_url      VARCHAR(500) NULL",
        ] as $sql) {
            try { $pdo->exec($sql); } catch (PDOException $ignored) {}
        }

        $stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ? AND activo = 1 LIMIT 1");
        $stmt->execute([$motoId]);
        $moto = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$moto) { http_response_code(404); echo json_encode(['error' => 'Moto no encontrada']); exit; }

        if ($moto['estado'] !== 'lista_para_entrega') {
            http_response_code(409);
            echo json_encode(['error' => 'La moto debe estar en "Lista para Entrega" para enviarla a un punto']);
            exit;
        }

        // ── Call envia.com API ─────────────────────────────────────────────────
        $trackingNumber = '';
        $trackingUrl    = '';
        $enviaResult    = null;

        // Fetch punto address from dealer_usuarios
        $puntoRow = $pdo->prepare("SELECT * FROM dealer_usuarios WHERE id = ? LIMIT 1");
        $puntoRow->execute([$dealerDestinoId]);
        $puntoData = $puntoRow->fetch(PDO::FETCH_ASSOC) ?: [];

        if (ENVIA_API_KEY) {
            $enviaResult = enviaCrearEnvio(
                [
                    'nombre'      => $puntoData['nombre']      ?? $puntoNombreDest,
                    'punto_nombre'=> $puntoNombreDest,
                    'telefono'    => $puntoData['telefono']    ?? CEDIS_TELEFONO,
                    'email'       => $puntoData['email']       ?? '',
                    'calle'       => $puntoData['calle']       ?? '',
                    'numero'      => $puntoData['numero']      ?? 'S/N',
                    'ciudad'      => $puntoData['ciudad']      ?? '',
                    'estado'      => $puntoData['estado_dir']  ?? '',
                    'cp'          => $puntoData['cp']          ?? '',
                ],
                ['peso' => 120, 'largo' => 180, 'ancho' => 80, 'alto' => 120],
                $moto['pedido_num'] ?? ''
            );
            if ($enviaResult && $enviaResult['ok']) {
                $trackingNumber = $enviaResult['tracking_number'];
                $trackingUrl    = $enviaResult['tracking_url'];
                // Use envia.com estimated date if available (override manual input)
                if (!empty($enviaResult['estimated_delivery_date'])) {
                    $fechaLlegada = $enviaResult['estimated_delivery_date'];
                }
            } else {
                error_log('Voltika envia.com skipped: ' . ($enviaResult['error'] ?? 'no API key'));
            }
        }

        // ── Update DB ──────────────────────────────────────────────────────────
        $logActual   = $moto['log_estados'] ? json_decode($moto['log_estados'], true) : [];
        $logActual[] = [
            'estado'            => 'en_envio',
            'accion'            => 'enviar_a_punto',
            'dealer'            => $dealer['nombre'],
            'timestamp'         => date('Y-m-d H:i:s'),
            'notas'             => "Enviado a: $puntoNombreDest" . ($notas ? " — $notas" : ''),
            'tracking_number'   => $trackingNumber ?: null,
            'tracking_url'      => $trackingUrl    ?: null,
            'fecha_llegada_est' => $fechaLlegada   ?: null,
            'fecha_recogida_est'=> $fechaRecogida  ?: null,
        ];

        $pdo->prepare("
            UPDATE inventario_motos
            SET estado = 'en_envio', fecha_estado = NOW(), dias_en_paso = 0,
                dealer_id = ?, punto_nombre = ?, punto_id = ?,
                cedis_origen = IFNULL(cedis_origen, punto_nombre),
                log_estados = ?,
                fecha_estimada_llegada  = NULLIF(?, ''),
                fecha_estimada_recogida = NULLIF(?, ''),
                envia_tracking_number   = NULLIF(?, ''),
                envia_tracking_url      = NULLIF(?, '')
            WHERE id = ?
        ")->execute([
            $dealerDestinoId,
            $puntoNombreDest,
            $puntoIdDest ?: null,
            json_encode($logActual, JSON_UNESCAPED_UNICODE),
            $fechaLlegada,
            $fechaRecogida,
            $trackingNumber,
            $trackingUrl,
            $motoId,
        ]);

        // ── Send customer email ────────────────────────────────────────────────
        if (!empty($moto['cliente_email'])) {
            $nombre       = $moto['cliente_nombre'] ?? 'Cliente';
            $fechaLL      = $fechaLlegada  ? date('d/m/Y', strtotime($fechaLlegada))  : 'Por confirmar';
            $fechaRC      = $fechaRecogida ? date('d/m/Y', strtotime($fechaRecogida)) : 'Por confirmar';
            $trackingHtml = $trackingUrl
                ? "<p style='margin:16px 0 0;'><a href='$trackingUrl' style='background:#1d4ed8;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:700;'>📦 Rastrear mi envío</a></p>"
                : '';
            $trackingInfo = $trackingNumber
                ? "<tr><td style='color:#6B7280;padding:10px 12px;border-bottom:1px solid #E5E7EB;'>No. de rastreo</td><td style='font-weight:700;padding:10px 12px;border-bottom:1px solid #E5E7EB;'>$trackingNumber</td></tr>"
                : '';

            $emailHtml = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;">
  <tr><td align="center" style="padding:24px;">
    <table width="620" cellpadding="0" cellspacing="0"
           style="background:#fff;border-radius:8px;overflow:hidden;max-width:620px;width:100%;">
      <tr>
        <td style="background:linear-gradient(135deg,#1d4ed8,#039fe1);padding:24px 28px;color:#fff;">
          <h1 style="margin:0;font-size:22px;font-weight:800;">&#9889; voltika</h1>
          <p style="margin:8px 0 0;font-size:16px;">&#128666; Tu moto está en camino</p>
        </td>
      </tr>
      <tr>
        <td style="padding:28px;">
          <p style="margin:0 0 16px;font-size:15px;color:#111;">
            Hola <strong>' . htmlspecialchars($nombre) . '</strong>,
          </p>
          <p style="margin:0 0 20px;font-size:14px;color:#555;">
            Tu motocicleta <strong>' . htmlspecialchars($moto['modelo'] . ' ' . $moto['color']) . '</strong>
            ha sido enviada al punto de entrega <strong>' . htmlspecialchars($puntoNombreDest) . '</strong>.
          </p>
          <table width="100%" cellpadding="8" cellspacing="0"
                 style="border:1px solid #E5E7EB;border-radius:8px;font-size:14px;">
            <tr style="background:#F9FAFB;">
              <td style="color:#6B7280;padding:10px 12px;border-bottom:1px solid #E5E7EB;">Modelo</td>
              <td style="font-weight:700;padding:10px 12px;border-bottom:1px solid #E5E7EB;">' . htmlspecialchars($moto['modelo'] . ' ' . $moto['color']) . '</td>
            </tr>
            <tr>
              <td style="color:#6B7280;padding:10px 12px;border-bottom:1px solid #E5E7EB;">Punto de entrega</td>
              <td style="padding:10px 12px;border-bottom:1px solid #E5E7EB;font-weight:700;">' . htmlspecialchars($puntoNombreDest) . '</td>
            </tr>
            <tr style="background:#F9FAFB;">
              <td style="color:#6B7280;padding:10px 12px;border-bottom:1px solid #E5E7EB;">&#128197; Llegada estimada al punto</td>
              <td style="padding:10px 12px;border-bottom:1px solid #E5E7EB;font-weight:700;color:#1d4ed8;">' . $fechaLL . '</td>
            </tr>
            <tr>
              <td style="color:#6B7280;padding:10px 12px;border-bottom:1px solid #E5E7EB;">&#128274; Fecha estimada de recogida</td>
              <td style="padding:10px 12px;border-bottom:1px solid #E5E7EB;font-weight:700;color:#059669;">' . $fechaRC . '</td>
            </tr>
            ' . $trackingInfo . '
            ' . ($moto['pedido_num'] ? "<tr style='background:#F9FAFB;'><td style='color:#6B7280;padding:10px 12px;'>Pedido</td><td style='font-weight:700;padding:10px 12px;'>" . htmlspecialchars($moto['pedido_num']) . "</td></tr>" : '') . '
          </table>
          ' . $trackingHtml . '
          <p style="margin:24px 0 0;font-size:13px;color:#9CA3AF;">
            Te avisaremos cuando tu moto esté lista para recoger. ¿Dudas?
            <a href="mailto:ventas@voltika.com.mx" style="color:#039fe1;">ventas@voltika.com.mx</a>
          </p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body></html>';

            sendMail(
                $moto['cliente_email'],
                $nombre,
                '🏍️ Tu Voltika está en camino — ' . $puntoNombreDest,
                $emailHtml
            );
        }

        echo json_encode([
            'ok'              => true,
            'nuevo_estado'    => 'en_envio',
            'moto_id'         => $motoId,
            'tracking_number' => $trackingNumber ?: null,
            'tracking_url'    => $trackingUrl    ?: null,
            'message'         => "Moto en camino a $puntoNombreDest",
        ]);
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

    // ── Email: moto lista para recoger (terminar_ensamble → lista_para_entrega) ─
    if ($accion === 'terminar_ensamble' && !empty($moto['cliente_email'])) {
        $nombre      = $moto['cliente_nombre'] ?? 'Cliente';
        $puntoNombre = $moto['punto_nombre']   ?? 'el punto de entrega';

        $emailHtml = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;">
  <tr><td align="center" style="padding:24px;">
    <table width="620" cellpadding="0" cellspacing="0"
           style="background:#fff;border-radius:8px;overflow:hidden;max-width:620px;width:100%;">
      <tr>
        <td style="background:linear-gradient(135deg,#059669,#10b981);padding:24px 28px;color:#fff;">
          <h1 style="margin:0;font-size:22px;font-weight:800;">&#9889; voltika</h1>
          <p style="margin:8px 0 0;font-size:18px;font-weight:700;">&#9989; ¡Tu moto está lista!</p>
        </td>
      </tr>
      <tr>
        <td style="padding:28px;">
          <p style="margin:0 0 16px;font-size:15px;color:#111;">
            Hola <strong>' . htmlspecialchars($nombre) . '</strong>,
          </p>
          <p style="margin:0 0 20px;font-size:15px;color:#111;line-height:1.6;">
            &#127881; ¡Excelente noticia! Tu motocicleta
            <strong>' . htmlspecialchars($moto['modelo'] . ' ' . $moto['color']) . '</strong>
            ha pasado por el proceso de ensamble y validación exitosamente.
            <br><br>
            <strong style="color:#059669;">Ya puedes pasar a recogerla.</strong>
          </p>
          <table width="100%" cellpadding="8" cellspacing="0"
                 style="border:1px solid #E5E7EB;border-radius:8px;font-size:14px;">
            <tr style="background:#F9FAFB;">
              <td style="color:#6B7280;padding:10px 12px;border-bottom:1px solid #E5E7EB;">Motocicleta</td>
              <td style="font-weight:700;padding:10px 12px;border-bottom:1px solid #E5E7EB;">' . htmlspecialchars($moto['modelo'] . ' ' . $moto['color']) . '</td>
            </tr>
            <tr>
              <td style="color:#6B7280;padding:10px 12px;border-bottom:1px solid #E5E7EB;">Punto de entrega</td>
              <td style="padding:10px 12px;border-bottom:1px solid #E5E7EB;font-weight:700;color:#059669;">' . htmlspecialchars($puntoNombre) . '</td>
            </tr>
            ' . ($moto['pedido_num'] ? "<tr style='background:#F9FAFB;'><td style='color:#6B7280;padding:10px 12px;'>Pedido</td><td style='font-weight:700;padding:10px 12px;'>" . htmlspecialchars($moto['pedido_num']) . "</td></tr>" : '') . '
          </table>
          <div style="background:#d1fae5;border-radius:8px;padding:16px;margin:20px 0 0;text-align:center;">
            <p style="margin:0;font-size:14px;font-weight:700;color:#065f46;">
              &#128205; Preséntate en <strong>' . htmlspecialchars($puntoNombre) . '</strong>
              con tu identificación oficial y número de pedido.
            </p>
          </div>
          <p style="margin:20px 0 0;font-size:13px;color:#9CA3AF;">
            ¿Dudas? <a href="mailto:ventas@voltika.com.mx" style="color:#039fe1;">ventas@voltika.com.mx</a>
          </p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body></html>';

        sendMail(
            $moto['cliente_email'],
            $nombre,
            '✅ ¡Tu Voltika está lista para recoger! — ' . $puntoNombre,
            $emailHtml
        );
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
