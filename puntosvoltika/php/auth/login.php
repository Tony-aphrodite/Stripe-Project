<?php
require_once __DIR__ . '/../bootstrap.php';

$d = puntoJsonIn();
$email = trim($d['email'] ?? '');
$pass  = $d['password'] ?? '';
if (!$email || !$pass) {
    puntoJsonOut(['error' => 'Email y contraseña requeridos'], 400);
}

$pdo = getDB();
// Allow both dealer and admin roles — admin can access any punto for supervision
$stmt = $pdo->prepare("SELECT * FROM dealer_usuarios
    WHERE email=? AND activo=1 AND rol IN ('dealer','admin') LIMIT 1");
$stmt->execute([$email]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$u || !password_verify($pass, $u['password_hash'])) {
    puntoJsonOut(['error' => 'Credenciales inválidas'], 401);
}

$_SESSION['punto_user_id']     = (int)$u['id'];
$_SESSION['punto_user_nombre'] = $u['nombre'];
$_SESSION['punto_user_rol']    = $u['rol'];
$_SESSION['punto_id']          = (int)($u['punto_id'] ?: 0);
$_SESSION['punto_nombre']      = $u['punto_nombre'] ?? '';

// Load point details if user is bound to a specific punto
$p = null;
if (!empty($u['punto_id'])) {
    try {
        $pStmt = $pdo->prepare("SELECT * FROM puntos_voltika WHERE id=? OR codigo_venta=? OR codigo_electronico=? LIMIT 1");
        $pStmt->execute([$u['punto_id'], $u['punto_id'], $u['punto_id']]);
        $p = $pStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { $p = null; }
}

// Admin without bound punto: let them pick or default to first
if (!$p && $u['rol'] === 'admin') {
    try {
        $p = $pdo->query("SELECT * FROM puntos_voltika WHERE activo=1 ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($p) {
            $_SESSION['punto_id']     = (int)$p['id'];
            $_SESSION['punto_nombre'] = $p['nombre'];
        }
    } catch (Throwable $e) {}
}

puntoLog('login', ['email' => $email, 'rol' => $u['rol']]);
puntoJsonOut([
    'ok' => true,
    'usuario' => [
        'id' => (int)$u['id'],
        'nombre' => $u['nombre'],
        'rol' => $u['rol'],
        'punto_nombre' => $u['punto_nombre'] ?? ($p['nombre'] ?? null),
    ],
    'punto' => $p,
]);
