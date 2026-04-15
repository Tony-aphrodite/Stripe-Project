<?php
/**
 * Voltika — Notification Service (shared)
 * ----------------------------------------
 * Unified notifications for all panels (admin, puntosvoltika, clientes).
 *
 *   voltikaNotify('tipo_evento', [
 *       'telefono'      => '5512345678',
 *       'email'         => 'cliente@mail.com',
 *       'whatsapp'      => '5512345678',   // optional — falls back to telefono
 *       'cliente_id'    => 42,             // for logging
 *       // template-specific placeholders: {modelo}, {punto}, {fecha}, {monto}, {otp}, {mensaje}, ...
 *   ]);
 *
 * Channels: SMS (SMSmasivos), Email (PHPMailer via sendMail), WhatsApp (Twilio — if configured).
 * Logs every send into `notificaciones_log`.
 */

require_once __DIR__ . '/config.php';

if (!function_exists('voltikaNotify')) {

function voltikaNotifyEnsureTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS notificaciones_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cliente_id INT NULL,
            tipo VARCHAR(60) NOT NULL,
            canal VARCHAR(20) NOT NULL,
            destino VARCHAR(150) NULL,
            mensaje TEXT NULL,
            status VARCHAR(30) DEFAULT 'sent',
            error TEXT NULL,
            freg DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cliente (cliente_id),
            INDEX idx_tipo (tipo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('voltikaNotifyEnsureTable: ' . $e->getMessage()); }
}

function voltikaNotifyTemplates(): array {
    return [
        // ── Flujo de entrega ────────────────────────────────────────────────
        'punto_asignado' => [
            'subject' => '📍 Tu Voltika tiene punto de entrega',
            'body'    => "Hola {nombre},\n\nTu moto {modelo} será entregada en {punto} ({ciudad}).\nTe avisaremos cuando esté lista para recoger.\n\n¡Gracias por elegir Voltika! ⚡",
            'sms'     => 'Voltika: Tu moto sera entregada en {punto}. Te avisamos cuando este lista.',
        ],
        'moto_enviada' => [
            'subject' => '🚚 Tu Voltika está en camino',
            'body'    => "Hola {nombre},\n\nTu moto {modelo} fue enviada al punto {punto}.\nFecha estimada de llegada: {fecha}.\n\nTe avisaremos cuando llegue.",
            'sms'     => 'Voltika: Tu moto va en camino. Llegada estimada: {fecha}.',
        ],
        'lista_para_recoger' => [
            'subject' => '✅ Tu Voltika está lista para entrega',
            'body'    => "¡Hola {nombre}! 🎉\n\nTu moto {modelo} ya está lista en {punto}.\n\n📅 Fecha de recolección: {fecha_entrega}\n📍 Dirección: {direccion}\n🕐 Horario: {horario}\n\nAcércate con tu INE el día de recolección. Al llegar recibirás un código OTP por SMS para validar tu identidad.",
            'sms'     => 'Voltika: Tu moto esta lista en {punto}. Recogela el {fecha_entrega}. Lleva tu INE. Recibiras un OTP al recogerla.',
        ],
        'otp_entrega' => [
            'subject' => '🔐 Código de entrega Voltika',
            'body'    => "Hola {nombre},\n\nTu código de entrega es: {otp}\n\nMuéstralo al asesor en el punto de Voltika. Este código expira en 10 minutos.\nNO lo compartas con nadie.",
            'sms'     => 'Voltika: Tu codigo de entrega es {otp}. Muestralo al asesor. No lo compartas. Expira en 10 min.',
        ],
        'acta_firmada' => [
            'subject' => '📄 ACTA DE ENTREGA firmada',
            'body'    => "Hola {nombre},\n\nHas firmado el ACTA DE ENTREGA de tu moto {modelo}.\nEl personal de Voltika finalizará el proceso ahora mismo.\n\n¡Casi listo! ⚡",
            'sms'     => 'Voltika: ACTA firmada. Personal finalizara tu entrega en instantes.',
        ],
        'entrega_completada' => [
            'subject' => '🎉 ¡Bienvenido a la familia Voltika!',
            'body'    => "¡Felicidades {nombre}! 🎊\n\nTu moto {modelo} te fue entregada con éxito.\n\nRecuerda:\n• Tu primer pago semanal vence el {proximo_pago}\n• Puedes gestionar tus pagos en voltika.mx/clientes\n• Para soporte, contáctanos por WhatsApp\n\n¡Disfruta tu Voltika! ⚡",
            'sms'     => 'Voltika: Bienvenido! Tu moto fue entregada. Proximo pago: {proximo_pago}. Gestiona todo en voltika.mx/clientes',
        ],
        'recepcion_incidencia' => [
            'subject' => '⚠️ Incidencia reportada en entrega',
            'body'    => "Hola {nombre},\n\nRegistramos la incidencia que reportaste sobre tu moto {modelo}:\n{mensaje}\n\nNuestro equipo te contactará en menos de 24h.",
            'sms'     => 'Voltika: Recibimos tu reporte de incidencia. Te contactaremos pronto.',
        ],

        // ── Flujo de pagos ──────────────────────────────────────────────────
        'pago_recibido' => [
            'subject' => '✅ Pago recibido — Voltika',
            'body'    => "Hola {nombre},\n\nRecibimos tu pago de ${monto} correctamente.\nSemana {semana} cubierta. Próximo pago: {proximo_pago}.\n\n¡Gracias!",
            'sms'     => 'Voltika: Pago de ${monto} recibido. Proximo pago: {proximo_pago}. Gracias!',
        ],
        'pago_vencido' => [
            'subject' => '⚠️ Pago vencido — Voltika',
            'body'    => "Hola {nombre},\n\nTu pago semanal de ${monto} está vencido.\nRegulariza en voltika.mx/clientes para evitar afectar tu historial crediticio.",
            'sms'     => 'Voltika: Tu pago de ${monto} esta vencido. Regularizalo en voltika.mx/clientes',
        ],
        'recordatorio_pago' => [
            'subject' => '⏰ Recordatorio de pago — Voltika',
            'body'    => "Hola {nombre},\n\nTe recordamos que tu pago semanal de ${monto} vence el {fecha}.\nPuedes pagar en voltika.mx/clientes o con tu tarjeta guardada.",
            'sms'     => 'Voltika: Recordatorio — pago de ${monto} vence {fecha}. Paga en voltika.mx/clientes',
        ],
    ];
}

