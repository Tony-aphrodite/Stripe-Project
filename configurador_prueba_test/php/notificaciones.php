<?php
/**
 * Voltika — Customer notifications module
 *
 * Centralizes customer-facing email + WhatsApp notifications triggered
 * by motorcycle status changes.
 *
 * Triggers (defined by client):
 *   1. Punto Voltika is assigned                — notifPuntoAsignado()
 *   2. Moto is in transit (CEDIS → punto)       — notifEnCamino()
 *   3. Moto is ready for pickup at the punto    — notifListaParaEntrega()
 *
 * In the current workflow, triggers 1 and 2 happen at the same moment
 * (the `enviar_a_punto` action), so notifEnCamino() handles both.
 * notifPuntoAsignado() exists for future use if a separate "assigned but
 * not yet shipped" stage is added.
 *
 * Each notification function:
 *   - Looks up moto + cliente + punto data
 *   - Checks the idempotency column (skip if already sent)
 *   - Sends the email
 *   - Sends the WhatsApp message (if provider is configured)
 *   - Marks the column with a timestamp
 *   - Logs the result
 *
 * Failure of a notification NEVER blocks the status transition.
 * All errors are logged but caller flow continues.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/centros-entrega-data.php';

// ─── Configuration ──────────────────────────────────────────────────────────

// Dry-run: when true, emails/WhatsApp are NOT actually sent — only logged.
// Use during initial testing in production. Switch to false to go live.
if (!defined('NOTIF_DRY_RUN')) define('NOTIF_DRY_RUN', false);

// Admin email to BCC on every customer notification (for audit/troubleshooting).
if (!defined('NOTIF_BCC_ADMIN')) define('NOTIF_BCC_ADMIN', 'redes@voltika.com.mx');

// Voltika branding
if (!defined('VOLTIKA_LOGO_URL'))    define('VOLTIKA_LOGO_URL',    'https://www.voltika.mx/configurador_prueba/img/voltika_logo_h_white.svg');
if (!defined('VOLTIKA_FOOTER_LOGO')) define('VOLTIKA_FOOTER_LOGO', 'https://www.voltika.mx/configurador_prueba/img/goelectric.svg');
if (!defined('VOLTIKA_SUPPORT_WA'))  define('VOLTIKA_SUPPORT_WA',  '+52 55 1341 6370');
if (!defined('VOLTIKA_SUPPORT_EMAIL')) define('VOLTIKA_SUPPORT_EMAIL', 'redes@voltika.mx');

// ─── Logging ────────────────────────────────────────────────────────────────

function notifLog(string $message): void {
    $logsDir = __DIR__ . '/logs';
    if (!is_dir($logsDir)) @mkdir($logsDir, 0755, true);
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($logsDir . '/notificaciones.log', $line, FILE_APPEND);
    error_log('Voltika notif: ' . $message);
}

// ─── Schema migration (idempotent) ──────────────────────────────────────────

/**
 * Ensure that the notification tracking columns exist on inventario_motos.
 * Safe to call repeatedly — silently ignores duplicate-column errors.
 */
function ensureNotifColumns(PDO $pdo): void {
    $columns = [
        "ALTER TABLE inventario_motos ADD COLUMN notif_envio_sent_at      DATETIME NULL",
        "ALTER TABLE inventario_motos ADD COLUMN notif_lista_sent_at      DATETIME NULL",
        "ALTER TABLE inventario_motos ADD COLUMN notif_envio_wa_sent_at   DATETIME NULL",
        "ALTER TABLE inventario_motos ADD COLUMN notif_lista_wa_sent_at   DATETIME NULL",
        "ALTER TABLE inventario_motos ADD COLUMN notif_asignado_sent_at   DATETIME NULL",
        "ALTER TABLE inventario_motos ADD COLUMN notif_asignado_wa_sent_at DATETIME NULL",
    ];
    foreach ($columns as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $ignored) {}
    }
}

// ─── Idempotency helpers ────────────────────────────────────────────────────

function notifAlreadySent(array $moto, string $columna): bool {
    return !empty($moto[$columna]);
}

function marcarNotifEnviada(PDO $pdo, int $motoId, string $columna): void {
    try {
        $pdo->prepare("UPDATE inventario_motos SET $columna = NOW() WHERE id = ?")
            ->execute([$motoId]);
    } catch (PDOException $e) {
        notifLog("Failed to mark $columna for moto #$motoId: " . $e->getMessage());
    }
}

