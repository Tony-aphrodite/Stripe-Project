<?php
/**
 * Voltika — Round 18 (2026-05-14)
 *
 * Lightweight read-only endpoint used by the credit SPA to check whether
 * the current customer has already signed the credit contract. Drives the
 * SPA step reordering: paso-credito-enganche.js calls this on step entry
 * and redirects to 'credito-contrato' when signed=false.
 *
 * GET ?email=foo@bar.com&telefono=5512345678
 *   → 200 { signed: true|false, signed_at: "ISO8601"|null, audit_id: int|null }
 *
 * Both fields optional individually but at least one required. Matches the
 * most recent firma_sha256-present row.
 *
 * Note: this endpoint is intentionally public (no auth gate) because the
 * SPA runs in the customer's browser pre-login. Exposes only a boolean —
 * no signature data, no PII beyond confirming/denying the check.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$email = trim((string)($_GET['email']    ?? ''));
$tel   = trim((string)($_GET['telefono'] ?? ''));

if ($email === '' && $tel === '') {
    http_response_code(400);
    echo json_encode([
        'ok'      => false,
        'error'   => 'parametros_faltantes',
        'message' => 'email o telefono requerido',
    ]);
    exit;
}

try {
    $pdo = getDB();
    // Lazy-create so a fresh install never throws on a missing table.
    @$pdo->exec("CREATE TABLE IF NOT EXISTS firmas_contratos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(200) NULL,
        email VARCHAR(200) NULL,
        telefono VARCHAR(40) NULL,
        curp VARCHAR(20) NULL,
        modelo VARCHAR(80) NULL,
        pdf_file VARCHAR(255) NULL,
        firma_base64 MEDIUMTEXT NULL,
        firma_sha256 CHAR(64) NULL,
        ip VARCHAR(45) NULL,
        user_agent VARCHAR(500) NULL,
        freg DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (email), INDEX (telefono)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $st = $pdo->prepare(
        "SELECT id, freg FROM firmas_contratos
         WHERE firma_sha256 IS NOT NULL AND firma_sha256 != ''
           AND (
                (LENGTH(?) > 0 AND email    = ?)
             OR (LENGTH(?) > 0 AND telefono = ?)
           )
         ORDER BY freg DESC LIMIT 1"
    );
    $st->execute([$email, $email, $tel, $tel]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'        => true,
        'signed'    => (bool)$row,
        'signed_at' => $row ? (string)$row['freg'] : null,
        'audit_id'  => $row ? (int)$row['id']     : null,
    ]);
} catch (Throwable $e) {
    error_log('firma-credito-status: ' . $e->getMessage());
    // Fail open on DB error — SPA will use the server gate as backup. We
    // return signed=false so the SPA at worst makes the customer re-sign
    // (which is annoying but never wrong).
    echo json_encode([
        'ok'        => false,
        'signed'    => false,
        'signed_at' => null,
        'audit_id'  => null,
        'error'     => 'check_failed',
    ]);
}
