<?php
/**
 * POST — Enable/disable a user account
 * Body: { usuario_id, activo: 0|1 }
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$d = adminJsonIn();
$usuarioId = (int)($d['usuario_id'] ?? 0);
$activo    = !empty($d['activo']) ? 1 : 0;

if (!$usuarioId) adminJsonOut(['error' => 'usuario_id requerido'], 400);

$pdo = getDB();
$pdo->prepare("UPDATE dealer_usuarios SET activo=? WHERE id=?")->execute([$activo, $usuarioId]);

adminLog('usuario_activo_toggle', ['usuario_id' => $usuarioId, 'activo' => $activo]);
adminJsonOut(['ok' => true]);
