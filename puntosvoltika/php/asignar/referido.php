<?php
/**
 * POST — Asignar moto del inventario del punto a una venta por referido
 * Body: { moto_id, cliente_nombre, cliente_email, cliente_telefono, precio, canal: 'directa'|'electronica' }
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$d = puntoJsonIn();
$motoId = (int)($d['moto_id'] ?? 0);
$canal  = $d['canal'] ?? 'directa';
if (!$motoId) puntoJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();

// Verify moto belongs to this point and is available
$stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE id=? AND punto_voltika_id=? AND estado IN ('recibida','lista_para_entrega')");
$stmt->execute([$motoId, $ctx['punto_id']]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) puntoJsonOut(['error' => 'Moto no disponible en este punto'], 404);
if (!empty($moto['cliente_nombre'])) puntoJsonOut(['error' => 'Moto ya asignada a otro cliente'], 400);

// Get point referral codes
$pStmt = $pdo->prepare("SELECT * FROM puntos_voltika WHERE id=?");
$pStmt->execute([$ctx['punto_id']]);
$punto = $pStmt->fetch(PDO::FETCH_ASSOC);
$codigoRef = $canal === 'electronica' ? $punto['codigo_electronico'] : $punto['codigo_venta'];

// Assign client to moto
$pdo->prepare("UPDATE inventario_motos SET cliente_nombre=?, cliente_email=?, cliente_telefono=?,
    precio_venta=?, tipo_asignacion='referido', dealer_id=?, estado='por_validar_entrega' WHERE id=?")
    ->execute([
        $d['cliente_nombre'] ?? '',
        $d['cliente_email'] ?? '',
        $d['cliente_telefono'] ?? '',
        (float)($d['precio'] ?? 0),
        $ctx['user_id'],
        $motoId
    ]);

// Log sale
$pdo->prepare("INSERT INTO ventas_log (moto_id, tipo, dealer_id, cliente_name, cliente_email, cliente_telefono,
    modelo, color, vin, monto, notas) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([
        $motoId, 'venta_punto', $ctx['user_id'],
        $d['cliente_nombre'] ?? '', $d['cliente_email'] ?? '', $d['cliente_telefono'] ?? '',
        $moto['modelo'], $moto['color'], $moto['vin'],
        (float)($d['precio'] ?? 0),
        "Canal: $canal · Código: $codigoRef"
    ]);

puntoLog('venta_referido', ['moto_id' => $motoId, 'canal' => $canal]);
puntoJsonOut(['ok' => true, 'codigo_referido' => $codigoRef]);
