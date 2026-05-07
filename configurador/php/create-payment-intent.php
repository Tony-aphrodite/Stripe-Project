<?php
/**
 * Voltika Configurador - Crear PaymentIntent en Stripe
 * Recibe JSON POST, crea PaymentIntent, devuelve clientSecret
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Central config ───────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';

/**
 * Insert (or upsert) a "pendiente" transaccion row when the PaymentIntent is
 * created, before the customer has actually completed payment.
 *
 * Customer brief 2026-04-30: a credit applicant that finished CDC + Truora
 * approval and reached the enganche screen but never paid (declined card,
 * abandoned tab, etc.) was completely invisible — no DB row, no email, no
 * admin queue. They want to be able to follow up on these high-quality
 * leads. We now insert the row at PI-creation time and let
 * confirmar-orden.php promote it to 'pagada' after a successful charge.
 *
 * Idempotent: if a row for this stripe_pi already exists, this is a no-op
 * (UNIQUE uniq_stripe_pi catches it). Failure is logged but never blocks
 * payment-intent creation — payments must still flow even if our tracking
 * fails.
 */
function _voltikaInsertPendingTransaccion(string $stripePi, array $customer, int $amountCents, string $method, int $msiMeses): void {
    if ($stripePi === '') return;
    try {
        $pdo = getDB();

        // Make sure the UNIQUE index on stripe_pi exists (confirmar-orden.php
        // also creates it; harmless duplicate-call-safe).
        try { $pdo->exec("ALTER TABLE transacciones ADD UNIQUE INDEX uniq_stripe_pi (stripe_pi)"); }
        catch (Throwable $e) {}

        $email    = trim((string)($customer['email']    ?? ''));
        $telefono = trim((string)($customer['telefono'] ?? ''));
        $nombre   = trim(($customer['nombre'] ?? '') . ' ' . ($customer['apellidos'] ?? ''));
        $tpago    = $customer['tpago']
                  ?? ($msiMeses > 0 ? 'msi' : ($method === 'oxxo' ? 'oxxo' : ($method === 'spei' ? 'spei' : 'contado')));
        $total    = $amountCents / 100;

        // ── Customer-level dedup (customer brief 2026-05-01) ──────────────
        // The same applicant may switch payment methods (card → OXXO/SPEI)
        // or trigger several create-payment-intent calls (e.g. OXXO is
        // split into multiple references). Each iteration must NOT create
        // a separate dashboard row. We look for an existing 'pendiente'
        // row matched by email or phone within the last 24h and UPDATE it
        // with the latest PI / method / amount instead of inserting a new
        // duplicate. Result: 1 row per applicant per session, no matter
        // how many PIs Stripe creates under the hood.
        $existingId = null;
        if ($email !== '' || $telefono !== '') {
            $where  = [];
            $params = [];
            if ($email !== '')    { $where[] = "email = ?";    $params[] = $email; }
            if ($telefono !== '') { $where[] = "telefono = ?"; $params[] = $telefono; }
            $sql = "SELECT id FROM transacciones
                    WHERE pago_estado = 'pendiente'
                      AND freg >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                      AND (" . implode(' OR ', $where) . ")
                    ORDER BY id DESC LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $existingId = $stmt->fetchColumn() ?: null;
        }

        if ($existingId) {
            // Same applicant — refresh the existing pending row with the
            // current PI / method / amount. Preserve any non-empty fields
            // already on the row (defensive against blank customer payloads
            // on subsequent calls). Touch freg too so the dashboard moves
            // the entry to the top after every payment-method change.
            $upd = $pdo->prepare("UPDATE transacciones SET
                    stripe_pi   = ?,
                    tpago       = ?,
                    precio      = ?,
                    total       = ?,
                    msi_meses   = ?,
                    nombre      = COALESCE(NULLIF(nombre,''), ?),
                    modelo      = COALESCE(NULLIF(modelo,''), ?),
                    color       = COALESCE(NULLIF(color,''),  ?),
                    ciudad      = COALESCE(NULLIF(ciudad,''), ?),
                    estado      = COALESCE(NULLIF(estado,''), ?),
                    cp          = COALESCE(NULLIF(cp,''),     ?),
                    environment = ?,
                    freg        = COALESCE(freg, NOW())
                WHERE id = ?");
            $upd->execute([
                $stripePi,
                $tpago,
                $total,
                $total,
                $msiMeses,
                $nombre,
                $customer['modelo']   ?? '',
                $customer['color']    ?? '',
                $customer['ciudad']   ?? '',
                $customer['estado']   ?? '',
                $customer['cp']       ?? '',
                defined('APP_ENV') ? APP_ENV : 'test',
                (int)$existingId,
            ]);
            return;
        }

        // No existing pending row — INSERT IGNORE for stripe_pi safety.
        // freg is set explicitly so admin dashboard ORDER BY freg DESC
        // returns rows in chronological order even on legacy schemas
        // where the column may not have DEFAULT CURRENT_TIMESTAMP.
        $sql = "INSERT IGNORE INTO transacciones
                  (nombre, email, telefono, modelo, color, ciudad, estado, cp,
                   tpago, precio, total, freg, stripe_pi, msi_meses, pago_estado, environment)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, 'pendiente', ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $nombre,
            $email,
            $telefono,
            $customer['modelo']   ?? '',
            $customer['color']    ?? '',
            $customer['ciudad']   ?? '',
            $customer['estado']   ?? '',
            $customer['cp']       ?? '',
            $tpago,
            $total,
            $total,
            $stripePi,
            $msiMeses,
            defined('APP_ENV') ? APP_ENV : 'test',
        ]);
    } catch (Throwable $e) {
        // Tracking failure must NEVER block the Stripe flow.
        error_log('voltika pending-transaccion insert: ' . $e->getMessage());
    }
}

