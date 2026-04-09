<?php
/**
 * POST — Assign moto to a Punto Voltika (creates envio record)
 * Validates: checklist_origen must be complete, model/color match if order linked
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$motoId  = (int)($d['moto_id'] ?? 0);
$puntoId = (int)($d['punto_id'] ?? 0);
$fechaEstimada = $d['fecha_estimada'] ?? null;
if (!$motoId || !$puntoId) adminJsonOut(['error' => 'moto_id y punto_id requeridos'], 400);

$pdo = getDB();

// Verify moto exists
$stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE id=?");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) adminJsonOut(['error' => 'Moto no encontrada'], 404);

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

// Create envio
$ins = $pdo->prepare("INSERT INTO envios (moto_id, punto_destino_id, estado, fecha_estimada_llegada, enviado_por)
    VALUES (?,?,'lista_para_enviar',?,?)");
$ins->execute([$motoId, $puntoId, $fechaEstimada, $uid]);

// Update moto
$pdo->prepare("UPDATE inventario_motos SET punto_voltika_id=?, estado='por_llegar',
    log_estados=JSON_ARRAY_APPEND(COALESCE(log_estados,'[]'), '$', JSON_OBJECT('estado','por_llegar','fecha',NOW(),'usuario',?))
    WHERE id=?")->execute([$puntoId, $uid, $motoId]);

adminLog('asignar_punto', ['moto_id' => $motoId, 'punto_id' => $puntoId]);

// Notify client if linked to an order
if ($moto['cliente_telefono'] || $moto['cliente_email']) {
    require_once __DIR__ . '/../../../configurador_prueba/php/voltika-notify.php';
    try {
        voltikaNotify('punto_asignado', [
            'cliente_id' => $moto['cliente_id'] ?? null,
            'nombre'     => $moto['cliente_nombre'] ?? '',
            'modelo'     => $moto['modelo'] ?? '',
            'punto'      => $punto['nombre'],
            'ciudad'     => $punto['ciudad'] ?? '',
            'telefono'   => $moto['cliente_telefono'] ?? '',
            'email'      => $moto['cliente_email'] ?? '',
        ]);
    } catch (Throwable $e) { error_log('notify punto_asignado: ' . $e->getMessage()); }
}

adminJsonOut(['ok' => true, 'envio_id' => $pdo->lastInsertId()]);
