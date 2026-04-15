<?php
/**
 * POST — Delete pricing conditions for a model
 * Body: { "modelo_id": 123 }
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin']);

$d = adminJsonIn();
$modeloId = (int)($d['modelo_id'] ?? 0);
if (!$modeloId) adminJsonOut(['error' => 'modelo_id requerido'], 400);

$pdo = getDB();

$stmt = $pdo->prepare("SELECT modelo_id FROM precios_condiciones WHERE modelo_id = ?");
$stmt->execute([$modeloId]);
if (!$stmt->fetch()) {
    adminJsonOut(['error' => 'Condiciones no encontradas'], 404);
}

$pdo->prepare("DELETE FROM precios_condiciones WHERE modelo_id = ?")->execute([$modeloId]);

adminLog('precio_eliminado', ['modelo_id' => $modeloId]);
adminJsonOut(['ok' => true, 'modelo_id' => $modeloId]);
