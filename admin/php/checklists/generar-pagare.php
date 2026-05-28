<?php
/**
 * POST — Generate immutable Pagaré PDF with auto-populated client data
 * Body: { moto_id, firma_data (base64 PNG, optional) }
 * Returns: { ok, pdf_path, pdf_hash, folio }
 *
 * Uses the official "Pagaré electrónico Voltika" legal template.
 * Auto-fills: Truora name, CURP, address, OTP phone, Pago total a plazos.
 */
require_once __DIR__ . '/../bootstrap.php';

// Round 102 (2026-05-26) — Accept punto auth too. The punto operator
// triggers PAGARÉ generation from the customer's delivery flow at the
// moto handoff (punto-entrega.js stepPagare). Without this fallback,
// the punto session can't reach the admin endpoint → punto delivery
// can't generate the legally-required pagaré at moto handoff time.
$uid = 0;
if (!empty($_SESSION['admin_user_id'])) {
    $uid = adminRequireAuth(['admin','cedis']);
} else {
    @session_write_close();
    @session_name('VOLTIKA_PUNTO');
    @session_start();
    if (!empty($_SESSION['punto_user_id'])) {
        $uid = (int)$_SESSION['punto_user_id'];
    } else {
        adminJsonOut(['error' => 'No autorizado (ni admin ni punto)'], 401);
    }
}

$d = adminJsonIn();
$motoId   = (int)($d['moto_id'] ?? 0);
$firmaB64 = $d['firma_data'] ?? '';

// Round 111 (2026-05-27) — Accept CURP + address fields from frontend stepPagare form.
// Previously the backend tried to look these up from sparse DB tables (clientes, transacciones)
// which often returned empty. Now the operator verifies/fills these at the punto and the
// frontend passes them in the POST body — the authoritative source.
$inputCurp        = strtoupper(trim((string)($d['curp'] ?? '')));
$inputRfc         = strtoupper(trim((string)($d['rfc'] ?? '')));
$inputDob         = trim((string)($d['fecha_nacimiento'] ?? ''));
$inputCalle       = trim((string)($d['calle'] ?? ''));
$inputNumExt      = trim((string)($d['num_exterior'] ?? ''));
$inputNumInt      = trim((string)($d['num_interior'] ?? ''));
$inputColonia     = trim((string)($d['colonia'] ?? ''));
$inputAlcaldia    = trim((string)($d['alcaldia'] ?? ''));
$inputEstadoDir   = trim((string)($d['estado_dir'] ?? ''));
$inputCp          = trim((string)($d['cp'] ?? ''));
$inputGeoLat      = trim((string)($d['geolat'] ?? ''));
$inputGeoLng      = trim((string)($d['geolng'] ?? ''));

if (!$motoId) adminJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();

// ── 1. Get checklist (auto-create if not exists) ────────────────────────
$stmt = $pdo->prepare("SELECT id, completado, otp_code, otp_timestamp,
        fase4_completada, fase4_fecha
    FROM checklist_entrega_v2 WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$stmt->execute([$motoId]);
$cl = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cl) {
    $pdo->prepare("INSERT INTO checklist_entrega_v2 (moto_id, dealer_id, fase_actual) VALUES (?, ?, 'fase1')")
        ->execute([$motoId, $uid]);
    $newId = (int)$pdo->lastInsertId();
    $cl = ['id' => $newId, 'completado' => 0, 'otp_code' => null, 'otp_timestamp' => null,
           'fase4_completada' => 0, 'fase4_fecha' => null];
}

if ($cl['completado']) adminJsonOut(['error' => 'Checklist ya completado'], 403);
$checkId = $cl['id'];

