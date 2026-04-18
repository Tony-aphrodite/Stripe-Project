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

/**
 * Build a purchase-confirmation template (subject/body/email_html) for one of
 * the 4 post-purchase cases.
 *
 *   $isCredit  — true for plazos/crédito; false for contado/MSI.
 *   $hasPunto  — true when the client picked a delivery point at checkout.
 *
 * Customer brief 2026-04-19: every combination is a distinct message.
 */
function voltikaBuildCompraTemplate(bool $isCredit, bool $hasPunto): array {
    // ── Title / subject ──────────────────────────────────────────────────
    $plazos = $isCredit ? ' a plazos' : '';
    $subject = '🎉 ¡Tu VOLTIKA está confirmada' . $plazos . '! — Pedido VK-{pedido}';

    // ── "TU PUNTO DE ENTREGA" section (HTML + text) ─────────────────────
    if ($hasPunto) {
        $puntoHtml = '<div style="background:#E8F4FD;border-radius:8px;padding:14px 16px;margin:6px 0 14px;">'
                   . '<div style="font-size:14px;line-height:1.7;color:#1a3a5c;">'
                   . '🏪 <strong>{punto}</strong><br>'
                   . '📬 {direccion_punto}<br>'
                   . '🗺️ <a href="{link_maps}" style="color:#039fe1;">Ver en Google Maps</a><br>'
                   . '🕐 Lunes a Sábado 9:00 - 18:00 hrs<br>'
                   . '📅 Entrega estimada: antes del <strong>{fecha_estimada}</strong>'
                   . '</div></div>';
        $puntoText = "🏪 {punto}\n📬 {direccion_punto}\n🗺️ {link_maps}\n🕐 Lunes a Sábado 9:00 - 18:00 hrs\n📅 Entrega estimada: antes del {fecha_estimada}";
    } else {
        $puntoHtml = '<div style="background:#FFF8E1;border-left:4px solid #FFC107;border-radius:4px;padding:12px 14px;margin:6px 0 14px;">'
                   . '<div style="font-size:13.5px;line-height:1.6;color:#6b4c0f;">'
                   . 'Estamos asignando el punto más cercano a ti en <strong>{ciudad}</strong>.<br><br>'
                   . 'Te confirmamos dirección exacta en menos de <strong>48 horas</strong> por WhatsApp. No necesitas hacer nada por ahora.'
                   . '</div></div>';
        $puntoText = "Estamos asignando el punto más cercano a ti en {ciudad}.\nTe confirmamos dirección exacta en menos de 48 horas por WhatsApp. No necesitas hacer nada por ahora.";
    }

    // ── Pasos list ───────────────────────────────────────────────────────
    $pasos = [];
    if (!$hasPunto) {
        $pasos[] = 'Asignamos tu punto de entrega en menos de 48 horas y te avisamos por WhatsApp';
    }
    $pasos[] = 'Preparamos tu moto en nuestro CEDIS';
    $pasos[] = 'La enviamos a tu punto de entrega';
    $pasos[] = 'Te avisamos por WhatsApp cuando salga de nuestras instalaciones';
    $pasos[] = 'Te avisamos cuando llegue al punto';
    $pasos[] = 'Te avisamos cuando esté lista con fecha y hora exacta para recogerla';
    $pasos[] = 'Llegas al punto con tu INE, firmas digitalmente y te llevas tu moto lista para circular';

    $pasosHtml = '';
    foreach ($pasos as $i => $p) {
        $pasosHtml .= '<div style="display:flex;gap:10px;margin:6px 0;font-size:13.5px;color:#333;line-height:1.5;">'
                    . '<span style="color:#039fe1;font-weight:700;flex-shrink:0;">' . ($i+1) . '️⃣</span>'
                    . '<span>' . $p . '</span></div>';
    }
    $pasosText = '';
    foreach ($pasos as $i => $p) {
        $pasosText .= ($i+1) . "️⃣ " . $p . "\n";
    }

    // ── Portal bullets (differ by credit) ────────────────────────────────
    $portalItems = ['Seguir tu pedido en tiempo real'];
    if ($isCredit) {
        $portalItems[] = 'Ver y realizar tus pagos semanales';
        $portalItems[] = 'Adelantar pagos sin penalización';
    }
    $portalItems[] = 'Descargar tu permiso temporal para circular';
    if (!$isCredit) {
        $portalItems[] = 'Consultar y descargar tu factura';
    }
    $portalItems[] = 'Descargar tu contrato y acta de entrega';
    $portalItems[] = 'Ver cotizaciones de seguro y placas si las solicitaste';

    $portalHtml = '';
    foreach ($portalItems as $it) {
        $portalHtml .= '<div style="font-size:13.5px;color:#333;margin:4px 0;">✅ ' . $it . '</div>';
    }

    // ── Pagos semanales section (credit only) ────────────────────────────
    $pagosHtml = '';
    if ($isCredit) {
        $pagosHtml = '<tr><td style="padding:14px 28px;">'
                   . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">💳 Tus pagos semanales</div>'
                   . '<p style="font-size:13.5px;color:#333;line-height:1.6;margin:0 0 10px;">Tu primer pago semanal de <strong>\${monto_semanal}</strong> inicia únicamente el día que recibas tu moto en mano.</p>'
                   . '<p style="font-size:13.5px;color:#444;line-height:1.6;margin:0 0 10px;">No se genera ningún cargo antes de la entrega.</p>'
                   . '<p style="font-size:13px;color:#555;margin:10px 0 4px;">Puedes pagar con:</p>'
                   . '<div style="font-size:13px;color:#333;line-height:1.8;">🏪 Efectivo en cualquier OXXO<br>🏦 Transferencia SPEI<br>💳 Tarjeta en tu portal</div>'
                   . '<p style="font-size:13px;color:#555;margin:10px 0 0;">Consulta tus fechas de pago y realiza pagos desde tu portal:<br><a href="https://voltika.mx/mi-cuenta" style="color:#039fe1;font-weight:700;">👉 voltika.mx/mi-cuenta</a></p>'
                   . '</td></tr>';
    }

    // ── Factura section (differs by credit) ──────────────────────────────
    if ($isCredit) {
        $facturaText = 'Tu factura se genera desde el inicio pero estará disponible en tu portal cuando completes todos tus pagos. Mientras tanto tu contrato y acta de entrega están disponibles desde el día de la entrega en:';
    } else {
        $facturaText = 'Tu factura estará disponible al momento de la entrega en:';
    }
    $facturaRfc = $isCredit ? '' : '<p style="font-size:13px;color:#555;margin:10px 0 0;">¿Necesitas registrar tu RFC? Escríbenos antes de la entrega:<br>📧 <a href="mailto:ventas@voltika.mx" style="color:#039fe1;font-weight:700;">ventas@voltika.mx</a></p>';

    // ── Full email HTML ──────────────────────────────────────────────────
    $emailHtml = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Voltika — Pedido confirmado</title></head>'
               . '<body style="margin:0;padding:0;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif;color:#1a3a5c;">'
               . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;"><tr><td align="center" style="padding:24px 12px;">'
               . '<table width="620" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;max-width:620px;width:100%;box-shadow:0 2px 12px rgba(0,0,0,0.08);">'
               // Header
               . '<tr><td style="background:linear-gradient(135deg,#1a3a5c,#039fe1);padding:26px;text-align:center;color:#fff;">'
               . '<div style="font-size:24px;font-weight:800;letter-spacing:1px;">voltika <span style="color:#22d37a;">⚡</span></div>'
               . '<div style="font-size:17px;font-weight:700;margin-top:12px;">🎉 ¡Tu VOLTIKA está confirmada' . ($isCredit ? ' a plazos' : '') . '!</div>'
               . '<div style="font-size:13px;opacity:.85;margin-top:4px;">Pedido VK-{pedido}</div>'
               . '</td></tr>'
               // Welcome
               . '<tr><td style="padding:22px 28px 6px;">'
               . '<div style="font-size:17px;color:#1a3a5c;">Hola <strong>{nombre}</strong> 🎉</div>'
               . '<p style="font-size:14px;line-height:1.6;color:#444;margin:10px 0 0;">¡Bienvenido a la familia VOLTIKA!<br>Tu <strong>{modelo}</strong> en color <strong>{color}</strong> ya está confirmada y en preparación.</p>'
               . '<p style="font-size:12px;color:#666;margin:8px 0 0;">Pedido: <strong>VK-{pedido}</strong></p>'
               . '</td></tr>'
               // Punto
               . '<tr><td style="padding:10px 28px;">'
               . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">📍 Tu punto de entrega</div>'
               . $puntoHtml
               . '</td></tr>'
               // Pasos
               . '<tr><td style="padding:6px 28px;">'
               . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">🔄 Lo que va a pasar — paso a paso</div>'
               . $pasosHtml
               . '<p style="font-size:12px;color:#777;background:#f5f7fa;padding:10px 12px;border-radius:6px;margin-top:10px;line-height:1.5;">📲 Recibirás WhatsApp automático en cada paso. No necesitas llamar ni escribir para saber cómo va tu pedido — todo llega solo.</p>'
               . '</td></tr>'
               // Portal
               . '<tr><td style="padding:14px 28px;">'
               . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">📱 Tu portal de cliente</div>'
               . '<p style="font-size:13.5px;color:#333;margin:0 0 8px;line-height:1.6;">Todo lo de tu compra en un solo lugar. Entra con tu número de celular:</p>'
               . '<p style="margin:0 0 12px;"><a href="https://voltika.mx/mi-cuenta" style="color:#039fe1;font-weight:700;">👉 voltika.mx/mi-cuenta</a></p>'
               . '<p style="font-size:13px;color:#555;margin:8px 0 4px;">Desde tu portal puedes:</p>'
               . $portalHtml
               . '</td></tr>'
               . $pagosHtml
               // Permiso
               . '<tr><td style="padding:14px 28px;">'
               . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">📄 Permiso temporal para circular</div>'
               . '<p style="font-size:13.5px;color:#333;line-height:1.6;margin:0 0 10px;">Tu permiso estará disponible en tu portal el día que recojas tu moto.</p>'
               . '<p style="font-size:12.5px;color:#b45309;background:#fffbeb;border-left:3px solid #f59e0b;padding:8px 12px;border-radius:4px;margin:8px 0;line-height:1.6;"><strong>⚠️ Entra en vigencia ese día</strong> y tienes <strong>30 días para tramitar tus placas definitivas</strong>.</p>'
               . '<p style="font-size:13px;color:#555;margin:8px 0 0;">Descárgalo e imprímelo ese mismo día: <a href="https://voltika.mx/mi-cuenta" style="color:#039fe1;font-weight:700;">👉 voltika.mx/mi-cuenta</a></p>'
               . '</td></tr>'
               // Factura
               . '<tr><td style="padding:14px 28px;">'
               . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">🧾 Tu factura</div>'
               . '<p style="font-size:13.5px;color:#333;line-height:1.6;margin:0;">' . $facturaText . '<br><a href="https://voltika.mx/mi-cuenta" style="color:#039fe1;font-weight:700;">👉 voltika.mx/mi-cuenta</a></p>'
               . $facturaRfc
               . '</td></tr>'
               // Seguro y placas
               . '<tr><td style="padding:14px 28px;">'
               . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">🛡️ Seguro y 🪪 placas</div>'
               . '<p style="font-size:13.5px;color:#333;line-height:1.6;margin:0 0 8px;">Si solicitaste asesoría de seguro o gestor de placas recibirás un correo por separado con toda la información.</p>'
               . '<p style="font-size:13px;color:#555;margin:0;">También podrás consultarla en tu portal en cualquier momento: <a href="https://voltika.mx/mi-cuenta" style="color:#039fe1;font-weight:700;">👉 voltika.mx/mi-cuenta</a></p>'
               . '</td></tr>'
               // Support
               . '<tr><td style="padding:14px 28px 4px;">'
               . '<p style="font-size:13px;color:#555;margin:0;">¿Tienes alguna duda?<br>📧 <a href="mailto:ventas@voltika.mx" style="color:#039fe1;font-weight:700;">ventas@voltika.mx</a><br>🕐 Lunes a Viernes 9:00 - 18:00 hrs</p>'
               . '</td></tr>'
               // Footer
               . '<tr><td style="background:#1a3a5c;padding:18px 28px;text-align:center;color:#fff;">'
               . '<div style="font-size:14px;font-weight:700;">voltika <span style="color:#22d37a;">⚡</span></div>'
               . '<div style="font-size:11px;opacity:.7;margin-top:4px;">voltika.mx · Mtech Gears S.A. de C.V.</div>'
               . '</td></tr>'
               . '</table></td></tr></table></body></html>';

    // ── WhatsApp body (compact) ──────────────────────────────────────────
    if ($hasPunto) {
        $waPunto = "📍 Tu punto de entrega:\n🏪 {punto} — {ciudad}\n📅 Entrega antes del {fecha_estimada}";
    } else {
        $waPunto = "📍 Tu punto de entrega:\nEstamos asignando el punto más cercano a ti en {ciudad}.\nTe confirmamos en menos de 48 horas.";
    }
    // WhatsApp pasos — drop "Llegas al punto" step; short list
    $waPasos = [];
    if (!$hasPunto) $waPasos[] = 'Asignamos tu punto en 48 horas';
    $waPasos[] = 'Preparamos tu moto';
    $waPasos[] = 'La enviamos a tu punto';
    $waPasos[] = 'Te avisamos cuando salga';
    $waPasos[] = 'Te avisamos cuando llegue';
    $waPasos[] = 'Te avisamos cuando esté lista';
    $waPasosText = '';
    foreach ($waPasos as $i => $p) $waPasosText .= ($i+1) . "️⃣ " . $p . "\n";

    $waPortalExtra = $isCredit
        ? "Desde hoy puedes ver tu pedido,\ntus pagos y tus documentos.\n\n💳 Tu primer pago semanal de\n\${monto_semanal} inicia el día\nque recibas tu moto.\nSin cargos antes de la entrega."
        : "Desde hoy puedes ver tu pedido\ny tus documentos en tiempo real.";

    $waFactura = $isCredit
        ? "🧾 Tu factura estará disponible\nen tu portal al liquidar tu plan\nde pagos completo."
        : "🧾 Tu factura estará lista\nal momento de la entrega.";

    $body = "🎉 ¡{nombre}, bienvenido a la\nfamilia VOLTIKA!\n\n"
          . "Tu {modelo} {color} está confirmada\ny en preparación ✅\n"
          . "Pedido: VK-{pedido}\n\n"
          . $waPunto . "\n\n"
          . "🔄 Lo que sigue:\n"
          . rtrim($waPasosText, "\n") . "\n\n"
          . "📲 Te notificamos aquí en cada paso.\nNo necesitas llamar ni escribir.\n\n"
          . "━━━━━━━━━━━━━━━━━━━━\n\n"
          . "📱 Tu portal de cliente:\n👉 voltika.mx/mi-cuenta\n\n"
          . $waPortalExtra . "\n\n"
          . "📄 Tu permiso temporal para circular\nestará disponible el día que recojas\ntu moto. Entra en vigencia ese día\ny tienes 30 días para tramitar placas.\n\n"
          . $waFactura . "\n\n"
          . "━━━━━━━━━━━━━━━━━━━━\n\n"
          . "¿Dudas? 📧 ventas@voltika.mx";

    // SMS (very short)
    $sms = 'VOLTIKA: {nombre}, tu {modelo} {color} está confirmada. Pedido VK-{pedido}. '
         . ($hasPunto ? 'Punto: {punto} — {ciudad}.' : 'Asignaremos tu punto en 48h.')
         . ' Portal: voltika.mx/mi-cuenta';

    return [
        'subject'    => $subject,
        'body'       => $body,
        'sms'        => $sms,
        'email_html' => $emailHtml,
    ];
}

