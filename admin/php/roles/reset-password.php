<?php
/**
 * POST — Reset a user's password and optionally notify them
 * Body: { usuario_id, password, notificar?(bool) }
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$d = adminJsonIn();
$usuarioId = (int)($d['usuario_id'] ?? 0);
$pass      = $d['password'] ?? '';
$notificar = !empty($d['notificar']);

if (!$usuarioId) adminJsonOut(['error' => 'usuario_id requerido'], 400);
if (strlen($pass) < 6) adminJsonOut(['error' => 'Contraseña debe tener al menos 6 caracteres'], 400);

$pdo = getDB();

$u = $pdo->prepare("SELECT u.*, p.telefono AS punto_tel FROM dealer_usuarios u
    LEFT JOIN puntos_voltika p ON p.id = u.punto_id
    WHERE u.id=? LIMIT 1");
$u->execute([$usuarioId]);
$user = $u->fetch(PDO::FETCH_ASSOC);
if (!$user) adminJsonOut(['error' => 'Usuario no encontrado'], 404);

$hash = password_hash($pass, PASSWORD_DEFAULT);
$pdo->prepare("UPDATE dealer_usuarios SET password_hash=? WHERE id=?")
    ->execute([$hash, $usuarioId]);

adminLog('usuario_password_reset', ['usuario_id' => $usuarioId, 'email' => $user['email']]);

$notifyResult = null;
if ($notificar) {
    $notifyPath = realpath(__DIR__ . '/../../../configurador_prueba/php/voltika-notify.php');
    if (!$notifyPath) $notifyPath = realpath(__DIR__ . '/../../../configurador_prueba_test/php/voltika-notify.php');
    if ($notifyPath && file_exists($notifyPath)) {
        require_once $notifyPath;
        try {
            $portalUrl = ($user['rol'] === 'dealer') ? 'voltika.mx/puntosvoltika' : 'voltika.mx/admin';
            voltikaNotify('credenciales_punto', [
                'nombre'   => $user['nombre'],
                'email'    => $user['email'],
                'password' => $pass,
                'rol'      => $user['rol'],
                'punto'    => $user['punto_nombre'] ?: '—',
                'url'      => $portalUrl,
                'telefono' => $user['punto_tel'],
            ]);
            $notifyResult = 'enviado';
        } catch (Throwable $e) {
            error_log('reset-password notify: ' . $e->getMessage());
            $notifyResult = 'error: ' . $e->getMessage();
        }
    }
}

adminJsonOut(['ok' => true, 'notify' => $notifyResult]);
