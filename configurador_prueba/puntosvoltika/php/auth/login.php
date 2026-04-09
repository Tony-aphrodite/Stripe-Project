<?php
// NOTE: this endpoint re-uses VOLTIKA_PUNTO session namespace
session_name('VOLTIKA_PUNTO');
session_start();

require_once __DIR__ . '/../../../configurador_prueba/php/config.php';
header('Content-Type: application/json');

$d = json_decode(file_get_contents('php://input'), true) ?: [];
$email = trim($d['email'] ?? '');
$pass  = $d['password'] ?? '';
if (!$email || !$pass) { http_response_code(400); echo json_encode(['error'=>'Email y contraseña requeridos']); exit; }

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM dealer_usuarios WHERE email=? AND activo=1 AND rol='dealer' LIMIT 1");
$stmt->execute([$email]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$u || !password_verify($pass, $u['password_hash'])) {
    http_response_code(401); echo json_encode(['error'=>'Credenciales inválidas']); exit;
}

$_SESSION['punto_user_id']  = (int)$u['id'];
$_SESSION['punto_user_nombre'] = $u['nombre'];
$_SESSION['punto_id']       = (int)($u['punto_id'] ?: 0);
$_SESSION['punto_nombre']   = $u['punto_nombre'];

// Load point details
$p = null;
if ($u['punto_id']) {
    $pStmt = $pdo->prepare("SELECT * FROM puntos_voltika WHERE id=?");
    $pStmt->execute([$u['punto_id']]);
    $p = $pStmt->fetch(PDO::FETCH_ASSOC);
}

echo json_encode([
    'ok' => true,
    'usuario' => ['id'=>$u['id'], 'nombre'=>$u['nombre'], 'punto_nombre'=>$u['punto_nombre']],
    'punto' => $p
]);
