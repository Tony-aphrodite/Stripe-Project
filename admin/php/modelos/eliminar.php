<?php
/**
 * POST — Soft delete a model (set activo=0) with inventory check
 * Body: { "id": 123, "force": false }
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin']);

$d = adminJsonIn();
$id = (int)($d['id'] ?? 0);
$force = !empty($d['force']);
if (!$id) adminJsonOut(['error' => 'id requerido'], 400);

$pdo = getDB();

// Check if model exists
$stmt = $pdo->prepare("SELECT nombre FROM modelos WHERE id = ?");
$stmt->execute([$id]);
$modelo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$modelo) adminJsonOut(['error' => 'Modelo no encontrado'], 404);

// Check inventory
$stk = $pdo->prepare("SELECT COUNT(*) FROM inventario_motos WHERE modelo = ? AND estado NOT IN ('entregada','retenida')");
$stk->execute([$modelo['nombre']]);
$stockActivo = (int)$stk->fetchColumn();

if ($stockActivo > 0 && !$force) {
    adminJsonOut([
        'ok' => false,
        'warn' => true,
        'stock_activo' => $stockActivo,
        'message' => "Este modelo tiene $stockActivo unidades activas en inventario. ¿Desea eliminarlo de todas formas?",
    ]);
}

// Soft delete: deactivate
$pdo->prepare("UPDATE modelos SET activo = 0 WHERE id = ?")->execute([$id]);

adminLog('modelo_eliminar', ['id' => $id, 'nombre' => $modelo['nombre'], 'stock_activo' => $stockActivo, 'force' => $force]);
adminJsonOut(['ok' => true, 'id' => $id, 'nombre' => $modelo['nombre']]);
