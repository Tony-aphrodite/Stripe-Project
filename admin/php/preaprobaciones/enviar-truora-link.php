<?php
/**
 * Voltika — admin manual-review action: send Truora verification link to a
 * preaprobacion applicant who completed CDC but never started Truora.
 *
 * Customer brief 2026-05-02: capture lost leads where the credit was
 * approved but the customer abandoned at the identity step. The admin
 * picks the row in Preaprobaciones, clicks "Enviar link de Truora", and
 * the system emails + texts the applicant a one-tap link that pre-fills
 * their CURP and drops them straight into the Truora step (no CDC
 * re-evaluation, no form re-entry).
 *
 * POST /admin/php/preaprobaciones/enviar-truora-link.php
 * Body: { "id": <preaprobacion_id> }
 *
 * Response: { ok: true, email_sent, sms_sent, recovery_url } on success
 */

require_once __DIR__ . '/../bootstrap.php';
$adminId = adminRequireAuth(['admin']);

$body = adminJsonIn();
$id   = (int)($body['id'] ?? 0);
if ($id <= 0) {
    adminJsonOut(['ok' => false, 'error' => 'id_required'], 400);
}

$pdo = getDB();

try {
    // ── Load preaprobacion data ─────────────────────────────────────────
    $stmt = $pdo->prepare("SELECT * FROM preaprobaciones WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        adminJsonOut(['ok' => false, 'error' => 'preaprobacion_not_found'], 404);
    }

    $email = trim((string)($row['email']    ?? ''));
    $tel   = trim((string)($row['telefono'] ?? ''));
    $nombre = trim(
        ($row['nombre']           ?? '') . ' ' .
        ($row['apellido_paterno'] ?? '') . ' ' .
        ($row['apellido_materno'] ?? '')
    );

    if ($email === '' && $tel === '') {
        adminJsonOut(['ok' => false, 'error' => 'no_contact_info'], 400);
    }

    // ── Build the HMAC-signed recovery URL ──────────────────────────────
    // Token format: id.expires.hmac(SHA256(id.expires + RECOVER_SECRET))
    // 7-day expiry — more than enough for a follow-up call/text. The
    // configurador/recover-truora.php landing page validates the HMAC and
    // rejects expired or tampered tokens.
    $recoverSecret = defined('VOLTIKA_RECOVER_SECRET')
        ? VOLTIKA_RECOVER_SECRET
        : (getenv('VOLTIKA_RECOVER_SECRET') ?: 'voltika_recover_2026_default');
    $expires = time() + (7 * 24 * 3600);
    $payload = $id . '.' . $expires;
    $sig     = hash_hmac('sha256', $payload, $recoverSecret);
    $token   = $payload . '.' . $sig;

    $base = defined('VOLTIKA_BASE_URL') ? rtrim(VOLTIKA_BASE_URL, '/') : 'https://www.voltika.mx';
    $recoveryUrl = $base . '/configurador/recover-truora.php?t=' . urlencode($token);

    // ── Send email ──────────────────────────────────────────────────────
    $emailSent = false;
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && function_exists('sendMail')) {
        $modelo = htmlspecialchars((string)($row['modelo'] ?? 'tu Voltika'));
        $name   = htmlspecialchars($nombre ?: 'Cliente Voltika');

        $subject = 'Voltika — Completa la verificación de identidad para tu crédito';
        $html =
            '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:560px;margin:auto;padding:24px;background:#fff;">' .
            '<div style="text-align:center;background:#1a3a5c;padding:18px;border-radius:12px 12px 0 0;">' .
            '<img src="' . $base . '/configurador/img/voltika_logo_h_white.svg" alt="Voltika" style="height:34px;">' .
            '</div>' .
            '<div style="background:#F8FAFC;padding:24px;border-radius:0 0 12px 12px;border:1px solid #E5E7EB;border-top:none;">' .
            '<h1 style="font-size:22px;color:#1a3a5c;margin:0 0 12px;">Hola ' . $name . ',</h1>' .
            '<p style="font-size:15px;color:#333;line-height:1.5;margin:0 0 14px;">' .
                'Tu solicitud de crédito para <strong>' . $modelo . '</strong> está casi lista. Solo falta un paso: <strong>verificar tu identidad</strong> tomando una foto de tu INE y una selfie.' .
            '</p>' .
            '<div style="background:#fff;border-radius:10px;padding:16px;margin:12px 0;border:1px solid #E5E7EB;">' .
                '<div style="font-size:13px;color:#888;margin-bottom:4px;">Modelo</div>' .
                '<div style="font-size:18px;font-weight:800;color:#333;margin-bottom:8px;">' . $modelo . '</div>' .
                '<div style="font-size:12px;color:#666;">Toma menos de 2 minutos · No vuelves a llenar el formulario</div>' .
            '</div>' .
            '<a href="' . htmlspecialchars($recoveryUrl) . '" style="display:block;text-align:center;padding:14px;background:#039fe1;color:#fff;border-radius:10px;font-size:15px;font-weight:800;text-decoration:none;margin:16px 0;">Continuar verificación</a>' .
            '<p style="font-size:13px;color:#666;line-height:1.5;margin:16px 0 0;">' .
                '¿Necesitas ayuda? Llámanos o escríbenos por WhatsApp al ' .
                '<a href="https://wa.me/525513416370" style="color:#039fe1;text-decoration:none;font-weight:700;">+52 55 1341 6370</a>.' .
            '</p>' .
            '<p style="font-size:11px;color:#999;margin:16px 0 0;text-align:center;">Este enlace es personal y expira en 7 días.</p>' .
            '</div>' .
            '</div>';

        try {
            $emailSent = (bool) @sendMail($email, $nombre, $subject, $html);
        } catch (Throwable $e) {
            error_log('enviar-truora-link email: ' . $e->getMessage());
        }
    }

    // ── Send SMS (voltikaSendSMS exists in voltika-notify.php) ──────────
    $smsSent = false;
    if ($tel !== '' && function_exists('voltikaSendSMS')) {
        $smsBody = "Voltika: continúa la verificación de tu crédito Voltika aquí: " . $recoveryUrl;
        try {
            $r = voltikaSendSMS($tel, $smsBody);
            $smsSent = !empty($r['ok']);
        } catch (Throwable $e) {
            error_log('enviar-truora-link sms: ' . $e->getMessage());
        }
    }

    // ── Update seguimiento + audit ──────────────────────────────────────
    try {
        $pdo->prepare("UPDATE preaprobaciones
            SET seguimiento = 'truora_enviado',
                notas_admin = TRIM(CONCAT(COALESCE(notas_admin,''), '\n[', NOW(), '] Link Truora enviado por admin#' , ?))
            WHERE id = ?")
            ->execute([$adminId, $id]);
    } catch (Throwable $e) {
        error_log('enviar-truora-link update: ' . $e->getMessage());
    }

    adminLog('preaprobacion_truora_link_enviado', [
        'preaprobacion_id' => $id,
        'email_sent'       => $emailSent,
        'sms_sent'         => $smsSent,
    ]);

    adminJsonOut([
        'ok'           => true,
        'email_sent'   => $emailSent,
        'sms_sent'     => $smsSent,
        'recovery_url' => $recoveryUrl,
        'message'      => 'Link de Truora enviado al cliente.',
    ]);
} catch (Throwable $e) {
    error_log('enviar-truora-link fatal: ' . $e->getMessage());
    adminJsonOut(['ok' => false, 'error' => 'internal_error', 'detail' => $e->getMessage()], 500);
}
