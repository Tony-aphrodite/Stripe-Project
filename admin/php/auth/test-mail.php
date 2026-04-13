<?php
/**
 * TEMPORARY — SMTP diagnostic endpoint. DELETE after debugging.
 */
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: text/plain; charset=UTF-8');

echo "=== SMTP DIAGNOSTIC ===\n\n";

// 1. Check vendor autoload
$autoload = realpath(__DIR__ . '/../../../configurador_prueba/php/vendor/autoload.php');
echo "1. Vendor autoload path: " . ($autoload ?: 'NOT FOUND') . "\n";
echo "   File exists: " . (file_exists(__DIR__ . '/../../../configurador_prueba/php/vendor/autoload.php') ? 'YES' : 'NO') . "\n\n";

if ($autoload && file_exists($autoload)) {
    require_once $autoload;
}

// 2. Check PHPMailer class
echo "2. PHPMailer class exists: " . (class_exists('PHPMailer\PHPMailer\PHPMailer') ? 'YES' : 'NO') . "\n\n";

// 3. SMTP config
echo "3. SMTP Config:\n";
echo "   Host: " . SMTP_HOST . "\n";
echo "   Port: " . SMTP_PORT . "\n";
echo "   User: " . SMTP_USER . "\n";
echo "   Pass: " . str_repeat('*', max(0, strlen(SMTP_PASS) - 3)) . substr(SMTP_PASS, -3) . "\n\n";

// 4. Try sending with full debug
if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    echo "4. Attempting SMTP send with DEBUG...\n\n";

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->SMTPAuth   = true;
        $mail->Host       = SMTP_HOST;
        $mail->Port       = SMTP_PORT;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;

        // Enable verbose debug output
        $mail->SMTPDebug  = 2; // 2 = client + server messages
        $mail->Debugoutput = function($str, $level) {
            echo "   [SMTP $level] $str\n";
        };

        $mail->setFrom(SMTP_USER, 'Voltika Test');
        $mail->addAddress('johnsontakashi45@gmail.com', 'Test');
        $mail->CharSet  = 'UTF-8';
        $mail->isHTML(true);
        $mail->Subject  = 'Voltika SMTP Test - ' . date('H:i:s');
        $mail->Body     = '<h2>SMTP Test</h2><p>If you see this, SMTP works. Time: ' . date('Y-m-d H:i:s') . '</p>';
        $mail->AltBody  = 'SMTP Test - ' . date('Y-m-d H:i:s');

        $result = $mail->send();
        echo "\n   Result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";

    } catch (Exception $e) {
        echo "\n   EXCEPTION: " . $e->getMessage() . "\n";
        echo "   ErrorInfo: " . ($mail->ErrorInfo ?? 'N/A') . "\n";
    }
} else {
    echo "4. PHPMailer not available, trying PHP mail()...\n";
    $result = @mail('johnsontakashi45@gmail.com', 'Voltika Test', 'Test mail() ' . date('H:i:s'));
    echo "   mail() result: " . ($result ? 'TRUE' : 'FALSE') . "\n";
}

echo "\n=== END DIAGNOSTIC ===\n";
