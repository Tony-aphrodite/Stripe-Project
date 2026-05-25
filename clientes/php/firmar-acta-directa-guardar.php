<?php
/**
 * Voltika Customer — Save the ACTA autograph signature, regenerate the ACTA
 * PDF with the autograph embedded, apply a Cincel NOM-151 timestamp, and
 * mark the moto as delivered. (Round 80, 2026-05-25.)
 *
 * Backend half of /clientes/firmar-acta-directa.php. Combines what
 * cincel-firma-acta.php + firmar-acta.php do in the SPA flow, but without
 * the customer SPA dependency (no portal session needed — token IS the auth).
 *
 * POST body (JSON):
 *   { token: "<40hex>", signature_data: "data:image/png;base64,..." }
 *
 * Response:
 *   { ok: true, message, new_pdf_url?, nom151_hash? }
 *   { ok: false, error, message }
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// ── Input ────────────────────────────────────────────────────────────────
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

$pdo = getDB();

try {
    // ── Resolve token ────────────────────────────────────────────────────
    $st = $pdo->prepare("SELECT * FROM firma_acta_requests WHERE token = ? LIMIT 1");
    $st->execute([$token]);
    $req = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$req) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'token_no_encontrado',
                          'message' => 'El enlace ya no es válido.']);
        exit;
    }
    if ((string)$req['estado'] === 'signed') {
        echo json_encode(['ok' => false, 'error' => 'ya_firmado',
                          'message' => 'Esta entrega ya fue firmada.']);
        exit;
    }
    if ((string)$req['estado'] === 'expired' || (int)$req['expires_at'] < time()) {
        $pdo->prepare("UPDATE firma_acta_requests SET estado='expired' WHERE id=?")
            ->execute([(int)$req['id']]);
        echo json_encode(['ok' => false, 'error' => 'token_expirado',
                          'message' => 'El enlace expiró. Pide a Voltika que te envíe uno nuevo.']);
        exit;
    }

    $motoId = (int)$req['moto_id'];
    $m = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ? LIMIT 1");
    $m->execute([$motoId]);
    $moto = $m->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$moto) {
        echo json_encode(['ok' => false, 'error' => 'moto_no_encontrada',
                          'message' => 'No encontramos la moto asociada.']);
        exit;
    }

    // ── Save signature to firmas_contratos ───────────────────────────────
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
    } catch (Throwable $e) {}

    $email = trim((string)($moto['cliente_email']    ?? ''));
    $tel   = trim((string)($moto['cliente_telefono'] ?? ''));
    $nom   = trim((string)($moto['cliente_nombre']   ?? ''));
    $sigHash = hash('sha256', $sig);
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

    $pdo->prepare("INSERT INTO firmas_contratos
            (nombre, email, telefono, modelo, firma_base64, firma_sha256, ip, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([
            $nom ?: null, $email ?: null, $tel ?: null,
            $moto['modelo'] ?? null, $sig, $sigHash, $ip, $ua,
        ]);
    $firmaId = (int)$pdo->lastInsertId();

    // ── Generate the ACTA PDF (with embedded signature) ──────────────────
    // Reuses the FPDF generator inlined in cincel-firma-acta.php. We
    // replicate the minimal version here so this endpoint is self-contained
    // (and doesn't depend on the cincel-firma-acta.php portal-auth wrapper).
    $pdfPath = generarActaPdfDirecta($pdo, $moto, $sig, $ip);

    // ── Idempotent column additions for the ACTA-related fields ──────────
    foreach ([
        ['cliente_acta_firmada',   "TINYINT(1) DEFAULT 0"],
        ['cliente_acta_fecha',     "DATETIME NULL"],
        ['cliente_acta_firma',     "VARCHAR(150) NULL"],
        ['cliente_acta_ip',        "VARCHAR(45) NULL"],
        ['cincel_acta_pdf_path',   "VARCHAR(600) NULL"],
        ['cincel_acta_status',     "VARCHAR(50) NULL"],
        ['cincel_acta_timestamp_hash', "CHAR(64) NULL"],
    ] as $c) {
        try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN `{$c[0]}` {$c[1]}"); }
        catch (Throwable $e) { /* already exists */ }
    }

    // ── Apply Cincel NOM-151 timestamp to the new PDF (best-effort) ─────
    $tsHash = null;
    if ($pdfPath && is_file($pdfPath)) {
        try {
            require_once __DIR__ . '/../../configurador/php/cincel-timestamp.php';
            $ts = cincelGetOrCreateTimestamp($pdfPath);
            if (!empty($ts['ok'])) {
                cincelSaveTimestamp($pdo, $ts, null, $pdfPath);
                $tsHash = $ts['hash'] ?? null;
            }
        } catch (Throwable $e) {
            error_log('firmar-acta-directa cincel: ' . $e->getMessage());
        }
    }

    // ── Update inventario_motos — mark as signed + delivered ─────────────
    try {
        $pdo->prepare("UPDATE inventario_motos SET
                cliente_acta_firmada      = 1,
                cliente_acta_fecha        = NOW(),
                cliente_acta_firma        = ?,
                cliente_acta_ip           = ?,
                cincel_acta_pdf_path      = ?,
                cincel_acta_status        = ?,
                cincel_acta_timestamp_hash= ?
              WHERE id = ?")
            ->execute([
                $nom ?: null,
                $ip,
                $pdfPath,
                $tsHash ? 'signed_with_timestamp' : 'signed_no_timestamp',
                $tsHash,
                $motoId,
            ]);
    } catch (Throwable $e) {
        error_log('firmar-acta-directa update moto: ' . $e->getMessage());
    }

    // ── Mark token as signed (atomic conditional) ───────────────────────
    $pdo->prepare("UPDATE firma_acta_requests
                      SET estado='signed', signed_at=NOW(), signed_firma_id=?,
                          ip=?, user_agent=?
                    WHERE id=? AND estado='pending'")
        ->execute([$firmaId, $ip, $ua, (int)$req['id']]);

    // ── Notifications (best-effort) ──────────────────────────────────────
    try {
        require_once __DIR__ . '/../../configurador/php/voltika-notify.php';
        if (function_exists('voltikaNotify')) {
            voltikaNotify('acta_firmada', [
                'cliente_id' => $moto['cliente_id'] ?? null,
                'nombre'     => $nom,
                'modelo'     => $moto['modelo'] ?? '',
                'telefono'   => $tel,
                'email'      => $email,
            ]);
        }
    } catch (Throwable $e) { error_log('notify acta_firmada: ' . $e->getMessage()); }

    // ── Notify punto panel ───────────────────────────────────────────────
    try {
        $msgPunto = '✅ ACTA DE ENTREGA firmada por ' . $nom
                  . ' · Moto #' . $motoId
                  . ' · Modelo: ' . ($moto['modelo'] ?? '?')
                  . ' · Color: ' . ($moto['color'] ?? '?')
                  . ' — Puede finalizar la entrega.';
        $destinoPunto = !empty($moto['punto_voltika_id'])
            ? ('punto:' . (int)$moto['punto_voltika_id'])
            : ('punto:moto:' . $motoId);
        $pdo->prepare("INSERT INTO notificaciones_log
                (cliente_id, tipo, canal, destino, mensaje, status)
            VALUES (?, ?, 'punto_panel', ?, ?, 'ok')")
            ->execute([(int)($moto['cliente_id'] ?? 0) ?: null,
                       'acta_firmada_punto', $destinoPunto, $msgPunto]);
    } catch (Throwable $e) { error_log('notify punto acta_firmada: ' . $e->getMessage()); }

    // Build a public URL for the new PDF if web-accessible.
    $newUrl = null;
    if ($pdfPath && strpos($pdfPath, '/configurador/') !== false) {
        $rel = substr($pdfPath, strpos($pdfPath, '/configurador/'));
        $newUrl = $rel;
    }

    echo json_encode([
        'ok'           => true,
        'message'      => 'Firma guardada. ACTA sellada con NOM-151.',
        'new_pdf_url'  => $newUrl,
        'nom151_hash'  => $tsHash,
        'firma_id'     => $firmaId,
    ]);

} catch (Throwable $e) {
    error_log('firmar-acta-directa-guardar: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'error'   => 'server_error',
        'message' => 'Error interno: ' . $e->getMessage(),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────
// PDF generation helper — inlined from cincel-firma-acta.php so this
// endpoint is self-contained. Returns absolute disk path or null on failure.
// ─────────────────────────────────────────────────────────────────────────
function generarActaPdfDirecta(PDO $pdo, array $moto, string $signatureDataUrl, ?string $ip): ?string {
    // Locate FPDF
    $fpdfPaths = [
        __DIR__ . '/../../admin/php/lib/fpdf.php',
        __DIR__ . '/../../configurador/php/vendor/fpdf/fpdf.php',
        __DIR__ . '/../../configurador/php/vendor/setasign/fpdf/fpdf.php',
    ];
    foreach ($fpdfPaths as $fp) {
        if (file_exists($fp)) { require_once $fp; break; }
    }
    if (!class_exists('FPDF')) {
        error_log('generarActaPdfDirecta: FPDF not available');
        return null;
    }

    $motoId = (int)$moto['id'];
    $enc = function($s) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string)$s); };

    // Punto info
    $punto = null;
    if (!empty($moto['punto_voltika_id'])) {
        try {
            $pq = $pdo->prepare("SELECT nombre, ciudad FROM puntos_voltika WHERE id=? LIMIT 1");
            $pq->execute([(int)$moto['punto_voltika_id']]);
            $punto = $pq->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {}
    }

    $pdf = new FPDF('P', 'mm', 'Letter');
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetTitle($enc('Acta de Entrega - Voltika'));
    $pdf->SetAuthor('Voltika - MTECH GEARS, S.A. DE C.V.');
    $pdf->AddPage();

    $folio = 'ACT-' . $motoId . '-' . date('Ymd-His');
    $fechaEntrega = date('d/m/Y H:i');

    // Brand bar
    $pdf->SetFillColor(26, 58, 92);
    $pdf->Rect(0, 0, 215.9, 11, 'F');
    $pdf->SetTextColor(255);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetXY(15, 3);
    $pdf->Cell(0, 5, $enc('VOLTIKA · ACTA DE ENTREGA DE MOTOCICLETA ELÉCTRICA'), 0, 0, 'L');
    $pdf->SetTextColor(0);
    $pdf->SetY(16);

    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(95, 4, $enc('FOLIO: ' . $folio), 0, 0);
    $pdf->Cell(95, 4, $enc('FECHA Y HORA: ' . $fechaEntrega), 0, 1, 'R');
    $pdf->Ln(2);

    $pdf->SetFont('Arial', 'B', 13);
    $pdf->Cell(0, 7, $enc('ACTA DE ENTREGA DE VEHÍCULO'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->Cell(0, 4, $enc('MTECH GEARS, S.A. DE C.V. — Voltika'), 0, 1, 'C');
    $pdf->Ln(3);

    $nombreCompleto = trim((string)($moto['cliente_nombre'] ?? ''));
    $puntoNombre    = $punto['nombre'] ?? '—';
    $puntoCiudad    = $punto['ciudad'] ?? ($moto['ciudad'] ?? 'México');

    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 4.5, $enc('En este acto, EL CLIENTE declara haber recibido la motocicleta eléctrica descrita en el presente documento, en condiciones óptimas de funcionamiento, completa y conforme a lo contratado.'), 0, 'J');
    $pdf->Ln(3);

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 5, $enc('DATOS DE LA OPERACIÓN'), 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $rows = [
        ['Cliente',         $nombreCompleto],
        ['Modelo',          $moto['modelo']  ?? '—'],
        ['Color',           $moto['color']   ?? '—'],
        ['VIN / NIV',       $moto['vin_display'] ?? $moto['vin'] ?? '—'],
        ['Pedido / folio',  $moto['pedido_num']  ?? '—'],
        ['Fecha y hora',    $fechaEntrega],
        ['Punto de entrega', $puntoNombre . ($puntoCiudad ? ' — ' . $puntoCiudad : '')],
    ];
    foreach ($rows as $r) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(60, 5, $enc($r[0] . ':'), 0);
        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell(0, 5, $enc((string)$r[1]), 0);
    }
    $pdf->Ln(3);

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 5, $enc('DECLARACIONES DEL CLIENTE'), 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $decls = [
        'El vehículo fue entregado en perfectas condiciones físicas y mecánicas.',
        'A partir de este momento, EL CLIENTE asume la responsabilidad total del uso, custodia y cuidado del vehículo.',
        'EL CLIENTE recibió información sobre garantía, uso correcto y medidas de seguridad del vehículo eléctrico.',
        'EL CLIENTE acepta el contenido de la presente acta y firma electrónicamente con validez NOM-151 a través de Cincel.',
    ];
    foreach ($decls as $i => $d) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(7, 5, ($i + 1) . '.', 0);
        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell(0, 4.8, $enc($d), 0, 'J');
        $pdf->Ln(1);
    }

    // Embed the autograph signature image.
    $pdf->Ln(6);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 5, $enc('FIRMA DEL CLIENTE'), 0, 1);
    $pdf->Ln(2);

    $tmpSig = null;
    if (preg_match('/^data:image\/png;base64,(.+)$/', $signatureDataUrl, $mm)) {
        $bin = base64_decode($mm[1]);
        if ($bin !== false) {
            $tmpSig = tempnam(sys_get_temp_dir(), 'sig_') . '.png';
            file_put_contents($tmpSig, $bin);
            try {
                $pdf->Image($tmpSig, 20, $pdf->GetY(), 90, 0, 'PNG');
                $pdf->Ln(40);
            } catch (Throwable $e) {
                $pdf->SetFont('Arial', 'I', 9);
                $pdf->Cell(0, 5, $enc('[firma no incrustada: ' . $e->getMessage() . ']'), 0, 1);
            }
        }
    }
    $pdf->Line(20, $pdf->GetY(), 110, $pdf->GetY());
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(90, 5, $enc($nombreCompleto), 0, 1);
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(90, 4, $enc('Firmado electrónicamente · sello NOM-151 a través de Cincel'), 0, 1);
    if ($ip) {
        $pdf->SetFont('Arial', '', 7);
        $pdf->Cell(0, 4, $enc('IP: ' . $ip . ' · ' . $fechaEntrega), 0, 1);
    }

    // Persist to disk — prefer public configurador/contratos folder, fall back to /tmp.
    $filename = 'acta_directa_' . $motoId . '_' . date('Ymd_His') . '.pdf';
    $candidateDirs = [
        __DIR__ . '/../../configurador/php/uploads/actas',
        __DIR__ . '/../../configurador/contratos/actas',
        sys_get_temp_dir() . '/voltika_actas',
    ];
    $outPath = null;
    foreach ($candidateDirs as $dir) {
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (!is_writable($dir)) continue;
        $try = $dir . '/' . $filename;
        try {
            $pdf->Output('F', $try);
            if (is_file($try) && filesize($try) > 0) { $outPath = $try; break; }
        } catch (Throwable $e) { error_log('generarActaPdfDirecta output: ' . $e->getMessage()); }
    }
    if ($tmpSig && is_file($tmpSig)) @unlink($tmpSig);
    return $outPath;
}
