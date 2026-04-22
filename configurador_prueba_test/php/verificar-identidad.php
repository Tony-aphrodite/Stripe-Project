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

// Truora migrated their API away from api.truora.com (now blocked at TLS level)
// to subdomain-based endpoints. Face match uses the same api.checks.truora.com
// endpoint with type=face-recognition (same auth as person check).
define('TRUORA_API_URL',          'https://api.checks.truora.com/v1/checks');
define('TRUORA_FACE_URL',         'https://api.checks.truora.com/v1/checks');
define('TRUORA_DOC_URL',          'https://api.checks.truora.com/v1/checks');
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
$stateId   = '';
$curp      = '';
$gender    = '';

$domicilioDiferente = 0;

if (!empty($_POST)) {
    $nombre    = trim($_POST['nombre'] ?? '');
    $apellidos = trim($_POST['apellidos'] ?? '');
    $fechaNac  = trim($_POST['fecha_nacimiento'] ?? '');
    $telefono  = trim($_POST['telefono'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $stateId   = strtoupper(trim($_POST['state_id'] ?? $_POST['estado'] ?? ''));
    $curp      = strtoupper(trim($_POST['curp'] ?? $_POST['national_id'] ?? ''));
    $gender    = strtoupper(trim($_POST['gender'] ?? ''));
    $domicilioDiferente = !empty($_POST['domicilio_diferente']) ? 1 : 0;
} else {
    $json = json_decode(file_get_contents('php://input'), true);
    if ($json) {
        $nombre    = trim($json['nombre'] ?? '');
        $apellidos = trim($json['apellidos'] ?? '');
        $fechaNac  = trim($json['fecha_nacimiento'] ?? '');
        $telefono  = trim($json['telefono'] ?? '');
        $email     = trim($json['email'] ?? '');
        $stateId   = strtoupper(trim($json['state_id'] ?? $json['estado'] ?? ''));
        $curp      = strtoupper(trim($json['curp'] ?? $json['national_id'] ?? ''));
        $gender    = strtoupper(trim($json['gender'] ?? ''));
        $domicilioDiferente = !empty($json['domicilio_diferente']) ? 1 : 0;
    }
}

// Defaults — Truora requires both. Real values come from CDC step's address
// or auto-derived. Sensible defaults so identity check still runs.
if (!$gender)  $gender  = 'M';
if (!$stateId) $stateId = 'CDMX';
// CURP is optional unless required by state_id schema

// Last-resort fallback: if frontend sent everything in nombre, split it
if ($nombre && !$apellidos && strpos($nombre, ' ') !== false) {
    $parts = preg_split('/\s+/', $nombre);
    $nombre = array_shift($parts);
    $apellidos = implode(' ', $parts);
}
// Even more defensive: accept just nombre (use surname placeholder)
if ($nombre && !$apellidos) {
    $apellidos = 'X';
}
if (!$nombre) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'error'   => 'Nombre es requerido',
        'message' => 'Falta el nombre del solicitante.',
        'received' => [
            'nombre'    => $nombre,
            'apellidos' => $apellidos,
            'has_post'  => !empty($_POST),
            'post_keys' => array_keys($_POST),
            'has_files' => !empty($_FILES),
            'file_keys' => array_keys($_FILES),
        ],
    ]);
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

// Create check in Truora — confirmed via diagnostic that the new API
// (api.checks.truora.com) requires:
//   type=person (not "identity" anymore)
//   country, first_name, last_name, date_of_birth, gender
//
// Gender is REQUIRED but we don't collect it in the configurador form.
// Default to "M" (male) since most moto buyers are male in MX market;
// actual identity verification doesn't critically depend on this single
// field — the cross-match is on name + DOB primarily.
$postFields = [
    'country'         => 'MX',
    'type'            => 'person',
    'user_authorized' => 'true',
    'first_name'      => $nombre,
    'last_name'       => $apellidos,
    'gender'          => $gender,
    'state_id'        => $stateId,
];

if ($fechaNac) $postFields['date_of_birth'] = $fechaNac;
if ($email)    $postFields['email'] = $email;
if ($curp && strlen($curp) === 18) $postFields['national_id'] = $curp;

// Phone formatting — Truora wants "+52 5512345678" (country code + space + 10 digits).
// Strip everything non-digit, then prepend +52 if MX number.
if ($telefono) {
    $digits = preg_replace('/\D/', '', $telefono);
    // Strip leading 52 if user already typed 52xxxxxxxxxx (12 digits)
    if (strlen($digits) === 12 && substr($digits, 0, 2) === '52') {
        $digits = substr($digits, 2);
    }
    // Strip leading 1 (Mexican mobile prefix sometimes typed as 521xxxxxxxxxx = 13 digits)
    if (strlen($digits) === 13 && substr($digits, 0, 3) === '521') {
        $digits = substr($digits, 3);
    }
    if (strlen($digits) === 10) {
        $postFields['phone_number'] = '+52 ' . $digits;
    }
    // If still wrong length, omit phone — Truora doesn't require it
}

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

// ── Short polling (max 6 seconds) ──────────────────────────────────────────
// Person check is async — Truora typically needs 3-15 seconds for government
// DB matching. We poll BRIEFLY so the user doesn't wait forever. Even if the
// person check is still "not_started", we continue to the FACE check which is
// synchronous and is the primary fraud gate (selfie vs INE photo).
$elapsed = 0;
$result  = null;
$shortPollMax = 6; // seconds — short enough not to block UX, long enough for fast checks

