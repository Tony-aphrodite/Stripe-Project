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

// ═════════════════════════════════════════════════════════════════════════
// SHARED EMAIL CHROME HELPERS (customer brief 2026-04-23)
// ─────────────────────────────────────────────────────────────────────────
// Customer reported that notification emails looked cheap because the
// header was text-only ("voltika ⚡") instead of a real logo. The reference
// design they approved (from confirmar-orden.php) uses the horizontal white
// logo on a cyan gradient with a tagline. These helpers are the single
// source of truth — every template now calls them instead of duplicating
// inline HTML, so future design tweaks propagate everywhere.
// ═════════════════════════════════════════════════════════════════════════

if (!function_exists('voltikaEmailHeader')) {
    /**
     * Gradient header with the real Voltika logo image + tagline + optional
     * hero title / sub. Used by every email template (compra, portal,
     * logistics shell, etc.).
     *
     *   $hero      — big headline text (e.g. "🎉 ¡Tu VOLTIKA está confirmada!")
     *                Pass '' to skip the hero line entirely.
     *   $heroSub   — small subtitle under the hero (e.g. "Pedido VK-XXXX")
     */
    function voltikaEmailHeader(string $hero = '', string $heroSub = ''): string {
        $heroHtml = '';
        if ($hero !== '') {
            $heroHtml .= '<div style="font-size:17px;font-weight:700;color:#fff;margin-top:14px;line-height:1.3;">' . $hero . '</div>';
        }
        if ($heroSub !== '') {
            $heroHtml .= '<div style="font-size:13px;color:rgba(255,255,255,0.85);margin-top:4px;">' . $heroSub . '</div>';
        }
        return '<tr><td style="background:linear-gradient(135deg,#1a3a5c 0%,#0d6aa0 50%,#039fe1 100%);padding:30px 28px;text-align:center;">'
             .   '<img src="https://www.voltika.mx/configurador_prueba/img/voltika_logo_h_white.svg"'
             .     ' alt="Voltika" style="height:44px;width:auto;display:block;margin:0 auto;">'
             .   '<p style="margin:6px 0 0;font-size:13px;color:rgba(255,255,255,0.8);letter-spacing:.2px;">Movilidad eléctrica inteligente</p>'
             .   $heroHtml
             . '</td></tr>';
    }
}

if (!function_exists('voltikaEmailFooter')) {
    /**
     * Navy footer with the white logo + legal line. Closes every email
     * consistently so the branding is matched to the header.
     */
    function voltikaEmailFooter(): string {
        return '<tr><td style="background:#1a3a5c;padding:22px 28px;text-align:center;">'
             .   '<img src="https://www.voltika.mx/configurador_prueba/img/voltika_logo_h_white.svg"'
             .     ' alt="Voltika" style="height:22px;width:auto;display:block;margin:0 auto 6px;opacity:.95;">'
             .   '<div style="font-size:11px;color:rgba(255,255,255,0.65);margin-top:4px;">voltika.mx · Mtech Gears S.A. de C.V.</div>'
             . '</td></tr>';
    }
}

if (!function_exists('voltikaEmailSectionLabel')) {
    /**
     * Cyan section label with a blue underline — matches the "TU VOLTIKA"
     * style the customer approved.
     */
    function voltikaEmailSectionLabel(string $title): string {
        return '<div style="margin:0 0 10px;padding:14px 0 6px;font-size:15px;font-weight:800;color:#1a3a5c;border-bottom:2px solid #039fe1;letter-spacing:.5px;text-transform:uppercase;">' . $title . '</div>';
    }
}

if (!function_exists('voltikaEmailDataTable')) {
    /**
     * Zebra-striped key/value data table — mirrors the "Cliente / Orden /
     * Modelo / Color" block from the reference design. Pass an array of
     * [label, value] pairs. Values may contain inline HTML.
     *
     *   $highlightLast — when true, the last row uses a cyan background +
     *                    bold value (e.g. for the grand total).
     */
    function voltikaEmailDataTable(array $rows, bool $highlightLast = false): string {
        $tdl  = 'style="padding:11px 16px;border-bottom:1px solid #E5E7EB;font-size:14px;color:#6B7280;"';
        $td   = 'style="padding:11px 16px;border-bottom:1px solid #E5E7EB;font-size:14px;color:#111;"';
        $html = '<table width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 18px;border:1px solid #E5E7EB;border-radius:8px;overflow:hidden;">';
        $last = count($rows) - 1;
        foreach ($rows as $i => $pair) {
            $label = $pair[0] ?? '';
            $value = $pair[1] ?? '';
            $rowStyle = '';
            $valStyle = $td;
            if ($highlightLast && $i === $last) {
                $rowStyle = ' style="background:#E8F4FD;"';
                $valStyle = 'style="padding:13px 16px;font-size:16px;color:#1a3a5c;font-weight:800;"';
                $tdlHL    = 'style="padding:13px 16px;font-size:14px;color:#1a3a5c;font-weight:700;"';
                $html .= '<tr' . $rowStyle . '><td ' . $tdlHL . '>' . $label . '</td><td ' . $valStyle . '>' . $value . '</td></tr>';
            } else {
                if ($i % 2 === 1) $rowStyle = ' style="background:#F9FAFB;"';
                $html .= '<tr' . $rowStyle . '><td ' . $tdl . '>' . $label . '</td><td ' . $valStyle . '>' . $value . '</td></tr>';
            }
        }
        $html .= '</table>';
        return $html;
    }
}

if (!function_exists('voltikaEmailShell')) {
    /**
     * Complete outer shell: <html>…body…table…HEADER…{innerRows}…FOOTER.
     * Any template can build its body as a string of <tr>…</tr> rows and
     * pass it here for a consistent wrapper.
     */
    function voltikaEmailShell(string $hero, string $heroSub, string $innerRows): string {
        return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Voltika</title></head>'
             . '<body style="margin:0;padding:0;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif;color:#1a3a5c;">'
             . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;"><tr><td align="center" style="padding:24px 12px;">'
             . '<table width="620" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:14px;overflow:hidden;max-width:620px;width:100%;box-shadow:0 4px 18px rgba(26,58,92,0.10);">'
             . voltikaEmailHeader($hero, $heroSub)
             . $innerRows
             . '<tr><td style="padding:16px 28px 10px;">'
             .   '<p style="font-size:13px;color:#555;margin:0;">¿Tienes alguna duda?<br>📧 <a href="mailto:ventas@voltika.mx" style="color:#039fe1;font-weight:700;text-decoration:none;">ventas@voltika.mx</a><br>🕐 Lunes a Viernes 9:00 - 18:00 hrs</p>'
             . '</td></tr>'
             . voltikaEmailFooter()
             . '</table></td></tr></table></body></html>';
    }
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
    $subject = '🎉 ¡Tu VOLTIKA está confirmada' . $plazos . '! — Pedido {pedido_corto}';

    // ── "TU PUNTO DE ENTREGA" section (HTML + text) ─────────────────────
    if ($hasPunto) {
        $puntoHtml = '<div style="background:#E8F4FD;border-radius:8px;padding:14px 16px;margin:6px 0 14px;">'
                   . '<div style="font-size:14px;line-height:1.7;color:#1a3a5c;">'
                   . '🏪 <strong>{punto}</strong><br>'
                   . '📬 {direccion_punto}<br>'
                   . '🗺️ <a href="{link_maps}" target="_blank" rel="noopener noreferrer" style="color:#039fe1;">Ver en Google Maps</a><br>'
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
                   . '<p style="font-size:13px;color:#555;margin:10px 0 0;">Consulta tus fechas de pago y realiza pagos desde tu portal:</p>'
                   . vkPortalBtn('💳 Ver mis pagos')
                   . '</td></tr>';
    }

    // ── Factura section (differs by credit) ──────────────────────────────
    if ($isCredit) {
        $facturaText = 'Tu factura se genera desde el inicio pero estará disponible en tu portal cuando completes todos tus pagos. Mientras tanto tu contrato y acta de entrega están disponibles desde el día de la entrega en:';
    } else {
        $facturaText = 'Tu factura estará disponible al momento de la entrega en:';
    }
    $facturaRfc = $isCredit ? '' : '<p style="font-size:13px;color:#555;margin:10px 0 0;">¿Necesitas registrar tu RFC? Escríbenos antes de la entrega:<br>📧 <a href="mailto:ventas@voltika.mx" style="color:#039fe1;font-weight:700;">ventas@voltika.mx</a></p>';

    // ── Full email HTML (uses the shared voltikaEmailHeader helper) ──────
    $emailHtml = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Voltika — Pedido confirmado</title></head>'
               . '<body style="margin:0;padding:0;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif;color:#1a3a5c;">'
               . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;"><tr><td align="center" style="padding:24px 12px;">'
               . '<table width="620" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:14px;overflow:hidden;max-width:620px;width:100%;box-shadow:0 4px 18px rgba(26,58,92,0.10);">'
               // Unified header (logo image + tagline + hero)
               . voltikaEmailHeader(
                    '🎉 ¡Tu VOLTIKA está confirmada' . ($isCredit ? ' a plazos' : '') . '!',
                    'Pedido {pedido_corto}'
                 )
               // Welcome
               . '<tr><td style="padding:22px 28px 6px;">'
               . '<div style="font-size:17px;color:#1a3a5c;">Hola <strong>{nombre}</strong> 🎉</div>'
               . '<p style="font-size:14px;line-height:1.6;color:#444;margin:10px 0 0;">¡Bienvenido a la familia VOLTIKA!<br>Tu <strong>{modelo}</strong> en color <strong>{color}</strong> ya está confirmada y en preparación.</p>'
               . '<p style="font-size:12px;color:#666;margin:8px 0 0;">Pedido: <strong>{pedido_corto}</strong></p>'
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
               . vkPortalBtn('👤 Seguir en mi cuenta')
               . '<p style="font-size:13px;color:#555;margin:8px 0 4px;">Desde tu portal puedes:</p>'
               . $portalHtml
               . '</td></tr>'
               . $pagosHtml
               // Permiso
               . '<tr><td style="padding:14px 28px;">'
               . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">📄 Permiso temporal para circular</div>'
               . '<p style="font-size:13.5px;color:#333;line-height:1.6;margin:0 0 10px;">Tu permiso estará disponible en tu portal el día que recojas tu moto.</p>'
               . '<p style="font-size:12.5px;color:#b45309;background:#fffbeb;border-left:3px solid #f59e0b;padding:8px 12px;border-radius:4px;margin:8px 0;line-height:1.6;"><strong>⚠️ Entra en vigencia ese día</strong> y tienes <strong>30 días para tramitar tus placas definitivas</strong>.</p>'
               . '<p style="font-size:13px;color:#555;margin:8px 0 0;">Descárgalo e imprímelo ese mismo día:</p>'
               . vkPortalBtn('📄 Descargar mi permiso')
               . '</td></tr>'
               // Factura
               . '<tr><td style="padding:14px 28px;">'
               . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">🧾 Tu factura</div>'
               . '<p style="font-size:13.5px;color:#333;line-height:1.6;margin:0;">' . $facturaText . '</p>'
               . vkPortalBtn('🧾 Ver mi factura')
               . $facturaRfc
               . '</td></tr>'
               // Seguro y placas
               . '<tr><td style="padding:14px 28px;">'
               . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">🛡️ Seguro y 🪪 placas</div>'
               . '<p style="font-size:13.5px;color:#333;line-height:1.6;margin:0 0 8px;">Si solicitaste asesoría de seguro o gestor de placas recibirás un correo por separado con toda la información.</p>'
               . '<p style="font-size:13px;color:#555;margin:0;">También podrás consultarla en tu portal en cualquier momento:</p>'
               . vkPortalBtn('🛡️ Ver seguro y placas')
               . '</td></tr>'
               // Support
               . '<tr><td style="padding:14px 28px 4px;">'
               . '<p style="font-size:13px;color:#555;margin:0;">¿Tienes alguna duda?<br>📧 <a href="mailto:ventas@voltika.mx" style="color:#039fe1;font-weight:700;">ventas@voltika.mx</a><br>🕐 Lunes a Viernes 9:00 - 18:00 hrs</p>'
               . '</td></tr>'
               // Unified footer (logo image + legal line)
               . voltikaEmailFooter()
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
          . "Pedido: {pedido_corto}\n\n"
          . $waPunto . "\n\n"
          . "🔄 Lo que sigue:\n"
          . rtrim($waPasosText, "\n") . "\n\n"
          . "📲 Te notificamos aquí en cada paso.\nNo necesitas llamar ni escribir.\n\n"
          . "━━━━━━━━━━━━━━━━━━━━\n\n"
          . "📱 Tu portal de cliente:\n👉 voltika.mx/clientes/\n\n"
          . $waPortalExtra . "\n\n"
          . "📄 Tu permiso temporal para circular\nestará disponible el día que recojas\ntu moto. Entra en vigencia ese día\ny tienes 30 días para tramitar placas.\n\n"
          . $waFactura . "\n\n"
          . "━━━━━━━━━━━━━━━━━━━━\n\n"
          . "¿Dudas? 📧 ventas@voltika.mx";

    // SMS (very short)
    $sms = 'VOLTIKA: {nombre}, tu {modelo} {color} está confirmada. Pedido {pedido_corto}. '
         . ($hasPunto ? 'Punto: {punto} — {ciudad}.' : 'Asignaremos tu punto en 48h.')
         . ' Portal: voltika.mx/clientes/';

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
    $subject = '🔐 Ya tienes acceso a tu portal VOLTIKA — Pedido {pedido_corto}';

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

    // Full email HTML (uses the shared voltikaEmailHeader helper)
    $emailHtml = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Acceso al portal Voltika</title></head>'
               . '<body style="margin:0;padding:0;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif;color:#1a3a5c;">'
               . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;"><tr><td align="center" style="padding:24px 12px;">'
               . '<table width="620" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:14px;overflow:hidden;max-width:620px;width:100%;box-shadow:0 4px 18px rgba(26,58,92,0.10);">'
               . voltikaEmailHeader('🔐 Tu portal ya está activo', 'Pedido {pedido_corto}')
               . '<tr><td style="padding:22px 28px 6px;">'
               . '<div style="font-size:17px;color:#1a3a5c;">Hola <strong>{nombre}</strong> 👋</div>'
               . '<p style="font-size:14px;line-height:1.6;color:#444;margin:10px 0 0;">Tu portal de cliente VOLTIKA ya está activo y listo para usar.</p>'
               . '</td></tr>'
               . '<tr><td style="padding:10px 28px 4px;">'
               . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">📱 Entra a tu portal ahora</div>'
               . '<p style="font-size:13.5px;color:#333;margin:0 0 6px;">Accede con tu número de celular registrado:</p>'
               . '<div style="text-align:center;margin:14px 0;">'
               . '<a href="https://voltika.mx/clientes/" target="_blank" rel="noopener noreferrer" style="display:inline-block;background:#039fe1;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;">Entrar a mi portal →</a>'
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
               . voltikaEmailFooter()
               . '</table></td></tr></table></body></html>';

    // WhatsApp body
    if ($isCredit) {
        $body = "🔐 {nombre}, ya tienes acceso a\ntu portal VOLTIKA ⚡\n\n"
              . "Entra ahora con tu número de celular:\n👉 voltika.mx/clientes/\n\n"
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
              . "Entra ahora con tu número de celular:\n👉 voltika.mx/clientes/\n\n"
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

    $sms = 'VOLTIKA: {nombre}, tu portal ya está activo. Entra con tu celular en voltika.mx/clientes/. Dudas: ventas@voltika.mx';

    return [
        'subject'    => $subject,
        'body'       => $body,
        'sms'        => $sms,
        'email_html' => $emailHtml,
    ];
}

/**
 * ══════════════════════════════════════════════════════════════════════════
 * LOGISTICS NOTIFICATIONS — 4 stages (customer brief 2026-04-19)
 *   A) punto_asignado      — punto confirmed for the order
 *   B) moto_enviada        — bike left CEDIS, on its way
 *   C) moto_recibida       — bike arrived at the point, in preparation
 *   D) moto_lista_entrega  — ready for customer pickup
 * ══════════════════════════════════════════════════════════════════════════
 */

// ── Helpers ─────────────────────────────────────────────────────────────────
function voltikaBuildMapsLink(string $direccion = '', string $ciudad = '', ?float $lat = null, ?float $lng = null): string {
    if ($lat !== null && $lng !== null && $lat != 0 && $lng != 0) {
        return 'https://www.google.com/maps/search/?api=1&query=' . $lat . ',' . $lng;
    }
    $q = trim($direccion . ($ciudad ? ', ' . $ciudad : ''));
    if ($q === '') return 'https://voltika.mx/clientes/';
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($q);
}

/**
 * Resolve / generate the short customer-facing order code VK-YYMM-NNNN.
 *
 *   - If the row already has pedido_corto, return it.
 *   - Otherwise compute next counter for current YYMM + write it back.
 *   - Idempotent: safe to call repeatedly on the same transaccion_id.
 *
 * Runs with the existing PDO (passed in) so triggers can reuse their handle
 * instead of opening a new one.
 */
function voltikaResolvePedidoCorto(PDO $pdo, int $transaccionId): string {
    if (!$transaccionId) return '';

    // Fast path — already stamped?
    $q = $pdo->prepare("SELECT pedido_corto, freg FROM transacciones WHERE id=?");
    $q->execute([$transaccionId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) return '';
    if (!empty($row['pedido_corto'])) return $row['pedido_corto'];

    // Ensure the column exists (lazy migration for databases that never ran
    // the one-shot migration script).
    try {
        $pdo->exec("ALTER TABLE transacciones ADD COLUMN IF NOT EXISTS pedido_corto VARCHAR(20) NULL");
        $pdo->exec("ALTER TABLE transacciones ADD UNIQUE INDEX IF NOT EXISTS idx_pedido_corto (pedido_corto)");
    } catch (Throwable $e) {
        // Older MySQL lacks IF NOT EXISTS on ADD COLUMN; absorb and continue.
    }

    // Compute next counter for current YYMM
    try {
        $dt = new DateTime($row['freg'] ?? 'now');
    } catch (Throwable $e) {
        $dt = new DateTime();
    }
    $yymm = $dt->format('ym');

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $cnt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(pedido_corto, '-', -1) AS UNSIGNED))
                                FROM transacciones
                               WHERE pedido_corto LIKE ?");
        $cnt->execute(["VK-$yymm-%"]);
        $next = ((int)$cnt->fetchColumn()) + 1 + $attempt;
        $short = sprintf('VK-%s-%04d', $yymm, $next);
        try {
            $pdo->prepare("UPDATE transacciones SET pedido_corto=? WHERE id=?")
               ->execute([$short, $transaccionId]);
            return $short;
        } catch (Throwable $e) {
            // UNIQUE collision (two concurrent writes) — retry with next index.
        }
    }
    return '';
}

