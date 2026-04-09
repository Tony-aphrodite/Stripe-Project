<?php
/**
 * Voltika Admin/CEDIS — Bootstrap
 * Central entry point for all admin panel endpoints.
 */
require_once __DIR__ . '/../../php/config.php';

if (!headers_sent()) {
    header('Content-Type: application/json');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET,POST,PUT,DELETE,OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
}

session_name('VOLTIKA_ADMIN');
session_start();

// ── Auto-create additional tables ──────────────────────────────────────────
$pdo = getDB();

// Puntos Voltika — dedicated table for point management
$pdo->exec("CREATE TABLE IF NOT EXISTS puntos_voltika (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    direccion TEXT,
    ciudad VARCHAR(120),
    estado VARCHAR(80),
    cp VARCHAR(10),
    telefono VARCHAR(20),
    email VARCHAR(255),
    lat DECIMAL(10,7),
    lng DECIMAL(10,7),
    horarios VARCHAR(255),
    capacidad INT DEFAULT 0,
    activo TINYINT DEFAULT 1,
    codigo_venta VARCHAR(20) UNIQUE COMMENT 'Código referido venta directa',
    codigo_electronico VARCHAR(20) UNIQUE COMMENT 'Código referido venta electrónica',
    freg DATETIME DEFAULT CURRENT_TIMESTAMP,
    fmod DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Envíos CEDIS → Punto
$pdo->exec("CREATE TABLE IF NOT EXISTS envios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    moto_id INT NOT NULL,
    punto_destino_id INT NOT NULL,
    estado ENUM('lista_para_enviar','enviada','recibida') DEFAULT 'lista_para_enviar',
    fecha_envio DATE,
    fecha_estimada_llegada DATE,
    fecha_recepcion DATETIME,
    enviado_por INT COMMENT 'dealer_usuarios.id',
    recibido_por INT COMMENT 'dealer_usuarios.id',
    notas TEXT,
    freg DATETIME DEFAULT CURRENT_TIMESTAMP,
    fmod DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Recepción en punto (checklist + fotos al recibir moto del CEDIS)
$pdo->exec("CREATE TABLE IF NOT EXISTS recepcion_punto (
    id INT AUTO_INCREMENT PRIMARY KEY,
    envio_id INT NOT NULL,
    moto_id INT NOT NULL,
    punto_id INT NOT NULL,
    recibido_por INT COMMENT 'dealer_usuarios.id',
    vin_escaneado VARCHAR(50),
    vin_coincide TINYINT DEFAULT 0,
    estado_fisico_ok TINYINT DEFAULT 0,
    sin_danos TINYINT DEFAULT 0,
    componentes_completos TINYINT DEFAULT 0,
    bateria_ok TINYINT DEFAULT 0,
    fotos JSON COMMENT 'Array de URLs de fotos',
    notas TEXT,
    completado TINYINT DEFAULT 0,
    freg DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Fotos de entrega al cliente
$pdo->exec("CREATE TABLE IF NOT EXISTS fotos_entrega (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entrega_id INT NOT NULL,
    moto_id INT NOT NULL,
    tipo ENUM('cliente','identificacion','moto_frente','moto_lateral','moto_trasera','otra') NOT NULL,
    url TEXT NOT NULL,
    freg DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Admin action log
$pdo->exec("CREATE TABLE IF NOT EXISTS admin_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT,
    accion VARCHAR(100),
    detalle JSON,
    ip VARCHAR(45),
    freg DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Ensure puntos_voltika columns exist on inventario_motos
try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN punto_voltika_id INT AFTER punto_id"); } catch(Throwable $e){}

// ── Helpers ─────────────────────────────────────────────────────────────────

function adminJsonIn() {
    return json_decode(file_get_contents('php://input'), true) ?: [];
}
function adminJsonOut($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function adminRequireAuth($roles = null) {
    if (empty($_SESSION['admin_user_id'])) {
        adminJsonOut(['error' => 'No autorizado'], 401);
    }
    if ($roles) {
        $rol = $_SESSION['admin_user_rol'] ?? '';
        if (!in_array($rol, (array)$roles)) {
            adminJsonOut(['error' => 'Sin permisos para esta acción'], 403);
        }
    }
    return (int)$_SESSION['admin_user_id'];
}
function adminLog($accion, $detalle = []) {
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO admin_log (usuario_id, accion, detalle, ip) VALUES (?,?,?,?)");
    $stmt->execute([
        $_SESSION['admin_user_id'] ?? null,
        $accion,
        json_encode($detalle, JSON_UNESCAPED_UNICODE),
        $_SERVER['REMOTE_ADDR'] ?? ''
    ]);
}
function adminNotify($tipo, $data) {
    // Email + WhatsApp notifications for status changes
    $pdo = getDB();
    $templates = [
        'punto_asignado' => [
            'subject' => '📍 Punto asignado a tu compra',
            'sms' => 'Voltika: Tu moto será entregada en {punto}. Te avisaremos cuando esté lista.',
        ],
        'moto_enviada' => [
            'subject' => '🚚 Tu Voltika está en camino',
            'sms' => 'Voltika: Tu moto fue enviada. Fecha estimada de llegada: {fecha}.',
        ],
        'lista_para_recoger' => [
            'subject' => '✅ Tu Voltika está lista para entrega',
            'sms' => 'Voltika: Tu moto está lista! Recibirás un código OTP para recogerla.',
        ],
    ];
    if (!isset($templates[$tipo])) return;
    $tpl = $templates[$tipo];
    $msg = $tpl['sms'];
    foreach ($data as $k => $v) { $msg = str_replace('{'.$k.'}', $v, $msg); }

    // Send SMS if phone available
    if (!empty($data['telefono'])) {
        $tel = preg_replace('/\D/', '', $data['telefono']);
        if (strlen($tel) === 10) $tel = '52' . $tel;
        $smsKey = defined('SMSMASIVOS_API_KEY') ? SMSMASIVOS_API_KEY : (getenv('SMSMASIVOS_API_KEY') ?: '');
        if ($smsKey) {
            $ch = curl_init('https://api.smsmasivos.com.mx/sms/send');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $smsKey],
                CURLOPT_POSTFIELDS => json_encode(['phone_number' => $tel, 'message' => $msg]),
            ]);
            curl_exec($ch); curl_close($ch);
        }
    }
    // Send email if available
    if (!empty($data['email'])) {
        @sendMail($data['email'], $tpl['subject'], '<p>' . nl2br(htmlspecialchars($msg)) . '</p>');
    }
}
