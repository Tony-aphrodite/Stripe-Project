<?php
/**
 * Voltika Admin — Solicitar firma autógrafa retroactiva (Round 75, 2026-05-25).
 *
 * Customer brief (Óscar): "Is there any way to resend the signature
 * request to the client to sign the contract again and have the document
 * with the handwritten signature?"
 *
 * Context: contracts signed before 2026-05-23 (the Round 70 deployment
 * date) don't have the embedded autograph image — the signature canvas
 * didn't exist in the checkout flow yet. This endpoint lets the admin
 * trigger a one-time signing link that lets the customer add their
 * handwritten signature retroactively, after which we regenerate the
 * contract PDF with the autograph embedded and apply a fresh Cincel
 * NOM-151 timestamp.
 *
 * Flow:
 *   1. Admin POSTs { transaccion_id }
 *   2. We generate a 40-char random token, persist it in a new table
 *      `firma_contrato_requests`, and send the signing link by email
 *      (SMSmasivos is currently 401'd so SMS is skipped; admin can also
 *      copy the URL and forward via WhatsApp manually).
 *   3. The customer opens /clientes/firmar-contrato-retro.php?token=XXX
 *      and signs. That page calls firmar-contrato-retro-guardar.php to
 *      persist the signature, regenerate the PDF, and stamp NOM-151.
 *
 * Token lifecycle: pending (48h) → signed (terminal) | expired.
 *
 * POST body: { transaccion_id: int }
 * Response : { ok, signing_url, expires_at_iso, sent_via:[...],
 *              copy_text (for WhatsApp paste) }
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$body  = adminJsonIn();
$txnId = (int)($body['transaccion_id'] ?? 0);

if ($txnId <= 0) {
    adminJsonOut(['ok' => false, 'error' => 'transaccion_id_requerido',
                  'message' => 'Se requiere transaccion_id'], 400);
}

$pdo = getDB();

// ── Step 1: load the transaccion + customer contact ─────────────────────
$txn = null;
try {
    $st = $pdo->prepare("SELECT id, pedido, email, telefono, nombre, modelo,
                                contrato_pdf_path, contrato_pdf_hash,
                                contrato_aceptado_at
                         FROM transacciones WHERE id = ? LIMIT 1");
    $st->execute([$txnId]);
    $txn = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    adminJsonOut(['ok' => false, 'error' => 'db_lookup_failed',
                  'message' => $e->getMessage()], 500);
}
if (!$txn) {
    adminJsonOut(['ok' => false, 'error' => 'transaccion_no_encontrada',
                  'message' => 'No existe la transacción ' . $txnId], 404);
}

$email = trim((string)($txn['email']    ?? ''));
$tel   = trim((string)($txn['telefono'] ?? ''));
$nom   = trim((string)($txn['nombre']   ?? ''));

if ($email === '' && $tel === '') {
    adminJsonOut(['ok' => false, 'error' => 'sin_contacto',
                  'message' => 'La transacción no tiene email ni teléfono — no podemos enviar el enlace.'], 422);
}

// ── Step 2: ensure the requests table exists ────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS firma_contrato_requests (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        transaccion_id  INT NOT NULL,
        token           CHAR(40) NOT NULL UNIQUE,
        email           VARCHAR(200) NULL,
        telefono        VARCHAR(30) NULL,
        estado          ENUM('pending','signed','expired') NOT NULL DEFAULT 'pending',
        expires_at      INT NOT NULL,
        signed_at       DATETIME NULL,
        signed_firma_id INT NULL,
        ip              VARCHAR(45) NULL,
        user_agent      VARCHAR(500) NULL,
        admin_id        INT NULL,
        freg            DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_txn   (transaccion_id),
        INDEX idx_estado(estado),
        INDEX idx_freg  (freg)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    error_log('firma_contrato_requests create: ' . $e->getMessage());
}

// ── Step 3: invalidate any previous pending tokens for this txn ─────────
// Customer might have lost the first link or the admin re-clicked. Only
// one pending token at a time per transaccion keeps the audit trail clean.
try {
    $pdo->prepare("UPDATE firma_contrato_requests
                      SET estado = 'expired'
                    WHERE transaccion_id = ? AND estado = 'pending'")
        ->execute([$txnId]);
} catch (Throwable $e) { /* non-fatal */ }

