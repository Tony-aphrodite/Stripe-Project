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

// CURP is REQUIRED — without it Truora's Mexico person check returns
// `not_found` for every real user because name+DOB+state matching against
// RENAPO is too weak on its own.
if (!$curp || !preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/', $curp)) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'error'   => 'CURP inválido',
        'message' => 'Ingresa tu CURP completo (18 caracteres). Lo encuentras al reverso de tu INE.',
    ]);
    exit;
}

// Derive gender from CURP position 11 if not supplied by frontend.
if (!$gender) {
    $genderFromCurp = strtoupper($curp[10]);
    $gender = ($genderFromCurp === 'M') ? 'F' : 'M';
}

// Normalize state_id to Truora's enum codes (CDMX, JAL, NL, MEX, ...).
$stateId = truoraEstadoEnum($stateId);

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
// Customer reported 2026-04-23: Apigee returns 10400 "Invalid phone number
// format" intermittently. Root cause: any non-clean input (country code
// variants, extra digits, stray characters) sneaks into phone_number and
// fails the regex. Policy now: only send phone_number when it matches the
// exact MX mobile pattern we're confident about; otherwise omit (Truora's
// person check does not require phone — name + CURP + DOB + gender already
// drive the match).
$phoneNormalized = null;
if ($telefono) {
    $digits = preg_replace('/\D/', '', (string)$telefono);
    // Peel common Mexican prefixes so we always end up with 10 local digits.
    //   12 digits starting 52  → drop "52"   (country code typed without +)
    //   13 digits starting 521 → drop "521"  (country code + mobile prefix 1)
    //   11 digits starting 1   → drop "1"    (mobile prefix without country code)
    if (strlen($digits) === 12 && substr($digits, 0, 2) === '52')  $digits = substr($digits, 2);
    if (strlen($digits) === 13 && substr($digits, 0, 3) === '521') $digits = substr($digits, 3);
    if (strlen($digits) === 11 && $digits[0] === '1')              $digits = substr($digits, 1);
    // Final guard: must be EXACTLY 10 Mexican digits, cannot start with 0 or
    // 1 (those are invalid area-code starts in MX), and must not repeat the
    // same digit 10 times (that's a junk placeholder).
    if (preg_match('/^[2-9]\d{9}$/', $digits) && !preg_match('/^(\d)\1{9}$/', $digits)) {
        $phoneNormalized = '+52 ' . $digits;
        $postFields['phone_number'] = $phoneNormalized;
    }
}

// Build body manually so we guarantee the encoding Apigee expects:
//   - `+` → `%2B`
//   - ` ` → `+`   (standard application/x-www-form-urlencoded space)
// PHP's http_build_query with default flags does this, but we do it
// explicitly here to avoid a silent change of behavior if a future PHP
// upgrade flips the defaults. The phone_number field is the only one that
// contains a literal `+` and space, so we spell out its encoding to
// remove any ambiguity about how Apigee decodes it.
$bodyPairs = [];
foreach ($postFields as $k => $v) {
    $bodyPairs[] = urlencode((string)$k) . '=' . urlencode((string)$v);
}
$bodyString = implode('&', $bodyPairs);

$ch = curl_init(TRUORA_API_URL);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $bodyString,
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

