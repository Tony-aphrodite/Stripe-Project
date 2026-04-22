<?php
/**
 * POST — Toggle enable/disable a user account
 * Body: { usuario_id, activo?: 0|1 }
 * If `activo` is omitted, current state is read and flipped.
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$d = adminJsonIn();
$usuarioId = (int)($d['usuario_id'] ?? 0);

if (!$usuarioId) adminJsonOut(['error' => 'usuario_id requerido'], 400);

$pdo = getDB();

if (array_key_exists('activo', $d)) {
    $activo = !empty($d['activo']) ? 1 : 0;
} else {
    $cur = $pdo->prepare("SELECT activo FROM dealer_usuarios WHERE id=? LIMIT 1");
    $cur->execute([$usuarioId]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$row) adminJsonOut(['error' => 'Usuario no encontrado'], 404);
    $activo = ((int)$row['activo'] === 1) ? 0 : 1;
}

$pdo->prepare("UPDATE dealer_usuarios SET activo=? WHERE id=?")->execute([$activo, $usuarioId]);

adminLog('usuario_activo_toggle', ['usuario_id' => $usuarioId, 'activo' => $activo]);
adminJsonOut(['ok' => true, 'activo' => $activo]);
