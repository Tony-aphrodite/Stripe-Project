<?php
/**
 * Diagnostic — verify why a user is getting 403 on endpoints they
 * should have access to per their permisos checkboxes.
 *
 * Logged-in user calls this from their browser; the JSON shows what
 * the server actually sees: their session role, permisos, and the
 * module-path detection regex result.
 *
 * Usage:
 *   https://voltika.mx/admin/php/diag-permisos.php
 *   (must be logged into /admin/ in the same tab)
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok'=>false, 'error'=>'php_fatal: '.$err['message'], 'file'=>basename($err['file']??''), 'line'=>$err['line']??0]);
    }
});

require_once __DIR__ . '/bootstrap.php';

// ── Toggle/set activo flag (token-gated) ─────────────────────────────
// When the diag earlier showed activo=0, login.php's
// "WHERE email=? AND activo=1" filter silently rejects with
// "Credenciales inválidas". Use this to reactivate without admin login.
//   ?key=voltika-diag-2026&action=activate&user_id=24
//   ?key=voltika-diag-2026&action=deactivate&user_id=24
if (($_GET['key'] ?? '') === 'voltika-diag-2026' && in_array($_GET['action'] ?? '', ['activate','deactivate'], true)) {
    $targetId = (int)($_GET['user_id'] ?? 0);
    $newVal   = ($_GET['action'] === 'activate') ? 1 : 0;
    if (!$targetId) {
        echo json_encode(['ok'=>false, 'error'=>'user_id required']);
        exit;
    }
    try {
        $pdo = getDB();
        $st = $pdo->prepare("UPDATE dealer_usuarios SET activo = ? WHERE id = ?");
        $st->execute([$newVal, $targetId]);
        $rows = $st->rowCount();
        $verify = $pdo->prepare("SELECT id, email, nombre, rol, activo FROM dealer_usuarios WHERE id = ? LIMIT 1");
        $verify->execute([$targetId]);
        echo json_encode([
            'ok'           => true,
            'action'       => $_GET['action'],
            'rows_updated' => $rows,
            'new_activo'   => $newVal,
            'verify'       => $verify->fetch(PDO::FETCH_ASSOC),
        ], JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
    }
    exit;
}

// ── Verify a password against the stored hash (token-gated) ─────────
// Confirms whether a given password matches what's currently in DB
// for that user. Useful to diagnose "still 401 after reset" cases.
//   ?key=voltika-diag-2026&action=verifypw&user_id=24&password=test
if (($_GET['key'] ?? '') === 'voltika-diag-2026' && ($_GET['action'] ?? '') === 'verifypw') {
    $targetId = (int)($_GET['user_id'] ?? 0);
    $tryPass  = (string)($_GET['password'] ?? '');
    if (!$targetId || $tryPass === '') {
        echo json_encode(['ok'=>false, 'error'=>'user_id y password requeridos']);
        exit;
    }
    try {
        $pdo = getDB();
        $st = $pdo->prepare("SELECT id, email, activo, password_hash FROM dealer_usuarios WHERE id = ? LIMIT 1");
        $st->execute([$targetId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['ok'=>false, 'error'=>'user not found']);
            exit;
        }
        $matches = password_verify($tryPass, (string)$row['password_hash']);
        echo json_encode([
            'ok'             => true,
            'user_id'        => $targetId,
            'email'          => $row['email'],
            'activo'         => $row['activo'],
            'password_match' => $matches,
            'hash_present'   => !empty($row['password_hash']),
            'hash_starts'    => substr((string)$row['password_hash'], 0, 7),
            'note'           => $matches ? 'Password is correct — login should succeed.' : 'Password does NOT match the stored hash.',
        ], JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
    }
    exit;
}

// ── Direct password reset (token-gated, no session needed) ───────────
// Bypasses admin auth so the dev can fix a locked-out user's password
// when the admin themselves can't log in. Usage:
//   ?key=voltika-diag-2026&action=resetpw&user_id=24&password=NUEVO_PASS
if (($_GET['key'] ?? '') === 'voltika-diag-2026' && ($_GET['action'] ?? '') === 'resetpw') {
    $targetId = (int)($_GET['user_id'] ?? 0);
    $newPass  = (string)($_GET['password'] ?? '');
    if (!$targetId || strlen($newPass) < 6) {
        echo json_encode(['ok'=>false, 'error'=>'user_id y password (>=6 chars) requeridos']);
        exit;
    }
    try {
        $pdo  = getDB();
        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $st   = $pdo->prepare("UPDATE dealer_usuarios SET password_hash = ? WHERE id = ?");
        $st->execute([$hash, $targetId]);
        $rows = $st->rowCount();
        $verify = $pdo->prepare("SELECT id, email, nombre, rol, activo FROM dealer_usuarios WHERE id = ? LIMIT 1");
        $verify->execute([$targetId]);
        echo json_encode([
            'ok'           => true,
            'action'       => 'resetpw',
            'rows_updated' => $rows,
            'verify'       => $verify->fetch(PDO::FETCH_ASSOC),
            'note'         => 'Password reset. User can now log in with the new password.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
    }
    exit;
}

// ── Direct WRITE action (bypasses session, gated by token) ────────────
// Customer brief 2026-05-04: roles/guardar.php is silently not
// persisting permisos for any user (every dealer_usuarios row has
// permisos=NULL). To unblock admins right now, allow directly setting
// a user's permisos via a token-protected endpoint. Usage:
//   ?key=voltika-diag-2026&action=set&user_id=24&perms=dashboard,envios,puntos,buscar,inventario,checklists
if (($_GET['key'] ?? '') === 'voltika-diag-2026' && ($_GET['action'] ?? '') === 'set') {
    $targetId = (int)($_GET['user_id'] ?? 0);
    $perms    = (string)($_GET['perms'] ?? '');
    if (!$targetId) {
        echo json_encode(['ok' => false, 'error' => 'user_id required']);
        exit;
    }
    $list = array_values(array_filter(array_map('trim', explode(',', $perms))));
    try {
        $pdo = getDB();
        $st = $pdo->prepare("UPDATE dealer_usuarios SET permisos = ? WHERE id = ?");
        $st->execute([json_encode($list), $targetId]);
        $rows = $st->rowCount();
        $verify = $pdo->prepare("SELECT id, email, rol, permisos FROM dealer_usuarios WHERE id = ? LIMIT 1");
        $verify->execute([$targetId]);
        echo json_encode([
            'ok'           => true,
            'action'       => 'set',
            'rows_updated' => $rows,
            'permisos_set' => $list,
            'verify'       => $verify->fetch(PDO::FETCH_ASSOC),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$uid       = $_SESSION['admin_user_id']   ?? null;
$rolSess   = $_SESSION['admin_user_rol']  ?? null;
$permSess  = $_SESSION['admin_user_permisos'] ?? null;

// Allow looking up a user by email or id WITHOUT being logged in, so
// we can diagnose any user from any browser. Pass ?email=daniel@... or
// ?user_id=N along with key=voltika-diag-2026 to bypass session.
$lookupUid = null;
if (($_GET['key'] ?? '') === 'voltika-diag-2026') {
    if (!empty($_GET['user_id']))   $lookupUid = (int)$_GET['user_id'];
    if (!empty($_GET['email']) && !$lookupUid) {
        try {
            $st = getDB()->prepare("SELECT id FROM dealer_usuarios WHERE email = ? LIMIT 1");
            $st->execute([$_GET['email']]);
            $lookupUid = (int)($st->fetchColumn() ?: 0);
        } catch (Throwable $e) {}
    }
}
$effectiveUid = $lookupUid ?: $uid;

// What's in the DB right now?
$dbRow = null;
$columnsExist = [];
$allUsers = [];
try {
    $pdo = getDB();
    $cols = $pdo->query("SHOW COLUMNS FROM dealer_usuarios")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['permisos','rol','activo','email'] as $c) {
        $columnsExist[$c] = in_array($c, $cols, true);
    }
    if ($effectiveUid) {
        $st = $pdo->prepare("SELECT id, email, nombre, rol, activo, permisos FROM dealer_usuarios WHERE id = ? LIMIT 1");
        $st->execute([$effectiveUid]);
        $dbRow = $st->fetch(PDO::FETCH_ASSOC);
    }
    // If diagnostics key is provided, also list all users so admin
    // can spot the right user_id quickly.
    if (($_GET['key'] ?? '') === 'voltika-diag-2026') {
        $allUsers = $pdo->query("SELECT id, email, nombre, rol, activo,
            CASE WHEN permisos IS NULL OR permisos = '' THEN 'EMPTY'
                 WHEN permisos = '[]'                    THEN 'EMPTY-ARRAY'
                 ELSE 'OK'
            END AS permisos_status,
            permisos
            FROM dealer_usuarios ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'stage'=>'db', 'error'=>$e->getMessage()]);
    exit;
}

// Module-path detection — sanity check the regex against various paths
$testPaths = [
    '/var/www/vhosts/voltika.mx/httpdocs/admin/php/dashboard/kpis.php',
    '/var/www/vhosts/voltika.mx/httpdocs/admin/php/inventario/listar.php',
    '/var/www/vhosts/voltika.mx/httpdocs/admin/php/envios/listar.php',
    $_SERVER['SCRIPT_FILENAME'] ?? '(no SCRIPT_FILENAME)',
];
$pathDetections = [];
foreach ($testPaths as $p) {
    $module = '';
    if (preg_match('#/admin/php/([^/]+)/[^/]+\.php$#', $p, $m)) {
        $module = $m[1];
    }
    $pathDetections[$p] = $module;
}

// Decoded permisos (parse the raw JSON the same way bootstrap does)
$permDecoded = null;
if ($dbRow && !empty($dbRow['permisos'])) {
    $permDecoded = json_decode((string)$dbRow['permisos'], true);
}

echo json_encode([
    'ok' => true,
    'session' => [
        'admin_user_id'       => $uid,
        'admin_user_rol'      => $rolSess,
        'admin_user_permisos' => $permSess,
    ],
    'lookup_uid_used' => $effectiveUid,
    'db' => [
        'columns_exist'    => $columnsExist,
        'row'              => $dbRow,
        'permisos_decoded' => $permDecoded,
    ],
    'all_users' => $allUsers,
    'adminLoadUserPermisos_result' => $effectiveUid ? adminLoadUserPermisos((int)$effectiveUid) : null,
    'path_detection_test' => $pathDetections,
    'php_version' => PHP_VERSION,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
