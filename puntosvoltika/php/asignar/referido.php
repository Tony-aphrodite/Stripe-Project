<?php
/**
 * POST — CASE 4: sale from the point's own showroom inventory using its CODIGO REFERIDO.
 *
 * Body: { moto_id, cliente_nombre, cliente_email, cliente_telefono, precio,
 *         canal: 'directa'|'electronica' }
 *
 * Per dashboards_diagrams.pdf this is CASE 4 (showroom sale). The moto is already
 * physically at the punto (inventario_venta); the point simply hands it to the client
 * and we record the sale. For CASE 3 (general sale via online configurador), see
 * configurador_prueba/php/confirmar-orden.php.
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$d = puntoJsonIn();
$motoId       = (int)($d['moto_id'] ?? 0);
$canal        = $d['canal'] ?? 'directa';
$pedidoModelo = trim($d['pedido_modelo'] ?? '');
$pedidoColor  = trim($d['pedido_color'] ?? '');
if (!$motoId) puntoJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();

// CASE 4 constraints per dashboards_diagrams.pdf:
//   1) Moto must physically be at this point (punto_voltika_id matches)
//   2) Moto must be showroom stock (tipo_asignacion = 'consignacion')
//   3) Moto must be FREE (no cliente already assigned)
//   4) Moto must be in a sellable state ('recibida' or 'lista_para_entrega')
//   5) If an order is provided, modelo + color must match
$stmt = $pdo->prepare("SELECT * FROM inventario_motos
    WHERE id=? AND punto_voltika_id=?
      AND tipo_asignacion='consignacion'
      AND estado IN ('recibida','lista_para_entrega')");
$stmt->execute([$motoId, $ctx['punto_id']]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) {
    puntoJsonOut([
        'error' => 'Moto no disponible en showroom de este punto. Debe estar en consignación y en estado recibida/lista_para_entrega.'
    ], 404);
}
if (!empty($moto['cliente_nombre']) || !empty($moto['cliente_id'])) {
    puntoJsonOut(['error' => 'Moto ya asignada a otro cliente (no está libre)'], 400);
}

// Enforce model/color match against the order (normalize case-insensitively)
if ($pedidoModelo !== '' && strcasecmp(trim($moto['modelo'] ?? ''), $pedidoModelo) !== 0) {
    puntoJsonOut([
        'error' => 'El modelo de la moto (' . ($moto['modelo'] ?? '?') . ') no coincide con el pedido (' . $pedidoModelo . ')'
    ], 400);
}
if ($pedidoColor !== '' && strcasecmp(trim($moto['color'] ?? ''), $pedidoColor) !== 0) {
    puntoJsonOut([
        'error' => 'El color de la moto (' . ($moto['color'] ?? '?') . ') no coincide con el pedido (' . $pedidoColor . ')'
    ], 400);
}

// Get point referral codes
$pStmt = $pdo->prepare("SELECT * FROM puntos_voltika WHERE id=?");
$pStmt->execute([$ctx['punto_id']]);
$punto = $pStmt->fetch(PDO::FETCH_ASSOC);
$codigoRef = $canal === 'electronica' ? $punto['codigo_electronico'] : $punto['codigo_venta'];

// CASE 4 — mark as consignacion (showroom stock) and flag for delivery validation
$pdo->prepare("UPDATE inventario_motos SET cliente_nombre=?, cliente_email=?, cliente_telefono=?,
    precio_venta=?, tipo_asignacion='consignacion', dealer_id=?, estado='por_validar_entrega' WHERE id=?")
    ->execute([
        $d['cliente_nombre'] ?? '',
        $d['cliente_email'] ?? '',
        $d['cliente_telefono'] ?? '',
        (float)($d['precio'] ?? 0),
        $ctx['user_id'],
        $motoId
    ]);

// Log sale — tipo='venta_showroom' distinguishes CASE 4 from online referral sales (CASE 3)
$pdo->prepare("INSERT INTO ventas_log (moto_id, tipo, dealer_id, cliente_nombre, cliente_email, cliente_telefono,
    modelo, color, vin, monto, notas) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([
        $motoId, 'venta_showroom', $ctx['user_id'],
        $d['cliente_nombre'] ?? '', $d['cliente_email'] ?? '', $d['cliente_telefono'] ?? '',
        $moto['modelo'], $moto['color'], $moto['vin'],
        (float)($d['precio'] ?? 0),
        "CASE 4 · Canal: $canal · Código: $codigoRef"
    ]);

puntoLog('venta_showroom', ['moto_id' => $motoId, 'canal' => $canal, 'caso' => 4]);

// Per dashboards_diagrams.pdf CASE 4: notify the client that their moto is
// available for pickup at the point. The bike is physically in the showroom,
// so there's no shipping step — the client just needs the point info.
if (!empty($d['cliente_telefono']) || !empty($d['cliente_email'])) {
    require_once __DIR__ . '/../../../configurador_prueba/php/voltika-notify.php';
    try {
        voltikaNotify('punto_asignado', [
            'nombre'    => $d['cliente_nombre'] ?? '',
            'modelo'    => $moto['modelo']      ?? '',
            'punto'     => $punto['nombre']     ?? '',
            'ciudad'    => $punto['ciudad']     ?? '',
            'direccion' => $punto['direccion']  ?? '',
            'telefono'  => $d['cliente_telefono'] ?? '',
            'email'     => $d['cliente_email']    ?? '',
        ]);
    } catch (Throwable $e) { error_log('notify CASE 4 punto_asignado: ' . $e->getMessage()); }
}

puntoJsonOut(['ok' => true, 'caso' => 4, 'codigo_referido' => $codigoRef]);
