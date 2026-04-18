<?php
/**
 * Voltika Puntos — Bootstrap
 * Shared entry point for all Punto Voltika panel endpoints.
 */
require_once __DIR__ . '/../../configurador_prueba_test/php/master-bootstrap.php';
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