// ── 2. Get moto + client data ───────────────────────────────────────────
$stmt = $pdo->prepare("SELECT m.*, pv.nombre AS punto_nombre, pv.direccion AS punto_direccion
    FROM inventario_motos m
    LEFT JOIN puntos_voltika pv ON pv.id = m.punto_voltika_id
    WHERE m.id = ?");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) adminJsonOut(['error' => 'Moto no encontrada'], 404);

// Transaction data
$trans = null;
if (!empty($moto['transaccion_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM transacciones WHERE id = ?");
    $stmt->execute([$moto['transaccion_id']]);
    $trans = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$trans && !empty($moto['cliente_email'])) {
    $stmt = $pdo->prepare("SELECT * FROM transacciones WHERE email = ? ORDER BY freg DESC LIMIT 1");
    $stmt->execute([$moto['cliente_email']]);
    $trans = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Credit/subscription details
$credito = null;
if (!empty($moto['cliente_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM subscripciones_credito WHERE cliente_id = ? AND inventario_moto_id = ? ORDER BY freg DESC LIMIT 1");
    $stmt->execute([$moto['cliente_id'], $motoId]);
    $credito = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$credito && !empty($moto['cliente_email'])) {
    $stmt = $pdo->prepare("SELECT sc.* FROM subscripciones_credito sc
        JOIN clientes c ON c.id = sc.cliente_id
        WHERE c.email = ? ORDER BY sc.freg DESC LIMIT 1");
    $stmt->execute([$moto['cliente_email']]);
    $credito = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Client master data (Truora-verified)
$cliente = null;
if (!empty($moto['cliente_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
    $stmt->execute([$moto['cliente_id']]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ── 2b. Ensure schema columns exist (idempotent) ───────────────────────
// Round 111 — new columns for legally-enforced PAGARÉ data
foreach ([
    'pagare_curp'                  => "VARCHAR(18) NULL",
    'pagare_calle'                 => "VARCHAR(200) NULL",
    'pagare_num_exterior'          => "VARCHAR(20) NULL",
    'pagare_num_interior'          => "VARCHAR(20) NULL",
    'pagare_colonia'               => "VARCHAR(100) NULL",
    'pagare_alcaldia'              => "VARCHAR(100) NULL",
    'pagare_estado_dir'            => "VARCHAR(50) NULL",
    'pagare_cp'                    => "VARCHAR(5) NULL",
    'pagare_monto_total_operacion' => "DECIMAL(12,2) NULL",
    'pagare_enganche'              => "DECIMAL(12,2) NULL",
    'pagare_fecha_vencimiento'     => "DATE NULL",
    'pagare_status'                => "VARCHAR(20) NULL DEFAULT 'draft'",
] as $_col => $_def) {
    try { $pdo->exec("ALTER TABLE checklist_entrega_v2 ADD COLUMN $_col $_def"); } catch (Throwable $e) {}
}

// ── 2c. OTP verification gate (Bug #3) ─────────────────────────────────
// The PAGARÉ PDF must NOT be generated until OTP is fully validated.
// Without completed OTP, the electronic signature has no legal value
// under Código de Comercio Art. 89-114.
$otpRow = null;
try {
    $oq = $pdo->prepare("SELECT otp_verified, otp_verified_at, otp_code
                            FROM entregas WHERE moto_id = ? ORDER BY freg DESC LIMIT 1");
    $oq->execute([$motoId]);
    $otpRow = $oq->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {}
$otpVerified   = $otpRow && (int)($otpRow['otp_verified'] ?? 0) === 1;
$otpCode       = (string)($otpRow['otp_code'] ?? '');
$otpTimestamp  = (string)($otpRow['otp_verified_at'] ?? '');
// Gate: reject if OTP not verified (unless admin override via _skip_otp_gate)
if (!$otpVerified && empty($d['_skip_otp_gate'])) {
    adminJsonOut([
        'error' => 'OTP no verificado. El cliente debe completar la verificación OTP antes de generar el pagaré.',
        'code'  => 'otp_pendiente',
    ], 400);
}

// ── 3. Build data for PDF ───────────────────────────────────────────────
// Round 111 (2026-05-27) — Complete rewrite per 5-27.md legal specification.
// 5 critical bug fixes + 4 additional legal text improvements.

$telCliente = (string)($moto['cliente_telefono'] ?? '');
try { if ($cliente) $telCliente = (string)($cliente['telefono'] ?? $telCliente); } catch (Throwable $e) {}
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

// ── Bug #1: CURP (mandatory) ───────────────────────────────────────────
// Priority: frontend input > clientes.curp > verificaciones_identidad
$curp = $inputCurp;
if ($curp === '') {
    try { $curp = strtoupper(trim((string)($cliente['curp'] ?? ''))); } catch (Throwable $e) {}
}
if ($curp === '') {
    try {
        $em = (string)($moto['cliente_email'] ?? '');
        $tl = (string)($moto['cliente_telefono'] ?? '');
        if ($em !== '' || $tl !== '') {
            $vq = $pdo->prepare("SELECT expected_curp, verified_curp FROM verificaciones_identidad
                WHERE (LENGTH(?) > 0 AND email = ?) OR (LENGTH(?) > 0 AND telefono = ?)
                ORDER BY id DESC LIMIT 1");
            $vq->execute([$em, $em, $tl, $tl]);
            $vc = $vq->fetch(PDO::FETCH_ASSOC) ?: [];
            $curp = strtoupper(trim((string)($vc['verified_curp'] ?: ($vc['expected_curp'] ?? ''))));
        }
    } catch (Throwable $e) {}
}
// Validate CURP format (18 alphanumeric characters, Mexican standard)
if (!preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z\d]\d$/', $curp)) {
    if ($curp === '' && empty($d['_skip_curp_gate'])) {
        adminJsonOut([
            'error' => 'CURP requerido para generar el pagaré. El operador debe ingresar el CURP del cliente.',
            'code'  => 'curp_requerido',
        ], 400);
    }
    // Non-empty but invalid format — warn but don't block (INE OCR sometimes has minor issues)
    if ($curp !== '') error_log('generar-pagare: CURP format questionable: ' . $curp);
}

// ── Bug #2: Complete address (7 fields) ────────────────────────────────
// Priority: frontend input > transacciones city/state/cp (partial fill)
$addrCalle    = $inputCalle;
$addrNumExt   = $inputNumExt;
$addrNumInt   = $inputNumInt;
$addrColonia  = $inputColonia;
$addrAlcaldia = $inputAlcaldia;
$addrEstado   = $inputEstadoDir;
$addrCp       = $inputCp;
// Fallback: fill from transacciones if frontend didn't provide
if ($addrEstado === '' && $trans) {
    $addrEstado = trim((string)($trans['estado'] ?? ''));
    $addrCp     = $addrCp ?: trim((string)($trans['cp'] ?? ''));
}
// Auto-replace legally obsolete "Distrito Federal" (since 2016)
if (preg_match('/distrito\s*federal/i', $addrEstado)) {
    $addrEstado = 'Ciudad de México';
}
// Compose formatted address for PDF
$addrParts = [$addrCalle];
if ($addrNumExt !== '') $addrParts[0] .= ' ' . $addrNumExt;
if ($addrNumInt !== '') $addrParts[0] .= ' Int. ' . $addrNumInt;
if ($addrColonia !== '')  $addrParts[] = 'Col. ' . $addrColonia;
if ($addrAlcaldia !== '') $addrParts[] = $addrAlcaldia;
if ($addrEstado !== '')   $addrParts[] = $addrEstado;
if ($addrCp !== '')       $addrParts[] = 'C.P. ' . $addrCp;
$addrParts[] = 'México';
$domicilio = implode(', ', array_filter($addrParts, 'strlen'));
// Validate: require at least estado + cp (bare minimum for legal address)
if ($addrEstado === '' && $addrCp === '' && empty($d['_skip_address_gate'])) {
    adminJsonOut([
        'error' => 'Domicilio requerido. El operador debe capturar al menos estado y código postal del cliente.',
        'code'  => 'domicilio_requerido',
    ], 400);
}

// ── Bug #4: Amount = TOTAL OPERATION VALUE (enganche + weekly payments) ─
// Legal rationale (5-27.md Bug #4): the pagaré covers worst-case (full default).
// The "Aplicación de Pagos" clause handles payment reductions as customer pays.
// Including enganche means the pagaré can enforce the FULL operation if
// customer defaults before any payments are applied. This REVERSES Round 110
// which used saldo pendiente (without enganche).
$catalogo = [
    'M05' => 48260, 'M03' => 39900, 'Ukko S+' => 89900,
    'MC10 Streetx' => 109900, 'MC10' => 109900,
    'Pesgo Plus' => 36600, 'Mino-B' => 41820, 'mino B' => 41820,
];
$precioContado = floatval($moto['precio_venta'] ?? 0);
if (!$precioContado) {
    $modelo = (string)($moto['modelo'] ?? '');
    $precioContado = $catalogo[$modelo] ?? 0;
    if (!$precioContado) {
        foreach ($catalogo as $k => $v) {
            if (stripos($modelo, $k) !== false) { $precioContado = $v; break; }
        }
    }
}
$enganche    = 0;
$pagoSemanal = 0;
$numPagos    = 0;
$plazoMeses  = 36;
if ($trans) {
    $enganche = floatval($trans['total'] ?? $trans['precio'] ?? 0);
}
if ($credito) {
    $pagoSemanal = floatval($credito['monto_semanal'] ?? 0);
    $plazoMeses  = intval($credito['plazo_meses'] ?? 36);
    $plazoSem    = intval($credito['plazo_semanas'] ?? 0);
    $numPagos    = $plazoSem > 0 ? $plazoSem : (int)round($plazoMeses * 4.33);
    if (!empty($credito['enganche'])) $enganche = floatval($credito['enganche']);
}
// TOTAL OPERATION = enganche + (pagoSemanal × numPagos)
$totalOperacion = $enganche + ($pagoSemanal > 0 && $numPagos > 0
    ? round($pagoSemanal * $numPagos, 2)
    : 0);
if ($totalOperacion <= 0 && $precioContado > 0) $totalOperacion = $precioContado;
$pagoTotalPlazos = $totalOperacion;

// ── Full customer name ─────────────────────────────────────────────────
$nombreCompleto = trim((string)($moto['cliente_nombre'] ?? ''));
try {
    if ($cliente) {
        $parts = array_filter([
            trim((string)($cliente['nombre'] ?? '')),
            trim((string)($cliente['apellido_paterno'] ?? '')),
            trim((string)($cliente['apellido_materno'] ?? '')),
        ], 'strlen');
        $fromClientes = $parts ? implode(' ', $parts) : '';
        if (strlen($fromClientes) > strlen($nombreCompleto)) {
            $nombreCompleto = $fromClientes;
        }
    }
} catch (Throwable $e) {}
if ($nombreCompleto === '') $nombreCompleto = 'Cliente';

// ── Bug #5: Maturity date ──────────────────────────────────────────────
$fechaVencimiento    = date('d/m/Y', strtotime("+{$plazoMeses} months"));
$fechaVencimientoISO = date('Y-m-d', strtotime("+{$plazoMeses} months"));

// ── Format amounts ─────────────────────────────────────────────────────
$montoLetra = numberToSpanishWords($pagoTotalPlazos);
$montoNum   = '$' . number_format($pagoTotalPlazos, 2) . ' MXN';

$folio = 'PAG-' . $motoId . '-' . date('Ymd-His');
$fechaSuscripcion = date('d/m/Y');
$lugarSuscripcion = 'Ciudad de México, CDMX';

// ── 4. Generate PDF ─────────────────────────────────────────────────────
$fpdfPaths = [
    __DIR__ . '/../lib/fpdf.php',
    __DIR__ . '/../../../configurador/php/vendor/fpdf/fpdf.php',
    __DIR__ . '/../../../configurador/php/vendor/setasign/fpdf/fpdf.php',
    // Cross-env fallback (Plesk hosting where FPDF is only in test)
    __DIR__ . '/../../../configurador_prueba_test/php/vendor/fpdf/fpdf.php',
    __DIR__ . '/../../../configurador_prueba_test/php/vendor/setasign/fpdf/fpdf.php',
    __DIR__ . '/../../../admin_test/php/lib/fpdf.php',
];
$fpdfFound = false;
foreach ($fpdfPaths as $fp) {
    if (file_exists($fp)) { require_once $fp; $fpdfFound = true; break; }
}
if (!$fpdfFound) {
    adminJsonOut(['error' => 'FPDF library not found. Upload fpdf.php to admin/php/lib/'], 500);
}

$storageDir = sys_get_temp_dir() . '/voltika_pagares/';
if (!is_dir($storageDir)) @mkdir($storageDir, 0777, true);

$filename = 'pagare_moto' . $motoId . '_' . date('Ymd_His') . '.pdf';
$filepath = $storageDir . $filename;

// Round 111 — Fallback: if no signature in POST body, read from checklist DB
if (!$firmaB64 || strpos($firmaB64, 'data:image') !== 0) {
    try {
        $fq = $pdo->prepare("SELECT firma_pagare_data FROM checklist_entrega_v2 WHERE id = ? AND firma_pagare_data IS NOT NULL AND firma_pagare_data <> ''");
        $fq->execute([$checkId]);
        $dbFirma = (string)($fq->fetchColumn() ?: '');
        if ($dbFirma !== '' && strlen($dbFirma) > 200) $firmaB64 = $dbFirma;
    } catch (Throwable $e) {}
}

// Save signature image temporarily if provided
$firmaImgPath = null;
if ($firmaB64 && strpos($firmaB64, 'data:image/png;base64,') === 0) {
    $firmaData = base64_decode(str_replace('data:image/png;base64,', '', $firmaB64));
    $firmaImgPath = $storageDir . 'firma_pagare_' . $motoId . '_' . date('Ymd_His') . '.png';
    file_put_contents($firmaImgPath, $firmaData);
}

// Helper
$enc = function($s) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string)$s); };
// Bullet helper
$bullet = function($pdf, $enc, $text) {
    $pdf->SetFont('Arial', 'B', 8);
    $x = $pdf->GetX();
    $pdf->Cell(6, 4.5, $enc(chr(149)), 0, 0); // bullet character
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->MultiCell(0, 4.5, $enc($text));
    $pdf->Ln(0.5);
};
// Section divider
$divider = function($pdf) {
    $pdf->SetDrawColor(180, 180, 180);
    $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
    $pdf->Ln(3);
};
// Section title
$secTitle = function($pdf, $enc, $title) {
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, $enc($title), 0, 1);
    $pdf->Ln(1);
};

// Evidence data (collected early for use in PDF body)
if (!isset($ip) || $ip === '') $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'N/A');
if ($ip !== 'N/A') $ip = trim(explode(',', $ip)[0]);
if (!isset($ua) || $ua === '') $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'N/A';
$fechaFirma = date('d/m/Y H:i:s');
$emailCliente = (string)($moto['cliente_email'] ?? '');
$fechaNacimiento = $inputDob;
if (!$fechaNacimiento) { try { $fechaNacimiento = (string)($cliente['fecha_nacimiento'] ?? ''); } catch (Throwable $e) {} }
$rfcCliente = $inputRfc;
$vinDisplay = (string)($moto['vin_display'] ?? $moto['vin'] ?? '—');
$geoDisplay = ($inputGeoLat !== '' && $inputGeoLng !== '') ? ($inputGeoLat . ', ' . $inputGeoLng) : '—';

// ── Two-pass PDF generation (Round 112 root-cause fix) ─────────────────
// Pass 1: generate with "Pendiente" → hash it → Cincel stamp.
// Pass 2: regenerate with real hash + Cincel folio via FPDF (not binary replace).
// This eliminates all binary PDF manipulation (compression, encoding, offset bugs).
$hashDisplay   = 'Pendiente';
$nom151Display = 'Pendiente';
$cincelDisplay = 'Pendiente';
$cincelHash  = null;
$cincelErr   = null;
$cincelFolio = null;

for ($_pdfPass = 1; $_pdfPass <= 2; $_pdfPass++) {

$pdf = new FPDF();
$pdf->SetAutoPageBreak(true, 15);

// ═══════════════════════════════════════════════════════════════════════
// PAGE 1 — Header + Obligación + Vencimiento + Moratorios
// Matches Voltika_Pagare_Corregido_v2.pdf page 1
// ═══════════════════════════════════════════════════════════════════════
$pdf->AddPage();

// Title
$pdf->SetFont('Arial', 'B', 20);
$pdf->Cell(0, 12, $enc('PAGARÉ'), 0, 1);
$pdf->Ln(2);

// Amount
$pdf->SetFont('Arial', 'B', 10);
$pdf->MultiCell(0, 6, $enc('Por la cantidad de ' . $montoNum . ' (' . $montoLetra . ')'));
$pdf->Ln(2);

// Place, dates
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 6, $enc(
    'Lugar de suscripción: ' . $lugarSuscripcion
    . '  Fecha de suscripción: ' . $fechaSuscripcion
    . '  Fecha de vencimiento final: ' . $fechaVencimiento
), 0, 1);
$pdf->Ln(4);

// ── OBLIGACIÓN DE PAGO ─────────────────────────────────────────────────
$divider($pdf);
$secTitle($pdf, $enc, 'OBLIGACIÓN DE PAGO');
$pdf->SetFont('Arial', '', 8.5);
$pdf->MultiCell(0, 4.5, $enc(
    'DEBO Y PAGARÉ incondicionalmente a la orden de MTECH GEARS, S.A. DE C.V. (VOLTIKA), '
    . 'la cantidad señalada en el presente documento, obligándome a cubrirla en el domicilio del '
    . 'acreedor o en el lugar que éste designe.'
));
$pdf->Ln(2);
$pdf->MultiCell(0, 4.5, $enc(
    'La obligación consignada en este pagaré es líquida, cierta, exigible y de plazo vencido '
    . 'desde su suscripción, constituyendo una obligación directa, autónoma e independiente '
    . 'conforme a la Ley General de Títulos y Operaciones de Crédito.'
));
$pdf->Ln(4);

// ── VENCIMIENTO Y EXIGIBILIDAD ─────────────────────────────────────────
$divider($pdf);
$secTitle($pdf, $enc, 'VENCIMIENTO Y EXIGIBILIDAD');
$pdf->SetFont('Arial', '', 8.5);
$pdf->MultiCell(0, 4.5, $enc(
    'El presente pagaré es exigible íntegramente desde su suscripción. No obstante, las partes '
    . 'reconocen que el acreedor ha otorgado al suscriptor la facilidad de cubrir esta obligación '
    . 'mediante pagos parciales, conforme a lo establecido en el Contrato de Compraventa a Plazos '
    . 'celebrado entre las partes.'
));
$pdf->Ln(4);

// ── VENCIMIENTO ANTICIPADO POR INCUMPLIMIENTO ──────────────────────────
$secTitle($pdf, $enc, 'VENCIMIENTO ANTICIPADO POR INCUMPLIMIENTO');
$pdf->SetFont('Arial', '', 8.5);
$pdf->MultiCell(0, 4.5, $enc(
    'En caso de que el suscriptor incurra en incumplimiento de cualquiera de los pagos parciales '
    . 'pactados en el referido Contrato, el saldo insoluto del presente pagaré se considerará '
    . 'vencido anticipadamente y será exigible en su totalidad de forma inmediata, sin necesidad '
    . 'de requerimiento, aviso previo, interpelación judicial o extrajudicial.'
));
$pdf->Ln(2);
$pdf->MultiCell(0, 4.5, $enc(
    'La validez, existencia y exigibilidad del presente pagaré no dependen del Contrato referido, '
    . 'conforme al principio de autonomía cambiaria establecido en el artículo 167 de la Ley '
    . 'General de Títulos y Operaciones de Crédito.'
));
$pdf->Ln(4);

// ── INTERESES MORATORIOS ───────────────────────────────────────────────
$divider($pdf);
$secTitle($pdf, $enc, 'INTERESES MORATORIOS');
$pdf->SetFont('Arial', '', 8.5);
$pdf->MultiCell(0, 4.5, $enc(
    'En caso de incumplimiento, el suscriptor se obliga a pagar un interés moratorio sobre el '
    . 'saldo insoluto a una tasa del 3.5% (tres punto cinco por ciento) mensual, calculado '
    . 'desde la fecha de incumplimiento y hasta la total liquidación del adeudo.'
));
$pdf->Ln(2);
$pdf->MultiCell(0, 4.5, $enc(
    'Dicha tasa constituye una estimación razonable de los daños y perjuicios derivados del '
    . 'incumplimiento, incluyendo costos administrativos, operativos y de recuperación.'
));
$pdf->Ln(2);
$pdf->MultiCell(0, 4.5, $enc(
    'El suscriptor reconoce y acepta expresamente la tasa pactada.'
));
$pdf->Ln(2);
$pdf->MultiCell(0, 4.5, $enc(
    'Los intereses moratorios no se capitalizarán ni generarán intereses sobre intereses.'
));
$pdf->Ln(4);

// ── APLICACIÓN DE PAGOS ────────────────────────────────────────────────
$divider($pdf);
$secTitle($pdf, $enc, 'APLICACIÓN DE PAGOS');
$pdf->SetFont('Arial', '', 8.5);
$pdf->MultiCell(0, 4.5, $enc(
    'Todos los pagos que realice el suscriptor serán aplicados al presente pagaré, reduciendo '
    . 'el saldo exigible en la proporción correspondiente, sin afectar la validez ni exigibilidad '
    . 'del mismo.'
));
$pdf->Ln(2);
$pdf->MultiCell(0, 4.5, $enc(
    'El acreedor expedirá comprobante por cada pago recibido y mantendrá registro actualizado '
    . 'del saldo insoluto.'
));
$pdf->Ln(4);

// ── RENUNCIA Y JURISDICCIÓN ────────────────────────────────────────────
$divider($pdf);
$secTitle($pdf, $enc, 'RENUNCIA Y JURISDICCIÓN');
$pdf->SetFont('Arial', '', 8.5);
$pdf->MultiCell(0, 4.5, $enc(
    'El suscriptor renuncia expresamente a cualquier fuero que pudiera corresponderle por razón '
    . 'de su domicilio presente o futuro, sometiéndose irrevocablemente a la jurisdicción y '
    . 'competencia de los tribunales de la Ciudad de México.'
));

// ═══════════════════════════════════════════════════════════════════════
// PAGE 2 — Declaración + Firma electrónica + Datos suscriptor
// ═══════════════════════════════════════════════════════════════════════
$pdf->AddPage();

// ── DECLARACIÓN EXPRESA DEL SUSCRIPTOR ─────────────────────────────────
$divider($pdf);
$secTitle($pdf, $enc, 'DECLARACIÓN EXPRESA DEL SUSCRIPTOR');
$pdf->SetFont('Arial', '', 8.5);
$pdf->MultiCell(0, 4.5, $enc('El suscriptor declara bajo protesta de decir verdad que:'));
$pdf->Ln(2);
$declaraciones = [
    'Ha leído, comprendido y aceptado íntegramente el contenido del presente pagaré.',
    'Reconoce de manera expresa una obligación incondicional de pago por la cantidad total consignada en este documento.',
    'Reconoce que la obligación es exigible por la vía ejecutiva mercantil conforme al artículo 1391 del Código de Comercio.',
    'Firma electrónicamente de forma libre, informada y voluntaria, con plena validez jurídica conforme al Código de Comercio, la Ley General de Títulos y Operaciones de Crédito y demás legislación aplicable.',
    'Reconoce que este pagaré constituye un título de crédito autónomo, independiente de cualquier otro documento o contrato.',
    'Acepta expresamente la tasa de interés moratorio pactada y reconoce que la misma es razonable y proporcional.',
];
foreach ($declaraciones as $decl) { $bullet($pdf, $enc, $decl); }
$pdf->Ln(4);

// ── FIRMA ELECTRÓNICA Y EVIDENCIA DIGITAL ──────────────────────────────
$divider($pdf);
$secTitle($pdf, $enc, 'FIRMA ELECTRÓNICA Y EVIDENCIA DIGITAL');
$pdf->SetFont('Arial', '', 8.5);
$pdf->MultiCell(0, 4.5, $enc(
    'El presente pagaré se firma mediante la plataforma CINCEL, garantizando:'
));
$pdf->Ln(2);
$garantias = [
    'Identificación plena del firmante mediante validación previa de identidad (INE/Truora)',
    'Integridad del documento mediante hash criptográfico SHA-256',
    'Autenticidad de la firma mediante validación OTP de doble factor',
    'Conservación conforme a la NOM-151-SCFI-2016 mediante sello de tiempo emitido por Autoridad Certificadora autorizada por la Secretaría de Economía',
    'Cumplimiento de los artículos 89 al 114 del Código de Comercio sobre firma electrónica avanzada',
];
foreach ($garantias as $g) { $bullet($pdf, $enc, $g); }
$pdf->Ln(2);
$pdf->MultiCell(0, 4.5, $enc(
    'El suscriptor acepta expresamente que los registros electrónicos asociados a la firma, '
    . 'incluyendo dirección IP, fecha, hora, geolocalización, dispositivo y validación mediante '
    . 'código OTP, constituyen prueba plena en cualquier procedimiento judicial o extrajudicial.'
));
$pdf->Ln(4);

// ── DATOS DEL SUSCRIPTOR (CLIENTE) ─────────────────────────────────────
$divider($pdf);
$secTitle($pdf, $enc, 'DATOS DEL SUSCRIPTOR (CLIENTE)');
$pdf->SetFont('Arial', '', 8.5);
// Row 1: Nombre + CURP
$pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(35, 5, $enc('Nombre completo:'), 0);
$pdf->SetFont('Arial', '', 8.5); $pdf->Cell(60, 5, $enc($nombreCompleto), 0);
$pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(15, 5, $enc('CURP:'), 0);
$pdf->SetFont('Arial', '', 8.5); $pdf->Cell(0, 5, $enc($curp ?: '________________________________'), 0, 1);
// Row 2: RFC + Fecha nacimiento + Nacionalidad
$pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(15, 5, $enc('RFC:'), 0);
$pdf->SetFont('Arial', '', 8.5); $pdf->Cell(40, 5, $enc($rfcCliente ?: '—'), 0);
$pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(35, 5, $enc('Fecha de nacimiento:'), 0);
$pdf->SetFont('Arial', '', 8.5); $pdf->Cell(25, 5, $enc($fechaNacimiento ?: '—'), 0);
$pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(25, 5, $enc('Nacionalidad:'), 0);
$pdf->SetFont('Arial', '', 8.5); $pdf->Cell(0, 5, $enc('Mexicana'), 0, 1);
$pdf->Ln(2);
// Address
$pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(35, 5, $enc('Domicilio completo:'), 0);
$pdf->SetFont('Arial', '', 8.5); $pdf->MultiCell(0, 5, $enc($domicilio ?: '________________________________'));
$pdf->Ln(1);
// Phone + Email
$pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(30, 5, $enc('Teléfono celular:'), 0);
$pdf->SetFont('Arial', '', 8.5); $pdf->Cell(50, 5, $enc($telCliente ? ('+52 ' . $telCliente) : '—'), 0);
$pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(30, 5, $enc('Correo electrónico:'), 0);
$pdf->SetFont('Arial', '', 8.5); $pdf->Cell(0, 5, $enc($emailCliente ?: '—'), 0, 1);
$pdf->Ln(4);

// ═══════════════════════════════════════════════════════════════════════
// PAGE 3 — Acreedor + Vehículo + Firma + Validación + Aceptación + Notas
// ═══════════════════════════════════════════════════════════════════════
$pdf->AddPage();

// ── DATOS DEL ACREEDOR ─────────────────────────────────────────────────
$divider($pdf);
$secTitle($pdf, $enc, 'DATOS DEL ACREEDOR');
$pdf->SetFont('Arial', '', 8.5);
$pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(25, 5, $enc('Razón social:'), 0);
$pdf->SetFont('Arial', '', 8.5); $pdf->Cell(65, 5, $enc('MTECH GEARS, S.A. DE C.V.'), 0);
$pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(30, 5, $enc('Nombre comercial:'), 0);
$pdf->SetFont('Arial', '', 8.5); $pdf->Cell(0, 5, $enc('VOLTIKA'), 0, 1);
$pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(15, 5, $enc('RFC:'), 0);
$pdf->SetFont('Arial', '', 8.5); $pdf->Cell(40, 5, $enc('MGE230316KA2'), 0);
$pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(25, 5, $enc('Domicilio fiscal:'), 0);
$pdf->SetFont('Arial', '', 8.5); $pdf->Cell(0, 5, $enc('Jaime Balmes 71 Int 101, Polanco, Miguel Hidalgo, CDMX C.P. 11510'), 0, 1);
$pdf->Ln(4);

// ── DATOS DEL VEHÍCULO AMPARADO ────────────────────────────────────────
$divider($pdf);
$secTitle($pdf, $enc, 'DATOS DEL VEHÍCULO AMPARADO');
$pdf->SetFont('Arial', '', 8.5);
$pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(18, 5, $enc('Modelo:'), 0);
$pdf->SetFont('Arial', '', 8.5); $pdf->Cell(40, 5, $enc((string)($moto['modelo'] ?? '—')), 0);
$pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(15, 5, $enc('Color:'), 0);
$pdf->SetFont('Arial', '', 8.5); $pdf->Cell(30, 5, $enc((string)($moto['color'] ?? '—')), 0);
$pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(18, 5, $enc('VIN/NIV:'), 0);
$pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(40, 5, $enc($vinDisplay), 0);
$pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(12, 5, $enc('Año:'), 0);
$pdf->SetFont('Arial', '', 8.5); $pdf->Cell(0, 5, $enc((string)($moto['anio_modelo'] ?? date('Y'))), 0, 1);
$pdf->Ln(4);

// ── FIRMA DEL SUSCRIPTOR ───────────────────────────────────────────────
$divider($pdf);
$secTitle($pdf, $enc, 'FIRMA DEL SUSCRIPTOR');
if ($firmaImgPath && file_exists($firmaImgPath)) {
    $pdf->Image($firmaImgPath, $pdf->GetX() + 20, $pdf->GetY(), 80, 30);
    $pdf->Ln(35);
} else {
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(0, 25, $enc('[ESPACIO PARA FIRMA GRÁFICA O CINCEL]'), 0, 1, 'C');
}
$pdf->Ln(2);

// Validación electrónica (structured bullet list per v2 template)
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->Cell(0, 5, $enc('Validación electrónica:'), 0, 1);
$pdf->Ln(1);
$pdf->SetFont('Arial', '', 8);
$otpDisplay = $otpVerified ? $otpCode : 'Pendiente';
$validacionItems = [
    ['OTP capturado:', $otpDisplay],
    ['Fecha y hora de firma:', $fechaFirma],
    ['Dirección IP:', $ip],
    ['Geolocalización:', $geoDisplay],
    ['Dispositivo:', substr($ua, 0, 80)],
    ['Hash del documento (SHA-256):', $hashDisplay],
    ['Folio NOM-151:', $nom151Display],
    ['Folio CINCEL:', $cincelDisplay],
];
foreach ($validacionItems as $vi) {
    $pdf->SetFont('Arial', 'B', 8); $pdf->Cell(6, 4.5, $enc(chr(149)), 0, 0);
    $pdf->Cell(50, 4.5, $enc($vi[0]), 0, 0);
    $pdf->SetFont('Arial', '', 8); $pdf->Cell(0, 4.5, $enc($vi[1]), 0, 1);
}
$pdf->Ln(4);

// ── ACEPTACIÓN DEL ACREEDOR ────────────────────────────────────────────
$divider($pdf);
$secTitle($pdf, $enc, 'ACEPTACIÓN DEL ACREEDOR');
$pdf->SetFont('Arial', '', 8.5);
$pdf->MultiCell(0, 5, $enc(
    'MTECH GEARS, S.A. DE C.V. (VOLTIKA) acepta el presente título de crédito.'
));
$pdf->Ln(1);
$pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(25, 5, $enc('Folio interno:'), 0);
$pdf->SetFont('Arial', '', 8.5); $pdf->Cell(60, 5, $enc($folio), 0);
$pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(20, 5, $enc('Generado:'), 0);
$pdf->SetFont('Arial', '', 8.5); $pdf->Cell(0, 5, $enc($fechaFirma), 0, 1);
$pdf->Ln(4);

// ── NOTAS LEGALES ──────────────────────────────────────────────────────
$divider($pdf);
$secTitle($pdf, $enc, 'NOTAS LEGALES');
$pdf->SetFont('Arial', '', 8);
$pdf->MultiCell(0, 4.5, $enc(
    'Este pagaré se rige por la Ley General de Títulos y Operaciones de Crédito (artículos 170 a '
    . '174 para pagarés), el Código de Comercio (artículos 89-114 para firma electrónica y 1391-1414 '
    . 'para vía ejecutiva mercantil), la Norma Oficial Mexicana NOM-151-SCFI-2016, y demás '
    . 'disposiciones aplicables.'
));
// (Old footer metadata removed — now in FIRMA DEL SUSCRIPTOR validation section above)

// ── Output PDF ─────────────────────────────────────────────────────────
$pdf->Output('F', $filepath);

if ($_pdfPass === 1) {
    // After pass 1: compute hash, call Cincel, set real values for pass 2
    $pdfHash = hash_file('sha256', $filepath);

    $cincelEnabled = strtolower((string)(getenv('CINCEL_TIMESTAMP_ENABLED') ?: '1'));
    if (in_array($cincelEnabled, ['1','true','yes','on'], true)) {
        try {
            require_once __DIR__ . '/../../../configurador/php/cincel-timestamp.php';
            try { $pdo->exec("ALTER TABLE checklist_entrega_v2 ADD COLUMN cincel_pagare_timestamp_hash CHAR(64) NULL"); } catch (Throwable $e) {}
            try { $pdo->exec("ALTER TABLE checklist_entrega_v2 ADD COLUMN cincel_pagare_status VARCHAR(40) NULL"); } catch (Throwable $e) {}
            $ts = cincelGetOrCreateTimestamp($filepath);
            if (!empty($ts['ok'])) {
                cincelSaveTimestamp($pdo, $ts, null, $filepath);
                $cincelHash  = $ts['hash'] ?? null;
                $cincelFolio = $ts['folio'] ?? $ts['id'] ?? $cincelHash;
                if ($cincelHash) {
                    try {
                        $pdo->prepare("UPDATE checklist_entrega_v2
                            SET cincel_pagare_timestamp_hash = ?,
                                cincel_pagare_status = 'signed_with_timestamp'
                            WHERE id = ?")
                            ->execute([$cincelHash, $checkId]);
                    } catch (Throwable $e) { error_log('generar-pagare cincel persist: ' . $e->getMessage()); }
                }
                adminLog('pagare_cincel_timestamp', [
                    'moto_id' => $motoId, 'checklist_id' => $checkId,
                    'cincel_hash' => $cincelHash, 'folio' => $folio,
                ]);
            } else {
                $cincelErr = 'Cincel HTTP ' . ($ts['http'] ?? '?');
                error_log('generar-pagare cincel failed: ' . json_encode($ts));
            }
        } catch (Throwable $e) {
            $cincelErr = 'Cincel error';
            error_log('generar-pagare cincel exception: ' . $e->getMessage());
        }
    }

    // Set real values for pass 2 — FPDF handles encoding properly
    $hashDisplay   = substr($pdfHash, 0, 40);
    $nom151Display = $cincelFolio ?: ($cincelErr ?: 'No disponible');
    $cincelDisplay = $cincelFolio ?: ($cincelErr ?: 'No disponible');
}

} // end for $_pdfPass

// Final hash is of the pass-2 PDF (which has real values embedded)
$pdfHash = hash_file('sha256', $filepath);

if ($firmaImgPath && file_exists($firmaImgPath)) @unlink($firmaImgPath);

// ── 5. Save to DB ───────────────────────────────────────────────────────
// Round 111 — enriched evidence + new columns for legal enforceability
$evidencia = json_encode([
    'ip' => $ip,
    'user_agent' => substr($ua, 0, 500),
    'fecha_hora' => date('Y-m-d H:i:s'),
    'otp_validado' => $otpVerified,
    'otp_code' => $otpCode ?: null,
    'otp_timestamp' => $otpTimestamp ?: null,
    'vin' => $moto['vin_display'] ?? $moto['vin'] ?? null,
    'folio' => $folio,
    'moto_id' => $motoId,
    'generado_por' => $uid,
    'curp' => $curp,
    'domicilio_completo' => $domicilio,
    'monto_total_operacion' => $pagoTotalPlazos,
    'enganche' => $enganche,
    'fecha_vencimiento' => $fechaVencimientoISO,
], JSON_UNESCAPED_UNICODE);

$pdo->prepare("UPDATE checklist_entrega_v2
    SET pagare_pdf_path=?, pagare_pdf_hash=?, pagare_ip=?, pagare_user_agent=?, pagare_evidencia=?,
        pagare_curp=?, pagare_calle=?, pagare_num_exterior=?, pagare_num_interior=?,
        pagare_colonia=?, pagare_alcaldia=?, pagare_estado_dir=?, pagare_cp=?,
        pagare_monto_total_operacion=?, pagare_enganche=?,
        pagare_fecha_vencimiento=?, pagare_status='signed'
    WHERE id=?")
    ->execute([
        $filename, $pdfHash, $ip, substr($ua, 0, 500), $evidencia,
        $curp ?: null, $addrCalle ?: null, $addrNumExt ?: null, $addrNumInt ?: null,
        $addrColonia ?: null, $addrAlcaldia ?: null, $addrEstado ?: null, $addrCp ?: null,
        $pagoTotalPlazos, $enganche,
        $fechaVencimientoISO, $checkId,
    ]);

adminLog('pagare_pdf_generado', [
    'moto_id' => $motoId,
    'checklist_id' => $checkId,
    'folio' => $folio,
    'pdf_hash' => $pdfHash,
]);

adminJsonOut([
    'ok' => true,
    'folio' => $folio,
    'pdf_path' => $filename,
    'pdf_hash' => $pdfHash,
    'cincel_hash' => $cincelHash,
    'cincel_status' => $cincelHash ? 'signed_with_timestamp' : ($cincelErr ? 'cincel_failed' : 'cincel_disabled'),
    'cincel_err' => $cincelErr,
    'datos' => [
        'nombre' => $nombreCompleto,
        'monto' => $pagoTotalPlazos,
        'monto_fmt' => $montoNum,
        'vin' => $moto['vin_display'] ?? $moto['vin'] ?? '',
    ],
]);

// ── Helper: Number to Spanish words ─────────────────────────────────────

function numberToSpanishWords(float $num): string {
    $num = round($num, 2);
    $entero = (int)$num;
    $centavos = round(($num - $entero) * 100);

    $unidades = ['', 'un', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve'];
    $especiales = ['diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve'];
    $decenas = ['', 'diez', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
    $centenas = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];

    $convertGroup = function(int $n) use ($unidades, $especiales, $decenas, $centenas): string {
        if ($n === 0) return '';
        if ($n === 100) return 'cien';
        $result = '';
        if ($n >= 100) { $result .= $centenas[(int)($n / 100)] . ' '; $n %= 100; }
        if ($n >= 10 && $n <= 19) { $result .= $especiales[$n - 10]; return trim($result); }
        if ($n >= 20 && $n <= 29) { $result .= 'veinti' . $unidades[$n - 20]; return trim($result); }
        if ($n >= 30) { $result .= $decenas[(int)($n / 10)]; $n %= 10; if ($n > 0) $result .= ' y ' . $unidades[$n]; return trim($result); }
        if ($n > 0) $result .= $unidades[$n];
        return trim($result);
    };

    if ($entero === 0) { $texto = 'cero'; }
    else {
        $texto = '';
        if ($entero >= 1000000) { $m = (int)($entero / 1000000); $texto .= ($m === 1 ? 'un millón' : $convertGroup($m) . ' millones') . ' '; $entero %= 1000000; }
        if ($entero >= 1000) { $k = (int)($entero / 1000); $texto .= ($k === 1 ? 'mil' : $convertGroup($k) . ' mil') . ' '; $entero %= 1000; }
        if ($entero > 0) $texto .= $convertGroup($entero);
    }

    $texto = trim($texto) . ' pesos';
    $texto .= ' ' . str_pad((string)(int)$centavos, 2, '0', STR_PAD_LEFT) . '/100 M.N.';
    return mb_strtoupper($texto);
}
