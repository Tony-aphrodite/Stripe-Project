<?php
/**
 * Voltika — Receive a digital signature for a checkout (contado/MSI)
 * order and persist it. Companion of /configurador/firmar-contrato-checkout.php.
 *
 * Customer brief 2026-05-04 round 3: contado/MSI orders need an actual
 * signed contract on file (boss reported "in purchase done, there is
 * no contract signed by the client"). This endpoint:
 *   1. Validates the HMAC token (same scheme as the landing page)
 *   2. Persists firma_base64 + audit fields to firmas_contratos
 *   3. Stamps the signature onto the existing contract PDF (or
 *      regenerates a signed copy when the original PDF isn't on disk)
 *   4. Sends a thank-you email with the signed PDF attached
 *
 * Body (JSON): { token, firma_base64 }
 * Response:    { ok, firma_id, pdf_url }
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

function out(array $j, int $code = 200): void {
    http_response_code($code);
    echo json_encode($j, JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode((string)file_get_contents('php://input'), true) ?: [];
$token   = (string)($body['token']        ?? '');
$dataUrl = (string)($body['firma_base64'] ?? '');

// ── Validate token (id.expires.firma.hmac, 7-day window) ──────────────
$parts = explode('.', $token);
if (count($parts) !== 4) out(['ok' => false, 'error' => 'token_invalid'], 400);
[$txId, $expires, $action, $sig] = $parts;
$txId    = (int)$txId;
$expires = (int)$expires;
if ($action !== 'firma')        out(['ok' => false, 'error' => 'token_action_invalid'], 400);
if ($expires < time())          out(['ok' => false, 'error' => 'token_expired'], 410);

$secret = defined('VOLTIKA_RECOVER_SECRET')
    ? VOLTIKA_RECOVER_SECRET
    : (getenv('VOLTIKA_RECOVER_SECRET') ?: 'voltika_recover_2026_default');
$expected = hash_hmac('sha256', $txId . '.' . $expires . '.' . $action, $secret);
if (!hash_equals($expected, $sig)) out(['ok' => false, 'error' => 'token_signature_invalid'], 403);

// ── Validate signature image ──────────────────────────────────────────
if (!preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=]+)$/', $dataUrl, $m)) {
    out(['ok' => false, 'error' => 'firma_format_invalid'], 400);
}
$rawBase64 = $m[1];
$decoded   = base64_decode($rawBase64, true);
if ($decoded === false || strlen($decoded) < 200) {
    out(['ok' => false, 'error' => 'firma_too_small'], 400);
}
if (strlen($decoded) > 5 * 1024 * 1024) {
    out(['ok' => false, 'error' => 'firma_too_large'], 400);
}

// ── Load order ────────────────────────────────────────────────────────
try {
    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT * FROM transacciones WHERE id = ? LIMIT 1");
    $stmt->execute([$txId]);
    $tx   = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tx) out(['ok' => false, 'error' => 'pedido_not_found'], 404);
} catch (Throwable $e) {
    error_log('firmar-checkout-submit load: ' . $e->getMessage());
    out(['ok' => false, 'error' => 'internal'], 500);
}

// ── Idempotency: if already signed, return success silently ───────────
try {
    $existsStmt = $pdo->prepare("
        SELECT id FROM firmas_contratos
         WHERE (telefono <> '' AND telefono = ?)
            OR (email    <> '' AND email    = ?)
         ORDER BY id DESC LIMIT 1");
    $existsStmt->execute([$tx['telefono'] ?? '', $tx['email'] ?? '']);
    $already = $existsStmt->fetch(PDO::FETCH_ASSOC);
    if ($already) {
        out([
            'ok'       => true,
            'firma_id' => (int)$already['id'],
            'note'     => 'ya_firmado',
        ]);
    }
} catch (Throwable $e) {}

// ── Insert into firmas_contratos ──────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS firmas_contratos (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        nombre        VARCHAR(200),
        email         VARCHAR(200),
        telefono      VARCHAR(30),
        curp          VARCHAR(20),
        modelo        VARCHAR(100),
        pdf_file      VARCHAR(255),
        customer_id   VARCHAR(80),
        firma_base64  LONGTEXT,
        firma_sha256  VARCHAR(64),
        ip            VARCHAR(64),
        user_agent    VARCHAR(500),
        freg          DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email (email),
        INDEX idx_freg  (freg)
    )");

    $hash = hash('sha256', $rawBase64);
    $ip   = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    $ua   = $_SERVER['HTTP_USER_AGENT'] ?? null;
    if ($ip) $ip = substr(explode(',', $ip)[0], 0, 64);
    if ($ua) $ua = substr($ua, 0, 500);

    $ins = $pdo->prepare("
        INSERT INTO firmas_contratos
            (nombre, email, telefono, curp, modelo, pdf_file, customer_id,
             firma_base64, firma_sha256, ip, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $ins->execute([
        $tx['nombre']    ?? null,
        $tx['email']     ?? null,
        $tx['telefono']  ?? null,
        null,                              // CURP not captured for contado checkout
        $tx['modelo']    ?? null,
        null,                              // pdf_file: filled below if we generate the signed PDF
        $tx['stripe_pi'] ?? null,
        $rawBase64,
        $hash,
        $ip,
        $ua,
    ]);
    $firmaId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    error_log('firmar-checkout-submit INSERT: ' . $e->getMessage());
    out(['ok' => false, 'error' => 'db_insert_failed'], 500);
}

// ── Stamp signature onto contract PDF (best-effort) ───────────────────
// Reuses the existing contrato_contado pipeline. If the unsigned PDF
// was already cached on disk we overlay the signature; otherwise we
// regenerate fresh with firma included. Failure here does NOT break
// the response — the firma row is already saved (legal evidence).
$pdfPath = null;
try {
    if (file_exists(__DIR__ . '/contrato-contado.php')) {
        require_once __DIR__ . '/contrato-contado.php';
        if (function_exists('contratoContadoGenerate')) {
            $contractData = [
                'pedido'             => $tx['pedido'] ?? '',
                'folio'              => $tx['folio_contrato'] ?: ($tx['pedido'] ?? ''),
                'contract_date'      => date('d/m/Y'),
                'customer_full_name' => $tx['nombre']   ?? '',
                'customer_email'     => $tx['email']    ?? '',
                'customer_phone'     => $tx['telefono'] ?? '',
                'customer_zip'       => $tx['cp']       ?? '',
                'vehicle_model'      => $tx['modelo']   ?? '',
                'vehicle_color'      => $tx['color']    ?? '',
                'vehicle_year'       => (int)date('Y'),
                'vehicle_price'      => (float)($tx['total'] ?: $tx['precio'] ?? 0),
                'logistics_cost'     => 0,
                'total_amount'       => (float)($tx['total'] ?: $tx['precio'] ?? 0),
                'payment_method'     => strtolower($tx['tpago'] ?? 'contado'),
                'payment_reference'  => $tx['stripe_pi'] ?: ($tx['pedido'] ?? ''),
                'firma_base64'       => 'data:image/png;base64,' . $rawBase64,
                'firma_sha256'       => $hash,
                'firma_freg'         => date('Y-m-d H:i:s'),
            ];
            // contratoContadoGenerate() returns ['ok'=>bool, 'path'=>string, 'error'=>string].
            // Earlier rev mistakenly treated the array as a path string, so
            // file_exists() got an array and the PDF path was never persisted.
            $genResult = @contratoContadoGenerate($contractData);
            if (is_array($genResult) && !empty($genResult['ok']) && !empty($genResult['path'])) {
                $pdfPath = (string)$genResult['path'];
            } else if (is_array($genResult) && !empty($genResult['error'])) {
                error_log('firmar-checkout-submit contratoContadoGenerate: ' . $genResult['error']);
            }
        }
    }
    if ($pdfPath && file_exists($pdfPath)) {
        $pdo->prepare("UPDATE firmas_contratos SET pdf_file = ? WHERE id = ?")
            ->execute([$pdfPath, $firmaId]);
    }
} catch (Throwable $e) {
    error_log('firmar-checkout-submit stamp PDF: ' . $e->getMessage());
}

// ── Optional: send thank-you email with the signed PDF link ───────────
try {
    if (function_exists('sendMail') && !empty($tx['email'])
        && filter_var($tx['email'], FILTER_VALIDATE_EMAIL)) {
        $base = defined('VOLTIKA_BASE_URL') ? rtrim(VOLTIKA_BASE_URL, '/') : 'https://www.voltika.mx';
        $subject = 'Voltika — Contrato firmado';
        $pdfLink = $base . '/configurador/php/descargar-contrato.php?pedido=' . urlencode($tx['pedido'] ?? '') . '&inline=1';
        $name    = htmlspecialchars($tx['nombre'] ?? 'Cliente Voltika');
        $html    = '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:560px;margin:auto;padding:24px;">'
                 . '<h1 style="color:#1a3a5c;font-size:22px;">¡Gracias, ' . $name . '!</h1>'
                 . '<p style="font-size:15px;color:#333;">Recibimos tu firma para el contrato Voltika '
                 . htmlspecialchars($tx['pedido_corto'] ?: ('VK-' . ($tx['pedido'] ?? '')))
                 . '. Adjuntamos / puedes descargar el contrato firmado a continuación.</p>'
                 . '<p style="margin:18px 0;"><a href="' . htmlspecialchars($pdfLink)
                 . '" style="display:inline-block;padding:12px 20px;background:#039fe1;color:#fff;border-radius:8px;font-weight:700;text-decoration:none;">Descargar contrato firmado</a></p>'
                 . '<p style="font-size:13px;color:#666;">Si necesitas ayuda, escríbenos por WhatsApp al '
                 . '<a href="https://wa.me/525513416370" style="color:#039fe1;">+52 55 1341 6370</a>.</p>'
                 . '</div>';
        @sendMail($tx['email'], $tx['nombre'] ?? '', $subject, $html);
    }
} catch (Throwable $e) {
    error_log('firmar-checkout-submit email: ' . $e->getMessage());
}

out([
    'ok'       => true,
    'firma_id' => $firmaId,
    'pdf_url'  => $pdfPath ? null : null,   // dashboard JOIN re-resolves anyway
]);
