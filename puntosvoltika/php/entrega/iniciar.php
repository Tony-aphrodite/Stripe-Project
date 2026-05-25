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
// Customer feedback 2026-04-23 + 2026-05-13 (Óscar, 13th round — "OTP
// never arrives" recurring): SMS delivery has multiple failure modes
// that previously vanished silently. We now diagnose + report each one:
//   - Phone format issues (no 10-digit body, missing country code).
//   - SMS provider misconfig (no API key, key revoked, account paused).
//   - HTTP errors from the SMS gateway (timeout, 401, 403, 5xx).
//   - "Sent OK" by the gateway but the carrier still drops the message
//     (the gateway returns 200 with status="queued"/"pending" forever).
//
// Strategy:
//   1. voltikaNotify('otp_entrega', ...) — rich template, sends WhatsApp +
//      email + SMS simultaneously (whatever channel the customer has), same
//      mechanism used by admin/php/checklists/enviar-otp.php.
//   2. Direct SMSmasivos call as a belt-and-braces fallback.
// The response includes per-channel outcomes AND the raw gateway response
// body so the dealer UI / support can see exactly why a customer didn't
// receive the SMS. The test_code is still surfaced as last-resort fallback
// when every channel failed.

// Normalize phone — strip everything non-digit; ensure 52-prefixed
// 12-digit (Mexico). If the source data is malformed we mark it as
// invalid up-front instead of letting SMS gateways silently swallow it.
$telRaw = (string)($moto['cliente_telefono'] ?? '');
$tel = preg_replace('/\D/', '', $telRaw);
$telInvalid = null;
if (strlen($tel) === 10) {
    $tel = '52' . $tel;
} elseif (strlen($tel) === 12 && strpos($tel, '52') === 0) {
    // Already in international format with country code.
} elseif (strlen($tel) === 11 && strpos($tel, '521') === 0) {
    // Legacy MX mobile prefix '521' — strip the extra 1 so SMS gateways
    // accept it. Stripe / SMSmasivos / Twilio all want plain '52' + 10 digits.
    $tel = '52' . substr($tel, 3);
} else {
    $telInvalid = 'Número de teléfono no válido (debe tener 10 dígitos de México). Recibido: "' . $telRaw . '"';
}

// Round 80 v2 (2026-05-25) — Auto-include the standalone signing link in
// the SMS so customers whose SPA fails for ANY reason (cache, JS error,
// network glitch, anything) can still complete the signature by tapping
// the link in the SMS. Same URL the new admin tool generates; backwards
// compatible — the OTP is still in the message for customers/asesores
// who prefer the existing flow.
$standaloneSigningUrl = '';
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS firma_acta_requests (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        moto_id         INT NOT NULL,
        token           CHAR(40) NOT NULL UNIQUE,
        email           VARCHAR(200) NULL,
        telefono        VARCHAR(30) NULL,
        estado          ENUM('pending','signed','expired') NOT NULL DEFAULT 'pending',
        expires_at      INT NOT NULL,
        signed_at       DATETIME NULL,
        signed_firma_id INT NULL,
        ip              VARCHAR(45) NULL,
        user_agent      VARCHAR(500) NULL,
        admin_id        INT NULL,
        freg            DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_moto   (moto_id),
        INDEX idx_estado (estado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Expire any prior pending token for this moto so only ONE is active.
    $pdo->prepare("UPDATE firma_acta_requests SET estado='expired'
                    WHERE moto_id=? AND estado='pending'")
        ->execute([$motoId]);

    $sigToken = bin2hex(random_bytes(20));
    $sigExpires = time() + (24 * 3600);
    $pdo->prepare("INSERT INTO firma_acta_requests
            (moto_id, token, email, telefono, expires_at)
        VALUES (?, ?, ?, ?, ?)")
        ->execute([
            $motoId, $sigToken,
            $moto['cliente_email']    ?? null,
            $moto['cliente_telefono'] ?? null,
            $sigExpires,
        ]);

    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'voltika.mx';
    $standaloneSigningUrl = $scheme . '://' . $host . '/clientes/firmar-acta-directa.php?token=' . $sigToken;
} catch (Throwable $e) {
    error_log('iniciar.php standalone signing link generation: ' . $e->getMessage());
}

// Build the SMS body. When the standalone link is available, use a shorter
// message that fits 160 chars including the ~96-char URL. Otherwise fall
// back to the original instruction.
if ($standaloneSigningUrl !== '') {
    // Keep wording tight so the total stays under 160 chars:
    //   "Voltika: codigo XXXXXX. Firma directo aqui: " ≈ 44 chars
    //   + URL ≈ 96 chars  → ~140 chars total
    $msg = "Voltika: codigo {$otp}. Firma tu entrega directo aqui: {$standaloneSigningUrl}";
} else {
    // Legacy message — OTP only, customer must use the SPA.
    $msg = "Voltika: Tu codigo de entrega es {$otp}. Muestralo al asesor en el punto. No lo compartas.";
}

// 1) Multi-channel via voltikaNotify (whatsapp + email + sms template)
$notifyResult = null;
$notifyPath = null;
foreach ([
    __DIR__ . '/../../../configurador/php/voltika-notify.php',
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
            // Round 80 v2 — standalone signing URL passed alongside the OTP
            // so the email/whatsapp templates can optionally include it.
            // If the template ignores this field, no harm done.
            'signing_url'=> $standaloneSigningUrl,
        ]);
    } catch (Throwable $e) {
        error_log('notify otp_entrega: ' . $e->getMessage());
        $notifyResult = ['error' => $e->getMessage()];
    }
}

// 2) SMSmasivos direct fallback — always attempt, even when voltikaNotify
// succeeds, so SMS lands on carriers the template doesn't cover.
$smsKey = defined('SMSMASIVOS_API_KEY') ? SMSMASIVOS_API_KEY : (getenv('SMSMASIVOS_API_KEY') ?: '');
$smsSent     = false;
$smsHttpCode = null;
$smsError    = null;
$smsResponse = null;
$smsSkipReason = null;
if ($telInvalid) {
    $smsSkipReason = 'phone_invalid';
} elseif (!$smsKey) {
    $smsSkipReason = 'no_api_key';   // SMSMASIVOS_API_KEY no configurada
} else {
    // ── Round 47 (2026-05-16): SMSmasivos uses apikey/form-urlencoded
    // (NOT Bearer/JSON). The old call silently failed because the
    // gateway returned HTTP 200 with body {"success":false,"status":401,
    // "code":"auth_01"} and the code only checked HTTP code. Switched
    // to the documented auth scheme + body.success parsing so the
    // operator sees the real failure mode.
    // SMSmasivos expects `numbers` as 10-digit national (no country
    // prefix); the country_code field carries '52' separately.
    $telNacional = $tel;
    if (strlen($telNacional) === 12 && strpos($telNacional, '52') === 0)  $telNacional = substr($telNacional, 2);
    if (strlen($telNacional) === 11 && strpos($telNacional, '521') === 0) $telNacional = substr($telNacional, 3);
    $ch = curl_init('https://api.smsmasivos.com.mx/sms/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $smsKey,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_POSTFIELDS => http_build_query([
            'message'      => $msg,
            'numbers'      => $telNacional,
            'country_code' => '52',
        ]),
        CURLOPT_TIMEOUT => 8,
    ]);
    $res = curl_exec($ch);
    $smsHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $smsError    = curl_error($ch) ?: null;
    curl_close($ch);
    $smsResponse = is_string($res) ? substr($res, 0, 500) : null;
    $smsParsed   = is_string($res) ? json_decode($res, true) : null;
    $bodyOk      = is_array($smsParsed) && !empty($smsParsed['success']);
    $smsSent     = ($smsHttpCode >= 200 && $smsHttpCode < 300) && $bodyOk;
    // If the gateway explicitly rejected, surface its message via $smsError
    // so the warning banner below shows the real reason (e.g., auth_01).
    if (!$smsSent && !$smsError && is_array($smsParsed)) {
        $smsError = (string)($smsParsed['message'] ?? 'gateway rechazó SMS')
                  . (isset($smsParsed['code']) ? ' (' . $smsParsed['code'] . ')' : '');
    }
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
    'moto_id'        => $motoId,
    'notify_ok'      => $notifyOk,
    'sms_ok'         => $smsSent,
    'sms_http'       => $smsHttpCode,
    'sms_error'      => $smsError,
    'sms_skip'       => $smsSkipReason,
    'sms_response'   => $smsResponse,
    'tel_invalid'    => $telInvalid,
    'tel_normalized' => $tel,
    'any_channel'    => $anyChannelOk,
]);

