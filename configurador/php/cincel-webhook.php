<?php
/**
 * POST — Cincel NOM-151 webhook callback
 * Called by Cincel when a document is signed.
 *
 * Flow:
 *   1. Parse webhook body
 *   2. Find moto by cincel_document_id
 *   3. Update status to 'firmado'
 *   4. Store NOM-151 timestamp data
 *   5. Return 200 OK
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metodo no permitido']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Body invalido']);
    exit;
}

$documentId = $data['document_id'] ?? ($data['id'] ?? '');
$status     = $data['status']      ?? ($data['event'] ?? '');

if (empty($documentId)) {
    http_response_code(400);
    echo json_encode(['error' => 'document_id no proporcionado']);
    exit;
}

$pdo = getDB();

// ── Ensure columns exist ────────────────────────────────────────────────────
try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cincel_document_id VARCHAR(255) NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cincel_status VARCHAR(50) NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cincel_nom151_data JSON NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cincel_firma_fecha DATETIME NULL"); } catch (Throwable $e) {}

// ── Find moto by cincel_document_id ─────────────────────────────────────────
// Bug 5.7 (customer brief 2026-05-08): the customer-portal ACTA flow stores
// its Cincel document id in inventario_motos.cincel_acta_document_id (a
// SEPARATE column from cincel_document_id which holds the credit contract
// id). We look at three possible homes for the document_id, in order:
//   1. inventario_motos.cincel_document_id          (credit contract — legacy)
//   2. inventario_motos.cincel_acta_document_id     (ACTA — NEW)
//   3. checklist_entrega_v2.firma_pagare_cincel_id  (pagaré — admin flow)
$stmt = $pdo->prepare("SELECT id FROM inventario_motos WHERE cincel_document_id = ?");
$stmt->execute([$documentId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);

$docKind = 'contrato'; // default — will switch to 'acta' or 'pagare' if matched there
$motoId = $moto ? (int)$moto['id'] : 0;

if (!$moto) {
    // Try the ACTA column.
    try {
        $sa = $pdo->prepare("SELECT id FROM inventario_motos WHERE cincel_acta_document_id = ?");
        $sa->execute([$documentId]);
        $maRow = $sa->fetch(PDO::FETCH_ASSOC);
        if ($maRow) {
            $motoId = (int)$maRow['id'];
            $docKind = 'acta';
        }
    } catch (Throwable $e) {
        // Column may not exist on older schemas — silently fall through.
        error_log('Cincel webhook acta lookup: ' . $e->getMessage());
    }
}

if (!$motoId) {
    // Also check checklist_entrega_v2 for pagare signatures
    $stmt2 = $pdo->prepare("SELECT moto_id FROM checklist_entrega_v2 WHERE firma_pagare_cincel_id = ?");
    $stmt2->execute([$documentId]);
    $check = $stmt2->fetch(PDO::FETCH_ASSOC);
    if ($check) {
        $motoId = (int)$check['moto_id'];
        $docKind = 'pagare';
    } else {
        // Log unknown webhook and return 200 to avoid retries
        error_log("Cincel webhook: document_id=$documentId no encontrado en DB");
        http_response_code(200);
        echo json_encode(['ok' => true, 'message' => 'Documento no encontrado, ignorado']);
        exit;
    }
}

// ── Update status ───────────────────────────────────────────────────────────
$nom151Data = json_encode([
    'document_id'    => $documentId,
    'status'         => $status,
    'timestamp'      => $data['timestamp']       ?? date('c'),
    'nom151_seal'    => $data['nom151_seal']      ?? ($data['seal'] ?? null),
    'certificate'    => $data['certificate']      ?? null,
    'signed_pdf_url' => $data['signed_pdf_url']   ?? ($data['file_url'] ?? null),
    'raw_event'      => $data,
], JSON_UNESCAPED_UNICODE);

// For ACTA signatures we ALSO flip cliente_acta_firmada=1 so the customer
// portal status endpoint reports `signed: true` and the PoS flow's
// estado-acta.php (which polls inventario_motos.cliente_acta_firmada)
// can advance to finalizar.
if ($docKind === 'acta') {
    // ensure the legacy ACTA columns exist (idempotent — same as portal does)
    try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cliente_acta_firmada TINYINT(1) DEFAULT 0"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cliente_acta_fecha DATETIME NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cliente_acta_firma VARCHAR(150) NULL"); } catch (Throwable $e) {}

    $pdo->prepare("
        UPDATE inventario_motos
        SET cincel_acta_status   = 'firmado',
            cincel_nom151_data   = ?,
            cliente_acta_firmada = 1,
            cliente_acta_fecha   = COALESCE(cliente_acta_fecha, NOW()),
            cliente_acta_firma   = COALESCE(cliente_acta_firma, ?)
        WHERE id = ?
    ")->execute([
        $nom151Data,
        // Best-effort: surface signer name if Cincel sent it. Falls back to
        // an empty string which the COALESCE keeps for the existing column.
        ($data['signer_name'] ?? ($data['signers'][0]['name'] ?? '')),
        $motoId,
    ]);
} else {
    // Credit contract / pagaré path — original behavior, untouched.
    $pdo->prepare("
        UPDATE inventario_motos
        SET cincel_status = 'firmado',
            cincel_nom151_data = ?,
            cincel_firma_fecha = NOW()
        WHERE id = ?
    ")->execute([$nom151Data, $motoId]);
}

// Log the event
try {
    $pdo->prepare("INSERT INTO admin_log (usuario_id, accion, detalle, ip) VALUES (NULL, ?, ?, ?)")
        ->execute([
            'cincel_webhook_firma',
            json_encode([
                'moto_id'     => $motoId,
                'document_id' => $documentId,
                'status'      => $status,
            ], JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
} catch (Throwable $e) {
    error_log("Cincel webhook log error: " . $e->getMessage());
}

http_response_code(200);
echo json_encode(['ok' => true, 'message' => 'Firma registrada']);
