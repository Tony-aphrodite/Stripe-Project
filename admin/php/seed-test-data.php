<?php
/**
 * Seed Script — Insert 1 test record into each table used by admin panel.
 * Run once via browser: /admin/php/seed-test-data.php
 * Safe to re-run: uses INSERT IGNORE / checks before inserting.
 */
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo = getDB();
$inserted = [];
$skipped  = [];

function seedOne(PDO $pdo, string $table, string $sql, array $params, array &$inserted, array &$skipped): void {
    try {
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        if ($cnt > 0) { $skipped[] = "$table (ya tiene $cnt registros)"; return; }
    } catch (Throwable $e) {
        // Table may not exist yet — try to insert anyway
    }
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $inserted[] = "$table ✓";
    } catch (Throwable $e) {
        $inserted[] = "$table ✗ ERROR: " . $e->getMessage();
    }
}

// ═══════════════════════════════════════════════════════════════════════
// 1. clientes — base customer
// ═══════════════════════════════════════════════════════════════════════
seedOne($pdo, 'clientes',
    "INSERT INTO clientes (nombre, apellido_paterno, apellido_materno, email, telefono, fecha_nacimiento)
     VALUES (?, ?, ?, ?, ?, ?)",
    ['Carlos', 'López', 'Hernández', 'carlos.test@voltika.mx', '5512345678', '1990-05-15'],
    $inserted, $skipped
);

// ═══════════════════════════════════════════════════════════════════════
// 2. dealer_usuarios — admin user (besides your own login)
// ═══════════════════════════════════════════════════════════════════════
seedOne($pdo, 'dealer_usuarios',
    "INSERT INTO dealer_usuarios (nombre, email, password_hash, rol, punto_nombre, activo)
     VALUES (?, ?, ?, ?, ?, 1)",
    ['Admin Test', 'admin-test@voltika.mx', password_hash('test1234', PASSWORD_DEFAULT), 'admin', 'CEDIS Central'],
    $inserted, $skipped
);

// ═══════════════════════════════════════════════════════════════════════
// 3. puntos_voltika — delivery point
// ═══════════════════════════════════════════════════════════════════════
seedOne($pdo, 'puntos_voltika',
    "INSERT INTO puntos_voltika (nombre, direccion, colonia, ciudad, estado, cp, telefono, email, lat, lng, horarios, capacidad, tipo, activo, codigo_venta, codigo_electronico)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)",
    ['Voltika CDMX Centro', 'Av. Reforma 222, Piso 3', 'Juárez', 'Ciudad de México', 'CDMX', '06600',
     '5598765432', 'cdmx@voltika.mx', 19.4326077, -99.1332080,
     'Lun-Vie 9:00-18:00, Sáb 10:00-14:00', 20, 'center', 'PVCDMX01', 'EVCDMX01'],
    $inserted, $skipped
);