function voltikaFormatFechaHuman(?string $iso): string {
    if (!$iso) return '';
    try {
        $meses = ['enero','febrero','marzo','abril','mayo','junio',
                  'julio','agosto','septiembre','octubre','noviembre','diciembre'];
        $dt = new DateTime($iso);
        return $dt->format('j') . ' de ' . $meses[(int)$dt->format('n') - 1] . ' de ' . $dt->format('Y');
    } catch (Throwable $e) { return (string)$iso; }
}

// Shared chrome (header + footer) used by every logistics email. Now a thin
// wrapper around voltikaEmailShell() — keeps the old function name for
// backwards compatibility with call sites that haven't been renamed yet.
function voltikaLogisticsEmailShell(string $hero, string $heroSub, string $innerRows): string {
    return voltikaEmailShell($hero, $heroSub, $innerRows);
}

// Email-safe CTA button linking to the customer portal. Wrapped in a table
// so Outlook and Gmail render the padding correctly (inline-block is not
// reliable across all clients). Takes a context label + optional emoji so
// the button makes sense next to each section (pagos / factura / permiso).
function vkPortalBtn(string $label): string {
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:14px 0;">'
         . '<tr><td style="background:#039fe1;border-radius:10px;box-shadow:0 2px 6px rgba(3,159,225,0.28);">'
         . '<a href="https://voltika.mx/clientes/" target="_blank" rel="noopener noreferrer" '
         . 'style="display:inline-block;padding:12px 28px;color:#ffffff;text-decoration:none;'
         . 'font-weight:700;font-size:14px;letter-spacing:.3px;font-family:Arial,Helvetica,sans-serif;">'
         . $label
         . '</a></td></tr></table>';
}

// ── Reusable section rows (customer brief 2026-04-20: match compra_confirmada
//    visual richness across every notification email).
function vkPortalRow(): string {
    // Customer brief 2026-04-20: portal section needs to look like a real CTA
    // — wrap the body in a soft blue card and turn the URL into a fat button
    // (with target=_blank so it stays clickable inside preview iframes too).
    return '<tr><td style="padding:14px 28px;">'
         . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">📱 Tu portal de cliente</div>'
         . '<div style="background:#E8F4FD;border:1px solid #B3D4FC;border-radius:10px;padding:16px 18px;">'
         .   '<p style="font-size:13.5px;color:#1a3a5c;margin:0 0 10px;line-height:1.6;">Todo lo de tu compra en un solo lugar. Entra con tu número de celular:</p>'
         .   '<table cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 14px;"><tr><td style="background:#039fe1;border-radius:10px;box-shadow:0 2px 6px rgba(3,159,225,0.28);">'
         .     '<a href="https://voltika.mx/clientes/" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:12px 28px;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;letter-spacing:.3px;font-family:Arial,Helvetica,sans-serif;">👤 Seguir en mi cuenta</a>'
         .   '</td></tr></table>'
         .   '<p style="font-size:11.5px;color:#555;margin:0 0 12px;font-family:ui-monospace,Menlo,Consolas,monospace;">'
         .     '<a href="https://voltika.mx/clientes/" target="_blank" rel="noopener noreferrer" style="color:#039fe1;text-decoration:underline;">voltika.mx/clientes/</a>'
         .   '</p>'
         .   '<p style="font-size:13px;color:#1a3a5c;margin:6px 0 6px;font-weight:700;">Desde tu portal puedes:</p>'
         .   '<div style="font-size:13.5px;color:#1a3a5c;margin:4px 0;">✅ Seguir tu pedido en tiempo real</div>'
         .   '<div style="font-size:13.5px;color:#1a3a5c;margin:4px 0;">✅ Descargar tu permiso temporal para circular</div>'
         .   '<div style="font-size:13.5px;color:#1a3a5c;margin:4px 0;">✅ Consultar y descargar tu factura</div>'
         .   '<div style="font-size:13.5px;color:#1a3a5c;margin:4px 0;">✅ Descargar tu contrato y acta de entrega</div>'
         .   '<div style="font-size:13.5px;color:#1a3a5c;margin:4px 0;">✅ Ver cotizaciones de seguro y placas si las solicitaste</div>'
         . '</div>'
         . '</td></tr>';
}

function vkPermisoRow(): string {
    return '<tr><td style="padding:14px 28px;">'
         . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">📄 Permiso temporal para circular</div>'
         . '<p style="font-size:13.5px;color:#333;line-height:1.6;margin:0 0 10px;">Tu permiso estará disponible en tu portal el día que recojas tu moto.</p>'
         . '<p style="font-size:12.5px;color:#b45309;background:#fffbeb;border-left:3px solid #f59e0b;padding:8px 12px;border-radius:4px;margin:8px 0;line-height:1.6;"><strong>⚠️ Entra en vigencia ese día</strong> y tienes <strong>30 días para tramitar tus placas definitivas</strong>.</p>'
         . '<p style="font-size:13px;color:#555;margin:8px 0 0;">Descárgalo e imprímelo ese mismo día:</p>'
         . vkPortalBtn('📄 Descargar mi permiso')
         . '</td></tr>';
}

function vkFacturaRow(): string {
    return '<tr><td style="padding:14px 28px;">'
         . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">🧾 Tu factura</div>'
         . '<p style="font-size:13.5px;color:#333;line-height:1.6;margin:0;">Tu factura estará disponible al momento de la entrega en tu portal:</p>'
         . vkPortalBtn('🧾 Ver mi factura')
         . '<p style="font-size:13px;color:#555;margin:10px 0 0;">¿Necesitas registrar tu RFC? Escríbenos antes de la entrega:<br>📧 <a href="mailto:ventas@voltika.mx" style="color:#039fe1;font-weight:700;">ventas@voltika.mx</a></p>'
         . '</td></tr>';
}

