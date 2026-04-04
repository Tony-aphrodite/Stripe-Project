<?php
/**
 * Voltika Admin — CEDIS (Central Inventory) operations
 * Requires admin or cedis role.
 *
 * POST { accion: 'mover_punto', moto_id, punto_id, punto_nombre }     → Move moto to Punto
 * POST { accion: 'asignar_pago', moto_id, transaccion_id, stripe_pi } → Link moto to payment
 * POST { accion: 'import_excel', motos[] }                            → Bulk import from parsed Excel
 * GET  ?vista=puntos                                                  → All puntos with inventory count
 * GET  ?vista=transacciones_pendientes                                → Payments without moto assigned
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-auth.php';

$dealer = requireDealerAuth();

// Only admin and cedis roles
if (!in_array($dealer['rol'], ['admin', 'cedis'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Acceso solo para administradores CEDIS']);
    exit;
}

$pdo = getDB();

// ── GET ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $vista = $_GET['vista'] ?? '';

    // Puntos overview
    if ($vista === 'puntos') {
        $stmt = $pdo->query("
            SELECT d.punto_id, d.punto_nombre, d.nombre AS dealer_nombre, d.id AS dealer_id,
                   COUNT(m.id) AS total_motos,
                   SUM(CASE WHEN m.estado NOT IN ('entregada') THEN 1 ELSE 0 END) AS activas,
                   SUM(CASE WHEN m.estado = 'entregada' THEN 1 ELSE 0 END) AS entregadas
            FROM dealer_usuarios d
            LEFT JOIN inventario_motos m ON m.dealer_id = d.id AND m.activo = 1
            WHERE d.activo = 1
            GROUP BY d.id
            ORDER BY d.punto_nombre
        ");
        echo json_encode(['ok' => true, 'puntos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // Unassigned transactions (payments without moto)
    if ($vista === 'transacciones_pendientes') {
        $stmt = $pdo->query("
            SELECT t.id, t.nombre, t.email, t.telefono, t.modelo, t.color,
                   t.tpago, t.total, t.pedido, t.stripe_pi, t.freg
            FROM transacciones t
            LEFT JOIN inventario_motos m ON m.transaccion_id = t.id
            WHERE m.id IS NULL
            ORDER BY t.freg DESC
            LIMIT 100
        ");
        echo json_encode(['ok' => true, 'transacciones' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // Default: all motos with location info
    $stmt = $pdo->query("
        SELECT m.id, m.vin, m.modelo, m.color, m.estado, m.punto_nombre,
               m.cliente_nombre, m.pedido_num, m.pago_estado, m.stripe_payment_status,
               m.dealer_id, m.cedis_origen, m.transaccion_id, m.freg,
               d.nombre AS dealer_nombre
        FROM inventario_motos m
        LEFT JOIN dealer_usuarios d ON d.id = m.dealer_id
        WHERE m.activo = 1
        ORDER BY m.freg DESC
        LIMIT 500
    ");
    echo json_encode(['ok' => true, 'motos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ── POST ─────────────────────────────────────────────────────────────────────
$json   = json_decode(file_get_contents('php://input'), true) ?? [];
$accion = $json['accion'] ?? '';

// ── MOVER A PUNTO ────────────────────────────────────────────────────────────
if ($accion === 'mover_punto') {
    $motoId     = intval($json['moto_id'] ?? 0);
    $dealerId   = intval($json['dealer_id'] ?? 0);
    $puntoNombre = trim($json['punto_nombre'] ?? '');
    $puntoId     = trim($json['punto_id'] ?? '');

    if (!$motoId || !$dealerId) {
        echo json_encode(['ok' => false, 'error' => 'moto_id y dealer_id requeridos']);
        exit;
    }

    $pdo->prepare("
        UPDATE inventario_motos
        SET dealer_id = ?, punto_nombre = ?, punto_id = ?,
            cedis_origen = IFNULL(cedis_origen, punto_nombre)
        WHERE id = ?
    ")->execute([$dealerId, $puntoNombre, $puntoId, $motoId]);

    // Log
    $pdo->prepare("
        UPDATE inventario_motos
        SET log_estados = JSON_ARRAY_APPEND(IFNULL(log_estados,'[]'), '$',
            JSON_OBJECT('estado', estado, 'accion', 'transferencia_cedis',
                        'dealer', ?, 'timestamp', NOW(),
                        'notas', CONCAT('Transferido a: ', ?)))
        WHERE id = ?
    ")->execute([$dealer['nombre'], $puntoNombre, $motoId]);

    echo json_encode(['ok' => true, 'message' => 'Moto transferida a ' . $puntoNombre]);
    exit;
}

// ── ASIGNAR PAGO ─────────────────────────────────────────────────────────────
if ($accion === 'asignar_pago') {
    $motoId       = intval($json['moto_id'] ?? 0);
    $transaccionId = intval($json['transaccion_id'] ?? 0);
    $stripePi     = trim($json['stripe_pi'] ?? '');

    if (!$motoId) {
        echo json_encode(['ok' => false, 'error' => 'moto_id requerido']);
        exit;
    }

    $sets = [];
    $vals = [];

    if ($transaccionId) {
        $sets[] = "transaccion_id = ?"; $vals[] = $transaccionId;

        // Get transaction details
        $tx = $pdo->prepare("SELECT nombre, email, telefono, pedido, stripe_pi, modelo, color FROM transacciones WHERE id = ?");
        $tx->execute([$transaccionId]);
        $txData = $tx->fetch(PDO::FETCH_ASSOC);

        if ($txData) {
            if ($txData['stripe_pi']) { $sets[] = "stripe_pi = ?"; $vals[] = $txData['stripe_pi']; }
            if ($txData['nombre'])    { $sets[] = "cliente_nombre = ?"; $vals[] = $txData['nombre']; }
            if ($txData['email'])     { $sets[] = "cliente_email = ?"; $vals[] = $txData['email']; }
            if ($txData['telefono']) { $sets[] = "cliente_telefono = ?"; $vals[] = $txData['telefono']; }
            if ($txData['pedido'])   { $sets[] = "pedido_num = ?"; $vals[] = 'VK-' . $txData['pedido']; }
        }
    }

    if ($stripePi) {
        $sets[] = "stripe_pi = ?"; $vals[] = $stripePi;
    }

    if (empty($sets)) {
        echo json_encode(['ok' => false, 'error' => 'transaccion_id o stripe_pi requerido']);
        exit;
    }

    $vals[] = $motoId;
    $pdo->prepare("UPDATE inventario_motos SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);

    echo json_encode(['ok' => true, 'message' => 'Pago vinculado a la moto']);
    exit;
}

// ── IMPORT EXCEL (bulk) ──────────────────────────────────────────────────────
if ($accion === 'import_excel') {
    $motos = $json['motos'] ?? [];
    if (empty($motos)) {
        echo json_encode(['ok' => false, 'error' => 'No hay motos para importar']);
        exit;
    }

    $imported = 0;
    $errors = [];

    foreach ($motos as $i => $m) {
        $vin   = strtoupper(trim($m['vin'] ?? ''));
        $modelo = trim($m['modelo'] ?? '');
        $color  = trim($m['color'] ?? '');

        if (!$vin || !$modelo || !$color) {
            $errors[] = "Fila " . ($i+1) . ": VIN, modelo y color son requeridos";
            continue;
        }

        try {
            $vinDisplay = '****' . substr($vin, -4);
            $log = json_encode([[
                'estado' => 'por_llegar', 'accion' => 'import_excel',
                'dealer' => $dealer['nombre'], 'timestamp' => date('Y-m-d H:i:s'),
                'notas' => 'Importado desde Excel',
            ]], JSON_UNESCAPED_UNICODE);

            $pdo->prepare("
                INSERT INTO inventario_motos
                    (vin, vin_display, modelo, color, tipo_asignacion, estado,
                     dealer_id, punto_nombre, fecha_estado, log_estados,
                     anio_modelo, potencia, descripcion, accesorios,
                     num_pedimento, fecha_ingreso_pais, aduana, hecho_en, notas)
                VALUES (?,?,?,?,?,'por_llegar',?,?,NOW(),?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $vin, $vinDisplay, $modelo, $color,
                $m['tipo_asignacion'] ?? 'voltika_entrega',
                $dealer['id'], $dealer['punto_nombre'] ?? 'CEDIS',
                $log,
                $m['anio_modelo'] ?? '', $m['potencia'] ?? '',
                $m['descripcion'] ?? '', $m['accesorios'] ?? '',
                $m['num_pedimento'] ?? '', $m['fecha_ingreso_pais'] ?? null,
                $m['aduana'] ?? '', $m['hecho_en'] ?? 'China',
                $m['notas'] ?? 'Importado desde Excel',
            ]);
            $imported++;
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $errors[] = "Fila " . ($i+1) . ": VIN duplicado ($vin)";
            } else {
                $errors[] = "Fila " . ($i+1) . ": " . $e->getMessage();
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'imported' => $imported,
        'errors' => $errors,
        'message' => "$imported motos importadas" . (count($errors) ? ". " . count($errors) . " errores." : ""),
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Acción no reconocida']);
