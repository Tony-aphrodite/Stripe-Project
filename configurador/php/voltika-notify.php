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

if (!function_exists('voltikaConvertLogoToWhite')) {
    /**
     * Convert a black / coloured logo PNG to a white-on-transparent PNG
     * suitable for the dark navy email banner. Used the first time the
     * brand team uploads a fresh voltika_logo_h.png — runs once, caches
     * the result on disk so future emails just read the cache.
     *
     * Re-paints every opaque pixel to white, preserving the original
     * alpha channel, then resamples to 800px wide and adds 20px of
     * vertical padding so the V-shield doesn't kiss the banner edge.
     */
    function voltikaConvertLogoToWhite(string $srcPath, string $cachePath): string {
        try {
            $src = @imagecreatefrompng($srcPath);
            if (!$src) return '';
            $sw = imagesx($src);
            $sh = imagesy($src);

            // Resample to 800px wide (≈4× retina at 220px display).
            $tw = 800;
            $th = (int) round($tw * $sh / $sw);
            $resized = imagecreatetruecolor($tw, $th);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transp = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefill($resized, 0, 0, $transp);
            imagecopyresampled($resized, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);
            imagedestroy($src);

            // Re-paint every non-fully-transparent pixel to white, keep alpha.
            for ($y = 0; $y < $th; $y++) {
                for ($x = 0; $x < $tw; $x++) {
                    $rgba  = imagecolorat($resized, $x, $y);
                    $alpha = ($rgba >> 24) & 0x7F;
                    if ($alpha < 127) {
                        $white = imagecolorallocatealpha($resized, 255, 255, 255, $alpha);
                        imagesetpixel($resized, $x, $y, $white);
                    }
                }
            }

            // Add 20px vertical padding (no horizontal padding — the SVG
            // is already wide enough).
            $pad = 20;
            $cw  = $tw;
            $ch  = $th + 2 * $pad;
            $canvas = imagecreatetruecolor($cw, $ch);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $ct = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefill($canvas, 0, 0, $ct);
            imagealphablending($canvas, true);
            imagecopy($canvas, $resized, 0, $pad, 0, 0, $tw, $th);
            imagedestroy($resized);

            // Write cache for next request, then return the bytes for inlining.
            ob_start();
            imagepng($canvas, null, 9);
            $bin = ob_get_clean();
            imagedestroy($canvas);

            // Best-effort cache write (non-fatal if cache dir isn't writable).
            @file_put_contents($cachePath, $bin);

            return $bin ?: '';
        } catch (Throwable $e) {
            error_log('voltikaConvertLogoToWhite: ' . $e->getMessage());
            return '';
        }
    }
}