function vkSeguroPlacasRow(): string {
    return '<tr><td style="padding:14px 28px;">'
         . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">🛡️ Seguro y 🪪 placas</div>'
         . '<p style="font-size:13.5px;color:#333;line-height:1.6;margin:0 0 8px;">Si solicitaste asesoría de seguro o gestor de placas recibirás un correo por separado con toda la información.</p>'
         . '<p style="font-size:13px;color:#555;margin:0;">También podrás consultarla en tu portal en cualquier momento:</p>'
         . vkPortalBtn('🛡️ Ver seguro y placas')
         . '</td></tr>';
}

function vkWhatsAppNoteRow(): string {
    return '<tr><td style="padding:6px 28px 14px;">'
         . '<p style="font-size:12px;color:#777;background:#f5f7fa;padding:10px 12px;border-radius:6px;margin:0;line-height:1.5;">📲 Recibirás WhatsApp automático en cada paso. No necesitas llamar ni escribir para saber cómo va tu pedido — todo llega solo.</p>'
         . '</td></tr>';
}

// ── A) PUNTO ASIGNADO ───────────────────────────────────────────────────────
function voltikaBuildPuntoAsignadoTemplate(): array {
    $subject = '🎉 ¡Todo listo! Tu VOLTIKA ya tiene punto de entrega — Pedido {pedido_corto}';

    $rows = '<tr><td style="padding:22px 28px 6px;">'
          . '<div style="font-size:17px;color:#1a3a5c;">Hola <strong>{nombre}</strong> 👋</div>'
          . '<p style="font-size:14px;line-height:1.6;color:#444;margin:10px 0 0;">Tenemos buenas noticias — tu moto ya tiene <strong>punto de entrega confirmado</strong> y fecha estimada. Todo marcha perfecto.</p>'
          . '<p style="font-size:12px;color:#666;margin:8px 0 0;">Pedido: <strong>{pedido_corto}</strong><br>🏍️ <strong>{modelo}</strong> · {color}</p>'
          . '</td></tr>'
          // Punto box
          . '<tr><td style="padding:10px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">📍 Tu punto de entrega</div>'
          . '<div style="background:#E8F4FD;border-radius:8px;padding:14px 16px;font-size:14px;line-height:1.7;color:#1a3a5c;">'
          . '🏪 <strong>{punto}</strong><br>'
          . '📬 {direccion_punto}<br>'
          . '🗺️ <a href="{link_maps}" target="_blank" rel="noopener noreferrer" style="color:#039fe1;">Ver en Google Maps</a><br>'
          . '🕐 Lunes a Sábado 9:00 - 18:00 hrs<br>'
          . '📅 Fecha estimada: antes del <strong>{fecha_estimada}</strong>'
          . '</div>'
          . '<p style="font-size:12.5px;color:#6b4c0f;background:#FFF8E1;border-left:3px solid #FFC107;padding:8px 12px;border-radius:4px;margin:10px 0 0;line-height:1.5;">Si por alguna razón la fecha cambia te avisamos de inmediato por WhatsApp — siempre estarás informado.</p>'
          . '</td></tr>'
          // Pasos
          . '<tr><td style="padding:14px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">🔄 Lo que viene — paso a paso</div>'
          . '<p style="font-size:13px;color:#444;margin:0 0 8px;">Desde aquí no tienes que hacer nada — nosotros nos encargamos de todo y te avisamos en cada paso:</p>'
          . '<div style="font-size:13.5px;color:#333;line-height:1.6;">'
          . '1️⃣ Preparamos tu moto con cuidado<br>'
          . '2️⃣ La enviamos directo a tu punto<br>'
          . '3️⃣ Te avisamos por WhatsApp cuando salga de nuestras instalaciones<br>'
          . '4️⃣ Te avisamos cuando llegue al punto<br>'
          . '5️⃣ Te avisamos cuando esté lista con fecha y hora exacta'
          . '</div>'
          . '<p style="font-size:12.5px;color:#166534;background:#ecfdf5;padding:8px 12px;border-radius:4px;border-left:3px solid #22d37a;margin:10px 0 0;">Tu moto llegará armada, revisada y lista para circular desde el primer momento ✅</p>'
          . '</td></tr>'
          // Portal CTA + Permiso + Factura + Seguro/Placas (rich, like compra_confirmada)
          . vkPortalRow()
          . vkPermisoRow()
          . vkFacturaRow()
          . vkSeguroPlacasRow()
          . '<tr><td style="padding:12px 28px 0;"><p style="font-size:13px;color:#333;margin:0;">Estamos contigo en cada paso 🙌</p></td></tr>';

    $emailHtml = voltikaLogisticsEmailShell(
        '🎉 ¡Tu punto de entrega está listo!',
        'Pedido {pedido_corto}',
        $rows
    );

    $body = "🎉 ¡Buenas noticias, {nombre}!\n\n"
          . "Tu {modelo} en color {color} ya tiene\ntodo listo para llegar a ti ⚡\n"
          . "Pedido: {pedido_corto}\n\n"
          . "📍 Tu punto de entrega:\n🏪 {punto}\n📬 {direccion_punto}\n🗺️ {link_maps}\n🕐 Lunes a Sábado 9:00 - 18:00 hrs\n\n"
          . "📅 Fecha estimada de entrega:\nAntes del {fecha_estimada}\n\n"
          . "Desde aquí no tienes que hacer\nnada — nosotros te avisamos\nen cada paso por aquí mismo.\n\n"
          . "🔄 Lo que viene:\n1️⃣ Preparamos tu moto con cuidado\n2️⃣ La enviamos directo a tu punto\n3️⃣ Te avisamos cuando salga\n4️⃣ Te avisamos cuando llegue\n5️⃣ Te avisamos cuando esté lista\n\n"
          . "Tu moto llegará armada, revisada\ny lista para circular desde el primer momento ✅\n\n"
          . "Sigue cada paso en tiempo real:\n👉 voltika.mx/clientes/\n\n"
          . "¿Alguna duda? Estamos aquí:\n📧 ventas@voltika.mx\n🕐 Lun a Vie 9:00 - 18:00 hrs";

    $sms = 'VOLTIKA: {nombre}, tu {modelo} ya tiene punto de entrega: {punto}. Entrega antes del {fecha_estimada}. Portal: voltika.mx/clientes/';

    return ['subject' => $subject, 'body' => $body, 'sms' => $sms, 'email_html' => $emailHtml];
}

// ── B) MOTO ENVIADA ─────────────────────────────────────────────────────────
function voltikaBuildMotoEnviadaTemplate(): array {
    $subject = '🚚 ¡Tu VOLTIKA ya está en camino! — Pedido {pedido_corto}';

    $rows = '<tr><td style="padding:22px 28px 6px;">'
          . '<div style="font-size:17px;color:#1a3a5c;">Hola <strong>{nombre}</strong> 👋</div>'
          . '<p style="font-size:14px;line-height:1.6;color:#444;margin:10px 0 0;">¡Momento emocionante — tu moto ya salió de nuestras instalaciones y está en camino hacia ti!</p>'
          . '<p style="font-size:12px;color:#666;margin:8px 0 0;">Pedido: <strong>{pedido_corto}</strong><br>🏍️ <strong>{modelo}</strong> · {color}</p>'
          . '</td></tr>'
          // Destino
          . '<tr><td style="padding:10px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">🚚 En camino hacia ti</div>'
          . '<div style="background:#E8F4FD;border-radius:8px;padding:14px 16px;font-size:14px;line-height:1.7;color:#1a3a5c;">'
          . '📍 Destino:<br>'
          . '🏪 <strong>{punto}</strong><br>'
          . '📬 {direccion_punto}<br>'
          . '🗺️ <a href="{link_maps}" target="_blank" rel="noopener noreferrer" style="color:#039fe1;">Ver en Google Maps</a><br>'
          . '📅 Llegada estimada al punto: <strong>{fecha_llegada_punto}</strong>'
          . '</div>'
          . '<p style="font-size:12.5px;color:#6b4c0f;background:#FFF8E1;border-left:3px solid #FFC107;padding:8px 12px;border-radius:4px;margin:10px 0 0;line-height:1.5;">Si por alguna razón la fecha cambia te avisamos de inmediato por WhatsApp — siempre estarás al tanto de dónde está tu moto.</p>'
          . '</td></tr>'
          // Lo que sigue
          . '<tr><td style="padding:14px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">🔄 Lo que sigue</div>'
          . '<p style="font-size:13px;color:#444;margin:0 0 8px;">Cuando tu moto llegue al punto nuestro equipo se encarga de todo:</p>'
          . '<div style="font-size:13.5px;color:#333;line-height:1.8;">'
          . '⚙️ La reciben y verifican<br>'
          . '🔧 La ensamblan completamente<br>'
          . '🔍 Revisan cada sistema — batería, frenos, luces y motor<br>'
          . '⚡ La activan y configuran<br>'
          . '✅ Realizan el checklist completo'
          . '</div>'
          . '<p style="font-size:12.5px;color:#166534;background:#ecfdf5;padding:8px 12px;border-radius:4px;border-left:3px solid #22d37a;margin:10px 0 0;">Tu moto no sale del punto hasta que esté perfecta para ti. Cuando esté lista recibirás un WhatsApp con fecha y hora exacta. No tienes que hacer nada por ahora.</p>'
          . '</td></tr>'
          // Rich sections: Portal + Permiso + Factura + Seguro/Placas
          . vkPortalRow()
          . vkPermisoRow()
          . vkFacturaRow()
          . vkSeguroPlacasRow()
          . '<tr><td style="padding:12px 28px 0;"><p style="font-size:13px;color:#333;margin:0;">Estamos contigo en cada paso 🙌</p></td></tr>';

    $emailHtml = voltikaLogisticsEmailShell(
        '🚚 ¡Tu moto ya está en camino!',
        'Pedido {pedido_corto}',
        $rows
    );

    $body = "🚚 ¡{nombre}, tu moto ya salió\ny está en camino hacia ti!\n\n"
          . "Tu {modelo} en color {color}\nya está en ruta ⚡\n"
          . "Pedido: {pedido_corto}\n\n"
          . "📍 Va directo a tu punto:\n🏪 {punto} — {ciudad}\n\n"
          . "📅 Llegada estimada al punto:\n{fecha_llegada_punto}\n\n"
          . "Si por alguna razón la fecha cambia\nte avisamos de inmediato —\nsiempre sabrás dónde está tu moto.\n\n"
          . "Una vez que llegue nuestro equipo\nla recibe, ensambla completamente,\nverifica cada detalle y la activa\npara que salga perfecta para ti ✅\n\n"
          . "Te avisamos cuando llegue y cuando\nesté lista — no tienes que hacer\nnada por ahora 🙌\n\n"
          . "Sigue tu moto en tiempo real:\n👉 voltika.mx/clientes/\n\n"
          . "¿Alguna duda? Estamos aquí:\n📧 ventas@voltika.mx\n🕐 Lun a Vie 9:00 - 18:00 hrs";

    $sms = 'VOLTIKA: {nombre}, tu {modelo} va en camino a {punto} — {ciudad}. Llegada estimada: {fecha_llegada_punto}.';

    return ['subject' => $subject, 'body' => $body, 'sms' => $sms, 'email_html' => $emailHtml];
}

