<?php
/**
 * Voltika Configurador - Confirmar orden post-pago
 * Guarda la orden en DB y envia email de confirmacion al cliente
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Central config ───────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';

// ── Request ───────────────────────────────────────────────────────────────────
$json = json_decode(file_get_contents('php://input'), true);

if (!$json) {
    http_response_code(400);
    echo json_encode(['error' => 'Request invalido']);
    exit;
}

$paymentIntentId = $json['paymentIntentId'] ?? '';
$pagoTipo        = $json['pagoTipo']        ?? 'unico';
$nombre          = $json['nombre']          ?? '';
$email           = $json['email']           ?? '';
$telefono        = $json['telefono']        ?? '';
$modelo          = $json['modelo']          ?? '';
$color           = $json['color']           ?? '';
$ciudad          = $json['ciudad']          ?? '';
$estado          = $json['estado']          ?? '';
$cp              = $json['cp']              ?? '';
$total           = floatval($json['total']  ?? 0);
$msiPago         = floatval($json['msiPago'] ?? 0);
$msiMeses        = intval($json['msiMeses'] ?? 9);
$asesoriaPlacas  = !empty($json['asesoriaPlacas']) ? 'Sí' : 'No';
$seguroQualitas  = !empty($json['seguroQualitas']) ? 'Sí' : 'No';
// Persisted as int flags (Sí/No is only used for the email body below)
$asesoriaPlacasInt = !empty($json['asesoriaPlacas']) ? 1 : 0;
$seguroQualitasInt = !empty($json['seguroQualitas']) ? 1 : 0;
// Selected delivery point (set in paso3-delivery.js → state.centroEntrega)
$puntoId     = trim($json['punto_id']     ?? '');
$puntoNombre = trim($json['punto_nombre'] ?? '');
$puntoTipo   = trim($json['punto_tipo']   ?? '');
// CODIGO REFERIDO (validated in paso2-color.js via validar-referido.php)
$codigoReferido = strtoupper(trim($json['codigo_referido'] ?? ''));
$referidoId     = isset($json['referido_id']) && $json['referido_id'] !== null ? intval($json['referido_id']) : null;
$referidoTipo   = trim($json['referido_tipo'] ?? ''); // 'referido' | 'punto' | ''

// ─ Purchase case number per dashboards_diagrams.pdf ─────────────────────────
//   CASE 1-A — no referido code, no point selected (punto_id = 'centro-cercano')
//   CASE 1-B — no referido code, user picked a point in the configurador
//   CASE 3   — referido code present (influencer or point), general sale (online)
//   CASE 4   — sale from a point's showroom inventory (handled by puntosvoltika/php/asignar/referido.php)
// The PDF has no "CASE 2" — the YES/NO point-selector branch inside CASE 1
// is a sub-flow of CASE 1.
$caso = 1;
if ($codigoReferido !== '') {
    $caso = 3;
}

// CASE 3 — if the referido code is a PUNTO code (not influencer), auto-assign
// the order to that point. Per the PDF, the order should land on the Point
// Panel as "completed sale via referral, pending motorcycle assignment" without
// any manual point selection step.
$puntoVoltikaId = null;
if ($caso === 3 && $referidoTipo === 'punto' && $referidoId) {
    try {
        $pdoTmp = getDB();
        $pStmt = $pdoTmp->prepare("SELECT id, nombre FROM puntos_voltika WHERE id = ? AND activo = 1 LIMIT 1");
        $pStmt->execute([(int)$referidoId]);
        $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
        if ($pRow) {
            $puntoVoltikaId = (int)$pRow['id'];
            // Override any stale puntoId/puntoNombre with the referral point
            $puntoId     = 'punto-' . $puntoVoltikaId;
            $puntoNombre = $pRow['nombre'];
        }
    } catch (Throwable $e) {
        error_log('confirmar-orden CASE3 punto lookup: ' . $e->getMessage());
    }
}

// Entropy-enriched pedido number — plain time() collides when two users
// confirm within the same second, which was making the second INSERT silently
// fail on any future UNIQUE constraint and leaving the order invisible to
// the admin dashboard (see listar.php).
$pedidoNum = time() . '-' . substr(bin2hex(random_bytes(3)), 0, 4);
$fecha     = date('Y-m-d H:i');

// ── Guardar en BD ─────────────────────────────────────────────────────────────
$dbSaveOk = false;
$dbSaveErr = '';
try {
    $pdo = getDB();

    // Recovery table: any orphan orders we couldn't write to `transacciones`
    // land here so the admin dashboard can recover them manually. Previously
    // a failed INSERT was swallowed silently, making the sale invisible.
    $pdo->exec("CREATE TABLE IF NOT EXISTS transacciones_errores (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        nombre    VARCHAR(200),
        email     VARCHAR(200),
        telefono  VARCHAR(30),
        modelo    VARCHAR(200),
        color     VARCHAR(100),
        total     DECIMAL(12,2),
        stripe_pi VARCHAR(100),
        payload   TEXT,
        error_msg TEXT,
        freg      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS transacciones (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        nombre     VARCHAR(200),
        email      VARCHAR(200),
        telefono   VARCHAR(30),
        modelo     VARCHAR(200),
        color      VARCHAR(100),
        ciudad     VARCHAR(100),
        estado     VARCHAR(100),
        cp         VARCHAR(10),
        tpago      VARCHAR(50),
        precio     DECIMAL(12,2),
        total      DECIMAL(12,2),
        freg       DATETIME DEFAULT CURRENT_TIMESTAMP,
        pedido     VARCHAR(20),
        stripe_pi  VARCHAR(100),
        asesoria_placas TINYINT(1) NOT NULL DEFAULT 0,
        seguro_qualitas TINYINT(1) NOT NULL DEFAULT 0,
        punto_id        VARCHAR(80)  NULL,
        punto_nombre    VARCHAR(200) NULL,
        msi_meses       INT          NULL,
        msi_pago        DECIMAL(12,2) NULL,
        referido        VARCHAR(40)  NULL,
        referido_id     INT          NULL,
        referido_tipo   VARCHAR(20)  NULL,
        caso            TINYINT      NULL
    )");
    // Backfill columns on pre-existing tables
    ensureTransaccionesColumns($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO transacciones
            (nombre, email, telefono, modelo, color, ciudad, estado, cp, tpago,
             precio, total, freg, pedido, stripe_pi,
             asesoria_placas, seguro_qualitas, punto_id, punto_nombre,
             msi_meses, msi_pago, referido, referido_id, referido_tipo, caso)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $nombre, $email, $telefono, $modelo, $color,
        $ciudad, $estado, $cp, $pagoTipo,
        $pagoTipo === 'msi' ? $msiPago : $total,
        $total, $fecha, $pedidoNum, $paymentIntentId,
        $asesoriaPlacasInt, $seguroQualitasInt,
        $puntoId ?: null, $puntoNombre ?: null,
        $pagoTipo === 'msi' ? $msiMeses : null,
        $pagoTipo === 'msi' ? $msiPago  : null,
        $codigoReferido ?: null,
        $referidoId,
        $referidoTipo ?: null,
        $caso,
    ]);
    $dbSaveOk = true;
    // ── Auto-crear registro en inventario_motos para el dealer panel ────────
    $vinAuto = 'VK-' . strtoupper(substr($modelo, 0, 3)) . '-' . $pedidoNum;
    $vinDisplay = $vinAuto;
    $logEstados = json_encode([[
        'estado'    => 'por_llegar',
        'accion'    => 'pedido_confirmado',
        'timestamp' => $fecha,
        'dealer'    => 'sistema',
        'notas'     => 'Creado automáticamente al confirmar orden #' . $pedidoNum
    ]]);

    try {
        // Get the transaccion ID we just inserted
        $txId = $pdo->lastInsertId();

        // For CASE 3 referral sales, tipo_asignacion is 'voltika_entrega' (online),
        // but the punto_voltika_id is pre-set from the referral code so the order
        // lands directly in the referred point's queue.
        $stmtMoto = $pdo->prepare("
            INSERT INTO inventario_motos
                (vin, vin_display, modelo, color, tipo_asignacion, estado,
                 cliente_nombre, cliente_email, cliente_telefono,
                 pedido_num, pago_estado, fecha_estado, log_estados, precio_venta, notas,
                 stripe_pi, transaccion_id, punto_id, punto_nombre, punto_voltika_id)
            VALUES
                (?, ?, ?, ?, 'voltika_entrega', 'por_llegar',
                 ?, ?, ?,
                 ?, ?, NOW(), ?, ?, ?,
                 ?, ?, ?, ?, ?)
        ");
        $stmtMoto->execute([
            $vinAuto, $vinDisplay, $modelo, $color,
            $nombre, $email, $telefono,
            'VK-' . $pedidoNum,
            $pagoTipo === 'enganche' ? 'parcial' : 'pagada',
            $logEstados, $total,
            'Pedido confirmado vía configurador. Tipo: ' . $pagoTipo
                . ($puntoNombre ? ' · Punto preferido: ' . $puntoNombre : '')
                . ($caso === 3 ? ' · CASO 3 (referido)' : ''),
            $paymentIntentId ?: null,
            $txId ?: null,
            $puntoId     ?: null,
            $puntoNombre ?: null,
            $puntoVoltikaId,
        ]);
    } catch (PDOException $e) {
        error_log('Voltika inventario_motos auto-insert error: ' . $e->getMessage());
    }

    // Bump referido counter if a valid referido_id was provided (tipo='referido')
    if ($referidoId && $referidoTipo === 'referido') {
        try {
            $pdo->prepare("
                UPDATE referidos
                SET ventas_count = ventas_count + 1
                WHERE id = ? AND activo = 1
            ")->execute([$referidoId]);
        } catch (PDOException $e) {
            error_log('Voltika referidos counter error: ' . $e->getMessage());
        }
    }

    // CASE 3 (dashboards_diagrams.pdf): the order was placed with a punto's
    // CODIGO REFERIDO for a general sale — bump the punto's ventas_count so it
    // shows up in punto reports alongside influencer referrals.
    if ($puntoVoltikaId && $referidoTipo === 'punto') {
        try {
            $pvCols = $pdo->query("SHOW COLUMNS FROM puntos_voltika")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('ventas_count', $pvCols, true)) {
                $pdo->exec("ALTER TABLE puntos_voltika ADD COLUMN ventas_count INT DEFAULT 0");
            }
            $pdo->prepare("
                UPDATE puntos_voltika
                SET ventas_count = COALESCE(ventas_count, 0) + 1
                WHERE id = ? AND activo = 1
            ")->execute([$puntoVoltikaId]);
        } catch (PDOException $e) {
            error_log('Voltika punto ventas_count error: ' . $e->getMessage());
        }
    }

} catch (PDOException $e) {
    // El pago ya se capturó en Stripe, así que NO podemos abortar sin dejar
    // una orden huérfana. Escribimos la orden a `transacciones_errores` para
    // que el admin la vea en el dashboard de ventas (ver admin/php/ventas/listar.php).
    $dbSaveErr = $e->getMessage();
    error_log('Voltika DB error: ' . $dbSaveErr);
    try {
        $errPdo = getDB();
        $errStmt = $errPdo->prepare("
            INSERT INTO transacciones_errores
                (nombre, email, telefono, modelo, color, total, stripe_pi, payload, error_msg)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $errStmt->execute([
            $nombre, $email, $telefono, $modelo, $color, $total,
            $paymentIntentId, json_encode($json), $dbSaveErr,
        ]);
    } catch (Throwable $e2) {
        error_log('Voltika transacciones_errores fallback failed: ' . $e2->getMessage());
    }
}

// ── Enviar email de confirmacion ──────────────────────────────────────────────
$enganchePct    = floatval($json['enganchePct'] ?? 0);
$plazoMeses     = intval($json['plazoMeses'] ?? 36);
$pagoSemanal    = floatval($json['pagoSemanal'] ?? 0);
$folioContrato  = $json['folioContrato'] ?? ('VK-' . date('Ymd') . '-' . strtoupper(substr($nombre, 0, 3)));
$metodoPago     = $json['metodoPago'] ?? $pagoTipo;
$esCredito      = ($pagoTipo === 'enganche' || $metodoPago === 'credito');

if ($pagoTipo === 'msi') {
    $pagoDescripcion = $msiMeses . ' pagos de $' . number_format($msiPago, 0, '.', ',') . ' MXN sin intereses';
    $montoFormateado = '$' . number_format($msiPago, 0, '.', ',') . ' MXN';
} else {
    $pagoDescripcion = 'Pago de Contado';
    $montoFormateado = '$' . number_format($total, 0, '.', ',') . ' MXN';
}
$engancheFormateado = '$' . number_format($total, 0, '.', ',') . ' MXN';
$pagoSemanalFormateado = '$' . number_format($pagoSemanal, 0, '.', ',') . ' MXN';
$plazoTexto = $plazoMeses . ' meses (' . round($plazoMeses * 4.33) . ' semanas)';
$whatsapp = '+52 55 1341 6370';
$n = htmlspecialchars($nombre);
$m = htmlspecialchars($modelo);
$c = htmlspecialchars($color);
$cd = htmlspecialchars($ciudad) . ($estado ? ', ' . htmlspecialchars($estado) : '');

$td = 'style="padding:10px 16px;border-bottom:1px solid #E5E7EB;font-size:14px;"';
$tdl = 'style="padding:10px 16px;border-bottom:1px solid #E5E7EB;font-size:14px;color:#6B7280;"';
$section = 'style="margin:0 0 8px;padding:12px 0 6px;font-size:16px;font-weight:800;color:#1a3a5c;border-bottom:2px solid #039fe1;"';

$cuerpo = '<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tu Voltika está confirmada</title></head>
<body style="margin:0;padding:0;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;">
<tr><td align="center" style="padding:24px 12px;">
<table width="620" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;max-width:620px;width:100%;box-shadow:0 2px 12px rgba(0,0,0,0.08);">

<!-- Header -->
<tr><td style="background:linear-gradient(135deg,#1a3a5c,#039fe1);padding:28px;text-align:center;">
<img src="https://www.voltika.mx/configurador_prueba/img/voltika_logo_h_white.svg" alt="Voltika" style="height:44px;width:auto;display:block;margin:0 auto;">
<p style="margin:6px 0 0;font-size:13px;color:rgba(255,255,255,0.8);">Movilidad eléctrica inteligente</p>
</td></tr>

<!-- Body -->
<tr><td style="padding:28px;">

<h2 style="margin:0 0 8px;font-size:20px;color:#1a3a5c;">Tu Voltika está confirmada.</h2>
<p style="margin:0 0 20px;font-size:14px;color:#555;line-height:1.7;">Gracias por tu compra. Hemos recibido tu pago correctamente y tu orden ya está en proceso.</p>
<p style="margin:0 0 24px;font-size:14px;color:#555;line-height:1.7;">A partir de este momento, nuestro equipo dará seguimiento a tu entrega para que recibas tu moto de forma segura y sin complicaciones.</p>

<!-- DETALLE DE TU COMPRA -->
<div ' . $section . '>DETALLE DE TU COMPRA</div>
<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
<tr><td ' . $tdl . '>Número de orden</td><td ' . $td . '><strong>#' . $pedidoNum . '</strong></td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Modelo</td><td ' . $td . '>' . $m . '</td></tr>
<tr><td ' . $tdl . '>Color</td><td ' . $td . '>' . $c . '</td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Ciudad de entrega</td><td ' . $td . '>' . $cd . '</td></tr>
<tr><td ' . $tdl . '>Monto pagado</td><td ' . $td . '><strong style="color:#039fe1;">' . $montoFormateado . '</strong></td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Método de pago</td><td ' . $td . '>' . htmlspecialchars($pagoDescripcion) . '</td></tr>
<tr><td ' . $tdl . '>Asesoría para placas</td><td ' . $td . '>' . $asesoriaPlacas . '</td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Seguro Qualitas</td><td ' . $td . '>' . $seguroQualitas . '</td></tr>
</table>
<p style="font-size:10px;color:#999;line-height:1.5;margin:6px 0 16px;">Voltika solo sugiere gestores y seguros de terceros. No es responsable por su servicio, tiempos, costos ni cobertura. La contratación es responsabilidad del cliente.</p>

<!-- QUÉ SIGUE -->
<div ' . $section . '>¿QUÉ SIGUE?</div>
<div style="margin-bottom:24px;font-size:14px;color:#555;line-height:1.8;">
<p style="margin:12px 0 4px;"><strong style="color:#1a3a5c;">1. Asignación de punto de entrega</strong></p>
<p style="margin:0 0 12px;">En menos de 48 horas te confirmaremos el punto Voltika autorizado más cercano a tu ubicación.</p>
<p style="margin:0 0 4px;"><strong style="color:#1a3a5c;">2. Confirmación de entrega</strong></p>
<p style="margin:0 0 12px;">Recibirás por correo y WhatsApp los datos del punto, dirección y fecha estimada.</p>
<p style="margin:0 0 4px;"><strong style="color:#1a3a5c;">3. Entrega de tu Voltika</strong></p>
<p style="margin:0;">Acudes al punto asignado, validas tu identidad y recibes tu moto lista para rodar.</p>
</div>

<!-- CUÁNDO RECIBO -->
<div ' . $section . '>¿CUÁNDO RECIBO MI VOLTIKA?</div>
<p style="font-size:14px;color:#555;line-height:1.7;margin:12px 0 24px;">El tiempo de entrega depende de disponibilidad y logística en tu zona.<br>Tu asesor Voltika te confirmará la fecha exacta junto con el punto asignado.</p>

<!-- ENTREGA SEGURA -->
<div ' . $section . '>ENTREGA SEGURA (IMPORTANTE)</div>
<div style="background:#FFF8E1;border-radius:8px;padding:16px;margin:12px 0 24px;border-left:4px solid #FFB300;">
<p style="margin:0 0 10px;font-size:14px;color:#333;font-weight:700;">🔒 Tu número celular es tu llave de entrega.</p>
<p style="margin:0 0 8px;font-size:13px;color:#555;">Para recibir tu Voltika deberás:</p>
<ul style="margin:0 0 10px;padding-left:20px;font-size:13px;color:#555;line-height:1.8;">
<li>Tener acceso a tu número registrado</li>
<li>Validar un código de seguridad (OTP)</li>
<li>Presentar identificación oficial</li>
<li>Confirmar datos de tu compra</li>
</ul>
<p style="margin:0;font-size:13px;color:#555;">Para garantizar una entrega segura, podremos solicitar información adicional como apellidos o confirmación de tu orden.</p>
</div>

<!-- INFO PAGO -->
<div ' . $section . '>INFORMACIÓN SOBRE TU PAGO</div>
<p style="font-size:14px;color:#555;line-height:1.7;margin:12px 0;">Tu compra ha sido procesada correctamente.</p>
' . ($pagoTipo === 'msi' ? '<p style="font-size:13px;color:#555;line-height:1.7;margin:0 0 24px;">En caso de meses sin intereses:<br>• Tu banco aplicará los cargos mensuales correspondientes<br>• Podrás ver los cargos reflejados en tu estado de cuenta</p>' : '<p style="margin:0 0 24px;"></p>') . '

<!-- CAMBIO DE DATOS -->
<div ' . $section . '>CAMBIO DE DATOS</div>
<p style="font-size:14px;color:#555;line-height:1.7;margin:12px 0 24px;">Si necesitas actualizar tu número telefónico o ciudad de entrega, debes solicitarlo antes de la asignación de tu punto de entrega.</p>

<!-- SOPORTE -->
<div ' . $section . '>SOPORTE Y ATENCIÓN</div>
<p style="font-size:14px;color:#555;margin:12px 0 8px;">Estamos contigo en todo momento.</p>
<p style="font-size:14px;margin:0 0 4px;">📱 WhatsApp: <a href="https://wa.me/5213416370" style="color:#039fe1;font-weight:700;">' . $whatsapp . '</a></p>
<p style="font-size:14px;margin:0 0 24px;">📧 Correo: <a href="mailto:redes@voltika.mx" style="color:#039fe1;font-weight:700;">redes@voltika.mx</a></p>

<!-- TÉRMINOS -->
<div style="background:#F5F5F5;border-radius:8px;padding:16px;margin-top:8px;">
<p style="font-size:12px;color:#888;margin:0 0 6px;">Tu compra está protegida bajo nuestros:</p>
<p style="font-size:12px;margin:0 0 4px;"><a href="https://voltika.mx/docs/tyc_2026.pdf" style="color:#039fe1;">Términos y Condiciones</a></p>
<p style="font-size:12px;margin:0 0 8px;"><a href="https://voltika.mx/docs/privacidad_2026.pdf" style="color:#039fe1;">Aviso de Privacidad</a></p>
<p style="font-size:11px;color:#aaa;margin:0;">Al completar tu compra aceptaste nuestros Términos y Condiciones y Aviso de Privacidad.</p>
</div>

</td></tr>

<!-- Footer -->
<tr><td style="background:#1a3a5c;padding:20px 28px;text-align:center;">
<img src="https://www.voltika.mx/configurador_prueba/img/goelectric.svg" alt="GO electric" style="height:28px;width:auto;margin-bottom:8px;">
<p style="margin:0 0 4px;font-size:14px;font-weight:700;color:#fff;">Voltika México</p>
<p style="margin:0;font-size:12px;color:rgba(255,255,255,0.6);">Movilidad eléctrica inteligente · Mtech Gears, S.A. de C.V.</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>';

// ── Crédito email template ────────────────────────────────────────────────────
if ($esCredito) {
    $asunto = 'Tu Voltika está confirmada a crédito, Orden #' . $pedidoNum;

    $cuerpo = '<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tu Voltika está confirmada a crédito</title></head>
<body style="margin:0;padding:0;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;">
<tr><td align="center" style="padding:24px 12px;">
<table width="620" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;max-width:620px;width:100%;box-shadow:0 2px 12px rgba(0,0,0,0.08);">

<tr><td style="background:linear-gradient(135deg,#1a3a5c,#039fe1);padding:28px;text-align:center;">
<img src="https://www.voltika.mx/configurador_prueba/img/voltika_logo_h_white.svg" alt="Voltika" style="height:44px;width:auto;display:block;margin:0 auto;">
<p style="margin:6px 0 0;font-size:13px;color:rgba(255,255,255,0.8);">Movilidad eléctrica inteligente</p>
</td></tr>

<tr><td style="padding:28px;">

<h2 style="margin:0 0 8px;font-size:20px;color:#1a3a5c;">Tu Voltika está confirmada.</h2>
<p style="margin:0 0 20px;font-size:14px;color:#555;line-height:1.7;">Gracias por tu compra. Tu crédito Voltika ha sido aprobado y tu orden ya está en proceso.</p>
<p style="margin:0 0 24px;font-size:14px;color:#555;line-height:1.7;">A partir de este momento, nuestro equipo dará seguimiento a tu entrega paso a paso para que recibas tu moto de forma segura y sin complicaciones.</p>

<div ' . $section . '>DETALLE DE TU COMPRA</div>
<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
<tr><td ' . $tdl . '>Número de orden</td><td ' . $td . '><strong>#' . $pedidoNum . '</strong></td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Modelo</td><td ' . $td . '>' . $m . '</td></tr>
<tr><td ' . $tdl . '>Color</td><td ' . $td . '>' . $c . '</td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Ciudad de entrega</td><td ' . $td . '>' . $cd . '</td></tr>
<tr><td ' . $tdl . '>Enganche pagado</td><td ' . $td . '><strong style="color:#039fe1;">' . $engancheFormateado . '</strong></td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Pago semanal</td><td ' . $td . '><strong style="color:#039fe1;">' . $pagoSemanalFormateado . '</strong></td></tr>
<tr><td ' . $tdl . '>Plazo</td><td ' . $td . '>' . $plazoTexto . '</td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Folio de Contrato</td><td ' . $td . '><strong>' . htmlspecialchars($folioContrato) . '</strong></td></tr>
<tr><td ' . $tdl . '>Asesoría para placas</td><td ' . $td . '>' . $asesoriaPlacas . '</td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Seguro Qualitas</td><td ' . $td . '>' . $seguroQualitas . '</td></tr>
</table>
<p style="font-size:10px;color:#999;line-height:1.5;margin:6px 0 16px;">Voltika solo sugiere gestores y seguros de terceros. No es responsable por su servicio, tiempos, costos ni cobertura. La contratación es responsabilidad del cliente.</p>

<div ' . $section . '>¿QUÉ SIGUE?</div>
<div style="margin-bottom:24px;font-size:14px;color:#555;line-height:1.8;">
<p style="margin:12px 0 4px;"><strong style="color:#1a3a5c;">1. Asignación de punto de entrega</strong></p>
<p style="margin:0 0 12px;">En menos de 48 horas te confirmaremos el punto Voltika autorizado más cercano a tu ubicación.</p>
<p style="margin:0 0 4px;"><strong style="color:#1a3a5c;">2. Confirmación de entrega</strong></p>
<p style="margin:0 0 12px;">Recibirás por correo y WhatsApp los datos del punto, dirección y fecha estimada.</p>
<p style="margin:0 0 4px;"><strong style="color:#1a3a5c;">3. Entrega de tu Voltika</strong></p>
<p style="margin:0;">Acudes al punto asignado, validas tu identidad y recibes tu moto lista para rodar.</p>
</div>

<div ' . $section . '>¿CUÁNDO RECIBO MI VOLTIKA?</div>
<p style="font-size:14px;color:#555;line-height:1.7;margin:12px 0 24px;">El tiempo de entrega depende de disponibilidad y logística en tu zona.<br>Tu asesor Voltika te confirmará la fecha exacta junto con el punto asignado.</p>

<div ' . $section . '>ENTREGA SEGURA (IMPORTANTE)</div>
<div style="background:#FFF8E1;border-radius:8px;padding:16px;margin:12px 0 24px;border-left:4px solid #FFB300;">
<p style="margin:0 0 10px;font-size:14px;color:#333;font-weight:700;">🔒 Tu número celular es tu llave de entrega.</p>
<p style="margin:0 0 8px;font-size:13px;color:#555;">Para recibir tu Voltika deberás:</p>
<ul style="margin:0 0 10px;padding-left:20px;font-size:13px;color:#555;line-height:1.8;">
<li>Tener acceso a tu número registrado</li>
<li>Validar un código de seguridad (OTP)</li>
<li>Presentar identificación oficial</li>
<li>Confirmar datos de tu compra</li>
</ul>
<p style="margin:0;font-size:13px;color:#555;">Para garantizar una entrega segura, podremos solicitar información adicional como apellidos o confirmación de tu orden.</p>
</div>

<div ' . $section . '>INFORMACIÓN SOBRE TU CRÉDITO</div>
<p style="font-size:14px;color:#555;line-height:1.7;margin:12px 0 8px;">Tu crédito Voltika se gestiona mediante cargos automáticos con el método de pago registrado.</p>
<ul style="margin:0 0 24px;padding-left:20px;font-size:13px;color:#555;line-height:1.8;">
<li>Mantén saldo disponible para evitar interrupciones</li>
<li>Podrás consultar y gestionar tu crédito con nuestro equipo</li>
<li>Las condiciones completas de tu financiamiento están definidas en tu contrato</li>
</ul>

<div ' . $section . '>CAMBIO DE DATOS</div>
<p style="font-size:14px;color:#555;line-height:1.7;margin:12px 0 24px;">Si necesitas actualizar tu número telefónico, ciudad de entrega o método de pago, debes solicitarlo antes de la asignación de tu punto o previo a la gestión de tu crédito.</p>

<div ' . $section . '>SOPORTE Y ATENCIÓN</div>
<p style="font-size:14px;color:#555;margin:12px 0 8px;">Estamos contigo en todo momento.</p>
<p style="font-size:14px;margin:0 0 4px;">📱 WhatsApp: <a href="https://wa.me/5213416370" style="color:#039fe1;font-weight:700;">' . $whatsapp . '</a></p>
<p style="font-size:14px;margin:0 0 24px;">📧 Correo: <a href="mailto:redes@voltika.mx" style="color:#039fe1;font-weight:700;">redes@voltika.mx</a></p>

<div style="background:#F5F5F5;border-radius:8px;padding:16px;margin-top:8px;">
<p style="font-size:12px;color:#888;margin:0 0 6px;">Tu crédito y compra están protegidos bajo nuestros:</p>
<p style="font-size:12px;margin:0 0 4px;"><a href="https://voltika.mx/docs/tyc_2026.pdf" style="color:#039fe1;">Términos y Condiciones</a></p>
<p style="font-size:12px;margin:0 0 8px;"><a href="https://voltika.mx/docs/privacidad_2026.pdf" style="color:#039fe1;">Aviso de Privacidad</a></p>
<p style="font-size:11px;color:#aaa;margin:0;">Al completar tu compra aceptaste nuestros Términos y Condiciones y Aviso de Privacidad.</p>
</div>

</td></tr>

<tr><td style="background:#1a3a5c;padding:20px 28px;text-align:center;">
<img src="https://www.voltika.mx/configurador_prueba/img/goelectric.svg" alt="GO electric" style="height:28px;width:auto;margin-bottom:8px;">
<p style="margin:0 0 4px;font-size:14px;font-weight:700;color:#fff;">Voltika México</p>
<p style="margin:0;font-size:12px;color:rgba(255,255,255,0.6);">Movilidad eléctrica inteligente · Mtech Gears, S.A. de C.V.</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>';

} else {
    $asunto = 'Tu Voltika está confirmada, Orden #' . $pedidoNum;
}

$emailSent = !empty($email) ? sendMail($email, $nombre, $asunto, $cuerpo) : false;

echo json_encode([
    'status'    => $dbSaveOk ? 'ok' : 'ok_warn',
    'pedido'    => $pedidoNum,
    'emailSent' => $emailSent,
    'db_saved'  => $dbSaveOk,
    'db_warning'=> $dbSaveOk ? null : 'La orden quedó registrada en recuperación. Contactar soporte con número de pedido.',
]);

// ═════════════════════════════════════════════════════════════════════════════
// Helpers
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Backfill missing columns on `transacciones` so old installs pick up the new
 * fields without a manual migration.
 */
