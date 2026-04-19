<?php
/**
 * POST — Assign moto to a Punto Voltika (creates envio record)
 *
 * Two modes:
 *   tipo=inventario  → Send bike to punto for in-store sales (no order link)
 *   tipo=venta       → Link bike to a specific purchase order + assign punto
 *
 * Body: { moto_id, punto_id, tipo, transaccion_id?, fecha_estimada? }
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../skydropx.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$motoId         = (int)($d['moto_id'] ?? 0);
$puntoId        = (int)($d['punto_id'] ?? 0);
$tipo           = $d['tipo'] ?? 'inventario';  // inventario | venta
$transaccionId  = (int)($d['transaccion_id'] ?? 0);
$fechaEstimada  = $d['fecha_estimada'] ?? null;

if (!$motoId || !$puntoId) adminJsonOut(['error' => 'moto_id y punto_id requeridos'], 400);
if ($tipo === 'venta' && !$transaccionId) adminJsonOut(['error' => 'transaccion_id requerido para tipo venta'], 400);

$pdo = getDB();

// Verify moto exists
$stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE id=? AND activo=1");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) adminJsonOut(['error' => 'Moto no encontrada'], 404);

// Rule: locked motos cannot be assigned
if (!empty($moto['bloqueado_venta'])) {
    adminJsonOut(['error' => 'Esta moto está bloqueada. Motivo: ' . ($moto['bloqueado_motivo'] ?? 'Sin motivo') . '. Desbloquéala primero.'], 403);
}

// Rule: checklist_origen must be complete
$co = $pdo->prepare("SELECT completado FROM checklist_origen WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$co->execute([$motoId]);
$coRow = $co->fetch(PDO::FETCH_ASSOC);
if (!$coRow || !$coRow['completado']) {
    adminJsonOut(['error' => 'El checklist de origen no está completo. No se puede asignar.'], 400);
}

// Verify punto exists
$pStmt = $pdo->prepare("SELECT * FROM puntos_voltika WHERE id=? AND activo=1");
$pStmt->execute([$puntoId]);
$punto = $pStmt->fetch(PDO::FETCH_ASSOC);
if (!$punto) adminJsonOut(['error' => 'Punto Voltika no encontrado o inactivo'], 404);

// ── If tipo=venta, link the order to the bike ────────────────────────────
$order = null;
if ($tipo === 'venta') {
    $stmt = $pdo->prepare("SELECT * FROM transacciones WHERE id=? LIMIT 1");
    $stmt->execute([$transaccionId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) adminJsonOut(['error' => 'Orden no encontrada'], 404);

    // Check bike is not already assigned to another order
    if (!empty($moto['pedido_num']) || !empty($moto['cliente_email'])) {
        adminJsonOut(['error' => 'Esta moto ya está asignada a otra orden (pedido: ' . ($moto['pedido_num'] ?: 'N/A') . ')'], 409);
    }

    // Link order data to bike
    $pedidoNum = 'VK-' . $order['pedido'];
    $tpago = strtolower(trim($order['tpago'] ?? ''));
    $pagoEstado = in_array($tpago, ['credito', 'enganche', 'parcial'], true)
        ? 'parcial'
        : 'pagada';

    $pdo->prepare("
        UPDATE inventario_motos SET
            cliente_nombre   = ?,
            cliente_email    = ?,
            cliente_telefono = ?,
            pedido_num       = ?,
            stripe_pi        = ?,
            pago_estado      = ?,
            fmod             = NOW()
        WHERE id = ?
    ")->execute([
        $order['nombre']   ?? '',
        $order['email']    ?? '',
        $order['telefono'] ?? '',
        $pedidoNum,
        $order['stripe_pi'] ?? '',
        $pagoEstado,
        $motoId,
    ]);
}

// ── Auto-quote delivery date via Skydropx ────────────────────────────────
if (!$fechaEstimada) {
    $cpOrigen  = defined('CEDIS_CP') ? CEDIS_CP : '';
    $cpDestino = $punto['cp'] ?? '';
    if ($cpOrigen && $cpDestino) {
        $quote = skydropxCotizar($cpOrigen, $cpDestino);
        if (!empty($quote['ok'])) {
            $fechaEstimada = $quote['fecha_estimada'];
        }
    }
}

// ── Create envio record ──────────────────────────────────────────────────
$ins = $pdo->prepare("INSERT INTO envios (moto_id, punto_destino_id, estado, fecha_estimada_llegada, enviado_por, notas)
    VALUES (?,?,'lista_para_enviar',?,?,?)");
$ins->execute([
    $motoId,
    $puntoId,
    $fechaEstimada,
    $uid,
    $tipo === 'venta' ? ('Venta - Pedido ' . ($pedidoNum ?? '')) : 'Inventario para venta en punto',
]);

// ── Update moto state ────────────────────────────────────────────────────
$pdo->prepare("UPDATE inventario_motos SET punto_voltika_id=?, estado='por_llegar',
    fecha_estado=NOW(),
    log_estados=JSON_ARRAY_APPEND(COALESCE(log_estados,'[]'), '$', JSON_OBJECT('estado','por_llegar','fecha',NOW(),'usuario',?,'tipo',?))
    WHERE id=?")->execute([$puntoId, $uid, $tipo, $motoId]);

adminLog('asignar_punto', [
    'moto_id'        => $motoId,
    'punto_id'       => $puntoId,
    'tipo'           => $tipo,
    'transaccion_id' => $transaccionId ?: null,
    'fecha_estimada' => $fechaEstimada,
]);

// ── Notify client ────────────────────────────────────────────────────────
$clienteTel   = $order['telefono']      ?? $moto['cliente_telefono'] ?? '';
$clienteEmail = $order['email']         ?? $moto['cliente_email']    ?? '';
$clienteNombre = $order['nombre']       ?? $moto['cliente_nombre']   ?? '';

if ($clienteTel || $clienteEmail) {
    // Resolve notify helper path (test vs prod)
    $_notifyPath = null;
    foreach ([
        __DIR__ . '/../../../configurador_prueba_test/php/voltika-notify.php',
        __DIR__ . '/../../../configurador_prueba/php/voltika-notify.php',
    ] as $_p) {
        if (is_file($_p)) { $_notifyPath = $_p; break; }
    }
    if ($_notifyPath) { try { require_once $_notifyPath; } catch (Throwable $e) { error_log('notify include: ' . $e->getMessage()); } }

    if (function_exists('voltikaNotify')) {
        try {
            $direccionPunto = trim(($punto['direccion'] ?? '')
                . ($punto['colonia'] ? ', ' . $punto['colonia'] : '')
                . ($punto['cp']      ? ' CP ' . $punto['cp']   : ''));
            if (!$direccionPunto) $direccionPunto = $punto['calle_numero'] ?? '';
            $linkMaps = function_exists('voltikaBuildMapsLink')
                ? voltikaBuildMapsLink($direccionPunto, $punto['ciudad'] ?? '',
                    isset($punto['lat']) ? (float)$punto['lat'] : null,
                    isset($punto['lng']) ? (float)$punto['lng'] : null)
                : 'https://voltika.mx/clientes/';
            $fechaHuman = function_exists('voltikaFormatFechaHuman')
                ? voltikaFormatFechaHuman($fechaEstimada)
                : (string)$fechaEstimada;
            $pedido = $order['pedido'] ?? ($moto['pedido_num'] ?? '');
            if ($pedido && str_starts_with($pedido, 'VK-')) $pedido = substr($pedido, 3);
            $pedidoCorto = (function_exists('voltikaResolvePedidoCorto') && !empty($order['id']))
                ? voltikaResolvePedidoCorto($pdo, (int)$order['id'])
                : 'VK-' . $pedido;

            voltikaNotify('punto_asignado', [
                'cliente_id'      => $moto['cliente_id'] ?? null,
                'nombre'          => $clienteNombre,
                'pedido'          => $pedido,
                'pedido_corto'    => $pedidoCorto,
                'modelo'          => $moto['modelo'] ?? ($order['modelo'] ?? ''),
                'color'           => $moto['color']  ?? ($order['color']  ?? ''),
                'punto'           => $punto['nombre'],
                'ciudad'          => $punto['ciudad'] ?? '',
                'direccion_punto' => $direccionPunto,
                'link_maps'       => $linkMaps,
                'fecha'           => $fechaHuman, // legacy alias
                'fecha_estimada'  => $fechaHuman,
                'telefono'        => $clienteTel,
                'email'           => $clienteEmail,
            ]);
        } catch (Throwable $e) { error_log('notify punto_asignado: ' . $e->getMessage()); }
    }
}

adminJsonOut([
    'ok'              => true,
    'envio_id'        => (int)$pdo->lastInsertId(),
    'tipo'            => $tipo,
    'fecha_estimada'  => $fechaEstimada,
]);
