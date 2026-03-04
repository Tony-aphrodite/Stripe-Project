<?php
/**
 * Voltika - Verificar Identidad con Truora
 * Receives multipart FormData (INE images + selfie) from frontend.
 * Saves images locally, then runs Truora text-based identity check.
 *
 * POST body (multipart/form-data):
 *   ine_frente        – File: INE front image
 *   ine_reverso       – File: INE back image
 *   selfie            – File: selfie image
 *   nombre            – First name
 *   apellidos         – Last name(s)
 *   fecha_nacimiento  – YYYY-MM-DD
 *   telefono          – Phone (optional)
 *   email             – Email (optional)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Central config ───────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';

define('TRUORA_API_URL', 'https://api.truora.com/v1/checks');
define('TRUORA_POLL_MAX', 20);
define('TRUORA_POLL_INTERVAL', 2);

session_start();

// ── Parse request (multipart FormData OR JSON fallback) ─────────────────────
$nombre    = '';
$apellidos = '';
$fechaNac  = '';
$telefono  = '';
$email     = '';

if (!empty($_POST)) {
    // Multipart FormData from frontend
    $nombre    = trim($_POST['nombre'] ?? '');
    $apellidos = trim($_POST['apellidos'] ?? '');
    $fechaNac  = trim($_POST['fecha_nacimiento'] ?? '');
    $telefono  = trim($_POST['telefono'] ?? '');
    $email     = trim($_POST['email'] ?? '');
} else {
    // JSON fallback
    $json = json_decode(file_get_contents('php://input'), true);
    if ($json) {
        $nombre    = trim($json['nombre'] ?? '');
        $apellidos = trim($json['apellidos'] ?? '');
        $fechaNac  = trim($json['fecha_nacimiento'] ?? '');
        $telefono  = trim($json['telefono'] ?? '');
        $email     = trim($json['email'] ?? '');
    }
}

if (!$nombre || !$apellidos) {
    http_response_code(400);
    echo json_encode(['error' => 'Nombre y apellidos son requeridos']);
    exit;
}

// ── Save uploaded images ────────────────────────────────────────────────────
$uploadDir = __DIR__ . '/uploads';
$timestamp = date('Ymd_His');
$prefix    = preg_replace('/[^a-zA-Z0-9]/', '', $nombre) . '_' . $timestamp;
$savedFiles = [];

$fileFields = ['ine_frente', 'ine_reverso', 'selfie'];
foreach ($fileFields as $field) {
    if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
        $tmpName  = $_FILES[$field]['tmp_name'];
        $origName = $_FILES[$field]['name'];
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION)) ?: 'jpg';

        // Validate: must be image
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $tmpName);
        finfo_close($finfo);
        if (strpos($mime, 'image/') !== 0) continue;

        // Validate: max 10 MB
        if ($_FILES[$field]['size'] > 10 * 1024 * 1024) continue;

        $destName = $prefix . '_' . $field . '.' . $ext;
        $destPath = $uploadDir . '/' . $destName;
        if (move_uploaded_file($tmpName, $destPath)) {
            $savedFiles[$field] = $destName;
        }
    }
}

// Store saved file paths in session for reference
$_SESSION['identidad_files'] = $savedFiles;

// ── Logging ─────────────────────────────────────────────────────────────────
$logFile = __DIR__ . '/logs/truora.log';
file_put_contents($logFile, json_encode([
    'timestamp'   => date('c'),
    'action'      => 'upload_received',
    'nombre'      => $nombre,
    'files_saved' => array_keys($savedFiles),
    'file_count'  => count($savedFiles),
]) . "\n", FILE_APPEND | LOCK_EX);

// ── Truora identity check ───────────────────────────────────────────────────
if (!TRUORA_API_KEY) {
    // No API key → fallback approve
    $_SESSION['truora_status'] = 'fallback_approved';
    $_SESSION['truora_score']  = null;
    echo json_encode([
        'status'   => 'approved',
        'score'    => null,
        'fallback' => true,
        'files'    => $savedFiles,
        'message'  => 'Verificación pendiente (API key no configurada)',
    ]);
    exit;
}

// Create check in Truora
$postFields = [
    'country'         => 'MX',
    'type'            => 'identity',
    'user_authorized' => 'true',
    'first_name'      => $nombre,
    'last_name'       => $apellidos,
];

if ($fechaNac) $postFields['date_of_birth'] = $fechaNac;
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

// Logging
file_put_contents($logFile, json_encode([
    'timestamp' => date('c'),
    'action'    => 'create_check',
    'nombre'    => $nombre,
    'httpCode'  => $httpCode,
    'curlErr'   => $curlErr,
    'response'  => $response,
]) . "\n", FILE_APPEND | LOCK_EX);

// ── Evaluate response ───────────────────────────────────────────────────────
if ($curlErr || $httpCode < 200 || $httpCode >= 300) {
    $_SESSION['truora_status'] = 'fallback_approved';
    $_SESSION['truora_score']  = null;
    echo json_encode([
        'status'   => 'approved',
        'score'    => null,
        'fallback' => true,
        'files'    => $savedFiles,
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
        'files'    => $savedFiles,
    ]);
    exit;
}

// ── Polling ─────────────────────────────────────────────────────────────────
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
        'files'    => $savedFiles,
    ]);
    exit;
}

// Timeout → fallback approve
$_SESSION['truora_status']   = 'fallback_approved';
$_SESSION['truora_check_id'] = $checkId;
echo json_encode([
    'status'   => 'approved',
    'score'    => null,
    'check_id' => $checkId,
    'fallback' => true,
    'files'    => $savedFiles,
    'message'  => 'Verificación en proceso, aprobación provisional',
]);
