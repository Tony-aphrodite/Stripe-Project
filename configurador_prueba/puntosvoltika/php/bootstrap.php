<?php
/**
 * Voltika Puntos — Bootstrap
 * Shared entry point for all Punto Voltika panel endpoints.
 */
require_once __DIR__ . '/../../configurador_prueba/php/config.php';

if (!headers_sent()) {
    header('Content-Type: application/json');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
}

session_name('VOLTIKA_PUNTO');
session_start();

$pdo = getDB();

// Ensure tables exist (shared schema with admin panel)
$pdo->exec("CREATE TABLE IF NOT EXISTS puntos_voltika (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL, direccion TEXT, ciudad VARCHAR(120), estado VARCHAR(80),
    cp VARCHAR(10), telefono VARCHAR(20), email VARCHAR(255),
    lat DECIMAL(10,7), lng DECIMAL(10,7), horarios VARCHAR(255), capacidad INT DEFAULT 0,
    activo TINYINT DEFAULT 1,
    codigo_venta VARCHAR(20) UNIQUE, codigo_electronico VARCHAR(20) UNIQUE,
    freg DATETIME DEFAULT CURRENT_TIMESTAMP,
    fmod DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS envios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    moto_id INT NOT NULL, punto_destino_id INT NOT NULL,
    estado ENUM('lista_para_enviar','enviada','recibida') DEFAULT 'lista_para_enviar',
    fecha_envio DATE, fecha_estimada_llegada DATE, fecha_recepcion DATETIME,
    enviado_por INT, recibido_por INT, notas TEXT,
    freg DATETIME DEFAULT CURRENT_TIMESTAMP,
    fmod DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS recepcion_punto (
    id INT AUTO_INCREMENT PRIMARY KEY,
    envio_id INT NOT NULL, moto_id INT NOT NULL, punto_id INT NOT NULL,
    recibido_por INT, vin_escaneado VARCHAR(50), vin_coincide TINYINT DEFAULT 0,
    estado_fisico_ok TINYINT DEFAULT 0, sin_danos TINYINT DEFAULT 0,
    componentes_completos TINYINT DEFAULT 0, bateria_ok TINYINT DEFAULT 0,
    fotos JSON, notas TEXT, completado TINYINT DEFAULT 0,
    freg DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS fotos_entrega (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entrega_id INT NOT NULL, moto_id INT NOT NULL,
    tipo ENUM('cliente','identificacion','moto_frente','moto_lateral','moto_trasera','otra'),
    url TEXT NOT NULL, freg DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS admin_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT, accion VARCHAR(100), detalle JSON, ip VARCHAR(45),
    freg DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN punto_voltika_id INT AFTER punto_id"); } catch(Throwable $e){}

function puntoJsonIn() {
    return json_decode(file_get_contents('php://input'), true) ?: [];
}
function puntoJsonOut($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function puntoRequireAuth() {
    if (empty($_SESSION['punto_user_id'])) {
        puntoJsonOut(['error' => 'No autorizado'], 401);
    }
    return [
        'user_id'  => (int)$_SESSION['punto_user_id'],
        'punto_id' => (int)$_SESSION['punto_id'],
        'nombre'   => $_SESSION['punto_user_nombre'] ?? '',
    ];
}
function puntoLog($accion, $detalle = []) {
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO admin_log (usuario_id, accion, detalle, ip) VALUES (?,?,?,?)");
    $stmt->execute([
        $_SESSION['punto_user_id'] ?? null,
        'punto:' . $accion,
        json_encode($detalle, JSON_UNESCAPED_UNICODE),
        $_SERVER['REMOTE_ADDR'] ?? ''
    ]);
}
function puntoGenOTP() { return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT); }
