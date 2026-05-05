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