// ── C) MOTO RECIBIDA EN EL PUNTO ────────────────────────────────────────────
function voltikaBuildMotoRecibidaTemplate(): array {
    $subject = '🔧 ¡Tu VOLTIKA llegó al punto y está en preparación! — Pedido {pedido_corto}';

    $rows = '<tr><td style="padding:22px 28px 6px;">'
          . '<div style="font-size:17px;color:#1a3a5c;">Hola <strong>{nombre}</strong> 👋</div>'
          . '<p style="font-size:14px;line-height:1.6;color:#444;margin:10px 0 0;">¡Muy buenas noticias — tu moto llegó a tu punto de entrega y ya está en manos de nuestro equipo!</p>'
          . '<p style="font-size:12px;color:#666;margin:8px 0 0;">Pedido: <strong>{pedido_corto}</strong><br>🏍️ <strong>{modelo}</strong> · {color}</p>'
          . '</td></tr>'
          // Punto
          . '<tr><td style="padding:10px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">📍 En preparación en tu punto</div>'
          . '<div style="background:#E8F4FD;border-radius:8px;padding:14px 16px;font-size:14px;line-height:1.7;color:#1a3a5c;">'
          . '🏪 <strong>{punto}</strong><br>'
          . '📬 {direccion_punto}<br>'
          . '🗺️ <a href="{link_maps}" target="_blank" rel="noopener noreferrer" style="color:#039fe1;">Ver en Google Maps</a><br>'
          . '🕐 Lunes a Sábado 9:00 - 18:00 hrs'
          . '</div>'
          . '</td></tr>'
          // Qué está pasando
          . '<tr><td style="padding:14px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">🔄 ¿Qué está pasando ahora?</div>'
          . '<p style="font-size:13px;color:#444;margin:0 0 8px;">Nuestro equipo está trabajando para que todo esté perfecto:</p>'
          . '<div style="font-size:13.5px;color:#333;line-height:1.8;">'
          . '⚙️ Ensamble completo de tu moto<br>'
          . '🔍 Verificación de todos los sistemas — batería, frenos, luces y motor<br>'
          . '⚡ Activación y configuración<br>'
          . '✅ Checklist completo de pre-entrega'
          . '</div>'
          . '<p style="font-size:12.5px;color:#166534;background:#ecfdf5;padding:8px 12px;border-radius:4px;border-left:3px solid #22d37a;margin:10px 0 0;line-height:1.5;">Tu moto no sale del punto hasta que pase todas las revisiones y esté lista al 100% para ti. Este proceso toma algunas horas. En cuanto esté lista recibirás un WhatsApp con fecha y hora exacta para ir a recogerla.</p>'
          . '<p style="font-size:12.5px;color:#6b4c0f;background:#FFF8E1;border-left:3px solid #FFC107;padding:8px 12px;border-radius:4px;margin:8px 0 0;">📲 No necesitas llamar ni ir al punto antes de ese aviso — nosotros te buscamos 🙌</p>'
          . '</td></tr>'
          // Rich sections: Portal + Permiso + Factura + Seguro/Placas
          . vkPortalRow()
          . vkPermisoRow()
          . vkFacturaRow()
          . vkSeguroPlacasRow()
          . '<tr><td style="padding:12px 28px 0;"><p style="font-size:13px;color:#333;margin:0;">Estamos contigo en cada paso 🙌</p></td></tr>';

    $emailHtml = voltikaLogisticsEmailShell(
        '🔧 ¡Tu moto llegó y está en preparación!',
        'Pedido {pedido_corto}',
        $rows
    );

    $body = "🔧 ¡{nombre}, tu moto llegó\nal punto y ya está en manos\nde nuestro equipo!\n\n"
          . "🏍️ {modelo} · {color}\nPedido: {pedido_corto}\n\n"
          . "📍 {punto} — {ciudad}\n\n"
          . "Ahora mismo están trabajando\npara que todo esté perfecto para ti:\n\n"
          . "⚙️ Ensamble completo\n🔍 Verificación de cada sistema\n⚡ Activación y configuración\n✅ Checklist completo de entrega\n\n"
          . "Tu moto no sale hasta que pase\ntodas las revisiones y esté\nlista al 100% ✅\n\n"
          . "Este proceso toma algunas horas.\nEn cuanto esté lista te avisamos\naquí con fecha y hora exacta\npara que vengas a recogerla 🙌\n\n"
          . "No necesitas llamar ni ir al punto\nantes de ese aviso — nosotros\nte buscamos.\n\n"
          . "Sigue tu pedido aquí:\n👉 voltika.mx/clientes/\n\n"
          . "¿Alguna duda? 📧 ventas@voltika.mx";

    $sms = 'VOLTIKA: {nombre}, tu {modelo} llegó a {punto}. En ensamble y revisión. Te avisamos cuando esté lista.';

    return ['subject' => $subject, 'body' => $body, 'sms' => $sms, 'email_html' => $emailHtml];
}

// ── D) MOTO LISTA PARA ENTREGA ──────────────────────────────────────────────
function voltikaBuildMotoListaEntregaTemplate(): array {
    $subject = '✅ ¡Tu VOLTIKA está lista! Descarga tu permiso en 24 hrs — Pedido {pedido_corto}';

    $rows = '<tr><td style="padding:22px 28px 6px;">'
          . '<div style="font-size:17px;color:#1a3a5c;">Hola <strong>{nombre}</strong> 🎉</div>'
          . '<p style="font-size:14px;line-height:1.6;color:#444;margin:10px 0 0;">¡El momento llegó — tu moto pasó todas las revisiones y está perfecta para ti desde el primer kilómetro!</p>'
          . '<p style="font-size:12px;color:#666;margin:8px 0 0;">Pedido: <strong>{pedido_corto}</strong><br>🏍️ <strong>{modelo}</strong> · {color}</p>'
          . '</td></tr>'
          // Permiso notice
          . '<tr><td style="padding:12px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#991b1b;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">⚠️ Importante — lee esto primero</div>'
          . '<div style="background:#fef2f2;border-left:4px solid #dc2626;padding:12px 14px;border-radius:6px;font-size:13px;color:#7a0e1f;line-height:1.6;">'
          . 'Al confirmarse tu entrega la <strong>autoridad de transporte emitió automáticamente tu permiso temporal</strong> para circular.<br><br>'
          . 'Este proceso es automático y está fuera del control de VOLTIKA — es la autoridad quien lo genera y determina su fecha de inicio.<br><br>'
          . 'El permiso tiene una vigencia de <strong>30 días a partir de su emisión</strong> para tramitar tus placas definitivas.<br><br>'
          . 'Los días ya están corriendo — por eso te recomendamos recoger tu moto lo antes posible.'
          . '</div>'
          . '</td></tr>'
          // Permiso descarga
          . '<tr><td style="padding:10px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">📄 Tu permiso temporal para circular</div>'
          . '<p style="font-size:13px;color:#444;margin:0 0 8px;line-height:1.6;">Estará disponible en tu portal en las <strong>próximas 24 horas</strong>:<br><a href="https://voltika.mx/clientes/" target="_blank" rel="noopener noreferrer" style="color:#039fe1;font-weight:700;">👉 voltika.mx/clientes/</a></p>'
          . '<p style="font-size:13px;color:#444;margin:10px 0 6px;"><strong>Qué hacer cuando esté disponible:</strong></p>'
          . '<div style="font-size:13px;color:#333;line-height:1.7;">'
          . '1️⃣ Entra a voltika.mx/clientes/<br>'
          . '2️⃣ Descarga e imprime tu permiso<br>'
          . '3️⃣ <strong>Enmícalo</strong> para protegerlo — lo llevarás en tu moto durante 30 días expuesto al sol, lluvia y polvo. Enmicarlo lo protege y lo mantiene legible ante cualquier autoridad.<br>'
          . '4️⃣ Colócalo en la <strong>parte trasera de tu moto</strong> — ese es el lugar oficial donde va mientras tramitas tus placas definitivas. Las autoridades lo verifican ahí.<br>'
          . '5️⃣ Llévalo contigo el día que vayas al punto a recoger tu moto'
          . '</div>'
          . '<p style="font-size:12.5px;color:#b45309;background:#fffbeb;border-left:3px solid #f59e0b;padding:8px 12px;border-radius:4px;margin:10px 0 0;"><strong>Sin el permiso impreso</strong> no podrás circular legalmente al salir del punto ese mismo día.</p>'
          . '</td></tr>'
          // Punto pickup
          . '<tr><td style="padding:14px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">📍 Ve a recogerla cuando quieras</div>'
          . '<div style="background:#E8F4FD;border-radius:8px;padding:14px 16px;font-size:14px;line-height:1.7;color:#1a3a5c;">'
          . '🏪 <strong>{punto}</strong><br>'
          . '📬 {direccion_punto}<br>'
          . '🗺️ <a href="{link_maps}" target="_blank" rel="noopener noreferrer" style="color:#039fe1;">Ver en Google Maps</a><br>'
          . '🕐 Lunes a Sábado 9:00 - 18:00 hrs<br>'
          . '📅 Recógela antes del <strong>{fecha_limite}</strong>'
          . '</div>'
          . '<p style="font-size:12.5px;color:#166534;background:#ecfdf5;padding:8px 12px;border-radius:4px;border-left:3px solid #22d37a;margin:10px 0 0;">No necesitas cita — llega en cualquier momento del horario y el equipo te atenderá de inmediato.</p>'
          . '</td></tr>'
          // Qué llevar
          . '<tr><td style="padding:14px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#991b1b;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">⚠️ Lo que debes llevar</div>'
          . '<div style="font-size:13.5px;color:#333;line-height:1.8;">'
          . '🖨️ Tu permiso impreso y enmicado<br>'
          . '🪪 Tu INE vigente<br>'
          . '📱 Tu celular con este número — recibirás un código OTP al momento de la entrega'
          . '</div>'
          . '<p style="font-size:12.5px;color:#7a0e1f;background:#fef2f2;border-left:3px solid #dc2626;padding:8px 12px;border-radius:4px;margin:10px 0 0;"><strong>Sin estos tres elementos</strong> no es posible entregarte la moto ni circular legalmente al salir.</p>'
          . '</td></tr>'
          // Payment scam warning — customer brief 2026-04-24: entrega gratis
          // mensajes tipo "hay que pagar algo extra" son fraude. Prominente
          // en naranja/rojo para que ningún cliente pague demás.
          . '<tr><td style="padding:14px 28px;">'
          . '<div style="background:#fffbeb;border:2px solid #f59e0b;border-radius:10px;padding:14px 16px;">'
          . '<div style="font-size:14px;font-weight:800;color:#92400e;margin-bottom:6px;">⚠ Tu entrega no requiere ningún pago extra.</div>'
          . '<div style="font-size:13px;color:#78350f;line-height:1.6;">Si te piden dinero por cualquier concepto (gasolina, trámite, propina, "apartado"), <strong>no pagues</strong> y repórtalo inmediatamente a Voltika:<br>'
          . '📧 <a href="mailto:ventas@voltika.mx" style="color:#78350f;font-weight:700;text-decoration:underline;">ventas@voltika.mx</a></div>'
          . '</div>'
          . '</td></tr>'
          // Proceso entrega
          . '<tr><td style="padding:14px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">🔄 Así es la entrega — muy sencillo</div>'
          . '<div style="font-size:13px;color:#333;line-height:1.8;">'
          . '1️⃣ Llegas con permiso enmicado, INE y celular<br>'
          . '2️⃣ El equipo verifica tu identidad<br>'
          . '3️⃣ Recibes tu código OTP en tu celular<br>'
          . '4️⃣ El punto lo ingresa al sistema<br>'
          . '5️⃣ Firmas el acta de entrega digital<br>'
          . '6️⃣ Colocas tu permiso enmicado en la parte trasera de tu moto<br>'
          . '7️⃣ ¡Te llevas tu moto lista para circular ese mismo momento! ⚡'
          . '</div>'
          . '</td></tr>'
          // OTP help
          . '<tr><td style="padding:14px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">💡 ¿No te llega el código OTP?</div>'
          . '<p style="font-size:13px;color:#444;margin:0;line-height:1.6;">No te preocupes — es algo sencillo de resolver. Díselo al personal del punto y ellos lo reenvían desde el sistema en ese momento.</p>'
          . '</td></tr>'
          // Reagendar
          . '<tr><td style="padding:14px 28px;">'
          . '<p style="font-size:13px;color:#444;margin:0;line-height:1.6;"><strong>¿No puedes ir antes del {fecha_limite}?</strong><br>Sin problema — escríbenos y lo coordinamos juntos:<br>📧 <a href="mailto:ventas@voltika.mx" style="color:#039fe1;font-weight:700;">ventas@voltika.mx</a><br>🕐 Lun a Vie 9:00 - 18:00 hrs</p>'
          . '</td></tr>'
          // Rich sections: Portal + Factura + Seguro/Placas
          . vkPortalRow()
          . vkFacturaRow()
          . vkSeguroPlacasRow()
          . '<tr><td style="padding:12px 28px 0;"><p style="font-size:13.5px;color:#1a3a5c;margin:0;font-weight:700;">¡Bienvenido a la familia VOLTIKA! Nos da mucho gusto que ya seas parte de nuestra red ⚡</p></td></tr>';

    $emailHtml = voltikaLogisticsEmailShell(
        '✅ ¡Tu VOLTIKA está lista!',
        'Pedido {pedido_corto}',
        $rows
    );

    $body = "✅ ¡{nombre}, tu moto está lista\npara entrega, ya puedes recogerla! 🎉\n\n"
          . "Tu {modelo} · {color}\nPedido: {pedido_corto}\n\n"
          . "━━━━━━━━━━━━━━━━━━━━\n\n"
          . "📍 Tu punto de entrega:\n🏪 {punto}\n📬 {direccion_punto}\n🗺️ {link_maps}\n🕐 Lunes a Sábado 9:00 - 18:00 hrs\n📅 Recógela antes del {fecha_limite}\n\n"
          . "Sin cita — llega cuando puedas.\n\n"
          . "━━━━━━━━━━━━━━━━━━━━\n\n"
          . "⚠️ IMPORTANTE\n\n"
          . "Tu entrega NO requiere ningún\npago extra. Si te piden dinero por\ncualquier concepto, NO pagues y\nrepórtalo a Voltika:\n📧 ventas@voltika.mx\n\n"
          . "━━━━━━━━━━━━━━━━━━━━\n\n"
          . "⚠️ ACCIÓN REQUERIDA HOY\n\n"
          . "Tu permiso temporal para circular\nya fue emitido por la autoridad\nde transporte — tienes 30 días\ndesde hoy para tramitar tus placas.\n\n"
          . "Haz esto antes de ir al punto:\n\n"
          . "1️⃣ Entra a tu portal:\n   👉 voltika.mx/clientes/\n"
          . "2️⃣ Descarga e imprime tu permiso\n   (disponible en las próximas 24 hrs)\n"
          . "3️⃣ Enmícalo\n"
          . "4️⃣ Llévalo el día que recojas tu moto\n   — va en la parte trasera de la moto\n\n"
          . "━━━━━━━━━━━━━━━━━━━━\n\n"
          . "⚠️ Lleva el día de la entrega:\n🖨️ Permiso impreso y enmicado\n🪪 INE vigente\n📱 Tu celular con este número\n\n"
          . "¿No puedes ir antes del {fecha_limite}?\n📧 ventas@voltika.mx\n\n"
          . "¡Bienvenido a la familia VOLTIKA!";

    $sms = 'VOLTIKA: {nombre}, tu {modelo} está lista para entrega en {punto}. La entrega es gratis — no pagues nada extra. Descarga permiso en voltika.mx/clientes/ (24h). Lleva INE.';

    return ['subject' => $subject, 'body' => $body, 'sms' => $sms, 'email_html' => $emailHtml];
}

