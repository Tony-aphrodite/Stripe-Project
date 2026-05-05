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

$uid       = $_SESSION['admin_user_id']   ?? null;
$rolSess   = $_SESSION['admin_user_rol']  ?? null;
$permSess  = $_SESSION['admin_user_permisos'] ?? null;

// What's in the DB right now?
$dbRow = null;
$columnsExist = [];
try {
    $pdo = getDB();
    $cols = $pdo->query("SHOW COLUMNS FROM dealer_usuarios")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['permisos','rol','activo','email'] as $c) {
        $columnsExist[$c] = in_array($c, $cols, true);
    }
    if ($uid) {
        $st = $pdo->prepare("SELECT id, email, rol, activo, permisos FROM dealer_usuarios WHERE id = ? LIMIT 1");
        $st->execute([$uid]);
        $dbRow = $st->fetch(PDO::FETCH_ASSOC);
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
    'db' => [
        'columns_exist'  => $columnsExist,
        'row'            => $dbRow,
        'permisos_decoded' => $permDecoded,
    ],
    'adminLoadUserPermisos_result' => $uid ? adminLoadUserPermisos($uid) : null,
    'path_detection_test' => $pathDetections,
    'php_version' => PHP_VERSION,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