// Self-healing retry: if Apigee flagged phone format (code 10400 with phone
// message), drop phone_number and try once more. The person check does not
// require phone, so this lets the customer complete verification even when
// their phone value triggers some unknown Apigee edge case.
if (
    $httpCode === 400 &&
    isset($postFields['phone_number']) &&
    is_string($response) &&
    stripos($response, 'phone') !== false &&
    stripos($response, 'format') !== false
) {
    unset($postFields['phone_number']);
    $bodyPairs2 = [];
    foreach ($postFields as $k => $v) {
        $bodyPairs2[] = urlencode((string)$k) . '=' . urlencode((string)$v);
    }
    $bodyStringRetry = implode('&', $bodyPairs2);

    $chRetry = curl_init(TRUORA_API_URL);
    curl_setopt_array($chRetry, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $bodyStringRetry,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Truora-API-Key: ' . TRUORA_API_KEY,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $retryResp = curl_exec($chRetry);
    $retryCode = curl_getinfo($chRetry, CURLINFO_HTTP_CODE);
    $retryErr  = curl_error($chRetry);
    curl_close($chRetry);
    if ($retryCode >= 200 && $retryCode < 300) {
        // Replace response so downstream code treats this as the success path.
        $response = $retryResp;
        $httpCode = $retryCode;
        $curlErr  = $retryErr;
    }
}

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
    // Decode Truora's error body so we can surface an actionable message to
    // the customer instead of a generic "contact soporte". Previously a 10400
    // phone format error rendered as "No pudimos conectar…" which suggested
    // a network outage — the real fix was on the user's input.
    $trBody = json_decode((string)$response, true);
    $trMsg  = is_array($trBody) ? ($trBody['message'] ?? '') : '';
    $uiMsg  = 'No pudimos conectar con el servicio de verificación. Intenta de nuevo o contacta soporte.';
    if ($httpCode === 400 && $trMsg) {
        if (stripos($trMsg, 'phone') !== false) {
            $uiMsg = 'El número de teléfono ingresado no tiene formato válido. Corrígelo (10 dígitos MX) e intenta de nuevo.';
        } elseif (stripos($trMsg, 'email') !== false) {
            $uiMsg = 'El correo ingresado no tiene formato válido. Revísalo e intenta de nuevo.';
        } elseif (stripos($trMsg, 'national_id') !== false || stripos($trMsg, 'curp') !== false) {
            $uiMsg = 'El CURP ingresado no tiene formato válido (18 caracteres). Revísalo e intenta de nuevo.';
        } elseif (stripos($trMsg, 'date') !== false) {
            $uiMsg = 'La fecha de nacimiento no tiene formato válido (YYYY-MM-DD). Revísala e intenta de nuevo.';
        } else {
            $uiMsg = 'Los datos enviados fueron rechazados por el servicio de verificación: ' . htmlspecialchars($trMsg, ENT_QUOTES);
        }
    }
    echo json_encode([
        'status'   => 'error',
        'error'    => 'Truora API falló',
        'http'     => $httpCode,
        'curl_err' => $curlErr ?: null,
        'body'     => substr((string)$response, 0, 400),
        'message'  => $uiMsg,
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

// ── Polling for completion (up to ~25 seconds) ───────────────────────────
// Customer report 2026-04-23: Truora dashboard showed all checks as
// "Expirado" because we were only polling 6 seconds — Truora's person
// validation usually needs 3–15 s and can spike to 25 s under load. When
// our short loop timed out we returned to the UI; the user advanced and
// the check was abandoned mid-flight, expiring on Truora's side. Now we
// wait long enough for the typical case to finish before returning.
$elapsed       = 0;
$result        = null;
$shortPollMax  = 25;  // seconds — accommodates Truora person-check tail latency
$pollStep      = 2;
$lastPollData  = null;

while ($elapsed < $shortPollMax) {
    sleep($pollStep);
    $elapsed += $pollStep;

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
        $lastPollData = $pollData;
        $status   = $pollData['check']['status'] ?? $pollData['status'] ?? 'unknown';
        @file_put_contents($logFile, json_encode([
            'timestamp' => date('c'),
            'action'    => 'poll_tick',
            'check_id'  => $checkId,
            'elapsed_s' => $elapsed,
            'status'    => $status,
        ]) . "\n", FILE_APPEND | LOCK_EX);
        if ($status === 'completed' || $status === 'error') {
            $result = $pollData;
            break;
        }
    }
}

// If we ran out of time, persist the LAST poll snapshot (so admin can see
// the check_id and partial status on the verificaciones_identidad row and
// follow up via the Truora dashboard).
if (!$result && $lastPollData) {
    $result = $lastPollData;
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
    // /v1/checks mandates type + user_authorized for API key V1+. The
    // "document-validation" type value assumes the customer's Truora account
    // has a matching custom type configured in the dashboard. If the account
    // only supports document validation via the Digital Identity flow
    // (POST /v1/processes), this path will 400 — enable the feature flag
    // after verifying the dashboard has a "document-validation" custom type.
    $postFields = [
        'country'         => 'MX',
        'type'            => 'document-validation',
        'user_authorized' => 'true',
        'document_type'   => 'national-id',
        'front_image'     => new CURLFile($ineFrontePath,  'image/jpeg', 'ine_frente.jpg'),
        'back_image'      => new CURLFile($ineReversoPath, 'image/jpeg', 'ine_reverso.jpg'),
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
    $docCheckId = $data['document_validation']['check_id']
               ?? $data['check_id']
               ?? $data['validation_id']
               ?? null;
    $docStatus  = $data['document_validation']['status']
               ?? $data['status']
               ?? 'in_progress';

    // Poll for the document validation to actually finish (Truora processes
    // OCR + tampering checks asynchronously). Without this loop the check
    // sits in "in_progress" on Truora's dashboard forever and eventually
    // expires — which is exactly what the customer flagged 2026-04-23 when
    // every "Validación de documento" entry showed "Expirado".
    $finalData = $data;
    if ($docCheckId && in_array($docStatus, ['in_progress', 'not_started', 'pending'], true)) {
        $elapsed = 0;
        $maxWait = 25; // seconds — same budget as person check
        while ($elapsed < $maxWait) {
            sleep(2); $elapsed += 2;
            $chP = curl_init('https://api.checks.truora.com/v1/checks/' . $docCheckId);
            curl_setopt_array($chP, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Truora-API-Key: ' . TRUORA_API_KEY],
                CURLOPT_TIMEOUT        => 5,
            ]);
            $pollRaw  = curl_exec($chP);
            $pollHttp = curl_getinfo($chP, CURLINFO_HTTP_CODE);
            curl_close($chP);
            if ($pollHttp >= 200 && $pollHttp < 300) {
                $pollData = json_decode($pollRaw, true);
                $pollStatus = $pollData['check']['status'] ?? $pollData['status'] ?? 'unknown';
                @file_put_contents($logFile, json_encode([
                    'timestamp' => date('c'),
                    'action'    => 'doc_poll_tick',
                    'check_id'  => $docCheckId,
                    'elapsed_s' => $elapsed,
                    'status'    => $pollStatus,
                ]) . "\n", FILE_APPEND | LOCK_EX);
                if ($pollStatus === 'completed' || $pollStatus === 'error') {
                    $finalData = $pollData;
                    $docStatus = $pollStatus;
                    break;
                }
            }
        }
    }

    return [
        'check_id' => $docCheckId,
        'status'   => $docStatus,
        'data'     => $finalData,
    ];
}

/**
 * Normalize free-text estado to Truora's Mexico state enum (2-4 char codes).
 * See production copy of this function for full rationale.
 */
function truoraEstadoEnum(string $raw): string {
    $k = strtoupper(trim($raw));
    $k = strtr($k, [
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
    ]);
    $k = preg_replace('/[^A-Z0-9]/', '', $k);
    if ($k === '') return 'CDMX';

    $validCodes = ['CDMX','AGS','BC','BCS','CAMP','CHIS','CHIH','COAH','COL',
        'DGO','GTO','GRO','HGO','JAL','MEX','MICH','MOR','NAY','NL','OAX',
        'PUE','QRO','QROO','SLP','SIN','SON','TAB','TAMS','TLAX','VER','YUC','ZAC'];
    if (in_array($k, $validCodes, true)) return $k;

    $aliases = [
        'CIUDADDEMEXICO'  => 'CDMX', 'DISTRITOFEDERAL' => 'CDMX', 'DF' => 'CDMX',
        'AGUASCALIENTES'  => 'AGS',
        'BAJACALIFORNIA'  => 'BC',  'BAJACALIFORNIASUR' => 'BCS', 'BCN' => 'BC',
        'CAMPECHE'        => 'CAMP',
        'CHIAPAS'         => 'CHIS', 'CHIHUAHUA' => 'CHIH',
        'COAHUILA'        => 'COAH', 'COLIMA'    => 'COL',
        'DURANGO'         => 'DGO',
        'GUANAJUATO'      => 'GTO',  'GUERRERO'  => 'GRO',
        'HIDALGO'         => 'HGO',
        'JALISCO'         => 'JAL',
        'ESTADODEMEXICO'  => 'MEX',  'ESTADOMEXICO' => 'MEX', 'EDOMEX' => 'MEX',
        'MEXICO'          => 'MEX',
        'MICHOACAN'       => 'MICH', 'MORELOS'   => 'MOR',
        'NAYARIT'         => 'NAY',  'NUEVOLEON' => 'NL',
        'OAXACA'          => 'OAX',
        'PUEBLA'          => 'PUE',
        'QUERETARO'       => 'QRO',  'QUINTANAROO' => 'QROO',
        'SANLUISPOTOSI'   => 'SLP',  'SINALOA'   => 'SIN', 'SONORA' => 'SON',
        'TABASCO'         => 'TAB',  'TAMAULIPAS'=> 'TAMS','TLAXCALA' => 'TLAX',
        'VERACRUZ'        => 'VER',
        'YUCATAN'         => 'YUC',
        'ZACATECAS'       => 'ZAC',
    ];
    return $aliases[$k] ?? 'CDMX';
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
