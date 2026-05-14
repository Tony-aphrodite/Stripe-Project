<?php
/**
 * Voltika Puntos — Bootstrap
 * Shared entry point for all Punto Voltika panel endpoints.
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
    header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
}

// ── Round 35 (2026-05-14, Óscar — "No autorizado" al Confirmar recepción) ──
// El operador del punto llena la recepción (3 fotos + checklist + datos)
// y eso toma varios minutos. Con el default de PHP (session.gc_maxlifetime
// = 1440s = 24 min) la sesión expira ANTES de que pueda guardar, y el
// submit revienta con "No autorizado". Subimos la vida útil de la sesión
// del PUNTO a 2 horas — suficiente para llenar el form con calma. Aplica
// SOLO a la cookie VOLTIKA_PUNTO; no toca admin/portal/configurador.
ini_set('session.gc_maxlifetime', '7200');
ini_set('session.cookie_lifetime', '7200');
session_set_cookie_params([
    'lifetime' => 7200,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('VOLTIKA_PUNTO');
session_start();

$pdo = getDB();

function puntoJsonIn() {
    return json_decode(file_get_contents('php://input'), true) ?: [];
}
function puntoJsonOut($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function puntoRequireAuth() {
    if (empty($_SESSION['punto_user_id'])) {
        puntoJsonOut(['error' => 'No autorizado'], 401);
    }
    return [
        'user_id'  => (int)$_SESSION['punto_user_id'],
        'punto_id' => (int)$_SESSION['punto_id'],
        'nombre'   => $_SESSION['punto_user_nombre'] ?? '',
    ];
}
function puntoLog($accion, $detalle = []) {
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO admin_log (usuario_id, accion, detalle, ip) VALUES (?,?,?,?)");
    $stmt->execute([
        $_SESSION['punto_user_id'] ?? null,
        'punto:' . $accion,
        json_encode($detalle, JSON_UNESCAPED_UNICODE),
        $_SERVER['REMOTE_ADDR'] ?? ''
    ]);
}
function puntoGenOTP() { return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT); }
