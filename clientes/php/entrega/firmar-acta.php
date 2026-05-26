<?php
/**
 * Portal Clientes — Firmar ACTA DE ENTREGA
 * Client reviews ACTA and signs it. Sets cliente_acta_firmada=1 so that
 * the Punto Voltika staff can finalize the delivery in step 5.
 */
require_once __DIR__ . '/../bootstrap.php';

$cid = portalRequireAuth();
$in  = portalJsonIn();
$motoId = (int)($in['moto_id'] ?? 0);
$firma  = trim((string)($in['firma_nombre'] ?? ''));
$signatureData = $in['signature_data'] ?? null; // base64 canvas (optional)

if (!$motoId || strlen($firma) < 3) {
    portalJsonOut(['error' => 'Datos incompletos'], 400);
}

$pdo = getDB();

try {
    // Ownership check accepts cliente_id OR matching cliente_telefono/email,
    // auto-backfilling cliente_id when found via the soft paths. Necessary
    // for credito/contado orders whose cliente_id was left null by earlier
    // webhooks or manual imports.
    $moto = portalFindOwnedMoto($cid, $motoId);
    if (!$moto) portalJsonOut(['error' => 'Moto no encontrada'], 404);
    if (!empty($moto['cliente_acta_firmada'])) {
        portalJsonOut(['ok' => true, 'already' => true, 'mensaje' => 'ACTA ya firmada']);
    }

    // Ensure columns exist
    try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cliente_acta_firmada TINYINT(1) DEFAULT 0"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cliente_acta_fecha DATETIME NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cliente_acta_firma VARCHAR(150) NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cliente_acta_ip VARCHAR(45) NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cliente_recepcion_ok TINYINT(1) DEFAULT 0"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cliente_recepcion_fecha DATETIME NULL"); } catch (Throwable $e) {}

    // Save signature image if provided
    $firmaPath = null;
    if (is_string($signatureData) && strpos($signatureData, 'data:image') === 0) {
        if (preg_match('/^data:image\/(png|jpeg|jpg);base64,(.+)$/', $signatureData, $m)) {
            $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
            $bin = base64_decode($m[2]);
            $dir = __DIR__ . '/../../../configurador/php/uploads/firmas';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $fname = 'acta_' . $motoId . '_' . time() . '.' . $ext;
            @file_put_contents($dir . '/' . $fname, $bin);
            $firmaPath = 'uploads/firmas/' . $fname;
        }
    }

    $pdo->prepare("UPDATE inventario_motos
        SET cliente_acta_firmada = 1,
            cliente_acta_fecha = NOW(),
            cliente_acta_firma = ?,
            cliente_acta_ip = ?
        WHERE id = ?")
        ->execute([$firma, $_SERVER['REMOTE_ADDR'] ?? null, $motoId]);

    // Round 83 (2026-05-26) — REGENERATE the ACTA PDF with the signature
    // image embedded, then apply Cincel NOM-151 timestamp on the NEW hash.
    //
    // Before Round 83, this path only saved the signature flag and applied
    // the NOM-151 stamp to the OLD blank PDF that cincel-firma-acta.php
    // had generated BEFORE the customer signed. Result: the saved PDF
    // never contained the customer's actual signature image. The signed
    // ACTA looked legal in DB (cliente_acta_firmada=1) but the document
    // itself had a blank signature line. Inconsistent with the standalone
    // Round 80 path which DID embed the signature.
    //
    // Round 83 fix: use the shared generarActaPdf() helper to regenerate
    // the PDF WITH the signature embedded, then stamp the new PDF hash
    // via Cincel. Now both signing paths (Round 73 SPA + Round 80 standalone)
    // produce IDENTICAL signed PDFs.
    $tsHashOut = null;
    $newPdfPath = null;
    try {
        // Reload the moto row in full — we need cliente_*, modelo, color,
        // pedido_num, punto_voltika_id, etc. for the regen.
        $mRow = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ? LIMIT 1");
        $mRow->execute([$motoId]);
        $motoFull = $mRow->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($motoFull && is_string($signatureData) && strpos($signatureData, 'data:image') === 0) {
            require_once __DIR__ . '/../acta-pdf-generator.php';
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? null;
            $newPdfPath = generarActaPdf($pdo, $motoFull, $signatureData, $clientIp);
            if ($newPdfPath) {
                // Persist the new path so Round 82 viewer and future stamps
                // reference the SIGNED PDF, not the old blank one.
                try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cincel_acta_pdf_path VARCHAR(600) NULL"); } catch (Throwable $e) {}
                try {
                    $pdo->prepare("UPDATE inventario_motos
                        SET cincel_acta_pdf_path = ?
                        WHERE id = ?")
                        ->execute([$newPdfPath, $motoId]);
                } catch (Throwable $e) { error_log('firmar-acta persist new pdf path: ' . $e->getMessage()); }
            }
        }

        // Fall back to the existing PDF path if regen didn't run (no sig data, etc.)
        if (!$newPdfPath) {
            $pathRow = $pdo->prepare("SELECT cincel_acta_pdf_path FROM inventario_motos WHERE id = ? LIMIT 1");
            $pathRow->execute([$motoId]);
            $newPdfPath = (string)($pathRow->fetchColumn() ?: '');
        }

        if ($newPdfPath && is_file($newPdfPath)) {
            require_once __DIR__ . '/../../../configurador/php/cincel-timestamp.php';
            $ts = cincelGetOrCreateTimestamp($newPdfPath);
            if (!empty($ts['ok'])) {
                cincelSaveTimestamp($pdo, $ts, null, $newPdfPath);
                $tsHashOut = $ts['hash'] ?? null;
                try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cincel_acta_timestamp_hash CHAR(64) NULL"); } catch (Throwable $e) {}
                if ($tsHashOut) {
                    try {
                        $pdo->prepare("UPDATE inventario_motos
                            SET cincel_acta_timestamp_hash = ?,
                                cincel_acta_status = 'signed_with_timestamp'
                            WHERE id = ?")
                            ->execute([$tsHashOut, $motoId]);
                    } catch (Throwable $e) { /* column may not exist on legacy schema */ }
                }
            } else {
                error_log('firmar-acta cincel timestamp failed: ' . json_encode($ts));
            }
        }
    } catch (Throwable $e) {
        // Never block delivery confirmation if regen or Cincel fails.
        error_log('firmar-acta regen+stamp exception: ' . $e->getMessage());
    }

    // Log
    portalLog('acta_firmada', ['cliente_id' => $cid, 'success' => 1,
        'detalle' => "moto=$motoId firma_img=" . ($firmaPath ? '1' : '0')
                   . ' cincel_ts=' . ($tsHashOut ? substr($tsHashOut, 0, 12) : 'no')]);

    // Notify cliente (confirmation) — punto staff will see it in their UI automatically
    try {
        require_once __DIR__ . '/../../../configurador/php/voltika-notify.php';
        voltikaNotify('acta_firmada', [
            'cliente_id' => $cid,
            'nombre'     => $firma,
            'modelo'     => $moto['modelo'] ?? '',
            'telefono'   => $moto['cliente_telefono'] ?? '',
            'email'      => $moto['cliente_email'] ?? '',
        ]);
    } catch (Throwable $e) { error_log('notify acta_firmada: ' . $e->getMessage()); }

    // Per dashboards_diagrams.pdf (Delivery process, step 6): notify the Point
    // Panel so the dealer that owns this moto sees the ACTA was signed and can
    // finalize the delivery. We persist a row in notificaciones_log targeted at
    // the punto so the Point Panel can surface it (polling by destino).
    try {
        $punto = null;
        if (!empty($moto['punto_voltika_id'])) {
            $pq = $pdo->prepare("SELECT id, nombre FROM puntos_voltika WHERE id=? LIMIT 1");
            $pq->execute([(int)$moto['punto_voltika_id']]);
            $punto = $pq->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $mensajePunto = '✅ ACTA DE ENTREGA firmada por ' . $firma
            . ' · Moto #' . $motoId
            . ' · Modelo: ' . ($moto['modelo'] ?? '?')
            . ' · Color: ' . ($moto['color'] ?? '?')
            . ' — Puede finalizar la entrega.';
        $destinoPunto = $punto
            ? ('punto:' . $punto['id'])
            : ('punto:moto:' . $motoId);
        $pdo->prepare("INSERT INTO notificaciones_log (cliente_id, tipo, canal, destino, mensaje, status)
            VALUES (?, ?, 'punto_panel', ?, ?, 'ok')")
            ->execute([$cid, 'acta_firmada_punto', $destinoPunto, $mensajePunto]);
    } catch (Throwable $e) { error_log('notify punto acta_firmada: ' . $e->getMessage()); }

    portalJsonOut([
        'ok' => true,
        'mensaje' => 'ACTA firmada correctamente',
        'firma_img' => $firmaPath,
    ]);
} catch (Throwable $e) {
    error_log('entrega/firmar-acta: ' . $e->getMessage());
    portalJsonOut(['error' => 'Error interno'], 500);
}
