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
$isTestNumber = in_array($tel, ['5500000000', '0000000000']);
if (!$r['ok'] || $isTestNumber) {
    $out['testCode'] = $codigo;
}
portalJsonOut($out);
