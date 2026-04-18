<?php
/**
 * POST — Reenviar link de pago al cliente para una transacción pendiente.
 *
 * Body: {
 *     transaccion_id: int,
 *     canales: ['email','sms','whatsapp'],   // al menos uno
 *     force:   bool                          // opcional — bypassa el cooldown de 24h
 * }
 *
 * Resolución del link:
 *   - Si el PaymentIntent de Stripe tiene un voucher SPEI/OXXO vigente, se
 *     reenvía ese mismo link (no se regenera la referencia para no invalidar
 *     la anterior que el cliente ya podría estar usando).
 *   - Si el PI es tarjeta / 3DS en estado pendiente, se crea un Checkout
 *     Session nuevo con metadata.pedido_original para que el cliente complete
 *     el pago.
 *
 * Anti-spam: rechaza la solicitud si last_reminder_at < 24h a menos que
 * force=1.
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

// Notify system (WhatsApp + SMS + Email)
$notifyCandidates = [
    __DIR__ . '/../../../configurador_prueba/php/voltika-notify.php',
    __DIR__ . '/../../../configurador_prueba_test/php/voltika-notify.php',
];
foreach ($notifyCandidates as $p) { if (file_exists($p)) { require_once $p; break; } }

// Stripe config
$configCandidates = [
    __DIR__ . '/../../../configurador_prueba/php/config.php',
    __DIR__ . '/../../../configurador_prueba_test/php/config.php',
];
foreach ($configCandidates as $p) { if (file_exists($p)) { require_once $p; break; } }

$d     = adminJsonIn();
$tid   = (int)($d['transaccion_id'] ?? 0);
$canales = is_array($d['canales'] ?? null) ? $d['canales'] : [];
$force = !empty($d['force']);

if (!$tid || !$canales) {
    adminJsonOut(['error' => 'transaccion_id y al menos un canal son requeridos'], 400);
}

$pdo = getDB();

// Ensure tracking columns exist (last_reminder_at, reminders_sent_count)
try {
    $cols = $pdo->query("SHOW COLUMNS FROM transacciones")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('last_reminder_at', $cols, true)) {
        $pdo->exec("ALTER TABLE transacciones ADD COLUMN last_reminder_at DATETIME NULL");
    }
    if (!in_array('reminders_sent_count', $cols, true)) {
        $pdo->exec("ALTER TABLE transacciones ADD COLUMN reminders_sent_count INT NOT NULL DEFAULT 0");
    }
} catch (Throwable $e) { error_log('enviar-link-pago ensure cols: ' . $e->getMessage()); }

// Fetch the order
$stmt = $pdo->prepare("SELECT * FROM transacciones WHERE id = ? LIMIT 1");
$stmt->execute([$tid]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) adminJsonOut(['error' => 'Orden no encontrada'], 404);

if (strtolower($order['pago_estado'] ?? '') === 'pagada') {
    adminJsonOut(['error' => 'Esta orden ya está pagada. No hay nada que recordar.'], 409);
}

// Cooldown check (24h)
if (!$force && !empty($order['last_reminder_at'])) {
    $diff = time() - strtotime($order['last_reminder_at']);
    if ($diff < 24 * 3600) {
        adminJsonOut([
            'error' => 'Ya se envió un recordatorio hace menos de 24 horas. Usa "Forzar envío" si necesitas reenviarlo.'
        ], 429);
    }
}

// Resolve the payment link via Stripe
$stripePi = $order['stripe_pi'] ?? '';
$paymentUrl = null;
$stripeStatus = null;

if ($stripePi && defined('STRIPE_SECRET_KEY') && STRIPE_SECRET_KEY) {
    // 1) Try to reuse existing voucher (SPEI / OXXO)
    $ch = curl_init('https://api.stripe.com/v1/payment_intents/' . urlencode($stripePi) . '?expand[]=next_action');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . STRIPE_SECRET_KEY],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200 && ($pi = json_decode($resp, true))) {
        $stripeStatus = $pi['status'] ?? null;
        $na = $pi['next_action'] ?? [];
        // OXXO
        if (!empty($na['oxxo_display_details']['hosted_voucher_url'])) {
            $paymentUrl = $na['oxxo_display_details']['hosted_voucher_url'];
        }
        // SPEI
        if (!$paymentUrl && !empty($na['display_bank_transfer_instructions']['hosted_instructions_url'])) {
            $paymentUrl = $na['display_bank_transfer_instructions']['hosted_instructions_url'];
        }
    }

    // 2) If no voucher, create a Checkout Session (card / 3DS flow)
    if (!$paymentUrl) {
        $amountCents = (int) round(((float)$order['total']) * 100);
        $origin = (defined('APP_URL') && APP_URL) ? APP_URL : 'https://voltika.mx';
        $successUrl = rtrim($origin, '/') . '/configurador_prueba/?order=' . urlencode($order['pedido']) . '&resume=ok';
        $cancelUrl  = rtrim($origin, '/') . '/configurador_prueba/?order=' . urlencode($order['pedido']) . '&resume=cancel';

        $fields = [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url'  => $cancelUrl,
            'customer_email' => $order['email'] ?? '',
            'payment_intent_data[metadata][pedido_original]' => $order['pedido'] ?? '',
            'payment_intent_data[metadata][transaccion_id]'  => $order['id'] ?? '',
            'payment_intent_data[metadata][reminder]'        => '1',
            'line_items[0][price_data][currency]'     => 'mxn',
            'line_items[0][price_data][product_data][name]' => 'Voltika ' . ($order['modelo'] ?? '') . ' ' . ($order['color'] ?? ''),
            'line_items[0][price_data][unit_amount]'  => $amountCents,
            'line_items[0][quantity]'                 => 1,
        ];
        $post = http_build_query($fields);
        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . STRIPE_SECRET_KEY],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp2 = curl_exec($ch);
        $code2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code2 === 200 && ($cs = json_decode($resp2, true)) && !empty($cs['url'])) {
            $paymentUrl = $cs['url'];
        } else {
            error_log('enviar-link-pago checkout create failed code=' . $code2 . ' resp=' . substr($resp2, 0, 500));
        }
    }
}

if (!$paymentUrl) {
    adminJsonOut([
        'error' => 'No se pudo generar el link de pago. Verifica que la orden tenga un PaymentIntent válido y que Stripe esté accesible.'
    ], 500);
}

// Send notifications
$tel   = $order['telefono'] ?? '';
$email = $order['email'] ?? '';
$nombre = $order['nombre'] ?? 'Cliente';
$modelo = trim(($order['modelo'] ?? '') . (!empty($order['color']) ? ' · ' . $order['color'] : ''));
$monto  = (float)($order['total'] ?? 0);

$data = [
    'cliente_id' => null,
    'nombre'     => $nombre,
    'modelo'     => $modelo,
    'monto_fmt'  => '$' . number_format($monto, 2) . ' MXN',
    'link'       => $paymentUrl,
    'telefono'   => $tel,
    'email'      => $email,
    'whatsapp'   => $tel,
];

$sentEmail = false; $sentSms = false; $sentWa = false;
$errorLog  = [];

// The template name must exist in voltikaNotifyTemplates().
$tipoTpl = 'recordatorio_pago_pendiente';

if (function_exists('voltikaNotify')) {
    // Build per-channel payload by restricting which channels voltikaNotify uses
    // (the template is the same; we drive channels via the presence of fields).
    foreach (['email','sms','whatsapp'] as $ch) {
        if (!in_array($ch, $canales, true)) continue;
        $channelData = $data;
        if ($ch !== 'email')    $channelData['email']    = null;
        if ($ch !== 'sms')      $channelData['telefono'] = null;
        if ($ch !== 'whatsapp') $channelData['whatsapp'] = null;
        if ($ch === 'whatsapp') $channelData['telefono'] = $tel; // WA uses telefono
        try {
            $r = voltikaNotify($tipoTpl, $channelData);
            if (!empty($r['email']['ok']))    $sentEmail = true;
            if (!empty($r['sms']['ok']))      $sentSms   = true;
            if (!empty($r['whatsapp']['ok'])) $sentWa    = true;
            if (!empty($r['error']))          $errorLog[] = $ch . ': ' . $r['error'];
        } catch (Throwable $e) {
            error_log('enviar-link-pago notify ' . $ch . ': ' . $e->getMessage());
            $errorLog[] = $ch . ': ' . $e->getMessage();
        }
    }
}

// Update tracking columns
try {
    $pdo->prepare("UPDATE transacciones
                   SET last_reminder_at = NOW(),
                       reminders_sent_count = COALESCE(reminders_sent_count, 0) + 1
                   WHERE id = ?")->execute([$tid]);
} catch (Throwable $e) { error_log('enviar-link-pago update tracking: ' . $e->getMessage()); }

adminLog('enviar_link_pago', [
    'transaccion_id' => $tid,
    'pedido'         => $order['pedido'] ?? '',
    'canales'        => $canales,
    'sent_email'     => $sentEmail ? 1 : 0,
    'sent_sms'       => $sentSms   ? 1 : 0,
    'sent_whatsapp'  => $sentWa    ? 1 : 0,
    'stripe_status'  => $stripeStatus,
    'link_preview'   => substr($paymentUrl, 0, 120),
]);

adminJsonOut([
    'ok'             => true,
    'link'           => $paymentUrl,
    'sent_email'     => $sentEmail,
    'sent_sms'       => $sentSms,
    'sent_whatsapp'  => $sentWa,
    'errors'         => $errorLog,
]);