// ─── Data lookup ────────────────────────────────────────────────────────────

/**
 * Load all data needed for a notification: moto + cliente (from pedidos) + punto.
 * Returns null if the moto cannot be found.
 *
 * Returned shape:
 * [
 *   'moto'    => [...inventario_motos row...],
 *   'pedido'  => [...pedidos row OR null...],   // monto, forma_pago come from here
 *   'punto'   => [...centros-entrega-data row OR null with link_maps + horario...],
 *   'nombre'  => 'Cliente Name',
 *   'email'   => 'cliente@...',
 *   'telefono'=> '+52...',
 *   'modelo', 'color', 'pedido_num',
 *   'monto_formateado', 'forma_pago_label',
 *   'fecha_estimada_fmt',
 * ]
 */
function obtenerDatosNotificacion(PDO $pdo, int $motoId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ? LIMIT 1");
    $stmt->execute([$motoId]);
    $moto = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$moto) return null;

    // Look up pedidos row to get monto + forma de pago
    $pedido = null;
    if (!empty($moto['pedido_num'])) {
        try {
            $pStmt = $pdo->prepare("SELECT pedido_num, nombre, email, telefono, modelo, color, ciudad, estado, total, metodo FROM pedidos WHERE pedido_num = ? ORDER BY freg DESC LIMIT 1");
            $pStmt->execute([$moto['pedido_num']]);
            $pedido = $pStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            notifLog("Could not fetch pedido for moto #$motoId: " . $e->getMessage());
        }
    }

    // Look up punto data (try by id first, then by name)
    $punto = null;
    if (!empty($moto['punto_id'])) {
        $punto = obtenerPuntoPorId($moto['punto_id']);
    }
    if (!$punto && !empty($moto['punto_nombre'])) {
        $punto = obtenerPuntoPorNombre($moto['punto_nombre']);
    }

    // Format helpers
    $total = floatval($pedido['total'] ?? 0);
    $montoFormateado = $total > 0
        ? '$' . number_format($total, 0, '.', ',') . ' MXN'
        : '';

    $metodo = strtolower($pedido['metodo'] ?? '');
    $formaPagoLabel = match (true) {
        str_contains($metodo, 'msi')      => 'Meses sin Intereses',
        str_contains($metodo, 'credito')  => 'Crédito Voltika',
        str_contains($metodo, 'crédito')  => 'Crédito Voltika',
        $metodo === 'contado' || $metodo === '' => 'Contado',
        default => ucfirst($metodo),
    };

    $fechaEst = $moto['fecha_estimada_llegada'] ?? '';
    $fechaEstFmt = $fechaEst ? date('d/m/Y', strtotime($fechaEst)) : 'Por confirmar';

    return [
        'moto'              => $moto,
        'pedido'            => $pedido,
        'punto'             => $punto,
        'nombre'            => $moto['cliente_nombre']   ?: ($pedido['nombre'] ?? 'Cliente'),
        'email'             => $moto['cliente_email']    ?: ($pedido['email']  ?? ''),
        'telefono'          => $moto['cliente_telefono'] ?: ($pedido['telefono'] ?? ''),
        'modelo'            => $moto['modelo'] ?? '',
        'color'             => $moto['color']  ?? '',
        'pedido_num'        => $moto['pedido_num'] ?? '',
        'monto_formateado'  => $montoFormateado,
        'forma_pago_label'  => $formaPagoLabel,
        'fecha_estimada_fmt'=> $fechaEstFmt,
    ];
}

// ─── Email shell (reusable HTML wrapper) ────────────────────────────────────

/**
 * Wraps a body HTML fragment in the Voltika branded email shell
 * (logo header gradient + footer). The body should be a sequence
 * of <div>/<p>/<table> elements — no <html>/<body> tags.
 *
 * @param string $titulo          Heading shown at the top of the body
 * @param string $headerSubtitle  Small text under the logo (e.g. "Tu moto está en camino")
 * @param string $bodyHtml        The unique inner content of the email
 * @param string $headerGradient  CSS gradient (default: Voltika blue)
 */
