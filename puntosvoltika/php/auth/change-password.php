<?php
/**
 * POST — Change password for the logged-in punto user
 * Body: { "currentPassword": "old", "newPassword": "new123" }
 */
require_once __DIR__ . '/../bootstrap.php';

$ctx = puntoRequireAuth();
$userId = $ctx['user_id'];

$d = puntoJsonIn();
$current = $d['currentPassword'] ?? '';
$newPass = $d['newPassword'] ?? '';

if (!$current || !$newPass) puntoJsonOut(['error' => 'Contraseña actual y nueva requeridas'], 400);
if (strlen($newPass) < 6)  puntoJsonOut(['error' => 'La nueva contraseña debe tener al menos 6 caracteres'], 400);

$pdo = getDB();
$stmt = $pdo->prepare("SELECT password_hash FROM dealer_usuarios WHERE id = ? AND activo = 1 LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) puntoJsonOut(['error' => 'Usuario no encontrado'], 404);
if (!password_verify($current, $user['password_hash'])) {
    puntoJsonOut(['error' => 'Contraseña actual incorrecta'], 401);
}

$hash = password_hash($newPass, PASSWORD_DEFAULT);
$pdo->prepare("UPDATE dealer_usuarios SET password_hash = ? WHERE id = ?")->execute([$hash, $userId]);

puntoLog('password_change', ['usuario_id' => $userId]);
puntoJsonOut(['ok' => true, 'message' => 'Contraseña actualizada correctamente.']);
