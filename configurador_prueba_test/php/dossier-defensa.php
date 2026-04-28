<?php
/**
 * Voltika — Dossier de Defensa por Compra (Defense Dossier per Purchase).
 *
 * Per-VIN evidence pack assembled into ZIP + master index PDF for use in:
 *   - Stripe chargeback / dispute responses (auto-attach via Disputes API)
 *   - PROFECO administrative complaints
 *   - Civil litigation
 *   - Internal cancellation reviews
 *
 * Aggregates everything Voltika holds about one purchase:
 *
 *   00_INDICE.pdf                  — one-page summary (fits Stripe upload limits)
 *   01_contrato_compraventa.pdf    — contado v3 OR credit Carátula+v5 body
 *   02_caratula_credito.pdf        — credit-only (already in 01 for credit)
 *   03_pagare.pdf                  — credit-only, signed at delivery (Cincel)
 *   04_acta_entrega.pdf            — delivery confirmation (Cincel)
 *   05_carta_factura.pdf           — provisional invoice
 *   06_cfdi.xml + 06_cfdi.pdf      — final tax invoice
 *   07_repuve_aviso.json           — REPUVE notice receipt
 *   08_truora_identity.pdf         — INE + selfie + biometric verdict
 *   09_evidencia_tecnica.json      — IP, geolocation, UA, OTP timestamps
 *   10_logs_notificaciones.csv     — every SMS/email/WhatsApp sent
 *   11_logs_pagos.csv              — Stripe payment intents + retries
 *   12_fotos_entrega/              — physical delivery photos
 *   HASHES.txt                     — SHA-256 of every file + manifest signature
 *
 * Tamper-evident: every file is hashed; the manifest hash is sent to
 * Cincel for NOM-151 timestamping; the whole bundle is archived to S3
 * Object Lock (compliance mode, 10-year retention) via archivo-larga-duracion.
 *
 * Public functions:
 *   dossierEnsureSchema(PDO)
 *   dossierBuild(int $motoId, array $opts = []): array
 *   dossierLatestForPedido(string $pedido): ?array
 *   dossierDownloadToken(string $pedido, string $stripePi = ''): string
 *   dossierVerifyToken(string $pedido, string $stripePi, string $token): bool
 */

require_once __DIR__ . '/config.php';