function emailShell(string $titulo, string $headerSubtitle, string $bodyHtml, string $headerGradient = 'linear-gradient(135deg,#1a3a5c,#039fe1)'): string {
    $logo        = VOLTIKA_LOGO_URL;
    $footerLogo  = VOLTIKA_FOOTER_LOGO;
    $waLink      = 'https://wa.me/' . preg_replace('/[^0-9]/', '', VOLTIKA_SUPPORT_WA);
    $waDisplay   = htmlspecialchars(VOLTIKA_SUPPORT_WA);
    $supportMail = htmlspecialchars(VOLTIKA_SUPPORT_EMAIL);
    $tituloEsc   = htmlspecialchars($titulo);
    $subtitleEsc = htmlspecialchars($headerSubtitle);

    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{$tituloEsc}</title></head>
<body style="margin:0;padding:0;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;">
<tr><td align="center" style="padding:24px 12px;">
<table width="620" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;max-width:620px;width:100%;box-shadow:0 2px 12px rgba(0,0,0,0.08);">

<!-- Header -->
<tr><td style="background:{$headerGradient};padding:28px;text-align:center;">
<img src="{$logo}" alt="Voltika" style="height:44px;width:auto;display:block;margin:0 auto;">
<p style="margin:10px 0 0;font-size:14px;color:rgba(255,255,255,0.95);font-weight:600;">{$subtitleEsc}</p>
</td></tr>

<!-- Body -->
<tr><td style="padding:28px;">
{$bodyHtml}

<!-- Soporte -->
<div style="margin:0 0 8px;padding:12px 0 6px;font-size:16px;font-weight:800;color:#1a3a5c;border-bottom:2px solid #039fe1;">SOPORTE Y ATENCIÓN</div>
<p style="font-size:14px;color:#555;margin:12px 0 4px;">Estamos contigo en todo momento.</p>
<p style="font-size:14px;margin:0 0 4px;">📱 WhatsApp: <a href="{$waLink}" style="color:#039fe1;font-weight:700;">{$waDisplay}</a></p>
<p style="font-size:14px;margin:0 0 24px;">📧 Correo: <a href="mailto:{$supportMail}" style="color:#039fe1;font-weight:700;">{$supportMail}</a></p>

</td></tr>

<!-- Footer -->
<tr><td style="background:#1a3a5c;padding:20px 28px;text-align:center;">
<img src="{$footerLogo}" alt="GO electric" style="height:28px;width:auto;margin-bottom:8px;">
<p style="margin:0 0 4px;font-size:14px;font-weight:700;color:#fff;">Voltika México</p>
<p style="margin:0;font-size:12px;color:rgba(255,255,255,0.6);">Movilidad eléctrica inteligente · Mtech Gears, S.A. de C.V.</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
HTML;
}

/**
 * Renders a section header bar in the Voltika style.
 */
function emailSectionHeader(string $title): string {
    $t = htmlspecialchars($title);
    return '<div style="margin:18px 0 8px;padding:12px 0 6px;font-size:16px;font-weight:800;color:#1a3a5c;border-bottom:2px solid #039fe1;">' . $t . '</div>';
}

/**
 * Renders a key/value table row pair (alternating background).
 */
function emailKVTable(array $rows): string {
    $tdl = 'style="padding:10px 16px;border-bottom:1px solid #E5E7EB;font-size:14px;color:#6B7280;"';
    $td  = 'style="padding:10px 16px;border-bottom:1px solid #E5E7EB;font-size:14px;"';
    $html = '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:18px;border:1px solid #E5E7EB;border-radius:8px;overflow:hidden;">';
    $i = 0;
    foreach ($rows as $label => $value) {
        if ($value === '' || $value === null) continue;
        $bg = ($i % 2 === 0) ? '' : ' style="background:#F9FAFB;"';
        $html .= "<tr$bg><td $tdl>" . htmlspecialchars($label) . "</td><td $td>" . $value . "</td></tr>";
        $i++;
    }
    $html .= '</table>';
    return $html;
}

// ─── WhatsApp stub (Phase 4 — provider not yet configured) ──────────────────

/**
 * Send a WhatsApp template message.
 *
 * Until the provider is configured, this is a no-op that returns false
 * and logs the intended payload. Once Phase 4 is implemented (see whatsapp-api.php),
 * this function will be replaced with the actual provider call.
 *
 * @param string $telefono       E.164 format (+52...)
 * @param string $templateName   Pre-approved Meta template name
 * @param array  $variables      Ordered list of {{1}}, {{2}}, ... values
 */
function enviarWhatsApp(string $telefono, string $templateName, array $variables): bool {
    if (function_exists('enviarWhatsAppReal')) {
        return enviarWhatsAppReal($telefono, $templateName, $variables);
    }
    notifLog("WhatsApp PENDING (provider not configured): $templateName → $telefono | vars=" . json_encode($variables, JSON_UNESCAPED_UNICODE));
    return false;
}