function voltikaNotifyInterpolate(string $tpl, array $data): string {
    foreach ($data as $k => $v) {
        if (is_scalar($v) || $v === null) {
            $tpl = str_replace('{' . $k . '}', (string)$v, $tpl);
        }
    }
    // Strip any unfilled placeholders
    return preg_replace('/\{[a-z_]+\}/', '', $tpl);
}

function voltikaNotifyLog(?int $clienteId, string $tipo, string $canal, ?string $destino, string $mensaje, string $status, ?string $err = null): void {
    try {
        getDB()->prepare("INSERT INTO notificaciones_log (cliente_id, tipo, canal, destino, mensaje, status, error)
            VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$clienteId, $tipo, $canal, $destino, $mensaje, $status, $err]);
    } catch (Throwable $e) { error_log('voltikaNotifyLog: ' . $e->getMessage()); }
}

function voltikaSendSMS(string $telefono, string $mensaje): array {
    $tel = preg_replace('/\D/', '', $telefono);
    if (strlen($tel) === 10) $tel = '52' . $tel;
    $smsKey = defined('SMSMASIVOS_API_KEY') ? SMSMASIVOS_API_KEY : (getenv('SMSMASIVOS_API_KEY') ?: '');
    if (!$smsKey) return ['ok' => false, 'error' => 'SMS key missing'];

    $ch = curl_init('https://api.smsmasivos.com.mx/sms/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $smsKey],
        CURLOPT_POSTFIELDS     => json_encode(['phone_number' => $tel, 'message' => $mensaje]),
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['ok' => !$err && $code >= 200 && $code < 300, 'error' => $err ?: null, 'response' => $res];
}

function voltikaSendWhatsApp(string $telefono, string $mensaje): array {
    // Twilio WhatsApp (optional — requires TWILIO_SID, TWILIO_TOKEN, TWILIO_WA_FROM in env)
    $sid   = getenv('TWILIO_SID')   ?: (defined('TWILIO_SID')   ? TWILIO_SID   : null);
    $token = getenv('TWILIO_TOKEN') ?: (defined('TWILIO_TOKEN') ? TWILIO_TOKEN : null);
    $from  = getenv('TWILIO_WA_FROM') ?: (defined('TWILIO_WA_FROM') ? TWILIO_WA_FROM : 'whatsapp:+14155238886');
    if (!$sid || !$token) return ['ok' => false, 'error' => 'Twilio not configured'];

    $tel = preg_replace('/\D/', '', $telefono);
    if (strlen($tel) === 10) $tel = '52' . $tel;

    $ch = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . $sid . '/Messages.json');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERPWD        => $sid . ':' . $token,
        CURLOPT_POSTFIELDS     => http_build_query([
            'From' => $from,
            'To'   => 'whatsapp:+' . $tel,
            'Body' => $mensaje,
        ]),
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['ok' => !$err && $code >= 200 && $code < 300, 'error' => $err ?: null, 'response' => $res];
}

