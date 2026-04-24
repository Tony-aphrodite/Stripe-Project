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

// Environment mode: "test" or "live"
if (!defined('APP_ENV')) {
    $appEnv = strtolower(trim(getenv('APP_ENV') ?: 'test'));
    define('APP_ENV', in_array($appEnv, ['live', 'production']) ? 'live' : 'test');
}
$_isLive = (APP_ENV === 'live');

// Stripe — select keys based on APP_ENV
if (!defined('STRIPE_SECRET_KEY')) {
    $sk = $_isLive
        ? (getenv('STRIPE_SECRET_KEY_LIVE') ?: '')
        : (getenv('STRIPE_SECRET_KEY_TEST') ?: getenv('STRIPE_SECRET_KEY') ?: '');
    define('STRIPE_SECRET_KEY', $sk);
}
if (!defined('STRIPE_PUBLISHABLE_KEY')) {
    $pk = $_isLive
        ? (getenv('STRIPE_PUBLISHABLE_KEY_LIVE') ?: '')
        : (getenv('STRIPE_PUBLISHABLE_KEY_TEST') ?: getenv('STRIPE_PUBLISHABLE_KEY') ?: '');
    define('STRIPE_PUBLISHABLE_KEY', $pk);
}
if (!defined('STRIPE_WEBHOOK_SECRET')) {
    $wh = $_isLive
        ? (getenv('STRIPE_WEBHOOK_SECRET_LIVE') ?: '')
        : (getenv('STRIPE_WEBHOOK_SECRET_TEST') ?: getenv('STRIPE_WEBHOOK_SECRET') ?: 'whsec_PLACEHOLDER');
    define('STRIPE_WEBHOOK_SECRET', $wh);
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
// Shared secret for verifying Truora webhook signatures. Configured in the
// Truora dashboard when creating the webhook subscription.
if (!defined('TRUORA_WEBHOOK_SECRET')) {
    define('TRUORA_WEBHOOK_SECRET', getenv('TRUORA_WEBHOOK_SECRET') ?: '');
}
// Digital Identity Flow ID (IPFxxxxxx...). Created in Truora dashboard →
// Products → Digital Identity → My Flows. Required for the iframe
// integration in truora-token.php.
if (!defined('TRUORA_FLOW_ID')) {
    define('TRUORA_FLOW_ID', getenv('TRUORA_FLOW_ID') ?: '');
}
// Digital Identity API base URL — separate from api.checks.truora.com
// (which is the Background Checks product).
if (!defined('TRUORA_IDENTITY_API_URL')) {
    define('TRUORA_IDENTITY_API_URL', getenv('TRUORA_IDENTITY_API_URL') ?: 'https://api.identity.truora.com');
}
// Voltika public base URL (used as the Truora redirect_url). Falls back to
// production so dev deployments still get a sensible default.
if (!defined('VOLTIKA_BASE_URL')) {
    define('VOLTIKA_BASE_URL', getenv('VOLTIKA_BASE_URL') ?: 'https://www.voltika.mx');
}

// Círculo de Crédito
if (!defined('CDC_API_KEY')) {
    define('CDC_API_KEY', getenv('CDC_API_KEY') ?: '5WdpF9Eqw7925TFAosGKifwkZ7nDuNUN');
}
// CDC credentials — these go as custom HTTP headers (not Basic Auth).
// Defined centrally so every endpoint that calls CDC (preflight, consultar,
// diagnostics) sees the same values. Without these being defined globally,
// PHP 8+ fatal-errors on "undefined constant CDC_USER" before any code runs.
if (!defined('CDC_USER'))  define('CDC_USER',  getenv('CDC_USER')  ?: '');
if (!defined('CDC_PASS'))  define('CDC_PASS',  getenv('CDC_PASS')  ?: '');
if (!defined('CDC_FOLIO')) define('CDC_FOLIO', getenv('CDC_FOLIO') ?: '');

// Envia.com (shipping / tracking)
if (!defined('ENVIA_API_KEY'))  define('ENVIA_API_KEY',  getenv('ENVIA_API_KEY')  ?: '');
if (!defined('ENVIA_CARRIER'))  define('ENVIA_CARRIER',  getenv('ENVIA_CARRIER')  ?: 'estafeta');
if (!defined('ENVIA_SERVICE'))  define('ENVIA_SERVICE',  getenv('ENVIA_SERVICE')  ?: 'standard');

// Skydropx (shipping quotes + labels)
if (!defined('SKYDROPX_API_KEY')) define('SKYDROPX_API_KEY', getenv('SKYDROPX_API_KEY') ?: 'XdkfMZOHYt4S8LJSNAUViThofrzsj4tgOvGfczTJbis');
// Default parcel dimensions for moto shipments (kg / cm)
if (!defined('SKYDROPX_PARCEL_WEIGHT')) define('SKYDROPX_PARCEL_WEIGHT', 150);
if (!defined('SKYDROPX_PARCEL_HEIGHT')) define('SKYDROPX_PARCEL_HEIGHT', 120);
if (!defined('SKYDROPX_PARCEL_WIDTH'))  define('SKYDROPX_PARCEL_WIDTH',  80);
if (!defined('SKYDROPX_PARCEL_LENGTH')) define('SKYDROPX_PARCEL_LENGTH', 200);
// CEDIS origin address (configure in .env)
if (!defined('CEDIS_NOMBRE'))   define('CEDIS_NOMBRE',   getenv('CEDIS_NOMBRE')   ?: 'Voltika CEDIS');
if (!defined('CEDIS_TELEFONO')) define('CEDIS_TELEFONO', getenv('CEDIS_TELEFONO') ?: '5512345678');
if (!defined('CEDIS_CALLE'))    define('CEDIS_CALLE',    getenv('CEDIS_CALLE')    ?: '');
if (!defined('CEDIS_NUMERO'))   define('CEDIS_NUMERO',   getenv('CEDIS_NUMERO')   ?: '');
if (!defined('CEDIS_CIUDAD'))   define('CEDIS_CIUDAD',   getenv('CEDIS_CIUDAD')   ?: '');
if (!defined('CEDIS_ESTADO'))   define('CEDIS_ESTADO',   getenv('CEDIS_ESTADO')   ?: '');
if (!defined('CEDIS_CP'))       define('CEDIS_CP',       getenv('CEDIS_CP')       ?: '');

// Cincel (NOM-151 Digital Signature)
if (!defined('CINCEL_API_URL'))  define('CINCEL_API_URL',  getenv('CINCEL_API_URL')  ?: 'https://api.cincel.digital/v3');
if (!defined('CINCEL_EMAIL'))    define('CINCEL_EMAIL',    getenv('CINCEL_EMAIL')    ?: 'test@riactor.com');
if (!defined('CINCEL_PASSWORD')) define('CINCEL_PASSWORD', getenv('CINCEL_PASSWORD') ?: 'Prueba2026_');

// WhatsApp (Meta Cloud API)
if (!defined('WHATSAPP_API_TOKEN')) define('WHATSAPP_API_TOKEN', getenv('WHATSAPP_API_TOKEN') ?: '');
if (!defined('WHATSAPP_PHONE_ID')) define('WHATSAPP_PHONE_ID', getenv('WHATSAPP_PHONE_ID') ?: '');

// Cron Jobs
if (!defined('VOLTIKA_CRON_TOKEN')) define('VOLTIKA_CRON_TOKEN', getenv('VOLTIKA_CRON_TOKEN') ?: '');
if (!defined('VOLTIKA_BASE_URL'))   define('VOLTIKA_BASE_URL',   getenv('VOLTIKA_BASE_URL')   ?: 'https://voltika.mx');

// ── Shared DB connection ────────────────────────────────────────────────────
// Guarded because admin/php/bootstrap.php already declares getDB(); if admin
// code pulls in this file transitively (e.g. via voltika-notify.php), a
// redeclare fatal crashed the request with a 500.
if (!function_exists('getDB')) {
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
}

// ── Shared PHPMailer helper ─────────────────────────────────────────────────
// Guarded to avoid "Cannot redeclare" fatals when this file is pulled in
// transitively from admin code that already declared sendMail().
if (!function_exists('sendMail')) {
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
