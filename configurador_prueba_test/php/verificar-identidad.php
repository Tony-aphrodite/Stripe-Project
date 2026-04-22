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

define('TRUORA_API_URL',          'https://api.truora.com/v1/checks');
define('TRUORA_FACE_URL',         'https://api.truora.com/v1/face-validation');
define('TRUORA_DOC_URL',          'https://api.truora.com/v1/document-validations');
define('TRUORA_POLL_MAX',         20);
define('TRUORA_POLL_INTERVAL',    2);
define('TRUORA_FACE_THRESHOLD',   0.70);   // 70% similarity to consider a match

// Feature flags — flip to true after customer confirms production credentials
// and that their Truora account has these products enabled.
if (!defined('TRUORA_DOC_VALIDATION_ENABLED')) {
    define('TRUORA_DOC_VALIDATION_ENABLED', getenv('TRUORA_DOC_VALIDATION_ENABLED') === '1');
}
if (!defined('TRUORA_FACE_MATCH_ENABLED')) {
    define('TRUORA_FACE_MATCH_ENABLED', getenv('TRUORA_FACE_MATCH_ENABLED') !== '0'); // default ON
}

session_start();

// ── Parse request (multipart FormData OR JSON fallback) ─────────────────────
$nombre    = '';
$apellidos = '';
$fechaNac  = '';
$telefono  = '';
$email     = '';

$domicilioDiferente = 0;

