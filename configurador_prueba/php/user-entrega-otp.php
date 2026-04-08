<?php
/**
 * Voltika - OTP para confirmación de entrega por el cliente
 * POST { accion: 'buscar', pedido_num }         → datos de la entrega pendiente
 * POST { accion: 'enviar_otp', pedido_num }     → genera y envía OTP por SMS
 * POST { accion: 'verificar', pedido_num, otp } → verifica OTP y confirma entrega
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';

$json      = json_decode(file_get_contents('php://input'), true) ?? [];
$accion    = $json['accion']    ?? 'buscar';
$pedidoNum = trim($json['pedido_num'] ?? '');

if (!$pedidoNum) {
    http_response_code(400);
    echo json_encode(['error' => 'Número de pedido requerido']);
    exit;
}

$pdo = getDB();

// ── BUSCAR orden ──────────────────────────────────────────────────────────────
if ($accion === 'buscar') {
    $stmt = $pdo->prepare("
        SELECT m.id AS moto_id, m.modelo, m.color, m.vin_display,
               m.cliente_nombre, m.cliente_email, m.cliente_telefono,
               m.estado, m.pedido_num, m.punto_nombre,
               e.id AS entrega_id, e.estado AS entrega_estado
        FROM inventario_motos m
        LEFT JOIN entregas e ON e.moto_id = m.id AND e.estado != 'cancelado'
        WHERE m.pedido_num = ? AND m.activo = 1
        ORDER BY e.freg DESC
        LIMIT 1
    ");
    $stmt->execute([$pedidoNum]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Número de pedido no encontrado']);
        exit;
    }

    if ($row['estado'] === 'entregada') {
        echo json_encode(['ok' => false, 'error' => 'Esta moto ya fue entregada', 'entregada' => true]);
        exit;
    }

    if (!in_array($row['estado'], ['por_validar_entrega', 'lista_para_entrega'])) {
        echo json_encode([
            'ok'    => false,
            'error' => 'Tu moto aún no está lista para entrega. Estado actual: ' . $row['estado'],
            'estado'=> $row['estado'],
        ]);
        exit;
    }

    // Mask phone
    $tel = $row['cliente_telefono'];
    $telMask = strlen($tel) >= 4 ? '****' . substr($tel, -4) : '****';

    echo json_encode([
        'ok'     => true,
        'moto_id'=> (int)$row['moto_id'],
        'data'   => [
            'cliente_nombre'  => $row['cliente_nombre'],
            'modelo'          => $row['modelo'],
            'color'           => $row['color'],
            'vin_display'     => $row['vin_display'],
            'estado'          => $row['estado'],
            'punto_nombre'    => $row['punto_nombre'],
            'telefono_mask'   => $telMask,
            'entrega_id'      => $row['entrega_id'] ? (int)$row['entrega_id'] : null,
        ]
    ]);
    exit;
}

// ── ENVIAR OTP ────────────────────────────────────────────────────────────────
if ($accion === 'enviar_otp') {
    // Get moto
    $stmt = $pdo->prepare("
        SELECT id, cliente_nombre, cliente_telefono, cliente_email, estado, modelo, color, punto_nombre
        FROM inventario_motos
        WHERE pedido_num = ? AND activo = 1
        LIMIT 1
    ");
    $stmt->execute([$pedidoNum]);
    $moto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$moto) {
        echo json_encode(['ok' => false, 'error' => 'Pedido no encontrado']); exit;
    }

    if (!in_array($moto['estado'], ['por_validar_entrega','lista_para_entrega'])) {
        echo json_encode(['ok' => false, 'error' => 'Moto no disponible para entrega']); exit;
    }

    // Generate 6-digit OTP
    $otp     = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', time() + 600); // 10 min

    // Upsert entrega record
    $existingStmt = $pdo->prepare("SELECT id FROM entregas WHERE moto_id = ? AND estado NOT IN ('confirmado','cancelado') LIMIT 1");
    $existingStmt->execute([$moto['id']]);
    $existing = $existingStmt->fetch();

    if ($existing) {
        $pdo->prepare("UPDATE entregas SET otp_code = ?, otp_expires = ?, estado = 'otp_enviado', otp_verified = 0 WHERE id = ?")
            ->execute([$otp, $expires, $existing['id']]);
        $entregaId = $existing['id'];
    } else {
        $pdo->prepare("
            INSERT INTO entregas (moto_id, pedido_num, cliente_nombre, cliente_email, cliente_telefono,
                                  otp_code, otp_expires, estado)
            VALUES (?,?,?,?,?,?,?,'otp_enviado')
        ")->execute([
            $moto['id'], $pedidoNum,
            $moto['cliente_nombre'], $moto['cliente_email'], $moto['cliente_telefono'],
            $otp, $expires
        ]);
        $entregaId = $pdo->lastInsertId();
    }

    // Send OTP via SMS (SMSMasivos)
    $smsSent = false;
    $tel = preg_replace('/[^0-9]/', '', $moto['cliente_telefono']);
    if (strlen($tel) >= 10) {
        $msg = "Voltika - Tu codigo de entrega es: $otp. Vigente 10 minutos. No lo compartas.";
        $url = "https://smsc.smsmasivos.com.mx/enviar?" . http_build_query([
            'ak'   => SMSMASIVOS_API_KEY,
            'to'   => $tel,
            'msg'  => $msg,
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $smsSent = !empty($resp);
    }

    // Fallback: send OTP by email
    $emailSent = false;
    if (!empty($moto['cliente_email'])) {
        $emailBody = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;">
<div style="max-width:480px;margin:0 auto;padding:24px;">
<img src="https://www.voltika.mx/configurador_prueba/img/voltika_logo_h_white.svg"
     style="height:36px;background:#1a3a5c;padding:8px 16px;border-radius:8px;margin-bottom:20px;">
<h2 style="color:#1a3a5c;">Código de confirmación de entrega</h2>
<p>Hola <strong>' . htmlspecialchars($moto['cliente_nombre']) . '</strong>,</p>
<p>Usa este código para confirmar la entrega de tu <strong>' . htmlspecialchars($moto['modelo']) . ' ' . htmlspecialchars($moto['color']) . '</strong>:</p>
<div style="font-size:40px;font-weight:900;letter-spacing:10px;color:#039fe1;text-align:center;padding:20px 0;">' . $otp . '</div>
<p style="color:#888;font-size:13px;">Este código expira en <strong>10 minutos</strong>. No lo compartas con nadie.</p>
<p style="font-size:13px;color:#888;">Punto de entrega: ' . htmlspecialchars($moto['punto_nombre']) . '</p>
</div></body></html>';
        $emailSent = sendMail($moto['cliente_email'], $moto['cliente_nombre'],
            'Voltika - Código de entrega #' . $pedidoNum, $emailBody);
    }

    $telMask = strlen($moto['cliente_telefono']) >= 4
        ? '****' . substr(preg_replace('/[^0-9]/', '', $moto['cliente_telefono']), -4)
        : '****';

    echo json_encode([
        'ok'          => true,
        'entrega_id'  => (int)$entregaId,
        'telefono_mask'=> $telMask,
        'sms_sent'    => $smsSent,
        'email_sent'  => $emailSent,
    ]);
    exit;
}

// ── VERIFICAR OTP ─────────────────────────────────────────────────────────────
if ($accion === 'verificar') {
    $otpInput = trim($json['otp'] ?? '');

    if (!$otpInput) {
        http_response_code(400);
        echo json_encode(['error' => 'Código OTP requerido']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT e.*, m.id AS moto_db_id, m.modelo, m.color, m.punto_nombre,
               m.cliente_nombre, m.vin_display
        FROM entregas e
        JOIN inventario_motos m ON m.id = e.moto_id
        WHERE e.pedido_num = ?
          AND e.estado = 'otp_enviado'
          AND e.otp_verified = 0
        ORDER BY e.freg DESC
        LIMIT 1
    ");
    $stmt->execute([$pedidoNum]);
    $entrega = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$entrega) {
        echo json_encode(['ok' => false, 'error' => 'No hay entrega pendiente para este pedido']); exit;
    }

    // Check expiry
    if (strtotime($entrega['otp_expires']) < time()) {
        echo json_encode(['ok' => false, 'error' => 'El código ha expirado. Solicita uno nuevo.']); exit;
    }

    // Verify OTP
    if ($entrega['otp_code'] !== $otpInput) {
        echo json_encode(['ok' => false, 'error' => 'Código incorrecto. Verifica e intenta de nuevo.']); exit;
    }

    // Mark entrega as confirmed
    $pdo->prepare("
        UPDATE entregas SET otp_verified = 1, otp_verified_at = NOW(),
                            estado = 'confirmado', fecha_entrega = NOW()
        WHERE id = ?
    ")->execute([$entrega['id']]);

    // Mark moto as entregada
    $logEntry = [[
        'estado'    => 'entregada',
        'accion'    => 'entrega_confirmada_otp',
        'timestamp' => date('Y-m-d H:i:s'),
        'notas'     => 'Entrega confirmada por cliente via OTP',
    ]];
    $pdo->prepare("
        UPDATE inventario_motos
        SET estado = 'entregada', fecha_estado = NOW(),
            log_estados = JSON_ARRAY_APPEND(IFNULL(log_estados, JSON_ARRAY()), '$', CAST(? AS JSON))
        WHERE id = ?
    ")->execute([json_encode($logEntry[0], JSON_UNESCAPED_UNICODE), $entrega['moto_db_id']]);

    // Log venta
    $pdo->prepare("
        INSERT INTO ventas_log (moto_id, tipo, cliente_nombre, cliente_email, cliente_telefono,
                                pedido_num, modelo, color, notas)
        SELECT m.id, 'entrega_voltika', m.cliente_nombre, m.cliente_email, m.cliente_telefono,
               m.pedido_num, m.modelo, m.color, 'Entrega confirmada por OTP del cliente'
        FROM inventario_motos m WHERE m.id = ?
    ")->execute([$entrega['moto_db_id']]);

    // Send confirmation email to client
    if (!empty($entrega['cliente_email'])) {
        $body = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;background:#f5f7fa;">
<div style="max-width:520px;margin:0 auto;padding:24px;">
<div style="background:linear-gradient(135deg,#1a3a5c,#039fe1);padding:24px;border-radius:12px 12px 0 0;text-align:center;">
<img src="https://www.voltika.mx/configurador_prueba/img/voltika_logo_h_white.svg" style="height:36px;">
</div>
<div style="background:#fff;padding:28px;border-radius:0 0 12px 12px;box-shadow:0 4px 20px rgba(0,0,0,.1);">
<h2 style="color:#1a3a5c;margin:0 0 12px;">¡Tu Voltika fue entregada! 🎉</h2>
<p style="color:#555;font-size:14px;">Hola <strong>' . htmlspecialchars($entrega['cliente_nombre']) . '</strong>, confirmamos que recibiste tu moto correctamente.</p>
<div style="background:#f0faff;border-radius:8px;padding:16px;margin:16px 0;">
<p style="margin:4px 0;font-size:13px;color:#1a3a5c;"><strong>Moto:</strong> ' . htmlspecialchars($entrega['modelo'] ?? '') . ' ' . htmlspecialchars($entrega['color'] ?? '') . '</p>
<p style="margin:4px 0;font-size:13px;color:#1a3a5c;"><strong>VIN:</strong> ' . htmlspecialchars($entrega['vin_display'] ?? '') . '</p>
<p style="margin:4px 0;font-size:13px;color:#1a3a5c;"><strong>Punto:</strong> ' . htmlspecialchars($entrega['punto_nombre'] ?? '') . '</p>
<p style="margin:4px 0;font-size:13px;color:#1a3a5c;"><strong>Fecha:</strong> ' . date('d/m/Y H:i') . '</p>
</div>
<p style="color:#888;font-size:12px;">¡Disfruta tu Voltika! Para soporte escríbenos a redes@voltika.mx</p>
</div></div></body></html>';

        sendMail($entrega['cliente_email'], $entrega['cliente_nombre'],
            'Tu Voltika fue entregada - Pedido #' . $pedidoNum, $body);
    }

    echo json_encode([
        'ok'      => true,
        'mensaje' => '¡Entrega confirmada! Disfruta tu Voltika.',
        'data'    => [
            'cliente_nombre' => $entrega['cliente_nombre'],
            'modelo'         => $entrega['modelo'],
            'color'          => $entrega['color'],
            'punto_nombre'   => $entrega['punto_nombre'],
            'fecha_entrega'  => date('d/m/Y H:i'),
        ]
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Acción no reconocida']);