if (!function_exists('voltikaEmailLogoSrc')) {
    /**
     * Customer brief 2026-05-09 (round 4): the previous embed shipped a
     * generated text-only "VOLTIKA" wordmark, but the customer wants the
     * actual brand logo (V-shield + voltika) — i.e. a render of
     * configurador/img/voltika_logo_h_white.svg — to appear in every
     * email instead.
     *
     * Email clients overwhelmingly do NOT render inline SVG (Outlook
     * desktop, Gmail web, Yahoo, ProtonMail all strip it), so the SVG
     * was rasterised to an 800×132 white-on-transparent PNG via librsvg
     * and embedded below as a base64 constant. The aspect ratio is the
     * SVG's native 6.06:1 (1575×260 viewBox), so the email header /
     * footer dimensions were updated to match.
     *
     * Resolution priority:
     *   1. configurador/img/logo_w.png IF the brand team uploads a
     *      proper replacement (≥2KB) — overrides the embedded version.
     *   2. Embedded base64 brand logo (always works, no I/O).
     *   3. Absolute URL fallback (kept for paranoia, never reached now).
     *
     * Compatibility: data: URIs render in iOS Mail, Apple Mail, Gmail
     * web/iOS/Android, Yahoo, ProtonMail, Thunderbird. Outlook 365 /
     * Outlook desktop strip data URIs — the existing VML fallback in
     * voltikaEmailHeader/Footer covers Outlook with a text wordmark.
     */
    function voltikaEmailLogoSrc(): string {
        static $cached = null;
        if ($cached !== null) return $cached;

        // Resolution order — first match wins. The brand team can swap
        // the logo at any time by replacing one of these files; no PHP
        // edits required.
        //   (a) voltika_logo_h_white.png — preferred, ready to use
        //   (b) voltika_logo_h.png       — auto-converted to white the
        //                                  first time it's seen, then
        //                                  cached as voltika_logo_h_white.png
        //   (c) logo_w.png               — legacy slot
        $imgDir   = __DIR__ . '/../img/';
        $whitePng = $imgDir . 'voltika_logo_h_white.png';
        $blackPng = $imgDir . 'voltika_logo_h.png';
        $legacy   = $imgDir . 'logo_w.png';

        // (a) Pre-converted white logo — use as-is.
        if (is_readable($whitePng) && filesize($whitePng) >= 2048) {
            $bin = @file_get_contents($whitePng);
            if ($bin !== false && $bin !== '') {
                return $cached = 'data:image/png;base64,' . base64_encode($bin);
            }
        }

        // (b) Black/coloured logo — invert opaque pixels to white once
        //     and persist the result so subsequent requests use the cache.
        if (is_readable($blackPng) && filesize($blackPng) >= 2048 && function_exists('imagecreatefrompng')) {
            $whiteBin = voltikaConvertLogoToWhite($blackPng, $whitePng);
            if ($whiteBin !== '') {
                return $cached = 'data:image/png;base64,' . base64_encode($whiteBin);
            }
        }

        // (c) Legacy slot from earlier iterations.
        if (is_readable($legacy) && filesize($legacy) >= 2048) {
            $bin = @file_get_contents($legacy);
            if ($bin !== false && $bin !== '') {
                return $cached = 'data:image/png;base64,' . base64_encode($bin);
            }
        }

        // 2. Embedded VOLTIKA brand logo (V-shield + voltika wordmark) —
        //    rasterised 2026-05-09 from voltika_logo_h_white.svg via
        //    librsvg at 800×132 (white on transparent). Do NOT reformat
        //    or linewrap this string — it's a single base64 PNG payload.
        $b64 = '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';
        return $cached = 'data:image/png;base64,' . $b64;
    }
}

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
            $heroHtml .= '<div style="font-size:18px;font-weight:700;color:#ffffff;margin-top:18px;line-height:1.3;">' . $hero . '</div>';
        }
        if ($heroSub !== '') {
            $heroHtml .= '<div style="font-size:13px;color:#ffffff;margin-top:4px;">' . $heroSub . '</div>';
        }
        // Customer brief 2026-05-07: header was rendering blank (no logo,
        // invisible white text) in iOS Mail / Outlook / some Gmail
        // setups because:
        //   1. SVG logos are NOT supported by Outlook/iOS Mail/many
        //      mobile clients — switched to logo_w.png (white PNG).
        //   2. CSS gradients are stripped by Outlook → fallback to a
        //      solid bgcolor attribute + background-color so the dark
        //      navy is rendered no matter what. The gradient stays as
        //      a progressive enhancement for clients that support it.
        //   3. rgba() colors collapse on some clients — switched to
        //      solid #ffffff and rely on the dark background.
        // Customer brief 2026-05-09 (first real credit sale): the wordmark
        // came through too small to read on mobile mail clients — the source
        // PNG is 300×80 but it was being scaled to 140×44 in HTML, leaving
        // the actual letters at ~12px. Bumped the logo to 220px wide
        // (≈59px tall, original aspect) and added an HTML-only "VOLTIKA"
        // wordmark fallback for clients that block images entirely so the
        // brand is always present. Also made the tagline bolder for the
        // same reason.
        // Brand logo aspect ratio is ~4.65:1 (voltika_logo_h_white.png
        // with 20px vertical padding around the SVG content). Display at
        // 240×52 to keep the V-shield clearly readable on mobile and
        // give the wordmark a confident presence in the banner.
        $logoSrc = voltikaEmailLogoSrc();
        return '<tr><td bgcolor="#1a3a5c" style="background-color:#1a3a5c;background:linear-gradient(135deg,#1a3a5c 0%,#0d6aa0 50%,#039fe1 100%);padding:32px 28px 28px;text-align:center;">'
             .   '<!--[if !mso]><!-->'
             .   '<img src="' . $logoSrc . '"'
             .     ' alt="VOLTIKA" width="240" height="52"'
             .     ' style="display:block;margin:0 auto;border:0;outline:0;max-width:240px;width:240px;height:52px;">'
             .   '<!--<![endif]-->'
             .   '<!--[if mso]>'
             .   '<v:rect xmlns:v="urn:schemas-microsoft-com:vml" fillcolor="#1a3a5c" stroked="false" style="width:240px;height:52px;"><v:fill type="solid" color="#1a3a5c" /><v:textbox inset="0,0,0,0"><center style="font-family:Arial,sans-serif;font-size:28px;font-weight:800;color:#ffffff;letter-spacing:3px;">VOLTIKA</center></v:textbox></v:rect>'
             .   '<![endif]-->'
             .   '<div style="margin:12px 0 0;font-size:13px;color:#ffffff;letter-spacing:.4px;font-weight:500;">Movilidad eléctrica inteligente</div>'
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
        // Customer brief 2026-05-07: same SVG-to-PNG + bgcolor attribute
        // hardening as the header so the footer logo + legal line render
        // in every email client.
        // Customer brief 2026-05-09 (first real credit sale): footer logo
        // was effectively invisible — width="80" + height:22px shrunk the
        // wordmark below readability and the customer reported the footer
        // looked empty above the legal line. Bumped to 140px wide
        // (≈37px tall, original aspect) and added an Outlook VML fallback
        // so it shows even when the image is blocked.
        // Footer: smaller display (150×32, same 4.65:1 brand ratio).
        $logoSrc = voltikaEmailLogoSrc();
        return '<tr><td bgcolor="#1a3a5c" style="background-color:#1a3a5c;background:#1a3a5c;padding:24px 28px 22px;text-align:center;">'
             .   '<!--[if !mso]><!-->'
             .   '<img src="' . $logoSrc . '"'
             .     ' alt="VOLTIKA" width="150" height="32"'
             .     ' style="display:block;margin:0 auto 10px;border:0;outline:0;max-width:150px;width:150px;height:32px;">'
             .   '<!--<![endif]-->'
             .   '<!--[if mso]>'
             .   '<v:rect xmlns:v="urn:schemas-microsoft-com:vml" fillcolor="#1a3a5c" stroked="false" style="width:150px;height:32px;margin:0 auto 10px;"><v:fill type="solid" color="#1a3a5c" /><v:textbox inset="0,0,0,0"><center style="font-family:Arial,sans-serif;font-size:16px;font-weight:800;color:#ffffff;letter-spacing:2px;">VOLTIKA</center></v:textbox></v:rect>'
             .   '<![endif]-->'
             .   '<div style="font-size:11px;color:#ffffff;margin-top:4px;letter-spacing:.2px;">voltika.mx · Mtech Gears S.A. de C.V.</div>'
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
    // Customer brief 2026-05-09: real-sale audit found two bugs in this block:
    //   (1) `\${monto_semanal}` in a single-quoted PHP string leaks `\$` to
    //       the rendered HTML (single quotes don't process the `\` escape;
    //       only `\\` and `\'` are special). Fix: remove the `\`.
    //   (2) When the subscripcion lookup fails, `{monto_semanal}` is replaced
    //       with empty, leaving "Tu primer pago semanal de $ inicia…" — an
    //       obvious break the customer flagged. Fix: render the amount line
    //       only when it's known; otherwise drop the figure.
    $pagosHtml = '';
    if ($isCredit) {
        $pagosHtml = '<tr><td style="padding:14px 28px;">'
                   . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">💳 Tus pagos semanales</div>'
                   . '{pagos_semanales_intro}'
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
        ? '{wa_pagos_intro}'
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
    // Customer brief 2026-05-09: same `\$` literal + missing-amount fix as
    // voltikaBuildCompraTemplate above. Use the {portal_pago_intro} variable
    // so the sentence stays clean when monto_semanal can't be resolved.
    $pagosHtml = '';
    $duplicadoHtml = '';
    if ($isCredit) {
        $pagosHtml = '<tr><td style="padding:14px 28px;">'
                   . '<div style="font-size:13px;font-weight:700;color:#039fe1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:10px;">💳 Tu primer pago semanal</div>'
                   . '{portal_pago_intro}'
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
              . '{wa_portal_pago_intro}'
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

    // Customer brief 2026-05-06: switch to a single global sequential
    // counter under fixed prefix `1826` (Voltika's permit number).
    // Old per-month resets ("VK-2605-0001", "VK-2606-0001") meant pedido
    // numbers REPEATED across months, which broke the "search VK-N"
    // workflow. Now every order gets a unique VK-1826-NNNN that grows
    // monotonically forever. Existing orders keep their old format —
    // we only stamp NEW rows with the new pattern (the lookup + UNIQUE
    // index already prevent collisions across either pattern).
    $prefix = 'VK-1826-';

    for ($attempt = 0; $attempt < 5; $attempt++) {
        // Find the highest NNNN under the new prefix. Existing
        // VK-YYMM-NNNN rows are ignored for max() since the LIKE
        // restricts to the new prefix only.
        $cnt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(pedido_corto, '-', -1) AS UNSIGNED))
                                FROM transacciones
                               WHERE pedido_corto LIKE ?");
        $cnt->execute([$prefix . '%']);
        $next = ((int)$cnt->fetchColumn()) + 1 + $attempt;
        $short = sprintf('%s%04d', $prefix, $next);
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

    // Customer brief 2026-05-07 (item 9.1): exact in-app + SMS copy
    // requested by the customer — direct address with full punto info
    // (name, address, contact + phone). The previous marketing-style
    // line was replaced with the customer-facing short form.
    $sms = 'Tu punto Voltika autorizado para entregar tu moto es en {punto}, {direccion_punto}. Llegada estimada: {fecha_estimada}. Portal: voltika.mx/clientes/';

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

    // Customer brief 2026-05-07 (item 9.2): exact wording requested.
    $sms = 'Tu Voltika ya va en camino al punto de entrega ({punto}, {ciudad}) y estimamos llegue el {fecha_llegada_punto}.';

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

    // Customer brief 2026-05-07 (item 9.3): exact wording requested.
    $sms = 'Tu Voltika ya llegó al punto de entrega ({punto}) y está en preparación. En cuanto esté lista te avisamos.';

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

    // Customer brief 2026-05-07 (item 9.4): exact wording requested.
    $sms = 'Tu Voltika está Lista para que la recojas en el punto {punto} ({direccion_punto}). Lleva tu INE. Portal: voltika.mx/clientes/';

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
          // Customer brief 2026-05-07: OTP code was rendering as
          // invisible white-on-white in clients that strip linear
          // gradients (iOS Mail dark mode, Outlook). Use a solid
          // bgcolor + background-color so the navy background is
          // guaranteed; the gradient stays as progressive enhancement.
          // The number itself is also wrapped in a table cell with an
          // explicit bgcolor for maximum compatibility.
          . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:8px 0;border-collapse:separate;border-radius:10px;overflow:hidden;">'
          . '<tr><td bgcolor="#1a3a5c" align="center" style="background-color:#1a3a5c;background:linear-gradient(135deg,#1a3a5c,#039fe1);color:#ffffff;text-align:center;padding:24px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:38px;font-weight:800;letter-spacing:8px;border-radius:10px;">'
          . '<span style="color:#ffffff;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:38px;font-weight:800;letter-spacing:8px;">{otp}</span>'
          . '</td></tr></table>'
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
    // Customer brief 2026-05-07 (item 9.5): unified delivery-complete copy
    // — sent after acta firmada + OTP validado + Truora "same person" check.
    $sms = 'Voltika entregada. Tu {modelo} ya es oficialmente tuya. Descarga tu acta en voltika.mx/clientes/. Permiso: 30 días para placas.';
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

/**
 * Customer brief 2026-05-09 (first real credit sale): the weekly-payment
 * sentence renders broken when monto_semanal can't be resolved (empty
 * subscripcion lookup, abandoned setup, mismatched email/phone). Derive
 * intro paragraphs from the resolved amount so the credit templates can
 * drop them in via {pagos_semanales_intro} / {portal_pago_intro} /
 * {wa_pagos_intro} / {wa_portal_pago_intro} without ever leaking a stray
 * "$" symbol when the figure is unknown.
 *
 * Centralised here (instead of inside voltikaNotify()) so every consumer
 * of voltikaNotifyInterpolate() — including the admin preview tool at
 * /admin/php/preview-notificaciones.php which bypasses voltikaNotify() —
 * gets the same derived placeholders.
 */
function voltikaNotifyDeriveMontoIntros(array $data): array {
    $monto = isset($data['monto_semanal']) ? trim((string)$data['monto_semanal']) : '';
    $hasMonto = ($monto !== '' && $monto !== '0' && $monto !== '0.00');
    if (!isset($data['pagos_semanales_intro'])) {
        $data['pagos_semanales_intro'] = $hasMonto
            ? '<p style="font-size:13.5px;color:#333;line-height:1.6;margin:0 0 10px;">Tu primer pago semanal de <strong>$' . $monto . '</strong> inicia únicamente el día que recibas tu moto en mano.</p>'
            : '<p style="font-size:13.5px;color:#333;line-height:1.6;margin:0 0 10px;">Tu primer pago semanal inicia únicamente el día que recibas tu moto en mano. Te confirmamos el monto exacto en tu portal antes de la entrega.</p>';
    }
    if (!isset($data['portal_pago_intro'])) {
        $data['portal_pago_intro'] = $hasMonto
            ? '<p style="font-size:13.5px;color:#333;line-height:1.6;margin:0 0 8px;">Tu primer pago de <strong>$' . $monto . '</strong> inicia únicamente el día que recibas tu moto en mano.</p>'
            : '<p style="font-size:13.5px;color:#333;line-height:1.6;margin:0 0 8px;">Tu primer pago inicia únicamente el día que recibas tu moto en mano. Consulta el monto exacto en tu portal.</p>';
    }
    if (!isset($data['wa_pagos_intro'])) {
        $data['wa_pagos_intro'] = $hasMonto
            ? "Desde hoy puedes ver tu pedido,\ntus pagos y tus documentos.\n\n💳 Tu primer pago semanal de\n$" . $monto . " inicia el día\nque recibas tu moto.\nSin cargos antes de la entrega."
            : "Desde hoy puedes ver tu pedido,\ntus pagos y tus documentos.\n\n💳 Tu primer pago semanal inicia\nel día que recibas tu moto.\nConsulta el monto exacto en tu portal.\nSin cargos antes de la entrega.";
    }
    if (!isset($data['wa_portal_pago_intro'])) {
        $data['wa_portal_pago_intro'] = $hasMonto
            ? "⚠️ Tu primer pago semanal de\n$" . $monto . " inicia el día\nque recibas tu moto en mano.\nSin cargos antes de la entrega.\n\n"
            : "⚠️ Tu primer pago semanal inicia\nel día que recibas tu moto en mano.\nEl monto exacto se confirma\nen tu portal antes de la entrega.\nSin cargos antes de la entrega.\n\n";
    }
    return $data;
}

function voltikaNotifyInterpolate(string $tpl, array $data): string {
    $data = voltikaNotifyDeriveMontoIntros($data);
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
    // ── Round 47 (2026-05-16, Óscar — "SMS never arrives" for days):
    // SMSmasivos rejects "Authorization: Bearer …" + JSON body with HTTP 200
    // and body {"success":false,"status":401,"code":"auth_01"}. The OLD
    // code only checked HTTP code, so every failed SMS was logged as
    // ok=true — silent breakage for days. Switched to the auth scheme
    // SMSmasivos actually documents (apikey: header + form-urlencoded body
    // with numbers/country_code split), matching the working pattern in
    // clientes/php/bootstrap.php::portalSendSMS. Also: parse body.success.
    $tel = preg_replace('/\D/', '', $telefono);
    // Strip leading country prefix(es) so we send `numbers` as the bare
    // 10-digit national number; the API receives the country code separately.
    if (strlen($tel) === 12 && strpos($tel, '52') === 0)  $tel = substr($tel, 2);
    if (strlen($tel) === 11 && strpos($tel, '521') === 0) $tel = substr($tel, 3);
    $smsKey = defined('SMSMASIVOS_API_KEY') ? SMSMASIVOS_API_KEY : (getenv('SMSMASIVOS_API_KEY') ?: '');
    if (!$smsKey) return ['ok' => false, 'error' => 'SMS key missing'];

    $ch = curl_init('https://api.smsmasivos.com.mx/sms/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . $smsKey,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_POSTFIELDS     => http_build_query([
            'message'      => $mensaje,
            'numbers'      => $tel,
            'country_code' => '52',
        ]),
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $data    = is_string($res) ? json_decode($res, true) : null;
    $bodyOk  = is_array($data) && !empty($data['success']);
    $transOk = !$err && $code >= 200 && $code < 300;
    return [
        'ok'       => $transOk && $bodyOk,
        'http'     => $code,
        'error'    => $err ?: (!$bodyOk ? (is_array($data) ? ($data['message'] ?? 'gateway rechazó') : 'respuesta inválida') : null),
        'response' => $res,
        'parsed'   => $data,
    ];
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
    //
    // Bug 5.2 (customer brief 2026-05-08): OTP for delivery must reach the
    // customer's phone (SMS / WhatsApp), NOT email. Customers reported they
    // were receiving the code only by email and couldn't act on it at the
    // point of sale. We skip the email channel exclusively for the OTP
    // template — every other notification keeps its existing email path
    // untouched, so no other flow is affected.
    $emailSkipTipos = ['otp_entrega'];
    if (in_array($tipo, $emailSkipTipos, true)) {
        $summary['channels']['email'] = false;
        $summary['email_skipped_reason'] = 'otp_phone_only';
    } elseif (!empty($data['email']) && function_exists('sendMail')) {
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
