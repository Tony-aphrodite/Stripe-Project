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

// ── Dependencias ──────────────────────────────────────────────────────────────
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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
    $pdo = new PDO(
        'mysql:host=localhost;dbname=voltika_;charset=utf8mb4',
        'voltika',
        'Lemon2022;',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

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
$pagoDescripcion = $pagoTipo === 'msi'
    ? $msiMeses . ' MSI de $' . number_format($msiPago, 0, '.', ',') . ' MXN/mes'
    : 'Pago unico de $' . number_format($total, 0, '.', ',') . ' MXN';

$cuerpo = '<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>Confirmacion Voltika</title></head>
<body style="margin:0;padding:0;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;">
    <tr><td align="center" style="padding:24px;">
      <table width="620" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;max-width:620px;width:100%;">
        <!-- Header verde -->
        <tr>
          <td style="background:#22C55E;padding:24px 28px;color:#fff;">
            <h1 style="margin:0;font-size:22px;font-weight:800;">&#9745; voltika</h1>
            <p style="margin:8px 0 0;font-size:16px;">Tu Voltika ya es tuya. &#127881;</p>
          </td>
        </tr>
        <!-- Cuerpo -->
        <tr>
          <td style="padding:28px;">
            <p style="margin:0 0 16px;font-size:15px;color:#111;">Hola <strong>' . htmlspecialchars($nombre) . '</strong>,</p>
            <p style="margin:0 0 20px;font-size:14px;color:#555;">Gracias por tu compra. Hemos recibido tu pago correctamente.</p>

            <table width="100%" cellpadding="8" cellspacing="0" style="border:1px solid #E5E7EB;border-radius:8px;font-size:14px;">
              <tr style="background:#F9FAFB;">
                <td style="color:#6B7280;padding:10px 12px;border-bottom:1px solid #E5E7EB;">Numero de orden</td>
                <td style="font-weight:700;padding:10px 12px;border-bottom:1px solid #E5E7EB;">#' . $pedidoNum . '</td>
              </tr>
              <tr>
                <td style="color:#6B7280;padding:10px 12px;border-bottom:1px solid #E5E7EB;">Modelo</td>
                <td style="padding:10px 12px;border-bottom:1px solid #E5E7EB;">' . htmlspecialchars($modelo) . ' &mdash; ' . htmlspecialchars($color) . '</td>
              </tr>
              <tr style="background:#F9FAFB;">
                <td style="color:#6B7280;padding:10px 12px;border-bottom:1px solid #E5E7EB;">Forma de pago</td>
                <td style="padding:10px 12px;border-bottom:1px solid #E5E7EB;">' . htmlspecialchars($pagoDescripcion) . '</td>
              </tr>
              <tr>
                <td style="color:#6B7280;padding:10px 12px;border-bottom:1px solid #E5E7EB;">Ciudad de entrega</td>
                <td style="padding:10px 12px;border-bottom:1px solid #E5E7EB;">' . htmlspecialchars($ciudad) . ', ' . htmlspecialchars($estado) . '</td>
              </tr>
              <tr style="background:#F9FAFB;">
                <td style="color:#6B7280;padding:10px 12px;">Fecha</td>
                <td style="padding:10px 12px;">' . $fecha . '</td>
              </tr>
            </table>

            <h3 style="margin:24px 0 8px;font-size:16px;color:#111;">Siguientes pasos</h3>
            <ol style="margin:0;padding-left:20px;font-size:14px;color:#555;line-height:1.8;">
              <li>Un asesor Voltika te contactara en <strong>24-48 horas</strong> para coordinar la entrega.</li>
              <li>La entrega se realiza en un <strong>Centro Voltika autorizado</strong> en tu ciudad.</li>
              <li>Tiempo estimado de entrega: <strong>7-10 dias habiles</strong>.</li>
              <li>Recibirás tu factura electronica (CFDI) una vez cerrada la operacion.</li>
            </ol>

            <p style="margin:24px 0 0;font-size:12px;color:#9CA3AF;">
              ¿Dudas? Escribe a <a href="mailto:ventas@voltika.com.mx" style="color:#22C55E;">ventas@voltika.com.mx</a>
              con tu numero de orden <strong>#' . $pedidoNum . '</strong>.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>';

$emailSent = false;
$asunto    = 'Confirmacion de tu compra Voltika #' . $pedidoNum;

if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    // PHPMailer (via composer require phpmailer/phpmailer)
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->SMTPAuth   = true;
        $mail->Host       = 'smtp.ionos.mx';
        $mail->Port       = 465;
        $mail->Username   = 'voltika@riactor.com';
        $mail->Password   = 'Lemon2022;';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->setFrom('voltika@riactor.com', 'Voltika México');
        $mail->addAddress($email, $nombre);
        $mail->addAddress('redes@voltika.com.mx');
        $mail->CharSet    = 'UTF-8';
        $mail->Encoding   = 'base64';
        $mail->isHTML(true);
        $mail->Subject    = $asunto;
        $mail->Body       = $cuerpo;
        $mail->AltBody    = strip_tags($cuerpo);
        $mail->send();
        $emailSent = true;
    } catch (Exception $e) {
        error_log('Voltika PHPMailer error: ' . $e->getMessage());
    }
}

// Fallback: PHP mail() si PHPMailer no está disponible
if (!$emailSent && !empty($email)) {
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Voltika México <voltika@riactor.com>\r\n";
    $headers .= "Bcc: redes@voltika.com.mx\r\n";
    $emailSent = @mail($email, $asunto, $cuerpo, $headers);
}

echo json_encode([
    'status'    => 'ok',
    'pedido'    => $pedidoNum,
    'emailSent' => $emailSent
]);
