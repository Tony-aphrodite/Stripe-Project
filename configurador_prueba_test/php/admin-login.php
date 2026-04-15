<?php
/**
 * Voltika Admin - Login / Logout endpoint
 * POST /php/admin-login.php   { email, password }  → { ok, dealer }
 * POST /php/admin-logout.php  (action=logout)       → { ok }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-auth.php';

$json = json_decode(file_get_contents('php://input'), true) ?? [];

$action = $json['action'] ?? 'login';

// ── LOGOUT ────────────────────────────────────────────────────────────────────
if ($action === 'logout') {
    destroyDealerSession();
    echo json_encode(['ok' => true]);
    exit;
}

// ── CHECK SESSION ─────────────────────────────────────────────────────────────
if ($action === 'check') {
    if (isDealerAuth()) {
        echo json_encode(['ok' => true, 'dealer' => [
            'id'          => $_SESSION['dealer_id'],
            'nombre'      => $_SESSION['dealer_nombre'],
            'email'       => $_SESSION['dealer_email'],
            'punto_nombre'=> $_SESSION['dealer_punto_nombre'],
            'punto_id'    => $_SESSION['dealer_punto_id'],
            'rol'         => $_SESSION['dealer_rol'],
        ]]);
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}

// ── LOGIN ─────────────────────────────────────────────────────────────────────
$email    = trim($json['email']    ?? '');
$password = trim($json['password'] ?? '');

if (!$email || !$password) {
    http_response_code(400);
    echo json_encode(['error' => 'Correo y contraseña requeridos']);
    exit;
}

try {
    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT * FROM dealer_usuarios WHERE email = ? AND activo = 1 LIMIT 1");
    $stmt->execute([$email]);
    $dealer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dealer || !password_verify($password, $dealer['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Correo o contraseña incorrectos']);
        exit;
    }

    setDealerSession($dealer);

    echo json_encode([
        'ok' => true,
        'dealer' => [
            'id'          => $dealer['id'],
            'nombre'      => $dealer['nombre'],
            'email'       => $dealer['email'],
            'punto_nombre'=> $dealer['punto_nombre'],
            'punto_id'    => $dealer['punto_id'],
            'rol'         => $dealer['rol'],
        ]
    ]);

} catch (PDOException $e) {
    error_log('Voltika admin-login error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Error interno. Intenta de nuevo.']);
}