/**
 * Recovery email for card / unattempted payments. OXXO/SPEI already have
 * their dedicated _sendReminderEmail() with bank details; this generic
 * variant tells the user "your Voltika is one step away — complete the
 * down payment" and provides a CTA back to the configurator. Sent at
 * PI-creation time so the customer has a written record of their
 * almost-purchase even if they close the tab seconds later.
 */
function _voltikaSendIncompletePaymentEmail(string $email, string $nombre, array $customer, int $amountCents, string $purchaseTipo = 'contado', int $msiMeses = 0): void {
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;

    $modelo  = htmlspecialchars($customer['modelo'] ?? 'tu Voltika');
    $color   = htmlspecialchars($customer['color']  ?? '');
    $monto   = '$' . number_format($amountCents / 100, 0, '.', ',') . ' MXN';
    $name    = htmlspecialchars($nombre ?: 'Cliente Voltika');
    $support = '+52 55 1341 6370';

    // ── Email content by purchase type (customer brief 2026-05-01) ──────
    // The same recovery email was being sent for credit-enganche, contado
    // and MSI buyers — that produced the embarrassing "tu solicitud de
    // crédito fue aprobada / falta el enganche" copy on a 9-MSI card
    // purchase, where there is no enganche and no credit application.
    // Branch on the actual purchase type so each customer sees correct
    // language.
    $tipo = strtolower(trim($purchaseTipo));
    $isCredito = ($tipo === 'enganche' || $tipo === 'credito');
    $isMsi     = ($tipo === 'msi') || ($msiMeses > 0 && !$isCredito);

    if ($isCredito) {
        $subject     = 'Tu Voltika está casi lista — solo falta el enganche';
        $headline    = 'Tu solicitud de crédito fue <strong>aprobada</strong> y solo falta un paso: <strong>el enganche</strong>.';
        $amountLabel = 'Enganche pendiente';
        $amountValue = $monto;
        $extraLine   = '';
    } elseif ($isMsi) {
        $subject     = 'Tu Voltika está casi lista — completa tu compra a 9 MSI';
        $msiTxt      = $msiMeses > 0 ? ($msiMeses . ' meses sin intereses') : '9 meses sin intereses';
        $headline    = 'Tu compra a <strong>' . htmlspecialchars($msiTxt) . '</strong> quedó pendiente. Para apartar tu Voltika solo falta completar el pago con tu tarjeta.';
        $amountLabel = 'Total a pagar (a ' . htmlspecialchars($msiTxt) . ')';
        $amountValue = $monto;
        $extraLine   = '<div style="font-size:12px;color:#666;margin-top:6px;">Tu banco emisor dividirá este monto en pagos mensuales sin intereses.</div>';
    } else {
        // Contado (pago único)
        $subject     = 'Tu Voltika está casi lista — completa tu pago';
        $headline    = 'Tu compra está casi lista — solo falta <strong>completar el pago</strong> de tu Voltika.';
        $amountLabel = 'Total a pagar';
        $amountValue = $monto;
        $extraLine   = '';
    }

    $html =
        '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:560px;margin:auto;padding:24px;background:#fff;">' .
        '<div style="text-align:center;background:#1a3a5c;padding:18px;border-radius:12px 12px 0 0;">' .
        '<img src="https://www.voltika.mx/configurador/img/logo_w.png" alt="Voltika" width="102" style="height:34px;width:auto;border:0;outline:0;">' .
        '</div>' .
        '<div style="background:#F8FAFC;padding:24px;border-radius:0 0 12px 12px;border:1px solid #E5E7EB;border-top:none;">' .
        '<h1 style="font-size:22px;color:#1a3a5c;margin:0 0 12px;">Hola ' . $name . ',</h1>' .
        '<p style="font-size:15px;color:#333;line-height:1.5;margin:0 0 14px;">' . $headline . '</p>' .
        '<div style="background:#fff;border-radius:10px;padding:16px;margin:12px 0;border:1px solid #E5E7EB;">' .
            '<div style="font-size:13px;color:#888;margin-bottom:4px;">Tu Voltika</div>' .
            '<div style="font-size:18px;font-weight:800;color:#333;margin-bottom:8px;">' . $modelo . ($color ? ' · ' . $color : '') . '</div>' .
            '<div style="font-size:13px;color:#888;margin-bottom:4px;">' . $amountLabel . '</div>' .
            '<div style="font-size:24px;font-weight:900;color:#039fe1;">' . $amountValue . '</div>' .
            $extraLine .
        '</div>' .
        '<a href="https://www.voltika.mx/configurador/" style="display:block;text-align:center;padding:14px;background:#039fe1;color:#fff;border-radius:10px;font-size:15px;font-weight:800;text-decoration:none;margin:16px 0;">Completar mi pago</a>' .
        '<p style="font-size:13px;color:#666;line-height:1.5;margin:16px 0 0;">' .
            '¿Tienes alguna pregunta? Llámanos o escríbenos por WhatsApp al ' .
            '<a href="https://wa.me/525513416370" style="color:#039fe1;text-decoration:none;font-weight:700;">' . $support . '</a>.' .
        '</p>' .
        '<p style="font-size:11px;color:#999;margin:16px 0 0;text-align:center;">' .
            'Si ya completaste tu pago, ignora este mensaje.' .
        '</p>' .
        '</div>' .
        '</div>';

    // Prefer the centralized PHPMailer-based sendMail() when available
    // (config.php). Fall back to plain mail() if not loaded for any reason.
    if (function_exists('sendMail')) {
        try { @sendMail($email, $nombre, $subject, $html); } catch (Throwable $e) {
            error_log('voltika incomplete-payment email: ' . $e->getMessage());
        }
    } else {
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: Voltika <noreply@voltika.mx>\r\n";
        $headers .= "Reply-To: ventas@voltika.mx\r\n";
        @mail($email, $subject, $html, $headers);
    }
}