/**
 * Normalize a Mexican phone number to E.164 format (+52...).
 * Returns empty string if the input is unusable.
 */
function normalizarTelefonoMx(string $tel): string {
    $digits = preg_replace('/[^0-9]/', '', $tel);
    if ($digits === '' || strlen($digits) < 10) return '';
    if (strlen($digits) === 10)        return '+52' . $digits;
    if (str_starts_with($digits, '52')) return '+' . $digits;
    if (str_starts_with($digits, '152')) return '+' . substr($digits, 1);
    return '+' . $digits;
}

// ─── Trigger 1: Punto Voltika has been assigned ─────────────────────────────
//
// Currently NOT wired up — triggers 1 and 2 happen in the same action
// (`enviar_a_punto`) so notifEnCamino() is used. This function is kept
// for future use if a separate "assigned but not yet shipped" stage is added.

function notifPuntoAsignado(int $motoId): bool {
    try {
        $pdo = getDB();
        ensureNotifColumns($pdo);

        $datos = obtenerDatosNotificacion($pdo, $motoId);
        if (!$datos) {
            notifLog("notifPuntoAsignado: moto #$motoId not found");
            return false;
        }
        if (notifAlreadySent($datos['moto'], 'notif_asignado_sent_at')) {
            notifLog("notifPuntoAsignado: skipping moto #$motoId — already sent");
            return false;
        }
        if (empty($datos['email'])) {
            notifLog("notifPuntoAsignado: no email for moto #$motoId");
            return false;
        }

        $puntoNombre   = $datos['punto']['nombre']             ?? ($datos['moto']['punto_nombre'] ?? '');
        $puntoDirec    = $datos['punto']['direccion_completa'] ?? '';
        $linkMaps      = $datos['punto']['link_maps']          ?? '';

        $resumen = emailSectionHeader('🧾 RESUMEN DE TU COMPRA') . emailKVTable([
            'Modelo'         => htmlspecialchars($datos['modelo']),
            'Color'          => htmlspecialchars($datos['color']),
            'Número de pedido' => '<strong>#' . htmlspecialchars($datos['pedido_num']) . '</strong>',
            'Forma de pago'  => htmlspecialchars($datos['forma_pago_label']),
            'Monto pagado'   => $datos['monto_formateado'] ? '<strong style="color:#039fe1;">' . $datos['monto_formateado'] . '</strong>' : '',
        ]);

        $puntoSection = emailSectionHeader('📍 PUNTO DE ENTREGA') . emailKVTable([
            'Punto'    => '<strong>' . htmlspecialchars($puntoNombre) . '</strong>',
            'Dirección'=> htmlspecialchars($puntoDirec),
            'Ubicación'=> $linkMaps ? '<a href="' . htmlspecialchars($linkMaps) . '" style="color:#039fe1;font-weight:700;">👉 Ver en Google Maps</a>' : '',
        ]);

        $entregaSegura = emailSectionHeader('🔐 ENTREGA SEGURA') .
            '<div style="background:#FFF8E1;border-radius:8px;padding:16px;margin:12px 0 18px;border-left:4px solid #FFB300;">' .
            '<p style="margin:0 0 8px;font-size:14px;color:#333;font-weight:700;">Para recibir tu Voltika será necesario:</p>' .
            '<ul style="margin:0;padding-left:20px;font-size:13px;color:#555;line-height:1.8;">' .
            '<li>Presentar tu INE</li>' .
            '<li>Acudir con el mismo número telefónico con el que realizaste tu compra</li>' .
            '<li>Validar un código de seguridad al momento</li>' .
            '</ul>' .
            '<p style="margin:8px 0 0;font-size:12px;color:#666;">💡 La entrega se realiza únicamente al titular de la compra o persona autorizada previamente.</p>' .
            '</div>';

        $body = '<h2 style="margin:0 0 8px;font-size:20px;color:#1a3a5c;">Hola, ' . htmlspecialchars($datos['nombre']) . ' 👋</h2>' .
                '<p style="margin:0 0 20px;font-size:14px;color:#555;line-height:1.7;">Tu Voltika ya cuenta con un punto de entrega asignado en tu ciudad y está en proceso para ti.</p>' .
                $resumen . $puntoSection .
                '<p style="margin:14px 0 18px;font-size:14px;color:#555;line-height:1.7;">Tu unidad está avanzando en su proceso de entrega hacia este punto. En breve recibirás la fecha estimada de llegada y disponibilidad para recogerla.</p>' .
                $entregaSegura .
                '<p style="margin:18px 0 0;font-size:14px;color:#555;line-height:1.7;">Gracias por confiar en Voltika ⚡<br>Tu nueva forma de moverte ya está en camino.</p>' .
                '<p style="margin:8px 0 0;font-size:14px;color:#1a3a5c;font-weight:700;">Equipo Voltika</p>';

        $html = emailShell(
            'Tu Voltika ya tiene punto de entrega',
            '📍 Punto de entrega asignado',
            $body
        );

        $subject = 'Tu Voltika ya tiene punto de entrega — Orden #' . $datos['pedido_num'];

        $okEmail = enviarEmailSeguro($datos['email'], $datos['nombre'], $subject, $html, "asignado moto #$motoId");

        // WhatsApp (Phase 4 — currently a no-op stub)
        $telE164 = normalizarTelefonoMx($datos['telefono']);
        $okWa = false;
        if ($telE164) {
            $okWa = enviarWhatsApp($telE164, 'voltika_punto_asignado', [
                $datos['nombre'],
                $puntoNombre,
                $linkMaps ?: '',
                $datos['modelo'] . ' – ' . $datos['color'],
                $datos['pedido_num'],
            ]);
        }

        if ($okEmail) marcarNotifEnviada($pdo, $motoId, 'notif_asignado_sent_at');
        if ($okWa)    marcarNotifEnviada($pdo, $motoId, 'notif_asignado_wa_sent_at');

        return $okEmail;
    } catch (\Throwable $e) {
        notifLog("notifPuntoAsignado FAILED for moto #$motoId: " . $e->getMessage());
        return false;
    }
}

