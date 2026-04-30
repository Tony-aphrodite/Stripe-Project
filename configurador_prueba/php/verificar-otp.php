<?php
/**
 * Voltika - Verificar OTP
 * Verifies the 6-digit code against SESSION first, then file as fallback.
 * We use the regular SMS API now (not 2FA), so verification is local only.
 *
 * Customer brief 2026-04-30 (legal-evidence enhancement):
 * On successful verification, we now also persist a small audit-trail
 * (timestamp, masked phone, hash of the code, IP, send count) into:
 *   1. $_SESSION['otp_audit']  — read by confirmar-orden.php during PDF gen
 *   2. transacciones.contrato_otp_*  — written when `pedido` is supplied so
 *      a re-download via descargar-contrato.php?regen=1 includes the new
 *      audit row in the contract's REGISTRO DE ACEPTACIÓN ELECTRÓNICA.
 * The audit fields contain NO plaintext OTP — only a SHA-256 hash that
 * proves "this code was validated" without exposing the secret.
 */

require_once __DIR__ . '/config.php';

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

$json = json_decode(file_get_contents('php://input'), true);
if (!$json) {
    http_response_code(400);
    echo json_encode(['error' => 'Request inválido']);
    exit;
}

$codigoIngresado = trim($json['codigo'] ?? '');
$telefono        = preg_replace('/\D/', '', $json['telefono'] ?? '');
// Optional pedido — when supplied, we'll update the matching transaction
// row's contract OTP audit fields so a contract regen reflects the OTP.
$pedidoForAudit  = trim((string)($json['pedido'] ?? ''));

/**
 * Build a lightweight audit-trail snapshot for the OTP that just passed.
 * Stores hash (not the code), masked phone, timestamp, IP. Idempotent.
 */
function _voltikaOtpBuildAudit(string $code, string $telefono): array {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip !== '' && strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
    return [
        'validated_at'   => gmdate('Y-m-d H:i:s'),
        'phone_masked'   => substr($telefono, 0, 3) . '****' . substr($telefono, -3),
        'code_sha256'    => hash('sha256', $code),
        'ip'             => $ip,
        'send_count'     => (int)($_SESSION['otp_send_count'] ?? 1),
        'session_id'     => session_id(),
    ];
}

/**
 * Write the OTP audit onto the transactions row for `pedido`. Lazy-add
 * the columns so older installs don't error. Non-fatal — if the DB is
 * unreachable we still return success to the SPA so the user proceeds.
 */
