<?php
/**
 * Voltika — Manual override: send personalised credit offer to applicant.
 *
 * Customer brief 2026-05-04: reviewer wants tactical control over
 * enganche % and plazo for CONDICIONAL/PREAPROBADO cases. This endpoint:
 *   1. Persists the override + original (audit trail) on preaprobaciones
 *   2. Generates an HMAC-signed 48h link
 *   3. Emails + SMS the customer
 *   4. Sets seguimiento='oferta_personalizada' so the listing reflects the
 *      reviewer's decision
 *
 * The link target is /configurador/recover-aprobado.php?t=<token>, which
 * shows a "your offer" landing page with the locked terms and bounces
 * the user into the existing Truora flow when they click Continuar.
 *
 * POST /admin/php/preaprobaciones/enviar-oferta-personalizada.php
 * Body: {
 *   id: <preap_id>,
 *   enganche_pct: 0.30,            // override (0.25 .. 0.80)
 *   plazo_meses:  18,              // override (12|18|24|36)
 *   original_enganche: 0.25,       // system suggestion (audit)
 *   original_plazo: 24             // system suggestion (audit)
 * }
 *
 * Response: { ok, link, email_sent, sms_sent }
 */

require_once __DIR__ . '/../bootstrap.php';
$adminId = adminRequireAuth(['admin']);

$body = adminJsonIn();
$id        = (int)($body['id'] ?? 0);
$engPct    = (float)($body['enganche_pct'] ?? 0);
$plazoM    = (int)($body['plazo_meses']    ?? 0);
$origEng   = (float)($body['original_enganche'] ?? 0);
$origPlazo = (int)($body['original_plazo']    ?? 0);
// preview=1 → don't update DB, don't send email/SMS, just return the
// signed link so the admin can walk through the customer landing in a
// new tab to verify the override looks right before committing.
$preview   = !empty($body['preview']);

if ($id <= 0)                              adminJsonOut(['ok' => false, 'error' => 'id_required'], 400);
if ($engPct < 0.25 || $engPct > 0.80)      adminJsonOut(['ok' => false, 'error' => 'enganche_pct fuera de rango (0.25-0.80)'], 400);
if (!in_array($plazoM, [12, 18, 24, 36], true))
    adminJsonOut(['ok' => false, 'error' => 'plazo_meses inválido (12|18|24|36)'], 400);

$pdo = getDB();