/**
 * Build a portal-access template (delayed 5 min after purchase).
 *
 *   $isCredit — true for plazos/crédito (rich flow: pagos + cambio tarjeta +
 *               adelantar pagos + PAGOS SIN DUPLICADO + factura diferida).
 *               false for contado/MSI (simple flow: estado + docs + factura
 *               inmediata).
 *
 * Customer brief 2026-04-19.
 */
function voltikaBuildPortalTemplate(bool $isCredit): array {
    $subject = '🔐 Ya tienes acceso a tu portal VOLTIKA — Pedido VK-{pedido}';

    // Portal bullets (HTML + WhatsApp text)
    $items = [];
    $items[] = [
        'icon' => '✅',
        'title' => 'ESTADO DE TU PEDIDO',
        'desc'  => 'Sigue en tiempo real cada etapa de tu moto — desde preparación en CEDIS hasta que esté lista para recoger en tu punto.',
    ];
    if ($isCredit) {
        $items[] = ['icon'=>'✅','title'=>'TUS PAGOS','desc'=>'Consulta tus pagos realizados y pendientes. Paga desde el portal con tarjeta, OXXO o transferencia SPEI cuando prefieras.'];
        $items[] = ['icon'=>'✅','title'=>'ADELANTAR PAGOS','desc'=>'Puedes adelantar pagos sin ningún cargo extra directamente desde tu portal.'];
        $items[] = ['icon'=>'✅','title'=>'CAMBIAR TU TARJETA DOMICILIADA','desc'=>'Actualiza tu tarjeta de cobro automático cuando quieras sin necesidad de llamar.'];
    }
    $items[] = ['icon'=>'✅','title'=>'TUS DOCUMENTOS','desc'=>'Descarga tu contrato de compra disponible desde hoy.'];
    $items[] = ['icon'=>'✅','title'=>'INFORMACIÓN DE TU MOTO','desc'=>'Todos los detalles de tu {modelo} en color {color}.'];
    $items[] = ['icon'=>'✅','title'=>'PERMISO TEMPORAL PARA CIRCULAR','desc'=>'Disponible en tu portal el día que recojas tu moto. Entra en vigencia ese día — tienes 30 días para tramitar tus placas definitivas.'];
    if ($isCredit) {
        $items[] = ['icon'=>'✅','title'=>'TU FACTURA','desc'=>'Tu factura se genera desde el inicio pero estará disponible en tu portal cuando completes todos tus pagos.'];
    } else {
        $items[] = ['icon'=>'✅','title'=>'TU FACTURA','desc'=>'Disponible al momento de la entrega. Si necesitas registrar tu RFC antes de esa fecha escríbenos: 📧 ventas@voltika.mx'];
    }
    $items[] = ['icon'=>'✅','title'=>'SEGURO Y PLACAS','desc'=>'Si solicitaste asesoría de seguro o gestor de placas encontrarás toda la información aquí.'];

    $itemsHtml = '';
    foreach ($items as $it) {
        $itemsHtml .= '<div style="margin:10px 0;padding:10px 12px;background:#f5f7fa;border-radius:6px;">'
                    . '<div style="font-size:12px;font-weight:700;color:#1a3a5c;letter-spacing:.3px;margin-bottom:3px;">' . $it['icon'] . ' ' . $it['title'] . '</div>'
                    . '<div style="font-size:13px;color:#444;line-height:1.55;">' . $it['desc'] . '</div>'
                    . '</div>';
    }

    // Credit-only sections
    $pagosHtml = '';
    $duplicadoHtml = '';
    if ($isCredit) {
        $pagosHtml = '<tr><td style="padding:14px 28px;">'
                   . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">💳 Tu primer pago semanal</div>'
                   . '<p style="font-size:13.5px;color:#333;line-height:1.6;margin:0 0 8px;">Tu primer pago de <strong>\${monto_semanal}</strong> inicia únicamente el día que recibas tu moto en mano.</p>'
                   . '<p style="font-size:13px;color:#444;line-height:1.5;margin:0;">No se genera ningún cargo antes de la entrega.</p>'
                   . '</td></tr>';
        $duplicadoHtml = '<tr><td style="padding:14px 28px;">'
                       . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">💡 Pagos sin duplicado</div>'
                       . '<p style="font-size:13.5px;color:#333;line-height:1.6;margin:0;">Si realizas un pago manual (OXXO, transferencia o adelanto) tu cargo automático no se duplica — el sistema lo detecta y cancela el cobro de esa semana.</p>'
                       . '</td></tr>';
    }

    // Full email HTML
    $emailHtml = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Acceso al portal Voltika</title></head>'
               . '<body style="margin:0;padding:0;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif;color:#1a3a5c;">'
               . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;"><tr><td align="center" style="padding:24px 12px;">'
               . '<table width="620" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;max-width:620px;width:100%;box-shadow:0 2px 12px rgba(0,0,0,0.08);">'
               . '<tr><td style="background:linear-gradient(135deg,#1a3a5c,#039fe1);padding:26px;text-align:center;color:#fff;">'
               . '<div style="font-size:24px;font-weight:800;letter-spacing:1px;">voltika <span style="color:#22d37a;">⚡</span></div>'
               . '<div style="font-size:17px;font-weight:700;margin-top:12px;">🔐 Tu portal ya está activo</div>'
               . '<div style="font-size:13px;opacity:.85;margin-top:4px;">Pedido VK-{pedido}</div>'
               . '</td></tr>'
               . '<tr><td style="padding:22px 28px 6px;">'
               . '<div style="font-size:17px;color:#1a3a5c;">Hola <strong>{nombre}</strong> 👋</div>'
               . '<p style="font-size:14px;line-height:1.6;color:#444;margin:10px 0 0;">Tu portal de cliente VOLTIKA ya está activo y listo para usar.</p>'
               . '</td></tr>'
               . '<tr><td style="padding:10px 28px 4px;">'
               . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">📱 Entra a tu portal ahora</div>'
               . '<p style="font-size:13.5px;color:#333;margin:0 0 6px;">Accede con tu número de celular registrado:</p>'
               . '<div style="text-align:center;margin:14px 0;">'
               . '<a href="https://voltika.mx/mi-cuenta" style="display:inline-block;background:#039fe1;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;">Entrar a mi portal</a>'
               . '</div></td></tr>'
               . '<tr><td style="padding:12px 28px;">'
               . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">¿Qué encuentras en tu portal?</div>'
               . $itemsHtml
               . '</td></tr>'
               . $pagosHtml
               . $duplicadoHtml
               . '<tr><td style="padding:14px 28px;">'
               . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">📲 Notificaciones automáticas</div>'
               . '<p style="font-size:13.5px;color:#333;line-height:1.6;margin:0;">Recibirás WhatsApp en cada paso del proceso de entrega de tu moto. No necesitas llamar ni escribir para saber cómo va tu pedido — todo llega solo a tu celular.</p>'
               . '</td></tr>'
               . '<tr><td style="padding:14px 28px 4px;">'
               . '<p style="font-size:13px;color:#555;margin:0;">¿Tienes alguna duda?<br>📧 <a href="mailto:ventas@voltika.mx" style="color:#039fe1;font-weight:700;">ventas@voltika.mx</a><br>🕐 Lunes a Viernes 9:00 - 18:00 hrs</p>'
               . '</td></tr>'
               . '<tr><td style="background:#1a3a5c;padding:18px 28px;text-align:center;color:#fff;">'
               . '<div style="font-size:14px;font-weight:700;">voltika <span style="color:#22d37a;">⚡</span></div>'
               . '<div style="font-size:11px;opacity:.7;margin-top:4px;">voltika.mx · Mtech Gears S.A. de C.V.</div>'
               . '</td></tr>'
               . '</table></td></tr></table></body></html>';

    // WhatsApp body
    if ($isCredit) {
        $body = "🔐 {nombre}, ya tienes acceso a\ntu portal VOLTIKA ⚡\n\n"
              . "Entra ahora con tu número de celular:\n👉 voltika.mx/mi-cuenta\n\n"
              . "Desde tu portal puedes:\n"
              . "✅ Ver el estado de tu pedido\n   en tiempo real\n"
              . "✅ Ver tus pagos realizados\n   y pendientes\n"
              . "✅ Descargar tu contrato de compra\n"
              . "✅ Cambiar tu tarjeta domiciliada\n   cuando quieras\n"
              . "✅ Adelantar pagos sin penalización\n"
              . "✅ Pagar en OXXO o por transferencia\n   cuando prefieras\n"
              . "✅ Descargar tu permiso temporal\n   para circular — disponible el\n   día que recojas tu moto\n"
              . "✅ Ver tus cotizaciones de seguro\n   y placas si las solicitaste\n\n"
              . "💡 Si realizas un pago manual\n(OXXO, transferencia o adelanto)\ntu cargo automático no se duplica —\nel sistema lo detecta y cancela\nel cobro de esa semana.\n\n"
              . "⚠️ Tu primer pago semanal de\n\${monto_semanal} inicia el día\nque recibas tu moto en mano.\nSin cargos antes de la entrega.\n\n"
              . "📲 También te notificamos aquí\nen cada paso del proceso.\nNo necesitas llamar ni escribir\npara saber cómo va tu pedido.\n\n"
              . "¿Dudas? 📧 ventas@voltika.mx\n🕐 Lunes a Viernes 9:00 - 18:00 hrs";
    } else {
        // Contado/MSI WhatsApp (customer didn't provide explicit WA — mirror the
        // Crédito style but with the shorter bullet list).
        $body = "🔐 {nombre}, ya tienes acceso a\ntu portal VOLTIKA ⚡\n\n"
              . "Entra ahora con tu número de celular:\n👉 voltika.mx/mi-cuenta\n\n"
              . "Desde tu portal puedes:\n"
              . "✅ Ver el estado de tu pedido\n   en tiempo real\n"
              . "✅ Descargar tu contrato de compra\n"
              . "✅ Consultar los detalles de tu\n   {modelo} {color}\n"
              . "✅ Descargar tu permiso temporal\n   para circular — disponible el\n   día que recojas tu moto\n"
              . "✅ Consultar y descargar tu factura\n   al momento de la entrega\n"
              . "✅ Ver tus cotizaciones de seguro\n   y placas si las solicitaste\n\n"
              . "📲 También te notificamos aquí\nen cada paso del proceso.\nNo necesitas llamar ni escribir\npara saber cómo va tu pedido.\n\n"
              . "¿Dudas? 📧 ventas@voltika.mx\n🕐 Lunes a Viernes 9:00 - 18:00 hrs";
    }

    $sms = 'VOLTIKA: {nombre}, tu portal ya está activo. Entra con tu celular en voltika.mx/mi-cuenta. Dudas: ventas@voltika.mx';

    return [
        'subject'    => $subject,
        'body'       => $body,
        'sms'        => $sms,
        'email_html' => $emailHtml,
    ];
}

