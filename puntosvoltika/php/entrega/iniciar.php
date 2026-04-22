<?php
/**
 * POST — Iniciar proceso de entrega al cliente: generar OTP y enviar por SMS
 * Body: { moto_id }
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$d = puntoJsonIn();
$motoId = (int)($d['moto_id'] ?? 0);
if (!$motoId) puntoJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE id=? AND punto_voltika_id=?");
$stmt->execute([$motoId, $ctx['punto_id']]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) puntoJsonOut(['error' => 'Moto no encontrada en este punto'], 404);
if (!$moto['cliente_telefono']) puntoJsonOut(['error' => 'Moto no tiene cliente asignado'], 400);

// Per dashboards_diagrams.pdf (Delivery process): delivery is blocked if payment is not complete.
// Allowed: 'pagada' (cash/MSI) or 'pagada_completa'. 'parcial' (credito with enganche only) requires
// the credit plan to be fully paid before release. Anything else blocks delivery.
$pagoEstado = strtolower(trim($moto['pago_estado'] ?? ''));
$pagoOk = in_array($pagoEstado, ['pagada'], true);

if (!$pagoOk && $pagoEstado === 'parcial') {
    // Credito flow — allow only if the subscription is current and has no overdue cycles.
    // The real table is `subscripciones_credito`, linked to the moto via `inventario_moto_id`.
    try {
        $sq = $pdo->prepare("SELECT s.id FROM subscripciones_credito s
            WHERE s.inventario_moto_id = ? AND s.estado IN ('activa','active','completada','completed')
            ORDER BY s.id DESC LIMIT 1");
        $sq->execute([$motoId]);
        $subId = $sq->fetchColumn();
        if ($subId) {
            $vq = $pdo->prepare("SELECT COUNT(*) FROM ciclos_pago
                WHERE subscripcion_id = ? AND estado IN ('overdue','pending')
                  AND fecha_vencimiento < CURDATE()");
            $vq->execute([(int)$subId]);
            $vencidos = (int)$vq->fetchColumn();
            if ($vencidos === 0) $pagoOk = true;
        }
    } catch (Throwable $e) { error_log('iniciar entrega pago check: ' . $e->getMessage()); }
}

if (!$pagoOk) {
    puntoJsonOut([
        'error' => 'No se puede iniciar la entrega: el pago no está completo.',
        'pago_estado' => $pagoEstado ?: 'desconocido'
    ], 403);
}

// Generate OTP
$otp = puntoGenOTP();
$expires = date('Y-m-d H:i:s', time() + 600);

// Upsert entrega record
$pdo->prepare("INSERT INTO entregas (moto_id, pedido_num, cliente_nombre, cliente_email, cliente_telefono,
    otp_code, otp_expires, estado, dealer_id)
    VALUES (?,?,?,?,?,?,?,'otp_enviado',?)
    ON DUPLICATE KEY UPDATE otp_code=VALUES(otp_code), otp_expires=VALUES(otp_expires), estado='otp_enviado'")
    ->execute([
        $motoId, $moto['pedido_num'], $moto['cliente_nombre'],
        $moto['cliente_email'], $moto['cliente_telefono'],
        $otp, $expires, $ctx['user_id']
    ]);

// ── Delivery channels ─────────────────────────────────────────────────────
// Customer feedback 2026-04-23: OTP was not received. Previously this
// endpoint only tried SMSmasivos; if that API timed out or returned an error
// body, the OTP vanished silently and the customer couldn't pick up the bike.
//
// Now we try in sequence:
//   1. voltikaNotify('otp_entrega', ...) — rich template, sends WhatsApp +
//      email + SMS simultaneously (whatever channel the customer has), same
//      mechanism used by admin/php/checklists/enviar-otp.php.
//   2. Direct SMSmasivos call as a belt-and-braces fallback.
// The call reports per-channel outcomes so the dealer UI can warn loudly
// when EVERY channel failed and staff must read the OTP aloud.
$tel = preg_replace('/\D/', '', $moto['cliente_telefono']);
if (strlen($tel) === 10) $tel = '52' . $tel;
$msg = "Voltika: Tu código de entrega es {$otp}. Muéstralo al asesor en el punto. No lo compartas.";

// 1) Multi-channel via voltikaNotify (whatsapp + email + sms template)
$notifyResult = null;
$notifyPath = null;
foreach ([
    __DIR__ . '/../../../configurador_prueba/php/voltika-notify.php',
    __DIR__ . '/../../../configurador_prueba_test/php/voltika-notify.php',
] as $_p) {
    if (is_file($_p)) { $notifyPath = $_p; break; }
}
if ($notifyPath) { try { require_once $notifyPath; } catch (Throwable $e) { error_log('notify include: ' . $e->getMessage()); } }
if (function_exists('voltikaNotify')) {
    try {
        $notifyResult = voltikaNotify('otp_entrega', [
            'cliente_id' => $moto['cliente_id'] ?? null,
            'nombre'     => $moto['cliente_nombre'] ?? '',
            'modelo'     => $moto['modelo'] ?? '',
            'color'      => $moto['color']  ?? '',
            'pedido'     => $moto['pedido_num'] ?? '',
            'otp'        => $otp,
            'codigo'     => $otp,
            'telefono'   => $moto['cliente_telefono'],
            'whatsapp'   => $moto['cliente_telefono'],
            'email'      => $moto['cliente_email'] ?? '',
        ]);
    } catch (Throwable $e) {
        error_log('notify otp_entrega: ' . $e->getMessage());
        $notifyResult = ['error' => $e->getMessage()];
    }
}

// 2) SMSmasivos direct fallback — always attempt, even when voltikaNotify
// succeeds, so SMS lands on carriers the template doesn't cover.
$smsKey = defined('SMSMASIVOS_API_KEY') ? SMSMASIVOS_API_KEY : (getenv('SMSMASIVOS_API_KEY') ?: '');
$smsSent = false;
$smsHttpCode = null;
$smsError    = null;
if ($smsKey) {
    $ch = curl_init('https://api.smsmasivos.com.mx/sms/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json','Authorization: Bearer '.$smsKey],
        CURLOPT_POSTFIELDS => json_encode(['phone_number'=>$tel,'message'=>$msg]),
        CURLOPT_TIMEOUT => 8,
    ]);
    $res = curl_exec($ch);
    $smsHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $smsError    = curl_error($ch) ?: null;
    curl_close($ch);
    $smsSent = ($smsHttpCode >= 200 && $smsHttpCode < 300 && !empty($res));
}

// Detect whether at least one channel reported success — the UI uses this
// to warn the operator loudly when nothing reached the customer.
$notifyOk = is_array($notifyResult) && empty($notifyResult['error']) && (
      !empty($notifyResult['whatsapp_sent'])
   || !empty($notifyResult['email_sent'])
   || !empty($notifyResult['sms_sent'])
   || !empty($notifyResult['sent'])
);
$anyChannelOk = $notifyOk || $smsSent;

puntoLog('entrega_otp_enviado', [
    'moto_id'     => $motoId,
    'notify_ok'   => $notifyOk,
    'sms_ok'      => $smsSent,
    'sms_http'    => $smsHttpCode,
    'sms_error'   => $smsError,
    'any_channel' => $anyChannelOk,
]);

puntoJsonOut([
    'ok'           => true,
    'sms_enviado'  => $smsSent,
    'notify'       => $notifyResult,
    'any_channel'  => $anyChannelOk,
    // test_code is only surfaced when every channel failed, so staff can
    // read the code to the customer in person as last-resort fallback.
    'test_code'    => $anyChannelOk ? null : $otp,
    'warning'      => $anyChannelOk
        ? null
        : 'No se pudo entregar el código por ningún canal. Léelo al cliente en persona y revisa la conexión del proveedor SMS.',
    'cliente'      => ['nombre' => $moto['cliente_nombre'], 'telefono' => $moto['cliente_telefono']]
]);
