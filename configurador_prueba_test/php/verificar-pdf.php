<?php
/**
 * GET — Verify the integrity of a generated Voltika PDF.
 *
 * Tech Spec EN §6 mandates SHA-256 integrity verification for every signed
 * document. This endpoint compares a SHA-256 hash submitted by the
 * caller against the hash stored in our DB at generation time.
 *
 * Two access modes:
 *   1. ?pedido=XXX&token=YY                    → contrato de compraventa contado/MSI
 *   2. ?moto_id=N&doc=acta|pagare|carta_factura → delivery documents
 *
 * Returns:
 *   { ok: true, valid: true|false, expected_hash, submitted_hash, document_type, generated_at }
 *   { ok: false, error }
 *
 * Usage example (customer reading the email PDF):
 *   curl 'https://voltika.mx/configurador_prueba/php/verificar-pdf.php?pedido=VK-...&token=...&hash=abc123...'
 *
 * Returns 200 with valid:true if the hash matches what we stored, valid:false otherwise.
 * Used by:
 *   - Customer portal "verify your contract" feature
 *   - Admin disputes panel cross-checking attached PDFs
 *   - External legal counsel auditing a document
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/contrato-contado.php'; // for token verification

$submittedHash = strtolower(trim((string)($_GET['hash'] ?? '')));
if ($submittedHash !== '' && !preg_match('/^[a-f0-9]{64}$/', $submittedHash)) {
    echo json_encode(['ok' => false, 'error' => 'hash debe ser SHA-256 (64 hex chars)']);
    exit;
}

$pedido = trim((string)($_GET['pedido'] ?? ''));
$token  = trim((string)($_GET['token']  ?? ''));
$motoId = (int)($_GET['moto_id'] ?? 0);
$doc    = trim((string)($_GET['doc']    ?? 'contrato'));

if ($pedido === '' && $motoId === 0) {
    echo json_encode(['ok' => false, 'error' => 'pedido o moto_id requerido']);
    exit;
}

try {
    $pdo = getDB();
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'DB no disponible']);
    exit;
}

$expectedHash = '';
$generatedAt  = '';
$docType      = '';

// ── Mode 1: contrato de compraventa (configurador) ─────────────────────
if ($pedido !== '') {
    // Look up stripe_pi for token verification
    $st = $pdo->prepare("SELECT stripe_pi, contrato_pdf_hash, contrato_aceptado_at
                         FROM transacciones
                         WHERE pedido = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$pedido]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'pedido no encontrado']);
        exit;
    }
    if (!contratoContadoVerifyToken($pedido, (string)$row['stripe_pi'], $token)) {
        echo json_encode(['ok' => false, 'error' => 'token inválido']);
        exit;
    }
    $expectedHash = (string)($row['contrato_pdf_hash'] ?? '');
    $generatedAt  = (string)($row['contrato_aceptado_at'] ?? '');
    $docType      = 'contrato_compraventa';
}

// ── Mode 2: delivery documents (admin only) ─────────────────────────────
if ($motoId > 0) {
    // Adopt the admin session name (VOLTIKA_ADMIN) so admin login is
    // recognized when calling from configurador_prueba/.
    if (session_status() === PHP_SESSION_NONE) {
        @session_name('VOLTIKA_ADMIN');
        @session_start();
    }
    if (empty($_SESSION['admin_user_id'])) {
        echo json_encode(['ok' => false, 'error' => 'auth requerida para moto_id']);
        exit;
    }
    $col = match ($doc) {
        'pagare'         => 'pagare_pdf_hash',
        'acta'           => 'acta_pdf_hash',
        'carta_factura'  => 'carta_factura_pdf_hash',
        default          => null,
    };
    if (!$col) {
        echo json_encode(['ok' => false, 'error' => 'doc debe ser pagare|acta|carta_factura']);
        exit;
    }
    try {
        $st = $pdo->prepare("SELECT $col AS h FROM checklist_entrega_v2
                             WHERE moto_id = ? ORDER BY freg DESC LIMIT 1");
        $st->execute([$motoId]);
        $expectedHash = (string)($st->fetchColumn() ?: '');
        $docType      = $doc;
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'No se pudo consultar el hash']);
        exit;
    }
}

if ($expectedHash === '') {
    echo json_encode([
        'ok'    => true,
        'valid' => false,
        'error' => 'No se ha generado este documento (hash vacío en DB).',
        'document_type' => $docType,
    ]);
    exit;
}

// If no hash submitted, just return the expected one (read-only inquiry).
if ($submittedHash === '') {
    echo json_encode([
        'ok'            => true,
        'valid'         => null,
        'expected_hash' => $expectedHash,
        'document_type' => $docType,
        'generated_at'  => $generatedAt,
        'note'          => 'Para verificar integridad, agregar &hash=<SHA-256 del archivo>',
    ]);
    exit;
}

$valid = hash_equals($expectedHash, $submittedHash);

echo json_encode([
    'ok'              => true,
    'valid'           => $valid,
    'expected_hash'   => $expectedHash,
    'submitted_hash'  => $submittedHash,
    'document_type'   => $docType,
    'generated_at'    => $generatedAt,
    'message'         => $valid
        ? 'Documento íntegro — coincide con el original generado por Voltika.'
        : 'ALERTA: el documento NO coincide con el original. Puede haber sido modificado o no es el mismo archivo. Contacta a soporte: contacto@voltika.mx',
]);
