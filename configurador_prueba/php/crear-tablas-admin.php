<?php
/**
 * Voltika Admin - Crear tablas del sistema de administración
 * Ejecutar UNA SOLA VEZ para crear todas las tablas necesarias.
 * ELIMINAR este archivo después de la instalación.
 */

$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika_admin_setup_2026') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=UTF-8');

$pdo = getDB();
$resultados = [];

$tablas = [

    // ── Dealers / usuarios del panel ────────────────────────────────────────
    'dealer_usuarios' => "
        CREATE TABLE IF NOT EXISTS dealer_usuarios (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            nombre        VARCHAR(200) NOT NULL,
            email         VARCHAR(200) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            punto_nombre  VARCHAR(200),
            punto_id      VARCHAR(100),
            rol           ENUM('dealer','admin') DEFAULT 'dealer',
            activo        TINYINT DEFAULT 1,
            freg          DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",

    // ── Inventario de motocicletas ───────────────────────────────────────────
    'inventario_motos' => "
        CREATE TABLE IF NOT EXISTS inventario_motos (
            id                 INT AUTO_INCREMENT PRIMARY KEY,
            vin                VARCHAR(50) NOT NULL,
            vin_display        VARCHAR(20),
            modelo             VARCHAR(100) NOT NULL,
            color              VARCHAR(50) NOT NULL,
            anio_modelo        VARCHAR(10),
            num_motor          VARCHAR(50),
            potencia           VARCHAR(50),
            config_baterias    ENUM('1','2') DEFAULT '1',
            descripcion        TEXT,
            hecho_en           VARCHAR(100),
            num_pedimento      VARCHAR(50),
            fecha_ingreso_pais DATE,
            aduana             VARCHAR(100),
            cedis_origen       VARCHAR(100),
            tipo_asignacion    ENUM('voltika_entrega','consignacion') DEFAULT 'voltika_entrega',
            estado             ENUM('por_llegar','recibida','por_ensamblar','en_ensamble','lista_para_entrega','por_validar_entrega','entregada','retenida') DEFAULT 'por_llegar',
            dealer_id          INT,
            punto_voltika_id   INT,
            punto_nombre       VARCHAR(200),
            punto_id           VARCHAR(100),
            cliente_nombre     VARCHAR(200),
            cliente_email      VARCHAR(200),
            cliente_telefono   VARCHAR(30),
            pedido_num         VARCHAR(50),
            stripe_pi          VARCHAR(200),
            pago_estado        ENUM('pagada','pendiente','parcial') DEFAULT 'pendiente',
            fecha_llegada      DATE,
            fecha_estimada_llegada DATE,
            fecha_entrega_estimada DATE,
            fecha_estado       DATETIME,
            dias_en_paso       INT DEFAULT 0,
            recepcion_completada TINYINT DEFAULT 0,
            notas              TEXT,
            log_estados        JSON,
            precio_venta       DECIMAL(12,2),
            activo             TINYINT DEFAULT 1,
            freg               DATETIME DEFAULT CURRENT_TIMESTAMP,
            fmod               DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",

    // ── Checklist pre-entrega ────────────────────────────────────────────────
    'checklist_entrega' => "
        CREATE TABLE IF NOT EXISTS checklist_entrega (
            id                   INT AUTO_INCREMENT PRIMARY KEY,
            moto_id              INT NOT NULL,
            dealer_id            INT NOT NULL,
            revision_fisica      TINYINT DEFAULT 0,
            revision_electrica   TINYINT DEFAULT 0,
            carga_bateria        TINYINT DEFAULT 0,
            luces_ok             TINYINT DEFAULT 0,
            frenos_ok            TINYINT DEFAULT 0,
            velocimetro_ok       TINYINT DEFAULT 0,
            documentos_completos TINYINT DEFAULT 0,
            llaves_entregadas    TINYINT DEFAULT 0,
            manual_entregado     TINYINT DEFAULT 0,
            identidad_verificada TINYINT DEFAULT 0,
            datos_confirmados    TINYINT DEFAULT 0,
            qr_pedido_ok         TINYINT DEFAULT 0,
            qr_moto_ok           TINYINT DEFAULT 0,
            notas                TEXT,
            completado           TINYINT DEFAULT 0,
            freg                 DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",

    // ── Entregas / OTP ───────────────────────────────────────────────────────
    'entregas' => "
        CREATE TABLE IF NOT EXISTS entregas (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            moto_id          INT NOT NULL,
            pedido_num       VARCHAR(50),
            cliente_nombre   VARCHAR(200),
            cliente_email    VARCHAR(200),
            cliente_telefono VARCHAR(30),
            otp_code         VARCHAR(10),
            otp_expires      DATETIME,
            otp_verified     TINYINT DEFAULT 0,
            otp_verified_at  DATETIME,
            checklist_id     INT,
            dealer_id        INT,
            estado           ENUM('pendiente','otp_enviado','confirmado','cancelado') DEFAULT 'pendiente',
            notas            TEXT,
            fecha_entrega    DATETIME,
            freg             DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",

    // ── Log de ventas y operaciones ──────────────────────────────────────────
    'ventas_log' => "
        CREATE TABLE IF NOT EXISTS ventas_log (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            moto_id          INT,
            tipo             ENUM('venta_punto','entrega_voltika','devolucion','ajuste','reserva') DEFAULT 'entrega_voltika',
            dealer_id        INT,
            cliente_nombre   VARCHAR(200),
            cliente_email    VARCHAR(200),
            cliente_telefono VARCHAR(30),
            pedido_num       VARCHAR(50),
            modelo           VARCHAR(100),
            color            VARCHAR(50),
            vin              VARCHAR(50),
            monto            DECIMAL(12,2),
            referido_id      INT,
            notas            TEXT,
            freg             DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",

    // ── Referidos ────────────────────────────────────────────────────────────
    'referidos' => "
        CREATE TABLE IF NOT EXISTS referidos (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            nombre           VARCHAR(200) NOT NULL,
            email            VARCHAR(200),
            telefono         VARCHAR(30),
            codigo_referido  VARCHAR(20) UNIQUE,
            ventas_count     INT DEFAULT 0,
            comision_total   DECIMAL(12,2) DEFAULT 0.00,
            activo           TINYINT DEFAULT 1,
            freg             DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",

];

foreach ($tablas as $nombre => $sql) {
    try {
        $pdo->exec(trim($sql));
        $resultados[] = ['tabla' => $nombre, 'ok' => true, 'msg' => 'Creada / ya existía'];
    } catch (PDOException $e) {
        $resultados[] = ['tabla' => $nombre, 'ok' => false, 'msg' => $e->getMessage()];
    }
}

// ── Insertar dealer demo si no existe ────────────────────────────────────────
try {
    $exists = $pdo->query("SELECT COUNT(*) FROM dealer_usuarios")->fetchColumn();
    if ($exists == 0) {
        $hash = password_hash('voltika2026', PASSWORD_DEFAULT);
        $pdo->prepare("
            INSERT INTO dealer_usuarios (nombre, email, password_hash, punto_nombre, punto_id, rol)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute(['Admin Voltika', 'admin@voltika.com.mx', $hash, 'Voltika Center Santa Fe', 'voltika-center-santa-fe', 'admin']);
        $resultados[] = ['tabla' => 'dealer_usuarios (seed)', 'ok' => true, 'msg' => 'Usuario demo: admin@voltika.com.mx / voltika2026'];
    }
} catch (PDOException $e) {
    $resultados[] = ['tabla' => 'dealer_usuarios (seed)', 'ok' => false, 'msg' => $e->getMessage()];
}

// ── HTML output ───────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>Voltika Admin Setup</title>
<style>
body{font-family:Arial,sans-serif;max-width:800px;margin:40px auto;padding:0 20px;background:#f0f6fc;}
h1{color:#1a3a5c;}
table{width:100%;border-collapse:collapse;margin-top:20px;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08);}
th,td{padding:12px 16px;border-bottom:1px solid #e5e7eb;text-align:left;}
th{background:#1a3a5c;color:#fff;}
.ok{color:#039fe1;font-weight:700;}
.err{color:#dc2626;font-weight:700;}
.warn{background:#fff8dc;padding:16px;border-radius:8px;margin-top:20px;border-left:4px solid #f59e0b;}
</style>
</head>
<body>
<h1>Voltika Admin — Setup de Base de Datos</h1>
<table>
<tr><th>Tabla</th><th>Estado</th><th>Mensaje</th></tr>
<?php foreach ($resultados as $r): ?>
<tr>
  <td><strong><?= htmlspecialchars($r['tabla']) ?></strong></td>
  <td class="<?= $r['ok'] ? 'ok' : 'err' ?>"><?= $r['ok'] ? '✔ OK' : '✖ ERROR' ?></td>
  <td><?= htmlspecialchars($r['msg']) ?></td>
</tr>
<?php endforeach; ?>
</table>
<div class="warn">
  ⚠️ <strong>IMPORTANTE:</strong> Elimina este archivo después de la instalación:<br>
  <code>configurador/php/crear-tablas-admin.php</code>
</div>
</body>
</html>
