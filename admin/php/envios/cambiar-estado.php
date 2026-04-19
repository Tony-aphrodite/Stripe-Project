<?php
/**
 * POST — Change shipping status (lista_para_enviar → enviada → recibida)
 *
 * Side-effects per stage:
 *   'enviada'  → inventario_motos.estado='por_llegar' + notify moto_enviada
 *   'recibida' → inventario_motos.estado='recibida' + moto_recibida notification
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$envioId = (int)($d['envio_id'] ?? 0);
$nuevoEstado = $d['estado'] ?? '';
if (!$envioId || !in_array($nuevoEstado, ['enviada','recibida'])) {
    adminJsonOut(['error' => 'envio_id y estado válido requeridos'], 400);
}

$pdo = getDB();
$stmt = $pdo->prepare("SELECT e.*, m.cliente_id, m.cliente_nombre, m.cliente_telefono, m.cliente_email,
                              m.modelo, m.color, m.pedido_num,
                              pv.nombre AS punto_nombre, pv.ciudad AS punto_ciudad,
                              pv.direccion AS punto_direccion, pv.colonia AS punto_colonia,
                              pv.cp AS punto_cp, pv.lat AS punto_lat, pv.lng AS punto_lng,
                              pv.calle_numero AS punto_calle
    FROM envios e
    JOIN inventario_motos m ON m.id=e.moto_id
    LEFT JOIN puntos_voltika pv ON pv.id=e.punto_destino_id
    WHERE e.id=?");
$stmt->execute([$envioId]);
$envio = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$envio) adminJsonOut(['error' => 'Envío no encontrado'], 404);

// Resolve notify helper (test vs prod) — single include, reused across branches.
$_notifyPath = null;
foreach ([
    __DIR__ . '/../../../configurador_prueba_test/php/voltika-notify.php',
    __DIR__ . '/../../../configurador_prueba/php/voltika-notify.php',
] as $_p) {
    if (is_file($_p)) { $_notifyPath = $_p; break; }
}
if ($_notifyPath) { try { require_once $_notifyPath; } catch (Throwable $e) { error_log('notify include: ' . $e->getMessage()); } }

// Compose shared notification payload (direccion_punto, link_maps, fecha_*,
// pedido). Reused for both moto_enviada and moto_recibida.
$pedido = $envio['pedido_num'] ?? '';
if ($pedido && str_starts_with($pedido, 'VK-')) $pedido = substr($pedido, 3);

// Resolve the short customer-facing code from transacciones via pedido_num.
$pedidoCorto = '';
try {
    $txQ = $pdo->prepare("SELECT id FROM transacciones WHERE pedido=? ORDER BY id DESC LIMIT 1");
    $txQ->execute([$pedido]);
    $txId = (int)($txQ->fetchColumn() ?: 0);
    if ($txId && function_exists('voltikaResolvePedidoCorto')) {
        $pedidoCorto = voltikaResolvePedidoCorto($pdo, $txId);
    }
} catch (Throwable $e) {}
if (!$pedidoCorto) $pedidoCorto = 'VK-' . $pedido;
$direccionPunto = trim(($envio['punto_direccion'] ?? '')
    . ($envio['punto_colonia'] ? ', ' . $envio['punto_colonia'] : '')
    . ($envio['punto_cp']      ? ' CP ' . $envio['punto_cp']   : ''));
if (!$direccionPunto) $direccionPunto = $envio['punto_calle'] ?? '';
$linkMaps = function_exists('voltikaBuildMapsLink')
    ? voltikaBuildMapsLink($direccionPunto, $envio['punto_ciudad'] ?? '',
        isset($envio['punto_lat']) ? (float)$envio['punto_lat'] : null,
        isset($envio['punto_lng']) ? (float)$envio['punto_lng'] : null)
    : 'https://voltika.mx/clientes/';
$fechaHuman = function_exists('voltikaFormatFechaHuman')
    ? voltikaFormatFechaHuman($envio['fecha_estimada_llegada'] ?? null)
    : ($envio['fecha_estimada_llegada'] ?: 'próximamente');

$notifyData = [
    'cliente_id'          => $envio['cliente_id'] ?? null,
    'nombre'              => $envio['cliente_nombre'] ?? '',
    'pedido'              => $pedido,
    'pedido_corto'        => $pedidoCorto,
    'modelo'              => $envio['modelo'] ?? '',
    'color'               => $envio['color']  ?? '',
    'punto'               => $envio['punto_nombre']  ?? '',
    'ciudad'              => $envio['punto_ciudad']  ?? '',
    'direccion_punto'     => $direccionPunto,
    'link_maps'           => $linkMaps,
    'fecha'               => $fechaHuman,
    'fecha_llegada_punto' => $fechaHuman,
    'telefono'            => $envio['cliente_telefono'] ?? '',
    'email'               => $envio['cliente_email']    ?? '',
];

$updates = ['estado' => $nuevoEstado];
if ($nuevoEstado === 'enviada') {
    $updates['fecha_envio'] = date('Y-m-d');
    $pdo->prepare("UPDATE inventario_motos SET estado='por_llegar' WHERE id=?")->execute([$envio['moto_id']]);
    if (function_exists('voltikaNotify') && ($envio['cliente_telefono'] || $envio['cliente_email'])) {
        try { voltikaNotify('moto_enviada', $notifyData); }
        catch (Throwable $e) { error_log('notify moto_enviada: ' . $e->getMessage()); }
    }
}

if ($nuevoEstado === 'recibida') {
    // When the punto confirms the bike has arrived, flip the inventario state
    // so the checklist/entrega flows can pick it up.
    $pdo->prepare("UPDATE inventario_motos SET estado='recibida', fecha_estado=NOW() WHERE id=?")
       ->execute([$envio['moto_id']]);
    if (function_exists('voltikaNotify') && ($envio['cliente_telefono'] || $envio['cliente_email'])) {
        try { voltikaNotify('moto_recibida', $notifyData); }
        catch (Throwable $e) { error_log('notify moto_recibida: ' . $e->getMessage()); }
    }
}

$sets = []; $params = [];
foreach ($updates as $k => $v) { $sets[] = "$k=?"; $params[] = $v; }
$params[] = $envioId;
$pdo->prepare("UPDATE envios SET " . implode(',', $sets) . " WHERE id=?")->execute($params);

adminLog('envio_estado', ['envio_id' => $envioId, 'estado' => $nuevoEstado]);
adminJsonOut(['ok' => true]);
