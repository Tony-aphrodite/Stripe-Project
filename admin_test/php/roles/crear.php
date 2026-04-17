<?php
/**
 * POST — Create a new dealer_usuarios user (punto or admin)
 * Body: { nombre, email, password, rol, punto_id?, notificar?(bool) }
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$d = adminJsonIn();
$nombre = trim($d['nombre'] ?? '');
$email  = strtolower(trim($d['email'] ?? ''));
$pass   = $d['password'] ?? '';
$rol    = trim($d['rol'] ?? 'dealer');
$puntoId = $d['punto_id'] ?? null;
$notificar = !empty($d['notificar']);

if (!$nombre) adminJsonOut(['error' => 'Nombre requerido'], 400);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) adminJsonOut(['error' => 'Email inválido'], 400);
if (strlen($pass) < 6) adminJsonOut(['error' => 'Contraseña debe tener al menos 6 caracteres'], 400);

$validRoles = ['admin','dealer','cedis','operador','cobranza','documentos','logistica'];
if (!in_array($rol, $validRoles, true)) adminJsonOut(['error' => 'Rol no válido'], 400);

$pdo = getDB();

// Check email uniqueness
$exists = $pdo->prepare("SELECT id FROM dealer_usuarios WHERE email=? LIMIT 1");
$exists->execute([$email]);
if ($exists->fetch()) adminJsonOut(['error' => 'Ya existe un usuario con ese email'], 409);

// Lookup punto info if assigned
$puntoNombre = null;
$puntoTel = null;
if ($puntoId) {
    $p = $pdo->prepare("SELECT id, nombre, telefono FROM puntos_voltika WHERE id=? LIMIT 1");
    $p->execute([(int)$puntoId]);
    $pRow = $p->fetch(PDO::FETCH_ASSOC);
    if (!$pRow) adminJsonOut(['error' => 'Punto no encontrado'], 404);
    $puntoNombre = $pRow['nombre'];
    $puntoTel = $pRow['telefono'] ?? null;
}

$hash = password_hash($pass, PASSWORD_DEFAULT);

$pdo->prepare("INSERT INTO dealer_usuarios
    (nombre, email, password_hash, rol, punto_id, punto_nombre, activo)
    VALUES (?,?,?,?,?,?,1)")
    ->execute([$nombre, $email, $hash, $rol, $puntoId ?: null, $puntoNombre]);

$userId = (int)$pdo->lastInsertId();

adminLog('usuario_creado', [
    'usuario_id' => $userId,
    'email'      => $email,
    'rol'        => $rol,
    'punto_id'   => $puntoId,
]);

// Send credentials to the new user
$notifyResult = null;
if ($notificar) {
    $notifyPath = realpath(__DIR__ . '/../../../configurador_prueba/php/voltika-notify.php');
    if (!$notifyPath) $notifyPath = realpath(__DIR__ . '/../../../configurador_prueba_test/php/voltika-notify.php');
    if ($notifyPath && file_exists($notifyPath)) {
        require_once $notifyPath;
        try {
            $portalUrl = ($rol === 'dealer') ? 'voltika.mx/puntosvoltika' : 'voltika.mx/admin';
            voltikaNotify('credenciales_punto', [
                'nombre'   => $nombre,
                'email'    => $email,
                'password' => $pass,
                'rol'      => $rol,
                'punto'    => $puntoNombre ?: '—',
                'url'      => $portalUrl,
                'telefono' => $puntoTel,
            ]);
            $notifyResult = 'enviado';
        } catch (Throwable $e) {
            error_log('credenciales_punto notify: ' . $e->getMessage());
            $notifyResult = 'error: ' . $e->getMessage();
        }
    } else {
        $notifyResult = 'voltika-notify.php no disponible';
    }
}

adminJsonOut([
    'ok'         => true,
    'usuario_id' => $userId,
    'email'      => $email,
    'notify'     => $notifyResult,
]);
