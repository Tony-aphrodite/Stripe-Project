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

if (!$motoId || !$puntoId) adminJsonOut(['error' => 'moto_id y punto_id requeridos'], 400);

$pdo = getDB();

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

    if (empty($moto['pedido_num']) && empty($moto['cliente_email'])) {
        $pedidoNum = 'VK-' . $order['pedido'];
        $pdo->prepare("UPDATE inventario_motos SET
            cliente_nombre=?, cliente_email=?, cliente_telefono=?,
            pedido_num=?, stripe_pi=?, pago_estado='pagada', fmod=NOW()
            WHERE id=?")->execute([
            $order['nombre'] ?? '', $order['email'] ?? '', $order['telefono'] ?? '',
            $pedidoNum, $order['stripe_pi'] ?? '', $motoId
        ]);
    }
    if (!$notas) $notas = 'Venta - Pedido VK-' . $order['pedido'];
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
    (moto_id, punto_destino_id, estado, fecha_estimada_llegada, enviado_por, notas, tracking_number, carrier)
    VALUES (?,?,'lista_para_enviar',?,?,?,?,?)");
$ins->execute([$motoId, $puntoId, $fechaEstimada, $uid, $notas, $trackingNumber ?: null, $carrier ?: null]);
$envioId = (int)$pdo->lastInsertId();

// Update moto
$pdo->prepare("UPDATE inventario_motos SET punto_voltika_id=?, estado='por_llegar',
    fecha_estado=NOW(),
    log_estados=JSON_ARRAY_APPEND(COALESCE(log_estados,'[]'), '$', JSON_OBJECT('estado','por_llegar','fecha',NOW(),'usuario',?))
    WHERE id=?")->execute([$puntoId, $uid, $motoId]);

adminLog('envio_crear', [
    'envio_id' => $envioId, 'moto_id' => $motoId,
    'punto_id' => $puntoId, 'tracking' => $trackingNumber,
]);

adminJsonOut(['ok' => true, 'envio_id' => $envioId, 'fecha_estimada' => $fechaEstimada]);
