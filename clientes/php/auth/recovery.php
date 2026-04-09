<?php
/**
 * Voltika Portal - Access Recovery (6-step flow)
 * Single endpoint, dispatches on ?step=N
 *  1 = email submit → send email OTP
 *  2 = verify email OTP
 *  3 = verify apellido paterno
 *  4 = verify fecha nacimiento
 *  5 = submit new phone → send SMS OTP
 *  6 = verify new phone OTP → update
 */
require_once __DIR__ . '/../bootstrap.php';

$step = intval($_GET['step'] ?? ($_POST['step'] ?? 0));
$in = portalJsonIn();
if (!$step && isset($in['step'])) $step = (int)$in['step'];

$rec = $_SESSION['portal_recovery'] ?? [];

function recoveryLog(string $ev, array $extra) { portalLog('recovery_' . $ev, $extra); }

switch ($step) {

case 1: // email → send email OTP
    $email = strtolower(trim($in['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) portalJsonOut(['error' => 'Correo inválido'], 400);
    $cliente = portalFindClienteByEmail($email);
    if (!$cliente) {
        recoveryLog('email_not_found', ['email' => $email, 'success' => 0]);
        portalJsonOut(['error' => 'No encontramos una cuenta con ese correo'], 404);
    }
    $codigo = portalGenOTP();
    $_SESSION['portal_recovery'] = [
        'email' => $email,
        'cliente_id' => (int)$cliente['id'],
        'email_otp' => $codigo,
        'email_otp_exp' => time() + 600,
        'stage' => 1,
    ];
    $body = "<p>Hola,</p><p>Tu código de recuperación de Voltika es:</p><h2>{$codigo}</h2><p>Válido por 10 minutos.</p>";
    $sent = sendMail($email, '', 'Tu código de recuperación Voltika', $body);
    recoveryLog('email_sent', ['email' => $email, 'cliente_id' => (int)$cliente['id'], 'success' => $sent ? 1 : 0]);
    $out = ['status' => 'sent'];
    if (!$sent) $out['testCode'] = $codigo;
    portalJsonOut($out);
    break;

case 2: // verify email OTP
    if (empty($rec) || ($rec['stage'] ?? 0) < 1) portalJsonOut(['error' => 'Flujo inválido'], 400);
    $cod = preg_replace('/\D/', '', $in['codigo'] ?? '');
    if (time() > ($rec['email_otp_exp'] ?? 0)) portalJsonOut(['error' => 'Código expirado'], 400);
    if ($rec['email_otp'] !== $cod) {
        recoveryLog('email_otp_fail', ['email' => $rec['email'], 'success' => 0]);
        portalJsonOut(['error' => 'Código incorrecto'], 400);
    }
    $_SESSION['portal_recovery']['stage'] = 2;
    recoveryLog('email_otp_ok', ['email' => $rec['email'], 'cliente_id' => $rec['cliente_id'], 'success' => 1]);
    portalJsonOut(['status' => 'ok']);
    break;

case 3: // verify apellido paterno
    if (empty($rec) || ($rec['stage'] ?? 0) < 2) portalJsonOut(['error' => 'Flujo inválido'], 400);
    $ap = strtoupper(trim($in['apellidoPaterno'] ?? ''));
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT apellido_paterno FROM clientes WHERE id = ?");
    $stmt->execute([$rec['cliente_id']]);
    $real = strtoupper(trim((string)$stmt->fetchColumn()));
    if (!$real || $real !== $ap) {
        recoveryLog('apellido_fail', ['cliente_id' => $rec['cliente_id'], 'success' => 0]);
        portalJsonOut(['error' => 'Dato incorrecto'], 400);
    }
    $_SESSION['portal_recovery']['stage'] = 3;
    portalJsonOut(['status' => 'ok']);
    break;

case 4: // verify fecha nacimiento
    if (empty($rec) || ($rec['stage'] ?? 0) < 3) portalJsonOut(['error' => 'Flujo inválido'], 400);
    $fn = trim($in['fechaNacimiento'] ?? '');
    // Accept dd/mm/yyyy or yyyy-mm-dd
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $fn, $m)) $fn = "{$m[3]}-{$m[2]}-{$m[1]}";
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT fecha_nacimiento FROM clientes WHERE id = ?");
    $stmt->execute([$rec['cliente_id']]);
    $real = substr((string)$stmt->fetchColumn(), 0, 10);
    if (!$real || $real !== $fn) {
        recoveryLog('dob_fail', ['cliente_id' => $rec['cliente_id'], 'success' => 0]);
        portalJsonOut(['error' => 'Dato incorrecto'], 400);
    }
    $_SESSION['portal_recovery']['stage'] = 4;
    portalJsonOut(['status' => 'ok']);
    break;

case 5: // new phone → SMS OTP
    if (empty($rec) || ($rec['stage'] ?? 0) < 4) portalJsonOut(['error' => 'Flujo inválido'], 400);
    $tel = portalNormPhone($in['telefono'] ?? '');
    if (strlen($tel) < 10) portalJsonOut(['error' => 'Teléfono inválido'], 400);
    $codigo = portalGenOTP();
    $_SESSION['portal_recovery']['new_phone'] = $tel;
    $_SESSION['portal_recovery']['phone_otp'] = $codigo;
    $_SESSION['portal_recovery']['phone_otp_exp'] = time() + 600;
    $_SESSION['portal_recovery']['stage'] = 5;
    $r = portalSendSMS($tel, "Voltika: Tu codigo para actualizar numero es {$codigo}");
    recoveryLog('new_phone_sms', ['cliente_id' => $rec['cliente_id'], 'new_phone' => $tel, 'success' => $r['ok'] ? 1 : 0]);
    $out = ['status' => 'sent'];
    if (!$r['ok']) $out['testCode'] = $codigo;
    portalJsonOut($out);
    break;

case 6: // verify new phone OTP → update
    if (empty($rec) || ($rec['stage'] ?? 0) < 5) portalJsonOut(['error' => 'Flujo inválido'], 400);
    $cod = preg_replace('/\D/', '', $in['codigo'] ?? '');
    if (time() > ($rec['phone_otp_exp'] ?? 0)) portalJsonOut(['error' => 'Código expirado'], 400);
    if ($rec['phone_otp'] !== $cod) {
        recoveryLog('new_phone_fail', ['cliente_id' => $rec['cliente_id'], 'success' => 0]);
        portalJsonOut(['error' => 'Código incorrecto'], 400);
    }
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT telefono FROM clientes WHERE id = ?");
    $stmt->execute([$rec['cliente_id']]);
    $old = (string)$stmt->fetchColumn();
    $pdo->prepare("UPDATE clientes SET telefono = ? WHERE id = ?")
        ->execute([$rec['new_phone'], $rec['cliente_id']]);
    $pdo->prepare("UPDATE subscripciones_credito SET telefono = ? WHERE cliente_id = ?")
        ->execute([$rec['new_phone'], $rec['cliente_id']]);
    recoveryLog('phone_updated', [
        'cliente_id' => $rec['cliente_id'],
        'old_phone' => $old, 'new_phone' => $rec['new_phone'],
        'validation_method' => 'email+apellido+dob+sms',
        'success' => 1,
    ]);
    // Auto-login
    $_SESSION['portal_cliente_id'] = (int)$rec['cliente_id'];
    unset($_SESSION['portal_recovery']);
    portalJsonOut(['status' => 'ok']);
    break;

default:
    portalJsonOut(['error' => 'Paso inválido'], 400);
}
