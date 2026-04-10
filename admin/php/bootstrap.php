<?php
/**
 * Voltika Admin/CEDIS — Bootstrap
 * Central entry point for all admin panel endpoints.
 */
require_once __DIR__ . '/../../configurador_prueba/php/config.php';

$isApiRequest = (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'index.php');
if (!headers_sent()) {
    if ($isApiRequest) {
        header('Content-Type: application/json');
    }
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

// ciclos_pago — shared with clientes portal (needed for KPI cartera queries)
$pdo->exec("CREATE TABLE IF NOT EXISTS ciclos_pago (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subscripcion_id INT NOT NULL,
    cliente_id INT NULL,
    semana_num INT NOT NULL,
    fecha_vencimiento DATE NOT NULL,
    monto DECIMAL(10,2) NOT NULL,
    estado ENUM('pending','paid_manual','paid_auto','overdue','skipped') DEFAULT 'pending',
    transaccion_id INT NULL,
    stripe_payment_intent VARCHAR(100) NULL,
    origen VARCHAR(30) NULL,
    freg DATETIME DEFAULT CURRENT_TIMESTAMP,
    fupd DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sub_semana (subscripcion_id, semana_num),
    INDEX idx_cliente (cliente_id),
    INDEX idx_estado (estado),
    INDEX idx_venc (fecha_vencimiento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Ensure puntos_voltika columns exist on inventario_motos
try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN punto_voltika_id INT AFTER punto_id"); } catch(Throwable $e){}

// Ensure tracking columns on envios
try { $pdo->exec("ALTER TABLE envios ADD COLUMN tracking_number VARCHAR(100) NULL AFTER notas"); } catch(Throwable $e){}
try { $pdo->exec("ALTER TABLE envios ADD COLUMN carrier VARCHAR(80) NULL AFTER tracking_number"); } catch(Throwable $e){}

// Ensure configurador fields on puntos_voltika
try { $pdo->exec("ALTER TABLE puntos_voltika ADD COLUMN tipo ENUM('center','certificado','entrega') DEFAULT 'entrega' AFTER capacidad"); } catch(Throwable $e){}
try { $pdo->exec("ALTER TABLE puntos_voltika ADD COLUMN servicios JSON NULL AFTER tipo"); } catch(Throwable $e){}
try { $pdo->exec("ALTER TABLE puntos_voltika ADD COLUMN tags JSON NULL AFTER servicios"); } catch(Throwable $e){}
try { $pdo->exec("ALTER TABLE puntos_voltika ADD COLUMN zonas JSON NULL AFTER tags"); } catch(Throwable $e){}
try { $pdo->exec("ALTER TABLE puntos_voltika ADD COLUMN colonia VARCHAR(200) NULL AFTER direccion"); } catch(Throwable $e){}
try { $pdo->exec("ALTER TABLE puntos_voltika ADD COLUMN descripcion TEXT NULL AFTER zonas"); } catch(Throwable $e){}
try { $pdo->exec("ALTER TABLE puntos_voltika ADD COLUMN autorizado TINYINT DEFAULT 1 AFTER descripcion"); } catch(Throwable $e){}
try { $pdo->exec("ALTER TABLE puntos_voltika ADD COLUMN slug VARCHAR(100) NULL AFTER id"); } catch(Throwable $e){}

// Checklist tables (origin, assembly, delivery)
$pdo->exec("CREATE TABLE IF NOT EXISTS checklist_origen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    moto_id INT NOT NULL,
    dealer_id INT NOT NULL,
    vin VARCHAR(50), num_motor VARCHAR(50), modelo VARCHAR(100), color VARCHAR(50),
    anio_modelo VARCHAR(10), config_baterias ENUM('1','2') DEFAULT '1',
    frame_completo TINYINT DEFAULT 0, chasis_sin_deformaciones TINYINT DEFAULT 0,
    soportes_estructurales TINYINT DEFAULT 0, charola_trasera TINYINT DEFAULT 0,
    llanta_delantera TINYINT DEFAULT 0, llanta_trasera TINYINT DEFAULT 0,
    rines_sin_dano TINYINT DEFAULT 0, ejes_completos TINYINT DEFAULT 0,
    manubrio TINYINT DEFAULT 0, soportes_completos TINYINT DEFAULT 0,
    dashboard_incluido TINYINT DEFAULT 0, controles_completos TINYINT DEFAULT 0,
    freno_delantero TINYINT DEFAULT 0, freno_trasero TINYINT DEFAULT 0,
    discos_sin_dano TINYINT DEFAULT 0, calipers_instalados TINYINT DEFAULT 0,
    lineas_completas TINYINT DEFAULT 0,
    cableado_completo TINYINT DEFAULT 0, conectores_correctos TINYINT DEFAULT 0,
    controlador_instalado TINYINT DEFAULT 0, encendido_operativo TINYINT DEFAULT 0,
    motor_instalado TINYINT DEFAULT 0, motor_sin_dano TINYINT DEFAULT 0, motor_conexion TINYINT DEFAULT 0,
    bateria_1 TINYINT DEFAULT 0, bateria_2 TINYINT DEFAULT 0,
    baterias_sin_dano TINYINT DEFAULT 0, cargador_incluido TINYINT DEFAULT 0,
    espejos TINYINT DEFAULT 0, tornilleria_completa TINYINT DEFAULT 0,
    birlos_completos TINYINT DEFAULT 0, kit_herramientas TINYINT DEFAULT 0,
    llaves_2 TINYINT DEFAULT 0, manual_usuario TINYINT DEFAULT 0, carnet_garantia TINYINT DEFAULT 0,
    sistema_enciende TINYINT DEFAULT 0, dashboard_funcional TINYINT DEFAULT 0,
    indicador_bateria TINYINT DEFAULT 0, luces_funcionando TINYINT DEFAULT 0,
    conectores_firmes TINYINT DEFAULT 0, cableado_sin_dano TINYINT DEFAULT 0,
    calcomanias_correctas TINYINT DEFAULT 0, alineacion_correcta TINYINT DEFAULT 0,
    sin_burbujas TINYINT DEFAULT 0, sin_desprendimientos TINYINT DEFAULT 0,
    sin_rayones TINYINT DEFAULT 0, acabados_correctos TINYINT DEFAULT 0,
    embalaje_correcto TINYINT DEFAULT 0, protecciones_colocadas TINYINT DEFAULT 0,
    caja_sin_dano TINYINT DEFAULT 0, sellos_colocados TINYINT DEFAULT 0, num_sellos INT DEFAULT 0,
    fotos JSON,
    declaracion_aceptada TINYINT DEFAULT 0, validacion_final TINYINT DEFAULT 0,
    notas TEXT, completado TINYINT DEFAULT 0, bloqueado TINYINT DEFAULT 0,
    pdf_url VARCHAR(255), hash_registro VARCHAR(128),
    freg DATETIME DEFAULT CURRENT_TIMESTAMP,
    fmod DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS checklist_ensamble (
    id INT AUTO_INCREMENT PRIMARY KEY,
    moto_id INT NOT NULL, dealer_id INT NOT NULL,
    fase_actual ENUM('fase1','fase2','fase3','completado') DEFAULT 'fase1',
    recepcion_validada TINYINT DEFAULT 0, primera_apertura TINYINT DEFAULT 0,
    area_segura TINYINT DEFAULT 0, herramientas_disponibles TINYINT DEFAULT 0,
    equipo_proteccion TINYINT DEFAULT 0, fotos_fase1 JSON,
    declaracion_fase1 TINYINT DEFAULT 0, fase1_completada TINYINT DEFAULT 0, fase1_fecha DATETIME,
    componentes_sin_dano TINYINT DEFAULT 0, accesorios_separados TINYINT DEFAULT 0,
    llanta_identificada TINYINT DEFAULT 0,
    base_instalada TINYINT DEFAULT 0, asiento_instalado TINYINT DEFAULT 0,
    tornilleria_base TINYINT DEFAULT 0, torque_base_25 TINYINT DEFAULT 0, fotos_base JSON,
    manubrio_instalado TINYINT DEFAULT 0, cableado_sin_tension TINYINT DEFAULT 0,
    alineacion_manubrio TINYINT DEFAULT 0, torque_manubrio_25 TINYINT DEFAULT 0, fotos_manubrio JSON,
    buje_corto TINYINT DEFAULT 0, buje_largo TINYINT DEFAULT 0, disco_alineado TINYINT DEFAULT 0,
    eje_instalado TINYINT DEFAULT 0, torque_llanta_50 TINYINT DEFAULT 0,
    fotos_llanta JSON, video_alineacion VARCHAR(255),
    espejo_izq TINYINT DEFAULT 0, espejo_der TINYINT DEFAULT 0, roscas_ok TINYINT DEFAULT 0,
    ajuste_espejos TINYINT DEFAULT 0, fotos_espejos JSON,
    fase2_completada TINYINT DEFAULT 0, fase2_fecha DATETIME,
    freno_del_funcional TINYINT DEFAULT 0, freno_tras_funcional TINYINT DEFAULT 0,
    luz_freno_operativa TINYINT DEFAULT 0,
    direccionales_ok TINYINT DEFAULT 0, intermitentes_ok TINYINT DEFAULT 0,
    luz_alta TINYINT DEFAULT 0, luz_baja TINYINT DEFAULT 0,
    claxon_ok TINYINT DEFAULT 0, dashboard_ok TINYINT DEFAULT 0,
    bateria_cargando TINYINT DEFAULT 0, puerto_carga_ok TINYINT DEFAULT 0,
    modo_eco TINYINT DEFAULT 0, modo_drive TINYINT DEFAULT 0,
    modo_sport TINYINT DEFAULT 0, reversa_ok TINYINT DEFAULT 0,
    nfc_ok TINYINT DEFAULT 0, control_remoto_ok TINYINT DEFAULT 0, llaves_funcionales TINYINT DEFAULT 0,
    sin_ruidos TINYINT DEFAULT 0, sin_interferencias TINYINT DEFAULT 0, torques_verificados TINYINT DEFAULT 0,
    fotos_fase3 JSON, declaracion_fase3 TINYINT DEFAULT 0,
    fase3_completada TINYINT DEFAULT 0, fase3_fecha DATETIME,
    completado TINYINT DEFAULT 0, bloqueado TINYINT DEFAULT 0, notas TEXT,
    freg DATETIME DEFAULT CURRENT_TIMESTAMP,
    fmod DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS checklist_entrega_v2 (
    id INT AUTO_INCREMENT PRIMARY KEY,
    moto_id INT NOT NULL, dealer_id INT NOT NULL,
    fase_actual ENUM('fase1','fase2','fase3','fase4','fase5','completado') DEFAULT 'fase1',
    ine_presentada TINYINT DEFAULT 0, nombre_coincide TINYINT DEFAULT 0,
    foto_coincide TINYINT DEFAULT 0, datos_confirmados TINYINT DEFAULT 0,
    ultimos4_telefono TINYINT DEFAULT 0, modelo_confirmado TINYINT DEFAULT 0,
    forma_pago_confirmada TINYINT DEFAULT 0, fotos_identidad JSON,
    face_match_result VARCHAR(50), face_match_score DECIMAL(5,4),
    fase1_completada TINYINT DEFAULT 0, fase1_fecha DATETIME,
    pago_confirmado TINYINT DEFAULT 0, enganche_validado TINYINT DEFAULT 0,
    metodo_pago_registrado TINYINT DEFAULT 0, domiciliacion_confirmada TINYINT DEFAULT 0,
    fase2_completada TINYINT DEFAULT 0, fase2_fecha DATETIME,
    vin_coincide TINYINT DEFAULT 0, unidad_ensamblada TINYINT DEFAULT 0,
    estado_fisico_ok TINYINT DEFAULT 0, sin_danos TINYINT DEFAULT 0,
    unidad_completa TINYINT DEFAULT 0, fotos_unidad JSON,
    fase3_completada TINYINT DEFAULT 0, fase3_fecha DATETIME,
    otp_enviado TINYINT DEFAULT 0, otp_validado TINYINT DEFAULT 0, otp_timestamp DATETIME,
    fase4_completada TINYINT DEFAULT 0, fase4_fecha DATETIME,
    acta_aceptada TINYINT DEFAULT 0, clausula_identidad TINYINT DEFAULT 0,
    clausula_medios TINYINT DEFAULT 0, clausula_uso_info TINYINT DEFAULT 0,
    firma_digital TINYINT DEFAULT 0, pdf_acta_url VARCHAR(255),
    fase5_completada TINYINT DEFAULT 0, fase5_fecha DATETIME,
    completado TINYINT DEFAULT 0, bloqueado TINYINT DEFAULT 0, notas TEXT,
    freg DATETIME DEFAULT CURRENT_TIMESTAMP,
    fmod DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Ensure OTP + firma columns on checklist_entrega_v2
try { $pdo->exec("ALTER TABLE checklist_entrega_v2 ADD COLUMN otp_code VARCHAR(10) NULL AFTER otp_validado"); } catch(Throwable $e){}
try { $pdo->exec("ALTER TABLE checklist_entrega_v2 ADD COLUMN otp_expires DATETIME NULL AFTER otp_code"); } catch(Throwable $e){}
try { $pdo->exec("ALTER TABLE checklist_entrega_v2 ADD COLUMN firma_data MEDIUMTEXT NULL AFTER firma_digital"); } catch(Throwable $e){}
// Ensure hash on checklist_ensamble + entrega
try { $pdo->exec("ALTER TABLE checklist_ensamble ADD COLUMN hash_registro VARCHAR(128) NULL AFTER notas"); } catch(Throwable $e){}
try { $pdo->exec("ALTER TABLE checklist_entrega_v2 ADD COLUMN hash_registro VARCHAR(128) NULL AFTER notas"); } catch(Throwable $e){}

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
