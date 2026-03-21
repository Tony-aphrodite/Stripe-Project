<?php
/**
 * Voltika - Central Configuration
 * Loads .env variables and provides shared helpers (DB, SMTP, etc.)
 * Include this file at the top of every PHP endpoint.
 */

// Prevent direct access
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'config.php') {
    http_response_code(403);
    exit('Forbidden');
}

// ── Load .env ────────────────────────────────────────────────────────────────
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        putenv($line);
    }
}

// ── Constants ────────────────────────────────────────────────────────────────

// Stripe
if (!defined('STRIPE_SECRET_KEY')) {
    define('STRIPE_SECRET_KEY', getenv('STRIPE_SECRET_KEY') ?: '');
}

// Database
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'voltika_');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'voltika');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: 'Lemon2022;');

// SMTP
if (!defined('SMTP_HOST')) define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.ionos.mx');
if (!defined('SMTP_PORT')) define('SMTP_PORT', intval(getenv('SMTP_PORT') ?: 465));
if (!defined('SMTP_USER')) define('SMTP_USER', getenv('SMTP_USER') ?: 'voltika@riactor.com');
if (!defined('SMTP_PASS')) define('SMTP_PASS', getenv('SMTP_PASS') ?: 'Lemon2022;');

// SMSMasivos
if (!defined('SMSMASIVOS_API_KEY')) {
    define('SMSMASIVOS_API_KEY', getenv('SMSMASIVOS_API_KEY') ?: 'ff4cca0aee49e64c91465559c9ced18d785d838c');
}

// Truora
if (!defined('TRUORA_API_KEY')) {
    define('TRUORA_API_KEY', getenv('TRUORA_API_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhY2NvdW50X2lkIjoiIiwiYWRkaXRpb25hbF9kYXRhIjoie30iLCJhcHBsaWNhdGlvbl9pZCI6IiIsImNsaWVudF9pZCI6IlRDSTc0NTkxNzg2NDA1NzYzZTMxZjFlODllYjY3NjY2NGEyIiwiZXhwIjozMzQ5Mjc4Mjg5LCJncmFudCI6IiIsImlhdCI6MTc3MjQ3ODI4OSwiaXNzIjoiaHR0cHM6Ly9jb2duaXRvLWlkcC51cy1lYXN0LTEuYW1hem9uYXdzLmNvbS91cy1lYXN0LTFfUmJvQ2lFd01nIiwianRpIjoiMDM3NTdlMjYtMTc5Yi00YTc4LWI0ZjEtMWYxOTE0YTI3NmM2Iiwia2V5X25hbWUiOiJwcnVlYmEiLCJrZXlfdHlwZSI6ImJhY2tlbmQiLCJ1c2VybmFtZSI6IlRDSTc0NTkxNzg2NDA1NzYzZTMxZjFlODllYjY3NjY2NGEyLXBydWViYSJ9.xL1w6VcjOCI5HqNijvWEj6dGjScUXRVouPkoueKCKs8');
}

// Círculo de Crédito
if (!defined('CDC_API_KEY')) {
    define('CDC_API_KEY', getenv('CDC_API_KEY') ?: '5WdpF9Eqw7925TFAosGKifwkZ7nDuNUN');
}

// Cincel (NOM-151 Digital Signature)
if (!defined('CINCEL_API_URL'))  define('CINCEL_API_URL',  getenv('CINCEL_API_URL')  ?: 'https://sandbox.api.cincel.digital/v3');
if (!defined('CINCEL_EMAIL'))    define('CINCEL_EMAIL',    getenv('CINCEL_EMAIL')    ?: 'test@riactor.com');
if (!defined('CINCEL_PASSWORD')) define('CINCEL_PASSWORD', getenv('CINCEL_PASSWORD') ?: 'Prueba2026_');

// ── Shared DB connection ────────────────────────────────────────────────────
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    return $pdo;
}

// ── Shared PHPMailer helper ─────────────────────────────────────────────────
function sendMail($to, $toName, $subject, $htmlBody) {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) require_once $autoload;

    $sent = false;

    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->SMTPAuth   = true;
            $mail->Host       = SMTP_HOST;
            $mail->Port       = SMTP_PORT;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $mail->setFrom(SMTP_USER, 'Voltika México');
            $mail->addAddress($to, $toName);
            $mail->addAddress('redes@voltika.com.mx');
            $mail->CharSet    = 'UTF-8';
            $mail->Encoding   = 'base64';
            $mail->isHTML(true);
            $mail->Subject    = $subject;
            $mail->Body       = $htmlBody;
            $mail->AltBody    = strip_tags($htmlBody);
            $mail->send();
            $sent = true;
        } catch (Exception $e) {
            error_log('Voltika PHPMailer error: ' . $e->getMessage());
        }
    }

    // Fallback: PHP mail()
    if (!$sent && !empty($to)) {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: Voltika México <" . SMTP_USER . ">\r\n";
        $headers .= "Bcc: redes@voltika.com.mx\r\n";
        $sent = @mail($to, $subject, $htmlBody, $headers);
    }

    return $sent;
}

// ── Ensure logs directory exists ────────────────────────────────────────────
$logsDir = __DIR__ . '/logs';
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0755, true);
}

// ── Ensure uploads directory exists ─────────────────────────────────────────
$uploadsDir = __DIR__ . '/uploads';
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}
