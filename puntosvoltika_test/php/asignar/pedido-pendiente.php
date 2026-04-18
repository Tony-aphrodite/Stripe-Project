<?php
/**
 * POST — Link a pending CASE 3 order (referido_tipo='punto' in transacciones)
 * to a free motorcycle in this point's inventory.
 *
 * Body: { pedido, moto_id }  // pedido = transacciones.pedido (without VK- prefix)
 *
 * On success:
 *   - inventario_motos gets client data copied from transacciones
 *   - pedido_num set to "VK-<pedido>"
 *   - estado moves to 'lista_para_entrega' (ready for client pickup)
 *   - email + WhatsApp sent to client (voltikaNotify 'moto_en_punto')
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$d = puntoJsonIn();
$pedido = trim((string)($d['pedido'] ?? ''));
$motoId = (int)($d['moto_id'] ?? 0);
if (!$pedido || !$motoId) puntoJsonOut(['error' => 'pedido y moto_id requeridos'], 400);

$pdo = getDB();

// 1) Verify the order belongs to THIS punto (codigo_referido) and has no moto linked yet
$tStmt = $pdo->prepare("SELECT t.*, p.nombre AS punto_nombre, p.ciudad AS punto_ciudad, p.direccion AS punto_direccion
    FROM transacciones t
    LEFT JOIN puntos_voltika p ON p.id = t.referido_id
    WHERE t.pedido = ? AND t.referido_tipo = 'punto' AND t.referido_id = ? LIMIT 1");
$tStmt->execute([$pedido, $ctx['punto_id']]);
$orden = $tStmt->fetch(PDO::FETCH_ASSOC);
if (!$orden) puntoJsonOut(['error' => 'Pedido no encontrado o no corresponde a este punto'], 404);

// Check no real moto already linked
$already = $pdo->prepare("SELECT id FROM inventario_motos
    WHERE pedido_num = CONCAT('VK-', ?) AND vin NOT LIKE 'VK-%' LIMIT 1");
$already->execute([$pedido]);
if ($already->fetchColumn()) {
    puntoJsonOut(['error' => 'Este pedido ya tiene una moto asignada'], 409);
}

// 2) Verify the moto belongs to THIS punto and is free
$mStmt = $pdo->prepare("SELECT * FROM inventario_motos
    WHERE id = ? AND punto_voltika_id = ?
      AND estado IN ('recibida','lista_para_entrega')
      AND (cliente_nombre IS NULL OR cliente_nombre = '')
      AND (pedido_num IS NULL OR pedido_num = '')");
$mStmt->execute([$motoId, $ctx['punto_id']]);
$moto = $mStmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) puntoJsonOut(['error' => 'Moto no disponible (ya asignada o en estado incorrecto)'], 400);

// 3) Verify model + color match
if (strcasecmp(trim($moto['modelo'] ?? ''), trim($orden['modelo'] ?? '')) !== 0) {
    puntoJsonOut(['error' => 'Modelo no coincide: moto=' . $moto['modelo'] . ' vs pedido=' . $orden['modelo']], 400);
}
if (strcasecmp(trim($moto['color'] ?? ''), trim($orden['color'] ?? '')) !== 0) {
    puntoJsonOut(['error' => 'Color no coincide: moto=' . $moto['color'] . ' vs pedido=' . $orden['color']], 400);
}

// 4) Link the moto to the order
$pdo->prepare("UPDATE inventario_motos SET
        cliente_nombre   = ?,
        cliente_email    = ?,
        cliente_telefono = ?,
        pedido_num       = CONCAT('VK-', ?),
        estado           = 'lista_para_entrega',
        fecha_estado     = NOW(),
        fmod             = NOW(),
        dealer_id        = ?
    WHERE id = ?")
    ->execute([
        $orden['nombre']   ?? '',
        $orden['email']    ?? '',
        $orden['telefono'] ?? '',
        $pedido,
        $ctx['user_id'],
        $motoId,
    ]);

// Log sale
$pdo->prepare("INSERT INTO ventas_log (moto_id, tipo, dealer_id, cliente_nombre, cliente_email, cliente_telefono,
    pedido_num, modelo, color, vin, monto, notas) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([
        $motoId, 'asignada_pedido_pendiente', $ctx['user_id'],
        $orden['nombre'] ?? '', $orden['email'] ?? '', $orden['telefono'] ?? '',
        'VK-' . $pedido,
        $moto['modelo'], $moto['color'], $moto['vin'],
        (float)($orden['total'] ?? 0),
        'CASE 3 · Asignada por punto a pedido online'
    ]);

puntoLog('asignar_pedido_pendiente', ['pedido' => $pedido, 'moto_id' => $motoId]);

// 5) Notify client: moto is at the point and ready
if (!empty($orden['telefono']) || !empty($orden['email'])) {
    require_once __DIR__ . '/../../../configurador_prueba_test/php/voltika-notify.php';
    try {
        voltikaNotify('moto_en_punto', [
            'nombre'    => $orden['nombre']         ?? '',
            'modelo'    => $moto['modelo']          ?? '',
            'punto'     => $orden['punto_nombre']   ?? '',
            'ciudad'    => $orden['punto_ciudad']   ?? '',
            'direccion' => $orden['punto_direccion'] ?? '',
            'telefono'  => $orden['telefono']       ?? '',
            'email'     => $orden['email']          ?? '',
        ]);
    } catch (Throwable $e) { error_log('notify pedido-pendiente: ' . $e->getMessage()); }
}

puntoJsonOut([
    'ok'       => true,
    'pedido'   => 'VK-' . $pedido,
    'moto_id'  => $motoId,
    'vin'      => $moto['vin'],
    'cliente'  => $orden['nombre'] ?? '',
]);
