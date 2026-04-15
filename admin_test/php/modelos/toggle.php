<?php
/**
 * POST — Toggle model active/inactive
 * Body: { "id": 123 }
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin']);

$d = adminJsonIn();
$id = (int)($d['id'] ?? 0);
if (!$id) adminJsonOut(['error' => 'id requerido'], 400);

$pdo = getDB();
$pdo->prepare("UPDATE modelos SET activo = NOT activo WHERE id=?")->execute([$id]);

$nuevo = $pdo->prepare("SELECT activo FROM modelos WHERE id=?");
$nuevo->execute([$id]);
$activo = (int)$nuevo->fetchColumn();

adminLog('modelo_toggle', ['id' => $id, 'activo' => $activo]);
adminJsonOut(['ok' => true, 'id' => $id, 'activo' => $activo]);