// ═══════════════════════════════════════════════════════════════════════
// 4. modelos — motorcycle model catalog
// ═══════════════════════════════════════════════════════════════════════
try { $pdo->exec("CREATE TABLE IF NOT EXISTS modelos (
    id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(120) NOT NULL,
    categoria VARCHAR(80) DEFAULT '', precio_contado DECIMAL(12,2) DEFAULT 0,
    precio_financiado DECIMAL(12,2) DEFAULT 0, costo DECIMAL(12,2) DEFAULT 0,
    bateria VARCHAR(120) DEFAULT '', velocidad VARCHAR(60) DEFAULT '',
    autonomia VARCHAR(60) DEFAULT '', torque VARCHAR(60) DEFAULT '',
    imagen_url VARCHAR(500) DEFAULT '', activo TINYINT(1) DEFAULT 1,
    freg DATETIME DEFAULT CURRENT_TIMESTAMP,
    factualizado DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

seedOne($pdo, 'modelos',
    "INSERT INTO modelos (nombre, categoria, precio_contado, precio_financiado, costo, bateria, velocidad, autonomia, torque, activo)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
    ['M03', 'Urbana', 34999.00, 44999.00, 22000.00, 'Litio 72V 30Ah', '75 km/h', '80 km', '120 Nm'],
    $inserted, $skipped
);

// ═══════════════════════════════════════════════════════════════════════
// 5. inventario_motos — motorcycle in inventory
// ═══════════════════════════════════════════════════════════════════════
$puntoId = null;
try { $puntoId = (int)$pdo->query("SELECT id FROM puntos_voltika ORDER BY id LIMIT 1")->fetchColumn(); } catch (Throwable $e) {}
$clienteId = null;
try { $clienteId = (int)$pdo->query("SELECT id FROM clientes ORDER BY id LIMIT 1")->fetchColumn(); } catch (Throwable $e) {}

seedOne($pdo, 'inventario_motos',
    "INSERT INTO inventario_motos (vin, vin_display, modelo, color, anio_modelo, num_motor, potencia,
     config_baterias, hecho_en, estado, punto_voltika_id, punto_nombre,
     cliente_nombre, cliente_email, cliente_telefono, cliente_id,
     pedido_num, pago_estado, precio_venta, fecha_llegada, fecha_estado, activo)
     VALUES (?, ?, ?, ?, ?, ?, ?, '1', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1)",
    ['LVTDB2A63RE000101', 'RE000101', 'M03', 'Gris', '2025', 'MTR-2025-000101', '3000W',
     'China', 'lista_para_entrega', $puntoId, 'Voltika CDMX Centro',
     'Carlos López', 'carlos.test@voltika.mx', '5512345678', $clienteId,
     'VK-TEST001', 'pagada', 34999.00, date('Y-m-d', strtotime('-10 days'))],
    $inserted, $skipped
);

// ═══════════════════════════════════════════════════════════════════════
// 6. transacciones — sale order
// ═══════════════════════════════════════════════════════════════════════
seedOne($pdo, 'transacciones',
    "INSERT INTO transacciones (nombre, email, telefono, modelo, color, ciudad, estado, cp, tpago, precio, total, pedido, stripe_pi, punto_nombre, pago_estado)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    ['Carlos López', 'carlos.test@voltika.mx', '5512345678', 'M03', 'Gris',
     'Ciudad de México', 'CDMX', '06600', 'contado', 34999.00, 34999.00,
     'VK-TEST001', 'pi_test_seed_001', 'Voltika CDMX Centro', 'pagada'],
    $inserted, $skipped
);

// ═══════════════════════════════════════════════════════════════════════
// 7. subscripciones_credito — credit subscription
// ═══════════════════════════════════════════════════════════════════════
seedOne($pdo, 'subscripciones_credito',
    "INSERT INTO subscripciones_credito (cliente_id, nombre, telefono, email, modelo, color, serie,
     precio_contado, monto_semanal, plazo_meses, plazo_semanas, fecha_inicio, estado,
     stripe_customer_id, stripe_payment_method_id)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'activa', ?, ?)",
    [$clienteId, 'Carlos López', '5512345678', 'carlos.test@voltika.mx',
     'M03', 'Gris', 'RE000101', 34999.00, 850.00, 12, 52,
     date('Y-m-d', strtotime('-7 days')),
     'cus_test_seed', 'pm_test_seed'],
    $inserted, $skipped
);

// ═══════════════════════════════════════════════════════════════════════
// 8. ciclos_pago — payment cycles (1 pending + 1 overdue)
// ═══════════════════════════════════════════════════════════════════════
$subId = null;
try { $subId = (int)$pdo->query("SELECT id FROM subscripciones_credito ORDER BY id DESC LIMIT 1")->fetchColumn(); } catch (Throwable $e) {}

