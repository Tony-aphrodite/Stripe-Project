<?php
require_once __DIR__ . '/../bootstrap.php';

$in = portalJsonIn();
$tel = portalNormPhone($in['telefono'] ?? '');
if (strlen($tel) < 10) {
    portalLog('login_request', ['telefono' => $tel, 'success' => 0, 'detalle' => 'telefono_invalido']);
    portalJsonOut(['error' => 'Teléfono inválido'], 400);
}

// Whitelisted test numbers bypass the clientes lookup — they auto-upsert a
// synthetic account so QA/staging can exercise the full portal flow without
// seeding a real customer. testCode is always surfaced below for these.
// 5500000099 added 2026-05-08 for the General Corrections (16 bugs) test
// run — see tests/general-corrections/seed-test-data.php. Existing test
// numbers retain their fixed OTP behaviour unchanged.
$TEST_NUMBERS = ['5500000000', '0000000000', '5511112222', '5555555555', '5500000099'];

$cliente = portalFindClienteByPhone($tel);
if (!$cliente) {
    if (in_array($tel, $TEST_NUMBERS, true)) {
        try {
            $pdo = getDB();
            $pdo->prepare("INSERT IGNORE INTO clientes (telefono, nombre, email)
                           VALUES (?, ?, ?)")
                ->execute([$tel, 'Cliente de Prueba', 'test-' . $tel . '@voltika.mx']);
            $fetch = $pdo->prepare("SELECT * FROM clientes WHERE telefono = ? ORDER BY id DESC LIMIT 1");
            $fetch->execute([$tel]);
            $cliente = $fetch->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('portal test cliente upsert: ' . $e->getMessage());
        }
    }
    if (!$cliente) {
        portalLog('login_request', ['telefono' => $tel, 'success' => 0, 'detalle' => 'no_encontrado']);
        portalJsonOut(['error' => 'No encontramos una cuenta con ese número'], 404);
    }
}

// Customer brief 2026-05-07 ("No puedo entrar"): test phones get the
// FIXED OTP 123456 instead of a random code. Without this, the
// whitelisted numbers got a randomly-generated testCode that had to be
// read from the JSON response — too brittle for customers running
// through the portal in their browser. Now the test code matches the
// /configurador/ bypass (also 123456) so customers can use the same
// code across the full flow.
$FIXED_TEST_OTP = '123456';
$isTestNumber = in_array($tel, $TEST_NUMBERS, true);
$codigo = $isTestNumber ? $FIXED_TEST_OTP : portalGenOTP();

// Persist OTP in DB (not session) — survives any cookie/proxy issues
$pdo = getDB();
$pdo->exec("CREATE TABLE IF NOT EXISTS portal_otp (
    telefono VARCHAR(20) PRIMARY KEY,
    codigo VARCHAR(10) NOT NULL,
    cliente_id INT NOT NULL,
    intentos INT DEFAULT 0,
    expira INT NOT NULL,
    freg DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$pdo->prepare("REPLACE INTO portal_otp (telefono, codigo, cliente_id, intentos, expira)
    VALUES (?, ?, ?, 0, ?)")
    ->execute([$tel, $codigo, (int)$cliente['id'], time() + 600]);

// Also keep it in session as a fast path (harmless if session is lost)
$_SESSION['portal_otp_login'] = [
    'codigo'   => $codigo,
    'telefono' => $tel,
    'cliente_id' => (int)$cliente['id'],
    'expira'   => time() + 600,
];

$msg = "Voltika: Tu codigo de acceso es {$codigo}. Valido por 10 minutos.";
$r = portalSendSMS($tel, $msg);

portalLog('login_request', [
    'telefono' => $tel,
    'cliente_id' => (int)$cliente['id'],
    'success' => 1,
    'detalle' => $r['ok'] ? 'sms_ok' : 'sms_fallback',
]);

$out = ['ok' => true, 'status' => 'sent'];
// Always expose testCode for known test numbers (fixed 123456) or when
// SMS fails. The fixed code lets customers/QA log in without reading
// the response JSON.
if (!$r['ok'] || $isTestNumber) {
    $out['testCode'] = $codigo;
    if ($isTestNumber) $out['test_mode'] = true;
}
portalJsonOut($out);