// ── Reminder email for OXXO/SPEI ─────────────────────────────────────────────
function _sendReminderEmail($email, $nombre, $customer, $monto, $metodo, $linkPago) {
    $pedidoNum = time();
    $n = htmlspecialchars($nombre);
    $m = htmlspecialchars($customer['modelo'] ?? '');
    $c = htmlspecialchars($customer['color'] ?? '');
    $montoFmt = '$' . number_format($monto, 0, '.', ',') . ' MXN';
    $whatsapp = '+52 55 1341 6370';

    $td = 'style="padding:10px 16px;border-bottom:1px solid #E5E7EB;font-size:14px;"';
    $tdl = 'style="padding:10px 16px;border-bottom:1px solid #E5E7EB;font-size:14px;color:#6B7280;"';
    $section = 'style="margin:0 0 8px;padding:12px 0 6px;font-size:16px;font-weight:800;color:#1a3a5c;border-bottom:2px solid #039fe1;"';

    $linkHtml = '';
    if (is_array($linkPago) && !empty($linkPago['clabe'])) {
        // SPEI bank transfer details
        $clabeValue = htmlspecialchars($linkPago['clabe']);
        $linkHtml .= '<div style="background:#E8F4FD;border-radius:10px;padding:16px;margin:12px 0;border:1px solid #B3D4FC;">';
        // Header with logo
        $linkHtml .= '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">';
        $linkHtml .= '<span style="font-size:14px;font-weight:700;color:#1a3a5c;">Datos para transferencia SPEI</span>';
        $linkHtml .= '<img src="https://www.voltika.mx/configurador/img/logo_w.png" alt="Voltika" width="84" style="height:28px;width:auto;border:0;outline:0;width:auto;background:#1a3a5c;border-radius:6px;padding:4px 8px;">';
        $linkHtml .= '</div>';
        // CLABE with copy link
        $linkHtml .= '<div style="background:#fff;border-radius:8px;padding:14px;border:1px solid #eee;margin-bottom:10px;">';
        $linkHtml .= '<div style="font-size:12px;color:#888;margin-bottom:4px;">CLABE Interbancaria:</div>';
        $linkHtml .= '<div style="display:flex;align-items:center;justify-content:space-between;">';
        $linkHtml .= '<div style="font-size:16px;font-weight:900;color:#333;letter-spacing:0.5px;">' . $clabeValue . '</div>';
        $linkHtml .= '<a href="https://www.voltika.mx/configurador/voucher.html?clabe=' . $clabeValue . '" style="flex-shrink:0;padding:6px 12px;background:#039fe1;color:#fff;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;">Copiar</a>';
        $linkHtml .= '</div>';
        $linkHtml .= '</div>';
        if (!empty($linkPago['referencia'])) {
            $linkHtml .= '<div style="font-size:14px;color:#333;margin-bottom:4px;">Referencia: <strong>' . htmlspecialchars($linkPago['referencia']) . '</strong></div>';
        }
        if (!empty($linkPago['beneficiario'])) {
            $linkHtml .= '<div style="font-size:14px;color:#333;margin-bottom:4px;">Beneficiario: <strong>' . htmlspecialchars($linkPago['beneficiario']) . '</strong></div>';
        }
        if (!empty($linkPago['banco'])) {
            $linkHtml .= '<div style="font-size:14px;color:#333;">Banco: <strong>' . htmlspecialchars($linkPago['banco']) . '</strong></div>';
        }
        $linkHtml .= '</div>';
    } elseif (is_array($linkPago) && !empty($linkPago['oxxoRefs'])) {
        // OXXO references with full details
        $refs = $linkPago['oxxoRefs'];
        $totalRefs = count($refs);
        $voucherBase = 'https://www.voltika.mx/configurador/voucher.html?url=';
        if ($totalRefs > 1) {
            $linkHtml .= '<p style="font-size:13px;color:#555;text-align:center;margin:8px 0;">Se generaron <strong>' . $totalRefs . ' referencias</strong> de pago. Presenta cualquiera en OXXO:</p>';
        }
        foreach ($refs as $idx => $ref) {
            $refNum = $ref['number'] ?? '--';
            $refAmount = $ref['amount'] ? '$' . number_format($ref['amount'] / 100, 0, '.', ',') . ' MXN' : '';
            $refExpires = !empty($ref['expires_after']) ? date('d/m/Y', $ref['expires_after']) : '';
            $formatted = implode(' ', str_split($refNum, 4));

            $linkHtml .= '<div style="background:#FFF8E1;border-radius:10px;padding:14px;margin:10px 0;border:1px solid #FFE082;">';
            if ($totalRefs > 1) {
                $linkHtml .= '<div style="font-size:12px;color:#039fe1;font-weight:700;margin-bottom:6px;">Referencia ' . ($idx + 1) . ' de ' . $totalRefs . '</div>';
            }
            $linkHtml .= '<div style="font-size:12px;color:#888;margin-bottom:2px;">N&uacute;mero de referencia:</div>';
            $linkHtml .= '<div style="font-size:16px;font-weight:900;color:#333;letter-spacing:0.5px;font-family:monospace;margin-bottom:8px;">' . htmlspecialchars($formatted) . '</div>';
            $linkHtml .= '<div style="font-size:13px;color:#333;">Monto: <strong>' . $refAmount . '</strong></div>';
            if ($refExpires) {
                $linkHtml .= '<div style="font-size:12px;color:#888;margin-top:2px;">Vence: <strong>' . $refExpires . '</strong></div>';
            }
            if (!empty($ref['hosted_voucher_url'])) {
                $wrappedUrl = $voucherBase . urlencode($ref['hosted_voucher_url']);
                $linkHtml .= '<a href="' . htmlspecialchars($wrappedUrl) . '" style="display:block;text-align:center;margin-top:10px;padding:10px;background:#E53935;color:#fff;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;">Ver voucher con c&oacute;digo de barras</a>';
            }
            $linkHtml .= '</div>';
        }
        $linkHtml .= '<p style="font-size:12px;color:#888;text-align:center;margin:10px 0 0;">Presenta en cualquier tienda OXXO. Confirmaci&oacute;n autom&aacute;tica al pagar.</p>';
    } elseif (is_array($linkPago) && count($linkPago) > 0) {
        // Fallback: array of URLs
        $voucherBase = 'https://www.voltika.mx/configurador/voucher.html?url=';
        $totalRefs = count($linkPago);
        foreach ($linkPago as $idx => $url) {
            $wrappedUrl = $voucherBase . urlencode($url);
            $label = $totalRefs > 1 ? 'REFERENCIA ' . ($idx + 1) . ' DE ' . $totalRefs . ' &rarr;' : 'COMPLETAR MI PAGO &rarr;';
            $linkHtml .= '<a href="' . htmlspecialchars($wrappedUrl) . '" style="display:block;text-align:center;padding:14px;background:#039fe1;color:#fff;border-radius:10px;font-size:14px;font-weight:800;text-decoration:none;margin:8px 0;">' . $label . '</a>';
        }
    } elseif (is_string($linkPago) && filter_var($linkPago, FILTER_VALIDATE_URL)) {
        $linkHtml = '<a href="' . htmlspecialchars($linkPago) . '" style="display:block;text-align:center;padding:16px;background:#039fe1;color:#fff;border-radius:10px;font-size:16px;font-weight:800;text-decoration:none;margin:12px 0;">COMPLETAR MI PAGO &rarr;</a>';
    } elseif (is_string($linkPago) && !empty($linkPago)) {
        $linkHtml = '<div style="background:#E8F4FD;border-radius:8px;padding:14px;text-align:center;margin:12px 0;font-size:14px;font-weight:700;color:#1a3a5c;">' . htmlspecialchars($linkPago) . '</div>';
    }

    $body = '<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Completa tu pago Voltika</title></head>
<body style="margin:0;padding:0;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;">
<tr><td align="center" style="padding:24px 12px;">
<table width="620" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;max-width:620px;width:100%;box-shadow:0 2px 12px rgba(0,0,0,0.08);">

<tr><td style="background:linear-gradient(135deg,#1a3a5c,#039fe1);padding:28px;text-align:center;">
<img src="https://www.voltika.mx/configurador/img/logo_w.png" alt="Voltika" width="132" style="height:44px;width:auto;border:0;outline:0;width:auto;display:block;margin:0 auto;">
<p style="margin:6px 0 0;font-size:13px;color:rgba(255,255,255,0.8);">Movilidad el&eacute;ctrica inteligente</p>
</td></tr>

<tr><td style="padding:28px;">

<h2 style="margin:0 0 8px;font-size:20px;color:#1a3a5c;">Hola ' . $n . ', tu Voltika te est&aacute; esperando.</h2>
<p style="margin:0 0 12px;font-size:14px;color:#555;line-height:1.7;">Ya elegiste tu modelo, tu color y tu forma de pago.<br>Tu moto est&aacute; lista para ti.</p>
<p style="margin:0 0 24px;font-size:15px;color:#E53935;font-weight:700;">Solo falta completar tu pago para asegurarla.</p>

<div ' . $section . '>TU VOLTIKA</div>
<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
<tr><td ' . $tdl . '>Cliente</td><td ' . $td . '><strong>' . $n . '</strong></td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Orden</td><td ' . $td . '><strong>#' . $pedidoNum . '</strong></td></tr>
<tr><td ' . $tdl . '>Modelo</td><td ' . $td . '>' . $m . '</td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Color</td><td ' . $td . '>' . $c . '</td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Monto pendiente</td><td ' . $td . '><strong style="color:#E53935;">' . $montoFmt . '</strong></td></tr>
<tr><td ' . $tdl . '>M&eacute;todo de pago</td><td ' . $td . '>' . htmlspecialchars($metodo) . '</td></tr>
</table>

<div ' . $section . '>TERMINA TU COMPRA AHORA</div>
' . (strpos($metodo, 'SPEI') !== false || strpos($metodo, 'Transferencia') !== false
    ? '<p style="font-size:14px;color:#555;margin:12px 0 8px;">Se gener&oacute; tu referencia para transferencia bancaria. Realiza tu transferencia desde cualquier banco a:</p>'
    : '<p style="font-size:14px;color:#555;margin:12px 0 8px;">Tu referencia de pago ya est&aacute; generada.</p>') . '
