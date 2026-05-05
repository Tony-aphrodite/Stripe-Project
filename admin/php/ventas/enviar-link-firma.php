<?php
/**
 * Voltika — Send a digital-signing link to a paid customer who has
 * not yet signed their contract.
 *
 * Customer brief 2026-05-04 round 3: contado/MSI orders need a real
 * signed contract. The dashboard now flags "⚠ Pagado · Falta firma"
 * for these rows. This endpoint is the action the admin triggers
 * from that flag — generates an HMAC link valid 7 days, emails +
 * texts the customer, returns the URL so the admin can verify.
 *
 * POST /admin/php/ventas/enviar-link-firma.php
 * Body: { transaccion_id }
 * Response: { ok, link, email_sent, sms_sent }
 */

require_once __DIR__ . '/../bootstrap.php';
$adminId = adminRequireAuth(['admin', 'cedis']);

$body = adminJsonIn();
$txId = (int)($body['transaccion_id'] ?? 0);
if ($txId <= 0) adminJsonOut(['ok' => false, 'error' => 'transaccion_id_required'], 400);

try {
    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT * FROM transacciones WHERE id = ? LIMIT 1");
    $stmt->execute([$txId]);
    $tx   = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tx) adminJsonOut(['ok' => false, 'error' => 'pedido_not_found'], 404);

    $email = trim((string)($tx['email']    ?? ''));
    $tel   = trim((string)($tx['telefono'] ?? ''));
    if ($email === '' && $tel === '') {
        adminJsonOut(['ok' => false, 'error' => 'sin_email_ni_telefono'], 400);
    }

    // Build HMAC-signed 7-day link
    $secret = defined('VOLTIKA_RECOVER_SECRET')
        ? VOLTIKA_RECOVER_SECRET
        : (getenv('VOLTIKA_RECOVER_SECRET') ?: 'voltika_recover_2026_default');
    $expires = time() + (7 * 24 * 3600);
    $payload = $txId . '.' . $expires . '.firma';
    $sig     = hash_hmac('sha256', $payload, $secret);
    $token   = $payload . '.' . $sig;
    $base    = defined('VOLTIKA_BASE_URL') ? rtrim(VOLTIKA_BASE_URL, '/') : 'https://www.voltika.mx';
    $link    = $base . '/configurador/firmar-contrato-checkout.php?t=' . urlencode($token);

    $pedido = $tx['pedido_corto'] ?: ('VK-' . ($tx['pedido'] ?? $tx['id']));
    $name   = trim((string)($tx['nombre'] ?? '')) ?: 'Cliente Voltika';

    // Email
    $emailSent = false;
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && function_exists('sendMail')) {
        $subject = 'Voltika — Falta tu firma del contrato ' . $pedido;
        $html =
            '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:560px;margin:auto;padding:24px;background:#fff;">' .
            '<div style="text-align:center;background:#1a3a5c;padding:18px;border-radius:12px 12px 0 0;">' .
            '<img src="' . $base . '/configurador/img/voltika_logo_h_white.svg" alt="Voltika" style="height:34px;">' .
            '</div>' .
            '<div style="background:#F8FAFC;padding:24px;border-radius:0 0 12px 12px;border:1px solid #E5E7EB;border-top:none;">' .
            '<h1 style="font-size:22px;color:#1a3a5c;margin:0 0 12px;">Hola ' . htmlspecialchars($name) . ',</h1>' .
            '<p style="font-size:15px;color:#333;line-height:1.5;margin:0 0 14px;">' .
                'Ya recibimos tu pago del pedido <strong>' . htmlspecialchars($pedido) . '</strong>. ' .
                'Solo falta tu firma para cerrar el contrato y liberar la entrega de tu Voltika.' .
            '</p>' .
            '<a href="' . htmlspecialchars($link) . '" style="display:block;text-align:center;padding:14px;background:#1a6b1a;color:#fff;border-radius:10px;font-size:15px;font-weight:800;text-decoration:none;margin:16px 0;">Firmar contrato</a>' .
            '<p style="font-size:13px;color:#666;line-height:1.5;margin:16px 0 0;">' .
                '¿Necesitas ayuda? WhatsApp: ' .
                '<a href="https://wa.me/525513416370" style="color:#039fe1;text-decoration:none;font-weight:700;">+52 55 1341 6370</a>' .
            '</p>' .
            '<p style="font-size:11px;color:#999;margin:16px 0 0;text-align:center;">El enlace es personal y expira en 7 días.</p>' .
            '</div>' .
            '</div>';
        try { $emailSent = (bool) @sendMail($email, $name, $subject, $html); }
        catch (Throwable $e) { error_log('enviar-link-firma email: ' . $e->getMessage()); }
    }

    // SMS
    $smsSent = false;
    if ($tel !== '' && function_exists('voltikaSendSMS')) {
        $smsBody = "Voltika: falta tu firma del contrato " . $pedido . ". Firma aqui (valido 7 dias): " . $link;
        try {
            $r = voltikaSendSMS($tel, $smsBody);
            $smsSent = !empty($r['ok']);
        } catch (Throwable $e) { error_log('enviar-link-firma sms: ' . $e->getMessage()); }
    }

    adminLog('venta_link_firma_enviado', [
        'transaccion_id' => $txId,
        'pedido'         => $pedido,
        'email_sent'     => $emailSent,
        'sms_sent'       => $smsSent,
        'admin_id'       => $adminId,
    ]);

    adminJsonOut([
        'ok'         => true,
        'link'       => $link,
        'expires_at' => date('Y-m-d H:i:s', $expires),
        'email_sent' => $emailSent,
        'sms_sent'   => $smsSent,
    ]);
} catch (Throwable $e) {
    error_log('enviar-link-firma fatal: ' . $e->getMessage());
    adminJsonOut(['ok' => false, 'error' => 'internal'], 500);
}