try {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM ciclos_pago")->fetchColumn();
    if ($cnt === 0) {
        $pdo->prepare("INSERT INTO ciclos_pago (subscripcion_id, cliente_id, semana_num, fecha_vencimiento, monto, estado)
            VALUES (?, ?, 1, ?, 850.00, 'paid_auto')")->execute([$subId, $clienteId, date('Y-m-d', strtotime('-7 days'))]);
        $pdo->prepare("INSERT INTO ciclos_pago (subscripcion_id, cliente_id, semana_num, fecha_vencimiento, monto, estado)
            VALUES (?, ?, 2, ?, 850.00, 'pending')")->execute([$subId, $clienteId, date('Y-m-d')]);
        $pdo->prepare("INSERT INTO ciclos_pago (subscripcion_id, cliente_id, semana_num, fecha_vencimiento, monto, estado, stripe_payment_intent)
            VALUES (?, ?, 3, ?, 850.00, 'overdue', 'pi_test_overdue')")->execute([$subId, $clienteId, date('Y-m-d', strtotime('-3 days'))]);
        $inserted[] = "ciclos_pago ✓ (3 ciclos: paid, pending, overdue)";
    } else { $skipped[] = "ciclos_pago (ya tiene $cnt registros)"; }
} catch (Throwable $e) { $inserted[] = "ciclos_pago ✗ ERROR: " . $e->getMessage(); }

// ═══════════════════════════════════════════════════════════════════════
// 9. envios — shipment CEDIS → punto
// ═══════════════════════════════════════════════════════════════════════
$motoId = null;
try { $motoId = (int)$pdo->query("SELECT id FROM inventario_motos ORDER BY id DESC LIMIT 1")->fetchColumn(); } catch (Throwable $e) {}
$dealerId = null;
try { $dealerId = (int)$pdo->query("SELECT id FROM dealer_usuarios ORDER BY id LIMIT 1")->fetchColumn(); } catch (Throwable $e) {}

seedOne($pdo, 'envios',
    "INSERT INTO envios (moto_id, punto_destino_id, estado, fecha_envio, fecha_estimada_llegada, enviado_por, tracking_number, carrier, notas)
     VALUES (?, ?, 'enviada', ?, ?, ?, ?, ?, ?)",
    [$motoId, $puntoId, date('Y-m-d', strtotime('-3 days')), date('Y-m-d', strtotime('+2 days')),
     $dealerId, 'TRK-SEED-001', 'DHL Express', 'Envío de prueba CEDIS→CDMX'],
    $inserted, $skipped
);

// ═══════════════════════════════════════════════════════════════════════
// 10. checklist_origen — origin quality checklist
// ═══════════════════════════════════════════════════════════════════════
seedOne($pdo, 'checklist_origen',
    "INSERT INTO checklist_origen (moto_id, dealer_id, vin, modelo, color, anio_modelo,
     frame_completo, chasis_sin_deformaciones, llanta_delantera, llanta_trasera,
     sistema_enciende, dashboard_funcional, completado)
     VALUES (?, ?, ?, ?, ?, ?, 1, 1, 1, 1, 1, 1, 1)",
    [$motoId, $dealerId ?: 1, 'LVTDB2A63RE000101', 'M03', 'Gris', '2025'],
    $inserted, $skipped
);

// ═══════════════════════════════════════════════════════════════════════
// 11. checklist_ensamble — assembly checklist
// ═══════════════════════════════════════════════════════════════════════
seedOne($pdo, 'checklist_ensamble',
    "INSERT INTO checklist_ensamble (moto_id, dealer_id, fase_actual,
     fase1_completada, fase2_completada, fase3_completada, completado)
     VALUES (?, ?, 'completado', 1, 1, 1, 1)",
    [$motoId, $dealerId ?: 1],
    $inserted, $skipped
);

// ═══════════════════════════════════════════════════════════════════════
// 12. checklist_entrega_v2 — delivery checklist
// ═══════════════════════════════════════════════════════════════════════
seedOne($pdo, 'checklist_entrega_v2',
    "INSERT INTO checklist_entrega_v2 (moto_id, dealer_id, fase_actual,
     fase1_completada, fase2_completada, fase3_completada, fase4_completada, completado)
     VALUES (?, ?, 'fase5', 1, 1, 1, 1, 0)",
    [$motoId, $dealerId ?: 1],
    $inserted, $skipped
);