' . $linkHtml . '

<div ' . $section . '>PAGO AUTOM&Aacute;TICO (IMPORTANTE)</div>
<p style="font-size:14px;color:#555;margin:12px 0 8px;">No necesitas enviar comprobantes.</p>
<div style="font-size:13px;color:#555;line-height:1.8;margin-bottom:24px;">
&#10004; Tu pago se acredita autom&aacute;ticamente<br>
&#10004; Tu orden se activa en cuanto se confirma<br>
&#10004; Recibir&aacute;s la confirmaci&oacute;n por correo y WhatsApp
</div>

<div ' . $section . '>LO QUE PASA AL PAGAR</div>
<div style="font-size:13px;color:#555;line-height:1.8;margin-bottom:24px;">
&#10004; Aseguras tu Voltika<br>
&#10004; Activamos tu proceso de entrega<br>
&#10004; Te asignamos punto autorizado en menos de 48 horas
</div>

<div style="background:#FFF8E1;border-radius:8px;padding:16px;margin-bottom:24px;border-left:4px solid #FFB300;">
<p style="margin:0 0 6px;font-size:14px;font-weight:700;color:#E65100;">&#9888; IMPORTANTE</p>
<p style="margin:0 0 6px;font-size:13px;color:#555;">Debido a la demanda, las unidades se asignan conforme se completan los pagos.</p>
<p style="margin:0;font-size:13px;color:#E53935;font-weight:700;">Tu reserva puede liberarse si no se confirma el pago.</p>
</div>