// Locate FPDF — try multiple known install locations so we work in
// production (configurador_prueba/php/vendor) and in any test or
// admin-shared layout. Records the resolved path in a global so the
// diagnostic page can surface "which paths did we try?".
if (!class_exists('FPDF')) {
    $GLOBALS['_dossier_fpdf_tried'] = [];
    foreach ([
        __DIR__ . '/vendor/fpdf/fpdf.php',
        __DIR__ . '/vendor/setasign/fpdf/fpdf.php',
        __DIR__ . '/../../admin/php/lib/fpdf.php',
        __DIR__ . '/../../admin_test/php/lib/fpdf.php',
        // Fallback to a project-wide vendor (some installs share one)
        dirname(__DIR__, 2) . '/vendor/fpdf/fpdf.php',
        dirname(__DIR__, 2) . '/vendor/setasign/fpdf/fpdf.php',
    ] as $_p) {
        $GLOBALS['_dossier_fpdf_tried'][$_p] = file_exists($_p);
        if (file_exists($_p)) { require_once $_p; break; }
    }
    if (!class_exists('FPDF')) {
        $_a = __DIR__ . '/vendor/autoload.php';
        $GLOBALS['_dossier_fpdf_tried'][$_a] = file_exists($_a);
        if (file_exists($_a)) require_once $_a;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Schema
// ─────────────────────────────────────────────────────────────────────────

function dossierEnsureSchema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS dossiers_defensa (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pedido           VARCHAR(60)  NULL,
            pedido_corto     VARCHAR(40)  NULL,
            vin              VARCHAR(40)  NULL,
            transaccion_id   INT NULL,
            moto_id          INT NULL,
            cliente_id       INT NULL,
            stripe_pi        VARCHAR(80)  NULL,
            zip_path         VARCHAR(500) NULL,
            zip_sha256       CHAR(64)     NULL,
            master_pdf_path  VARCHAR(500) NULL,
            master_pdf_sha256 CHAR(64)    NULL,
            archivo_zip_id   INT NULL,
            archivo_pdf_id   INT NULL,
            cincel_id        VARCHAR(120) NULL,
            componentes_json MEDIUMTEXT   NULL,
            motivo           VARCHAR(60)  NOT NULL DEFAULT 'auto_post_delivery',
              -- auto_post_delivery | manual | chargeback_response | profeco | regenerate
            stripe_dispute_id VARCHAR(80) NULL,
            enviado_a_stripe TINYINT(1)   NOT NULL DEFAULT 0,
            enviado_at       DATETIME NULL,
            generado_en      DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pedido (pedido),
            INDEX idx_vin (vin),
            INDEX idx_moto (moto_id),
            INDEX idx_dispute (stripe_dispute_id),
            INDEX idx_motivo (motivo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('dossierEnsureSchema: ' . $e->getMessage()); }
}

// ─────────────────────────────────────────────────────────────────────────
// Output paths + token
// ─────────────────────────────────────────────────────────────────────────

function _dossierOutputDir(): string {
    $dir = __DIR__ . '/../dossiers';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        // Try chmod even if mkdir failed — common case where parent
        // already has restrictive perms but the dir got created by
        // a previous run as a different user.
        @chmod($dir, 0775);
    }
    // Last-resort fallback: write to system temp if local dir is not
    // writable (still usable for download but not persistent across
    // server reboots — surfaces clearly in diagnostic page).
    if (!is_writable($dir)) {
        $alt = sys_get_temp_dir() . '/voltika_dossiers';
        if (!is_dir($alt)) @mkdir($alt, 0777, true);
        if (is_writable($alt)) {
            $GLOBALS['_dossier_using_temp_dir'] = true;
            return $alt;
        }
    }
    return $dir;
}

function _dossierSanitize(string $s): string {
    return preg_replace('/[^A-Za-z0-9_-]/', '_', $s);
}

function _dossierPaths(string $pedido, string $vin, int $version = 1): array {
    $base = _dossierSanitize($pedido) . '_' . _dossierSanitize($vin) . '_v' . $version;
    return [
        'work_dir' => sys_get_temp_dir() . '/voltika_dossier_build_' . $base . '_' . bin2hex(random_bytes(3)),
        'zip'      => _dossierOutputDir() . '/voltika_defensa_' . $base . '.zip',
        'pdf'      => _dossierOutputDir() . '/voltika_defensa_' . $base . '.pdf',
        'rel_zip'  => 'dossiers/voltika_defensa_' . $base . '.zip',
        'rel_pdf'  => 'dossiers/voltika_defensa_' . $base . '.pdf',
    ];
}

function dossierDownloadToken(string $pedido, string $stripePi = ''): string {
    $key = defined('SMTP_PASS') ? (string)SMTP_PASS : 'voltika-secret';
    return substr(hash_hmac('sha256', 'dossier|' . $pedido . '|' . $stripePi, $key), 0, 16);
}

function dossierVerifyToken(string $pedido, string $stripePi, string $token): bool {
    if ($token === '') return false;
    return hash_equals(dossierDownloadToken($pedido, $stripePi), $token);
}

// ─────────────────────────────────────────────────────────────────────────
// Main entry: build dossier
// ─────────────────────────────────────────────────────────────────────────

/**
 * Build a defense dossier for a moto (and its associated purchase).
 *
 * $opts:
 *   motivo              - 'manual' | 'chargeback_response' | 'profeco' | 'regenerate' | 'auto_post_delivery'
 *   stripe_dispute_id   - if motivo = chargeback_response, attach to this dispute
 *   archive_to_s3       - true (default) → also push to long-term storage
 *   timestamp_with_cincel - true (default) → NOM-151 timestamp the manifest
 *
 * Returns: ['ok' => bool, 'dossier_id' => int, 'zip_path', 'pdf_path',
 *           'zip_hash', 'pdf_hash', 'componentes' => [...], 'error' => null]
 */
function dossierBuild(int $motoId, array $opts = []): array {
    if ($motoId <= 0) return ['ok' => false, 'error' => 'moto_id requerido'];
    $pdo = getDB();
    dossierEnsureSchema($pdo);

    // ─── 1. Resolve all the data scattered across tables ────────────────
    $ctx = _dossierLoadContext($pdo, $motoId);
    if (!$ctx['ok']) return ['ok' => false, 'error' => $ctx['error']];

    $pedido = (string)($ctx['transaccion']['pedido'] ?? ('moto_' . $motoId));
    $vin    = (string)($ctx['moto']['vin'] ?? 'VIN_PENDIENTE');

    // Pick the next version number for this pedido+VIN.
    $verStmt = $pdo->prepare("SELECT COUNT(*) FROM dossiers_defensa WHERE pedido = ? AND vin = ?");
    $verStmt->execute([$pedido, $vin]);
    $version = ((int)$verStmt->fetchColumn()) + 1;

    $paths = _dossierPaths($pedido, $vin, $version);
    @mkdir($paths['work_dir'], 0775, true);

    // ─── 2. Collect all evidence files into the work dir ────────────────
    $componentes = _dossierCollect($ctx, $paths['work_dir']);

    // ─── 3. Build the master index PDF ──────────────────────────────────
    $masterPdfTmp = $paths['work_dir'] . '/00_INDICE.pdf';
    _dossierBuildMasterPdf($masterPdfTmp, $ctx, $componentes, $pedido, $vin, $version);

    // ─── 4. Build the HASHES.txt manifest ───────────────────────────────
    $manifestLines = [];
    $manifestLines[] = "VOLTIKA — DOSSIER DE DEFENSA · MANIFEST";
    $manifestLines[] = "Pedido: {$pedido}";
    $manifestLines[] = "VIN: {$vin}";
    $manifestLines[] = "Versión: {$version}";
    $manifestLines[] = "Generado: " . gmdate('Y-m-d H:i:s') . " UTC";
    $manifestLines[] = "Motivo: " . ($opts['motivo'] ?? 'auto_post_delivery');
    $manifestLines[] = str_repeat('─', 70);
    $manifestLines[] = sprintf("%-64s  %s", "SHA-256", "ARCHIVO");
    $manifestLines[] = str_repeat('─', 70);

    $files = _dossierListFiles($paths['work_dir']);
    foreach ($files as $rel => $abs) {
        $h = hash_file('sha256', $abs);
        $manifestLines[] = sprintf("%s  %s", $h, $rel);
    }

    $manifestText = implode("\n", $manifestLines) . "\n";
    file_put_contents($paths['work_dir'] . '/HASHES.txt', $manifestText);
    $manifestHash = hash('sha256', $manifestText);

    // ─── 5. Cincel timestamp the manifest hash (NOM-151 anchor) ─────────
    $cincelId = null;
    if (($opts['timestamp_with_cincel'] ?? true) === true) {
        $cincelId = _dossierCincelTimestamp($manifestHash, $pedido, $vin);
    }

    // ─── 6. Zip the work dir ────────────────────────────────────────────
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'error' => 'PHP ZipArchive no disponible — habilitar ext-zip'];
    }
    $zip = new ZipArchive();
    if ($zip->open($paths['zip'], ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['ok' => false, 'error' => 'No se pudo crear ZIP en ' . $paths['zip']];
    }
    foreach ($files as $rel => $abs) $zip->addFile($abs, $rel);
    $zip->addFile($paths['work_dir'] . '/HASHES.txt', 'HASHES.txt');
    $zip->setArchiveComment(
        "Voltika · Dossier de Defensa\n"
        . "Pedido: {$pedido}\n"
        . "VIN: {$vin}\n"
        . "Manifest SHA-256: {$manifestHash}\n"
        . ($cincelId ? "Cincel timestamp: {$cincelId}\n" : '')
    );
    $zip->close();

    // Also publish a stand-alone copy of the master PDF (Stripe upload)
    @copy($masterPdfTmp, $paths['pdf']);

    if (!file_exists($paths['zip']) || !file_exists($paths['pdf'])) {
        return ['ok' => false, 'error' => 'Salida no escrita'];
    }
    $zipHash = hash_file('sha256', $paths['zip']);
    $pdfHash = hash_file('sha256', $paths['pdf']);

    // ─── 7. (Optional) Archive to S3 for long-term tamper-evident hold ──
    $archivoZipId = null;
    $archivoPdfId = null;
    if (($opts['archive_to_s3'] ?? true) === true) {
        $archivePath = __DIR__ . '/archivo-larga-duracion.php';
        if (file_exists($archivePath)) {
            require_once $archivePath;
            try {
                $r1 = archivoUploadPDF($paths['pdf'], [
                    'tipo' => 'dossier_pdf', 'referencia' => $pedido . '_v' . $version,
                    'transaccion_id' => $ctx['transaccion']['id'] ?? null,
                    'moto_id' => $motoId,
                    'cliente_id' => $ctx['cliente']['id'] ?? null,
                ]);
                if ($r1['ok']) $archivoPdfId = $r1['archivo_id'];
                // Re-use the same archival function for the ZIP (it accepts any binary).
                $r2 = archivoUploadPDF($paths['zip'], [
                    'tipo' => 'dossier_zip', 'referencia' => $pedido . '_v' . $version,
                    'transaccion_id' => $ctx['transaccion']['id'] ?? null,
                    'moto_id' => $motoId,
                    'cliente_id' => $ctx['cliente']['id'] ?? null,
                ]);
                if ($r2['ok']) $archivoZipId = $r2['archivo_id'];
            } catch (Throwable $e) { error_log('dossier archive: ' . $e->getMessage()); }
        }
    }

    // ─── 8. Persist the dossier record ──────────────────────────────────
    $insert = $pdo->prepare("INSERT INTO dossiers_defensa
            (pedido, pedido_corto, vin, transaccion_id, moto_id, cliente_id, stripe_pi,
             zip_path, zip_sha256, master_pdf_path, master_pdf_sha256,
             archivo_zip_id, archivo_pdf_id, cincel_id,
             componentes_json, motivo, stripe_dispute_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $insert->execute([
        $pedido,
        $ctx['pedido_corto'] ?? null,
        $vin,
        $ctx['transaccion']['id'] ?? null,
        $motoId,
        $ctx['cliente']['id'] ?? null,
        $ctx['transaccion']['stripe_pi'] ?? null,
        $paths['rel_zip'], $zipHash,
        $paths['rel_pdf'], $pdfHash,
        $archivoZipId, $archivoPdfId, $cincelId,
        json_encode($componentes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        $opts['motivo'] ?? 'auto_post_delivery',
        $opts['stripe_dispute_id'] ?? null,
    ]);
    $dossierId = (int)$pdo->lastInsertId();

    // Clean up work dir.
    _dossierRmTree($paths['work_dir']);

    return [
        'ok'              => true,
        'dossier_id'      => $dossierId,
        'pedido'          => $pedido,
        'vin'             => $vin,
        'version'         => $version,
        'zip_path'        => $paths['rel_zip'],
        'pdf_path'        => $paths['rel_pdf'],
        'zip_hash'        => $zipHash,
        'pdf_hash'        => $pdfHash,
        'manifest_hash'   => $manifestHash,
        'cincel_id'       => $cincelId,
        'componentes'     => $componentes,
        'archivo_zip_id'  => $archivoZipId,
        'archivo_pdf_id'  => $archivoPdfId,
    ];
}

/**
 * Latest dossier metadata for a pedido (or null).
 */
function dossierLatestForPedido(string $pedido): ?array {
    $pdo = getDB();
    dossierEnsureSchema($pdo);
    $st = $pdo->prepare("SELECT * FROM dossiers_defensa WHERE pedido = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$pedido]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

// ─────────────────────────────────────────────────────────────────────────
// Internal — context loading
// ─────────────────────────────────────────────────────────────────────────

function _dossierLoadContext(PDO $pdo, int $motoId): array {
    $ctx = ['ok' => true, 'moto_id' => $motoId];

    // Moto + delivery point
    $stmt = $pdo->prepare("SELECT m.*, pv.nombre AS punto_nombre_pv, pv.direccion AS punto_direccion
        FROM inventario_motos m
        LEFT JOIN puntos_voltika pv ON pv.id = m.punto_voltika_id
        WHERE m.id = ?");
    $stmt->execute([$motoId]);
    $ctx['moto'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$ctx['moto']) return ['ok' => false, 'error' => 'Moto no encontrada (id=' . $motoId . ')'];

    // Transaction
    $ctx['transaccion'] = null;
    if (!empty($ctx['moto']['transaccion_id'])) {
        $st = $pdo->prepare("SELECT * FROM transacciones WHERE id = ?");
        $st->execute([(int)$ctx['moto']['transaccion_id']]);
        $ctx['transaccion'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$ctx['transaccion'] && !empty($ctx['moto']['cliente_email'])) {
        $st = $pdo->prepare("SELECT * FROM transacciones WHERE email = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$ctx['moto']['cliente_email']]);
        $ctx['transaccion'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $ctx['transaccion'] = $ctx['transaccion'] ?: ['id' => null];

    // Pedido short code
    $ctx['pedido_corto'] = null;
    if (!empty($ctx['transaccion']['id']) && function_exists('voltikaResolvePedidoCorto')) {
        try { $ctx['pedido_corto'] = voltikaResolvePedidoCorto($pdo, (int)$ctx['transaccion']['id']); }
        catch (Throwable $e) {}
    }

    // Cliente master
    $ctx['cliente'] = null;
    if (!empty($ctx['moto']['cliente_id'])) {
        $st = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
        $st->execute([(int)$ctx['moto']['cliente_id']]);
        $ctx['cliente'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Subscription (credit only)
    $ctx['subscripcion'] = null;
    if (!empty($ctx['moto']['cliente_id'])) {
        $st = $pdo->prepare("SELECT * FROM subscripciones_credito
                              WHERE cliente_id = ?
                              ORDER BY id DESC LIMIT 1");
        $st->execute([(int)$ctx['moto']['cliente_id']]);
        $ctx['subscripcion'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Checklist (Acta + Pagaré + Carta Factura paths + OTP evidence + delivery photos)
    $ctx['checklist'] = null;
    $st = $pdo->prepare("SELECT * FROM checklist_entrega_v2 WHERE moto_id = ? ORDER BY freg DESC LIMIT 1");
    $st->execute([$motoId]);
    $ctx['checklist'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    // CFDI emitido
    $ctx['cfdi'] = null;
    if (!empty($ctx['transaccion']['id'])) {
        try {
            $st = $pdo->prepare("SELECT * FROM cfdi_emitidos WHERE transaccion_id = ?
                                  AND estado IN ('emitido','pendiente_pac')
                                  ORDER BY id DESC LIMIT 1");
            $st->execute([(int)$ctx['transaccion']['id']]);
            $ctx['cfdi'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { /* table may not exist yet */ }
    }

    // REPUVE aviso
    $ctx['repuve'] = null;
    if (!empty($ctx['moto']['vin']) || !empty($ctx['transaccion']['id'])) {
        try {
            $st = $pdo->prepare("SELECT * FROM repuve_avisos
                WHERE (vin = ? OR transaccion_id = ?) ORDER BY id DESC LIMIT 1");
            $st->execute([$ctx['moto']['vin'] ?? '', $ctx['transaccion']['id'] ?? 0]);
            $ctx['repuve'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {}
    }

    // Truora identity verification
    $ctx['truora'] = null;
    try {
        if (!empty($ctx['moto']['cliente_email']) || !empty($ctx['cliente']['email'])) {
            $email = $ctx['cliente']['email'] ?? $ctx['moto']['cliente_email'];
            $st = $pdo->prepare("SELECT * FROM verificaciones_identidad
                                  WHERE email = ? ORDER BY id DESC LIMIT 1");
            $st->execute([$email]);
            $ctx['truora'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (Throwable $e) {}

    // Notification log
    $ctx['notif_log'] = [];
    try {
        $email = $ctx['cliente']['email'] ?? $ctx['transaccion']['email'] ?? $ctx['moto']['cliente_email'] ?? '';
        $tel   = $ctx['cliente']['telefono'] ?? $ctx['transaccion']['telefono'] ?? $ctx['moto']['cliente_telefono'] ?? '';
        if ($email || $tel) {
            $st = $pdo->prepare("SELECT freg, tipo, canal, destino, mensaje, status, error
                FROM voltika_notificaciones
                WHERE destino = ? OR destino = ?
                ORDER BY freg DESC LIMIT 200");
            $st->execute([$email, $tel]);
            $ctx['notif_log'] = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {}

    // Payment intents log (Stripe)
    $ctx['pagos_log'] = [];
    if (!empty($ctx['transaccion']['stripe_pi'])) {
        try {
            $st = $pdo->prepare("SELECT * FROM stripe_payment_intents
                WHERE stripe_pi = ? ORDER BY id DESC LIMIT 50");
            $st->execute([$ctx['transaccion']['stripe_pi']]);
            $ctx['pagos_log'] = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { /* table may be named differently */ }
    }
    // Fallback: list ciclos_pago for credit subscriptions
    if (empty($ctx['pagos_log']) && !empty($ctx['subscripcion']['id'])) {
        try {
            $st = $pdo->prepare("SELECT id, semana_num, monto, fecha_vencimiento, fecha_pago,
                                        estado, stripe_payment_intent, origen
                                 FROM ciclos_pago
                                 WHERE subscripcion_id = ?
                                 ORDER BY semana_num ASC LIMIT 100");
            $st->execute([(int)$ctx['subscripcion']['id']]);
            $ctx['pagos_log'] = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    }

    // Escalations (chargebacks etc.)
    $ctx['escalations'] = [];
    try {
        $st = $pdo->prepare("SELECT * FROM escalations
            WHERE transaccion_id = ? OR moto_id = ? OR cliente_id = ?
            ORDER BY freg DESC");
        $st->execute([
            $ctx['transaccion']['id'] ?? 0,
            $motoId,
            $ctx['cliente']['id'] ?? 0,
        ]);
        $ctx['escalations'] = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    return $ctx;
}

// ─────────────────────────────────────────────────────────────────────────
// Internal — gather files into the work dir
// ─────────────────────────────────────────────────────────────────────────

function _dossierCollect(array $ctx, string $workDir): array {
    $componentes = [];
    $tx   = $ctx['transaccion'] ?? [];
    $moto = $ctx['moto']        ?? [];
    $chk  = $ctx['checklist']   ?? [];
    $tpago = strtolower((string)($tx['tpago'] ?? ''));
    $isCredit = in_array($tpago, ['credito', 'enganche', 'parcial'], true);

    // 01 — Contrato de Compraventa
    if (!$isCredit) {
        // contado contract
        $contratoPath = trim((string)($tx['contrato_pdf_path'] ?? ''));
        if ($contratoPath !== '') {
            $abs = __DIR__ . '/../' . ltrim($contratoPath, '/');
            if (file_exists($abs)) {
                copy($abs, $workDir . '/01_contrato_compraventa.pdf');
                $componentes['01_contrato_compraventa.pdf'] = [
                    'tipo' => 'contrato_contado_v3',
                    'sha256' => hash_file('sha256', $abs),
                    'aceptado_at' => $tx['contrato_aceptado_at'] ?? null,
                    'aceptado_ip' => $tx['contrato_aceptado_ip'] ?? null,
                    'aceptado_ua' => $tx['contrato_aceptado_ua'] ?? null,
                    'geolocation' => $tx['contrato_geolocation'] ?? null,
                    'otp_validado' => (bool)($tx['contrato_otp_validated'] ?? 0),
                ];
            }
        }
    } else {
        // credit contract — generated to $uploadDir/contratos by generar-contrato-pdf.php
        $contratosDir = __DIR__ . '/../uploads/contratos';
        if (is_dir($contratosDir)) {
            $folio = 'VK-' . preg_replace('/[^A-Za-z0-9]/', '', (string)($tx['nombre'] ?? '')) . '-';
            $candidates = glob($contratosDir . '/contrato_*' . preg_replace('/[^A-Za-z0-9]/', '', (string)($tx['pedido'] ?? '')) . '*.pdf') ?: [];
            if (empty($candidates)) {
                // Fallback: latest 5 files; pick first that contains the customer's first 6 chars of name
                $candidates = glob($contratosDir . '/contrato_*.pdf') ?: [];
                rsort($candidates);
                $candidates = array_slice($candidates, 0, 1);
            }
            if (!empty($candidates) && file_exists($candidates[0])) {
                copy($candidates[0], $workDir . '/01_contrato_compraventa_a_plazos.pdf');
                $componentes['01_contrato_compraventa_a_plazos.pdf'] = [
                    'tipo' => 'contrato_credito_v5',
                    'sha256' => hash_file('sha256', $candidates[0]),
                ];
            }
        }
    }

    // 03 — Pagaré (credit only)
    if ($isCredit && !empty($chk['pagare_pdf_path'])) {
        $pagPath = sys_get_temp_dir() . '/voltika_pagares/' . basename($chk['pagare_pdf_path']);
        if (file_exists($pagPath)) {
            copy($pagPath, $workDir . '/03_pagare.pdf');
            $componentes['03_pagare.pdf'] = [
                'tipo' => 'pagare_ejecutivo',
                'sha256' => hash_file('sha256', $pagPath),
                'cincel_id' => $chk['firma_pagare_cincel_id'] ?? null,
                'firmado_at' => $chk['firma_pagare_timestamp'] ?? null,
                'evidencia'  => json_decode($chk['pagare_evidencia'] ?? 'null', true),
            ];
        }
    }

    // 04 — Acta de Entrega
    if (!empty($chk['acta_pdf_path'])) {
        $actaPath = sys_get_temp_dir() . '/voltika_actas/' . basename($chk['acta_pdf_path']);
        if (file_exists($actaPath)) {
            copy($actaPath, $workDir . '/04_acta_entrega.pdf');
            $componentes['04_acta_entrega.pdf'] = [
                'tipo' => 'acta_entrega',
                'sha256' => hash_file('sha256', $actaPath),
                'cincel_id' => $chk['acta_pdf_cincel_id'] ?? null,
                'firmado_at' => $chk['acta_pdf_timestamp']  ?? null,
                'evidencia'  => json_decode($chk['acta_evidencia'] ?? 'null', true),
            ];
        }
    }

    // 05 — Carta Factura
    if (!empty($chk['carta_factura_pdf_path'])) {
        $cfPath = sys_get_temp_dir() . '/voltika_carta_factura/' . basename($chk['carta_factura_pdf_path']);
        if (file_exists($cfPath)) {
            copy($cfPath, $workDir . '/05_carta_factura.pdf');
            $componentes['05_carta_factura.pdf'] = [
                'tipo' => 'carta_factura',
                'sha256' => hash_file('sha256', $cfPath),
                'folio'  => $chk['carta_factura_folio'] ?? null,
                'emitida_at' => $chk['carta_factura_fecha'] ?? null,
            ];
        }
    }

    // 06 — CFDI 4.0 (XML + PDF)
    if (!empty($ctx['cfdi'])) {
        $cfdi = $ctx['cfdi'];
        $componentes['06_cfdi.json'] = [
            'tipo'  => 'cfdi_metadata',
            'uuid'  => $cfdi['uuid'] ?? null,
            'folio' => $cfdi['folio'] ?? null,
            'serie' => $cfdi['serie'] ?? null,
            'estado' => $cfdi['estado'] ?? null,
            'pac_provider' => $cfdi['pac_provider'] ?? null,
        ];
        file_put_contents($workDir . '/06_cfdi.json',
            json_encode($cfdi, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    // 07 — REPUVE notice
    if (!empty($ctx['repuve'])) {
        $repuve = $ctx['repuve'];
        file_put_contents($workDir . '/07_repuve_aviso.json',
            json_encode($repuve, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $componentes['07_repuve_aviso.json'] = [
            'tipo' => 'repuve_notice',
            'folio_repuve' => $repuve['folio_repuve'] ?? null,
            'estado' => $repuve['estado'] ?? null,
            'enviado_at' => $repuve['fenviado'] ?? null,
        ];
    }

    // 08 — Truora identity verification
    if (!empty($ctx['truora'])) {
        file_put_contents($workDir . '/08_truora_identity.json',
            json_encode($ctx['truora'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $componentes['08_truora_identity.json'] = [
            'tipo' => 'truora_kyc',
            'estado' => $ctx['truora']['identity_status'] ?? null,
            'approved' => (bool)($ctx['truora']['approved'] ?? 0),
            'process_id' => $ctx['truora']['process_id'] ?? null,
        ];
    }

    // 09 — Evidencia técnica (composite)
    $evidencia = [
        'pedido'           => $tx['pedido'] ?? null,
        'pedido_corto'     => $ctx['pedido_corto'] ?? null,
        'transaccion_id'   => $tx['id'] ?? null,
        'moto_id'          => $ctx['moto_id'],
        'vin'              => $moto['vin'] ?? null,
        'fecha_compra'     => $tx['freg'] ?? null,
        'monto_total'      => $tx['total'] ?? null,
        'modalidad_pago'   => $tx['tpago'] ?? null,
        'stripe_pi'        => $tx['stripe_pi'] ?? null,
        'pago_estado'      => $tx['pago_estado'] ?? null,
        'cliente' => [
            'nombre'   => $tx['nombre'] ?? null,
            'email'    => $tx['email']  ?? null,
            'telefono' => $tx['telefono'] ?? null,
            'rfc'      => $ctx['cliente']['rfc'] ?? null,
            'curp'     => $ctx['cliente']['curp'] ?? null,
            'domicilio'=> $ctx['cliente']['domicilio'] ?? null,
        ],
        'aceptacion_contrato' => [
            'aceptado_at'  => $tx['contrato_aceptado_at'] ?? null,
            'aceptado_ip'  => $tx['contrato_aceptado_ip'] ?? null,
            'user_agent'   => $tx['contrato_aceptado_ua'] ?? null,
            'geolocation'  => $tx['contrato_geolocation'] ?? null,
            'otp_validado' => (bool)($tx['contrato_otp_validated'] ?? 0),
            'sha256_pdf'   => $tx['contrato_pdf_hash'] ?? null,
        ],
        'entrega' => [
            'punto_nombre'  => $moto['punto_nombre'] ?? $moto['punto_nombre_pv'] ?? null,
            'fecha_entrega' => $chk['fase5_fecha'] ?? null,
            'otp_code'      => $chk['otp_code']    ?? null,
            'otp_validado_at' => $chk['otp_timestamp'] ?? null,
            'pagare' => [
                'sha256'    => $chk['pagare_pdf_hash'] ?? null,
                'cincel_id' => $chk['firma_pagare_cincel_id'] ?? null,
                'ip'        => $chk['pagare_ip']  ?? null,
                'firmado_at'=> $chk['firma_pagare_timestamp'] ?? null,
            ],
            'acta' => [
                'sha256'    => $chk['acta_pdf_hash'] ?? null,
                'cincel_id' => $chk['acta_pdf_cincel_id'] ?? null,
                'ip'        => $chk['acta_ip']  ?? null,
                'firmado_at'=> $chk['acta_pdf_timestamp'] ?? null,
            ],
        ],
        'cfdi'    => $ctx['cfdi']   ?? null,
        'repuve'  => $ctx['repuve'] ?? null,
        'truora_resumen' => isset($ctx['truora']) && $ctx['truora']
            ? ['approved' => (bool)($ctx['truora']['approved'] ?? 0),
               'estado'  => $ctx['truora']['identity_status'] ?? null]
            : null,
    ];
    file_put_contents($workDir . '/09_evidencia_tecnica.json',
        json_encode($evidencia, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $componentes['09_evidencia_tecnica.json'] = ['tipo' => 'evidencia_consolidada'];

    // 10 — Notification log → CSV
    $csv = "fecha_hora,tipo,canal,destino,status,mensaje\n";
    foreach (($ctx['notif_log'] ?? []) as $n) {
        $msg = str_replace(["\n", "\r", '"'], [' ', ' ', "'"], (string)($n['mensaje'] ?? ''));
        $csv .= sprintf("%s,%s,%s,%s,%s,\"%s\"\n",
            $n['freg'] ?? '', $n['tipo'] ?? '', $n['canal'] ?? '',
            $n['destino'] ?? '', $n['status'] ?? '', $msg);
    }
    file_put_contents($workDir . '/10_logs_notificaciones.csv', $csv);
    $componentes['10_logs_notificaciones.csv'] = [
        'tipo' => 'notification_log',
        'rows' => count($ctx['notif_log'] ?? []),
    ];

    // 11 — Payment log → CSV
    $csv = "fecha,evento,monto,estado,referencia\n";
    foreach (($ctx['pagos_log'] ?? []) as $p) {
        $csv .= sprintf("%s,%s,%s,%s,%s\n",
            $p['fecha_pago'] ?? ($p['fecha_vencimiento'] ?? ($p['freg'] ?? '')),
            'ciclo_' . ($p['semana_num'] ?? '0'),
            $p['monto'] ?? '0',
            $p['estado'] ?? '',
            $p['stripe_payment_intent'] ?? '');
    }
    if ($tx) {
        $csv .= sprintf("%s,compra_inicial,%s,%s,%s\n",
            $tx['freg'] ?? '', $tx['total'] ?? '0', $tx['pago_estado'] ?? '', $tx['stripe_pi'] ?? '');
    }
    file_put_contents($workDir . '/11_logs_pagos.csv', $csv);
    $componentes['11_logs_pagos.csv'] = [
        'tipo' => 'payment_log',
        'rows' => count($ctx['pagos_log'] ?? []) + ($tx ? 1 : 0),
    ];

    // 12 — Delivery photos
    $checklistsDir = sys_get_temp_dir() . '/voltika_checklists/';
    if (is_dir($checklistsDir) && !empty($chk['id'])) {
        try {
            $pdo = getDB();
            $st = $pdo->prepare("SELECT * FROM checklist_fotos_v2 WHERE checklist_id = ? ORDER BY id ASC");
            $st->execute([(int)$chk['id']]);
            $fotos = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $fotos = []; }
        if (!empty($fotos)) {
            @mkdir($workDir . '/12_fotos_entrega', 0775, true);
            foreach ($fotos as $i => $f) {
                $fname = basename((string)($f['archivo'] ?? ($f['filename'] ?? '')));
                if (!$fname) continue;
                $abs = $checklistsDir . $fname;
                if (file_exists($abs)) {
                    $dest = sprintf('%s/12_fotos_entrega/%02d_%s', $workDir, $i + 1, $fname);
                    copy($abs, $dest);
                    $componentes['12_fotos_entrega/' . sprintf('%02d_%s', $i + 1, $fname)] = [
                        'tipo' => 'foto_entrega',
                        'campo' => $f['campo'] ?? null,
                        'sha256' => hash_file('sha256', $abs),
                    ];
                }
            }
        }
    }

    // Escalations summary (informational)
    if (!empty($ctx['escalations'])) {
        file_put_contents($workDir . '/escalations_relacionadas.json',
            json_encode($ctx['escalations'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $componentes['escalations_relacionadas.json'] = [
            'tipo' => 'escalations',
            'count' => count($ctx['escalations']),
        ];
    }

    return $componentes;
}

// ─────────────────────────────────────────────────────────────────────────
// Internal — Master index PDF (the one Stripe gets uploaded)
// ─────────────────────────────────────────────────────────────────────────

function _dossierBuildMasterPdf(string $outPath, array $ctx, array $componentes,
                                 string $pedido, string $vin, int $version): void {
    $enc = function($s) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string)$s); };
    $fmt = function($v) { return $v === null || $v === '' ? '—' : (string)$v; };
    $fmtMoney = function($v) { return '$' . number_format((float)($v ?: 0), 2, '.', ',') . ' MXN'; };

    $pdf = new FPDF('P', 'mm', 'Letter');
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->SetTitle($enc('Dossier de Defensa - Voltika'));
    $pdf->SetAuthor('Voltika - MTECH GEARS, S.A. DE C.V.');
    $pdf->AddPage();

    // Header bar
    $pdf->SetFillColor(26, 58, 92);
    $pdf->Rect(0, 0, 215.9, 12, 'F');
    $pdf->SetTextColor(255);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetXY(15, 3.5);
    $pdf->Cell(0, 5, $enc('VOLTIKA · DOSSIER DE DEFENSA'), 0, 0, 'L');
    $pdf->SetTextColor(0);
    $pdf->SetY(18);

    // Subheader
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(95, 4, $enc('FOLIO PEDIDO: ' . ($ctx['pedido_corto'] ?: $pedido)), 0, 0);
    $pdf->Cell(95, 4, $enc('GENERADO: ' . gmdate('d/m/Y H:i') . ' UTC'), 0, 1, 'R');
    $pdf->Cell(95, 4, $enc('VIN: ' . $vin), 0, 0);
    $pdf->Cell(95, 4, $enc('Versión dossier: v' . $version), 0, 1, 'R');
    $pdf->Ln(3);

    $pdf->SetFont('Arial', 'B', 13);
    $pdf->Cell(0, 7, $enc('RESUMEN EJECUTIVO DE LA OPERACIÓN'), 0, 1, 'C');
    $pdf->SetFont('Arial', 'I', 7.5);
    $pdf->SetTextColor(120);
    $pdf->MultiCell(0, 3.6, $enc('Documento consolidado generado automáticamente por el sistema Voltika para sustentar la legitimidad de la operación comercial. Cumple con NOM-151-SCFI-2016 (mensajes de datos), artículos 89-95 del Código de Comercio (firma electrónica) y disposiciones aplicables a contracargos y disputas. Adjunto integral en archivo ZIP con manifiesto SHA-256.'), 0, 'J');
    $pdf->SetTextColor(0);
    $pdf->Ln(2);

    // ─── DATOS DE LA OPERACIÓN ────────────────────────────────────────
    _dossierH2($pdf, 'DATOS DE LA OPERACIÓN');
    $tx = $ctx['transaccion'];
    $cli = $ctx['cliente'] ?? [];
    _dossierTable($pdf, [
        ['Folio del pedido',          $ctx['pedido_corto'] ?: $pedido],
        ['VIN / NIV del vehículo',    $vin],
        ['Modelo · color',            ($ctx['moto']['modelo'] ?? '—') . ' · ' . ($ctx['moto']['color'] ?? '—')],
        ['Cliente (nombre completo)', $tx['nombre'] ?? '—'],
        ['Correo electrónico',        $tx['email']    ?? '—'],
        ['Teléfono',                  $tx['telefono'] ?? '—'],
        ['RFC · CURP',                ($cli['rfc'] ?? '—') . ' · ' . ($cli['curp'] ?? '—')],
        ['Modalidad de pago',         strtoupper($tx['tpago'] ?? '—')],
        ['Monto total cobrado',       $fmtMoney($tx['total'] ?? 0)],
        ['Stripe PaymentIntent',      $tx['stripe_pi'] ?? '—'],
        ['Fecha de compra',           $tx['freg'] ?? '—'],
        ['Estado de pago',            strtoupper($tx['pago_estado'] ?? '—')],
    ], $enc);

    // ─── ACEPTACIÓN ELECTRÓNICA DEL CONTRATO ─────────────────────────
    _dossierH2($pdf, 'ACEPTACIÓN ELECTRÓNICA DEL CONTRATO (artículo 89 Código de Comercio)');
    _dossierTable($pdf, [
        ['Checkbox aceptado en',      $tx['contrato_aceptado_at']  ?? '—'],
        ['Dirección IP',              $tx['contrato_aceptado_ip']  ?? '—'],
        ['Geolocalización',           $tx['contrato_geolocation']  ?? 'No proporcionada'],
        ['User-Agent (dispositivo)',  substr($tx['contrato_aceptado_ua'] ?? '—', 0, 90)],
        ['Código OTP validado',       ($tx['contrato_otp_validated'] ?? 0) ? 'Sí' : 'No'],
        ['SHA-256 contrato firmado',  $tx['contrato_pdf_hash'] ?? '—'],
    ], $enc);

    // ─── VALIDACIÓN DE IDENTIDAD (TRUORA) ────────────────────────────
    if (!empty($ctx['truora'])) {
        _dossierH2($pdf, 'VALIDACIÓN DE IDENTIDAD (Truora — INE + biometría)');
        _dossierTable($pdf, [
            ['Process ID Truora',     $ctx['truora']['process_id']      ?? '—'],
            ['Estado',                $ctx['truora']['identity_status'] ?? '—'],
            ['Aprobado',              ($ctx['truora']['approved'] ?? 0) ? 'Sí' : 'No'],
            ['Razón decline',         $ctx['truora']['declined_reason'] ?? '—'],
            ['Validado en',           $ctx['truora']['freg']            ?? '—'],
        ], $enc);
    }

    // ─── ENTREGA FÍSICA ──────────────────────────────────────────────
    $chk = $ctx['checklist'] ?? [];
    if (!empty($chk['fase5_fecha'])) {
        _dossierH2($pdf, 'ENTREGA FÍSICA DEL VEHÍCULO');
        _dossierTable($pdf, [
            ['Fecha y hora de entrega',  $chk['fase5_fecha']         ?? '—'],
            ['Punto de entrega',         $ctx['moto']['punto_nombre']?? ($ctx['moto']['punto_nombre_pv'] ?? '—')],
            ['Personal de entrega',      $chk['dealer_nombre_firma'] ?? '—'],
            ['OTP validado en entrega',  ($chk['fase4_completada'] ?? 0) ? 'Sí — código ' . ($chk['otp_code'] ?? '****') : 'No'],
            ['Pagaré firmado',           !empty($chk['firma_pagare_timestamp'])
                                         ? 'Sí · Cincel ' . ($chk['firma_pagare_cincel_id'] ?? 'pendiente')
                                         : ($tx['tpago'] === 'credito' ? 'PENDIENTE' : 'No aplica (contado)')],
            ['Acta de Entrega firmada',  !empty($chk['acta_pdf_timestamp'])
                                         ? 'Sí · Cincel ' . ($chk['acta_pdf_cincel_id'] ?? 'pendiente')
                                         : 'PENDIENTE'],
            ['SHA-256 Pagaré',           $chk['pagare_pdf_hash'] ?? '—'],
            ['SHA-256 Acta de Entrega',  $chk['acta_pdf_hash']   ?? '—'],
        ], $enc);
    }

    // ─── FACTURACIÓN + REPUVE ────────────────────────────────────────
    if (!empty($ctx['cfdi']) || !empty($ctx['repuve'])) {
        _dossierH2($pdf, 'FACTURACIÓN Y REGISTRO VEHICULAR');
        $rows = [];
        if (!empty($ctx['cfdi'])) {
            $rows[] = ['CFDI 4.0 UUID',     $ctx['cfdi']['uuid']    ?? '—'];
            $rows[] = ['CFDI Folio · Serie',($ctx['cfdi']['folio'] ?? '—') . ' · ' . ($ctx['cfdi']['serie'] ?? '—')];
            $rows[] = ['CFDI Estado',       $ctx['cfdi']['estado']  ?? '—'];
            $rows[] = ['CFDI Emitido en',   $ctx['cfdi']['femitido']?? '—'];
        }
        if (!empty($ctx['repuve'])) {
            $rows[] = ['REPUVE Folio',      $ctx['repuve']['folio_repuve'] ?? '—'];
            $rows[] = ['REPUVE Estado',     $ctx['repuve']['estado']        ?? '—'];
            $rows[] = ['REPUVE Enviado en', $ctx['repuve']['fenviado']      ?? '—'];
        }
        _dossierTable($pdf, $rows, $enc);
    }

    // ─── MANIFIESTO DE COMPONENTES ───────────────────────────────────
    _dossierH2($pdf, 'COMPONENTES INCLUIDOS EN EL DOSSIER');
    $pdf->SetFont('Arial', '', 7.5);
    $rowsManifest = [];
    foreach ($componentes as $name => $meta) {
        $tipo = $meta['tipo'] ?? '';
        $rowsManifest[] = [$name, $tipo];
    }
    if (empty($rowsManifest)) {
        $pdf->SetTextColor(180, 50, 50);
        $pdf->MultiCell(0, 4.5, $enc('Sin componentes adjuntos — verificar con admin si la operación está completa.'), 0, 'L');
        $pdf->SetTextColor(0);
    } else {
        _dossierTable($pdf, $rowsManifest, $enc, 7.5);
    }

    // ─── ESCALATIONS ABIERTAS ────────────────────────────────────────
    if (!empty($ctx['escalations'])) {
        _dossierH2($pdf, 'ESCALATIONS RELACIONADAS');
        foreach ($ctx['escalations'] as $esc) {
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor(180, 50, 50);
            $pdf->Cell(0, 4, $enc('· ' . strtoupper($esc['kind']) . ' (' . $esc['estado'] . '): ' . $esc['titulo']), 0, 1);
            $pdf->SetTextColor(0);
            $pdf->SetFont('Arial', '', 7.5);
            $pdf->MultiCell(0, 3.6, $enc(($esc['detalle'] ?? '') . ' · Abierta el ' . $esc['freg']), 0, 'J');
            $pdf->Ln(0.4);
        }
    }

    // Footer
    $pdf->Ln(3);
    $pdf->SetDrawColor(220);
    $pdf->Line(15, $pdf->GetY(), 200, $pdf->GetY());
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->SetTextColor(120);
    $pdf->MultiCell(0, 3.4, $enc('Voltika · MTECH GEARS, S.A. DE C.V. · Jaime Balmes 71 desp. 101 C, Polanco I Sección, Miguel Hidalgo, CDMX 11510 · WhatsApp +52 55 1341 6370 · contacto@voltika.mx · Documento generado automáticamente; los archivos auxiliares y sus hashes SHA-256 se incluyen en el ZIP adjunto y están respaldados en almacenamiento Object Lock conforme a NOM-151-SCFI-2016.'), 0, 'C');
    $pdf->SetTextColor(0);

    $pdf->Output('F', $outPath);
}

// ─────────────────────────────────────────────────────────────────────────
// Internal — small helpers
// ─────────────────────────────────────────────────────────────────────────

function _dossierH2(FPDF $pdf, string $title): void {
    $pdf->Ln(2);
    $pdf->SetFillColor(241, 245, 249);
    $pdf->SetTextColor(26, 58, 92);
    $pdf->SetFont('Arial', 'B', 9);
    $enc = function($s) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string)$s); };
    $pdf->Cell(0, 6, $enc(' ' . $title), 0, 1, 'L', true);
    $pdf->SetTextColor(0);
    $pdf->Ln(0.5);
}

function _dossierTable(FPDF $pdf, array $rows, callable $enc, float $fontSize = 8): void {
    $w1 = 70; $w2 = 109.9; $h = 5.2;
    foreach ($rows as $r) {
        $label = (string)$r[0];
        $value = (string)($r[1] ?? '—');
        if ($value === '') $value = '—';
        $pdf->SetFont('Arial', 'B', $fontSize);
        $pdf->SetFillColor(248, 250, 252);
        $pdf->Cell($w1, $h, $enc($label), 1, 0, 'L', true);
        $pdf->SetFont('Arial', '', $fontSize);
        $pdf->Cell($w2, $h, $enc(mb_strimwidth($value, 0, 75, '…')), 1, 1, 'L');
    }
    $pdf->Ln(0.5);
}

function _dossierListFiles(string $dir, string $base = ''): array {
    $out = [];
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === 'HASHES.txt') continue;
        $abs = $dir . '/' . $entry;
        $rel = $base === '' ? $entry : $base . '/' . $entry;
        if (is_dir($abs)) {
            $out += _dossierListFiles($abs, $rel);
        } else {
            $out[$rel] = $abs;
        }
    }
    ksort($out);
    return $out;
}

function _dossierRmTree(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $p = $dir . '/' . $entry;
        if (is_dir($p)) _dossierRmTree($p);
        else @unlink($p);
    }
    @rmdir($dir);
}

/**
 * Send the manifest hash to Cincel for NOM-151 trusted-timestamp.
 * Returns the cincel timestamp id or null on failure.
 */
function _dossierCincelTimestamp(string $manifestHash, string $pedido, string $vin): ?string {
    $apiUrl = defined('CINCEL_API_URL')  ? CINCEL_API_URL  : '';
    $email  = defined('CINCEL_EMAIL')    ? CINCEL_EMAIL    : '';
    $pass   = defined('CINCEL_PASSWORD') ? CINCEL_PASSWORD : '';
    if (!$apiUrl || !$email || !$pass) return null;

    // Auth
    $ch = curl_init($apiUrl . '/auth/tokens');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'password' => $pass]),
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = json_decode(curl_exec($ch), true) ?: [];
    $authCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $token = $resp['access_token'] ?? $resp['token'] ?? null;
    if (!$token || $authCode >= 400) return null;

    // Timestamp
    $ch = curl_init($apiUrl . '/timestamps');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_POSTFIELDS => json_encode([
            'hash' => $manifestHash,
            'algorithm' => 'SHA-256',
            'description' => "Voltika Dossier de Defensa · Pedido {$pedido} · VIN {$vin}",
        ]),
        CURLOPT_TIMEOUT => 30,
    ]);
    $tsResp = json_decode(curl_exec($ch), true) ?: [];
    $tsCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($tsCode >= 400) return null;
    return (string)($tsResp['id'] ?? $tsResp['timestamp_id'] ?? $tsResp['data']['id'] ?? '') ?: null;
}