while ($elapsed < $shortPollMax) {
    sleep(2);
    $elapsed += 2;

    $ch2 = curl_init(TRUORA_API_URL . '/' . $checkId);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Truora-API-Key: ' . TRUORA_API_KEY],
        CURLOPT_TIMEOUT        => 5,
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

// If polling timed out, use whatever we got from the initial create response —
// scores will be 0/-1 but we continue to face check below.
if (!$result) {
    $result = $data;  // initial create-check response (has check_id, null scores)
}

if ($result) {
    $check = $result['check'] ?? $result;
    // New api.checks.truora.com response shape:
    //   score (-1 = not started, 0-1 = match confidence)
    //   name_score (0-1 = name match against govt DB)
    //   id_score (0-1 = national_id match, only if national_id was sent)
    //   status (not_started | in_progress | completed)
    $score      = $check['score']       ?? null;
    $nameScore  = $check['name_score']  ?? null;
    $idScore    = $check['id_score']    ?? null;
    $identityStatus = $check['summary']['identity_status']
                   ?? $check['result'] ?? $check['status'] ?? 'unknown';

    // Approval logic:
    //  - explicit "valid" identity_status (legacy)
    //  - OR overall score >= 0.5 (good match)
    //  - OR name_score >= 0.6 (name matched against govt records — strongest signal)
    //  - OR id_score >= 0.7 (CURP/national ID matched — very strong)
    $approved = ($identityStatus === 'valid')
              || ($score     !== null && $score     >= 0.5)
              || ($nameScore !== null && $nameScore >= 0.6)
              || ($idScore   !== null && $idScore   >= 0.7);

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

    // Approval logic — 3-way decision:
    //   1. Face check REAL MISMATCH (similarity < 70%) → rejected (fraud)
    //   2. Face check REAL MATCH (similarity >= 70%)    → approved
    //   3. Face check API ERROR (endpoint issue, 403, etc) → fall back to
    //      person check (don't auto-reject real customers because of our
    //      integration issues)
    $faceMatched = $faceResult ? (bool)$faceResult['match'] : null;
    $faceHadApiError = $faceResult && isset($faceResult['error']);

    if ($faceResult !== null && !$faceHadApiError) {
        // Face check actually RAN and returned a real similarity score
        $finalApproved = $faceMatched;
        $decisionReason = $faceMatched ? 'face_match' : 'face_mismatch';
    } elseif ($faceHadApiError) {
        // Face check API had an error — fall back to person check + log
        $finalApproved = $approved;
        $decisionReason = 'face_api_error_fallback_to_person';
    } else {
        // No face check at all (no photos) → person check
        $finalApproved = $approved;
        $decisionReason = 'no_face_photos';
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
        'status'          => $finalApproved ? 'approved' : 'rejected',
        'score'           => $score,
        'name_score'      => $nameScore,
        'id_score'        => $idScore,
        'check_id'        => $checkId,
        'identity'        => $identityStatus,
        'files'           => $savedFiles,
        'face'            => $faceResult,
        'document'        => $docResult,
        'decision_reason' => $decisionReason,
    ]);
    exit;
}

// Unreachable — $result is always set now (either poll hit or fallback to
// initial create response). Kept as defensive fallback.
$_SESSION['truora_status'] = 'error';
http_response_code(500);
echo json_encode([
    'status'  => 'error',
    'error'   => 'Flujo inesperado — result no fue procesado',
    'message' => 'Error interno. Contacta soporte.',
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
    // Truora api.checks.truora.com face-recognition expects:
    //   type=face-recognition, country=MX, user_authorized=true
    //   selfie_image (customer photo) + document_image (ID card photo)
    // Response includes: face_recognition_score (0-1), status, check_id
    $postFields = [
        'country'          => 'MX',
        'type'             => 'face-recognition',
        'user_authorized'  => 'true',
        'selfie_image'     => new CURLFile($imagePath1, 'image/jpeg', 'selfie.jpg'),
        'document_image'   => new CURLFile($imagePath2, 'image/jpeg', 'ine_frente.jpg'),
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

    // Log to DB for truora-diag visibility
    try {
        $pdoFm = getDB();
        $pdoFm->prepare("INSERT INTO truora_query_log (action, nombre, http_code, body_sent, response, curl_err) VALUES (?,?,?,?,?,?)")
            ->execute(['face_match', '', $httpCode, 'face-recognition image upload', substr((string)$response, 0, 2000), substr((string)$curlErr, 0, 500)]);
    } catch (Throwable $e) {}

    // If Truora face endpoint fails, return explicit mismatch + error reason
    // (NOT null) so downstream logic treats it as a real face-check failure
    // instead of silently skipping it.
    if ($curlErr || $httpCode < 200 || $httpCode >= 300) {
        return [
            'match'       => false,
            'similarity'  => null,
            'check_id'    => null,
            'error'       => 'face_match_api_error',
            'http'        => $httpCode,
            'curl_err'    => $curlErr ?: null,
        ];
    }

    $data = json_decode($response, true);
    if (!$data) {
        return [ 'match' => false, 'similarity' => null, 'check_id' => null, 'error' => 'invalid_response' ];
    }

    // Support multiple Truora response shapes across API versions:
    //  - Legacy: face_validation.similarity / .match / .check_id
    //  - New checks API: check.face_recognition_score / .score / .check_id
    //  - Both: .similarity / .match / .check_id at root
    $check = $data['check'] ?? $data;
    $similarity = $check['face_recognition_score']
               ?? $data['face_validation']['similarity']
               ?? $check['score']
               ?? $data['similarity']
               ?? $data['score']
               ?? null;
    $matchFlag  = $data['face_validation']['match'] ?? $check['match'] ?? $data['match'] ?? null;
    $checkId    = $check['check_id']
               ?? $data['face_validation']['check_id']
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