function _voltikaOtpPersistAudit(string $pedido, array $audit): void {
    if ($pedido === '') return;
    try {
        $pdo = getDB();
        foreach ([
            "ALTER TABLE transacciones ADD COLUMN contrato_otp_validated_at DATETIME NULL",
            "ALTER TABLE transacciones ADD COLUMN contrato_otp_phone_masked VARCHAR(40) NULL",
            "ALTER TABLE transacciones ADD COLUMN contrato_otp_code_sha256 CHAR(64) NULL",
            "ALTER TABLE transacciones ADD COLUMN contrato_otp_ip VARCHAR(64) NULL",
            "ALTER TABLE transacciones ADD COLUMN contrato_otp_send_count INT NULL",
        ] as $ddl) { try { $pdo->exec($ddl); } catch (Throwable $e) {} }
        $pdo->prepare("UPDATE transacciones SET
                contrato_otp_validated    = 1,
                contrato_otp_validated_at = ?,
                contrato_otp_phone_masked = ?,
                contrato_otp_code_sha256  = ?,
                contrato_otp_ip           = ?,
                contrato_otp_send_count   = ?
            WHERE pedido = ? LIMIT 1")
            ->execute([
                $audit['validated_at'],
                $audit['phone_masked'],
                $audit['code_sha256'],
                $audit['ip'],
                $audit['send_count'],
                $pedido,
            ]);
    } catch (Throwable $e) {
        error_log('verificar-otp persist audit: ' . $e->getMessage());
    }
}

if (!$telefono || !$codigoIngresado) {
    echo json_encode(['valido' => false, 'error' => 'Datos incompletos.']);
    exit;
}

$logFile = __DIR__ . '/logs/sms-otp.log';
if (!is_dir(dirname($logFile))) mkdir(dirname($logFile), 0755, true);

// ── Method 1: Verify via PHP SESSION ─────────────────────────────────────────
session_start();
$sessionCode  = $_SESSION['otp_code'] ?? null;
$sessionPhone = $_SESSION['otp_phone'] ?? null;
$sessionExp   = $_SESSION['otp_expires'] ?? 0;

if ($sessionCode && $sessionPhone === $telefono) {
    // Session has OTP for this phone
    file_put_contents($logFile, json_encode([
        'timestamp' => date('c'),
        'action'    => 'verify-session',
        'telefono'  => substr($telefono, 0, 3) . '****' . substr($telefono, -3),
        'expected'  => $sessionCode,
        'received'  => $codigoIngresado,
        'match'     => ($codigoIngresado === $sessionCode),
        'expired'   => (time() > $sessionExp)
    ]) . "\n", FILE_APPEND | LOCK_EX);

    if (time() > $sessionExp) {
        unset($_SESSION['otp_code'], $_SESSION['otp_phone'], $_SESSION['otp_expires']);
        echo json_encode(['valido' => false, 'error' => 'Código expirado. Solicita uno nuevo.']);
        exit;
    }

    if ($codigoIngresado === $sessionCode) {
        // Build + persist OTP audit BEFORE clearing the session so we
        // capture send_count etc. for the legal evidence trail.
        $audit = _voltikaOtpBuildAudit($codigoIngresado, $telefono);
        $_SESSION['otp_audit'] = $audit;
        if ($pedidoForAudit !== '') _voltikaOtpPersistAudit($pedidoForAudit, $audit);

        unset($_SESSION['otp_code'], $_SESSION['otp_phone'], $_SESSION['otp_expires']);
        echo json_encode([
            'valido'         => true,
            'method'         => 'session',
            'validated_at'   => $audit['validated_at'],
            'phone_masked'   => $audit['phone_masked'],
            'audit_recorded' => ($pedidoForAudit !== ''),
        ]);
        exit;
    }

    // Code didn't match via session — don't fall through, return error
    echo json_encode(['valido' => false, 'error' => 'Código incorrecto. Verifica e intenta de nuevo.']);
    exit;
}

// ── Method 2: Fallback to file-based verification ────────────────────────────
$otpDir   = __DIR__ . '/otp_temp';
$codeFile = $otpDir . '/' . hash('sha256', $telefono) . '.json';

if (!file_exists($codeFile)) {
    file_put_contents($logFile, json_encode([
        'timestamp'    => date('c'),
        'action'       => 'verify-file',
        'telefono'     => substr($telefono, 0, 3) . '****' . substr($telefono, -3),
        'result'       => 'no_code_found',
        'sessionEmpty' => ($sessionCode === null),
        'sessionPhone' => $sessionPhone ? (substr($sessionPhone, 0, 3) . '****') : null,
        'dirExists'    => is_dir($otpDir)
    ]) . "\n", FILE_APPEND | LOCK_EX);

    echo json_encode(['valido' => false, 'error' => 'No hay código pendiente. Solicita uno nuevo.']);
    exit;
}

$data           = json_decode(file_get_contents($codeFile), true);
$codigoEsperado = $data['codigo'] ?? null;
$expira         = $data['expira'] ?? 0;

file_put_contents($logFile, json_encode([
    'timestamp' => date('c'),
    'action'    => 'verify-file',
    'telefono'  => substr($telefono, 0, 3) . '****' . substr($telefono, -3),
    'expected'  => $codigoEsperado,
    'received'  => $codigoIngresado,
    'match'     => ($codigoIngresado === $codigoEsperado)
]) . "\n", FILE_APPEND | LOCK_EX);

if (time() > $expira) {
    @unlink($codeFile);
    echo json_encode(['valido' => false, 'error' => 'Código expirado. Solicita uno nuevo.']);
    exit;
}

if ($codigoEsperado && $codigoIngresado === $codigoEsperado) {
    // Same audit trail as the session-based path.
    $audit = _voltikaOtpBuildAudit($codigoIngresado, $telefono);
    $_SESSION['otp_audit'] = $audit;
    if ($pedidoForAudit !== '') _voltikaOtpPersistAudit($pedidoForAudit, $audit);

    @unlink($codeFile);
    echo json_encode([
        'valido'         => true,
        'method'         => 'file',
        'validated_at'   => $audit['validated_at'],
        'phone_masked'   => $audit['phone_masked'],
        'audit_recorded' => ($pedidoForAudit !== ''),
    ]);
} else {
    echo json_encode(['valido' => false, 'error' => 'Código incorrecto. Verifica e intenta de nuevo.']);
}
