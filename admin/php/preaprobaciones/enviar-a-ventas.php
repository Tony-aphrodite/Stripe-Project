<?php
/**
 * Voltika — admin manual-review action: promote a fully-verified
 * preaprobacion to the Ventas queue and email the customer a payment link.
 *
 * Customer brief 2026-05-02: when CDC = real AND Truora = approved AND
 * CURP matches, the application is ready for payment. The admin clicks
 * "Enviar a Ventas para cobro" which:
 *   1. Validates all three signals are green
 *   2. Inserts a 'pendiente' row into transacciones (so it shows up in
 *      Ventas/Ordenes and Pagos pipelines)
 *   3. Sends the customer a payment link via email + SMS
 *   4. Updates seguimiento='enviado_a_ventas' on the preaprobacion
 *
 * POST /admin/php/preaprobaciones/enviar-a-ventas.php
 * Body: { "id": <preaprobacion_id> }
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
    // ── Load preaprobacion + linked Truora row ──────────────────────────
    $stmt = $pdo->prepare("SELECT p.*,
                                  vi.truora_process_id, vi.truora_status,
                                  vi.curp_match, vi.approved AS truora_approved
                             FROM preaprobaciones p
                             LEFT JOIN verificaciones_identidad vi ON vi.id = (
                                 SELECT vi2.id FROM verificaciones_identidad vi2
                                  WHERE (vi2.telefono <> '' AND vi2.telefono = p.telefono)
                                     OR (vi2.email    <> '' AND vi2.email    = p.email)
                                  ORDER BY vi2.id DESC LIMIT 1
                             )
                             WHERE p.id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        adminJsonOut(['ok' => false, 'error' => 'preaprobacion_not_found'], 404);
    }

    // ── Gate: require all three green ───────────────────────────────────
    $cdcReal      = (string)($row['circulo_source'] ?? '') === 'real';
    $truoraOk     = (int)($row['truora_approved'] ?? 0) === 1;
    $curpMatch    = (int)($row['curp_match'] ?? 0) === 1;

    if (!$cdcReal || !$truoraOk || !$curpMatch) {
        adminJsonOut([
            'ok' => false,
            'error' => 'verificaciones_incompletas',
            'detail' => [
                'cdc_real'   => $cdcReal,
                'truora_ok'  => $truoraOk,
                'curp_match' => $curpMatch,
            ],
            'message' => 'Esta solicitud aún no tiene las 3 verificaciones (CDC + CURP + Truora) en verde.',
        ], 400);
    }

    $email   = trim((string)($row['email']    ?? ''));
    $tel     = trim((string)($row['telefono'] ?? ''));
    $nombre  = trim(
        ($row['nombre']           ?? '') . ' ' .
        ($row['apellido_paterno'] ?? '') . ' ' .
        ($row['apellido_materno'] ?? '')
    );
    $modelo  = (string)($row['modelo'] ?? '');
    $monto   = (float)($row['precio_contado'] ?? 0);

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        adminJsonOut(['ok' => false, 'error' => 'email_invalido'], 400);
    }

    // ── Insert a 'pendiente' transacciones row for the Ventas pipeline ─
    // Same shape that create-payment-intent.php's pending helper produces,
    // so the row appears in Ventas/Ordenes + Pagos exactly like a normal
    // checkout-stage abandonment.
    try {
        $pdo->exec("ALTER TABLE transacciones ADD UNIQUE INDEX uniq_stripe_pi (stripe_pi)");
    } catch (Throwable $e) {}
    // Customer brief 2026-05-06 — every transacción created from a
    // preaprobacion must carry the predecessor id so the Ventas Ver
    // modal can show the originating request. Idempotent ALTER.
    try { $pdo->exec("ALTER TABLE transacciones ADD COLUMN preaprobacion_id INT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE transacciones ADD INDEX idx_preap (preaprobacion_id)"); } catch (Throwable $e) {}
    // Use a synthetic stripe_pi placeholder so the UNIQUE constraint is
    // honored. Real Stripe PI takes its place when the customer actually
    // pays via the link (create-payment-intent.php replaces this row).
    $synthPi = 'manual-' . $id . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

    $insStmt = $pdo->prepare("INSERT IGNORE INTO transacciones
            (nombre, email, telefono, modelo, color, ciudad, estado, cp,
             tpago, precio, total, freg, stripe_pi, msi_meses, pago_estado, environment,
             notas_admin, preaprobacion_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 0, 'pendiente', ?, ?, ?)");
    $insStmt->execute([
        $nombre,
        $email,
        $tel,
        $modelo,
        $row['color'] ?? '',
        $row['ciudad'] ?? '',
        $row['estado'] ?? '',
        $row['cp'] ?? '',
        'credito',                   // tpago
        $monto,                      // precio
        $monto,                      // total
        $synthPi,
        defined('APP_ENV') ? APP_ENV : 'test',
        'Promovido manualmente desde Preaprobacion #' . $id,
        $id,                         // preaprobacion_id
    ]);
    $newTxnId = (int)$pdo->lastInsertId();

    // ── Build the customer-facing recovery URL pointing at the payment step ─
    // Same HMAC scheme used by recover-truora.php — but with action=pago.
    $recoverSecret = defined('VOLTIKA_RECOVER_SECRET')
        ? VOLTIKA_RECOVER_SECRET
        : (getenv('VOLTIKA_RECOVER_SECRET') ?: 'voltika_recover_2026_default');
    $expires = time() + (7 * 24 * 3600);
    $payload = $id . '.' . $expires . '.pago';
    $sig     = hash_hmac('sha256', $payload, $recoverSecret);
    $token   = $payload . '.' . $sig;

    $base = defined('VOLTIKA_BASE_URL') ? rtrim(VOLTIKA_BASE_URL, '/') : 'https://www.voltika.mx';
    $payUrl = $base . '/configurador/recover-truora.php?t=' . urlencode($token);

    // ── Send payment-link email ─────────────────────────────────────────
    $emailSent = false;
    if (function_exists('sendMail')) {
        $modeloHtml = htmlspecialchars($modelo);
        $name       = htmlspecialchars($nombre ?: 'Cliente Voltika');
        $montoHtml  = '$' . number_format($monto, 0, '.', ',') . ' MXN';

        $subject = 'Voltika — Tu solicitud de crédito está aprobada, completa el pago';
        $html =
            '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:560px;margin:auto;padding:24px;background:#fff;">' .
            '<div style="text-align:center;background:#1a3a5c;padding:18px;border-radius:12px 12px 0 0;">' .
            '<img src="' . $base . '/configurador/img/logo_w.png" alt="Voltika" width="110" style="height:34px;width:auto;border:0;outline:0;">' .
            '</div>' .
            '<div style="background:#F8FAFC;padding:24px;border-radius:0 0 12px 12px;border:1px solid #E5E7EB;border-top:none;">' .
            '<h1 style="font-size:22px;color:#1a3a5c;margin:0 0 12px;">¡Felicidades, ' . $name . '!</h1>' .
            '<p style="font-size:15px;color:#333;line-height:1.5;margin:0 0 14px;">' .
                'Tu identidad y crédito ya están <strong>aprobados</strong>. Solo falta completar tu pago para apartar tu Voltika.' .
            '</p>' .
            '<div style="background:#fff;border-radius:10px;padding:16px;margin:12px 0;border:1px solid #E5E7EB;">' .
                '<div style="font-size:13px;color:#888;margin-bottom:4px;">Tu Voltika</div>' .
                '<div style="font-size:18px;font-weight:800;color:#333;margin-bottom:8px;">' . $modeloHtml . '</div>' .
                '<div style="font-size:13px;color:#888;margin-bottom:4px;">Precio</div>' .
                '<div style="font-size:24px;font-weight:900;color:#039fe1;">' . $montoHtml . '</div>' .
            '</div>' .
            '<a href="' . htmlspecialchars($payUrl) . '" style="display:block;text-align:center;padding:14px;background:#039fe1;color:#fff;border-radius:10px;font-size:15px;font-weight:800;text-decoration:none;margin:16px 0;">Continuar al pago</a>' .
            '<p style="font-size:13px;color:#666;line-height:1.5;margin:16px 0 0;">' .
                '¿Necesitas ayuda? Llámanos o escríbenos por WhatsApp al ' .
                '<a href="https://wa.me/525513416370" style="color:#039fe1;text-decoration:none;font-weight:700;">+52 55 1341 6370</a>.' .
            '</p>' .
            '<p style="font-size:11px;color:#999;margin:16px 0 0;text-align:center;">Este enlace es personal y expira en 7 días.</p>' .
            '</div>' .
            '</div>';

        try { $emailSent = (bool) @sendMail($email, $nombre, $subject, $html); }
        catch (Throwable $e) { error_log('enviar-a-ventas email: ' . $e->getMessage()); }
    }

    // ── Send SMS ────────────────────────────────────────────────────────
    $smsSent = false;
    if ($tel !== '' && function_exists('voltikaSendSMS')) {
        $smsBody = "Voltika: tu credito esta aprobado. Completa el pago aqui: " . $payUrl;
        try {
            $r = voltikaSendSMS($tel, $smsBody);
            $smsSent = !empty($r['ok']);
        } catch (Throwable $e) { error_log('enviar-a-ventas sms: ' . $e->getMessage()); }
    }

    // ── Update seguimiento + audit ──────────────────────────────────────
    try {
        $pdo->prepare("UPDATE preaprobaciones
            SET seguimiento = 'enviado_a_ventas',
                notas_admin = TRIM(CONCAT(COALESCE(notas_admin,''), '\n[', NOW(), '] Enviado a Ventas (txn#' , ?, ') por admin#', ?))
            WHERE id = ?")
            ->execute([$newTxnId, $adminId, $id]);
    } catch (Throwable $e) { error_log('enviar-a-ventas update: ' . $e->getMessage()); }

    adminLog('preaprobacion_enviada_a_ventas', [
        'preaprobacion_id'  => $id,
        'transaccion_id'    => $newTxnId,
        'email_sent'        => $emailSent,
        'sms_sent'          => $smsSent,
    ]);

    adminJsonOut([
        'ok'                => true,
        'transaccion_id'    => $newTxnId,
        'email_sent'        => $emailSent,
        'sms_sent'          => $smsSent,
        'recovery_url'      => $payUrl,
        'message'           => 'Solicitud enviada a Ventas. Cliente recibió enlace de pago.',
    ]);
} catch (Throwable $e) {
    error_log('enviar-a-ventas fatal: ' . $e->getMessage());
    adminJsonOut(['ok' => false, 'error' => 'internal_error', 'detail' => $e->getMessage()], 500);
}
