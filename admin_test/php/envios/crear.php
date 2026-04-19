<?php
/**
 * POST — Manually create a shipment
 * Body: { moto_id, punto_id, tracking_number?, carrier?, fecha_estimada?, transaccion_id?, notas? }
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../skydropx.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$motoId         = (int)($d['moto_id'] ?? 0);
$puntoId        = (int)($d['punto_id'] ?? 0);
$trackingNumber = trim($d['tracking_number'] ?? '');
$carrier        = trim($d['carrier'] ?? '');
$fechaEstimada  = $d['fecha_estimada'] ?? null;
$transaccionId  = (int)($d['transaccion_id'] ?? 0);
$notas          = trim($d['notas'] ?? '');
// Type of assignment per dashboards_diagrams.pdf diagram 5. Only meaningful
// for shipments WITHOUT a linked order — the point staff chooses whether the
// moto is for 'showroom' sale or 'entrega' (for-delivery stock pool).
// Accepted values: 'showroom' | 'entrega'. Defaults to 'entrega' when an order
// is linked (transaccion_id > 0), because an order-linked shipment is always
// for delivery.
$envioTipo = strtolower(trim($d['envio_tipo'] ?? ''));
if ($transaccionId) {
    $envioTipo = 'entrega';
} elseif (!in_array($envioTipo, ['showroom', 'entrega'], true)) {
    adminJsonOut(['error' => 'envio_tipo requerido para envío sin orden (showroom|entrega)'], 400);
}

if (!$puntoId) adminJsonOut(['error' => 'punto_id requerido'], 400);

$pdo = getDB();

// Order-linked flow: derive moto_id server-side from the transaction's
// already-linked real moto (assigned in Ventas via asignar-moto.php). Client
// doesn't send moto_id; we trust only the DB link.
if ($transaccionId && !$motoId) {
    $motoStmt = $pdo->prepare("
        SELECT m.id
          FROM transacciones t
          JOIN inventario_motos m
            ON m.activo = 1
           AND m.vin NOT REGEXP '^VK-[A-Z0-9]+-[0-9]+-[a-f0-9]+'
           AND (
                 (m.stripe_pi = t.stripe_pi AND m.stripe_pi <> '')
              OR  m.pedido_num = CONCAT('VK-', t.pedido)
           )
         WHERE t.id = ?
         LIMIT 1
    ");
    $motoStmt->execute([$transaccionId]);
    $motoId = (int)($motoStmt->fetchColumn() ?: 0);
    if (!$motoId) {
        adminJsonOut(['error' => 'La orden no tiene una moto asignada. Asigna una moto desde Ventas primero.'], 409);
    }
}

if (!$motoId) adminJsonOut(['error' => 'moto_id requerido'], 400);

// Ensure envios.envio_tipo column exists (idempotent migration)
try {
    $cols = $pdo->query("SHOW COLUMNS FROM envios")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('envio_tipo', $cols, true)) {
        $pdo->exec("ALTER TABLE envios ADD COLUMN envio_tipo ENUM('showroom','entrega') NULL AFTER estado");
    }
} catch (Throwable $e) { error_log('envios ensure envio_tipo: ' . $e->getMessage()); }

// Verify moto
$stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE id=? AND activo=1");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) adminJsonOut(['error' => 'Moto no encontrada'], 404);

// Verify punto
$pStmt = $pdo->prepare("SELECT * FROM puntos_voltika WHERE id=? AND activo=1");
$pStmt->execute([$puntoId]);
$punto = $pStmt->fetch(PDO::FETCH_ASSOC);
if (!$punto) adminJsonOut(['error' => 'Punto no encontrado'], 404);

// If transaccion_id provided, link order to bike
if ($transaccionId) {
    $tStmt = $pdo->prepare("SELECT * FROM transacciones WHERE id=? LIMIT 1");
    $tStmt->execute([$transaccionId]);
    $order = $tStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) adminJsonOut(['error' => 'Orden no encontrada'], 404);

    $pedidoNum = 'VK-' . $order['pedido'];

    // Deactivate any placeholder moto (VK-MOD-xxx-timestamp-hash) that
    // confirmar-orden.php created when the order was placed. Without this
    // cleanup the real moto + placeholder would both carry the same
    // pedido_num, breaking downstream queries.
    try {
        $pdo->prepare("UPDATE inventario_motos SET activo=0, fmod=NOW()
            WHERE vin REGEXP '^VK-[A-Z0-9]+-[0-9]+-[a-f0-9]+'
              AND (pedido_num = ? OR (stripe_pi = ? AND stripe_pi <> ''))
              AND id <> ?")
           ->execute([$pedidoNum, $order['stripe_pi'] ?? '', $motoId]);
    } catch (Throwable $e) { error_log('envios placeholder cleanup: ' . $e->getMessage()); }

    if (empty($moto['pedido_num']) && empty($moto['cliente_email'])) {
        $pdo->prepare("UPDATE inventario_motos SET
            cliente_nombre=?, cliente_email=?, cliente_telefono=?,
            pedido_num=?, stripe_pi=?, pago_estado='pagada', fmod=NOW()
            WHERE id=?")->execute([
            $order['nombre'] ?? '', $order['email'] ?? '', $order['telefono'] ?? '',
            $pedidoNum, $order['stripe_pi'] ?? '', $motoId
        ]);
    }
    if (!$notas) $notas = 'Venta - Pedido ' . $pedidoNum;
}

// Auto-quote via Skydropx if no date provided
if (!$fechaEstimada) {
    $cpOrigen  = defined('CEDIS_CP') ? CEDIS_CP : '';
    $cpDestino = $punto['cp'] ?? '';
    if ($cpOrigen && $cpDestino) {
        $quote = skydropxCotizar($cpOrigen, $cpDestino);
        if (!empty($quote['ok'])) $fechaEstimada = $quote['fecha_estimada'];
    }
}

// Create envio
$ins = $pdo->prepare("INSERT INTO envios
    (moto_id, punto_destino_id, estado, envio_tipo, fecha_estimada_llegada, enviado_por, notas, tracking_number, carrier)
    VALUES (?,?,'lista_para_enviar',?,?,?,?,?,?)");
$ins->execute([$motoId, $puntoId, $envioTipo, $fechaEstimada, $uid, $notas, $trackingNumber ?: null, $carrier ?: null]);
$envioId = (int)$pdo->lastInsertId();

// Update moto — per diagram 5, also set tipo_asignacion to match the shipment.
// showroom → 'consignacion' (stays in the point's showroom stock pool)
// entrega  → 'voltika_entrega' (reserved for delivery, waits for CEDIS assignment)
$tipoAsignacion = ($envioTipo === 'showroom') ? 'consignacion' : 'voltika_entrega';
$pdo->prepare("UPDATE inventario_motos SET punto_voltika_id=?, estado='por_llegar',
    tipo_asignacion=?,
    fecha_estado=NOW(),
    log_estados=JSON_ARRAY_APPEND(COALESCE(log_estados,'[]'), '$', JSON_OBJECT('estado','por_llegar','fecha',NOW(),'usuario',?,'envio_tipo',?))
    WHERE id=?")->execute([$puntoId, $tipoAsignacion, $uid, $envioTipo, $motoId]);

adminLog('envio_crear', [
    'envio_id' => $envioId, 'moto_id' => $motoId,
    'punto_id' => $puntoId, 'tracking' => $trackingNumber,
]);

// Per dashboards_diagrams.pdf CASE 1/3 step: "when the shipping information
// is created for an order, the client will receive a notification informing
// the shipping to the point of his moto, and the estimate arrive date".
// Only fires for order-linked shipments — stock shipments have no client yet.
if ($transaccionId && !empty($order)) {
    $clienteTel   = $order['telefono'] ?? '';
    $clienteEmail = $order['email']    ?? '';
    if ($clienteTel || $clienteEmail) {
        // Resolve notify helper path — test env uses _test suffix, prod uses
        // the unsuffixed folder. file_exists() avoids fatal require_once.
        $notifyPath = null;
        foreach ([
            __DIR__ . '/../../../configurador_prueba_test/php/voltika-notify.php',
            __DIR__ . '/../../../configurador_prueba/php/voltika-notify.php',
        ] as $_p) {
            if (is_file($_p)) { $notifyPath = $_p; break; }
        }
        if ($notifyPath) { try { require_once $notifyPath; } catch (Throwable $e) { error_log('notify include: ' . $e->getMessage()); } }
        if (function_exists('voltikaNotify')) {
            try {
                $fechaHuman = $fechaEstimada;
                if ($fechaEstimada) {
                    try {
                        $meses = ['enero','febrero','marzo','abril','mayo','junio',
                                  'julio','agosto','septiembre','octubre','noviembre','diciembre'];
                        $dt = new DateTime($fechaEstimada);
                        $fechaHuman = $dt->format('j') . ' de ' . $meses[(int)$dt->format('n') - 1] . ' de ' . $dt->format('Y');
                    } catch (Throwable $e) {}
                }
                voltikaNotify('moto_enviada', [
                    'cliente_id' => $moto['cliente_id'] ?? null,
                    'nombre'     => $order['nombre']    ?? '',
                    'modelo'     => $moto['modelo']     ?? ($order['modelo'] ?? ''),
                    'punto'      => $punto['nombre']    ?? '',
                    'ciudad'     => $punto['ciudad']    ?? '',
                    'fecha'      => $fechaHuman         ?? '',
                    'telefono'   => $clienteTel,
                    'email'      => $clienteEmail,
                ]);
            } catch (Throwable $e) { error_log('notify moto_enviada: ' . $e->getMessage()); }
        } else {
            error_log('envios crear: voltikaNotify no disponible, se omite notificación');
        }
    }
}

adminJsonOut(['ok' => true, 'envio_id' => $envioId, 'fecha_estimada' => $fechaEstimada]);
