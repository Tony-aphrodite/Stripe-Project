<?php
/**
 * Voltika Admin - Crear tablas de checklists v2
 * 3 tablas: checklist_origen, checklist_ensamble, checklist_entrega_v2
 *
 * Ejecutar UNA SOLA VEZ: ?key=voltika_checklists_2026
 */

$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika_checklists_2026') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=UTF-8');

$pdo = getDB();
$resultados = [];

$tablas = [

    // ── 1. CHECKLIST DE ORIGEN ──────────────────────────────────────────────
    'checklist_origen' => "
        CREATE TABLE IF NOT EXISTS checklist_origen (
            id                  INT AUTO_INCREMENT PRIMARY KEY,
            moto_id             INT NOT NULL,
            dealer_id           INT NOT NULL,
            vin                 VARCHAR(50),
            num_motor           VARCHAR(50),
            modelo              VARCHAR(100),
            color               VARCHAR(50),
            anio_modelo         VARCHAR(10),
            config_baterias     ENUM('1','2') DEFAULT '1',

            -- 1. Estructura principal
            frame_completo          TINYINT DEFAULT 0,
            chasis_sin_deformaciones TINYINT DEFAULT 0,
            soportes_estructurales  TINYINT DEFAULT 0,
            charola_trasera         TINYINT DEFAULT 0,

            -- 2. Sistema de rodamiento
            llanta_delantera     TINYINT DEFAULT 0,
            llanta_trasera       TINYINT DEFAULT 0,
            rines_sin_dano       TINYINT DEFAULT 0,
            ejes_completos       TINYINT DEFAULT 0,

            -- 3. Dirección y control
            manubrio             TINYINT DEFAULT 0,
            soportes_completos   TINYINT DEFAULT 0,
            dashboard_incluido   TINYINT DEFAULT 0,
            controles_completos  TINYINT DEFAULT 0,

            -- 4. Sistema de frenado
            freno_delantero      TINYINT DEFAULT 0,
            freno_trasero        TINYINT DEFAULT 0,
            discos_sin_dano      TINYINT DEFAULT 0,
            calipers_instalados  TINYINT DEFAULT 0,
            lineas_completas     TINYINT DEFAULT 0,

            -- 5. Sistema eléctrico
            cableado_completo    TINYINT DEFAULT 0,
            conectores_correctos TINYINT DEFAULT 0,
            controlador_instalado TINYINT DEFAULT 0,
            encendido_operativo  TINYINT DEFAULT 0,

            -- 6. Motor
            motor_instalado      TINYINT DEFAULT 0,
            motor_sin_dano       TINYINT DEFAULT 0,
            motor_conexion       TINYINT DEFAULT 0,

            -- 7. Baterías
            bateria_1            TINYINT DEFAULT 0,
            bateria_2            TINYINT DEFAULT 0,
            baterias_sin_dano    TINYINT DEFAULT 0,
            cargador_incluido    TINYINT DEFAULT 0,

            -- 8. Accesorios
            espejos              TINYINT DEFAULT 0,
            tornilleria_completa TINYINT DEFAULT 0,
            birlos_completos     TINYINT DEFAULT 0,
            kit_herramientas     TINYINT DEFAULT 0,

            -- 9. Complementos
            llaves_2             TINYINT DEFAULT 0,
            manual_usuario       TINYINT DEFAULT 0,
            carnet_garantia      TINYINT DEFAULT 0,

            -- 10. Validación eléctrica
            sistema_enciende     TINYINT DEFAULT 0,
            dashboard_funcional  TINYINT DEFAULT 0,
            indicador_bateria    TINYINT DEFAULT 0,
            luces_funcionando    TINYINT DEFAULT 0,
            conectores_firmes    TINYINT DEFAULT 0,
            cableado_sin_dano    TINYINT DEFAULT 0,

            -- 11. Artes decorativos
            calcomanias_correctas TINYINT DEFAULT 0,
            alineacion_correcta  TINYINT DEFAULT 0,
            sin_burbujas         TINYINT DEFAULT 0,
            sin_desprendimientos TINYINT DEFAULT 0,
            sin_rayones          TINYINT DEFAULT 0,
            acabados_correctos   TINYINT DEFAULT 0,

            -- 12. Empaque
            embalaje_correcto    TINYINT DEFAULT 0,
            protecciones_colocadas TINYINT DEFAULT 0,
            caja_sin_dano        TINYINT DEFAULT 0,
            sellos_colocados     TINYINT DEFAULT 0,
            num_sellos           INT DEFAULT 0,

            -- 13. Evidencia fotográfica (JSON array of filenames)
            fotos                JSON,

            -- 14-15. Declaración y validación
            declaracion_aceptada TINYINT DEFAULT 0,
            validacion_final     TINYINT DEFAULT 0,

            -- Meta
            notas               TEXT,
            completado          TINYINT DEFAULT 0,
            bloqueado           TINYINT DEFAULT 0,
            pdf_url             VARCHAR(255),
            hash_registro       VARCHAR(128),
            freg                DATETIME DEFAULT CURRENT_TIMESTAMP,
            fmod                DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",

    // ── 2. CHECKLIST DE ENSAMBLE ────────────────────────────────────────────
    'checklist_ensamble' => "
        CREATE TABLE IF NOT EXISTS checklist_ensamble (
            id                  INT AUTO_INCREMENT PRIMARY KEY,
            moto_id             INT NOT NULL,
            dealer_id           INT NOT NULL,
            fase_actual         ENUM('fase1','fase2','fase3','completado') DEFAULT 'fase1',

            -- FASE 1: Inicio
            recepcion_validada      TINYINT DEFAULT 0,
            primera_apertura        TINYINT DEFAULT 0,
            area_segura             TINYINT DEFAULT 0,
            herramientas_disponibles TINYINT DEFAULT 0,
            equipo_proteccion       TINYINT DEFAULT 0,
            fotos_fase1             JSON,
            declaracion_fase1       TINYINT DEFAULT 0,
            fase1_completada        TINYINT DEFAULT 0,
            fase1_fecha             DATETIME,

            -- FASE 2: Durante ensamble
            -- 2.1 Desembalaje
            componentes_sin_dano    TINYINT DEFAULT 0,
            accesorios_separados    TINYINT DEFAULT 0,
            llanta_identificada     TINYINT DEFAULT 0,

            -- 2.2 Base y asiento
            base_instalada          TINYINT DEFAULT 0,
            asiento_instalado       TINYINT DEFAULT 0,
            tornilleria_base        TINYINT DEFAULT 0,
            torque_base_25          TINYINT DEFAULT 0,
            fotos_base              JSON,

            -- 2.3 Manubrio
            manubrio_instalado      TINYINT DEFAULT 0,
            cableado_sin_tension    TINYINT DEFAULT 0,
            alineacion_manubrio     TINYINT DEFAULT 0,
            torque_manubrio_25      TINYINT DEFAULT 0,
            fotos_manubrio          JSON,

            -- 2.4 Llanta delantera
            buje_corto              TINYINT DEFAULT 0,
            buje_largo              TINYINT DEFAULT 0,
            disco_alineado          TINYINT DEFAULT 0,
            eje_instalado           TINYINT DEFAULT 0,
            torque_llanta_50        TINYINT DEFAULT 0,
            fotos_llanta            JSON,
            video_alineacion        VARCHAR(255),

            -- 2.5 Espejos
            espejo_izq              TINYINT DEFAULT 0,
            espejo_der              TINYINT DEFAULT 0,
            roscas_ok               TINYINT DEFAULT 0,
            ajuste_espejos          TINYINT DEFAULT 0,
            fotos_espejos           JSON,

            fase2_completada        TINYINT DEFAULT 0,
            fase2_fecha             DATETIME,

            -- FASE 3: Validación final
            -- 3.1 Frenos
            freno_del_funcional     TINYINT DEFAULT 0,
            freno_tras_funcional    TINYINT DEFAULT 0,
            luz_freno_operativa     TINYINT DEFAULT 0,

            -- 3.2 Iluminación
            direccionales_ok        TINYINT DEFAULT 0,
            intermitentes_ok        TINYINT DEFAULT 0,
            luz_alta                TINYINT DEFAULT 0,
            luz_baja                TINYINT DEFAULT 0,

            -- 3.3 Sistema eléctrico
            claxon_ok               TINYINT DEFAULT 0,
            dashboard_ok            TINYINT DEFAULT 0,
            bateria_cargando        TINYINT DEFAULT 0,
            puerto_carga_ok         TINYINT DEFAULT 0,

            -- 3.4 Motor y modos
            modo_eco                TINYINT DEFAULT 0,
            modo_drive              TINYINT DEFAULT 0,
            modo_sport              TINYINT DEFAULT 0,
            reversa_ok              TINYINT DEFAULT 0,

            -- 3.5 Acceso
            nfc_ok                  TINYINT DEFAULT 0,
            control_remoto_ok       TINYINT DEFAULT 0,
            llaves_funcionales      TINYINT DEFAULT 0,

            -- 3.6 Validación mecánica
            sin_ruidos              TINYINT DEFAULT 0,
            sin_interferencias      TINYINT DEFAULT 0,
            torques_verificados     TINYINT DEFAULT 0,

            fotos_fase3             JSON,
            declaracion_fase3       TINYINT DEFAULT 0,
            fase3_completada        TINYINT DEFAULT 0,
            fase3_fecha             DATETIME,

            -- Meta
            completado              TINYINT DEFAULT 0,
            bloqueado               TINYINT DEFAULT 0,
            notas                   TEXT,
            freg                    DATETIME DEFAULT CURRENT_TIMESTAMP,
            fmod                    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",

    // ── 3. CHECKLIST DE ENTREGA v2 ──────────────────────────────────────────
    'checklist_entrega_v2' => "
        CREATE TABLE IF NOT EXISTS checklist_entrega_v2 (
            id                  INT AUTO_INCREMENT PRIMARY KEY,
            moto_id             INT NOT NULL,
            dealer_id           INT NOT NULL,
            fase_actual         ENUM('fase1','fase2','fase3','fase4','fase5','completado') DEFAULT 'fase1',

            -- FASE 1: Identidad
            ine_presentada          TINYINT DEFAULT 0,
            nombre_coincide         TINYINT DEFAULT 0,
            foto_coincide           TINYINT DEFAULT 0,
            datos_confirmados       TINYINT DEFAULT 0,
            ultimos4_telefono       TINYINT DEFAULT 0,
            modelo_confirmado       TINYINT DEFAULT 0,
            forma_pago_confirmada   TINYINT DEFAULT 0,
            fotos_identidad         JSON,
            face_match_result       VARCHAR(50),
            face_match_score        DECIMAL(5,4),
            fase1_completada        TINYINT DEFAULT 0,
            fase1_fecha             DATETIME,

            -- FASE 2: Pago
            pago_confirmado         TINYINT DEFAULT 0,
            enganche_validado       TINYINT DEFAULT 0,
            metodo_pago_registrado  TINYINT DEFAULT 0,
            domiciliacion_confirmada TINYINT DEFAULT 0,
            fase2_completada        TINYINT DEFAULT 0,
            fase2_fecha             DATETIME,

            -- FASE 3: Unidad
            vin_coincide            TINYINT DEFAULT 0,
            unidad_ensamblada       TINYINT DEFAULT 0,
            estado_fisico_ok        TINYINT DEFAULT 0,
            sin_danos               TINYINT DEFAULT 0,
            unidad_completa         TINYINT DEFAULT 0,
            fotos_unidad            JSON,
            fase3_completada        TINYINT DEFAULT 0,
            fase3_fecha             DATETIME,

            -- FASE 4: OTP
            otp_enviado             TINYINT DEFAULT 0,
            otp_validado            TINYINT DEFAULT 0,
            otp_timestamp           DATETIME,
            fase4_completada        TINYINT DEFAULT 0,
            fase4_fecha             DATETIME,

            -- FASE 5: Acta legal
            acta_aceptada           TINYINT DEFAULT 0,
            clausula_identidad      TINYINT DEFAULT 0,
            clausula_medios         TINYINT DEFAULT 0,
            clausula_uso_info       TINYINT DEFAULT 0,
            firma_digital           TINYINT DEFAULT 0,
            pdf_acta_url            VARCHAR(255),
            fase5_completada        TINYINT DEFAULT 0,
            fase5_fecha             DATETIME,

            -- Meta
            completado              TINYINT DEFAULT 0,
            bloqueado               TINYINT DEFAULT 0,
            notas                   TEXT,
            freg                    DATETIME DEFAULT CURRENT_TIMESTAMP,
            fmod                    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
];

echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Voltika — Crear tablas checklists</title></head><body>';
echo '<h1>Voltika — Crear tablas de checklists v2</h1>';

foreach ($tablas as $nombre => $sql) {
    try {
        $pdo->exec($sql);
        $resultados[$nombre] = 'OK';
        echo '<p style="color:green;">✅ <strong>' . $nombre . '</strong> — OK</p>';
    } catch (PDOException $e) {
        $resultados[$nombre] = 'ERROR: ' . $e->getMessage();
        echo '<p style="color:red;">❌ <strong>' . $nombre . '</strong> — ' . $e->getMessage() . '</p>';
    }
}

echo '<hr>';
echo '<p><strong>Resultado:</strong></p>';
echo '<pre>' . json_encode($resultados, JSON_PRETTY_PRINT) . '</pre>';
echo '<p style="color:#C62828;">⚠️ Eliminar este script después de ejecutar.</p>';
echo '</body></html>';
