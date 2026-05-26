<?php
/**
 * Voltika Admin — Regenerate an ACTA PDF with the existing signature
 * embedded + apply NOM-151 timestamp. (Round 83, 2026-05-26)
 *
 * Customer brief (Óscar, 2026-05-26): Adrian's signed ACTA showed the
 * autograph status as 'autograph_pending' and the PDF had a blank
 * signature line — because the SPA path (firmar-acta.php pre-Round-83)
 * saved the signature flag but never embedded the signature image into
 * the PDF.
 *
 * This endpoint provides a one-click fix for any moto that's in that
 * state. It:
 *   1. Loads the moto + the most recent signature from firmas_contratos
 *      (matched by email/telefono).
 *   2. Calls the shared generarActaPdf() helper to regenerate the PDF
 *      with the autograph embedded.
 *   3. Applies a fresh NOM-151 timestamp on the new hash.
 *   4. Updates inventario_motos.cincel_acta_pdf_path,
 *      cincel_acta_timestamp_hash, cincel_acta_status='signed_with_timestamp'.
 *
 * POST body: { moto_id: int }
 * Response : { ok, new_pdf_path, nom151_hash, message }
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

// ── Load moto ───────────────────────────────────────────────────────────
try {
    $st = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ? LIMIT 1");
    $st->execute([$motoId]);
    $moto = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    adminJsonOut(['ok' => false, 'error' => 'db_lookup_failed',
                  'message' => $e->getMessage()], 500);
}
if (!$moto) {
    adminJsonOut(['ok' => false, 'error' => 'moto_no_encontrada',
                  'message' => 'No existe la moto ' . $motoId], 404);
}

// ── Find the most recent signature for this customer ────────────────────
$tel   = trim((string)($moto['cliente_telefono'] ?? ''));
$email = trim((string)($moto['cliente_email']    ?? ''));
$sigDataUrl = '';

try {
    $where = [];
    $args  = [];
    if ($tel !== '')   { $where[] = 'telefono = ?'; $args[] = $tel; }
    if ($email !== '') { $where[] = 'email = ?';    $args[] = $email; }
    if (!$where) {
        adminJsonOut(['ok' => false, 'error' => 'sin_contacto',
            'message' => 'La moto no tiene email ni teléfono — no puedo buscar firma asociada.'], 422);
    }
    $sql = "SELECT firma_base64, freg FROM firmas_contratos
             WHERE (" . implode(' OR ', $where) . ")
               AND firma_base64 IS NOT NULL AND firma_base64 <> ''
             ORDER BY id DESC LIMIT 1";
    $fs = $pdo->prepare($sql);
    $fs->execute($args);
    $row = $fs->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($row && !empty($row['firma_base64'])) {
        $sigDataUrl = (string)$row['firma_base64'];
    }
} catch (Throwable $e) {
    error_log('regenerar-acta firmas lookup: ' . $e->getMessage());
}

if ($sigDataUrl === '') {
    adminJsonOut([
        'ok'      => false,
        'error'   => 'sin_firma_guardada',
        'message' => 'No encontré ninguna firma guardada en firmas_contratos para este cliente '
                   . '(buscando por email=' . ($email ?: '—') . ' y teléfono=' . ($tel ?: '—') . '). '
                   . 'El cliente debe firmar primero — usa /admin/php/checklists/herramienta-firma-acta.php para enviarle un link.',
    ], 404);
}

// ── Regenerate the PDF with the signature embedded ──────────────────────
require_once __DIR__ . '/../../../clientes/php/acta-pdf-generator.php';
$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$newPdfPath = generarActaPdf($pdo, $moto, $sigDataUrl, $ip);

if (!$newPdfPath) {
    adminJsonOut(['ok' => false, 'error' => 'regen_failed',
        'message' => 'La regeneración del PDF falló. Revisa error_log para detalles (probable FPDF / permisos /tmp).'], 500);
}

// Persist the new path
try {
    $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cincel_acta_pdf_path VARCHAR(600) NULL");
} catch (Throwable $e) {}
try {
    $pdo->prepare("UPDATE inventario_motos SET cincel_acta_pdf_path = ? WHERE id = ?")
        ->execute([$newPdfPath, $motoId]);
} catch (Throwable $e) { error_log('regenerar-acta persist path: ' . $e->getMessage()); }

// ── Apply Cincel NOM-151 timestamp on the new PDF ───────────────────────
$tsHash = null;
$cincelMsg = null;
try {
    require_once __DIR__ . '/../../../configurador/php/cincel-timestamp.php';
    $ts = cincelGetOrCreateTimestamp($newPdfPath);
    if (!empty($ts['ok'])) {
        cincelSaveTimestamp($pdo, $ts, null, $newPdfPath);
        $tsHash = $ts['hash'] ?? null;
        try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cincel_acta_timestamp_hash CHAR(64) NULL"); } catch (Throwable $e) {}
        if ($tsHash) {
            $pdo->prepare("UPDATE inventario_motos
                SET cincel_acta_timestamp_hash = ?,
                    cincel_acta_status = 'signed_with_timestamp'
                WHERE id = ?")
                ->execute([$tsHash, $motoId]);
        }
    } else {
        $cincelMsg = 'Cincel respondió HTTP ' . ($ts['http'] ?? '?') . ' — ' . ($ts['error'] ?? 'sin detalle');
        error_log('regenerar-acta cincel failed: ' . json_encode($ts));
    }
} catch (Throwable $e) {
    $cincelMsg = 'Cincel exception: ' . $e->getMessage();
    error_log('regenerar-acta cincel exception: ' . $e->getMessage());
}

// ── Audit + respond ─────────────────────────────────────────────────────
if (function_exists('adminLog')) {
    adminLog('regenerar_acta', [
        'moto_id'   => $motoId,
        'vin'       => $moto['vin_display'] ?? $moto['vin'] ?? '',
        'new_pdf'   => $newPdfPath,
        'nom151'    => $tsHash,
        'cincel_err'=> $cincelMsg,
    ]);
}

adminJsonOut([
    'ok'           => true,
    'message'      => 'ACTA regenerada con firma embebida' . ($tsHash ? ' + sello NOM-151 aplicado' : ' (NOM-151 pendiente: ' . $cincelMsg . ')'),
    'new_pdf_path' => $newPdfPath,
    'view_url'     => '/admin/php/inventario/view-acta.php?moto_id=' . $motoId,
    'nom151_hash'  => $tsHash,
    'cincel_msg'   => $cincelMsg,
]);
