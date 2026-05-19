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
    // Round 58 (2026-05-18, Óscar): Cincel returns the FULLY SIGNED PDF with
    // customer's autograph signature + NOM-151 timestamp watermark embedded.
    // Until now we never downloaded it — descargar.php served the unsigned
    // template instead, which is why the boss saw missing signatures. Save
    // the signed PDF locally + persist its filename for descargar.php.
    try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cincel_acta_signed_pdf_path VARCHAR(255) NULL"); } catch (Throwable $e) {}

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

    // ── Round 58: download the SIGNED PDF from Cincel and store locally.
    // This is the file with the customer's drawn autograph + Cincel
    // NOM-151 watermark. Without this step, customers can only download
    // our pre-sign template (no signature visible).
    //
    // Resolution of signed PDF URL, in order:
    //   1. webhook body  →  signed_pdf_url / file_url
    //   2. Cincel API    →  GET /v3/documents/{document_id}
    // We auth with CINCEL_EMAIL + CINCEL_PASSWORD (same as cincel-firma-acta.php).
    $signedUrl = $data['signed_pdf_url'] ?? ($data['file_url'] ?? null);
    $cincelApi = defined('CINCEL_API_URL') ? rtrim(CINCEL_API_URL, '/')
               : (getenv('CINCEL_API_URL') ?: 'https://api.cincel.digital/v3');
    $cincelEmail    = defined('CINCEL_EMAIL')    ? CINCEL_EMAIL    : (getenv('CINCEL_EMAIL')    ?: '');
    $cincelPassword = defined('CINCEL_PASSWORD') ? CINCEL_PASSWORD : (getenv('CINCEL_PASSWORD') ?: '');

    if (!$signedUrl && $cincelEmail && $cincelPassword) {
        // Authenticate first to fetch document metadata.
        $cincelToken = null;
        foreach (['/auth/tokens', '/auth/login'] as $authPath) {
            $ch = curl_init($cincelApi . $authPath);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode(['email' => $cincelEmail, 'password' => $cincelPassword]),
                CURLOPT_TIMEOUT => 15,
            ]);
            $rawAuth = curl_exec($ch);
            $authCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($authCode === 200) {
                $authArr = json_decode((string)$rawAuth, true);
                $cincelToken = $authArr['access_token'] ?? $authArr['token'] ?? null;
                if ($cincelToken) break;
            }
        }
        if ($cincelToken) {
            $ch = curl_init($cincelApi . '/documents/' . rawurlencode($documentId));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cincelToken],
                CURLOPT_TIMEOUT => 15,
            ]);
            $rawDoc = curl_exec($ch);
            $docCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($docCode >= 200 && $docCode < 300) {
                $docArr = json_decode((string)$rawDoc, true);
                $signedUrl = $docArr['signed_pdf_url']
                          ?? $docArr['file_url']
                          ?? ($docArr['document']['signed_pdf_url'] ?? null)
                          ?? ($docArr['document']['file_url'] ?? null);
            }
        }
    }

    if ($signedUrl) {
        // Download — Cincel signed PDFs may be on CDN (no auth) or behind
        // the API (Bearer token). Try without auth first, then retry with
        // the most recent token if available.
        $downloadDir = __DIR__ . '/uploads/actas/';
        if (!is_dir($downloadDir)) @mkdir($downloadDir, 0775, true);
        $signedName = 'acta_signed_' . $motoId . '_' . date('Ymd_His') . '.pdf';
        $signedPath = $downloadDir . $signedName;

        $ch = curl_init($signedUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $pdfBin = curl_exec($ch);
        $pdfCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Validate it's a PDF (starts with %PDF magic bytes) and write it.
        if ($pdfCode >= 200 && $pdfCode < 300 && is_string($pdfBin)
            && strlen($pdfBin) > 1000 && substr($pdfBin, 0, 4) === '%PDF') {
            $written = @file_put_contents($signedPath, $pdfBin);
            if ($written !== false && $written > 1000) {
                $pdo->prepare("UPDATE inventario_motos
                                  SET cincel_acta_signed_pdf_path = ?
                                WHERE id = ?")
                    ->execute([$signedName, $motoId]);
            } else {
                error_log('cincel-webhook acta: no se pudo escribir PDF en ' . $signedPath);
            }
        } else {
            error_log('cincel-webhook acta: descarga falló HTTP=' . $pdfCode
                    . ' bytes=' . (is_string($pdfBin) ? strlen($pdfBin) : 'null'));
        }
    } else {
        error_log('cincel-webhook acta: signed_pdf_url no disponible (ni en webhook ni en API)');
    }
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
