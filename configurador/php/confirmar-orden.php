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

$pedidoNum = time();
$fecha     = date('Y-m-d H:i');

// ── Guardar en BD ─────────────────────────────────────────────────────────────
try {
    $pdo = getDB();

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
        stripe_pi  VARCHAR(100)
    )");

    $stmt = $pdo->prepare("
        INSERT INTO transacciones
            (nombre, email, telefono, modelo, color, ciudad, estado, cp, tpago, precio, total, freg, pedido, stripe_pi)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $nombre, $email, $telefono, $modelo, $color,
        $ciudad, $estado, $cp, $pagoTipo,
        $pagoTipo === 'msi' ? $msiPago : $total,
        $total, $fecha, $pedidoNum, $paymentIntentId
    ]);
} catch (PDOException $e) {
    // Log pero no fallar — el pago ya fue capturado
    error_log('Voltika DB error: ' . $e->getMessage());
}

// ── Enviar email de confirmacion ──────────────────────────────────────────────
$enganchePct    = floatval($json['enganchePct'] ?? 0);
$plazoMeses     = intval($json['plazoMeses'] ?? 36);
$pagoSemanal    = floatval($json['pagoSemanal'] ?? 0);
$folioContrato  = $json['folioContrato'] ?? ('VK-' . date('Ymd') . '-' . strtoupper(substr($nombre, 0, 3)));
$metodoPago     = $json['metodoPago'] ?? $pagoTipo;
$esCredito      = ($pagoTipo === 'enganche' || $metodoPago === 'credito');

$pagoDescripcion = $pagoTipo === 'msi'
    ? $msiMeses . ' MSI de $' . number_format($msiPago, 0, '.', ',') . ' MXN/mes'
    : 'Pago único de $' . number_format($total, 0, '.', ',') . ' MXN';

$montoFormateado = '$' . number_format($total, 0, '.', ',') . ' MXN';
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
<h1 style="margin:0;font-size:26px;font-weight:900;color:#fff;">Voltika</h1>
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
</table>

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
<h1 style="margin:0;font-size:26px;font-weight:900;color:#fff;">Voltika</h1>
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
</table>

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
    'status'    => 'ok',
    'pedido'    => $pedidoNum,
    'emailSent' => $emailSent
]);
