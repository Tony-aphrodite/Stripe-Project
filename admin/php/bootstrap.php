<?php
/**
 * Voltika Admin/CEDIS — Bootstrap
 * Central entry point for all admin panel endpoints.
 */
require_once __DIR__ . '/../../configurador/php/master-bootstrap.php';
voltikaEnsureSchema();


$isApiRequest = (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'index.php');
if (!headers_sent()) {
    if ($isApiRequest) {
        header('Content-Type: application/json');
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET,POST,PUT,DELETE,OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
}

session_name('VOLTIKA_ADMIN');
session_start();

// ── Helpers ─────────────────────────────────────────────────────────────────

function adminJsonIn() {
    return json_decode(file_get_contents('php://input'), true) ?: [];
}
function adminJsonOut($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function adminRequireAuth($roles = null) {
    if (empty($_SESSION['admin_user_id'])) {
        adminJsonOut(['error' => 'No autorizado'], 401);
    }
    if ($roles) {
        $rol = $_SESSION['admin_user_rol'] ?? '';
        if (!in_array($rol, (array)$roles, true)) {
            // Customer brief 2026-05-04: role-based gate alone breaks
            // granular per-user permissions. The dashboard stores
            // dealer_usuarios.permisos = ["dashboard","envios",...] but
            // every endpoint only accepts a static role list, so a
            // user with role=logistica + permisos=["envios","puntos"]
            // gets 403 from EVERY endpoint they have permission for.
            // Fix: when the role check fails, fall back to checking
            // whether the script's enclosing module folder is in the
            // user's permisos array. e.g. /admin/php/envios/listar.php
            // → module "envios" → allowed if permisos contains "envios".
            $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
            $module = '';
            if (preg_match('#/admin/php/([^/]+)/[^/]+\.php$#', $script, $m)) {
                $module = $m[1];
            }
            $permisos = adminLoadUserPermisos((int)$_SESSION['admin_user_id']);
            if ($module === '' || !in_array($module, $permisos, true)) {
                adminJsonOut([
                    'error'  => 'Sin permisos para esta acción',
                    'rol'    => $rol,
                    'modulo' => $module ?: '(desconocido)',
                ], 403);
            }
            // Module is in user's permisos — allow regardless of role.
        }
    }
    return (int)$_SESSION['admin_user_id'];
}

/**
 * Load the per-user permisos array from DB on every call (NOT cached).
 * Returns ["dashboard","envios","puntos",...] from dealer_usuarios.permisos.
 *
 * Uncached on purpose: when an admin edits a user's permissions via
 * roles/guardar.php the change must take effect immediately without
 * forcing the user to log out and back in. The query is one indexed
 * read per protected endpoint hit, negligible cost.
 */
function adminLoadUserPermisos(int $userId): array {
    $perm = [];
    try {
        $pdo = getDB();
        $st = $pdo->prepare("SELECT permisos FROM dealer_usuarios WHERE id = ? LIMIT 1");
        $st->execute([$userId]);
        $raw = $st->fetchColumn();
        if ($raw) {
            $decoded = json_decode((string)$raw, true);
            if (is_array($decoded)) $perm = array_values(array_filter(array_map('strval', $decoded)));
        }
    } catch (Throwable $e) {
        error_log('adminLoadUserPermisos: ' . $e->getMessage());
    }
    return $perm;
}
function adminLog($accion, $detalle = []) {
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO admin_log (usuario_id, accion, detalle, ip) VALUES (?,?,?,?)");
    $stmt->execute([
        $_SESSION['admin_user_id'] ?? null,
        $accion,
        json_encode($detalle, JSON_UNESCAPED_UNICODE),
        $_SERVER['REMOTE_ADDR'] ?? ''
    ]);
}
function adminNotify($tipo, $data) {
    // Email + WhatsApp notifications for status changes
    $pdo = getDB();
    $templates = [
        'punto_asignado' => [
            'subject' => '📍 Punto asignado a tu compra',
            'sms' => 'Voltika: Tu moto será entregada en {punto}. Te avisaremos cuando esté lista.',
        ],
        'moto_enviada' => [
            'subject' => '🚚 Tu Voltika está en camino',
            'sms' => 'Voltika: Tu moto fue enviada. Fecha estimada de llegada: {fecha}.',
        ],
        'lista_para_recoger' => [
            'subject' => '✅ Tu Voltika está lista para entrega',
            'sms' => 'Voltika: Tu moto está lista! Recibirás un código OTP para recogerla.',
        ],
    ];
    if (!isset($templates[$tipo])) return;
    $tpl = $templates[$tipo];
    $msg = $tpl['sms'];
    foreach ($data as $k => $v) { $msg = str_replace('{'.$k.'}', $v, $msg); }

    // Send SMS if phone available
    // ── Round 47 (2026-05-16): apikey/form-urlencoded (NOT Bearer/JSON)
    // to match SMSmasivos' real auth scheme. See voltika-notify.php for
    // full rationale.
    if (!empty($data['telefono'])) {
        $tel = preg_replace('/\D/', '', $data['telefono']);
        if (strlen($tel) === 12 && strpos($tel, '52') === 0)  $tel = substr($tel, 2);
        if (strlen($tel) === 11 && strpos($tel, '521') === 0) $tel = substr($tel, 3);
        $smsKey = defined('SMSMASIVOS_API_KEY') ? SMSMASIVOS_API_KEY : (getenv('SMSMASIVOS_API_KEY') ?: '');
        if ($smsKey) {
            $ch = curl_init('https://api.smsmasivos.com.mx/sms/send');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'apikey: ' . $smsKey,
                    'Content-Type: application/x-www-form-urlencoded',
                ],
                CURLOPT_POSTFIELDS => http_build_query([
                    'message'      => $msg,
                    'numbers'      => $tel,
                    'country_code' => '52',
                ]),
            ]);
            curl_exec($ch); curl_close($ch);
        }
    }
    // Send email if available
    if (!empty($data['email'])) {
        @sendMail($data['email'], $data['nombre'] ?? 'Cliente', $tpl['subject'], '<p>' . nl2br(htmlspecialchars($msg)) . '</p>');
    }
}
