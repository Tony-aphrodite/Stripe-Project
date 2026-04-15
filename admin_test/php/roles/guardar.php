<?php
/**
 * POST — Update a user's role and permissions
 * Body: { "usuario_id": 1, "rol": "cobranza", "permisos": ["dashboard","pagos","cobranza"] }
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin']);

$d = adminJsonIn();
$usuarioId = (int)($d['usuario_id'] ?? 0);
$rol = trim($d['rol'] ?? '');
$permisos = $d['permisos'] ?? [];

if (!$usuarioId) adminJsonOut(['error' => 'usuario_id requerido'], 400);
if (!$rol) adminJsonOut(['error' => 'rol requerido'], 400);

$validRoles = ['admin','dealer','cedis','operador','cobranza','documentos','logistica'];
if (!in_array($rol, $validRoles)) adminJsonOut(['error' => 'Rol no válido'], 400);

$pdo = getDB();

$pdo->prepare("UPDATE dealer_usuarios SET rol=?, permisos=? WHERE id=?")
    ->execute([$rol, json_encode($permisos), $usuarioId]);

adminLog('rol_actualizado', ['usuario_id' => $usuarioId, 'rol' => $rol, 'permisos' => $permisos]);
adminJsonOut(['ok' => true, 'usuario_id' => $usuarioId, 'rol' => $rol]);
