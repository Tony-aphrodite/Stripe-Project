<?php
/**
 * POST JSON — Delete the quotation file attached to a transaction.
 *
 * Body: { transaccion_id, tipo: 'seguro'|'placas' }
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d    = adminJsonIn();
$txId = (int)($d['transaccion_id'] ?? 0);
$tipo = $d['tipo'] ?? '';
if (!$txId) adminJsonOut(['error' => 'transaccion_id requerido'], 400);
if (!in_array($tipo, ['seguro','placas'], true)) adminJsonOut(['error' => 'tipo inválido'], 400);

$col = $tipo . '_cotizacion_';
$pdo = getDB();
$stmt = $pdo->prepare("SELECT {$col}archivo AS archivo FROM transacciones WHERE id=?");
$stmt->execute([$txId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) adminJsonOut(['error' => 'Transacción no encontrada'], 404);

if (!empty($row['archivo'])) {
    $abs = dirname(__DIR__, 2) . '/' . ltrim($row['archivo'], '/');
    if (is_file($abs)) @unlink($abs);
}

$pdo->prepare("UPDATE transacciones SET
        {$col}archivo=NULL, {$col}mime=NULL, {$col}size=NULL, {$col}subido=NULL,
        servicios_fmod=NOW(), servicios_admin_uid=?
    WHERE id=?")
   ->execute([$uid, $txId]);

adminLog('cotizacion_eliminada', ['tx_id' => $txId, 'tipo' => $tipo]);
adminJsonOut(['ok' => true]);
