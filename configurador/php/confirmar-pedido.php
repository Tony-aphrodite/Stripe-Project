<?php
/**
 * Voltika - Confirmar pedido (email + Zoho CRM)
 * Se llama desde paso-exito.js en modo fire-and-forget.
 * Funciona para todos los métodos de pago: contado, MSI y crédito.
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
    echo json_encode(['error' => 'Request inválido']);
    exit;
}

$nombre     = trim($json['nombre']     ?? '');
$email      = trim($json['email']      ?? '');
$telefono   = trim($json['telefono']   ?? '');
$modelo     = trim($json['modelo']     ?? '');
$color      = trim($json['color']      ?? '');
$metodoPago = trim($json['metodoPago'] ?? '');
$ciudad     = trim($json['ciudad']     ?? '');
$estado     = trim($json['estado']     ?? '');
$cp         = trim($json['cp']         ?? '');
$total      = floatval($json['total']  ?? 0);
$asesoria   = !empty($json['asesoriaPlacos']);
$seguro     = !empty($json['seguro']);
$credito    = $json['credito']         ?? null;
$fecha      = date('d/m/Y H:i');
$pedidoNum  = 'VK-' . strtoupper(base_convert(time(), 10, 36));

// Descripción del método de pago
if ($metodoPago === 'credito') {
    $pagoDesc = 'Crédito Voltika';
    if ($credito) {
        $engPct   = round(($credito['enganchePct'] ?? 0.30) * 100);
        $plazo    = intval($credito['plazoMeses'] ?? 12);
        $pagoDesc .= " · $engPct% enganche · $plazo meses";
    }
} elseif ($metodoPago === 'msi') {
    $pagoDesc = '9 MSI sin intereses';
} else {
    $pagoDesc = 'Contado';
}

// ── Guardar en BD ─────────────────────────────────────────────────────────────
try {
    $pdo = getDB();

    $pdo->exec("CREATE TABLE IF NOT EXISTS pedidos (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        pedido_num VARCHAR(20),
        nombre     VARCHAR(200),
        email      VARCHAR(200),
        telefono   VARCHAR(30),
        modelo     VARCHAR(200),
        color      VARCHAR(100),
        metodo     VARCHAR(50),
        ciudad     VARCHAR(100),
        estado     VARCHAR(100),
        cp         VARCHAR(10),
        total      DECIMAL(12,2),
        asesoria_placas TINYINT(1) DEFAULT 0,
        seguro_qualitas TINYINT(1) DEFAULT 0,
        freg       DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $stmt = $pdo->prepare("
        INSERT INTO pedidos
            (pedido_num, nombre, email, telefono, modelo, color, metodo, ciudad, estado, cp, total, asesoria_placas, seguro_qualitas)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $pedidoNum, $nombre, $email, $telefono, $modelo, $color,
        $metodoPago, $ciudad, $estado, $cp, $total,
        $asesoria ? 1 : 0, $seguro ? 1 : 0,
    ]);
} catch (PDOException $e) {
    error_log('Voltika pedidos DB error: ' . $e->getMessage());
}

// ── Email de confirmación al cliente ─────────────────────────────────────────
$esCancelacion = ($metodoPago === 'credito');
$headline = $esCancelacion
    ? 'Tu solicitud de Crédito Voltika ha sido recibida'
    : '¡Tu Voltika ya es tuya!';

$totalFmt = $total > 0 ? '$' . number_format($total, 0, '.', ',') . ' MXN' : '—';

$cuerpo = '<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;">
    <tr><td align="center" style="padding:24px;">
      <table width="620" cellpadding="0" cellspacing="0"
             style="background:#fff;border-radius:8px;overflow:hidden;max-width:620px;width:100%;">
        <tr>
          <td style="background:#22C55E;padding:24px 28px;color:#fff;">
            <h1 style="margin:0;font-size:22px;font-weight:800;">&#9745; voltika</h1>
            <p style="margin:8px 0 0;font-size:16px;">' . htmlspecialchars($headline) . '</p>
          </td>
        </tr>
        <tr>
          <td style="padding:28px;">
            <p style="margin:0 0 16px;font-size:15px;color:#111;">
              Hola <strong>' . htmlspecialchars($nombre) . '</strong>,
            </p>
            <p style="margin:0 0 20px;font-size:14px;color:#555;">
              ' . ($esCancelacion
                ? 'Hemos registrado tu solicitud. Un asesor te contactará en las próximas 24-48 horas por WhatsApp para coordinar tu enganche y entrega.'
                : 'Gracias por tu compra. Tu pedido ha sido confirmado exitosamente.')
              . '
            </p>

            <table width="100%" cellpadding="8" cellspacing="0"
                   style="border:1px solid #E5E7EB;border-radius:8px;font-size:14px;">
              <tr style="background:#F9FAFB;">
                <td style="color:#6B7280;padding:10px 12px;border-bottom:1px solid #E5E7EB;">
                  Número de pedido</td>
                <td style="font-weight:700;padding:10px 12px;border-bottom:1px solid #E5E7EB;">
                  ' . htmlspecialchars($pedidoNum) . '</td>
              </tr>
              <tr>
                <td style="color:#6B7280;padding:10px 12px;border-bottom:1px solid #E5E7EB;">
                  Modelo</td>
                <td style="padding:10px 12px;border-bottom:1px solid #E5E7EB;">
                  ' . htmlspecialchars($modelo) . ' &mdash; ' . htmlspecialchars($color) . '</td>
              </tr>
              <tr style="background:#F9FAFB;">
                <td style="color:#6B7280;padding:10px 12px;border-bottom:1px solid #E5E7EB;">
                  Forma de pago</td>
                <td style="padding:10px 12px;border-bottom:1px solid #E5E7EB;">
                  ' . htmlspecialchars($pagoDesc) . '</td>
              </tr>
              <tr>
                <td style="color:#6B7280;padding:10px 12px;border-bottom:1px solid #E5E7EB;">
                  Ciudad de entrega</td>
                <td style="padding:10px 12px;border-bottom:1px solid #E5E7EB;">
                  ' . htmlspecialchars($ciudad) . ', ' . htmlspecialchars($estado) . '</td>
              </tr>
              ' . ($total > 0 ? '
              <tr style="background:#F9FAFB;">
                <td style="color:#6B7280;padding:10px 12px;border-bottom:1px solid #E5E7EB;">Total</td>
                <td style="padding:10px 12px;font-weight:700;color:#22C55E;border-bottom:1px solid #E5E7EB;">' . $totalFmt . '</td>
              </tr>' : '') . '
              <tr>
                <td style="color:#6B7280;padding:10px 12px;border-bottom:1px solid #E5E7EB;">Asesoría placas</td>
                <td style="padding:10px 12px;border-bottom:1px solid #E5E7EB;">' . ($asesoria ? '&#10004; Sí' : 'No') . '</td>
              </tr>
              <tr style="background:#F9FAFB;">
                <td style="color:#6B7280;padding:10px 12px;">Seguro Qualitas</td>
                <td style="padding:10px 12px;">' . ($seguro ? '&#10004; Sí' : 'No') . '</td>
              </tr>
            </table>

            <p style="margin:24px 0 0;font-size:13px;color:#9CA3AF;">
              ¿Dudas? Escribe a
              <a href="mailto:ventas@voltika.com.mx" style="color:#22C55E;">ventas@voltika.com.mx</a>
              con tu número de pedido <strong>' . htmlspecialchars($pedidoNum) . '</strong>.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>';

$asunto    = 'Confirmación de tu pedido Voltika ' . $pedidoNum;
$emailSent = !empty($email) ? sendMail($email, $nombre, $asunto, $cuerpo) : false;

echo json_encode([
    'status'    => 'ok',
    'pedido'    => $pedidoNum,
    'emailSent' => $emailSent,
]);
