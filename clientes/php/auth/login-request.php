<?php
require_once __DIR__ . '/../bootstrap.php';

$in = portalJsonIn();
$tel = portalNormPhone($in['telefono'] ?? '');
if (strlen($tel) < 10) {
    portalLog('login_request', ['telefono' => $tel, 'success' => 0, 'detalle' => 'telefono_invalido']);
    portalJsonOut(['error' => 'Teléfono inválido'], 400);
}

$cliente = portalFindClienteByPhone($tel);
if (!$cliente) {
    portalLog('login_request', ['telefono' => $tel, 'success' => 0, 'detalle' => 'no_encontrado']);
    portalJsonOut(['error' => 'No encontramos una cuenta con ese número'], 404);
}

$codigo = portalGenOTP();

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
// Always expose testCode for known test numbers, or when SMS fails
$isTestNumber = in_array($tel, ['5500000000', '0000000000', '5511112222']);
if (!$r['ok'] || $isTestNumber) {
    $out['testCode'] = $codigo;
}
portalJsonOut($out);
