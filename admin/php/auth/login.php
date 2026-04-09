<?php
require_once __DIR__ . '/../bootstrap.php';
$d = adminJsonIn();
$email = trim($d['email'] ?? '');
$pass  = $d['password'] ?? '';
if (!$email || !$pass) adminJsonOut(['error' => 'Email y contraseña requeridos'], 400);

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM dealer_usuarios WHERE email=? AND activo=1 LIMIT 1");
$stmt->execute([$email]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$u || !password_verify($pass, $u['password_hash'])) {
    adminJsonOut(['error' => 'Credenciales inválidas'], 401);
}
$_SESSION['admin_user_id']  = (int)$u['id'];
$_SESSION['admin_user_rol'] = $u['rol'];
$_SESSION['admin_user_nombre'] = $u['nombre'];
$_SESSION['admin_punto_id'] = $u['punto_id'];
adminLog('login', ['email' => $email]);
adminJsonOut(['ok' => true, 'usuario' => [
    'id' => $u['id'], 'nombre' => $u['nombre'], 'rol' => $u['rol'], 'punto_nombre' => $u['punto_nombre']
]]);