/**
 * ══════════════════════════════════════════════════════════════════════════
 * OTP / ACTA / INCIDENCIA / COBRANZA — customer brief 2026-04-19 (batch 2)
 * All 9 templates below use voltikaLogisticsEmailShell() for HTML where a
 * rich email version is desired, and plain WhatsApp/SMS bodies elsewhere.
 * ══════════════════════════════════════════════════════════════════════════
 */

// ── OTP entrega ─────────────────────────────────────────────────────────────
function voltikaBuildOtpEntregaTemplate(): array {
    $sms = "Voltika: {nombre}, tu código de entrega es: {otp}. Muéstraselo al asesor del punto. Solo tú debes verlo. Expira en 10 min. Dudas: ventas@voltika.mx";
    $body = "🔐 {nombre}, aquí está tu código\nde seguridad para recibir tu moto:\n\n"
          . "━━━━━━━━━━━━━━━━━━━━\n\n"
          . "🔑  {otp}\n\n"
          . "━━━━━━━━━━━━━━━━━━━━\n\n"
          . "Muéstraselo al asesor del punto\nVOLTIKA en este momento.\n\n"
          . "⏱️ Expira en 10 minutos.\n"
          . "⚠️ No lo compartas con nadie\n   más que el asesor del punto.\n\n"
          . "Este código es tu llave digital\npara recibir tu moto de forma\nsegura — es el último paso antes\nde llevártela ⚡\n\n"
          . "Si no solicitaste este código\no tienes dudas escríbenos:\n📧 ventas@voltika.mx";

    // Rich HTML version — customer brief 2026-04-20: same brand richness as
    // compra_confirmada. The OTP code is the centerpiece, framed in a giant
    // monospace card so it's instantly readable on any screen.
    $rows = '<tr><td style="padding:22px 28px 6px;">'
          . '<div style="font-size:17px;color:#1a3a5c;">Hola <strong>{nombre}</strong> 🔐</div>'
          . '<p style="font-size:14px;line-height:1.6;color:#444;margin:10px 0 0;">Aquí está tu código de seguridad para recibir tu moto. Muéstralo al asesor del punto VOLTIKA en este momento.</p>'
          . '</td></tr>'
          . '<tr><td style="padding:6px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">🔑 Tu código de 6 dígitos</div>'
          . '<div style="background:linear-gradient(135deg,#1a3a5c,#039fe1);color:#fff;text-align:center;padding:24px;border-radius:10px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:38px;font-weight:800;letter-spacing:8px;margin:8px 0;">{otp}</div>'
          . '<p style="font-size:12.5px;color:#b45309;background:#fffbeb;border-left:3px solid #f59e0b;padding:10px 12px;border-radius:4px;margin:10px 0 0;line-height:1.6;">⏱️ <strong>Expira en 10 minutos.</strong><br>⚠️ No lo compartas con nadie más que el asesor del punto.</p>'
          . '</td></tr>'
          . '<tr><td style="padding:14px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">⚡ ¿Por qué este código?</div>'
          . '<p style="font-size:13.5px;color:#333;line-height:1.6;margin:0;">Este código es tu llave digital para recibir tu moto de forma segura — es el último paso antes de llevártela. Sin este código el punto no puede entregarte la unidad.</p>'
          . '</td></tr>'
          . '<tr><td style="padding:14px 28px;">'
          . '<p style="font-size:12.5px;color:#7a0e1f;background:#fef2f2;border-left:3px solid #dc2626;padding:10px 12px;border-radius:4px;margin:0;line-height:1.6;"><strong>¿No solicitaste este código?</strong> Escríbenos de inmediato:<br>📧 <a href="mailto:ventas@voltika.mx" style="color:#dc2626;font-weight:700;">ventas@voltika.mx</a></p>'
          . '</td></tr>';

    $emailHtml = voltikaLogisticsEmailShell(
        '🔐 Tu código de entrega',
        'Válido por 10 minutos',
        $rows
    );

    return ['subject' => '🔐 Código de entrega Voltika', 'body' => $body, 'sms' => $sms, 'email_html' => $emailHtml];
}

// ── Acta firmada — entrega completada ───────────────────────────────────────
function voltikaBuildActaFirmadaTemplate(): array {
    $subject = '✅ Acta de Entrega firmada — Tu VOLTIKA es oficialmente tuya — Pedido {pedido_corto}';
    $rows = '<tr><td style="padding:22px 28px 6px;">'
          . '<div style="font-size:17px;color:#1a3a5c;">Hola <strong>{nombre}</strong> 🎉</div>'
          . '<p style="font-size:14px;line-height:1.6;color:#444;margin:10px 0 0;">¡Tu moto es oficialmente tuya desde este momento!</p>'
          . '<p style="font-size:13px;color:#333;margin:10px 0 0;">Has firmado el Acta de Entrega de tu <strong>{modelo}</strong> · {color}<br>Pedido: <strong>{pedido_corto}</strong><br>Fecha y hora de entrega: <strong>{fecha_entrega}</strong></p>'
          . '</td></tr>'
          . '<tr><td style="padding:14px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">📋 Resumen de tu entrega</div>'
          . '<p style="font-size:13px;color:#444;margin:0 0 8px;">Este documento certifica que:</p>'
          . '<div style="font-size:13.5px;color:#333;line-height:1.8;">'
          . '✓ Recibiste tu <strong>{modelo} · {color}</strong> con número de serie <strong>{vin}</strong> en perfectas condiciones<br>'
          . '✓ Verificaste su funcionamiento antes de recibirla<br>'
          . '✓ Tu identidad fue validada mediante reconocimiento facial y código OTP<br>'
          . '✓ Firmaste digitalmente el Acta de Entrega con validez legal<br>'
          . '✓ Aceptaste los términos de tu contrato VOLTIKA'
          . '</div></td></tr>'
          . '<tr><td style="padding:14px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">📄 Tu acta de entrega</div>'
          . '<p style="font-size:13px;color:#444;margin:0;line-height:1.6;">Tu acta firmada ya está disponible en tu portal como comprobante oficial de entrega:<br><a href="https://voltika.mx/clientes/" target="_blank" rel="noopener noreferrer" style="color:#039fe1;font-weight:700;">👉 voltika.mx/clientes/</a></p>'
          . '<p style="font-size:12.5px;color:#555;margin:8px 0 0;">Guárdala — es tu documento legal que acredita que eres el propietario de la moto desde este momento.</p>'
          . '</td></tr>'
          . '<tr><td style="padding:14px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#b45309;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">📌 Recuerda antes de salir</div>'
          . '<p style="font-size:13px;color:#7a4f08;background:#fffbeb;border-left:3px solid #f59e0b;padding:10px 12px;border-radius:4px;margin:0;line-height:1.6;">Coloca tu permiso temporal <strong>enmicado en la parte trasera</strong> de tu moto — es obligatorio para circular legalmente. Tienes <strong>30 días</strong> desde la emisión para tramitar tus placas definitivas.</p>'
          . '</td></tr>'
          . '<tr><td style="padding:14px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">📱 Tu portal de cliente</div>'
          . '<p style="font-size:13px;color:#444;margin:0 0 6px;">Accede en cualquier momento a:<br><a href="https://voltika.mx/clientes/" target="_blank" rel="noopener noreferrer" style="color:#039fe1;font-weight:700;">👉 voltika.mx/clientes/</a></p>'
          . '<div style="font-size:13px;color:#333;line-height:1.7;margin-top:6px;">'
          . '✅ Descargar tu acta de entrega<br>'
          . '✅ Descargar tu contrato<br>'
          . '✅ Consultar tus pagos<br>'
          . '✅ Descargar tu permiso temporal<br>'
          . '✅ Ver toda la información de tu moto'
          . '</div></td></tr>'
          // Rich extra sections
          . vkPermisoRow()
          . vkFacturaRow()
          . vkSeguroPlacasRow()
          . '<tr><td style="padding:12px 28px 0;"><p style="font-size:13.5px;color:#1a3a5c;margin:0;font-weight:700;">¡Bienvenido a la familia VOLTIKA! Disfruta tu moto y la libertad de la movilidad eléctrica ⚡</p></td></tr>';
    $emailHtml = voltikaLogisticsEmailShell('✅ Acta de Entrega firmada', 'Pedido {pedido_corto}', $rows);

    $body = "✅ ¡{nombre}, tu moto es oficialmente\ntuya desde este momento! 🎉\n\n"
          . "Has firmado el Acta de Entrega\nde tu {modelo} · {color}\nPedido: {pedido_corto}\n\n"
          . "📋 Este documento confirma que:\n"
          . "✓ Recibiste tu moto en perfectas\n  condiciones\n"
          . "✓ Verificaste su funcionamiento\n"
          . "✓ Aceptaste los términos de tu contrato\n"
          . "✓ La entrega fue validada con tu\n  identidad y código de seguridad\n\n"
          . "Tu acta firmada ya está disponible\nen tu portal:\n👉 voltika.mx/clientes/\n\n"
          . "━━━━━━━━━━━━━━━━━━━━\n\n"
          . "📄 Recuerda colocar tu permiso\nenmicado en la parte trasera\nde tu moto antes de salir.\n\n"
          . "Tienes 30 días para tramitar\ntus placas definitivas.\n\n"
          . "━━━━━━━━━━━━━━━━━━━━\n\n"
          . "¡Disfruta tu VOLTIKA! ⚡\n\n"
          . "¿Dudas?\n📧 ventas@voltika.mx";
    $sms = 'VOLTIKA: {nombre}, tu {modelo} es oficialmente tuya. Descarga tu acta en voltika.mx/clientes/. Permiso: coloca atrás de la moto. 30 días para placas.';
    return ['subject' => $subject, 'body' => $body, 'sms' => $sms, 'email_html' => $emailHtml];
}