// ─── Trigger 1+2 combined: Punto assigned AND moto in transit ───────────────
//
// This is what fires when CEDIS clicks "enviar a punto". The action both
// assigns the punto and starts the shipment, so we send a single email
// that combines both messages (using customer's "in transit" text since
// it includes the FECHA_ESTIMADA).

function notifEnCamino(int $motoId): bool {
    try {
        $pdo = getDB();
        ensureNotifColumns($pdo);

        $datos = obtenerDatosNotificacion($pdo, $motoId);
        if (!$datos) {
            notifLog("notifEnCamino: moto #$motoId not found");
            return false;
        }
        if (notifAlreadySent($datos['moto'], 'notif_envio_sent_at')) {
            notifLog("notifEnCamino: skipping moto #$motoId — already sent");
            return false;
        }
        if (empty($datos['email'])) {
            notifLog("notifEnCamino: no email for moto #$motoId");
            return false;
        }

        $puntoNombre = $datos['punto']['nombre']             ?? ($datos['moto']['punto_nombre'] ?? '');
        $puntoDirec  = $datos['punto']['direccion_completa'] ?? '';
        $linkMaps    = $datos['punto']['link_maps']          ?? '';
        $tracking    = $datos['moto']['envia_tracking_number'] ?? '';
        $trackingUrl = $datos['moto']['envia_tracking_url']    ?? '';

        $resumen = emailSectionHeader('🧾 RESUMEN DE TU COMPRA') . emailKVTable([
            'Modelo' => htmlspecialchars($datos['modelo']),
            'Color'  => htmlspecialchars($datos['color']),
            'Pedido' => '<strong>#' . htmlspecialchars($datos['pedido_num']) . '</strong>',
        ]);

        $destinoRows = [
            'Punto'    => '<strong>' . htmlspecialchars($puntoNombre) . '</strong>',
            'Dirección'=> htmlspecialchars($puntoDirec),
            'Ubicación'=> $linkMaps ? '<a href="' . htmlspecialchars($linkMaps) . '" style="color:#039fe1;font-weight:700;">👉 Ver en Google Maps</a>' : '',
            '🚚 Entrega estimada en punto' => '<strong style="color:#039fe1;">' . htmlspecialchars($datos['fecha_estimada_fmt']) . '</strong>',
        ];
        if ($tracking) {
            $destinoRows['No. de rastreo'] = $trackingUrl
                ? '<a href="' . htmlspecialchars($trackingUrl) . '" style="color:#039fe1;font-weight:700;">' . htmlspecialchars($tracking) . '</a>'
                : htmlspecialchars($tracking);
        }
        $destinoSection = emailSectionHeader('📍 DESTINO') . emailKVTable($destinoRows);

        $entregaSegura = emailSectionHeader('🔐 RECUERDA PARA RECOGERLA') .
            '<div style="background:#FFF8E1;border-radius:8px;padding:16px;margin:12px 0 18px;border-left:4px solid #FFB300;">' .
            '<p style="margin:0;font-size:14px;color:#333;line-height:1.7;">' .
            'INE + el mismo número telefónico de tu compra + código de seguridad que se enviará al momento de recoger.' .
            '</p></div>';

        $body = '<h2 style="margin:0 0 8px;font-size:20px;color:#1a3a5c;">Hola, ' . htmlspecialchars($datos['nombre']) . ' 👋</h2>' .
                '<p style="margin:0 0 20px;font-size:14px;color:#555;line-height:1.7;">Tu Voltika ya está en tránsito hacia tu punto de entrega.</p>' .
                $resumen . $destinoSection .
                '<p style="margin:14px 0 8px;font-size:14px;color:#555;line-height:1.7;">Tu unidad ya está en ruta y avanzando hacia tu ciudad.</p>' .
                '<p style="margin:0 0 18px;font-size:14px;color:#555;line-height:1.7;">📩 Recibirás la confirmación de llegada en cuanto esté lista para entrega.</p>' .
                $entregaSegura .
                '<p style="margin:18px 0 0;font-size:14px;color:#555;line-height:1.7;">Gracias por confiar en Voltika.<br>Tu moto ya está cada vez más cerca.</p>';

        $html = emailShell(
            'Tu Voltika está en camino',
            '🚚 Tu moto está en tránsito',
            $body
        );

        $subject = 'Tu Voltika está en camino — Orden #' . $datos['pedido_num'];

        $okEmail = enviarEmailSeguro($datos['email'], $datos['nombre'], $subject, $html, "en_camino moto #$motoId");

        // WhatsApp (Phase 4 — currently a no-op stub)
        $telE164 = normalizarTelefonoMx($datos['telefono']);
        $okWa = false;
        if ($telE164) {
            $okWa = enviarWhatsApp($telE164, 'voltika_en_camino', [
                $datos['nombre'],
                $puntoNombre,
                $datos['fecha_estimada_fmt'],
                $datos['modelo'] . ' – ' . $datos['color'],
            ]);
        }

        if ($okEmail) marcarNotifEnviada($pdo, $motoId, 'notif_envio_sent_at');
        if ($okWa)    marcarNotifEnviada($pdo, $motoId, 'notif_envio_wa_sent_at');

        return $okEmail;
    } catch (\Throwable $e) {
        notifLog("notifEnCamino FAILED for moto #$motoId: " . $e->getMessage());
        return false;
    }
}