<div ' . $section . '>ACREDITACI&Oacute;N</div>
<div style="font-size:13px;color:#555;line-height:1.8;margin-bottom:24px;">
&bull; SPEI: hasta 24 horas<br>
&bull; OXXO: hasta 24 horas
</div>

<div ' . $section . '>ENTREGA SEGURA</div>
<p style="font-size:14px;color:#333;font-weight:700;margin:12px 0 8px;">&#128274; Tu n&uacute;mero celular es tu llave de entrega.</p>
<div style="font-size:13px;color:#555;line-height:1.8;margin-bottom:24px;">
Se te pedir&aacute;:<br>
&bull; C&oacute;digo de seguridad (OTP)<br>
&bull; Identificaci&oacute;n oficial<br>
&bull; Confirmaci&oacute;n de datos de tu compra
</div>

<div ' . $section . '>&iquest;TIENES DUDAS?</div>
<p style="font-size:14px;color:#555;margin:12px 0 8px;">Te ayudamos en este momento.</p>
<p style="font-size:14px;margin:0 0 4px;">&#128241; WhatsApp: <a href="https://wa.me/5213416370" style="color:#039fe1;font-weight:700;">' . $whatsapp . '</a></p>
<p style="font-size:14px;margin:0 0 24px;">&#128231; Correo: <a href="mailto:redes@voltika.mx" style="color:#039fe1;font-weight:700;">redes@voltika.mx</a></p>

<div style="background:#F5F5F5;border-radius:8px;padding:16px;">
<p style="font-size:12px;margin:0 0 4px;"><a href="https://voltika.mx/docs/tyc_2026.pdf" style="color:#039fe1;">T&eacute;rminos y Condiciones</a></p>
<p style="font-size:12px;margin:0 0 8px;"><a href="https://voltika.mx/docs/privacidad_2026.pdf" style="color:#039fe1;">Aviso de Privacidad</a></p>
<p style="font-size:11px;color:#aaa;margin:0;">Al iniciar tu compra aceptaste estas condiciones.</p>
</div>

</td></tr>

<tr><td style="background:#1a3a5c;padding:20px 28px;text-align:center;">
<img src="https://www.voltika.mx/configurador/img/goelectric.svg" alt="GO electric" style="height:28px;width:auto;margin-bottom:8px;">
<p style="margin:0 0 4px;font-size:14px;font-weight:700;color:#fff;">Voltika M&eacute;xico</p>
<p style="margin:0;font-size:12px;color:rgba(255,255,255,0.6);">Mu&eacute;vete a el&eacute;ctrico &middot; Mtech Gears, S.A. de C.V.</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>';

    $asunto = 'Tu Voltika te está esperando! Completa tu pago Orden #' . $pedidoNum;
    try {
        sendMail($email, $nombre, $asunto, $body);
    } catch (Exception $e) {
        error_log('Voltika reminder email error: ' . $e->getMessage());
    }
}