// ── Step 4: generate fresh token + persist ──────────────────────────────
$token     = bin2hex(random_bytes(20));      // 40 hex chars
$expiresAt = time() + (48 * 3600);           // 48h validity
try {
    $pdo->prepare("INSERT INTO firma_contrato_requests
            (transaccion_id, token, email, telefono, expires_at, admin_id)
        VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$txnId, $token, $email ?: null, $tel ?: null, $expiresAt, $adminId ?? null]);
} catch (Throwable $e) {
    adminJsonOut(['ok' => false, 'error' => 'token_insert_failed',
                  'message' => $e->getMessage()], 500);
}

// ── Step 5: build the signing URL ───────────────────────────────────────
$scheme    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'] ?? 'voltika.mx';
$signingUrl = $scheme . '://' . $host . '/clientes/firmar-contrato-retro.php?token=' . $token;

// ── Step 6: send email (best-effort) ────────────────────────────────────
$sentVia = [];
$emailErr = null;
if ($email !== '' && function_exists('sendMail')) {
    $subject = 'Voltika — Confirma tu contrato con tu firma';
    $html =
        '<div style="font-family:system-ui,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#222;background:#f7f9fc;">'
      .   '<h2 style="color:#0c2340;margin:0 0 8px;font-size:22px;">Voltika</h2>'
      .   '<p style="font-size:15px;line-height:1.5;">Hola ' . htmlspecialchars($nom ?: 'cliente') . ',</p>'
      .   '<p style="font-size:14px;line-height:1.6;color:#444;">Para completar la documentación de tu compra en Voltika, '
      .     'te pedimos firmar electrónicamente tu contrato. Es un proceso de <strong>menos de 1 minuto</strong> desde tu celular: '
      .     'sólo necesitas tocar el botón de abajo y dibujar tu firma con el dedo. Tu firma quedará sellada con '
      .     'NOM-151 a través de Cincel para validez legal.</p>'
      .   '<p style="margin:24px 0;text-align:center;">'
      .     '<a href="' . htmlspecialchars($signingUrl) . '" '
      .        'style="display:inline-block;background:#039fe1;color:#fff;padding:14px 28px;border-radius:8px;'
      .        'font-weight:700;text-decoration:none;font-size:15px;">📝 Firmar mi contrato</a>'
      .   '</p>'
      .   '<p style="font-size:12px;color:#888;line-height:1.5;">Este enlace es de un solo uso y expira en 48 horas. '
      .     'Si el botón no funciona, copia esta URL en tu navegador:</p>'
      .   '<p style="font-size:12px;word-break:break-all;color:#475569;background:#fff;padding:10px;border-radius:4px;">'
      .      htmlspecialchars($signingUrl)
      .   '</p>'
      .   '<hr style="border:0;border-top:1px solid #e5e7eb;margin:24px 0;">'
      .   '<p style="font-size:11px;color:#888;">Voltika · MTECH GEARS, S.A. de C.V. Si no esperabas este mensaje, ignóralo — '
      .     'el enlace expira sólo y no tiene ningún efecto si nadie lo usa.</p>'
      . '</div>';
    try {
        $ok = (bool)@sendMail($email, $nom, $subject, $html);
        if ($ok) $sentVia[] = 'email';
        else     $emailErr = 'sendMail returned false';
    } catch (Throwable $e) {
        $emailErr = $e->getMessage();
    }
}

// ── Step 7: audit log ────────────────────────────────────────────────────
try {
    if (function_exists('adminLog')) {
        adminLog('solicitar_firma_contrato', [
            'transaccion_id' => $txnId,
            'pedido'         => $txn['pedido'] ?? null,
            'token_first8'   => substr($token, 0, 8),
            'sent_email'     => in_array('email', $sentVia, true),
            'email_err'      => $emailErr,
        ]);
    }
} catch (Throwable $e) { /* non-fatal */ }

// ── Step 8: respond ──────────────────────────────────────────────────────
// The admin UI shows the signing_url in a modal so it can be copy-pasted
// into WhatsApp / Telegram (SMSmasivos is currently 401'd; email is the
// only auto-channel). The `copy_text` field is a ready-to-paste message.
$copyText = "Voltika: Hola " . ($nom ?: 'cliente') . ", por favor firma tu contrato aquí: "
          . $signingUrl . " (el enlace expira en 48 horas).";

adminJsonOut([
    'ok'              => true,
    'signing_url'     => $signingUrl,
    'expires_at_iso'  => gmdate('Y-m-d\TH:i:s\Z', $expiresAt),
    'sent_via'        => $sentVia,
    'email_err'       => $emailErr,
    'copy_text'       => $copyText,
    'message'         => empty($sentVia)
        ? 'Enlace generado. No pudimos enviar email — copia el enlace y compártelo por WhatsApp.'
        : 'Enlace generado y enviado por ' . implode(', ', $sentVia) . '.',
]);