// ── Incidencia al entregar ──────────────────────────────────────────────────
function voltikaBuildIncidenciaTemplate(): array {
    $subject = '⚠️ Recibimos tu reporte — Te contactamos en 24 hrs — Pedido {pedido_corto}';
    $rows = '<tr><td style="padding:22px 28px 6px;">'
          . '<div style="font-size:17px;color:#1a3a5c;">Hola <strong>{nombre}</strong> 👋</div>'
          . '<p style="font-size:14px;line-height:1.6;color:#444;margin:10px 0 0;">Recibimos tu reporte y lo registramos en nuestro sistema. Entendemos que esto puede ser frustrante y queremos que sepas que ya estamos en ello.</p>'
          . '<p style="font-size:12px;color:#666;margin:8px 0 0;">Pedido: <strong>{pedido_corto}</strong><br>🏍️ <strong>{modelo}</strong> · {color}</p>'
          . '</td></tr>'
          . '<tr><td style="padding:14px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#b45309;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">📋 Lo que nos reportaste</div>'
          . '<div style="padding:12px 14px;background:#fffbeb;border-left:4px solid #f59e0b;border-radius:6px;font-size:13.5px;color:#7a4f08;line-height:1.6;">"{mensaje}"</div>'
          . '<p style="font-size:12.5px;color:#555;margin:10px 0 0;line-height:1.6;">Fecha y hora del reporte: <strong>{fecha_reporte}</strong><br>Número de caso: <strong>{numero_caso}</strong></p>'
          . '<p style="font-size:12px;color:#888;margin:6px 0 0;">Guarda este número — te sirve para dar seguimiento a tu caso.</p>'
          . '</td></tr>'
          . '<tr><td style="padding:14px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">🔄 ¿Qué sigue?</div>'
          . '<p style="font-size:13px;color:#444;margin:0 0 8px;line-height:1.6;">Nuestro equipo de soporte ya tiene tu caso asignado y lo está revisando.<br>Te contactaremos en <strong>menos de 24 hrs</strong> con una respuesta y plan de acción.</p>'
          . '<p style="font-size:12.5px;color:#166534;background:#ecfdf5;padding:8px 12px;border-radius:4px;border-left:3px solid #22d37a;margin:10px 0 0;">No necesitas llamar ni escribir de nuevo — ya tenemos tu caso y nosotros te buscamos 🙌</p>'
          . '</td></tr>'
          . '<tr><td style="padding:14px 28px;">'
          . '<p style="font-size:13px;color:#444;margin:0;line-height:1.6;">¿Es una situación urgente?<br>📧 <a href="mailto:ventas@voltika.mx" style="color:#039fe1;font-weight:700;">ventas@voltika.mx</a></p>'
          . '</td></tr>'
          // Rich extra sections — same density as compra_confirmada
          . vkPortalRow();
    $emailHtml = voltikaLogisticsEmailShell('⚠️ Reporte recibido', 'Caso {numero_caso}', $rows);

    $body = "⚠️ Hola {nombre}, recibimos tu\nreporte sobre tu {modelo} · {color}\nPedido: {pedido_corto}\n\n"
          . "📋 Lo que nos reportaste:\n\"{mensaje}\"\n\n"
          . "Tu reporte quedó registrado en\nnuestro sistema con fecha y hora.\nNúmero de caso: {numero_caso}\n\nNuestro equipo de soporte lo está\nrevisando ahora mismo.\n\n"
          . "Te contactaremos en menos de 24 hrs\npara darte seguimiento y solución.\n\n"
          . "No necesitas llamar ni escribir\nde nuevo — ya tenemos tu caso\ny te buscamos nosotros 🙌\n\n"
          . "Si es urgente escríbenos a:\n📧 ventas@voltika.mx\n🕐 Lun a Vie 9:00 - 18:00 hrs";
    $sms = 'VOLTIKA: {nombre}, recibimos tu reporte. Caso {numero_caso}. Te contactamos en 24h. Urgente: ventas@voltika.mx';
    return ['subject' => $subject, 'body' => $body, 'sms' => $sms, 'email_html' => $emailHtml];
}

// ── Cobranza email shell (reused by M1/M2/M3/M5/M6) ────────────────────────
function voltikaBuildCobranzaEmailHtml(string $hero, string $heroSub, string $innerRows): string {
    // Customer brief 2026-04-20: same visual richness as compra_confirmada.
    // Adds 4 standard sections after the per-message inner rows: methods,
    // portal CTA, no-duplicate guarantee, and a "why we care" footer note.
    $tail = '<tr><td style="padding:14px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">💳 Cómo pagar</div>'
          . '<div style="background:#f5f7fa;border-radius:8px;padding:12px 14px;font-size:13.5px;color:#333;line-height:1.9;">'
          . '🏪 <strong>OXXO</strong> — efectivo en cualquier tienda del país<br>'
          . '🏦 <strong>SPEI</strong> — transferencia desde tu banco<br>'
          . '💳 <strong>Tarjeta</strong> desde tu portal:<br>'
          . '<a href="{payment_link}" target="_blank" rel="noopener noreferrer" style="color:#039fe1;font-weight:700;">👉 {payment_link}</a>'
          . '</div>'
          . '<p style="font-size:11.5px;color:#777;margin:8px 0 0;line-height:1.5;">⏱️ OXXO y SPEI tardan hasta 24 horas en acreditarse.</p>'
          . '</td></tr>'
          // No-duplicate guarantee — green box matching compra_confirmada style
          . '<tr><td style="padding:14px 28px;">'
          . '<div style="background:#ecfdf5;border:1px solid #a7f3d0;border-left:4px solid #22c55e;border-radius:8px;padding:12px 14px;display:flex;gap:10px;align-items:flex-start;">'
          . '<span style="color:#16a34a;font-size:18px;line-height:1;">✓</span>'
          . '<div style="font-size:13px;color:#166534;line-height:1.55;"><strong>Tu pago nunca se duplica.</strong> Si realizas un pago manual el sistema lo detecta automáticamente y cancela cualquier cargo adicional.</div>'
          . '</div></td></tr>'
          // Portal saldo
          . '<tr><td style="padding:14px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">📱 Tu portal de cliente</div>'
          . '<p style="font-size:13.5px;color:#333;margin:0 0 6px;">Consulta tu saldo, historial de pagos y descarga comprobantes:</p>'
          . '<p style="margin:0;"><a href="https://voltika.mx/clientes/" target="_blank" rel="noopener noreferrer" style="color:#039fe1;font-weight:700;">👉 voltika.mx/clientes/</a></p>'
          . '</td></tr>';
    return voltikaLogisticsEmailShell($hero, $heroSub, $innerRows . $tail);
}

// ── M1: recordatorio 2 días antes ───────────────────────────────────────────
function voltikaBuildRecordatorio2diasTemplate(): array {
    $subject = '⏰ Tu pago de ${monto_semanal} vence en 2 días — Pedido {pedido_corto}';
    $rows = '<tr><td style="padding:22px 28px 6px;">'
          . '<div style="font-size:17px;color:#1a3a5c;">Hola <strong>{nombre}</strong> 👋</div>'
          . '<p style="font-size:14px;line-height:1.6;color:#444;margin:10px 0 0;">Tu pago semanal de <strong>${monto_semanal}</strong> vence el <strong>{fecha_vencimiento}</strong>.</p>'
          . '</td></tr>'
          . '<tr><td style="padding:10px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#b45309;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">💡 Págalo hoy</div>'
          . '<p style="font-size:13px;color:#444;margin:0;line-height:1.6;">OXXO y SPEI tardan <strong>24 hrs</strong> en acreditarse — si esperas al día del vencimiento puede llegar tarde.</p>'
          . '</td></tr>'
          . '<tr><td style="padding:14px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">💡 ¿Tienes tarjeta registrada?</div>'
          . '<p style="font-size:13px;color:#444;margin:0;line-height:1.6;">Tu tarjeta actúa como respaldo automático el día del vencimiento si no detectamos otro pago antes. Pagar por OXXO o SPEI hoy es la mejor opción.</p>'
          . '</td></tr>';
    $emailHtml = voltikaBuildCobranzaEmailHtml('⏰ Tu pago vence en 2 días', 'Pedido {pedido_corto} · ${monto_semanal}', $rows);

    $body = "⏰ {nombre}, tu pago de \${monto_semanal}\nvence el {fecha_vencimiento}.\n\n"
          . "Págalo HOY — OXXO y SPEI tardan\n24 hrs en acreditarse:\n🏪 OXXO\n🏦 SPEI\n💳 👉 {payment_link}\n\n"
          . "💡 Tu tarjeta es solo respaldo\nautomático si no detectamos\notro pago antes del vencimiento.";
    $sms = "Voltika: {nombre}, pago de \${monto_semanal} vence el {fecha_vencimiento}. Págalo hoy — OXXO/SPEI tardan 24hrs: {payment_link}";
    return ['subject' => $subject, 'body' => $body, 'sms' => $sms, 'email_html' => $emailHtml];
}

// ── M1B: 24h antes del cargo (pre-charge per Tech Spec EN §9) ────────────
// Mexican-law-compliant pre-charge notice required by Cláusula Séptima of
// the v5 contract: customer must be notified before each recurring charge.
// Emitted 1 day before the due date, at 6PM, so the customer has the
// evening + early next day to switch payment method or pay manually.
function voltikaBuildRecordatorio1diaTemplate(): array {
    $subject = '🔔 Cargo automático mañana: ${monto_semanal} — Pedido {pedido_corto}';
    $rows = '<tr><td style="padding:22px 28px 6px;">'
          . '<div style="font-size:17px;color:#1a3a5c;">Hola <strong>{nombre}</strong> 👋</div>'
          . '<p style="font-size:14px;line-height:1.6;color:#444;margin:10px 0 0;">Mañana <strong>{fecha_vencimiento}</strong> haremos el cargo automático a tu tarjeta registrada.</p>'
          . '</td></tr>'
          . '<tr><td style="padding:14px 28px;">'
          . '<table cellpadding="0" cellspacing="0" style="background:#F1F9FF;border-radius:10px;width:100%;border:1px solid #B3D4FC;">'
          . '<tr><td style="padding:14px;"><div style="font-size:11px;color:#777;text-transform:uppercase;letter-spacing:.5px;">Detalle del cargo</div>'
          . '<div style="font-size:22px;font-weight:800;color:#1a3a5c;margin-top:4px;">${monto_semanal} MXN</div>'
          . '<div style="font-size:13px;color:#444;margin-top:6px;">Tarjeta {card_brand} ··· {card_last4}</div>'
          . '<div style="font-size:13px;color:#444;">Fecha: <strong>{fecha_vencimiento}</strong></div>'
          . '</td></tr></table></td></tr>'
          . '<tr><td style="padding:10px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">¿Quieres cambiar el medio?</div>'
          . '<p style="font-size:13px;color:#444;margin:0;line-height:1.6;">Puedes pagar HOY por OXXO o SPEI desde tu portal — si lo haces antes del cargo, tu tarjeta no se cobra (no hay duplicado).</p>'
          . '<p style="font-size:13px;color:#444;margin:8px 0 0;line-height:1.6;">También puedes <strong>actualizar tu tarjeta</strong> si la actual ya no funciona.</p>'
          . '</td></tr>';
    $emailHtml = voltikaBuildCobranzaEmailHtml('🔔 Cargo automático mañana', 'Pedido {pedido_corto} · ${monto_semanal}', $rows);

    $body = "🔔 {nombre}, mañana {fecha_vencimiento}\nharemos el cargo automático\nde \${monto_semanal} a tu\ntarjeta {card_brand} ··· {card_last4}.\n\n"
          . "¿Quieres pagar hoy por OXXO\no SPEI? No hay duplicado:\n💳 👉 {payment_link}\n\n"
          . "También puedes actualizar tu\ntarjeta antes del cargo.";
    $sms = "Voltika: {nombre}, mañana {fecha_vencimiento} cargo de \${monto_semanal} a tarjeta ··{card_last4}. Cambiar/pagar hoy: {payment_link}";
    return ['subject' => $subject, 'body' => $body, 'sms' => $sms, 'email_html' => $emailHtml];
}

