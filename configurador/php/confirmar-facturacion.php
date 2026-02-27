<?php
/**
 * Voltika - Confirmar datos de facturación
 * Guarda RFC, razón social y datos de CFDI en DB.
 * Se llama después de completar el pago en Stripe.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$json = json_decode(file_get_contents('php://input'), true);
if (!$json) {
    http_response_code(400);
    echo json_encode(['error' => 'Request inválido']);
    exit;
}

$nombre      = trim($json['nombre']      ?? '');
$email       = trim($json['email']       ?? '');
$modelo      = trim($json['modelo']      ?? '');
$metodoPago  = trim($json['metodoPago']  ?? '');
$total       = floatval($json['total']   ?? 0);
$rfc         = strtoupper(trim($json['rfc']         ?? ''));
$razonSocial = trim($json['razon']  ?? $json['razonSocial'] ?? '');
$usoCfdi     = trim($json['cfdi']   ?? $json['usoCfdi']    ?? 'G03');
$calle       = trim($json['calle']       ?? '');
$cp          = trim($json['cp']          ?? '');
$ciudad      = trim($json['ciudad']      ?? '');
$estado      = trim($json['estado']      ?? '');
$factura     = !empty($rfc);

// ── Guardar en BD ─────────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=voltika_;charset=utf8mb4',
        'voltika',
        'Lemon2022;',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Crear tabla si no existe (primera vez)
    $pdo->exec("CREATE TABLE IF NOT EXISTS facturacion (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        nombre     VARCHAR(200),
        email      VARCHAR(200),
        modelo     VARCHAR(200),
        metodo     VARCHAR(50),
        total      DECIMAL(12,2),
        rfc        VARCHAR(20),
        razon      VARCHAR(200),
        uso_cfdi   VARCHAR(10),
        calle      VARCHAR(300),
        cp         VARCHAR(10),
        ciudad     VARCHAR(100),
        estado     VARCHAR(100),
        freg       DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $stmt = $pdo->prepare("
        INSERT INTO facturacion
            (nombre, email, modelo, metodo, total, rfc, razon, uso_cfdi, calle, cp, ciudad, estado)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $nombre, $email, $modelo, $metodoPago, $total,
        $rfc, $razonSocial, $usoCfdi, $calle, $cp, $ciudad, $estado,
    ]);
} catch (PDOException $e) {
    error_log('Voltika facturacion DB error: ' . $e->getMessage());
}

// ── Notificación interna por email ────────────────────────────────────────────
if ($factura) {
    // Aviso al equipo interno cuando el cliente pide factura
    $to      = 'ventas@voltika.com.mx';
    $subject = 'Nueva solicitud de factura - ' . $nombre;
    $body    = "Cliente: $nombre\nEmail: $email\nRFC: $rfc\nRazón social: $razonSocial\nUso CFDI: $usoCfdi\nDirección: $calle, $cp, $ciudad, $estado\nModelo: $modelo\nTotal: $$total";
    $headers = 'From: voltika@riactor.com';
    @mail($to, $subject, $body, $headers);
}

echo json_encode(['status' => 'ok', 'factura' => $factura]);
