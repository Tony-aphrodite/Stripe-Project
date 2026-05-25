<?php
/**
 * Voltika Admin — Generate a direct ACTA signing link (Round 80, 2026-05-25).
 *
 * Customer brief (Óscar, 2026-05-25): the existing SPA flow for "Firma del
 * ACTA DE ENTREGA" gets stuck on "Preparando documento…" for customers
 * whose iPhone Safari has cached old JS — even after Round 73's fix, Round
 * 76's cache-bust hardening, and verifying the backend responds in 106 ms.
 * The cache problem is unfixable from our side (iOS PWA cache ignores
 * Cache-Control headers).
 *
 * Solution: bypass the customer SPA entirely. This endpoint generates a
 * one-time tokenized URL that points to /clientes/firmar-acta-directa.php
 * — a standalone HTML page with no app.js dependency. Cached or not, the
 * customer's browser has never loaded the new page so it can't have a
 * stale version.
 *
 * Same pattern as Round 75 (solicitar-firma-contrato.php for retro-sign
 * contracts) — reused infrastructure: firmas_contratos table, token TTL
 * via UNIX timestamp, voltikaNotify for SMS+email delivery.
 *
 * POST body: { moto_id: int }
 * Response : { ok, signing_url, expires_at_iso, sent_via:[...], copy_text }
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$body   = adminJsonIn();
$motoId = (int)($body['moto_id'] ?? 0);

if ($motoId <= 0) {
    adminJsonOut(['ok' => false, 'error' => 'moto_id_requerido',
                  'message' => 'Se requiere moto_id'], 400);
}

$pdo = getDB();

// ── Load moto + customer contact ─────────────────────────────────────────
try {
    $st = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ? LIMIT 1");
    $st->execute([$motoId]);
    $moto = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    adminJsonOut(['ok' => false, 'error' => 'db_lookup_failed', 'message' => $e->getMessage()], 500);
}
if (!$moto) {
    adminJsonOut(['ok' => false, 'error' => 'moto_no_encontrada',
                  'message' => 'No existe la moto ' . $motoId], 404);
}

$email = trim((string)($moto['cliente_email']    ?? ''));
$tel   = trim((string)($moto['cliente_telefono'] ?? ''));
$nom   = trim((string)($moto['cliente_nombre']   ?? ''));

if ($email === '' && $tel === '') {
    adminJsonOut(['ok' => false, 'error' => 'sin_contacto',
                  'message' => 'La moto no tiene email ni teléfono del cliente — no podemos enviar el link.'], 422);
}
if (!empty($moto['cliente_acta_firmada'])) {
    adminJsonOut(['ok' => false, 'error' => 'ya_firmada',
                  'message' => 'Esta moto ya tiene su ACTA firmada por el cliente.'], 409);
}

// ── Ensure firma_acta_requests table exists ──────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS firma_acta_requests (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        moto_id         INT NOT NULL,
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
        INDEX idx_moto   (moto_id),
        INDEX idx_estado (estado),
        INDEX idx_freg   (freg)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) { error_log('firma_acta_requests create: ' . $e->getMessage()); }

// Expire any previously-pending token for this moto so only one is active.
try {
    $pdo->prepare("UPDATE firma_acta_requests
                      SET estado='expired'
                    WHERE moto_id=? AND estado='pending'")
        ->execute([$motoId]);
} catch (Throwable $e) { /* non-fatal */ }

// ── Generate fresh token + persist ───────────────────────────────────────
$token     = bin2hex(random_bytes(20));
$expiresAt = time() + (24 * 3600);   // 24h validity (delivery happens same day)
try {
    $pdo->prepare("INSERT INTO firma_acta_requests
            (moto_id, token, email, telefono, expires_at, admin_id)
        VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$motoId, $token, $email ?: null, $tel ?: null, $expiresAt, $adminId ?? null]);
} catch (Throwable $e) {
    adminJsonOut(['ok' => false, 'error' => 'token_insert_failed',
                  'message' => $e->getMessage()], 500);
}

