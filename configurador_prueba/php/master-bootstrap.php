<?php
/**
 * Voltika - Master Bootstrap (Single Source of Truth)
 * Consolidates ALL table definitions across the system.
 * Include from individual portal bootstraps to ensure complete schema.
 *
 * All CREATE TABLE statements are idempotent (IF NOT EXISTS).
 * All ALTER TABLE statements are wrapped in try/catch for safety.
 */

require_once __DIR__ . '/config.php';

function voltikaEnsureSchema(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = getDB();

    // ── 1. Core tables (from configurador) ──────────────────────────────────

    // Dealers / admin users
    $pdo->exec("CREATE TABLE IF NOT EXISTS dealer_usuarios (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        nombre        VARCHAR(200) NOT NULL,
        email         VARCHAR(200) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        punto_nombre  VARCHAR(200),
        punto_id      VARCHAR(100),
        rol           ENUM('dealer','admin','cedis','operador','cobranza','documentos','logistica') DEFAULT 'operador',
        permisos      JSON DEFAULT NULL,
        activo        TINYINT DEFAULT 1,
        freg          DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Inventario de motocicletas
    $pdo->exec("CREATE TABLE IF NOT EXISTS inventario_motos (
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
        estado             ENUM('por_llegar','recibida','por_ensamblar','en_ensamble','lista_para_entrega','por_validar_entrega','entregada','retenida','en_envio','en_punto') DEFAULT 'por_llegar',
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
        stripe_verified_at DATETIME NULL,
        cliente_acta_firmada TINYINT DEFAULT 0,
        fecha_estimada_recogida DATE NULL,
        envia_tracking_number VARCHAR(100) NULL,
        envia_tracking_url VARCHAR(255) NULL,
        activo             TINYINT DEFAULT 1,
        freg               DATETIME DEFAULT CURRENT_TIMESTAMP,
        fmod               DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Clientes
    $pdo->exec("CREATE TABLE IF NOT EXISTS clientes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(150) NULL,
        apellido_paterno VARCHAR(100) NULL,
        apellido_materno VARCHAR(100) NULL,
        email VARCHAR(150) NULL,
        telefono VARCHAR(20) NULL,
        fecha_nacimiento DATE NULL,
        freg DATETIME DEFAULT CURRENT_TIMESTAMP,
        fupd DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_telefono (telefono),
        INDEX idx_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Transacciones (configurador checkout)
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
        folio_contrato  VARCHAR(50)  NULL,
        fecha_estimada_entrega DATE NULL,
        pago_estado     VARCHAR(30)  NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Pedidos
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Subscripciones de credito
    $pdo->exec("CREATE TABLE IF NOT EXISTS subscripciones_credito (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT NULL,
        nombre VARCHAR(200) NULL,
        telefono VARCHAR(20) NULL,
        email VARCHAR(150) NULL,
        modelo VARCHAR(200) NULL,
        color VARCHAR(50) NULL,
        serie VARCHAR(100) NULL,
        precio_contado DECIMAL(12,2) NULL,
        monto_semanal DECIMAL(10,2) NULL,
        plazo_meses INT NULL,
        plazo_semanas INT NULL,
        fecha_inicio DATE NULL,
        fecha_entrega DATE NULL,
        stripe_customer_id VARCHAR(100) NULL,
        stripe_payment_method_id VARCHAR(100) NULL,
        stripe_setup_intent_id VARCHAR(100) NULL,
        inventario_moto_id INT NULL,
        estado VARCHAR(30) DEFAULT 'activa',
        factivacion DATETIME NULL,
        freg DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cliente (cliente_id),
        INDEX idx_telefono (telefono),
        INDEX idx_moto (inventario_moto_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── 2. Admin/CEDIS tables ───────────────────────────────────────────────

    // Puntos Voltika (complete definition)
    $pdo->exec("CREATE TABLE IF NOT EXISTS puntos_voltika (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(100) NULL,
        nombre VARCHAR(255) NOT NULL,
        direccion TEXT,
        colonia VARCHAR(200) NULL,
        ciudad VARCHAR(120),
        estado VARCHAR(80),
        cp VARCHAR(10),
        telefono VARCHAR(20),
        email VARCHAR(255),
        lat DECIMAL(10,7),
        lng DECIMAL(10,7),
        horarios VARCHAR(255),
        capacidad INT DEFAULT 0,
        tipo ENUM('center','certificado','entrega') DEFAULT 'entrega',
        servicios JSON NULL,
        tags JSON NULL,
        zonas JSON NULL,
        descripcion TEXT NULL,
        autorizado TINYINT DEFAULT 1,
        activo TINYINT DEFAULT 1,
        codigo_venta VARCHAR(20) UNIQUE COMMENT 'Codigo referido venta directa',
        codigo_electronico VARCHAR(20) UNIQUE COMMENT 'Codigo referido venta electronica',
        freg DATETIME DEFAULT CURRENT_TIMESTAMP,
        fmod DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Envios CEDIS -> Punto (complete with tracking)
    $pdo->exec("CREATE TABLE IF NOT EXISTS envios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        moto_id INT NOT NULL,
        punto_destino_id INT NOT NULL,
        estado ENUM('lista_para_enviar','enviada','recibida') DEFAULT 'lista_para_enviar',
        envio_tipo VARCHAR(50) NULL,
        fecha_envio DATE,
        fecha_estimada_llegada DATE,
        fecha_recepcion DATETIME,
        enviado_por INT COMMENT 'dealer_usuarios.id',
        recibido_por INT COMMENT 'dealer_usuarios.id',
        notas TEXT,
        tracking_number VARCHAR(100) NULL,
        carrier VARCHAR(80) NULL,
        freg DATETIME DEFAULT CURRENT_TIMESTAMP,
        fmod DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Recepcion en punto
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

    // Admin OTP for password recovery
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_otp (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        codigo VARCHAR(6) NOT NULL,
        expira DATETIME NOT NULL,
        usado TINYINT DEFAULT 0,
        ip VARCHAR(45),
        freg DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_usuario (usuario_id),
        INDEX idx_expira (expira)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Expand rol ENUM on existing tables (safe migration)
    try {
        $pdo->exec("ALTER TABLE dealer_usuarios MODIFY COLUMN rol ENUM('dealer','admin','cedis','operador','cobranza','documentos','logistica') DEFAULT 'operador'");
    } catch (Throwable $e) {}

    // Ciclos de pago (shared admin + portal)
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
        fecha_pago DATETIME DEFAULT NULL,
        origen VARCHAR(30) NULL,
        freg DATETIME DEFAULT CURRENT_TIMESTAMP,
        fupd DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sub_semana (subscripcion_id, semana_num),
        INDEX idx_cliente (cliente_id),
        INDEX idx_estado (estado),
        INDEX idx_venc (fecha_vencimiento)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Checklist pre-entrega (legacy v1)
    $pdo->exec("CREATE TABLE IF NOT EXISTS checklist_entrega (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Entregas / OTP
    $pdo->exec("CREATE TABLE IF NOT EXISTS entregas (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Ventas log
    $pdo->exec("CREATE TABLE IF NOT EXISTS ventas_log (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Referidos
    $pdo->exec("CREATE TABLE IF NOT EXISTS referidos (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        nombre           VARCHAR(200) NOT NULL,
        email            VARCHAR(200),
        telefono         VARCHAR(30),
        codigo_referido  VARCHAR(20) UNIQUE,
        ventas_count     INT DEFAULT 0,
        comision_total   DECIMAL(12,2) DEFAULT 0.00,
        activo           TINYINT DEFAULT 1,
        freg             DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Consultas buro de credito
    $pdo->exec("CREATE TABLE IF NOT EXISTS consultas_buro (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        nombre           VARCHAR(200),
        apellido_paterno VARCHAR(100),
        apellido_materno VARCHAR(100),
        fecha_nacimiento VARCHAR(20),
        cp               VARCHAR(10),
        score            INT,
        pago_mensual     DECIMAL(12,2),
        dpd90_flag       TINYINT(1),
        dpd_max          INT,
        num_cuentas      INT,
        folio_consulta   VARCHAR(100),
        freg             DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Verificaciones de identidad (Truora)
    $pdo->exec("CREATE TABLE IF NOT EXISTS verificaciones_identidad (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        nombre           VARCHAR(200),
        apellidos        VARCHAR(200),
        fecha_nacimiento VARCHAR(20),
        telefono         VARCHAR(30),
        email            VARCHAR(200),
        truora_check_id  VARCHAR(100),
        truora_score     DECIMAL(5,4),
        identity_status  VARCHAR(50),
        approved         TINYINT(1),
        files_saved      TEXT,
        freg             DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Firmas de contratos
    $pdo->exec("CREATE TABLE IF NOT EXISTS firmas_contratos (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        nombre        VARCHAR(200),
        email         VARCHAR(200),
        telefono      VARCHAR(30),
        curp          VARCHAR(20),
        modelo        VARCHAR(200),
        pdf_file      VARCHAR(255),
        customer_id   VARCHAR(100),
        firma_base64  MEDIUMTEXT,
        firma_sha256  CHAR(64),
        ip            VARCHAR(64),
        user_agent    VARCHAR(500),
        freg          DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email (email),
        INDEX idx_freg  (freg)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── 3. Portal-specific tables ───────────────────────────────────────────

    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_auth_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT NULL,
        evento VARCHAR(40) NOT NULL,
        telefono VARCHAR(20) NULL,
        email VARCHAR(150) NULL,
        old_phone VARCHAR(20) NULL,
        new_phone VARCHAR(20) NULL,
        validation_method VARCHAR(40) NULL,
        ip VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        success TINYINT(1) DEFAULT 0,
        detalle VARCHAR(255) NULL,
        freg DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cliente (cliente_id),
        INDEX idx_evento (evento)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_sesiones (
        id VARCHAR(128) PRIMARY KEY,
        cliente_id INT NOT NULL,
        ip VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        created DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cliente (cliente_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_descargas_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT NOT NULL,
        doc_type VARCHAR(50) NOT NULL,
        ip VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        freg DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cliente (cliente_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_preferencias (
        cliente_id INT PRIMARY KEY,
        notif_email TINYINT(1) DEFAULT 1,
        notif_whatsapp TINYINT(1) DEFAULT 1,
        notif_sms TINYINT(1) DEFAULT 1,
        idioma VARCHAR(5) DEFAULT 'es',
        fupd DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_recordatorios_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT NOT NULL,
        ciclo_id INT NULL,
        tipo VARCHAR(20) NOT NULL,
        canal VARCHAR(20) NOT NULL,
        estado VARCHAR(20) DEFAULT 'sent',
        freg DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cliente (cliente_id),
        INDEX idx_ciclo (ciclo_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_otp (
        id INT AUTO_INCREMENT PRIMARY KEY,
        telefono VARCHAR(20) NOT NULL,
        code VARCHAR(10) NOT NULL,
        expires DATETIME NOT NULL,
        used TINYINT(1) DEFAULT 0,
        freg DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_telefono (telefono)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_cambios_otp (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT NOT NULL,
        new_phone VARCHAR(20) NOT NULL,
        code VARCHAR(10) NOT NULL,
        expires DATETIME NOT NULL,
        used TINYINT(1) DEFAULT 0,
        freg DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cliente (cliente_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── 4. Checklist tables (origin, assembly, delivery v2) ─────────────────

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
        hash_registro VARCHAR(128) NULL,
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
        otp_enviado TINYINT DEFAULT 0, otp_validado TINYINT DEFAULT 0,
        otp_code VARCHAR(10) NULL, otp_expires DATETIME NULL,
        otp_timestamp DATETIME,
        fase4_completada TINYINT DEFAULT 0, fase4_fecha DATETIME,
        acta_aceptada TINYINT DEFAULT 0, clausula_identidad TINYINT DEFAULT 0,
        clausula_medios TINYINT DEFAULT 0, clausula_uso_info TINYINT DEFAULT 0,
        firma_digital TINYINT DEFAULT 0, firma_data MEDIUMTEXT NULL,
        firma_acta_data MEDIUMTEXT NULL, firma_pagare_data MEDIUMTEXT NULL,
        firma_pagare_timestamp DATETIME NULL, firma_pagare_cincel_id VARCHAR(255) NULL,
        pdf_acta_url VARCHAR(255),
        fase5_completada TINYINT DEFAULT 0, fase5_fecha DATETIME,
        completado TINYINT DEFAULT 0, bloqueado TINYINT DEFAULT 0, notas TEXT,
        hash_registro VARCHAR(128) NULL,
        freg DATETIME DEFAULT CURRENT_TIMESTAMP,
        fmod DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── 5. Idempotent ALTER statements for migration compatibility ──────────
    $alters = [
        "ALTER TABLE inventario_motos ADD COLUMN punto_voltika_id INT AFTER punto_id",
        "ALTER TABLE inventario_motos ADD COLUMN stripe_pi VARCHAR(100) NULL",
        "ALTER TABLE inventario_motos ADD COLUMN stripe_payment_status VARCHAR(30) NULL",
        "ALTER TABLE inventario_motos ADD COLUMN transaccion_id INT NULL",
        "ALTER TABLE ciclos_pago ADD COLUMN fecha_pago DATETIME DEFAULT NULL AFTER stripe_payment_intent",
        "ALTER TABLE inventario_motos ADD COLUMN cliente_id INT NULL",
        // inventario_motos: new columns for CEDIS flow + verification
        "ALTER TABLE inventario_motos ADD COLUMN stripe_verified_at DATETIME NULL",
        "ALTER TABLE inventario_motos ADD COLUMN cliente_acta_firmada TINYINT DEFAULT 0",
        "ALTER TABLE inventario_motos ADD COLUMN fecha_estimada_recogida DATE NULL",
        "ALTER TABLE inventario_motos ADD COLUMN envia_tracking_number VARCHAR(100) NULL",
        "ALTER TABLE inventario_motos ADD COLUMN envia_tracking_url VARCHAR(255) NULL",
        "ALTER TABLE inventario_motos MODIFY COLUMN estado ENUM('por_llegar','recibida','por_ensamblar','en_ensamble','lista_para_entrega','por_validar_entrega','entregada','retenida','en_envio','en_punto') DEFAULT 'por_llegar'",
        // subscripciones_credito: missing columns
        "ALTER TABLE subscripciones_credito ADD COLUMN nombre VARCHAR(200) NULL AFTER cliente_id",
        "ALTER TABLE subscripciones_credito ADD COLUMN stripe_setup_intent_id VARCHAR(100) NULL",
        "ALTER TABLE subscripciones_credito ADD COLUMN inventario_moto_id INT NULL",
        "ALTER TABLE subscripciones_credito ADD COLUMN factivacion DATETIME NULL",
        // dealer_usuarios: permisos + expanded rol
        "ALTER TABLE dealer_usuarios ADD COLUMN permisos JSON DEFAULT NULL",
        "ALTER TABLE dealer_usuarios MODIFY COLUMN rol ENUM('dealer','admin','cedis','operador','cobranza','documentos','logistica') DEFAULT 'operador'",
        // envios: envio_tipo
        "ALTER TABLE envios ADD COLUMN envio_tipo VARCHAR(50) NULL",
        // transacciones: runtime columns used by ventas
        "ALTER TABLE transacciones ADD COLUMN folio_contrato VARCHAR(50) NULL",
        "ALTER TABLE transacciones ADD COLUMN fecha_estimada_entrega DATE NULL",
        "ALTER TABLE transacciones ADD COLUMN pago_estado VARCHAR(30) NULL",
        // checklist_entrega_v2: dual signatures
        "ALTER TABLE checklist_entrega_v2 ADD COLUMN firma_acta_data MEDIUMTEXT NULL AFTER firma_data",
        "ALTER TABLE checklist_entrega_v2 ADD COLUMN firma_pagare_data MEDIUMTEXT NULL AFTER firma_acta_data",
        "ALTER TABLE checklist_entrega_v2 ADD COLUMN firma_pagare_timestamp DATETIME NULL AFTER firma_pagare_data",
        "ALTER TABLE checklist_entrega_v2 ADD COLUMN firma_pagare_cincel_id VARCHAR(255) NULL AFTER firma_pagare_timestamp",
    ];
    foreach ($alters as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) {}
    }
}