// ── M2: vence hoy ───────────────────────────────────────────────────────────
function voltikaBuildPagoVenceHoyTemplate(): array {
    $subject = '🔔 Hoy vence tu pago de ${monto_semanal} — Pedido {pedido_corto}';
    $rows = '<tr><td style="padding:22px 28px 6px;">'
          . '<div style="font-size:17px;color:#1a3a5c;">Hola <strong>{nombre}</strong> 👋</div>'
          . '<p style="font-size:14px;line-height:1.6;color:#444;margin:10px 0 0;">HOY es el último día para pagar sin cargos por atraso.<br>Tu pago semanal de <strong>${monto_semanal}</strong> vence hoy.</p>'
          . '</td></tr>'
          . '<tr><td style="padding:10px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#dc2626;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">⚠️ Págalo ahora</div>'
          . '<p style="font-size:13px;color:#444;margin:0;line-height:1.6;">Si pagas por OXXO o SPEI hazlo de inmediato — tardan <strong>24 hrs</strong> en acreditarse.</p>'
          . '</td></tr>'
          . '<tr><td style="padding:14px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">💡 ¿Tienes tarjeta registrada?</div>'
          . '<p style="font-size:13px;color:#444;margin:0;line-height:1.6;">Si no detectamos otro pago hoy se intenta el cargo automático al final del día. Pagar directo en OXXO o SPEI siempre es la mejor opción — el pago no se duplica.</p>'
          . '</td></tr>'
          . '<tr><td style="padding:10px 28px;">'
          . '<p style="font-size:12.5px;color:#555;background:#f5f7fa;padding:10px 12px;border-radius:6px;margin:0;">¿Ya pagaste ayer por OXXO o SPEI? Ignora este mensaje — tu pago está en proceso de acreditación.</p>'
          . '</td></tr>';
    $emailHtml = voltikaBuildCobranzaEmailHtml('🔔 Tu pago vence HOY', 'Pedido {pedido_corto} · ${monto_semanal}', $rows);

    $body = "🔔 {nombre}, HOY vence tu pago\nde \${monto_semanal}.\n\n"
          . "Si pagas por OXXO o SPEI hazlo\nde inmediato — tardan 24 hrs\nen acreditarse:\n🏪 OXXO\n🏦 SPEI\n💳 👉 {payment_link}\n\n"
          . "💡 Tu tarjeta registrada actúa\ncomo respaldo automático hoy\nsi no detectamos otro pago.\n\n"
          . "¿Ya pagaste ayer? Ignora esto.";
    $sms = "Voltika: {nombre}, HOY vence \${monto_semanal}. Paga ya — OXXO/SPEI tardan 24hrs: {payment_link}";
    return ['subject' => $subject, 'body' => $body, 'sms' => $sms, 'email_html' => $emailHtml];
}

// ── M3: vencido 48h ─────────────────────────────────────────────────────────
function voltikaBuildPagoVencido48hTemplate(): array {
    $subject = '⚠️ Tu pago lleva 2 días vencido — Pedido {pedido_corto}';
    $rows = '<tr><td style="padding:22px 28px 6px;">'
          . '<div style="font-size:17px;color:#1a3a5c;">Hola <strong>{nombre}</strong>,</div>'
          . '<p style="font-size:14px;line-height:1.6;color:#444;margin:10px 0 0;">Tu pago de <strong>${monto_semanal}</strong> lleva 2 días vencido y ya se acumulan cargos por atraso en tu cuenta.</p>'
          . '</td></tr>'
          . '<tr><td style="padding:10px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#dc2626;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">Regulariza hoy</div>'
          . '<p style="font-size:13px;color:#444;margin:0;line-height:1.6;">Cada día que pasa sin pagar los cargos por atraso aumentan.</p>'
          . '</td></tr>'
          . '<tr><td style="padding:14px 28px;">'
          . '<p style="font-size:12.5px;color:#555;background:#f5f7fa;padding:10px 12px;border-radius:6px;margin:0;line-height:1.6;"><strong>¿Pagaste ayer por OXXO o SPEI?</strong><br>Ignora este mensaje — tu pago está en proceso de acreditación y se verá reflejado en 24 hrs.</p>'
          . '</td></tr>'
          . '<tr><td style="padding:10px 28px;">'
          . '<p style="font-size:13px;color:#444;margin:0;line-height:1.6;">¿Tienes algún problema con tu pago? Escríbenos hoy — podemos ayudarte:<br>📧 <a href="mailto:ventas@voltika.mx" style="color:#039fe1;font-weight:700;">ventas@voltika.mx</a></p>'
          . '</td></tr>';
    $emailHtml = voltikaBuildCobranzaEmailHtml('⚠️ 2 días vencido', 'Pedido {pedido_corto}', $rows);

    $body = "⚠️ {nombre}, tu pago de \${monto_semanal}\nlleva 2 días vencido y ya acumula\ncargos por atraso.\n\n"
          . "Regulariza hoy:\n🏪 OXXO\n🏦 SPEI\n💳 👉 {payment_link}\n\n"
          . "¿Pagaste ayer por OXXO o SPEI?\nEspera 24 hrs — está acreditándose.\n\n"
          . "¿Problema con tu pago?\n📧 ventas@voltika.mx";
    $sms = "Voltika: {nombre}, 2 días vencido. Cargos acumulándose. Regulariza: {payment_link}";
    return ['subject' => $subject, 'body' => $body, 'sms' => $sms, 'email_html' => $emailHtml];
}

// ── M4: vencido 96h (critical tone) ─────────────────────────────────────────
function voltikaBuildPagoVencido96hTemplate(): array {
    $subject = '🔴 Pago vencido — 4 días — Pedido {pedido_corto}';
    $rows = '<tr><td style="padding:22px 28px 6px;">'
          . '<div style="font-size:17px;color:#1a3a5c;">Hola <strong>{nombre}</strong>,</div>'
          . '<p style="font-size:14px;line-height:1.6;color:#444;margin:10px 0 0;">Tu pago lleva <strong>4 días vencido</strong>. Tu saldo incluye <strong>${monto_semanal}</strong> más cargos por atraso acumulados.</p>'
          . '</td></tr>'
          // Critical warning red box
          . '<tr><td style="padding:6px 28px;">'
          . '<div style="background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #dc2626;border-radius:8px;padding:14px 16px;">'
          . '<div style="font-size:13px;font-weight:800;color:#991b1b;letter-spacing:.4px;text-transform:uppercase;margin-bottom:8px;">⚠️ Si no regularizas hoy</div>'
          . '<div style="font-size:13.5px;color:#7a0e1f;line-height:1.7;">'
          . '❌ Los cargos por atraso siguen aumentando cada día<br>'
          . '❌ Tu historial en <strong>Buró de Crédito</strong> se ve afectado<br>'
          . '❌ El acceso al portal puede ser limitado'
          . '</div></div></td></tr>'
          . '<tr><td style="padding:14px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">¿Tienes algún problema?</div>'
          . '<p style="font-size:13.5px;color:#333;line-height:1.6;margin:0;">Si necesitas reestructurar tu plan o tienes una dificultad temporal, escríbenos hoy — podemos ayudarte:<br>📧 <a href="mailto:ventas@voltika.mx" style="color:#039fe1;font-weight:700;">ventas@voltika.mx</a></p>'
          . '</td></tr>';
    $emailHtml = voltikaBuildCobranzaEmailHtml('🔴 Pago vencido — 4 días', 'Acción urgente requerida', $rows);

    $body = "🔴 {nombre}, 4 días vencido.\n\n"
          . "Tu saldo incluye \${monto_semanal}\nmás cargos por atraso acumulados.\n\n"
          . "Si no regularizas hoy:\n❌ Los cargos siguen aumentando\n❌ Tu historial en Buró de Crédito\n   se ve afectado\n\n"
          . "Paga ahora:\n🏪 OXXO\n🏦 SPEI\n💳 👉 {payment_link}\n\n"
          . "👉 voltika.mx/clientes/\n\n"
          . "¿Necesitas apoyo?\n📧 ventas@voltika.mx";
    $sms = "Voltika: {nombre}, 4 días vencido. Riesgo de reporte a Buró. Regulariza hoy: {payment_link}";
    return ['subject' => $subject, 'body' => $body, 'sms' => $sms, 'email_html' => $emailHtml];
}

// ── M5: incentivo adelanto ──────────────────────────────────────────────────
function voltikaBuildIncentivoAdelantoTemplate(): array {
    $subject = '💡 Adelanta pagos sin costo extra y liquida tu VOLTIKA antes';
    $rows = '<tr><td style="padding:22px 28px 6px;">'
          . '<div style="font-size:17px;color:#1a3a5c;">Hola <strong>{nombre}</strong> 👋</div>'
          . '<p style="font-size:14px;line-height:1.6;color:#444;margin:10px 0 0;">¿Sabías que puedes adelantar pagos de tu VOLTIKA sin ningún costo adicional?</p>'
          . '</td></tr>'
          . '<tr><td style="padding:10px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">¿Por qué adelantar?</div>'
          . '<div style="font-size:13.5px;color:#333;line-height:1.8;">'
          . '✅ Reduces tu saldo pendiente<br>'
          . '✅ Te acercas a liquidar antes<br>'
          . '✅ Te olvidas de fechas de pago<br>'
          . '✅ Sin ningún cargo extra'
          . '</div></td></tr>'
          . '<tr><td style="padding:14px 28px;">'
          . '<p style="font-size:12.5px;color:#555;background:#f5f7fa;padding:10px 12px;border-radius:6px;margin:0;line-height:1.6;"><strong>⚠️ OXXO y SPEI tardan 24 hrs en acreditarse.</strong><br>💡 Si haces un pago adelantado el cargo automático de esa semana no se duplica — el sistema lo detecta solo.</p>'
          . '</td></tr>';
    $emailHtml = voltikaBuildCobranzaEmailHtml('💡 Adelanta pagos sin costo', 'Liquida tu VOLTIKA antes', $rows);

    $body = "💡 {nombre}, adelanta pagos\nsin ningún costo extra.\n\n"
          . "Cada pago adelantado reduce\ntu saldo y acerca tu liquidación.\n\n"
          . "🏪 OXXO\n🏦 SPEI\n💳 👉 {payment_link}\n\n"
          . "Tu tarjeta no se cobra doble —\nel sistema lo detecta solo.";
    $sms = "Voltika: {nombre}, adelanta pagos sin costo. Reduce tu saldo: {payment_link}";
    return ['subject' => $subject, 'body' => $body, 'sms' => $sms, 'email_html' => $emailHtml];
}