/**
 * Main entry point.
 * @return array summary of what was sent
 */
function voltikaNotify(string $tipo, array $data): array {
    voltikaNotifyEnsureTable();
    $templates = voltikaNotifyTemplates();
    if (!isset($templates[$tipo])) {
        error_log("voltikaNotify: unknown template $tipo");
        return ['ok' => false, 'error' => 'unknown_template'];
    }

    $tpl       = $templates[$tipo];
    $subject   = voltikaNotifyInterpolate($tpl['subject'] ?? 'Voltika', $data);
    $body      = voltikaNotifyInterpolate($tpl['body'] ?? '', $data);
    $sms       = voltikaNotifyInterpolate($tpl['sms'] ?? $body, $data);
    $clienteId = isset($data['cliente_id']) ? (int)$data['cliente_id'] : null;
    $summary   = ['tipo' => $tipo, 'channels' => []];

    // SMS
    if (!empty($data['telefono'])) {
        $r = voltikaSendSMS($data['telefono'], $sms);
        voltikaNotifyLog($clienteId, $tipo, 'sms', $data['telefono'], $sms, $r['ok'] ? 'sent' : 'failed', $r['error'] ?? null);
        $summary['channels']['sms'] = $r['ok'];
    }

    // WhatsApp (optional)
    $wa = $data['whatsapp'] ?? $data['telefono'] ?? null;
    if ($wa && (getenv('TWILIO_SID') || defined('TWILIO_SID'))) {
        $r = voltikaSendWhatsApp($wa, $body ?: $sms);
        voltikaNotifyLog($clienteId, $tipo, 'whatsapp', $wa, $body ?: $sms, $r['ok'] ? 'sent' : 'failed', $r['error'] ?? null);
        $summary['channels']['whatsapp'] = $r['ok'];
    }

    // Email
    if (!empty($data['email']) && function_exists('sendMail')) {
        $html = '<div style="font-family:system-ui,sans-serif;max-width:560px;margin:0 auto;padding:20px;color:#222">'
              . '<h2 style="color:#22d37a;margin:0 0 14px">' . htmlspecialchars($subject) . '</h2>'
              . '<p style="white-space:pre-line;line-height:1.6">' . htmlspecialchars($body) . '</p>'
              . '<hr><p style="font-size:11px;color:#888">Este mensaje fue enviado automáticamente por Voltika. No respondas a este correo.</p>'
              . '</div>';
        $ok = false;
        try { $ok = (bool) @sendMail($data['email'], $subject, $html); } catch (Throwable $e) {}
        voltikaNotifyLog($clienteId, $tipo, 'email', $data['email'], $subject, $ok ? 'sent' : 'failed');
        $summary['channels']['email'] = $ok;
    }

    $summary['ok'] = true;
    return $summary;
}

} // if !function_exists
