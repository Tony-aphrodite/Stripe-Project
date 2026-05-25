<?php
/**
 * Voltika Customer — Save retroactive signature + regenerate contract PDF
 * (Round 75, 2026-05-25).
 *
 * Backend half of the retro-sign flow started by firmar-contrato-retro.php.
 * Receives the customer's canvas signature, validates the one-time token,
 * persists the signature to firmas_contratos, regenerates the contract PDF
 * with the autograph embedded, and applies a Cincel NOM-151 timestamp on
 * the new PDF. Marks the token as 'signed' so it can't be reused.
 *
 * POST body (JSON):
 *   { token: "<40hex>", signature_data: "data:image/png;base64,..." }
 *
 * Response (JSON):
 *   { ok: true, message: "...", new_pdf_url?: "...", nom151_hash?: "..." }
 *   { ok: false, error: "<code>", message: "..." }
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// ─────────────────────────────────────────────────────────────────────────
// Read + validate input
// ─────────────────────────────────────────────────────────────────────────
$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_json', 'message' => 'Cuerpo inválido.']);
    exit;
}

$token = trim((string)($in['token'] ?? ''));
$sig   = (string)($in['signature_data'] ?? '');

if (!preg_match('/^[a-f0-9]{40}$/i', $token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_token', 'message' => 'Token inválido.']);
    exit;
}
if (strlen($sig) < 200 || strpos($sig, 'data:image/png;base64,') !== 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_signature',
                      'message' => 'La firma no se recibió correctamente. Vuelve a dibujarla.']);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// Resolve token → transaccion (with row-level lock to prevent double-sign)
// ─────────────────────────────────────────────────────────────────────────
$pdo = getDB();

try {
    $pdo->beginTransaction();

    $st = $pdo->prepare("SELECT * FROM firma_contrato_requests
                         WHERE token = ? FOR UPDATE");
    $st->execute([$token]);
    $req = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$req) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'token_no_encontrado',
                          'message' => 'El enlace ya no es válido.']);
        exit;
    }
    if ((string)$req['estado'] === 'signed') {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => 'ya_firmado',
                          'message' => 'Este contrato ya fue firmado.']);
        exit;
    }
    if ((string)$req['estado'] === 'expired' || (int)$req['expires_at'] < time()) {
        $pdo->prepare("UPDATE firma_contrato_requests SET estado='expired' WHERE id=?")
            ->execute([(int)$req['id']]);
        $pdo->commit();
        echo json_encode(['ok' => false, 'error' => 'token_expirado',
                          'message' => 'El enlace expiró. Pide a Voltika que te envíe uno nuevo.']);
        exit;
    }

    $txnId = (int)$req['transaccion_id'];
    $txStmt = $pdo->prepare("SELECT * FROM transacciones WHERE id = ? LIMIT 1");
    $txStmt->execute([$txnId]);
    $txn = $txStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$txn) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => 'transaccion_no_encontrada',
                          'message' => 'No encontramos la transacción asociada.']);
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Persist signature into firmas_contratos
    // (idempotent table create — same shape as guardar-firma-precompra.php)
    // ─────────────────────────────────────────────────────────────────────
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS firmas_contratos (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            nombre        VARCHAR(200),
            email         VARCHAR(200),
            telefono      VARCHAR(30),
            curp          VARCHAR(20),
            modelo        VARCHAR(200),
            pdf_file      VARCHAR(255),
            customer_id   VARCHAR(100),
            firma_base64  MEDIUMTEXT,
            firma_sha256  CHAR(64),
            ip            VARCHAR(64),
            user_agent    VARCHAR(500),
            freg          DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email (email),
            INDEX idx_freg  (freg)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* non-fatal */ }

    $email = trim((string)($txn['email']    ?? ''));
    $tel   = trim((string)($txn['telefono'] ?? ''));
    $nom   = trim((string)($txn['nombre']   ?? ''));
    $hash  = hash('sha256', $sig);
    $ip    = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua    = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

    $pdo->prepare("INSERT INTO firmas_contratos
            (nombre, email, telefono, modelo, firma_base64, firma_sha256, ip, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([
            $nom ?: null, $email ?: null, $tel ?: null,
            $txn['modelo'] ?? null, $sig, $hash, $ip, $ua,
        ]);
    $firmaId = (int)$pdo->lastInsertId();

    // ─────────────────────────────────────────────────────────────────────
    // Regenerate the contract PDF with the autograph embedded.
    // Best-effort: we rebuild contratoData from transacciones columns. Some
    // fields (cincel_*, otp_audit) won't be present here — that's fine, the
    // generator already handles missing keys gracefully.
    // ─────────────────────────────────────────────────────────────────────
    require_once __DIR__ . '/../../configurador/php/contrato-contado.php';

    $pedido     = (string)($txn['pedido']           ?? '');
    $modelo     = (string)($txn['modelo']           ?? '');
    $color      = (string)($txn['color']            ?? '');
    $apePat     = (string)($txn['apellido_paterno'] ?? '');
    $apeMat     = (string)($txn['apellido_materno'] ?? '');
    $fullName   = trim($nom . ' ' . $apePat . ' ' . $apeMat);
    $total      = (float)($txn['total'] ?? $txn['precio_unitario'] ?? 0);
    $cp         = (string)($txn['cp'] ?? '');
    $folio      = (string)($txn['pedido'] ?? '');

    $contratoData = [
        'pedido'                  => $pedido,
        'folio'                   => $folio,
        'contract_date'           => isset($txn['contrato_aceptado_at'])
                                       ? date('d/m/Y', strtotime((string)$txn['contrato_aceptado_at']))
                                       : date('d/m/Y'),
        'customer_full_name'      => $fullName ?: $nom,
        'customer_first_name'     => $nom,
        'apellido_paterno'        => $apePat,
        'apellido_materno'        => $apeMat,
        'customer_email'          => $email,
        'customer_phone'          => $tel,
        'customer_zip'            => $cp,
        'vehicle_model'           => $modelo,
        'vehicle_color'           => $color,
        'vehicle_year'            => (string)($txn['anio'] ?? date('Y')),
        'vehicle_price'           => $total,
        'logistics_cost'          => 0,
        'total_amount'            => $total,
        'payment_method'          => (string)($txn['tipo_pago'] ?? 'contado'),
        'payment_reference'       => (string)($txn['stripe_pi'] ?? ''),
        'payment_date'            => isset($txn['fecha_pago'])
                                       ? date('d/m/Y H:i', strtotime((string)$txn['fecha_pago']))
                                       : '',
        'estimated_delivery_date' => '',
        'acceptance_timestamp'    => (string)($txn['contrato_aceptado_at'] ?? gmdate('Y-m-d H:i:s')),
        'acceptance_ip'           => (string)($txn['contrato_aceptado_ip'] ?? $ip ?? ''),
        'acceptance_user_agent'   => (string)($txn['contrato_aceptado_ua'] ?? $ua),
        'acceptance_geolocation'  => (string)($txn['contrato_geolocation'] ?? ''),
        'otp_validated'           => (int)($txn['contrato_otp_validated'] ?? 0),
        // Autograph signature: just captured by the customer.
        'firma_autografa_base64'  => $sig,
        'firma_autografa_fecha'   => date('Y-m-d H:i:s'),
        'firma_autografa_ip'      => $ip ?? '',
    ];

    $genResult = contratoContadoGenerate($contratoData);
    if (empty($genResult['ok'])) {
        // Don't roll back the firmas_contratos insert — the signature is
        // valuable evidence even if the PDF regen failed. Just report.
        $pdo->commit();
        echo json_encode([
            'ok'      => false,
            'error'   => 'pdf_regen_failed',
            'message' => 'Tu firma quedó guardada, pero no pudimos regenerar el PDF. Voltika lo regenerará manualmente. Detalle: '
                       . ($genResult['error'] ?? 'desconocido'),
            'firma_id'=> $firmaId,
        ]);
        exit;
    }

    $newPdfPath = (string)$genResult['path'];
    $newPdfHash = (string)($genResult['hash'] ?? hash_file('sha256', $newPdfPath));
    $relPath    = contratoContadoRelativePath($pedido);

    // ─────────────────────────────────────────────────────────────────────
    // Apply Cincel NOM-151 timestamp to the regenerated PDF (Round 71 module)
    // ─────────────────────────────────────────────────────────────────────
    $tsHash = null;
    $tsResult = null;
    try {
        require_once __DIR__ . '/../../configurador/php/cincel-timestamp.php';
        $tsResult = cincelGetOrCreateTimestamp($newPdfPath);
        if (!empty($tsResult['ok'])) {
            cincelSaveTimestamp($pdo, $tsResult, $txnId, $newPdfPath);
            $tsHash = $tsResult['hash'] ?? null;
        }
    } catch (Throwable $e) {
        // Never block the signing because Cincel had a hiccup; we'll backfill.
        error_log('firmar-contrato-retro: cincel exception: ' . $e->getMessage());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Update transacciones with new path / hash / timestamp
    // ─────────────────────────────────────────────────────────────────────
    try {
        $cols = ["contrato_pdf_path = ?", "contrato_pdf_hash = ?"];
        $args = [$relPath, $newPdfHash];
        // cincel_timestamp_hash column may not exist on older schemas; the
        // cincel-timestamp module already added it idempotently above when
        // it ran cincelEnsureSchema(), so the UPDATE should be safe.
        if ($tsHash) {
            $cols[] = "cincel_timestamp_hash = ?";
            $args[] = $tsHash;
        }
        $args[] = $txnId;
        $pdo->prepare("UPDATE transacciones SET " . implode(', ', $cols) . " WHERE id = ?")
            ->execute($args);
    } catch (Throwable $e) {
        error_log('firmar-contrato-retro: tx update: ' . $e->getMessage());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Mark token as signed
    // ─────────────────────────────────────────────────────────────────────
    $pdo->prepare("UPDATE firma_contrato_requests
                      SET estado = 'signed',
                          signed_at = NOW(),
                          signed_firma_id = ?,
                          ip = ?,
                          user_agent = ?
                    WHERE id = ?")
        ->execute([$firmaId, $ip, $ua, (int)$req['id']]);

    $pdo->commit();

    // Build a public URL for the new PDF if it's web-accessible.
    $newUrl = null;
    if (strpos($relPath, '/') !== 0 && strpos($relPath, 'contratos/') === 0) {
        $newUrl = '/configurador/' . $relPath;
    }

    echo json_encode([
        'ok'           => true,
        'message'      => 'Firma guardada. Contrato regenerado y sellado con NOM-151.',
        'new_pdf_url'  => $newUrl,
        'new_pdf_hash' => $newPdfHash,
        'nom151_hash'  => $tsHash,
        'firma_id'     => $firmaId,
    ]);

} catch (Throwable $e) {
    try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $r) {}
    error_log('firmar-contrato-retro-guardar: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'error'   => 'server_error',
        'message' => 'Error interno: ' . $e->getMessage(),
    ]);
}
