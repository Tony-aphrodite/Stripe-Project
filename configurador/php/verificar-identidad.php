<?php
/**
 * Voltika - Verificar Identidad con Truora
 * Crea un identity check en Truora y opcionalmente espera el resultado (polling).
 * Docs: https://developers.truora.com
 *
 * POST body (JSON):
 *   nombre            – Nombre(s)
 *   apellidos         – Apellido paterno + materno
 *   fecha_nacimiento  – YYYY-MM-DD
 *   ine_numero        – Clave de elector o CIC del INE (opcional, mejora precisión)
 *   curp              – CURP (opcional)
 *   telefono          – Teléfono (opcional)
 *   email             – Email (opcional)
 *   wait              – bool: si true, hace polling hasta 20 s (default true)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Configuración Truora ────────────────────────────────────────────────────
define('TRUORA_API_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhY2NvdW50X2lkIjoiIiwiYWRkaXRpb25hbF9kYXRhIjoie30iLCJhcHBsaWNhdGlvbl9pZCI6IiIsImNsaWVudF9pZCI6IlRDSTc0NTkxNzg2NDA1NzYzZTMxZjFlODllYjY3NjY2NGEyIiwiZXhwIjozMzQ5Mjc4Mjg5LCJncmFudCI6IiIsImlhdCI6MTc3MjQ3ODI4OSwiaXNzIjoiaHR0cHM6Ly9jb2duaXRvLWlkcC51cy1lYXN0LTEuYW1hem9uYXdzLmNvbS91cy1lYXN0LTFfUmJvQ2lFd01nIiwianRpIjoiMDM3NTdlMjYtMTc5Yi00YTc4LWI0ZjEtMWYxOTE0YTI3NmM2Iiwia2V5X25hbWUiOiJwcnVlYmEiLCJrZXlfdHlwZSI6ImJhY2tlbmQiLCJ1c2VybmFtZSI6IlRDSTc0NTkxNzg2NDA1NzYzZTMxZjFlODllYjY3NjY2NGEyLXBydWViYSJ9.xL1w6VcjOCI5HqNijvWEj6dGjScUXRVouPkoueKCKs8');
define('TRUORA_API_URL', 'https://api.truora.com/v1/checks');
define('TRUORA_POLL_MAX', 20); // Max seconds to poll
define('TRUORA_POLL_INTERVAL', 2); // Seconds between polls

session_start();

// ── Request ─────────────────────────────────────────────────────────────────
$json = json_decode(file_get_contents('php://input'), true);
if (!$json) {
    http_response_code(400);
    echo json_encode(['error' => 'Request inválido']);
    exit;
}

$nombre   = trim($json['nombre'] ?? '');
$apellidos = trim($json['apellidos'] ?? '');
$fechaNac = trim($json['fecha_nacimiento'] ?? '');
$ine      = trim($json['ine_numero'] ?? '');
$curp     = trim($json['curp'] ?? '');
$telefono = trim($json['telefono'] ?? '');
$email    = trim($json['email'] ?? '');
$wait     = $json['wait'] ?? true;

if (!$nombre || !$apellidos) {
    http_response_code(400);
    echo json_encode(['error' => 'Nombre y apellidos son requeridos']);
    exit;
}

// ── Crear check en Truora ───────────────────────────────────────────────────
$postFields = [
    'country'         => 'MX',
    'type'            => 'identity',
    'user_authorized' => 'true',
    'first_name'      => $nombre,
    'last_name'       => $apellidos,
];

if ($fechaNac) $postFields['date_of_birth'] = $fechaNac;
if ($ine)      $postFields['national_id'] = $ine;
if ($curp)     $postFields['curp'] = $curp;
if ($telefono) $postFields['phone_number'] = $telefono;
if ($email)    $postFields['email'] = $email;

$ch = curl_init(TRUORA_API_URL);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($postFields),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Truora-API-Key: ' . TRUORA_API_KEY,
        'Content-Type: application/x-www-form-urlencoded',
    ],
    CURLOPT_TIMEOUT        => 15,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

// ── Logging ─────────────────────────────────────────────────────────────────
$logFile = __DIR__ . '/logs/truora.log';
if (!is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}
file_put_contents($logFile, json_encode([
    'timestamp' => date('c'),
    'action'    => 'create_check',
    'nombre'    => $nombre,
    'httpCode'  => $httpCode,
    'curlErr'   => $curlErr,
    'response'  => $response,
]) . "\n", FILE_APPEND | LOCK_EX);

// ── Evaluar respuesta ───────────────────────────────────────────────────────
if ($curlErr || $httpCode < 200 || $httpCode >= 300) {
    // API error → fallback: aprobar sin verificación (modo MVP)
    $_SESSION['truora_status'] = 'fallback_approved';
    $_SESSION['truora_score']  = null;
    echo json_encode([
        'status'   => 'approved',
        'score'    => null,
        'fallback' => true,
        'message'  => 'Verificación pendiente (modo estimado)',
    ]);
    exit;
}

$data = json_decode($response, true);
$checkId = $data['check']['check_id'] ?? $data['check_id'] ?? null;

if (!$checkId) {
    $_SESSION['truora_status'] = 'fallback_approved';
    $_SESSION['truora_score']  = null;
    echo json_encode([
        'status'   => 'approved',
        'score'    => null,
        'fallback' => true,
    ]);
    exit;
}

// ── Polling (si wait = true) ────────────────────────────────────────────────
if ($wait) {
    $elapsed = 0;
    $result  = null;

    while ($elapsed < TRUORA_POLL_MAX) {
        sleep(TRUORA_POLL_INTERVAL);
        $elapsed += TRUORA_POLL_INTERVAL;

        $ch2 = curl_init(TRUORA_API_URL . '/' . $checkId);
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Truora-API-Key: ' . TRUORA_API_KEY,
            ],
            CURLOPT_TIMEOUT        => 10,
        ]);

        $pollResponse = curl_exec($ch2);
        $pollCode     = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);

        if ($pollCode >= 200 && $pollCode < 300) {
            $pollData = json_decode($pollResponse, true);
            $status   = $pollData['check']['status'] ?? $pollData['status'] ?? 'unknown';

            if ($status === 'completed' || $status === 'error') {
                $result = $pollData;
                break;
            }
        }
    }

    if ($result) {
        $check = $result['check'] ?? $result;
        $score = $check['score'] ?? null;
        $identityStatus = $check['summary']['identity_status']
                       ?? $check['result'] ?? 'unknown';

        $approved = ($identityStatus === 'valid' || ($score !== null && $score >= 0.5));

        $_SESSION['truora_status']   = $approved ? 'approved' : 'rejected';
        $_SESSION['truora_score']    = $score;
        $_SESSION['truora_check_id'] = $checkId;

        file_put_contents($logFile, json_encode([
            'timestamp' => date('c'),
            'action'    => 'poll_result',
            'check_id'  => $checkId,
            'score'     => $score,
            'identity'  => $identityStatus,
            'approved'  => $approved,
        ]) . "\n", FILE_APPEND | LOCK_EX);

        echo json_encode([
            'status'   => $approved ? 'approved' : 'rejected',
            'score'    => $score,
            'check_id' => $checkId,
            'identity' => $identityStatus,
        ]);
        exit;
    }

    // Timeout — aprobar con fallback
    $_SESSION['truora_status']   = 'fallback_approved';
    $_SESSION['truora_check_id'] = $checkId;
    echo json_encode([
        'status'   => 'approved',
        'score'    => null,
        'check_id' => $checkId,
        'fallback' => true,
        'message'  => 'Verificación en proceso, aprobación provisional',
    ]);
    exit;
}

// ── Sin polling: devolver check_id para que el frontend lo consulte ─────────
$_SESSION['truora_check_id'] = $checkId;
echo json_encode([
    'status'   => 'in_progress',
    'check_id' => $checkId,
]);