function ensureTransaccionesColumns(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $cols = [
        'asesoria_placas' => "ADD COLUMN asesoria_placas TINYINT(1) NOT NULL DEFAULT 0 AFTER stripe_pi",
        'seguro_qualitas' => "ADD COLUMN seguro_qualitas TINYINT(1) NOT NULL DEFAULT 0 AFTER asesoria_placas",
        'punto_id'        => "ADD COLUMN punto_id        VARCHAR(80)   NULL AFTER seguro_qualitas",
        'punto_nombre'    => "ADD COLUMN punto_nombre    VARCHAR(200)  NULL AFTER punto_id",
        'msi_meses'       => "ADD COLUMN msi_meses       INT           NULL AFTER punto_nombre",
        'msi_pago'        => "ADD COLUMN msi_pago        DECIMAL(12,2) NULL AFTER msi_meses",
        'referido'        => "ADD COLUMN referido        VARCHAR(40)   NULL AFTER msi_pago",
        'referido_id'     => "ADD COLUMN referido_id     INT           NULL AFTER referido",
        'referido_tipo'   => "ADD COLUMN referido_tipo   VARCHAR(20)   NULL AFTER referido_id",
        'caso'            => "ADD COLUMN caso            TINYINT       NULL AFTER referido_tipo",
    ];
    try {
        $existing = $pdo->query("SHOW COLUMNS FROM transacciones")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($cols as $name => $alter) {
            if (!in_array($name, $existing, true)) {
                try { $pdo->exec("ALTER TABLE transacciones " . $alter); }
                catch (PDOException $e) { error_log('ensureTransaccionesColumns(' . $name . '): ' . $e->getMessage()); }
            }
        }
    } catch (PDOException $e) {
        error_log('ensureTransaccionesColumns: ' . $e->getMessage());
    }
}