// ─── Trigger 3: Moto is ready for pickup at the punto ───────────────────────

function notifListaParaEntrega(int $motoId): bool {
    try {
        $pdo = getDB();
        ensureNotifColumns($pdo);

        $datos = obtenerDatosNotificacion($pdo, $motoId);
        if (!$datos) {
            notifLog("notifListaParaEntrega: moto #$motoId not found");
            return false;
        }
        if (notifAlreadySent($datos['moto'], 'notif_lista_sent_at')) {
            notifLog("notifListaParaEntrega: skipping moto #$motoId — already sent");
            return false;
        }
        if (empty($datos['email'])) {
            notifLog("notifListaParaEntrega: no email for moto #$motoId");
            return false;
        }

        $puntoNombre = $datos['punto']['nombre']             ?? ($datos['moto']['punto_nombre'] ?? '');
        $puntoDirec  = $datos['punto']['direccion_completa'] ?? '';
        $horario     = $datos['punto']['horarios']           ?? 'Consultar con el punto';
        $puntoTel    = $datos['punto']['telefono']           ?? '';
        $linkMaps    = $datos['punto']['link_maps']          ?? '';

        $resumen = emailSectionHeader('🧾 RESUMEN DE TU COMPRA') . emailKVTable([
            'Modelo' => htmlspecialchars($datos['modelo']),
            'Color'  => htmlspecialchars($datos['color']),
            'Pedido' => '<strong>#' . htmlspecialchars($datos['pedido_num']) . '</strong>',
        ]);

        $puntoRows = [
            'Punto'     => '<strong>' . htmlspecialchars($puntoNombre) . '</strong>',
            'Dirección' => htmlspecialchars($puntoDirec),
            '🕒 Horario'=> htmlspecialchars($horario),
        ];
        if ($puntoTel) {
            $puntoRows['📞 Teléfono'] = htmlspecialchars($puntoTel);
        }
        if ($linkMaps) {
            $puntoRows['Ubicación'] = '<a href="' . htmlspecialchars($linkMaps) . '" style="color:#039fe1;font-weight:700;">👉 Ver en Google Maps</a>';
        }
        $puntoSection = emailSectionHeader('📍 PUNTO DE ENTREGA') . emailKVTable($puntoRows);

        $entregaSegura = emailSectionHeader('🔐 ENTREGA RÁPIDA Y SEGURA') .
            '<div style="background:#FFF8E1;border-radius:8px;padding:16px;margin:12px 0 18px;border-left:4px solid #FFB300;">' .
            '<p style="margin:0 0 8px;font-size:14px;color:#333;font-weight:700;">Para recibir tu Voltika solo necesitas:</p>' .
            '<ul style="margin:0;padding-left:20px;font-size:13px;color:#555;line-height:1.8;">' .
            '<li>Tu INE</li>' .
            '<li>El mismo número telefónico con el que realizaste tu compra</li>' .
            '<li>Validar un código de seguridad al momento</li>' .
            '</ul></div>';

        $body = '<h2 style="margin:0 0 8px;font-size:20px;color:#059669;">Hola, ' . htmlspecialchars($datos['nombre']) . ' 👋</h2>' .
                '<p style="margin:0 0 20px;font-size:15px;color:#333;line-height:1.7;"><strong>Tu Voltika ya está lista y disponible para entrega.</strong></p>' .
                $resumen . $puntoSection .
                '<p style="margin:14px 0 8px;font-size:14px;color:#555;line-height:1.7;">Tu motocicleta ya está completamente preparada y lista para rodar. Puedes pasar por ella dentro del horario indicado.</p>' .
                $entregaSegura .
                '<div style="background:#d1fae5;border-radius:8px;padding:14px;margin:12px 0 18px;text-align:center;border-left:4px solid #059669;">' .
                '<p style="margin:0;font-size:13px;color:#065f46;font-weight:700;">⏱ Te recomendamos recogerla lo antes posible para agilizar tu entrega.</p>' .
                '</div>' .
                '<p style="margin:18px 0 0;font-size:14px;color:#555;line-height:1.7;">Gracias por confiar en Voltika.<br>Tu nueva forma de moverte ya está lista para ti.</p>' .
                '<p style="margin:8px 0 0;font-size:14px;color:#1a3a5c;font-weight:700;">Equipo Voltika</p>';

        $html = emailShell(
            'Tu Voltika está lista para entrega',
            '✅ ¡Tu moto está lista para recoger!',
            $body,
            'linear-gradient(135deg,#059669,#10b981)'
        );

        $subject = '✅ Tu Voltika está lista para entrega — Orden #' . $datos['pedido_num'];

        $okEmail = enviarEmailSeguro($datos['email'], $datos['nombre'], $subject, $html, "lista moto #$motoId");

        // WhatsApp (Phase 4 — currently a no-op stub)
        $telE164 = normalizarTelefonoMx($datos['telefono']);
        $okWa = false;
        if ($telE164) {
            $okWa = enviarWhatsApp($telE164, 'voltika_lista_entrega', [
                $datos['nombre'],
                $puntoNombre,
                $horario,
                $linkMaps ?: '',
                $datos['modelo'] . ' – ' . $datos['color'],
            ]);
        }

        if ($okEmail) marcarNotifEnviada($pdo, $motoId, 'notif_lista_sent_at');
        if ($okWa)    marcarNotifEnviada($pdo, $motoId, 'notif_lista_wa_sent_at');

        return $okEmail;
    } catch (\Throwable $e) {
        notifLog("notifListaParaEntrega FAILED for moto #$motoId: " . $e->getMessage());
        return false;
    }
}