// ── Stripe SDK ────────────────────────────────────────────────────────────────
$stripePhpPath = __DIR__ . '/vendor/autoload.php';
if (!file_exists($stripePhpPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Stripe SDK no encontrado. Ejecuta: cd php && composer install']);
    exit;
}
require_once $stripePhpPath;

// ── Helpers-only mode (customer brief 2026-05-01) ──────────────────────────
// Other scripts (cron-recovery-email.php) require_once this file just to
// reuse _voltikaSendIncompletePaymentEmail() etc. We must not execute the
// HTTP-request flow below in that case (it tries to read php://input and
// would emit "Request invalido" to the cron caller). The caller signals
// helpers-only by defining VOLTIKA_PI_HELPERS_ONLY before the require_once.
if (defined('VOLTIKA_PI_HELPERS_ONLY') && VOLTIKA_PI_HELPERS_ONLY) {
    return;
}

if (!STRIPE_SECRET_KEY || STRIPE_SECRET_KEY === 'sk_test_PLACEHOLDER') {
    http_response_code(500);
    echo json_encode(['error' => 'STRIPE_SECRET_KEY no configurada. Edita el archivo .env']);
    exit;
}

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// ── Request ───────────────────────────────────────────────────────────────────
$json = json_decode(file_get_contents('php://input'), true);

if (!$json) {
    http_response_code(400);
    echo json_encode(['error' => 'Request invalido']);
    exit;
}

$amount           = intval($json['amount'] ?? 0);        // centavos MXN
$method           = trim($json['method'] ?? 'card');      // card, oxxo, spei
$installments     = !empty($json['installments']);
$msiMeses         = intval($json['msiMeses'] ?? 9);
$customer         = $json['customer'] ?? [];
// Purchase type drives the recovery-email language. Credit-flow callers
// (paso-credito-enganche.js) send tipo='enganche'; the configurador
// checkout (paso4a-checkout.js) sends customer.tpago='msi'|'unico'.
$purchaseTipo     = (string)($json['tipo'] ?? ($customer['tpago'] ?? ''));
if ($purchaseTipo === '') {
    $purchaseTipo = $installments ? 'msi' : 'contado';
}

if ($amount <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Monto invalido']);
    exit;
}

// ── Determinar payment_method_types segun metodo ─────────────────────────────
$paymentMethodTypes = ['card'];
if ($method === 'oxxo') {
    $paymentMethodTypes = ['oxxo'];
} elseif ($method === 'spei') {
    $paymentMethodTypes = ['customer_balance'];
}

// ── Crear PaymentIntent ───────────────────────────────────────────────────────
try {
    // Metadata is our safety net for webhook-based order recovery. If the
    // client's POST to confirmar-orden.php ever fails (network drop,
    // browser close, etc.), stripe-webhook.php can reconstruct the order
    // from these fields alone — so include every field transacciones
    // needs to be auditable.
    $intentData = [
        'amount'               => $amount,
        'currency'             => 'mxn',
        'payment_method_types' => $paymentMethodTypes,
        'description'          => 'Voltika - ' . ($customer['modelo'] ?? 'Moto electrica'),
        'metadata'             => [
            'nombre'    => $customer['nombre']    ?? '',
            'apellidos' => $customer['apellidos'] ?? '',
            'email'     => $customer['email']     ?? '',
            'telefono'  => $customer['telefono']  ?? '',
            'modelo'    => $customer['modelo']    ?? '',
            'color'     => $customer['color']     ?? '',
            'ciudad'    => $customer['ciudad']    ?? '',
            'estado'    => $customer['estado']    ?? '',
            'cp'        => $customer['cp']        ?? '',
            'method'    => $method,
            'tpago'     => $customer['tpago']     ?? ($installments ? 'msi' : $method),
            'msi_meses' => $installments ? (string)$msiMeses : '0',
            'punto_id'     => $customer['punto_id']     ?? '',
            'punto_nombre' => $customer['punto_nombre'] ?? '',
        ],
    ];

    // Agregar datos del cliente si estan disponibles
    if (!empty($customer['nombre']) && !empty($customer['email'])) {
        $stripeCustomer = \Stripe\Customer::create([
            'name'  => $customer['nombre'],
            'email' => $customer['email'],
            'phone' => '+52' . ($customer['telefono'] ?? ''),
        ]);
        $intentData['customer'] = $stripeCustomer->id;
        $intentData['receipt_email'] = $customer['email'];
    }

    // SPEI: handle server-side and return bank details directly
    if ($method === 'spei') {
        // SPEI requiere customer obligatorio
        if (empty($intentData['customer'])) {
            $stripeCustomer = \Stripe\Customer::create([
                'name'  => $customer['nombre'] ?? 'Cliente Voltika',
                'email' => $customer['email'] ?? 'cliente@voltika.mx',
            ]);
            $intentData['customer'] = $stripeCustomer->id;
        }
        $intentData['payment_method_types'] = ['customer_balance'];
        $intentData['payment_method_data'] = [
            'type' => 'customer_balance'
        ];
        $intentData['payment_method_options'] = [
            'customer_balance' => [
                'funding_type' => 'bank_transfer',
                'bank_transfer' => [
                    'type' => 'mx_bank_transfer'
                ]
            ]
        ];
        $intentData['confirm'] = true;

        $intent = \Stripe\PaymentIntent::create($intentData);

        // SPEI: track as pendiente immediately. The bank-details email is
        // sent below (existing _sendReminderEmail) so we only insert the
        // DB row here — no duplicate email.
        _voltikaInsertPendingTransaccion($intent->id, $customer, $amount, 'spei', 0);

        $response = ['clientSecret' => $intent->client_secret, 'paymentIntentId' => $intent->id];

        // Extract bank transfer details
        if ($intent->next_action && isset($intent->next_action->display_bank_transfer_instructions)) {
            $bankInfo = $intent->next_action->display_bank_transfer_instructions;
            $addresses = $bankInfo->financial_addresses ?? [];
            $clabe = '';
            foreach ($addresses as $addr) {
                // Try different CLABE property paths
                if (isset($addr->spei_clabe->clabe)) {
                    $clabe = $addr->spei_clabe->clabe;
                    break;
                } elseif (isset($addr->clabe)) {
                    $clabe = $addr->clabe;
                    break;
                } elseif (isset($addr->spei->clabe)) {
                    $clabe = $addr->spei->clabe;
                    break;
                }
            }
            // If still no CLABE, try to get from the full object
            if (empty($clabe) && !empty($addresses)) {
                $firstAddr = json_decode(json_encode($addresses[0]), true);
                error_log('SPEI address structure: ' . json_encode($firstAddr));
                // Search recursively for any 18-digit number (CLABE format)
                array_walk_recursive($firstAddr, function($value) use (&$clabe) {
                    if (is_string($value) && preg_match('/^\d{18}$/', $value)) {
                        $clabe = $value;
                    }
                });
            }
            $response['speiData'] = [
                'clabe'        => $clabe,
                'banco'        => !empty($bankInfo->hosted_instructions_url) ? 'Stripe' : 'STP',
                'beneficiario' => 'MTECH GEARS S.A. DE C.V.',
                'referencia'   => $bankInfo->reference ?? '',
                'amount'       => $amount
            ];
        }

        // Send SPEI reminder email
        $custEmail = $customer['email'] ?? '';
        $custNombre = trim(($customer['nombre'] ?? '') . ' ' . ($customer['apellidos'] ?? ''));
        if ($custEmail) {
            $speiInfo = [
                'clabe'        => $clabe ?: '',
                'referencia'   => $bankInfo->reference ?? '',
                'beneficiario' => 'MTECH GEARS S.A. DE C.V.',
                'banco'        => !empty($bankInfo->hosted_instructions_url) ? 'Stripe' : 'STP'
            ];
            _sendReminderEmail($custEmail, $custNombre, $customer, $amount / 100, 'Transferencia SPEI', $speiInfo);
        }

        echo json_encode($response);
        exit;
    }

    // ── 3D Secure handling for card payments ───────────────────────────────
    // Customer brief 2026-04-24: "All card payment transactions has to be
    // with 3D secure".
    //
    // History:
    //   - 'any' was forcing 3DS on EVERY card unconditionally. Diagnostic
    //     2026-05-01 showed 90% of recent live PIs failing — 21/30 stuck at
    //     `requires_payment_method` and 6/30 at `requires_action`. Many
    //     Mexican issuing banks have spotty 3DS support: the popup either
    //     doesn't appear, the SMS/email code never arrives, or the
    //     authentication times out → customer reports "no deja pagar con
    //     TDC". This was the production-breaking bug.
    //
    // Fix (customer brief 2026-05-01): change to 'automatic' — Stripe and
    // the issuing bank decide together when 3DS is actually required (the
    // issuer-mandated cases under SCA / EMV 3DS rules). This still
    // protects high-risk transactions while letting low-risk/issuer-exempt
    // transactions proceed without an unnecessary 3DS hurdle. Liability
    // shift still applies whenever 3DS does run, which covers the
    // chargeback concern for the $48k motorcycles.
    //
    // Off-session (MIT) payments — like the weekly credit auto-charge —
    // cannot do 3DS because the customer isn't present; Stripe handles
    // those via the MIT exemption captured during this first on-session
    // payment.
    if ($method === 'card') {
        if (!isset($intentData['payment_method_options'])) {
            $intentData['payment_method_options'] = [];
        }
        if (!isset($intentData['payment_method_options']['card'])) {
            $intentData['payment_method_options']['card'] = [];
        }
        $intentData['payment_method_options']['card']['request_three_d_secure'] = 'automatic';
    }

    // Habilitar MSI si aplica (solo para card)
    if ($method === 'card' && $installments && $msiMeses > 0) {
        if (!isset($intentData['payment_method_options']['card'])) {
            $intentData['payment_method_options']['card'] = [];
        }
        $intentData['payment_method_options']['card']['installments'] = ['enabled' => true];
    }

    // Para OXXO: dividir si supera $10,000 MXN (1,000,000 centavos)
    if ($method === 'oxxo') {
        $maxOxxoCents = 999900; // $9,999 MXN en centavos (margen seguro)
        $oxxoAmounts = [];
        if ($amount > $maxOxxoCents) {
            $remaining = $amount;
            while ($remaining > 0) {
                $chunk = min($remaining, $maxOxxoCents);
                $oxxoAmounts[] = $chunk;
                $remaining -= $chunk;
            }
        } else {
            $oxxoAmounts[] = $amount;
        }

        $oxxoRefs = [];
        $rawName      = !empty($customer['nombre']) ? trim($customer['nombre']) : '';
        $billingEmail = !empty($customer['email']) ? trim($customer['email']) : 'cliente@voltika.mx';
        // OXXO requires first + last name, each min 2 chars — always ensure valid
        $billingName = 'Cliente Voltika';
        if (strlen($rawName) >= 4 && strpos($rawName, ' ') !== false) {
            $parts = explode(' ', $rawName);
            $valid = true;
            foreach ($parts as $p) {
                if (strlen(trim($p)) < 2) { $valid = false; break; }
            }
            if ($valid) $billingName = $rawName;
        }

        // Asegurar que hay customer para OXXO
        if (empty($intentData['customer'])) {
            $stripeCustomer = \Stripe\Customer::create([
                'name'  => $billingName,
                'email' => $billingEmail,
            ]);
            $intentData['customer'] = $stripeCustomer->id;
        }

        foreach ($oxxoAmounts as $idx => $oxxoAmount) {
            $oxxoIntentData = [
                'amount'               => $oxxoAmount,
                'currency'             => 'mxn',
                'payment_method_types' => ['oxxo'],
                'customer'             => $intentData['customer'],
                'description'          => 'Voltika - OXXO ' . ($idx + 1) . '/' . count($oxxoAmounts),
                'metadata'             => $intentData['metadata'] ?? [],
            ];

            $intent = \Stripe\PaymentIntent::create($oxxoIntentData);

            // Crear PaymentMethod y confirmar
            $pm = \Stripe\PaymentMethod::create([
                'type' => 'oxxo',
                'billing_details' => [
                    'name'  => $billingName,
                    'email' => $billingEmail,
                ],
            ]);

            $intent = $intent->confirm(['payment_method' => $pm->id]);

            if ($intent->next_action && isset($intent->next_action->oxxo_display_details)) {
                $oxxo = $intent->next_action->oxxo_display_details;
                $oxxoRefs[] = [
                    'number'             => $oxxo->number ?? '',
                    'amount'             => $oxxoAmount,
                    'expires_after'      => $oxxo->expires_after ?? 0,
                    'hosted_voucher_url' => $oxxo->hosted_voucher_url ?? '',
                    'paymentIntentId'    => $intent->id
                ];
            }
        }

        // ── OXXO dashboard tracking (customer brief 2026-05-01) ───────────
        // OXXO orders over $10k are split into multiple references in
        // Stripe, but the customer expects ONE dashboard entry per order.
        // Insert/update the pending row only ONCE, using the first PI as
        // canonical and the TOTAL enganche amount (sum of refs).
        if (!empty($oxxoRefs)) {
            _voltikaInsertPendingTransaccion(
                (string)$oxxoRefs[0]['paymentIntentId'],
                $customer,
                (int)$amount,
                'oxxo',
                0
            );
        }

        $response = [
            'oxxoData' => $oxxoRefs,
            'totalRefs' => count($oxxoRefs),
            'paymentIntentId' => !empty($oxxoRefs) ? $oxxoRefs[0]['paymentIntentId'] : ''
        ];

        // Send OXXO reminder email with full reference data
        $custEmail = $customer['email'] ?? '';
        $custNombre = trim(($customer['nombre'] ?? '') . ' ' . ($customer['apellidos'] ?? ''));
        if ($custEmail) {
            _sendReminderEmail($custEmail, $custNombre, $customer, $amount / 100, 'Pago en OXXO', ['oxxoRefs' => $oxxoRefs]);
        }

        echo json_encode($response);
        exit;
    }

    $intent = \Stripe\PaymentIntent::create($intentData);

    // ── Track this attempt as a 'pendiente' transaccion ─────────────────────
    //   Customer brief 2026-04-30: visibility on approved-but-not-paid leads.
    //
    // Customer brief 2026-05-01 URGENT: the immediate recovery-email send
    // was firing the moment a customer reached the payment screen, before
    // they had a chance to actually pay. Customers who completed checkout
    // 5 seconds later then received a "completa tu pago" email when they
    // had already paid — confusing and damaging to reputation.
    //
    // Fix: insert the pending row (so admin still sees the lead) but DO
    // NOT send the recovery email here. A separate cron-driven script
    // (php/cron-recovery-email.php) is responsible for sending the email
    // ONLY for rows that have remained 'pendiente' for >30 minutes — by
    // then the chance of a successful late payment is low and the row is
    // genuinely an abandoned cart.
    _voltikaInsertPendingTransaccion($intent->id, $customer, $amount, $method, $installments ? $msiMeses : 0);
    // Email send intentionally REMOVED here — handled by cron-recovery-email.php.

    $response = ['clientSecret' => $intent->client_secret, 'paymentIntentId' => $intent->id];

    echo json_encode($response);

} catch (\Stripe\Exception\CardException $e) {
    http_response_code(402);
    echo json_encode(['error' => $e->getError()->message]);
} catch (\Stripe\Exception\ApiErrorException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error de Stripe: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error interno del servidor']);
}
