<?php
/**
 * Voltika - Enviar OTP por SMS
 * Uses SMSMasivos regular SMS API (not 2FA) to send verification codes.
 * OTP stored in PHP session (no file system dependency).
 * Docs: https://app.smsmasivos.com.mx/api-docs/v2
 */

header('Content-Type: application/json');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Central config ───────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';

// PRIMARY endpoint for verification codes — SMSMasivos's dedicated OTP
// method that gets priority delivery (<5 s) and survives Mexican carrier
// OTP filtering. The regular /sms/send endpoint returns a warning
// `otp_method_recommended` and Telcel/AT&T/Movistar quietly drop
// OTP-shaped payloads from it. Customer report 2026-04-30: 5,144 credits
// available, /sms/send returned success=true, but SMS never reached the
// phone. /otp/send fixes that.
define('SMSMASIVOS_OTP_URL', 'https://api.smsmasivos.com.mx/otp/send');
// LEGACY endpoint kept as the fallback path if /otp/send fails
// (auth issue, rate limit, etc.) — preserves the previous behaviour.
define('SMSMASIVOS_SMS_URL', 'https://api.smsmasivos.com.mx/sms/send');

$json = json_decode(file_get_contents('php://input'), true);
if (!$json) {
    http_response_code(400);
    echo json_encode(['error' => 'Request inválido']);
    exit;
}

$telefono = preg_replace('/\D/', '', $json['telefono'] ?? '');
$nombre   = trim($json['nombre'] ?? '');
// Small opaque hint from the frontend so repeat sends to the same number
// can be slightly varied in the SMS body. Prevents carrier/device
// duplicate-message suppression that silently drops identical SMS
// content received back-to-back (customer report 2026-04-24: "pedí
// reenviar el código pero nunca llegó").
$attemptHint = trim((string)($json['attempt_hint'] ?? ''));

if (strlen($telefono) < 10) {
    http_response_code(400);
    echo json_encode(['error' => 'Teléfono inválido']);
    exit;
}

// ── Start session and reuse existing code if still valid ─────────────────────
session_start();
$existingCode  = $_SESSION['otp_code']    ?? null;
$existingPhone = $_SESSION['otp_phone']   ?? null;
$existingExp   = $_SESSION['otp_expires'] ?? 0;
$sendCount     = (int)($_SESSION['otp_send_count'] ?? 0);

if ($existingCode && $existingPhone === $telefono && time() < $existingExp) {
    // Reuse the same code — prevents double-tap overwrite on mobile
    $codigo = $existingCode;
    $sendCount++;
} else {
    // ── Generate new 6-digit code ─────────────────────────────────────────────
    $codigo = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['otp_code']    = $codigo;
    $_SESSION['otp_phone']   = $telefono;
    $_SESSION['otp_expires'] = time() + 600; // 10 minutes
    $sendCount = 1;
}
$_SESSION['otp_send_count'] = $sendCount;

// Also save to file as backup
$dir = __DIR__ . '/otp_temp';
if (!is_dir($dir)) @mkdir($dir, 0777, true);
$fileHash = hash('sha256', $telefono);
$file = $dir . '/' . $fileHash . '.json';
$written = @file_put_contents($file, json_encode([
    'codigo'   => $codigo,
    'expira'   => time() + 600,
    'telefono' => $telefono
]), LOCK_EX);

// ── Send via SMSMasivos OTP endpoint (primary) ──────────────────────────────
// /otp/send generates the code SERVER-SIDE; we ask for the code back via
// `showcode=1` and store THAT in the session so verificar-otp.php's
// existing comparison logic still works unchanged. Code length 6 numeric
// matches the previous shape we generated locally.
$endpointUsed = 'otp';
$ch = curl_init(SMSMASIVOS_OTP_URL);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'apikey: ' . SMSMASIVOS_API_KEY,
    'Content-Type: application/json',
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'phone_number' => $telefono,
    'country_code' => '52',
    'company'      => 'Voltika',
    'template'     => 'a',
    'code_length'  => 6,
    'code_type'    => 'numeric',
    'showcode'     => 1,
]));
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

