<?php
/**
 * Voltika — Bulk-resend the Truora+Cincel signing link to multiple credit
 * customers whose contract is still unsigned.
 *
 * Customer brief 2026-05-12 (Óscar, 10th round — "There's other purchase
 * operations without signed contract" — systemic recovery). Fase 2 of the
 * audit-and-recover plan. The admin selects N rows from the "Sin firma"
 * panel and triggers this endpoint with the transaccion_ids. Per row we
 *   1. Locate the matching preaprobacion (by email or phone).
 *   2. Reuse the existing single-send Truora-link flow.
 *   3. Aggregate results so the admin sees a per-row summary.
 *
 * POST /admin/php/ventas/reenviar-firmas-masivo.php
 * Body: { "transaccion_ids": [123, 456, ...] }
 * Response: { ok, results: [{transaccion_id, email_sent, sms_sent, error?}], summary }
 */

require_once __DIR__ . '/../bootstrap.php';
$adminId = adminRequireAuth(['admin']);

$body = adminJsonIn();
$ids = $body['transaccion_ids'] ?? [];
if (!is_array($ids) || !count($ids)) {
    adminJsonOut(['ok' => false, 'error' => 'transaccion_ids_required'], 400);
}
// Sanitize: keep only positive integers, dedupe, cap at 200 per batch.
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
if (count($ids) === 0) {
    adminJsonOut(['ok' => false, 'error' => 'no_valid_ids'], 400);
}
if (count($ids) > 200) {
    adminJsonOut(['ok' => false, 'error' => 'batch_too_large', 'max' => 200], 400);
}

$pdo = getDB();

// HMAC secret + base URL (same logic as enviar-truora-link.php so the
// recovery URLs are identical).
$recoverSecret = defined('VOLTIKA_RECOVER_SECRET')
    ? VOLTIKA_RECOVER_SECRET
    : (getenv('VOLTIKA_RECOVER_SECRET') ?: 'voltika_recover_2026_default');
$base = defined('VOLTIKA_BASE_URL') ? rtrim(VOLTIKA_BASE_URL, '/') : 'https://www.voltika.mx';

// Notification helpers are optional (voltika-notify.php exposes sendMail +
// voltikaSendSMS). If not present we still try to record audit rows.
$notifyPath = __DIR__ . '/../../../configurador/php/voltika-notify.php';
if (is_file($notifyPath)) {
    try { require_once $notifyPath; } catch (Throwable $e) { error_log('notify include: ' . $e->getMessage()); }
}

$results = [];
$summary = ['ok' => 0, 'sin_preaprobacion' => 0, 'sin_contacto' => 0, 'errores' => 0];