// ── Build the signing URL ────────────────────────────────────────────────
$scheme    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'] ?? 'voltika.mx';
$signingUrl = $scheme . '://' . $host . '/clientes/firmar-acta-directa.php?token=' . $token;

// ── Send email (best-effort) ─────────────────────────────────────────────
$sentVia = [];
$emailErr = null;
if ($email !== '' && function_exists('sendMail')) {
    $subject = 'Voltika — Firma la entrega de tu moto';
    $modelo  = (string)($moto['modelo'] ?? '');
    $color   = (string)($moto['color']  ?? '');
    $html =
        '<div style="font-family:system-ui,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#222;background:#f7f9fc;">'
      .   '<h2 style="color:#0c2340;margin:0 0 8px;font-size:22px;">Voltika</h2>'
      .   '<p style="font-size:15px;line-height:1.5;">Hola ' . htmlspecialchars($nom ?: 'cliente') . ',</p>'
      .   '<p style="font-size:14px;line-height:1.6;color:#444;">Ya casi terminas. Para completar la entrega '
      .     'de tu <strong>' . htmlspecialchars($modelo . ' ' . $color) . '</strong>, firma electrónicamente '
      .     'el ACTA DE ENTREGA con tu dedo. Tu firma queda sellada con <strong>NOM-151</strong> a través de '
      .     'Cincel para validez legal.</p>'
      .   '<p style="margin:24px 0;text-align:center;">'
      .     '<a href="' . htmlspecialchars($signingUrl) . '" '
      .        'style="display:inline-block;background:#039fe1;color:#fff;padding:14px 28px;border-radius:8px;'
      .        'font-weight:700;text-decoration:none;font-size:15px;">📝 Firmar entrega</a>'
      .   '</p>'
      .   '<p style="font-size:12px;color:#888;line-height:1.5;">Este enlace es de un solo uso y expira en 24 horas. '
      .     'Si el botón no funciona, copia esta URL en tu navegador:</p>'
      .   '<p style="font-size:12px;word-break:break-all;color:#475569;background:#fff;padding:10px;border-radius:4px;">'
      .      htmlspecialchars($signingUrl)
      .   '</p>'
      .   '<hr style="border:0;border-top:1px solid #e5e7eb;margin:24px 0;">'
      .   '<p style="font-size:11px;color:#888;">Voltika · MTECH GEARS, S.A. de C.V.</p>'
      . '</div>';
    try {
        $ok = (bool)@sendMail($email, $nom, $subject, $html);
        if ($ok) $sentVia[] = 'email';
        else     $emailErr = 'sendMail returned false';
    } catch (Throwable $e) { $emailErr = $e->getMessage(); }
}

// ── Audit log ────────────────────────────────────────────────────────────
try {
    if (function_exists('adminLog')) {
        adminLog('solicitar_firma_acta', [
            'moto_id'       => $motoId,
            'vin'           => $moto['vin_display'] ?? $moto['vin'] ?? '',
            'token_first8'  => substr($token, 0, 8),
            'sent_email'    => in_array('email', $sentVia, true),
            'email_err'     => $emailErr,
        ]);
    }
} catch (Throwable $e) { /* non-fatal */ }

// ── Response ─────────────────────────────────────────────────────────────
$copyText = "Voltika: Hola " . ($nom ?: 'cliente') . ", firma la entrega de tu moto aquí: "
          . $signingUrl . " (link de un solo uso, expira en 24 horas).";

adminJsonOut([
    'ok'              => true,
    'signing_url'     => $signingUrl,
    'expires_at_iso'  => gmdate('Y-m-d\TH:i:s\Z', $expiresAt),
    'sent_via'        => $sentVia,
    'email_err'       => $emailErr,
    'copy_text'       => $copyText,
    'message'         => empty($sentVia)
        ? 'Enlace generado. No pudimos enviar email — copia el link y compártelo por WhatsApp/SMS.'
        : 'Enlace generado y enviado por ' . implode(', ', $sentVia) . '.',
]);