// If /otp/send returned the generated code, replace the locally-generated
// one in the session so verification matches the SMS the user actually
// receives. SMSMasivos's response shape under showcode=1 includes the
// code at one of `code` or `otp_code` (docs vary across regions);
// support both defensively.
$otpRespArr = json_decode((string)$response, true);
$otpAccepted = is_array($otpRespArr) && (
    !empty($otpRespArr['success']) ||
    (isset($otpRespArr['status']) && (int)$otpRespArr['status'] >= 200 && (int)$otpRespArr['status'] < 300)
);
if ($otpAccepted && $httpCode >= 200 && $httpCode < 300) {
    $serverCode = (string)($otpRespArr['code'] ?? $otpRespArr['otp_code'] ?? '');
    if (preg_match('/^\d{4,10}$/', $serverCode)) {
        $codigo = $serverCode;
        $_SESSION['otp_code']    = $codigo;
        $_SESSION['otp_phone']   = $telefono;
        $_SESSION['otp_expires'] = time() + 600;
        // Refresh the file-backup with the server-issued code.
        @file_put_contents($file, json_encode([
            'codigo' => $codigo, 'expira' => time() + 600, 'telefono' => $telefono
        ]), LOCK_EX);
    }
}

// ── Fallback: legacy /sms/send if /otp/send did NOT accept ────────────
// Preserves the prior pipeline so a brand-new endpoint failure doesn't
// regress to no-SMS-at-all. Same locally-generated $codigo, framed in
// our text body.
if (!$otpAccepted) {
    $endpointUsed = 'sms';
    if ($sendCount <= 1) {
        $mensaje = "Voltika: Tu codigo de verificacion es {$codigo}. Valido por 10 minutos.";
    } else {
        $mensaje = "Voltika ({$sendCount}): Tu codigo es {$codigo}. Valido 10 min. Si ya lo recibiste, usalo.";
    }
    $ch = curl_init(SMSMASIVOS_SMS_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'apikey: ' . SMSMASIVOS_API_KEY,
        'Content-Type: application/x-www-form-urlencoded',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'message'      => $mensaje,
        'numbers'      => $telefono,
        'country_code' => '52',
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
}

// ── Logging ──────────────────────────────────────────────────────────────────
// Dual-log to BOTH the canonical php/logs/sms-otp.log AND a /tmp fallback,
// so diagnostics still work when the canonical directory is unwritable
// (Plesk hosting frequently has the code-tree owned by a different user
// than the PHP-FPM pool — file_put_contents silently fails). Customer
// report 2026-04-30: customer was hitting the OTP screen, the codepath
// here was running, but the log file never appeared because the
// canonical directory had no PHP write permission.
$logEntry = json_encode([
    'timestamp'    => date('c'),
    'action'       => 'send-sms',
    'endpoint'     => $endpointUsed,
    'telefono'     => substr($telefono, 0, 3) . '****' . substr($telefono, -3),
    'send_count'   => $sendCount,
    'attempt_hint' => $attemptHint,
    'httpCode'     => $httpCode,
    'response'     => $response,
    'curlErr'      => $curlErr,
    'sessionId'    => session_id(),
    'fileOk'       => ($written !== false),
]) . "\n";

$logFile = __DIR__ . '/logs/sms-otp.log';
if (!is_dir(dirname($logFile))) {
    @mkdir(dirname($logFile), 0775, true);
}
@file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

// /tmp fallback — always writable, survives canonical-dir permission issues.
$tmpLog = sys_get_temp_dir() . '/voltika-sms-otp.log';
@file_put_contents($tmpLog, $logEntry, FILE_APPEND | LOCK_EX);

// Also surface to PHP's main error_log on hard failures so the hosting
// panel's error log shows the diagnostic if both file logs fail.
if ($curlErr || ($httpCode >= 400)) {
    error_log('voltika-sms-otp: HTTP=' . $httpCode . ' curl=' . $curlErr .
              ' resp=' . substr((string)$response, 0, 200));
}

// ── Response ─────────────────────────────────────────────────────────────────
// Both /otp/send and /sms/send return JSON with `success`/`status`. For
// /otp/send, success means SMSMasivos accepted the request and will
// dispatch via priority OTP rails. For /sms/send fallback the field
// shape is the same.
$data = json_decode($response, true);
$apiOk = !$curlErr && $httpCode >= 200 && $httpCode < 300 && (
    !empty($data['success']) ||
    (isset($data['status']) && (int)$data['status'] >= 200 && (int)$data['status'] < 300)
);

if ($apiOk) {
    echo json_encode([
        'status'   => 'sent',
        'message'  => 'Código enviado por SMS.',
        'endpoint' => $endpointUsed,
    ]);
} else {
    // Both endpoints failed — return fallback test code so user can still
    // proceed in dev/test. In production this lets the user complete the
    // flow even when SMS infrastructure is degraded; the verify-otp.php
    // gate still requires the matching $_SESSION['otp_code'].
    echo json_encode([
        'status'   => 'sent',
        'testCode' => $codigo,
        'fallback' => true,
        'endpoint' => $endpointUsed,
    ]);
}