foreach ($ids as $txId) {
    $row = [
        'transaccion_id' => $txId,
        'email_sent'     => false,
        'sms_sent'       => false,
        'recovery_url'   => null,
        'preaprobacion_id' => null,
        'error'          => null,
    ];

    try {
        // 1. Load transaccion + matching preaprobacion in one query
        $stmt = $pdo->prepare("
            SELECT t.id AS tx_id, t.nombre AS tx_nombre, t.email AS tx_email,
                   t.telefono AS tx_telefono, t.modelo AS tx_modelo,
                   p.id AS preap_id, p.nombre AS preap_nombre,
                   p.apellido_paterno, p.apellido_materno,
                   p.email AS preap_email, p.telefono AS preap_telefono,
                   p.modelo AS preap_modelo
              FROM transacciones t
              LEFT JOIN preaprobaciones p ON (
                       (p.email    <> '' AND p.email    = t.email)
                    OR (p.telefono <> '' AND p.telefono = t.telefono)
              )
             WHERE t.id = ?
             LIMIT 1
        ");
        $stmt->execute([$txId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$r) {
            $row['error'] = 'transaccion_no_encontrada';
            $summary['errores']++;
            $results[] = $row;
            continue;
        }

        // 2. Build target contact data — preaprobacion wins (más completo)
        $preapId = (int)($r['preap_id'] ?? 0);
        $email   = trim((string)($r['preap_email']    ?: $r['tx_email']    ?? ''));
        $tel     = trim((string)($r['preap_telefono'] ?: $r['tx_telefono'] ?? ''));
        $nombre  = trim(
            ($r['preap_nombre']           ?? $r['tx_nombre'] ?? '') . ' ' .
            ($r['apellido_paterno']       ?? '') . ' ' .
            ($r['apellido_materno']       ?? '')
        );
        $modelo  = $r['preap_modelo'] ?: ($r['tx_modelo'] ?: 'tu Voltika');
        $row['preaprobacion_id'] = $preapId ?: null;

        if (!$preapId) {
            // No preaprobacion linked — admin needs to handle these manually
            // (maybe customer skipped CDC). We still try to send a generic
            // signing link, but flag it for review.
            $row['error'] = 'sin_preaprobacion_vinculada';
            $summary['sin_preaprobacion']++;
            // Do not stop — proceed with whatever contact data we have.
        }

        if ($email === '' && $tel === '') {
            $row['error'] = ($row['error'] ? $row['error'].'; ' : '') . 'sin_contacto';
            $summary['sin_contacto']++;
            $results[] = $row;
            continue;
        }

        // 3. Build the recovery URL — same HMAC scheme as enviar-truora-link.php
        $linkId = $preapId ?: $txId; // fallback identifier
        $expires = time() + (7 * 24 * 3600);
        $payload = $linkId . '.' . $expires;
        $sig     = hash_hmac('sha256', $payload, $recoverSecret);
        $token   = $payload . '.' . $sig;
        $recoveryUrl = $base . '/configurador/recover-truora.php?t=' . urlencode($token);
        $row['recovery_url'] = $recoveryUrl;

        // 4. Email
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && function_exists('sendMail')) {
            $modelo_h = htmlspecialchars($modelo);
            $name_h   = htmlspecialchars($nombre ?: 'Cliente Voltika');
            $subject  = 'Voltika — Completa la firma de tu contrato de crédito';
            $html =
                '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:560px;margin:auto;padding:24px;background:#fff;">' .
                '<div style="text-align:center;background:#1a3a5c;padding:18px;border-radius:12px 12px 0 0;">' .
                '<img src="' . $base . '/configurador/img/logo_w.png" alt="Voltika" width="102" style="height:34px;width:auto;border:0;outline:0;">' .
                '</div>' .
                '<div style="background:#F8FAFC;padding:24px;border-radius:0 0 12px 12px;border:1px solid #E5E7EB;border-top:none;">' .
                '<h1 style="font-size:22px;color:#1a3a5c;margin:0 0 12px;">Hola ' . $name_h . ',</h1>' .
                '<p style="font-size:15px;color:#333;line-height:1.5;margin:0 0 14px;">' .
                    'Recibimos tu enganche para <strong>' . $modelo_h . '</strong>, ¡gracias!. ' .
                    'Para entregarte la moto necesitamos que firmes el contrato de crédito ' .
                    'electrónicamente (verificación de identidad con Truora + firma con Cincel).' .
                '</p>' .
                '<div style="background:#fff;border-radius:10px;padding:16px;margin:12px 0;border:1px solid #E5E7EB;">' .
                    '<div style="font-size:13px;color:#888;margin-bottom:4px;">Modelo</div>' .
                    '<div style="font-size:18px;font-weight:800;color:#333;margin-bottom:8px;">' . $modelo_h . '</div>' .
                    '<div style="font-size:12px;color:#666;">Toma menos de 3 minutos · 100% en línea</div>' .
                '</div>' .
                '<a href="' . htmlspecialchars($recoveryUrl) . '" style="display:block;text-align:center;padding:14px;background:#039fe1;color:#fff;border-radius:10px;font-size:15px;font-weight:800;text-decoration:none;margin:16px 0;">Firmar contrato</a>' .
                '<p style="font-size:13px;color:#666;line-height:1.5;margin:16px 0 0;">' .
                    '¿Necesitas ayuda? Llámanos o escríbenos por WhatsApp al ' .
                    '<a href="https://wa.me/525513416370" style="color:#039fe1;text-decoration:none;font-weight:700;">+52 55 1341 6370</a>.' .
                '</p>' .
                '<p style="font-size:11px;color:#999;margin:16px 0 0;text-align:center;">Este enlace es personal y expira en 7 días.</p>' .
                '</div>' .
                '</div>';
            try {
                $row['email_sent'] = (bool) @sendMail($email, $nombre, $subject, $html);
            } catch (Throwable $e) {
                error_log('reenviar-firma email: ' . $e->getMessage());
            }
        }

        // 5. SMS
        if ($tel !== '' && function_exists('voltikaSendSMS')) {
            $smsBody = "Voltika: por favor firma tu contrato de credito aqui: " . $recoveryUrl;
            try {
                $sr = voltikaSendSMS($tel, $smsBody);
                $row['sms_sent'] = !empty($sr['ok']);
            } catch (Throwable $e) {
                error_log('reenviar-firma sms: ' . $e->getMessage());
            }
        }

        // 6. Audit log + seguimiento update
        if ($preapId) {
            try {
                $pdo->prepare("UPDATE preaprobaciones
                    SET seguimiento = 'truora_enviado',
                        notas_admin = TRIM(CONCAT(COALESCE(notas_admin,''),
                            '\n[', NOW(), '] Link de firma (masivo) reenviado por admin#', ?))
                    WHERE id = ?")
                    ->execute([$adminId, $preapId]);
            } catch (Throwable $e) {
                error_log('reenviar-firma update preap: ' . $e->getMessage());
            }
        }

        $summary['ok']++;
    } catch (Throwable $e) {
        $row['error'] = 'internal_error: ' . $e->getMessage();
        $summary['errores']++;
    }

    $results[] = $row;
}

adminLog('credito_firmas_masivo_reenvio', [
    'count'   => count($ids),
    'summary' => $summary,
]);

adminJsonOut([
    'ok'      => true,
    'results' => $results,
    'summary' => $summary,
]);