if (!empty($_POST)) {
    // Multipart FormData from frontend
    $nombre    = trim($_POST['nombre'] ?? '');
    $apellidos = trim($_POST['apellidos'] ?? '');
    $fechaNac  = trim($_POST['fecha_nacimiento'] ?? '');
    $telefono  = trim($_POST['telefono'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $domicilioDiferente = !empty($_POST['domicilio_diferente']) ? 1 : 0;
} else {
    // JSON fallback
    $json = json_decode(file_get_contents('php://input'), true);
    if ($json) {
        $nombre    = trim($json['nombre'] ?? '');
        $apellidos = trim($json['apellidos'] ?? '');
        $fechaNac  = trim($json['fecha_nacimiento'] ?? '');
        $telefono  = trim($json['telefono'] ?? '');
        $email     = trim($json['email'] ?? '');
        $domicilioDiferente = !empty($json['domicilio_diferente']) ? 1 : 0;
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

$fileFields = ['ine_frente', 'ine_reverso', 'selfie', 'comprobante_domicilio'];
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
    // No API key is a config error in production. Surface it instead of
    // pretending the customer was approved.
    $_SESSION['truora_status'] = 'error';
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'error'   => 'TRUORA_API_KEY no configurada en el servidor',
        'message' => 'Configuración incompleta. Contacta soporte.',
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

// Logging — DB (reliable on Plesk) + file (best-effort)
try {
    require_once __DIR__ . '/config.php';
    $pdoLog = getDB();
    $pdoLog->exec("CREATE TABLE IF NOT EXISTS truora_query_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        action VARCHAR(50),
        nombre VARCHAR(200),
        apellidos VARCHAR(200),
        email VARCHAR(200),
        http_code INT,
        body_sent TEXT,
        response MEDIUMTEXT,
        curl_err VARCHAR(500),
        check_id VARCHAR(100),
        approved TINYINT(1),
        freg DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdoLog->prepare("INSERT INTO truora_query_log (action, nombre, apellidos, email, http_code, body_sent, response, curl_err) VALUES (?,?,?,?,?,?,?,?)")
        ->execute(['create_check', $nombre, $apellidos, $email, $httpCode, http_build_query($postFields), substr((string)$response, 0, 5000), substr((string)$curlErr, 0, 500)]);
} catch (Throwable $e) {}
@file_put_contents($logFile, json_encode([
    'timestamp' => date('c'), 'action' => 'create_check', 'nombre' => $nombre,
    'httpCode' => $httpCode, 'curlErr' => $curlErr, 'response' => $response,
]) . "\n", FILE_APPEND | LOCK_EX);

// ── Evaluate response ───────────────────────────────────────────────────────
// NO SILENT FALLBACK: if Truora fails, surface the real error so the customer
// (and support) can see what's wrong. The old "fallback approved" behaviour
// masked 100% failures as success, which is why queries weren't showing up
// in the Truora dashboard despite customers seeing "aprobado" on screen.
if ($curlErr || $httpCode < 200 || $httpCode >= 300) {
    $_SESSION['truora_status'] = 'error';
    http_response_code(502);
    echo json_encode([
        'status'   => 'error',
        'error'    => 'Truora API falló',
        'http'     => $httpCode,
        'curl_err' => $curlErr ?: null,
        'body'     => substr((string)$response, 0, 400),
        'message'  => 'No pudimos conectar con el servicio de verificación. Intenta de nuevo o contacta soporte.',
    ]);
    exit;
}

$data = json_decode($response, true);
$checkId = $data['check']['check_id'] ?? $data['check_id'] ?? null;

if (!$checkId) {
    $_SESSION['truora_status'] = 'error';
    http_response_code(502);
    echo json_encode([
        'status'  => 'error',
        'error'   => 'Truora respondió sin check_id',
        'raw'     => $data,
        'message' => 'Respuesta inesperada del servicio de verificación. Contacta soporte.',
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

    // ── Face Recognition: selfie vs INE front ─────────────────────────────────
    // Compares the customer's selfie with the face on the INE (front side).
    // This proves that the person submitting the application is the same
    // person shown on the official ID, preventing identity theft.
    $faceResult = null;
    if (TRUORA_FACE_MATCH_ENABLED
        && !empty($savedFiles['selfie'])
        && !empty($savedFiles['ine_frente'])) {
        $faceResult = truoraFaceMatch(
            $uploadDir . '/' . $savedFiles['selfie'],
            $uploadDir . '/' . $savedFiles['ine_frente'],
            $logFile
        );
    }

    // ── Document Validation: INE OCR ──────────────────────────────────────────
    // Sends both sides of the INE to Truora so they can extract data and
    // verify the document is authentic. Disabled by default until the
    // customer confirms their account has this product enabled.
    $docResult = null;
    if (TRUORA_DOC_VALIDATION_ENABLED
        && !empty($savedFiles['ine_frente'])
        && !empty($savedFiles['ine_reverso'])) {
        $docResult = truoraDocumentValidation(
            $uploadDir . '/' . $savedFiles['ine_frente'],
            $uploadDir . '/' . $savedFiles['ine_reverso'],
            $logFile
        );
    }

    // Combine all results: approved only if BOTH name check AND face match pass
    // (document validation is informational until confirmed working).
    $faceMatched = $faceResult ? (bool)$faceResult['match'] : null;
    $finalApproved = $approved;
    if ($faceResult !== null) {
        $finalApproved = $approved && $faceMatched;
    }

    // ── Guardar en BD ─────────────────────────────────────────────────────────
    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS verificaciones_identidad (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            nombre           VARCHAR(200),
            apellidos        VARCHAR(200),
            fecha_nacimiento VARCHAR(20),
            telefono         VARCHAR(30),
            email            VARCHAR(200),
            truora_check_id  VARCHAR(100),
            truora_score     DECIMAL(5,4),
            identity_status  VARCHAR(50),
            approved         TINYINT(1),
            files_saved      TEXT,
            freg             DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        // Add columns for face match + document validation (idempotent)
        ensureVerifColumns($pdo);

        $stmt = $pdo->prepare("
            INSERT INTO verificaciones_identidad
                (nombre, apellidos, fecha_nacimiento, telefono, email,
                 truora_check_id, truora_score, identity_status, approved, files_saved,
                 ine_frente_path, ine_reverso_path, selfie_path,
                 face_check_id, face_score, face_match,
                 doc_check_id, doc_status,
                 comprobante_path, domicilio_diferente)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $nombre, $apellidos, $fechaNac, $telefono, $email,
            $checkId, $score, $identityStatus, $finalApproved ? 1 : 0,
            json_encode(array_values($savedFiles)),
            $savedFiles['ine_frente']  ?? null,
            $savedFiles['ine_reverso'] ?? null,
            $savedFiles['selfie']      ?? null,
            $faceResult['check_id'] ?? null,
            $faceResult['similarity'] ?? null,
            $faceResult ? ($faceMatched ? 1 : 0) : null,
            $docResult['check_id'] ?? null,
            $docResult['status']   ?? null,
            $savedFiles['comprobante_domicilio'] ?? null,
            $domicilioDiferente,
        ]);
    } catch (PDOException $e) {
        error_log('Voltika verificaciones_identidad DB error: ' . $e->getMessage());
    }

    echo json_encode([
        'status'     => $finalApproved ? 'approved' : 'rejected',
        'score'      => $score,
        'check_id'   => $checkId,
        'identity'   => $identityStatus,
        'files'      => $savedFiles,
        'face'       => $faceResult,
        'document'   => $docResult,
    ]);
    exit;
}

// Timeout → Truora check is still processing. Return "pending" (not approved)
// so the frontend doesn't advance the customer falsely. The check_id is
// saved; the truora-webhook.php endpoint will update the DB when Truora
// finishes. Customer can retry.
$_SESSION['truora_status']   = 'pending';
$_SESSION['truora_check_id'] = $checkId;
http_response_code(202);
echo json_encode([
    'status'   => 'pending',
    'check_id' => $checkId,
    'files'    => $savedFiles,
    'message'  => 'Truora aún está procesando la verificación. Espera un momento y vuelve a intentar.',
]);

// ═════════════════════════════════════════════════════════════════════════════
// Helper functions
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Compare two face images via Truora Face Recognition API.
 * Used in admin-face-compare.php at delivery time; reused here to verify
 * the customer's selfie matches the face on the INE front.
 *
 * Returns:
 *   [ 'match' => bool, 'similarity' => float, 'check_id' => string ]
 * or null on failure (so the caller can decide whether to block or fall through).
 */
function truoraFaceMatch(string $imagePath1, string $imagePath2, string $logFile): ?array {
    if (!file_exists($imagePath1) || !file_exists($imagePath2)) return null;
    if (!TRUORA_API_KEY) return null;

    $ch = curl_init(TRUORA_FACE_URL);
    $postFields = [
        'type'   => 'face-recognition',
        'image1' => new CURLFile($imagePath1, 'image/jpeg', 'selfie.jpg'),
        'image2' => new CURLFile($imagePath2, 'image/jpeg', 'ine_frente.jpg'),
    ];
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Truora-API-Key: ' . TRUORA_API_KEY,
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    @file_put_contents($logFile, json_encode([
        'timestamp' => date('c'),
        'action'    => 'face_match',
        'httpCode'  => $httpCode,
        'curlErr'   => $curlErr,
        'response'  => substr((string)$response, 0, 500),
    ]) . "\n", FILE_APPEND | LOCK_EX);

    if ($curlErr || $httpCode < 200 || $httpCode >= 300) return null;

    $data = json_decode($response, true);
    if (!$data) return null;

    // Truora response fields vary by product version. Support both shapes.
    $similarity = $data['face_validation']['similarity']
               ?? $data['similarity']
               ?? $data['score']
               ?? null;
    $matchFlag  = $data['face_validation']['match'] ?? $data['match'] ?? null;
    $checkId    = $data['face_validation']['check_id']
               ?? $data['check_id']
               ?? $data['face_recognition_id']
               ?? null;

    $isMatch = false;
    if ($matchFlag === true || $matchFlag === 'true') {
        $isMatch = true;
    } elseif ($similarity !== null && floatval($similarity) >= TRUORA_FACE_THRESHOLD) {
        $isMatch = true;
    }

    return [
        'match'      => $isMatch,
        'similarity' => $similarity !== null ? floatval($similarity) : null,
        'check_id'   => $checkId,
    ];
}

/**
 * Send INE front + back to Truora Document Validation for OCR and
 * authenticity check. Disabled by default — enable via
 * TRUORA_DOC_VALIDATION_ENABLED=1 in .env after the customer confirms
 * the Truora account has the Document Validation product activated.
 *
 * IMPORTANT: The exact endpoint URL and payload shape must be confirmed
 * against Truora's production docs for the customer's account. This
 * implementation targets the standard pattern; adjust if Truora returns 404.
 */
function truoraDocumentValidation(string $ineFrontePath, string $ineReversoPath, string $logFile): ?array {
    if (!file_exists($ineFrontePath) || !file_exists($ineReversoPath)) return null;
    if (!TRUORA_API_KEY) return null;

    $ch = curl_init(TRUORA_DOC_URL);
    $postFields = [
        'country'        => 'MX',
        'document_type'  => 'national-id',
        'front_image'    => new CURLFile($ineFrontePath,  'image/jpeg', 'ine_frente.jpg'),
        'back_image'     => new CURLFile($ineReversoPath, 'image/jpeg', 'ine_reverso.jpg'),
    ];
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Truora-API-Key: ' . TRUORA_API_KEY,
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    @file_put_contents($logFile, json_encode([
        'timestamp' => date('c'),
        'action'    => 'document_validation',
        'httpCode'  => $httpCode,
        'curlErr'   => $curlErr,
        'response'  => substr((string)$response, 0, 500),
    ]) . "\n", FILE_APPEND | LOCK_EX);

    if ($curlErr || $httpCode < 200 || $httpCode >= 300) {
        return [
            'check_id' => null,
            'status'   => 'api_error',
            'error'    => $curlErr ?: ('HTTP ' . $httpCode),
        ];
    }

    $data = json_decode($response, true);
    return [
        'check_id' => $data['document_validation']['check_id']
                   ?? $data['check_id']
                   ?? $data['validation_id']
                   ?? null,
        'status'   => $data['document_validation']['status']
                   ?? $data['status']
                   ?? 'completed',
        'data'     => $data,
    ];
}

/**
 * Add columns needed for face match + document validation to
 * verificaciones_identidad table. Idempotent — safe to call on every request.
 */
function ensureVerifColumns(PDO $pdo): void {
    $cols = [
        'ine_frente_path'  => "ADD COLUMN ine_frente_path  VARCHAR(255) NULL AFTER files_saved",
        'ine_reverso_path' => "ADD COLUMN ine_reverso_path VARCHAR(255) NULL AFTER ine_frente_path",
        'selfie_path'      => "ADD COLUMN selfie_path      VARCHAR(255) NULL AFTER ine_reverso_path",
        'face_check_id'    => "ADD COLUMN face_check_id    VARCHAR(100) NULL AFTER selfie_path",
        'face_score'       => "ADD COLUMN face_score       DECIMAL(5,4) NULL AFTER face_check_id",
        'face_match'       => "ADD COLUMN face_match       TINYINT(1)   NULL AFTER face_score",
        'doc_check_id'     => "ADD COLUMN doc_check_id     VARCHAR(100) NULL AFTER face_match",
        'doc_status'       => "ADD COLUMN doc_status       VARCHAR(50)  NULL AFTER doc_check_id",
        'webhook_payload'  => "ADD COLUMN webhook_payload  TEXT         NULL AFTER doc_status",
        'webhook_received_at' => "ADD COLUMN webhook_received_at DATETIME NULL AFTER webhook_payload",
        'comprobante_path'    => "ADD COLUMN comprobante_path    VARCHAR(255) NULL AFTER webhook_received_at",
        'domicilio_diferente' => "ADD COLUMN domicilio_diferente TINYINT(1)   NOT NULL DEFAULT 0 AFTER comprobante_path",
    ];
    try {
        $existing = $pdo->query("SHOW COLUMNS FROM verificaciones_identidad")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($cols as $name => $alter) {
            if (!in_array($name, $existing, true)) {
                try { $pdo->exec("ALTER TABLE verificaciones_identidad " . $alter); }
                catch (PDOException $e) { error_log('ensureVerifColumns(' . $name . '): ' . $e->getMessage()); }
            }
        }
    } catch (PDOException $e) {
        error_log('ensureVerifColumns: ' . $e->getMessage());
    }
}