// Build a precise human-readable warning so the dealer knows exactly
// what to do when SMS doesn't reach the customer. Customer brief
// 2026-05-13 (Óscar, 13th round — "OTP never arrives"): the previous
// generic "Léelo al cliente en persona" message hid the real cause.
// Now we surface the failure reason directly.
$warning = null;
if (!$anyChannelOk) {
    if ($telInvalid) {
        $warning = $telInvalid . ' — Pide al cliente que verifique su número y lee el código en persona.';
    } elseif ($smsSkipReason === 'no_api_key') {
        $warning = 'El proveedor de SMS no está configurado en el servidor (falta SMSMASIVOS_API_KEY). Lee el código al cliente y reporta a soporte.';
    } elseif ($smsHttpCode && $smsHttpCode >= 400) {
        $warning = 'El proveedor de SMS rechazó el envío (HTTP ' . $smsHttpCode . '). Lee el código al cliente y avisa a soporte para revisar la cuenta SMSmasivos.';
    } elseif ($smsError) {
        $warning = 'Error de red al enviar SMS: ' . $smsError . '. Lee el código al cliente y reintenta.';
    } else {
        $warning = 'No se pudo entregar el código por ningún canal. Léelo al cliente en persona y revisa la conexión del proveedor SMS.';
    }
}

puntoJsonOut([
    'ok'           => true,
    'sms_enviado'  => $smsSent,
    'notify'       => $notifyResult,
    'any_channel'  => $anyChannelOk,
    // test_code is only surfaced when every channel failed, so staff can
    // read the code to the customer in person as last-resort fallback.
    'test_code'    => $anyChannelOk ? null : $otp,
    'warning'      => $warning,
    // Detailed diagnostics — surfaced in the UI under a "Detalle técnico"
    // expander so the operator can tell soporte the exact failure mode.
    'diagnostico'  => [
        'tel_recibido'   => $moto['cliente_telefono'] ?? '',
        'tel_normalized' => $tel,
        'tel_invalid'    => $telInvalid,
        'sms_http'       => $smsHttpCode,
        'sms_error'      => $smsError,
        'sms_skip'       => $smsSkipReason,
        'sms_response'   => $smsResponse,
        'notify_result'  => $notifyResult,
    ],
    'cliente'      => ['nombre' => $moto['cliente_nombre'], 'telefono' => $moto['cliente_telefono']]
]);