try {
    // ── Load applicant + ensure override columns exist ─────────────────
    foreach ([
        'manual_override'          => 'TINYINT(1) NOT NULL DEFAULT 0',
        'original_enganche'        => 'DECIMAL(5,4) NULL',
        'original_plazo'           => 'INT NULL',
        'revisor_user_id'          => 'INT NULL',
        'timestamp_decision'       => 'DATETIME NULL',
        'oferta_link_expires_at'   => 'DATETIME NULL',
    ] as $col => $def) {
        try { $pdo->exec("ALTER TABLE preaprobaciones ADD COLUMN `$col` $def"); }
        catch (Throwable $e) {}
    }

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
    $modelo  = (string)($row['modelo'] ?? '');
    $precio  = (float)($row['precio_contado'] ?? 0);
    if ($precio <= 0) adminJsonOut(['ok' => false, 'error' => 'precio_contado faltante en preaprobacion'], 400);

    // ── Save override + audit trail (skipped in preview mode) ──────────
    // In preview, the admin is just verifying the customer landing page
    // — don't mutate preaprobaciones, don't lock terms, don't email.
    // The override values travel inside the signed token instead, so
    // the landing page can render them without a DB read.
    $expiresAt = date('Y-m-d H:i:s', time() + 48 * 3600);
    $note = sprintf(
        "[%s] Override manual por admin#%d: %d%% enganche / %d meses (sistema sugería %d%% / %d)",
        date('Y-m-d H:i'), (int)$adminId,
        (int)round($engPct * 100), $plazoM,
        (int)round($origEng * 100), $origPlazo
    );
    $newNotas = trim(($row['notas_admin'] ? $row['notas_admin'] . "\n" : '') . $note);

    if (!$preview) {
        $upd = $pdo->prepare("UPDATE preaprobaciones SET
            enganche_requerido = ?,
            enganche_pct       = ?,
            plazo_max          = ?,
            plazo_meses        = ?,
            manual_override    = 1,
            original_enganche  = ?,
            original_plazo     = ?,
            revisor_user_id    = ?,
            timestamp_decision = NOW(),
            oferta_link_expires_at = ?,
            seguimiento        = 'oferta_personalizada',
            notas_admin        = ?
            WHERE id = ?");
        $upd->execute([
            $engPct, $engPct * 100, $plazoM, $plazoM,
            $origEng, $origPlazo,
            $adminId,
            $expiresAt,
            $newNotas,
            $id,
        ]);
    }

    // ── Build HMAC-signed link ─────────────────────────────────────────
    // Two token shapes:
    //   • normal  (4 parts): id.expires.aprobado.hmac           → 48h, reads override from DB
    //   • preview (6 parts): id.expires.preview.engX100.plazo.hmac → 1h, uses embedded values
    // The preview window is short (1h) on purpose so a leaked test URL
    // doesn't survive long enough to be useful, and we never persist
    // anything DB-side that a real customer might subsequently consume.
    $recoverSecret = defined('VOLTIKA_RECOVER_SECRET')
        ? VOLTIKA_RECOVER_SECRET
        : (getenv('VOLTIKA_RECOVER_SECRET') ?: 'voltika_recover_2026_default');

    if ($preview) {
        $expiresEpoch = time() + (1 * 3600);
        $engX100      = (int) round($engPct * 100);
        $payload      = $id . '.' . $expiresEpoch . '.preview.' . $engX100 . '.' . $plazoM;
    } else {
        $expiresEpoch = time() + (48 * 3600);
        $payload      = $id . '.' . $expiresEpoch . '.aprobado';
    }
    $sig   = hash_hmac('sha256', $payload, $recoverSecret);
    $token = $payload . '.' . $sig;

    $base = defined('VOLTIKA_BASE_URL') ? rtrim(VOLTIKA_BASE_URL, '/') : 'https://www.voltika.mx';
    $link = $base . '/configurador/recover-aprobado.php?t=' . urlencode($token);

    // ── Compute payment numbers for the email body ─────────────────────
    // Customer brief 2026-05-06: previous version sent naïve
    // financiado / plazoMeses (no interest), which mismatched the
    // configurador's VkCalculadora.calcular and produced offers the
    // collections (cobranza) system couldn't reconcile. Use the same
    // PMT formula as configurador/js/modules/calculadora-credito.js
    // and configurador/js/data/productos.js (tasaAnual=0.60, 52 weekly
    // pagos, IVA=0.16 on the period rate).
    $enganche   = $precio * $engPct;
    $financiado = $precio - $enganche;

    $tasaAnual            = 0.60;
    $pagosPorAno          = 52;
    $iva                  = 0.16;
    $tasaPeriodoSinIVA    = $tasaAnual / $pagosPorAno;
    $tasaPeriodoConIVA    = $tasaPeriodoSinIVA * (1 + $iva);
    $numeroPagos          = (int) round($plazoM * ($pagosPorAno / 12));

    $semanal = 0.0;
    if ($numeroPagos > 0 && $financiado > 0) {
        if ($tasaPeriodoConIVA <= 0) {
            $semanal = $financiado / $numeroPagos;
        } else {
            $r = $tasaPeriodoConIVA;
            $n = $numeroPagos;
            $semanal = $financiado * $r / (1 - pow(1 + $r, -$n));
        }
    }
    $mensual = $semanal * (52 / 12); // 4.3333…

    // ── Preview short-circuit ──────────────────────────────────────────
    // Return the link only — no email/SMS, no audit log entry, no
    // mutation. The admin opens it in a new tab to walk through the
    // customer landing visually.
    if ($preview) {
        adminJsonOut([
            'ok'      => true,
            'preview' => true,
            'link'    => $link,
            'expires_at' => date('Y-m-d H:i:s', $expiresEpoch),
            'message' => 'Link de prueba generado (válido 1h, sin envío ni guardado).',
        ]);
    }

    // ── Send email ─────────────────────────────────────────────────────
    $emailSent = false;
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && function_exists('sendMail')) {
        $modeloHtml = htmlspecialchars($modelo);
        $name       = htmlspecialchars($nombre ?: 'Cliente Voltika');

        $subject = 'Voltika — Tu solicitud está aprobada con condiciones personalizadas';
        $html =
            '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:560px;margin:auto;padding:24px;background:#fff;">' .
            '<div style="text-align:center;background:#1a3a5c;padding:18px;border-radius:12px 12px 0 0;">' .
            '<img src="' . $base . '/configurador/img/voltika_logo_h_white.svg" alt="Voltika" style="height:34px;">' .
            '</div>' .
            '<div style="background:#F8FAFC;padding:24px;border-radius:0 0 12px 12px;border:1px solid #E5E7EB;border-top:none;">' .
            '<h1 style="font-size:22px;color:#1a3a5c;margin:0 0 12px;">¡Felicidades, ' . $name . '!</h1>' .
            '<p style="font-size:15px;color:#333;line-height:1.5;margin:0 0 14px;">' .
                'Tu solicitud de crédito para tu Voltika ha sido <strong>aprobada</strong> con las siguientes condiciones personalizadas:' .
            '</p>' .
            '<div style="background:#fff;border-radius:10px;padding:16px;margin:14px 0;border:1px solid #E5E7EB;">' .
                '<div style="font-size:13px;color:#888;">Modelo</div>' .
                '<div style="font-size:18px;font-weight:800;color:#333;margin-bottom:10px;">' . $modeloHtml . '</div>' .
                '<table style="width:100%;font-size:14px;border-collapse:collapse;">' .
                    '<tr><td style="padding:6px 0;color:#666;">Enganche</td><td style="text-align:right;font-weight:700;">$' . number_format($enganche,0,'.',',') . ' (' . round($engPct*100) . '%)</td></tr>' .
                    '<tr><td style="padding:6px 0;color:#666;">Plazo</td><td style="text-align:right;font-weight:700;">' . $plazoM . ' meses</td></tr>' .
                    '<tr><td style="padding:6px 0;color:#666;">Pago semanal</td><td style="text-align:right;font-weight:700;color:#039fe1;">$' . number_format($semanal,0,'.',',') . '</td></tr>' .
                    '<tr><td style="padding:6px 0;color:#666;">Pago mensual</td><td style="text-align:right;font-weight:700;color:#039fe1;">$' . number_format($mensual,0,'.',',') . '</td></tr>' .
                '</table>' .
            '</div>' .
            '<a href="' . htmlspecialchars($link) . '" style="display:block;text-align:center;padding:14px;background:#039fe1;color:#fff;border-radius:10px;font-size:15px;font-weight:800;text-decoration:none;margin:16px 0;">Continuar con verificación de identidad</a>' .
            '<p style="font-size:13px;color:#666;line-height:1.5;margin:16px 0 0;">' .
                '¿Necesitas ayuda? Llámanos o escríbenos por WhatsApp al ' .
                '<a href="https://wa.me/525513416370" style="color:#039fe1;text-decoration:none;font-weight:700;">+52 55 1341 6370</a>.' .
            '</p>' .
            '<p style="font-size:11px;color:#999;margin:16px 0 0;text-align:center;">Este enlace es personal y expira en 48 horas. Las condiciones quedan bloqueadas — no se pueden modificar.</p>' .
            '</div>' .
            '</div>';

        try { $emailSent = (bool) @sendMail($email, $nombre, $subject, $html); }
        catch (Throwable $e) { error_log('oferta-personalizada email: ' . $e->getMessage()); }
    }

    // ── Send SMS ───────────────────────────────────────────────────────
    $smsSent = false;
    if ($tel !== '' && function_exists('voltikaSendSMS')) {
        $smsBody = "Voltika: tu credito esta aprobado (" . round($engPct*100) . "% enganche / " . $plazoM . " meses, pago mensual $" . number_format($mensual,0,'.',',') . "). Continua aqui (48h): " . $link;
        try {
            $r = voltikaSendSMS($tel, $smsBody);
            $smsSent = !empty($r['ok']);
        } catch (Throwable $e) { error_log('oferta-personalizada sms: ' . $e->getMessage()); }
    }

    adminLog('preaprobacion_oferta_personalizada', [
        'preaprobacion_id'   => $id,
        'enganche_pct'       => $engPct,
        'plazo_meses'        => $plazoM,
        'original_enganche'  => $origEng,
        'original_plazo'     => $origPlazo,
        'revisor_user_id'    => $adminId,
        'expires_at'         => $expiresAt,
        'email_sent'         => $emailSent,
        'sms_sent'           => $smsSent,
    ]);

    adminJsonOut([
        'ok'         => true,
        'link'       => $link,
        'expires_at' => $expiresAt,
        'email_sent' => $emailSent,
        'sms_sent'   => $smsSent,
        'message'    => 'Oferta personalizada enviada al cliente.',
    ]);
} catch (Throwable $e) {
    error_log('enviar-oferta-personalizada fatal: ' . $e->getMessage());
    adminJsonOut(['ok' => false, 'error' => 'internal_error', 'detail' => $e->getMessage()], 500);
}