// ── M6: pago recibido ──────────────────────────────────────────────────────
function voltikaBuildPagoRecibidoTemplate(): array {
    $subject = '✅ Pago recibido — Semana {semana} — Pedido {pedido_corto}';
    $rows = '<tr><td style="padding:22px 28px 6px;">'
          . '<div style="font-size:17px;color:#1a3a5c;">¡Hola <strong>{nombre}</strong>! ✅</div>'
          . '<p style="font-size:14px;line-height:1.6;color:#444;margin:10px 0 0;">¡Pago recibido y aplicado a tu cuenta! Tu cuenta sigue al corriente ⚡</p>'
          . '</td></tr>'
          // Pago amount feature card
          . '<tr><td style="padding:6px 28px;">'
          . '<div style="background:linear-gradient(135deg,#ecfdf5,#d1fae5);border:1px solid #a7f3d0;border-radius:10px;padding:18px;text-align:center;">'
          . '<div style="font-size:11.5px;font-weight:700;color:#16a34a;letter-spacing:.5px;text-transform:uppercase;margin-bottom:6px;">Monto recibido</div>'
          . '<div style="font-size:32px;font-weight:800;color:#166534;line-height:1;">${monto}</div>'
          . '<div style="font-size:13px;color:#16a34a;margin-top:6px;font-weight:600;">Semana {semana} cubierta</div>'
          . '</div></td></tr>'
          // Próximo pago
          . '<tr><td style="padding:14px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">📆 Próximo pago</div>'
          . '<p style="font-size:14px;color:#333;margin:0;"><strong>{proximo_pago}</strong></p>'
          . '<p style="font-size:12.5px;color:#666;margin:6px 0 0;">Te recordaremos antes del vencimiento por WhatsApp y email.</p>'
          . '</td></tr>'
          // Adelanto incentive
          . '<tr><td style="padding:14px 28px;">'
          . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px;">💡 ¿Quieres adelantar?</div>'
          . '<p style="font-size:13.5px;color:#333;line-height:1.6;margin:0 0 8px;">Cada pago adelantado reduce tu saldo y acerca tu liquidación. Sin ningún costo extra.</p>'
          . '<p style="margin:0;"><a href="{payment_link}" target="_blank" rel="noopener noreferrer" style="color:#039fe1;font-weight:700;">👉 Adelantar ahora</a></p>'
          . '</td></tr>';
    $emailHtml = voltikaBuildCobranzaEmailHtml('✅ Pago recibido', 'Semana {semana} cubierta', $rows);

    $body = "✅ ¡{nombre}, pago recibido!\n\n"
          . "💰 \${monto} — Semana {semana} cubierta\n"
          . "📆 Próximo pago: {proximo_pago}\n\n"
          . "¿Lo adelantas ahora?\nSin costo extra 👉 {payment_link}\n\n"
          . "Tu cuenta al corriente ⚡";
    $sms = "Voltika: ¡{nombre}, \${monto} recibido! Semana {semana} cubierta. Próximo: {proximo_pago}. ¿Lo adelantas? {payment_link}";
    return ['subject' => $subject, 'body' => $body, 'sms' => $sms, 'email_html' => $emailHtml];
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

    // Logistics stages — customer brief 2026-04-19.
    $tplPuntoAsig    = voltikaBuildPuntoAsignadoTemplate();
    $tplMotoEnviada  = voltikaBuildMotoEnviadaTemplate();
    $tplMotoRecibida = voltikaBuildMotoRecibidaTemplate();
    $tplMotoLista    = voltikaBuildMotoListaEntregaTemplate();

    // OTP / acta / incidencia / cobranza — customer brief 2026-04-19 batch 2.
    $tplOtp         = voltikaBuildOtpEntregaTemplate();
    $tplActa        = voltikaBuildActaFirmadaTemplate();
    $tplIncidencia  = voltikaBuildIncidenciaTemplate();
    $tplCobr2d      = voltikaBuildRecordatorio2diasTemplate();
    $tplCobr1d      = voltikaBuildRecordatorio1diaTemplate();
    $tplCobrHoy     = voltikaBuildPagoVenceHoyTemplate();
    $tplCobr48h     = voltikaBuildPagoVencido48hTemplate();
    $tplCobr96h     = voltikaBuildPagoVencido96hTemplate();
    $tplCobrIncent  = voltikaBuildIncentivoAdelantoTemplate();
    $tplCobrRecv    = voltikaBuildPagoRecibidoTemplate();

    return [
        'compra_confirmada_contado_punto'     => $tplCP,
        'compra_confirmada_contado_sin_punto' => $tplCNP,
        'compra_confirmada_credito_punto'     => $tplKP,
        'compra_confirmada_credito_sin_punto' => $tplKNP,

        // New portal access templates (replaces legacy inline definitions below)
        'portal_contado' => $tplPortalContado,
        'portal_msi'     => $tplPortalContado,
        'portal_plazos'  => $tplPortalCredito,

        // Logistics — rich rewritten templates (override the legacy short ones
        // further down in this file thanks to PHP array later-key-wins).
        'punto_asignado'      => $tplPuntoAsig,
        'moto_enviada'        => $tplMotoEnviada,
        'moto_recibida'       => $tplMotoRecibida,
        'moto_en_punto'       => $tplMotoRecibida,  // alias for backward compat
        'moto_lista_entrega'  => $tplMotoLista,
        'lista_para_recoger'  => $tplMotoLista,     // alias for backward compat

        // Batch 2 overrides — the legacy inline definitions further down are
        // replaced thanks to PHP array later-key-wins.
        'otp_entrega'                 => $tplOtp,
        'acta_firmada'                => $tplActa,
        'entrega_completada'          => $tplActa,    // alias — same content
        'recepcion_incidencia'        => $tplIncidencia,
        'recordatorio_pago_2dias'     => $tplCobr2d,
        'recordatorio_pago_1dia'      => $tplCobr1d,
        'pago_vence_hoy'              => $tplCobrHoy,
        'pago_vencido_48h'            => $tplCobr48h,
        'pago_vencido_96h'            => $tplCobr96h,
        'incentivo_adelanto'          => $tplCobrIncent,
        'pago_recibido'               => $tplCobrRecv,

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
                         // Header (uses shared voltikaEmailHeader helper)
                         . voltikaEmailHeader('Bienvenido al equipo · {punto}', 'Red de Puntos Oficiales')
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
                         . '<tr><td style="padding:8px 14px;color:#666;">Panel</td><td style="padding:8px 14px;"><a href="https://{url}" target="_blank" rel="noopener noreferrer" style="color:#039fe1;font-weight:700;">https://{url}</a></td></tr>'
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
                         . '📱 WhatsApp: <a href="https://wa.me/525579440928" target="_blank" rel="noopener noreferrer" style="color:#039fe1;font-weight:700;">557 944 0928</a><br>'
                         . '📧 Email: <a href="mailto:puntos@voltika.mx" style="color:#039fe1;font-weight:700;">puntos@voltika.mx</a><br>'
                         . '🕐 Lunes a Viernes 9:00 - 18:00 hrs</p>'
                         . '<p style="font-size:12.5px;color:#555;line-height:1.6;margin:10px 0 0;">👤 Comunícate con el ejecutivo VOLTIKA que te contactó para afiliarte — él es tu contacto principal para dudas y capacitación por videollamada.</p>'
                         . '</td></tr>'
                         // Footer (shared helper)
                         . voltikaEmailFooter()
                         . '</table>'
                         . '</td></tr></table></body></html>',
        ],

        // Sent when a CEDIS controller account is created. No Punto line,
        // inventory-focused responsibilities.
        'credenciales_cedis' => [
            'subject' => '🏢 Acceso al CEDIS de VOLTIKA',
            'body'    => "🏢 Hola {nombre}, bienvenido al equipo VOLTIKA ⚡\n\n"
                       . "Ya tienes acceso al Panel de Administración como {rol}.\n"
                       . "Desde aquí gestionas todo el inventario: recepción de motos, asignación a puntos, envíos y reportes.\n\n"
                       . "🌐 Panel: https://{url}\n"
                       . "👤 Usuario: {email}\n"
                       . "🔒 Clave: {password}\n\n"
                       . "⚠️ Cambia tu contraseña al entrar por primera vez.\n\n"
                       . "¿Dudas? Contacto:\n"
                       . "📧 ventas@voltika.mx\n"
                       . "🕐 Lunes a Viernes 9:00 - 18:00 hrs\n\n"
                       . "Este es un mensaje automático — no respondas aquí.",
            'sms'     => 'VOLTIKA: Hola {nombre}, acceso CEDIS activo. Usuario: {email} Clave: {password} Panel: https://{url} Cambia tu clave al entrar.',
            'email_html' => '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Acceso CEDIS · VOLTIKA</title></head>'
                         . '<body style="margin:0;padding:0;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif;color:#1a3a5c;">'
                         . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;">'
                         . '<tr><td align="center" style="padding:24px 12px;">'
                         . '<table width="620" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;max-width:620px;width:100%;box-shadow:0 2px 12px rgba(0,0,0,0.08);">'
                         . voltikaEmailHeader('Acceso activo', 'Centro de Distribución')
                         . '<tr><td style="padding:26px 28px 10px;">'
                         . '<div style="font-size:17px;color:#1a3a5c;">Hola <strong>{nombre}</strong> 👋</div>'
                         . '<p style="font-size:14px;line-height:1.6;color:#444;margin:10px 0 0;">Tu cuenta como <strong>{rol}</strong> del Centro de Distribución de VOLTIKA ya está activa. Desde el panel administras toda la operación del inventario.</p>'
                         . '</td></tr>'
                         . '<tr><td style="padding:14px 28px;">'
                         . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">🔑 Tus credenciales de acceso</div>'
                         . '<table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;background:#f5f7fa;border-radius:8px;padding:4px;">'
                         . '<tr><td style="padding:8px 14px;color:#666;">Panel</td><td style="padding:8px 14px;"><a href="https://{url}" target="_blank" rel="noopener noreferrer" style="color:#039fe1;font-weight:700;">https://{url}</a></td></tr>'
                         . '<tr><td style="padding:8px 14px;color:#666;">Usuario</td><td style="padding:8px 14px;font-family:ui-monospace,Consolas,monospace;">{email}</td></tr>'
                         . '<tr><td style="padding:8px 14px;color:#666;">Contraseña</td><td style="padding:8px 14px;font-family:ui-monospace,Consolas,monospace;font-weight:700;">{password}</td></tr>'
                         . '</table>'
                         . '<p style="font-size:12px;color:#b45309;background:#fffbeb;border-left:3px solid #f59e0b;padding:8px 12px;border-radius:4px;margin-top:12px;"><strong>⚠️ Cambia tu contraseña en tu primer inicio de sesión.</strong><br>Menú superior → tu nombre → Cambiar contraseña.</p>'
                         . '</td></tr>'
                         . '<tr><td style="padding:14px 28px;">'
                         . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">📦 Tus responsabilidades</div>'
                         . '<ul style="font-size:13px;color:#444;line-height:1.8;padding-left:20px;margin:4px 0 12px;">'
                         . '<li>Recepción e inventariado de motos que llegan al CEDIS</li>'
                         . '<li>Asignación de motos a puntos de entrega</li>'
                         . '<li>Gestión de envíos y cambios de estado</li>'
                         . '<li>Importación/actualización de catálogo desde Excel</li>'
                         . '<li>Reportes y trazabilidad por VIN</li>'
                         . '</ul>'
                         . '<div style="text-align:center;margin:16px 0;">'
                         . '<a href="https://{url}" target="_blank" style="display:inline-block;background:#039fe1;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;">Entrar al panel</a>'
                         . '</div>'
                         . '</td></tr>'
                         . '<tr><td style="padding:14px 28px 22px;">'
                         . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">💬 Soporte</div>'
                         . '<p style="font-size:13px;color:#444;line-height:1.7;margin:0;">'
                         . '📧 Email: <a href="mailto:ventas@voltika.mx" style="color:#039fe1;font-weight:700;">ventas@voltika.mx</a><br>'
                         . '🕐 Lunes a Viernes 9:00 - 18:00 hrs</p>'
                         . '</td></tr>'
                         . voltikaEmailFooter()
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

        // Delivery flow (moto_enviada / moto_en_punto / lista_para_recoger)
        // is defined at the top of this function via voltikaBuild*Template()
        // with rich email_html — no stubs here.

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
            'body'    => "🎫 NUEVA SOLICITUD: asesoría de placas\n\nPedido: {pedido_corto}\nCliente: {nombre}\nTel. cliente: {telefono_cliente}\nEstado MX: {estado_mx}\nCiudad: {ciudad}\nModelo: {modelo}\n\nGestioná en: voltika.mx/admin/#ventas",
            'sms'     => 'Voltika ADMIN: Nueva solicitud placas. Pedido {pedido_corto} / {nombre} / {estado_mx}. Gestiona en admin.',
        ],

        // Sent to Voltika admin when a new order opts in for Quálitas insurance
        'admin_extras_seguro' => [
            'subject' => '🛡 Nueva solicitud Quálitas — Pedido {pedido}',
            'body'    => "🛡 NUEVA SOLICITUD: seguro Quálitas\n\nPedido: {pedido_corto}\nCliente: {nombre}\nTel. cliente: {telefono_cliente}\nUnidad: {modelo} · {color}\n\nCotizá y registra en: voltika.mx/admin/#ventas",
            'sms'     => 'Voltika ADMIN: Nueva solicitud Qualitas. Pedido {pedido_corto} / {nombre} / {modelo} {color}.',
        ],

        // Delivery / cobranza rich templates (punto_asignado, otp_entrega,
        // acta_firmada, entrega_completada, recepcion_incidencia, and the
        // 6 cobranza messages) are defined at the top of this function via
        // voltikaBuild*Template() — no stubs here.

        // ── Legacy payment templates (distinct keys, still referenced by
        // older callers — no rich override exists for these) ──────────────
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

    // Ensure pedido_corto has a sensible value — templates were migrated from
    // "VK-{pedido}" to "{pedido_corto}" (customer wants short codes). If the
    // caller forgot to pass it, synthesise from the legacy pedido so messages
    // never go out with a blank order number.
    if (empty($data['pedido_corto']) && !empty($data['pedido'])) {
        $data['pedido_corto'] = 'VK-' . $data['pedido'];
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
