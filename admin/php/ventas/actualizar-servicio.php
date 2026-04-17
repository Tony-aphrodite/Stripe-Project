<?php
/**
 * POST — Update service tracking fields for a transaction.
 *
 * Body for tipo=placas:
 *   { id, tipo: 'placas', estado, gestor, telefono, nota }
 * Body for tipo=seguro:
 *   { id, tipo: 'seguro', estado, cotizacion, poliza, nota }
 *
 * Response: { ok, tx_id }
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d    = adminJsonIn();
$txId = (int)($d['id'] ?? 0);
$tipo = $d['tipo'] ?? '';

if (!$txId) adminJsonOut(['error' => 'id requerido'], 400);
if (!in_array($tipo, ['placas', 'seguro'], true)) {
    adminJsonOut(['error' => 'tipo inválido'], 400);
}

$pdo = getDB();

// Verify transaction exists + has the matching flag
$stmt = $pdo->prepare("SELECT id, asesoria_placas, seguro_qualitas FROM transacciones WHERE id=?");
$stmt->execute([$txId]);
$tx = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$tx) adminJsonOut(['error' => 'Transacción no encontrada'], 404);

if ($tipo === 'placas' && empty($tx['asesoria_placas'])) {
    adminJsonOut(['error' => 'El cliente no solicitó asesoría de placas para esta orden'], 400);
}
if ($tipo === 'seguro' && empty($tx['seguro_qualitas'])) {
    adminJsonOut(['error' => 'El cliente no solicitó seguro Quálitas para esta orden'], 400);
}

if ($tipo === 'placas') {
    $estado = (string)($d['estado'] ?? 'pendiente');
    if (!in_array($estado, ['pendiente','en_proceso','completado'], true)) {
        adminJsonOut(['error' => 'estado inválido'], 400);
    }
    $pdo->prepare("
        UPDATE transacciones SET
            placas_estado=?,
            placas_gestor_nombre=?,
            placas_gestor_telefono=?,
            placas_nota=?,
            servicios_fmod=NOW(),
            servicios_admin_uid=?
        WHERE id=?
    ")->execute([
        $estado,
        trim((string)($d['gestor']   ?? '')) ?: null,
        trim((string)($d['telefono'] ?? '')) ?: null,
        trim((string)($d['nota']     ?? '')) ?: null,
        $uid,
        $txId,
    ]);
    adminLog('servicio_placas_update', ['tx_id' => $txId, 'estado' => $estado]);
} else { // seguro
    $estado = (string)($d['estado'] ?? 'pendiente');
    if (!in_array($estado, ['pendiente','cotizado','activo'], true)) {
        adminJsonOut(['error' => 'estado inválido'], 400);
    }
    $cotiz = $d['cotizacion'] ?? '';
    $cotizVal = ($cotiz === '' || $cotiz === null) ? null : (float)$cotiz;
    $pdo->prepare("
        UPDATE transacciones SET
            seguro_estado=?,
            seguro_cotizacion=?,
            seguro_poliza=?,
            seguro_nota=?,
            servicios_fmod=NOW(),
            servicios_admin_uid=?
        WHERE id=?
    ")->execute([
        $estado,
        $cotizVal,
        trim((string)($d['poliza'] ?? '')) ?: null,
        trim((string)($d['nota']   ?? '')) ?: null,
        $uid,
        $txId,
    ]);
    adminLog('servicio_seguro_update', ['tx_id' => $txId, 'estado' => $estado]);
}

adminJsonOut(['ok' => true, 'tx_id' => $txId]);
