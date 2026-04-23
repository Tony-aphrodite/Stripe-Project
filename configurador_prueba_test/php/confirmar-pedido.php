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
require_once __DIR__ . '/inventory-utils.php';
require_once __DIR__ . '/lib/catalog-normalize.php';
// Shared email chrome helpers — voltikaEmailHeader / voltikaEmailFooter /
// voltikaEmailShell. Loaded so this script's confirmation email uses the
// same design (logo image + tagline) as every other notification.
require_once __DIR__ . '/voltika-notify.php';

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
// Normalize to short catalog codes — see lib/catalog-normalize.php.
$modelo     = voltikaNormalizeModelo($json['modelo'] ?? '');
$color      = voltikaNormalizeColor($json['color']  ?? '');
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
    // Bike assignment is ALWAYS manual by CEDIS via admin dashboard.
    // Per business rules: "a purchase NEVER adds a motorcycle to the inventory"
    // and "the assignation of a bike NEVER is automatically".
    // The old asignarMotoFIFO() call was removed here — CEDIS assigns via
    // admin/php/ventas/asignar-moto.php after verifying payment.

} catch (PDOException $e) {
    error_log('Voltika pedidos DB error: ' . $e->getMessage());
}

// ── Email de confirmación al cliente ─────────────────────────────────────────
$esCancelacion = ($metodoPago === 'credito');
$headline = $esCancelacion
    ? 'Tu solicitud de Crédito Voltika ha sido recibida'
    : '¡Tu Voltika ya es tuya!';

$totalFmt = $total > 0 ? '$' . number_format($total, 0, '.', ',') . ' MXN' : '—';

// Build the body rows (will be wrapped by voltikaEmailShell with the
// standard logo header + footer).
$rows  = [
    ['Número de pedido', '<strong>' . htmlspecialchars($pedidoNum) . '</strong>'],
    ['Modelo',           htmlspecialchars($modelo) . ' &mdash; ' . htmlspecialchars($color)],
    ['Forma de pago',    htmlspecialchars($pagoDesc)],
    ['Ciudad de entrega',htmlspecialchars($ciudad) . ', ' . htmlspecialchars($estado)],
];
if ($total > 0) $rows[] = ['Total', '<strong style="color:#039fe1;">' . $totalFmt . '</strong>'];
$rows[] = ['Asesoría placas', $asesoria ? '&#10004; Sí' : 'No'];
$rows[] = ['Seguro Qualitas', $seguro   ? '&#10004; Sí' : 'No'];

$intro = $esCancelacion
    ? 'Hemos registrado tu solicitud. Un asesor te contactará en las próximas 24-48 horas por WhatsApp para coordinar tu enganche y entrega.'
    : 'Gracias por tu compra. Tu pedido ha sido confirmado exitosamente.';

$innerRows = '<tr><td style="padding:24px 28px 6px;">'
           . '<h2 style="margin:0 0 10px;font-size:20px;color:#1a3a5c;">Hola, ' . htmlspecialchars($nombre) . ' 👋</h2>'
           . '<p style="margin:0 0 18px;font-size:14px;color:#555;line-height:1.6;">' . $intro . '</p>'
           . voltikaEmailSectionLabel('Detalle de tu pedido')
           . voltikaEmailDataTable($rows, $total > 0)
           . '<p style="margin:16px 0 0;font-size:13px;color:#9CA3AF;">'
           .   '¿Dudas? Escribe a <a href="mailto:ventas@voltika.com.mx" style="color:#039fe1;font-weight:700;text-decoration:none;">ventas@voltika.com.mx</a> con tu número de pedido <strong>' . htmlspecialchars($pedidoNum) . '</strong>.'
           . '</p>'
           . '</td></tr>';

$cuerpo = voltikaEmailShell(htmlspecialchars($headline), 'Pedido #' . htmlspecialchars($pedidoNum), $innerRows);

// Credit flow emails are handled by confirmar-orden.php — skip here to avoid duplicates
if ($metodoPago === 'credito') {
    $emailSent = false;
} else {
    $asunto    = 'Confirmación de tu pedido Voltika ' . $pedidoNum;
    $emailSent = !empty($email) ? sendMail($email, $nombre, $asunto, $cuerpo) : false;
}

echo json_encode([
    'status'    => 'ok',
    'pedido'    => $pedidoNum,
    'emailSent' => $emailSent,
]);