function voltikaNotifyTemplates(): array {
    // Build the 4 purchase-confirmation templates.
    // Keys: compra_confirmada_{contado|credito}_{punto|sin_punto}
    $tplCP  = voltikaBuildCompraTemplate(false, true);   // contado con punto
    $tplCNP = voltikaBuildCompraTemplate(false, false);  // contado sin punto
    $tplKP  = voltikaBuildCompraTemplate(true,  true);   // crédito con punto
    $tplKNP = voltikaBuildCompraTemplate(true,  false);  // crédito sin punto

    // Portal access templates (rewritten 2026-04-19). MSI uses the same
    // content as Contado — customer didn't provide a distinct MSI variant.
    $tplPortalContado = voltikaBuildPortalTemplate(false);
    $tplPortalCredito = voltikaBuildPortalTemplate(true);

    return [
        'compra_confirmada_contado_punto'     => $tplCP,
        'compra_confirmada_contado_sin_punto' => $tplCNP,
        'compra_confirmada_credito_punto'     => $tplKP,
        'compra_confirmada_credito_sin_punto' => $tplKNP,

        // New portal access templates (replaces legacy inline definitions below)
        'portal_contado' => $tplPortalContado,
        'portal_msi'     => $tplPortalContado,
        'portal_plazos'  => $tplPortalCredito,

        // ═══════════════════════════════════════════════════════════════════
        // INTERNAL — DEALER/PUNTO CREDENTIALS
        // ═══════════════════════════════════════════════════════════════════

        // Sent to newly-created dealer/admin user with login credentials.
        // Rewritten 2026-04-19 per customer brief: richer welcome + legal notice
        // + manual download link.
        'credenciales_punto' => [
            'subject' => 'Bienvenido a la red VOLTIKA — {punto}',
            // WhatsApp body (shorter, emoji-friendly)
            'body'    => "🔐 Hola {nombre}, bienvenido a la red VOLTIKA ⚡\n\n"
                       . "Ya tienes acceso al Panel de Operaciones como {rol}.\n\n"
                       . "📍 Punto: {punto}\n"
                       . "🌐 Panel: https://{url}\n"
                       . "👤 Usuario: {email}\n"
                       . "🔒 Clave: {password}\n\n"
                       . "⚠️ Cambia tu contraseña al entrar por primera vez.\n\n"
                       . "📎 Revisa el manual adjunto en tu correo antes de tu primera operación:\n"
                       . "https://voltika.mx/docs/manual-operador-punto.pdf\n\n"
                       . "¿Dudas? Comunícate directamente con el ejecutivo VOLTIKA que te afilió — él es tu contacto principal.\n"
                       . "📧 puntos@voltika.mx\n"
                       . "🕐 Lunes a Viernes 9:00 - 18:00 hrs\n\n"
                       . "Este es un mensaje automático — no respondas aquí.",
            // SMS body (single line, no emoji for Mexican carrier compatibility)
            'sms'     => 'VOLTIKA: Hola {nombre}, ya tienes acceso como {rol}. Usuario: {email} Clave: {password} Panel: https://{url} Cambia tu clave al entrar. Dudas: puntos@voltika.mx',
            // Rich HTML email
            'email_html' => '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Bienvenido a VOLTIKA</title></head>'
                         . '<body style="margin:0;padding:0;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif;color:#1a3a5c;">'
                         . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;">'
                         . '<tr><td align="center" style="padding:24px 12px;">'
                         . '<table width="620" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;max-width:620px;width:100%;box-shadow:0 2px 12px rgba(0,0,0,0.08);">'
                         // Header
                         . '<tr><td style="background:linear-gradient(135deg,#1a3a5c,#039fe1);padding:28px;text-align:center;color:#fff;">'
                         . '<div style="font-size:26px;font-weight:800;letter-spacing:1px;">voltika <span style="color:#22d37a;">⚡</span></div>'
                         . '<div style="font-size:13px;opacity:.85;margin-top:4px;">Red de Puntos Oficiales</div>'
                         . '<div style="font-size:17px;font-weight:700;margin-top:14px;">Bienvenido al equipo</div>'
                         . '<div style="font-size:14px;opacity:.9;margin-top:2px;">{punto}</div>'
                         . '</td></tr>'
                         // Welcome
                         . '<tr><td style="padding:26px 28px 10px;">'
                         . '<div style="font-size:17px;color:#1a3a5c;">Hola <strong>{nombre}</strong> 👋</div>'
                         . '<p style="font-size:14px;line-height:1.6;color:#444;margin:10px 0 0;">Ya eres parte oficial de la red VOLTIKA como <strong>{rol}</strong>. Tu acceso al Panel de Operaciones está activo y listo para usar desde ahora mismo.</p>'
                         . '</td></tr>'
                         // Credenciales
                         . '<tr><td style="padding:14px 28px;">'
                         . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">🔑 Tus credenciales de acceso</div>'
                         . '<table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;background:#f5f7fa;border-radius:8px;padding:4px;">'
                         . '<tr><td style="padding:8px 14px;color:#666;">Punto</td><td style="padding:8px 14px;font-weight:700;">{punto}</td></tr>'
                         . '<tr><td style="padding:8px 14px;color:#666;">Panel</td><td style="padding:8px 14px;"><a href="https://{url}" style="color:#039fe1;font-weight:700;">https://{url}</a></td></tr>'
                         . '<tr><td style="padding:8px 14px;color:#666;">Usuario</td><td style="padding:8px 14px;font-family:ui-monospace,Consolas,monospace;">{email}</td></tr>'
                         . '<tr><td style="padding:8px 14px;color:#666;">Contraseña</td><td style="padding:8px 14px;font-family:ui-monospace,Consolas,monospace;font-weight:700;">{password}</td></tr>'
                         . '</table>'
                         . '<p style="font-size:12px;color:#b45309;background:#fffbeb;border-left:3px solid #f59e0b;padding:8px 12px;border-radius:4px;margin-top:12px;"><strong>⚠️ Cambia tu contraseña en tu primer inicio de sesión.</strong><br>Menú superior → tu nombre → Cambiar contraseña. No compartas tus credenciales con nadie.</p>'
                         . '</td></tr>'
                         // Manual
                         . '<tr><td style="padding:14px 28px;">'
                         . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">📎 Manual del operador</div>'
                         . '<p style="font-size:13px;color:#444;line-height:1.6;margin:0 0 10px;">Todo lo que necesitas saber está en el manual. <strong>Léelo antes de tu primera operación</strong>. Incluye capturas reales del panel y protocolos paso a paso.</p>'
                         . '<ul style="font-size:13px;color:#444;line-height:1.8;padding-left:20px;margin:4px 0 12px;">'
                         . '<li>Cómo usar el panel</li>'
                         . '<li>Recepción de motos</li>'
                         . '<li>Proceso de entrega</li>'
                         . '<li>Tus comisiones</li>'
                         . '<li>Venta por referido</li>'
                         . '<li>Protocolos de emergencia</li>'
                         . '</ul>'
                         . '<div style="text-align:center;margin:16px 0;">'
                         . '<a href="https://voltika.mx/docs/manual-operador-punto.pdf" target="_blank" style="display:inline-block;background:#039fe1;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;">Descargar manual del operador</a>'
                         . '</div>'
                         . '</td></tr>'
                         // Legal notice
                         . '<tr><td style="padding:14px 28px;">'
                         . '<div style="background:#fef2f2;border-left:4px solid #dc2626;padding:14px 16px;border-radius:6px;">'
                         . '<div style="font-size:13px;font-weight:700;color:#991b1b;margin-bottom:6px;">🚨 LEE EL MANUAL ANTES DE TU PRIMERA ENTREGA</div>'
                         . '<p style="font-size:12.5px;color:#7a0e1f;line-height:1.6;margin:0;">Entregar una moto sin completar la <strong>validación facial</strong> y el <strong>OTP</strong> en el sistema hace al punto responsable del <strong>valor total de la moto</strong>. El manual explica el proceso completo. Sin excepciones.</p>'
                         . '</div>'
                         . '</td></tr>'
                         // Soporte
                         . '<tr><td style="padding:14px 28px;">'
                         . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">💬 ¿Tienes dudas? Estamos aquí</div>'
                         . '<p style="font-size:13px;color:#444;line-height:1.7;margin:0;">'
                         . '📱 WhatsApp: <a href="https://wa.me/525579440928" style="color:#039fe1;font-weight:700;">557 944 0928</a><br>'
                         . '📧 Email: <a href="mailto:puntos@voltika.mx" style="color:#039fe1;font-weight:700;">puntos@voltika.mx</a><br>'
                         . '🕐 Lunes a Viernes 9:00 - 18:00 hrs</p>'
                         . '<p style="font-size:12.5px;color:#555;line-height:1.6;margin:10px 0 0;">👤 Comunícate con el ejecutivo VOLTIKA que te contactó para afiliarte — él es tu contacto principal para dudas y capacitación por videollamada.</p>'
                         . '</td></tr>'
                         // Footer
                         . '<tr><td style="background:#1a3a5c;padding:20px 28px;text-align:center;color:#fff;">'
                         . '<div style="font-size:14px;font-weight:700;">voltika <span style="color:#22d37a;">⚡</span></div>'
                         . '<div style="font-size:11px;opacity:.7;margin-top:4px;">Movilidad eléctrica · Red Nacional · México</div>'
                         . '<div style="font-size:11px;opacity:.7;margin-top:2px;">voltika.mx · puntos@voltika.mx</div>'
                         . '</td></tr>'
                         . '</table>'
                         . '</td></tr></table></body></html>',
        ],

        // ═══════════════════════════════════════════════════════════════════
        // POST-PURCHASE MESSAGES
        // ═══════════════════════════════════════════════════════════════════

        // MSG 1 — Purchase confirmed, delivery point assigned
        'compra_punto_definido' => [
            'subject' => '🎉 Bienvenido a la familia VOLTIKA',
            'body'    => "🎉 ¡{nombre}, bienvenido a la familia VOLTIKA!\n\nTu {modelo} ya está confirmada y en preparación.\n\n📍 Tu punto de entrega:\n{punto} — {ciudad}\n\n🔄 Lo que sigue:\n1️⃣ Preparamos tu moto\n2️⃣ La enviamos a tu punto\n3️⃣ Te avisamos cuando llegue\n4️⃣ Te avisamos cuando esté lista para ti\n\n📲 Te notificamos por aquí en cada paso.\nNo necesitas hacer nada por ahora.",
            'sms'     => 'Voltika: Tu {modelo} esta confirmada. Punto de entrega: {punto}. Te notificamos en cada paso.',
        ],

        // MSG 2 — Purchase confirmed, delivery point pending
        'compra_punto_pendiente' => [
            'subject' => '🎉 Bienvenido a la familia VOLTIKA',
            'body'    => "🎉 ¡{nombre}, bienvenido a la familia VOLTIKA!\n\nTu {modelo} ya está confirmada y en preparación.\n\n📍 Punto de entrega:\nEstamos asignando el punto más cercano a ti.\nEn menos de 48 horas te confirmamos cuál es.\n\n🔄 Lo que sigue:\n1️⃣ Asignamos tu punto\n2️⃣ Preparamos tu moto\n3️⃣ La enviamos a tu punto\n4️⃣ Te avisamos cuando llegue\n5️⃣ Te avisamos cuando esté lista para ti\n\n📲 Te notificamos por aquí en cada paso.\nNo necesitas hacer nada por ahora.",
            'sms'     => 'Voltika: Tu {modelo} esta confirmada. Estamos asignando tu punto de entrega. Te avisamos en 48h.',
        ],

        // Legacy portal_contado/portal_msi/portal_plazos definitions removed
        // — replaced by voltikaBuildPortalTemplate() at top of this function
        // (2026-04-19 customer rewrite with rich email_html).

        // ═══════════════════════════════════════════════════════════════════
        // DELIVERY FLOW MESSAGES
        // ═══════════════════════════════════════════════════════════════════

        // MSG 3 — Motorcycle assigned and in transit
        'moto_enviada' => [
            'subject' => '📦 Tu Voltika ya fue asignada',
            'body'    => "📦 ¡{nombre}, tu {modelo} ya fue asignada!\n\nTu moto está en camino hacia tu punto de entrega:\n\n📍 {punto} — {ciudad}\n\n📲 Te avisamos en cuanto llegue.\nNo necesitas acudir todavía.",
            'sms'     => 'Voltika: Tu {modelo} va en camino a {punto}. Te avisamos cuando llegue.',
        ],

        // MSG 4 — Arrived at delivery point
        'moto_en_punto' => [
            'subject' => '📍 Tu Voltika ya llegó al punto',
            'body'    => "📍 ¡{nombre}, tu {modelo} ya llegó!\n\nTu moto está en:\n📍 {punto} — {ciudad}\n\n🔧 Está en revisión y preparación final.\n\n📲 Te avisamos en cuanto esté lista para recogerla.\nPor favor espera este mensaje antes de acudir.",
            'sms'     => 'Voltika: Tu {modelo} ya llego a {punto}. Esta en revision. Te avisamos cuando este lista.',
        ],

        // MSG 5 — Ready for pickup
        'lista_para_recoger' => [
            'subject' => '🚀 Tu Voltika está lista',
            'body'    => "🚀 ¡{nombre}, tu {modelo} está lista!\n\n📍 {punto} — {ciudad}\n📍 {direccion}\n🗺️ {maps_link}\n\n⏱️ Horario de entrega:\n{horario}\n\n🛡️ Para recogerla necesitas:\n✔️ Identificación oficial vigente\n✔️ Acceso a tu número registrado\n\n⚠️ Preséntate únicamente en el horario indicado.\n\n💳 Tu primer pago semanal inicia a partir de hoy — gestiona todo en:\n👉 voltika.mx/mi-cuenta",
            'sms'     => 'Voltika: Tu {modelo} esta lista en {punto}. Lleva tu INE. Horario: {horario}. voltika.mx/mi-cuenta',
        ],

        // ═══════════════════════════════════════════════════════════════════
        // PAYMENT FOLLOW-UP — Pending/abandoned orders
        // ═══════════════════════════════════════════════════════════════════

        // Sent from admin panel "Pago pendiente" → Enviar link. Reuses the
        // original Stripe voucher (SPEI/OXXO) or a new Checkout Session.
        'recordatorio_pago_pendiente' => [
            'subject' => '💳 Completa el pago de tu Voltika',
            'body'    => "Hola {nombre} 👋\n\nNotamos que aún no se ha confirmado el pago de tu Voltika ({modelo}).\n\nMonto: {monto_fmt}\n\nContinúa tu pago aquí:\n{link}\n\nSi ya lo pagaste en OXXO o por transferencia, espera unas horas a que se acredite o ignora este mensaje.\n\n¿Dudas? Escríbenos por WhatsApp: +52 55 1341 6370",
            'sms'     => 'Voltika: Completa el pago de tu {modelo} ({monto_fmt}): {link}',
        ],

        // ═══════════════════════════════════════════════════════════════════
        // INTERNAL — SERVICIOS ADICIONALES (Admin alerts)
        // ═══════════════════════════════════════════════════════════════════

        // Sent to Voltika admin when a new order requests license-plate advisory
        'admin_extras_placas' => [
            'subject' => '🎫 Nueva solicitud de placas — Pedido {pedido}',
            'body'    => "🎫 NUEVA SOLICITUD: asesoría de placas\n\nPedido: VK-{pedido}\nCliente: {nombre}\nTel. cliente: {telefono_cliente}\nEstado MX: {estado_mx}\nCiudad: {ciudad}\nModelo: {modelo}\n\nGestioná en: voltika.mx/admin/#ventas",
            'sms'     => 'Voltika ADMIN: Nueva solicitud placas. Pedido VK-{pedido} / {nombre} / {estado_mx}. Gestiona en admin.',
        ],

        // Sent to Voltika admin when a new order opts in for Quálitas insurance
        'admin_extras_seguro' => [
            'subject' => '🛡 Nueva solicitud Quálitas — Pedido {pedido}',
            'body'    => "🛡 NUEVA SOLICITUD: seguro Quálitas\n\nPedido: VK-{pedido}\nCliente: {nombre}\nTel. cliente: {telefono_cliente}\nUnidad: {modelo} · {color}\n\nCotizá y registra en: voltika.mx/admin/#ventas",
            'sms'     => 'Voltika ADMIN: Nueva solicitud Qualitas. Pedido VK-{pedido} / {nombre} / {modelo} {color}.',
        ],

        // ── Legacy delivery templates (kept for backward compat) ────────
        'punto_asignado' => [
            'subject' => '📍 Tu Voltika tiene punto de entrega',
            'body'    => "Hola {nombre},\n\nTu moto {modelo} será entregada en {punto} ({ciudad}).\nTe avisaremos cuando esté lista para recoger.\n\n¡Gracias por elegir Voltika! ⚡",
            'sms'     => 'Voltika: Tu moto sera entregada en {punto}. Te avisamos cuando este lista.',
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

        // ═══════════════════════════════════════════════════════════════════
        // WEEKLY PAYMENT COLLECTION (plazos_voltika only)
        // ═══════════════════════════════════════════════════════════════════

        // MSG A — Payment reminder (2 days before due)
        'recordatorio_pago_2dias' => [
            'subject' => '⏰ Recordatorio de pago — Voltika',
            'body'    => "Hola {nombre} 👋\n\nTu pago semanal VOLTIKA vence en 2 días.\n\nPaga fácil y rápido:\n\n🏪 Efectivo en cualquier OXXO\n🏦 Transferencia bancaria (SPEI)\n👉 {payment_link}\n\n💡 Si no pagas antes del vencimiento se intentará el cargo automático a tu tarjeta.\n\nEl pago no se duplica — el sistema lo detecta automáticamente.\n\n⏱️ OXXO y transferencia se acreditan en hasta 24 horas.",
            'sms'     => 'Voltika: Tu pago semanal vence en 2 dias. Paga en OXXO o SPEI: {payment_link}',
        ],

        // MSG B — Payment due today
        'pago_vence_hoy' => [
            'subject' => '⏰ Tu pago vence hoy — Voltika',
            'body'    => "Hola {nombre} 👋\n\nHoy vence tu pago semanal VOLTIKA.\n\nPaga ahora en segundos:\n\n🏪 Efectivo en cualquier OXXO\n🏦 Transferencia bancaria (SPEI)\n👉 {payment_link}\n\n💡 Si no se recibe pago hoy se intentará el cargo automático a tu tarjeta registrada.\n\nEl pago no se duplica — el sistema lo detecta automáticamente.\n\n⏱️ OXXO y transferencia se acreditan en hasta 24 horas.",
            'sms'     => 'Voltika: Hoy vence tu pago semanal. Paga en OXXO o SPEI: {payment_link}',
        ],

        // MSG C — First overdue (48 hours)
        'pago_vencido_48h' => [
            'subject' => '⚠️ Pago pendiente — Voltika',
            'body'    => "Hola {nombre} 👋\n\nTu pago semanal VOLTIKA aún no ha sido procesado.\n\nSi pagaste en OXXO o transferencia en las últimas 24 horas ignora este mensaje — tu pago está en proceso de acreditación.\n\nSi aún no has pagado, hazlo hoy para evitar cargos por atraso:\n\n🏪 Efectivo en cualquier OXXO\n🏦 Transferencia bancaria (SPEI)\n👉 {payment_link}\n\nTu estado actualizado:\n👉 voltika.mx/mi-cuenta",
            'sms'     => 'Voltika: Tu pago semanal aun no se procesa. Si ya pagaste en OXXO/SPEI, espera acreditacion. Si no: {payment_link}',
        ],

        // MSG D — Critical overdue (96 hours)
        'pago_vencido_96h' => [
            'subject' => '🔴 Pago vencido — Voltika',
            'body'    => "{nombre}, tu cuenta VOLTIKA tiene un pago vencido.\n\nTu saldo incluye cargos por atraso acumulados.\n\nRegulariza hoy para detener los cargos:\n\n🏪 Efectivo en cualquier OXXO\n🏦 Transferencia bancaria (SPEI)\n👉 {payment_link}\n\nEl pago no se duplica — el sistema lo detecta automáticamente.\n\nTu saldo actualizado:\n👉 voltika.mx/mi-cuenta\n\nPara aclaración: atencion@voltika.mx",
            'sms'     => 'Voltika: Tu cuenta tiene un pago vencido con cargos. Regulariza hoy: {payment_link} o contacta atencion@voltika.mx',
        ],

        // MSG E — Advance payment incentive
        'incentivo_adelanto' => [
            'subject' => '💡 Adelanta pagos sin cargo extra — Voltika',
            'body'    => "Hola {nombre} 👋\n\n¿Sabías que puedes adelantar pagos de tu VOLTIKA sin ningún cargo extra?\n\nCada pago que adelantas:\n✅ Reduce tu saldo pendiente\n✅ Acerca tu liquidación total\n✅ Sin ningún costo adicional\n\nAdelanta cuando quieras:\n\n🏪 Efectivo en cualquier OXXO\n🏦 Transferencia bancaria (SPEI)\n👉 {payment_link}\n\n⏱️ OXXO y transferencia se acreditan en hasta 24 horas.\n\nTu saldo actualizado:\n👉 voltika.mx/mi-cuenta",
            'sms'     => 'Voltika: Adelanta pagos sin cargo extra. Reduce tu saldo: {payment_link}. Info en voltika.mx/mi-cuenta',
        ],

        // ── Legacy payment templates (kept for backward compat) ─────────
        'pago_recibido' => [
            'subject' => '✅ Pago recibido — Voltika',
            'body'    => "Hola {nombre},\n\nRecibimos tu pago de \${monto} correctamente.\nSemana {semana} cubierta. Próximo pago: {proximo_pago}.\n\n¡Gracias!",
            'sms'     => 'Voltika: Pago de \${monto} recibido. Proximo pago: {proximo_pago}. Gracias!',
        ],
        'pago_vencido' => [
            'subject' => '⚠️ Pago vencido — Voltika',
            'body'    => "Hola {nombre},\n\nTu pago semanal de \${monto} está vencido.\nRegulariza en voltika.mx/clientes para evitar afectar tu historial crediticio.",
            'sms'     => 'Voltika: Tu pago de \${monto} esta vencido. Regularizalo en voltika.mx/clientes',
        ],
        'recordatorio_pago' => [
            'subject' => '⏰ Recordatorio de pago — Voltika',
            'body'    => "Hola {nombre},\n\nTe recordamos que tu pago semanal de \${monto} vence el {fecha}.\nPuedes pagar en voltika.mx/clientes o con tu tarjeta guardada.",
            'sms'     => 'Voltika: Recordatorio — pago de \${monto} vence {fecha}. Paga en voltika.mx/clientes',
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

    // Email — template can provide `email_html` for rich markup, otherwise
    // the default plain-text wrapper is used.
    if (!empty($data['email']) && function_exists('sendMail')) {
        $html = '';
        if (!empty($tpl['email_html'])) {
            $html = voltikaNotifyInterpolate($tpl['email_html'], $data);
        } else {
            $html = '<div style="font-family:system-ui,sans-serif;max-width:560px;margin:0 auto;padding:20px;color:#222">'
                  . '<h2 style="color:#22d37a;margin:0 0 14px">' . htmlspecialchars($subject) . '</h2>'
                  . '<p style="white-space:pre-line;line-height:1.6">' . htmlspecialchars($body) . '</p>'
                  . '<hr><p style="font-size:11px;color:#888">Este mensaje fue enviado automáticamente por Voltika. No respondas a este correo.</p>'
                  . '</div>';
        }
        $ok = false;
        try { $ok = (bool) @sendMail($data['email'], $data['nombre'] ?? '', $subject, $html); } catch (Throwable $e) { error_log('voltikaNotify email: ' . $e->getMessage()); }
        voltikaNotifyLog($clienteId, $tipo, 'email', $data['email'], $subject, $ok ? 'sent' : 'failed');
        $summary['channels']['email'] = $ok;
    }

    $summary['ok'] = true;
    return $summary;
}

/**
 * Schedule a delayed notification via a lightweight pending_notifications table.
 * A cron job (admin/cron/enviar-notificaciones.php) picks these up and sends them.
 * Used for the 5-minute-delay portal messages (MSG 1B/1C/1D).
 */
function voltikaNotifyDelayed(string $tipo, array $data, int $delaySeconds = 300): bool {
    voltikaNotifyEnsureTable();
    try {
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS pending_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tipo VARCHAR(60) NOT NULL,
            data_json TEXT NOT NULL,
            send_after DATETIME NOT NULL,
            sent TINYINT(1) DEFAULT 0,
            freg DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pending (sent, send_after)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $sendAfter = date('Y-m-d H:i:s', time() + $delaySeconds);
        $pdo->prepare("INSERT INTO pending_notifications (tipo, data_json, send_after) VALUES (?, ?, ?)")
            ->execute([$tipo, json_encode($data, JSON_UNESCAPED_UNICODE), $sendAfter]);
        return true;
    } catch (Throwable $e) {
        error_log('voltikaNotifyDelayed: ' . $e->getMessage());
        return false;
    }
}

} // if !function_exists