// ─── Safe email send wrapper (handles dry-run + logging) ────────────────────

// ─── WhatsApp Real Implementation (Meta Cloud API + SMSMasivos fallback) ────

/**
 * Send a WhatsApp template message via Meta Cloud API.
 * Falls back to SMSMasivos WhatsApp endpoint if Meta credentials are missing
 * but SMSMasivos is configured.
 *
 * @param string $telefono       E.164 format (+52...)
 * @param string $templateName   Pre-approved Meta template name
 * @param array  $variables      Ordered list of {{1}}, {{2}}, ... values
 * @return bool
 */
function enviarWhatsAppReal(string $telefono, string $templateName, array $variables): bool {
    if (NOTIF_DRY_RUN) {
        notifLog("DRY-RUN WhatsApp skipped: $templateName → $telefono | vars=" . json_encode($variables, JSON_UNESCAPED_UNICODE));
        return true;
    }

    // ── Primary: Meta Cloud API ────────────────────────────────────────────
    if (defined('WHATSAPP_API_TOKEN') && WHATSAPP_API_TOKEN !== '') {
        if (!defined('WHATSAPP_PHONE_ID') || WHATSAPP_PHONE_ID === '') {
            notifLog("WhatsApp ERROR: WHATSAPP_API_TOKEN is set but WHATSAPP_PHONE_ID is missing");
            return false;
        }

        $url = 'https://graph.facebook.com/v18.0/' . WHATSAPP_PHONE_ID . '/messages';

        // Build template components — body parameters from $variables
        $bodyParams = [];
        foreach ($variables as $val) {
            $bodyParams[] = [
                'type' => 'text',
                'text' => (string)$val,
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => preg_replace('/[^0-9]/', '', $telefono),
            'type'              => 'template',
            'template'          => [
                'name'     => $templateName,
                'language' => ['code' => 'es_MX'],
                'components' => [
                    [
                        'type'       => 'body',
                        'parameters' => $bodyParams,
                    ],
                ],
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . WHATSAPP_API_TOKEN,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            notifLog("WhatsApp META CURL ERROR: $curlErr | template=$templateName tel=$telefono");
            return false;
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300 && !empty($decoded['messages'][0]['id'])) {
            $msgId = $decoded['messages'][0]['id'];
            notifLog("WhatsApp META OK: template=$templateName tel=$telefono msgId=$msgId");
            return true;
        }

        $errMsg = $decoded['error']['message'] ?? ($response ?: "HTTP $httpCode");
        notifLog("WhatsApp META FAIL (HTTP $httpCode): $errMsg | template=$templateName tel=$telefono");
        return false;
    }

    // ── Fallback: SMSMasivos WhatsApp API ──────────────────────────────────
    if (defined('SMSMASIVOS_API_KEY') && SMSMASIVOS_API_KEY !== '') {
        $url = 'https://api.smsmasivos.com.mx/whatsapp/send';

        // Build a readable text message from template variables
        $lineas = [];
        foreach ($variables as $i => $val) {
            $lineas[] = (string)$val;
        }
        $mensaje = "[$templateName] " . implode(' | ', $lineas);

        $postData = [
            'apikey'  => SMSMASIVOS_API_KEY,
            'numero'  => preg_replace('/[^0-9]/', '', $telefono),
            'mensaje' => $mensaje,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($postData, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            notifLog("WhatsApp SMSMASIVOS CURL ERROR: $curlErr | template=$templateName tel=$telefono");
            return false;
        }

        $decoded = json_decode($response, true);
        $success = ($httpCode >= 200 && $httpCode < 300) && (($decoded['status'] ?? '') === 'ok' || ($decoded['success'] ?? false));

        if ($success) {
            notifLog("WhatsApp SMSMASIVOS OK: template=$templateName tel=$telefono");
            return true;
        }

        $errMsg = $decoded['message'] ?? $decoded['error'] ?? ($response ?: "HTTP $httpCode");
        notifLog("WhatsApp SMSMASIVOS FAIL (HTTP $httpCode): $errMsg | template=$templateName tel=$telefono");
        return false;
    }

    // ── No provider configured ─────────────────────────────────────────────
    notifLog("WhatsApp SKIPPED (no provider configured): template=$templateName tel=$telefono");
    return false;
}

// ─── Safe email send wrapper (handles dry-run + logging) ────────────────────

function enviarEmailSeguro(string $to, string $toName, string $subject, string $html, string $contexto): bool {
    if (NOTIF_DRY_RUN) {
        notifLog("DRY-RUN email skipped [$contexto] → $to | subject: $subject");
        return true;
    }
    $sent = sendMail($to, $toName, $subject, $html);
    if ($sent) {
        notifLog("Email sent [$contexto] → $to | subject: $subject");
    } else {
        notifLog("Email FAILED [$contexto] → $to | subject: $subject");
    }
    return $sent;
}
