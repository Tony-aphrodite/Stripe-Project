<?php
/**
 * Voltika Admin/CEDIS — Bootstrap
 * Central entry point for all admin panel endpoints.
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
        if (!in_array($rol, (array)$roles)) {
            adminJsonOut(['error' => 'Sin permisos para esta acción'], 403);
        }
    }
    return (int)$_SESSION['admin_user_id'];
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
    if (!empty($data['telefono'])) {
        $tel = preg_replace('/\D/', '', $data['telefono']);
        if (strlen($tel) === 10) $tel = '52' . $tel;
        $smsKey = defined('SMSMASIVOS_API_KEY') ? SMSMASIVOS_API_KEY : (getenv('SMSMASIVOS_API_KEY') ?: '');
        if ($smsKey) {
            $ch = curl_init('https://api.smsmasivos.com.mx/sms/send');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $smsKey],
                CURLOPT_POSTFIELDS => json_encode(['phone_number' => $tel, 'message' => $msg]),
            ]);
            curl_exec($ch); curl_close($ch);
        }
    }
    // Send email if available
    if (!empty($data['email'])) {
        @sendMail($data['email'], $data['nombre'] ?? 'Cliente', $tpl['subject'], '<p>' . nl2br(htmlspecialchars($msg)) . '</p>');
    }
}
