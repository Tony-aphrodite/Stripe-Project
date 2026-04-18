<?php
/**
 * POST — Delete a gestor de placas (hard delete).
 * Body: { id }
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$d  = adminJsonIn();
$id = (int)($d['id'] ?? 0);
if (!$id) adminJsonOut(['error' => 'id requerido'], 400);

$pdo = getDB();
$pdo->prepare("DELETE FROM gestores_placas WHERE id = ?")->execute([$id]);
adminLog('gestor_placas_delete', ['id' => $id]);

adminJsonOut(['ok' => true]);
