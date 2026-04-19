<?php
/**
 * POST — Send OTP (SMS + WhatsApp) for delivery checklist
 * Body: { moto_id }
 *
 * Routes through voltikaNotify('otp_entrega', …) so SMS + WhatsApp fire in
 * one shot using the customer-authored rich template. Falls back to the raw
 * SMSmasivos API call if voltika-notify is unavailable (e.g. the helper file
 * is missing on a partial deploy).
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$motoId = (int)($d['moto_id'] ?? 0);
if (!$motoId) adminJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();
$stmt = $pdo->prepare("SELECT cliente_nombre, cliente_telefono, cliente_email, cliente_id FROM inventario_motos WHERE id=?");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) adminJsonOut(['error' => 'Moto no encontrada'], 404);

$telefono = preg_replace('/\D/', '', $moto['cliente_telefono'] ?? '');
if (strlen($telefono) < 10) {
    adminJsonOut(['error' => 'El cliente no tiene teléfono registrado o es inválido'], 400);
}

// Generate 6-digit code + persist with a 10-minute TTL on the checklist row.
$codigo = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
$existing = $pdo->prepare("SELECT id FROM checklist_entrega_v2 WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$existing->execute([$motoId]);
$row = $existing->fetch(PDO::FETCH_ASSOC);
if ($row) {
    $pdo->prepare("UPDATE checklist_entrega_v2 SET otp_code=?, otp_expires=?, otp_enviado=1, otp_timestamp=NOW() WHERE id=?")
        ->execute([$codigo, date('Y-m-d H:i:s', time() + 600), $row['id']]);
} else {
    $pdo->prepare("INSERT INTO checklist_entrega_v2 (moto_id, dealer_id, otp_code, otp_expires, otp_enviado, otp_timestamp) VALUES (?,?,?,?,1,NOW())")
        ->execute([$motoId, $uid, $codigo, date('Y-m-d H:i:s', time() + 600)]);
}

// ── Send via voltikaNotify (preferred: SMS + WhatsApp + log) ────────────────
$smsSent = false;
$waSent  = false;

$notifyPath = null;
foreach ([
    __DIR__ . '/../../../configurador_prueba_test/php/voltika-notify.php',
    __DIR__ . '/../../../configurador_prueba/php/voltika-notify.php',
] as $p) {
    if (is_file($p)) { $notifyPath = $p; break; }
}
if ($notifyPath) { try { require_once $notifyPath; } catch (Throwable $e) { error_log('notify include: ' . $e->getMessage()); } }

if (function_exists('voltikaNotify')) {
    try {
        $res = voltikaNotify('otp_entrega', [
            'cliente_id' => $moto['cliente_id'] ?? null,
            'nombre'     => $moto['cliente_nombre'] ?? '',
            'otp'        => $codigo,
            'telefono'   => $telefono,
            'whatsapp'   => $telefono,
            'email'      => $moto['cliente_email'] ?? '',
        ]);
        // voltikaNotify returns per-channel status keyed 'sms','whatsapp','email'
        $smsSent = !empty($res['sms']) && ($res['sms']['status'] ?? '') === 'sent';
        $waSent  = !empty($res['whatsapp']) && ($res['whatsapp']['status'] ?? '') === 'sent';
    } catch (Throwable $e) {
        error_log('notify otp_entrega: ' . $e->getMessage());
    }
}

// ── Legacy fallback: direct SMSmasivos if notify-layer is offline ───────────
if (!$smsSent && !$waSent) {
    $apiKey = defined('SMSMASIVOS_API_KEY') ? SMSMASIVOS_API_KEY : (getenv('SMSMASIVOS_API_KEY') ?: '');
    if ($apiKey) {
        $mensaje = "Voltika: Tu codigo de entrega es {$codigo}. Valido por 10 minutos.";
        $ch = curl_init('https://api.smsmasivos.com.mx/sms/send');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['apikey: ' . $apiKey, 'Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query([
                'message' => $mensaje,
                'numbers' => $telefono,
                'country_code' => '52'
            ]),
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode((string)$resp, true);
        $smsSent = ($httpCode >= 200 && $httpCode < 300 && !empty($data['success']));
    }
}

adminLog('checklist_otp_enviado', [
    'moto_id' => $motoId,
    'telefono' => substr($telefono, 0, 3) . '****',
    'sms_sent' => $smsSent, 'wa_sent' => $waSent,
]);

$result = [
    'ok' => true,
    'enviado' => $smsSent || $waSent,
    'sms_sent' => $smsSent,
    'whatsapp_sent' => $waSent,
    'telefono_masked' => substr($telefono, 0, 3) . '****' . substr($telefono, -2),
];
if (!$smsSent && !$waSent) {
    $result['fallback_code'] = $codigo;
    $result['warn'] = 'SMS y WhatsApp no enviados. Código de respaldo incluido.';
}

adminJsonOut($result);
