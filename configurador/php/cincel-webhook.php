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
$stmt = $pdo->prepare("SELECT id FROM inventario_motos WHERE cincel_document_id = ?");
$stmt->execute([$documentId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$moto) {
    // Also check checklist_entrega_v2 for pagare signatures
    $stmt2 = $pdo->prepare("SELECT moto_id FROM checklist_entrega_v2 WHERE firma_pagare_cincel_id = ?");
    $stmt2->execute([$documentId]);
    $check = $stmt2->fetch(PDO::FETCH_ASSOC);
    if ($check) {
        $motoId = (int)$check['moto_id'];
    } else {
        // Log unknown webhook and return 200 to avoid retries
        error_log("Cincel webhook: document_id=$documentId no encontrado en DB");
        http_response_code(200);
        echo json_encode(['ok' => true, 'message' => 'Documento no encontrado, ignorado']);
        exit;
    }
} else {
    $motoId = (int)$moto['id'];
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

$pdo->prepare("
    UPDATE inventario_motos
    SET cincel_status = 'firmado',
        cincel_nom151_data = ?,
        cincel_firma_fecha = NOW()
    WHERE id = ?
")->execute([$nom151Data, $motoId]);

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
