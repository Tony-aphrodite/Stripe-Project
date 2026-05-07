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

// Customer brief 2026-05-07 (Cesar.pedraza incident): users with a
// punto/dealer role were logging in here and seeing an empty admin
// dashboard because none of the admin queries match their permission
// scope. The two panels share the dealer_usuarios table but each one
// expects its own role set:
//   - /admin/         : admin, cedis, operador, logistica  (back-office)
//   - /configurador/dealer-panel.html : dealer, punto       (point of sale)
// Reject the login here with a redirect hint so the operator lands
// on the right tool the first time. The credentials are valid; we
// just refuse to seat them in the wrong panel.
$rolBruto = strtolower(trim((string)($u['rol'] ?? '')));
$rolesPunto = ['dealer', 'punto', 'punto_voltika', 'punto-voltika', 'pos'];
if (in_array($rolBruto, $rolesPunto, true)) {
    adminLog('login_rechazado_rol_punto', ['email' => $email, 'rol' => $rolBruto]);
    adminJsonOut([
        'error'    => 'Esta cuenta es para Puntos Voltika. Inicia sesión en el panel del punto.',
        'redirect' => '/configurador/dealer-panel.html',
        'rol'      => $rolBruto,
    ], 403);
}

$_SESSION['admin_user_id']  = (int)$u['id'];
$_SESSION['admin_user_rol'] = $u['rol'];
$_SESSION['admin_user_nombre'] = $u['nombre'];
$_SESSION['admin_punto_id'] = $u['punto_id'];
// Customer brief 2026-05-04: seed per-user permisos into the session so
// adminRequireAuth's module-permission fallback works without an extra
// DB hit. JSON-decoded into an array of module names.
$_perm = [];
if (!empty($u['permisos'])) {
    $_decoded = json_decode((string)$u['permisos'], true);
    if (is_array($_decoded)) $_perm = array_values(array_filter(array_map('strval', $_decoded)));
}
$_SESSION['admin_user_permisos'] = $_perm;
adminLog('login', ['email' => $email]);
adminJsonOut(['ok' => true, 'usuario' => [
    'id'           => $u['id'],
    'nombre'       => $u['nombre'],
    'rol'          => $u['rol'],
    'punto_nombre' => $u['punto_nombre'],
    // Frontend uses this to filter the sidebar (customer brief
    // 2026-05-04 round 7): "make only the allowed modules visible".
    'permisos'     => $_perm,
]]);
