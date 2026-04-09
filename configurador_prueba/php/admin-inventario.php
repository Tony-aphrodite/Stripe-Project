<?php
/**
 * Voltika Admin - Inventario CRUD
 * GET  ?punto_id=X&estado=X&modelo=X  → lista de motos
 * POST { accion: 'agregar', ... }      → crear moto
 * POST { accion: 'actualizar', ... }   → editar moto
 * POST { accion: 'eliminar', moto_id } → soft delete
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-auth.php';

$dealer = requireDealerAuth();

$pdo = getDB();

// ── GET - Lista ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $where  = ['m.activo = 1'];
    $params = [];

    // Admin ve todo; dealer solo ve su punto
    if ($dealer['rol'] !== 'admin') {
        $where[]  = '(m.dealer_id = ? OR m.dealer_id IS NULL)';
        $params[] = $dealer['id'];
    }

    if (!empty($_GET['punto_id'])) {
        $where[]  = 'm.punto_id = ?';
        $params[] = $_GET['punto_id'];
    }
    if (!empty($_GET['estado']) && $_GET['estado'] !== 'todos') {
        $where[]  = 'm.estado = ?';
        $params[] = $_GET['estado'];
    }
    if (!empty($_GET['tipo'])) {
        $where[]  = 'm.tipo_asignacion = ?';
        $params[] = $_GET['tipo'];
    }
    if (!empty($_GET['modelo'])) {
        $where[]  = 'm.modelo LIKE ?';
        $params[] = '%' . $_GET['modelo'] . '%';
    }
    if (!empty($_GET['q'])) {
        $q = '%' . $_GET['q'] . '%';
        $where[]  = '(m.vin LIKE ? OR m.cliente_nombre LIKE ? OR m.pedido_num LIKE ?)';
        $params[] = $q; $params[] = $q; $params[] = $q;
    }

    $whereStr = implode(' AND ', $where);
    $stmt = $pdo->prepare("
        SELECT m.id, m.vin, m.vin_display, m.modelo, m.color, m.tipo_asignacion,
               m.estado, m.dealer_id, m.punto_nombre, m.punto_id,
               m.cliente_nombre, m.cliente_email, m.cliente_telefono,
               m.pedido_num, m.pago_estado, m.dias_en_paso,
               m.fecha_llegada, m.fecha_estado, m.precio_venta, m.notas,
               m.log_estados, m.freg,
               m.anio_modelo, m.potencia, m.descripcion, m.accesorios,
               m.num_pedimento, m.fecha_ingreso_pais, m.aduana, m.hecho_en,
               d.nombre AS dealer_nombre
        FROM inventario_motos m
        LEFT JOIN dealer_usuarios d ON d.id = m.dealer_id
        WHERE $whereStr
        ORDER BY m.freg DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['log_estados'] = $r['log_estados'] ? json_decode($r['log_estados'], true) : [];
        $r['id']         = (int)$r['id'];
        $r['dias_en_paso'] = (int)$r['dias_en_paso'];
    }
    unset($r);

    // Stats totals — same dealer filter as list
    $stats = [];
    if ($dealer['rol'] === 'admin') {
        $stmtStats = $pdo->query("SELECT estado, COUNT(*) AS cnt FROM inventario_motos WHERE activo=1 GROUP BY estado");
    } else {
        $stmtStats = $pdo->prepare("SELECT estado, COUNT(*) AS cnt FROM inventario_motos WHERE activo=1 AND (dealer_id = ? OR dealer_id IS NULL) GROUP BY estado");
        $stmtStats->execute([$dealer['id']]);
    }
    foreach ($stmtStats->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $stats[$s['estado']] = (int)$s['cnt'];
    }

    echo json_encode(['ok' => true, 'motos' => $rows, 'stats' => $stats], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST ──────────────────────────────────────────────────────────────────────
$json   = json_decode(file_get_contents('php://input'), true) ?? [];
$accion = $json['accion'] ?? 'agregar';

// ── AGREGAR ───────────────────────────────────────────────────────────────────
if ($accion === 'agregar') {
    $vin             = strtoupper(trim($json['vin']             ?? ''));
    $modelo          = trim($json['modelo']          ?? '');
    $color           = trim($json['color']           ?? '');
    $tipoAsignacion  = $json['tipo_asignacion']      ?? 'voltika_entrega';
    $estado          = $json['estado']               ?? 'por_llegar';
    $clienteNombre   = trim($json['cliente_nombre']  ?? '');
    $clienteEmail    = trim($json['cliente_email']   ?? '');
    $clienteTelefono = trim($json['cliente_telefono']?? '');
    $pedidoNum       = trim($json['pedido_num']      ?? '');
    $pagoEstado      = $json['pago_estado']          ?? 'pagada';
    $precioVenta     = floatval($json['precio_venta']?? 0);
    $notas           = trim($json['notas']           ?? '');
    $fechaLlegada    = $json['fecha_llegada']        ?? null;
    $anioModelo      = trim($json['anio_modelo']     ?? '');
    $potencia        = trim($json['potencia']        ?? '');
    $descripcion     = trim($json['descripcion']     ?? '');
    $accesorios      = trim($json['accesorios']      ?? '');
    $numPedimento    = trim($json['num_pedimento']   ?? '');
    $fechaIngresoPais= $json['fecha_ingreso_pais']   ?? null;
    $aduana          = trim($json['aduana']           ?? '');
    $hechoEn         = trim($json['hecho_en']        ?? 'China');

    // Override dealer_id / punto for non-admin
    $dealerId    = ($dealer['rol'] === 'admin' && !empty($json['dealer_id']))
                    ? intval($json['dealer_id'])
                    : $dealer['id'];
    $puntoNombre = ($dealer['rol'] === 'admin' && !empty($json['punto_nombre']))
                    ? $json['punto_nombre']
                    : $dealer['punto_nombre'];
    $puntoId     = ($dealer['rol'] === 'admin' && !empty($json['punto_id']))
                    ? $json['punto_id']
                    : $dealer['punto_id'];

    if (!$vin || !$modelo || !$color) {
        http_response_code(400);
        echo json_encode(['error' => 'VIN, modelo y color son requeridos']);
        exit;
    }

    // VIN display: mask all but last 4
    $vinDisplay = '****' . substr($vin, -4);

    // Initial log
    $log = [[
        'estado'    => $estado,
        'accion'    => 'registro',
        'dealer'    => $dealer['nombre'],
        'timestamp' => date('Y-m-d H:i:s'),
        'notas'     => 'Moto registrada en inventario',
    ]];

    try {
        $pdo->prepare("
            INSERT INTO inventario_motos
                (vin, vin_display, modelo, color, tipo_asignacion, estado,
                 dealer_id, punto_nombre, punto_id, cliente_nombre, cliente_email,
                 cliente_telefono, pedido_num, pago_estado, precio_venta, notas,
                 fecha_llegada, fecha_estado, log_estados,
                 anio_modelo, potencia, descripcion, accesorios,
                 num_pedimento, fecha_ingreso_pais, aduana, hecho_en)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,?,?,?,?,?,?,?)
        ")->execute([
            $vin, $vinDisplay, $modelo, $color, $tipoAsignacion, $estado,
            $dealerId, $puntoNombre, $puntoId,
            $clienteNombre, $clienteEmail, $clienteTelefono,
            $pedidoNum, $pagoEstado, $precioVenta, $notas,
            $fechaLlegada,
            json_encode($log, JSON_UNESCAPED_UNICODE),
            $anioModelo, $potencia, $descripcion, $accesorios,
            $numPedimento, $fechaIngresoPais, $aduana, $hechoEn
        ]);

        $newId = $pdo->lastInsertId();

        // Register in ventas_log
        $pdo->prepare("
            INSERT INTO ventas_log (moto_id, tipo, dealer_id, cliente_nombre, cliente_email,
                                    cliente_telefono, pedido_num, modelo, color, vin, notas)
            VALUES (?, 'reserva', ?, ?, ?, ?, ?, ?, ?, ?, 'Moto registrada en inventario')
        ")->execute([
            $newId, $dealerId,
            $clienteNombre, $clienteEmail, $clienteTelefono,
            $pedidoNum, $modelo, $color, $vin
        ]);

        echo json_encode(['ok' => true, 'moto_id' => (int)$newId]);

    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            http_response_code(409);
            echo json_encode(['error' => 'VIN duplicado: ya existe una moto con ese VIN']);
        } else {
            error_log('Voltika inventario agregar error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno']);
        }
    }
    exit;
}

// ── ACTUALIZAR ────────────────────────────────────────────────────────────────
if ($accion === 'actualizar') {
    $motoId = intval($json['moto_id'] ?? 0);
    if (!$motoId) { http_response_code(400); echo json_encode(['error' => 'moto_id requerido']); exit; }

    $allowed = ['modelo','color','tipo_asignacion','cliente_nombre','cliente_email',
                'cliente_telefono','pedido_num','pago_estado','precio_venta','notas',
                'fecha_llegada','anio_modelo','potencia','descripcion','accesorios',
                'num_pedimento','fecha_ingreso_pais','aduana','hecho_en'];
    $sets = []; $vals = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $json)) {
            $sets[] = "$f = ?";
            $vals[] = $json[$f];
        }
    }
    if (empty($sets)) { http_response_code(400); echo json_encode(['error' => 'Sin cambios']); exit; }

    $vals[] = $motoId;
    $vals[] = $dealer['id'];
    $cond   = $dealer['rol'] === 'admin' ? 'id = ?' : 'id = ? AND (dealer_id = ? OR dealer_id IS NULL)';
    if ($dealer['rol'] === 'admin') array_pop($vals);

    try {
        $pdo->prepare("UPDATE inventario_motos SET " . implode(', ', $sets) . " WHERE $cond")->execute($vals);
        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error interno']);
    }
    exit;
}

// ── ELIMINAR (soft) ───────────────────────────────────────────────────────────
if ($accion === 'eliminar') {
    $motoId = intval($json['moto_id'] ?? 0);
    if (!$motoId) { http_response_code(400); echo json_encode(['error' => 'moto_id requerido']); exit; }

    $cond   = $dealer['rol'] === 'admin' ? 'id = ?' : 'id = ? AND dealer_id = ?';
    $params = $dealer['rol'] === 'admin' ? [$motoId] : [$motoId, $dealer['id']];

    try {
        $pdo->prepare("UPDATE inventario_motos SET activo = 0 WHERE $cond")->execute($params);
        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error interno']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Acción no reconocida']);