// ═══════════════════════════════════════════════════════════════════════
// 13. consultas_buro — credit bureau check
// ═══════════════════════════════════════════════════════════════════════
seedOne($pdo, 'consultas_buro',
    "INSERT INTO consultas_buro (nombre, apellido_paterno, apellido_materno, fecha_nacimiento, cp, score, pago_mensual, dpd90_flag, dpd_max, num_cuentas, folio_consulta)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    ['Carlos', 'López', 'Hernández', '1990-05-15', '06600', 720, 3500.00, 0, 15, 4, 'BURO-SEED-001'],
    $inserted, $skipped
);

// ═══════════════════════════════════════════════════════════════════════
// 14. precios_condiciones — pricing per model
// ═══════════════════════════════════════════════════════════════════════
try { $pdo->exec("CREATE TABLE IF NOT EXISTS precios_condiciones (
    id INT AUTO_INCREMENT PRIMARY KEY, modelo_id INT NOT NULL,
    enganche_min DECIMAL(12,2) DEFAULT 0, enganche_max DECIMAL(12,2) DEFAULT 0,
    pago_semanal DECIMAL(12,2) DEFAULT 0, tasa_interna DECIMAL(6,4) DEFAULT 0,
    plazo_semanas INT DEFAULT 52, msi_opciones JSON DEFAULT NULL,
    promocion_nombre VARCHAR(200) DEFAULT '', promocion_activa TINYINT(1) DEFAULT 0,
    promocion_descuento DECIMAL(12,2) DEFAULT 0,
    freg DATETIME DEFAULT CURRENT_TIMESTAMP,
    factualizado DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_modelo (modelo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

$modeloId = null;
try { $modeloId = (int)$pdo->query("SELECT id FROM modelos ORDER BY id LIMIT 1")->fetchColumn(); } catch (Throwable $e) {}

if ($modeloId) {
    seedOne($pdo, 'precios_condiciones',
        "INSERT INTO precios_condiciones (modelo_id, enganche_min, enganche_max, pago_semanal, tasa_interna, plazo_semanas, msi_opciones)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        [$modeloId, 5000.00, 15000.00, 850.00, 0.1200, 52, json_encode([3,6,12])],
        $inserted, $skipped
    );
}

// ═══════════════════════════════════════════════════════════════════════
// 15. tiempos_entrega — delivery times config
// ═══════════════════════════════════════════════════════════════════════
try { $pdo->exec("CREATE TABLE IF NOT EXISTS tiempos_entrega (
    id INT AUTO_INCREMENT PRIMARY KEY, modelo VARCHAR(120) NOT NULL DEFAULT '',
    ciudad VARCHAR(120) NOT NULL DEFAULT '', dias_estimados INT DEFAULT 7,
    disponible_inmediato TINYINT(1) DEFAULT 0, notas VARCHAR(500) DEFAULT '',
    activo TINYINT(1) DEFAULT 1, freg DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_modelo_ciudad (modelo, ciudad)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

seedOne($pdo, 'tiempos_entrega',
    "INSERT INTO tiempos_entrega (modelo, ciudad, dias_estimados, disponible_inmediato, notas, activo)
     VALUES (?, ?, ?, ?, ?, 1)",
    ['M03', 'Ciudad de México', 5, 1, 'Disponible para entrega inmediata en CDMX'],
    $inserted, $skipped
);

// ═══════════════════════════════════════════════════════════════════════
// 16. documentos_cliente — customer documents
// ═══════════════════════════════════════════════════════════════════════
try { $pdo->exec("CREATE TABLE IF NOT EXISTS documentos_cliente (
    id INT AUTO_INCREMENT PRIMARY KEY, cliente_id INT NOT NULL,
    tipo ENUM('contrato','acta_entrega','factura','carta_factura','seguro','ine','pagare') NOT NULL,
    archivo_url VARCHAR(500) DEFAULT '', archivo_nombre VARCHAR(250) DEFAULT '',
    estado ENUM('pendiente','subido','verificado') DEFAULT 'pendiente',
    notas TEXT DEFAULT NULL, subido_por INT DEFAULT NULL,
    freg DATETIME DEFAULT CURRENT_TIMESTAMP,
    factualizado DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_cliente (cliente_id), KEY idx_tipo (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

seedOne($pdo, 'documentos_cliente',
    "INSERT INTO documentos_cliente (cliente_id, tipo, archivo_url, archivo_nombre, estado, notas)
     VALUES (?, 'contrato', '/uploads/test/contrato_test.pdf', 'contrato_carlos_lopez.pdf', 'verificado', 'Contrato de prueba')",
    [$clienteId],
    $inserted, $skipped
);

// ═══════════════════════════════════════════════════════════════════════
// 17. incidencias_punto — punto incidents
// ═══════════════════════════════════════════════════════════════════════
try { $pdo->exec("CREATE TABLE IF NOT EXISTS incidencias_punto (
    id INT AUTO_INCREMENT PRIMARY KEY, punto_id INT NOT NULL,
    tipo ENUM('queja','averia','faltante','otro') DEFAULT 'otro',
    descripcion TEXT NOT NULL, estado ENUM('abierta','en_proceso','resuelta') DEFAULT 'abierta',
    reportado_por INT DEFAULT NULL, freg DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_punto (punto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

seedOne($pdo, 'incidencias_punto',
    "INSERT INTO incidencias_punto (punto_id, tipo, descripcion, estado, reportado_por)
     VALUES (?, 'faltante', 'Falta cargador de batería en unidad recibida', 'abierta', ?)",
    [$puntoId, $dealerId],
    $inserted, $skipped
);

// ═══════════════════════════════════════════════════════════════════════
// 18. transacciones_errores — orphan/error orders
// ═══════════════════════════════════════════════════════════════════════
try { $pdo->exec("CREATE TABLE IF NOT EXISTS transacciones_errores (
    id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(200), email VARCHAR(200),
    telefono VARCHAR(30), modelo VARCHAR(200), color VARCHAR(100),
    total DECIMAL(12,2), stripe_pi VARCHAR(100), payload TEXT, error_msg TEXT,
    freg DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

seedOne($pdo, 'transacciones_errores',
    "INSERT INTO transacciones_errores (nombre, email, telefono, modelo, color, total, stripe_pi, error_msg)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
    ['Error Test', 'error@test.mx', '5500000000', 'M03', 'Negro', 34999.00, 'pi_test_error_001', 'card_declined: insufficient_funds'],
    $inserted, $skipped
);

// ═══════════════════════════════════════════════════════════════════════
// 19. portal_recordatorios_log — notification log (for Notificaciones module)
// ═══════════════════════════════════════════════════════════════════════
seedOne($pdo, 'portal_recordatorios_log',
    "INSERT INTO portal_recordatorios_log (cliente_id, ciclo_id, tipo, canal, estado)
     VALUES (?, NULL, 'reminder_dia_pago', 'sms', 'sent')",
    [$clienteId],
    $inserted, $skipped
);

// ═══════════════════════════════════════════════════════════════════════
// 20. portal_preferencias — client notification preferences
// ═══════════════════════════════════════════════════════════════════════
seedOne($pdo, 'portal_preferencias',
    "INSERT INTO portal_preferencias (cliente_id, notif_email, notif_whatsapp, notif_sms)
     VALUES (?, 1, 1, 0)",
    [$clienteId],
    $inserted, $skipped
);

// ═══════════════════════════════════════════════════════════════════════
// OUTPUT
// ═══════════════════════════════════════════════════════════════════════
echo "╔══════════════════════════════════════════════╗\n";
echo "║   Voltika — Test Data Seed Script            ║\n";
echo "╚══════════════════════════════════════════════╝\n\n";

echo "INSERTADOS (" . count($inserted) . "):\n";
foreach ($inserted as $i) echo "  • $i\n";

echo "\nOMITIDOS (ya tenían datos) (" . count($skipped) . "):\n";
foreach ($skipped as $s) echo "  • $s\n";

echo "\n— Listo. Recarga el admin panel para ver los datos.\n";
