<?php
/**
 * POST — Change password for logged-in user
 * Body: { "currentPassword": "old", "newPassword": "new123" }
 */
require_once __DIR__ . '/../bootstrap.php';

$userId = adminRequireAuth();

$d = adminJsonIn();
$current = $d['currentPassword'] ?? '';
$newPass = $d['newPassword'] ?? '';

if (!$current || !$newPass) adminJsonOut(['error' => 'Contraseña actual y nueva requeridas'], 400);
if (strlen($newPass) < 6) adminJsonOut(['error' => 'La nueva contraseña debe tener al menos 6 caracteres'], 400);

$pdo = getDB();
$stmt = $pdo->prepare("SELECT password_hash FROM dealer_usuarios WHERE id = ? AND activo = 1 LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) adminJsonOut(['error' => 'Usuario no encontrado'], 404);
if (!password_verify($current, $user['password_hash'])) {
    adminJsonOut(['error' => 'Contraseña actual incorrecta'], 401);
}

$hash = password_hash($newPass, PASSWORD_DEFAULT);
$pdo->prepare("UPDATE dealer_usuarios SET password_hash = ? WHERE id = ?")->execute([$hash, $userId]);

adminLog('password_change', ['usuario_id' => $userId, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
adminJsonOut(['ok' => true, 'message' => 'Contraseña actualizada correctamente.']);
