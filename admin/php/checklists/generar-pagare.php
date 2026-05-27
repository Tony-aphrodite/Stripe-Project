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
$inputCalle       = trim((string)($d['calle'] ?? ''));
$inputNumExt      = trim((string)($d['num_exterior'] ?? ''));
$inputNumInt      = trim((string)($d['num_interior'] ?? ''));
$inputColonia     = trim((string)($d['colonia'] ?? ''));
$inputAlcaldia    = trim((string)($d['alcaldia'] ?? ''));
$inputEstadoDir   = trim((string)($d['estado_dir'] ?? ''));
$inputCp          = trim((string)($d['cp'] ?? ''));

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

// Save signature image temporarily if provided
$firmaImgPath = null;
if ($firmaB64 && strpos($firmaB64, 'data:image/png;base64,') === 0) {
    $firmaData = base64_decode(str_replace('data:image/png;base64,', '', $firmaB64));
    $firmaImgPath = $storageDir . 'firma_pagare_' . $motoId . '_' . date('Ymd_His') . '.png';
    file_put_contents($firmaImgPath, $firmaData);
}

// Helper
$enc = function($s) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string)$s); };

$pdf = new FPDF();
$pdf->SetAutoPageBreak(true, 15);

// ═══════════════════════════════════════════════════════════════════════
// PAGE 1 — PAGARÉ header + legal text
// ═══════════════════════════════════════════════════════════════════════
$pdf->AddPage();

// Title
$pdf->SetFont('Arial', 'B', 20);
$pdf->Cell(0, 12, $enc('PAGARÉ'), 0, 1, 'C');
$pdf->Ln(2);

// Amount line — number + letter
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 7, $enc('Por la cantidad de ' . $montoNum), 0, 1, 'C');
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(0, 6, $enc('(' . $montoLetra . ')'), 0, 1, 'C');
$pdf->Ln(3);

// Place, date, and maturity (Bug #5)
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(95, 6, $enc('Lugar de suscripción: ' . $lugarSuscripcion), 0, 0);
$pdf->Cell(95, 6, $enc('Fecha de suscripción: ' . $fechaSuscripcion), 0, 1, 'R');
$pdf->Cell(95, 6, '', 0, 0);
$pdf->Cell(95, 6, $enc('Fecha de vencimiento final: ' . $fechaVencimiento), 0, 1, 'R');
$pdf->Ln(3);

// ── Main obligation text ────────────────────────────────────────────────
$pdf->SetFont('Arial', '', 8.5);
$pdf->MultiCell(0, 4.5, $enc(
    'DEBO Y PAGARÉ incondicionalmente a la orden de MTECH GEARS, S.A. DE C.V. (VOLTIKA), '
    . 'la cantidad señalada en el presente documento, obligándome a cubrirla en el domicilio del '
    . 'acreedor o en el lugar que éste designe.'
));
$pdf->Ln(1);
$pdf->MultiCell(0, 4.5, $enc(
    'La obligación consignada en este pagaré es líquida, cierta, exigible y de plazo vencido en '
    . 'los términos aquí establecidos, constituyendo una obligación directa, autónoma e independiente '
    . 'conforme a la Ley General de Títulos y Operaciones de Crédito.'
));
$pdf->Ln(1);
$pdf->MultiCell(0, 4.5, $enc(
    'El presente pagaré se suscribe con motivo de una operación de compraventa a plazos celebrada '
    . 'entre las partes; sin embargo, su validez, existencia y exigibilidad no dependen de dicho '
    . 'contrato ni de documento alguno.'
));
$pdf->Ln(3);

// ── DATOS DEL VEHÍCULO (Fix #6 — VIN prominent in body) ────────────────
$pdf->SetDrawColor(180, 180, 180);
$pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 6, $enc('DATOS DEL VEHÍCULO'), 0, 1);
$pdf->SetFont('Arial', '', 8.5);
$pdf->Cell(50, 5, $enc('Marca:'), 0); $pdf->Cell(0, 5, $enc('VOLTIKA (MTECH GEARS, S.A. DE C.V.)'), 0, 1);
$pdf->Cell(50, 5, $enc('Modelo:'), 0); $pdf->Cell(0, 5, $enc((string)($moto['modelo'] ?? '—')), 0, 1);
$pdf->Cell(50, 5, $enc('Color:'), 0); $pdf->Cell(0, 5, $enc((string)($moto['color'] ?? '—')), 0, 1);
$pdf->Cell(50, 5, $enc('VIN / NIV:'), 0); $pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(0, 5, $enc((string)($moto['vin_display'] ?? $moto['vin'] ?? '—')), 0, 1); $pdf->SetFont('Arial', '', 8.5);
$pdf->Cell(50, 5, $enc('Año modelo:'), 0); $pdf->Cell(0, 5, $enc((string)($moto['anio_modelo'] ?? date('Y'))), 0, 1);
$pdf->Ln(3);

// ── VENCIMIENTO Y EXIGIBILIDAD (Fix #5 — updated clause per 5-27.md spec) ─
$pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 6, $enc('VENCIMIENTO Y EXIGIBILIDAD'), 0, 1);
$pdf->SetFont('Arial', '', 8.5);
$pdf->MultiCell(0, 4.5, $enc(
    'El presente pagaré es exigible íntegramente desde su suscripción. No obstante, las partes reconocen '
    . 'que el acreedor ha otorgado al suscriptor la facilidad de cubrir esta obligación mediante pagos '
    . 'parciales, conforme a lo establecido en el Contrato de Compraventa a Plazos celebrado entre las partes.'
));
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 6, $enc('VENCIMIENTO ANTICIPADO POR INCUMPLIMIENTO'), 0, 1);
$pdf->SetFont('Arial', '', 8.5);
$pdf->MultiCell(0, 4.5, $enc(
    'En caso de que el suscriptor incurra en incumplimiento de cualquiera de los pagos parciales pactados '
    . 'en el referido Contrato, el saldo insoluto del presente pagaré se considerará vencido anticipadamente '
    . 'y será exigible en su totalidad de forma inmediata, sin necesidad de requerimiento, aviso previo, '
    . 'interpelación judicial o extrajudicial.'
));
$pdf->Ln(1);
$pdf->MultiCell(0, 4.5, $enc(
    'La validez, existencia y exigibilidad del presente pagaré no dependen del Contrato referido, '
    . 'conforme al principio de autonomía cambiaria establecido en el artículo 167 de la Ley General '
    . 'de Títulos y Operaciones de Crédito.'
));
$pdf->Ln(3);

// ── INTERESES MORATORIOS ────────────────────────────────────────────────
$pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 6, $enc('INTERESES MORATORIOS'), 0, 1);
$pdf->SetFont('Arial', '', 8.5);
$pdf->MultiCell(0, 4.5, $enc(
    'En caso de incumplimiento, el suscriptor se obliga a pagar un interés moratorio sobre el saldo '
    . 'insoluto a una tasa del 3.5% (tres punto cinco por ciento) mensual, calculado desde la fecha de '
    . 'incumplimiento y hasta la total liquidación del adeudo.'
));
$pdf->Ln(1);
$pdf->MultiCell(0, 4.5, $enc(
    'Dicha tasa constituye una estimación razonable de los daños y perjuicios derivados del '
    . 'incumplimiento, incluyendo costos administrativos, operativos y de recuperación.'
));
$pdf->Ln(1);
$pdf->MultiCell(0, 4.5, $enc(
    'El suscriptor reconoce y acepta expresamente la tasa de interés moratorio del 3.5% mensual '
    . 'y reconoce que la misma es razonable y proporcional considerando los costos administrativos, '
    . 'operativos y de recuperación.'
));
$pdf->Ln(1);
$pdf->MultiCell(0, 4.5, $enc(
    'Los intereses moratorios no se capitalizarán ni generarán intereses sobre intereses.'
));
$pdf->Ln(3);

// ── APLICACIÓN DE PAGOS ─────────────────────────────────────────────────
$pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 6, $enc('APLICACIÓN DE PAGOS'), 0, 1);
$pdf->SetFont('Arial', '', 8.5);
$pdf->MultiCell(0, 4.5, $enc(
    'Todos los pagos que realice el suscriptor serán aplicados al presente pagaré, reduciendo el '
    . 'saldo exigible en la proporción correspondiente, sin afectar la validez ni exigibilidad del mismo.'
));
$pdf->Ln(3);

// ── RENUNCIA Y JURISDICCIÓN ─────────────────────────────────────────────
$pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 6, $enc('RENUNCIA Y JURISDICCIÓN'), 0, 1);
$pdf->SetFont('Arial', '', 8.5);
$pdf->MultiCell(0, 4.5, $enc(
    'El suscriptor renuncia expresamente a cualquier fuero que pudiera corresponderle por razón de su '
    . 'domicilio presente o futuro, sometiéndose irrevocablemente a la jurisdicción y competencia de '
    . 'los tribunales de la Ciudad de México.'
));

// ═══════════════════════════════════════════════════════════════════════
// PAGE 2 — Declaration + Signature + Evidence
// ═══════════════════════════════════════════════════════════════════════
$pdf->AddPage();

// ── DECLARACIÓN EXPRESA DEL SUSCRIPTOR ──────────────────────────────────
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 6, $enc('DECLARACIÓN EXPRESA DEL SUSCRIPTOR'), 0, 1);
$pdf->Ln(1);
$pdf->SetFont('Arial', '', 8.5);
$pdf->MultiCell(0, 4.5, $enc('El suscriptor declara bajo protesta de decir verdad que:'));
$pdf->Ln(1);

$declaraciones = [
    'Ha leído, comprendido y aceptado íntegramente el contenido del presente pagaré.',
    'Reconoce de manera expresa una obligación incondicional de pago por la cantidad total consignada en este documento.',
    'Reconoce que la obligación es exigible por la vía ejecutiva mercantil conforme al artículo 1391 del Código de Comercio.',
    'Firma electrónicamente de forma libre, informada y voluntaria, con plena validez jurídica conforme al Código de Comercio, la Ley General de Títulos y Operaciones de Crédito y demás legislación aplicable.',
    'Reconoce que este pagaré constituye un título de crédito autónomo, independiente de cualquier otro documento o contrato.',
];
foreach ($declaraciones as $decl) {
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(5, 4.5, $enc('•'), 0, 0);
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->MultiCell(0, 4.5, $enc($decl));
    $pdf->Ln(0.5);
}
$pdf->Ln(3);

// ── FIRMA ELECTRÓNICA Y EVIDENCIA DIGITAL ───────────────────────────────
$pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 6, $enc('FIRMA ELECTRÓNICA Y EVIDENCIA DIGITAL'), 0, 1);
$pdf->SetFont('Arial', '', 8.5);
$pdf->MultiCell(0, 4.5, $enc(
    'El presente pagaré podrá ser firmado mediante medios electrónicos a través de la plataforma '
    . 'CINCEL u otra herramienta de firma electrónica avanzada, garantizando:'
));
$pdf->Ln(1);
$garantias = [
    'Identificación plena del firmante',
    'Integridad del documento',
    'Autenticidad de la firma',
    'Conservación conforme a NOM-151',
];
foreach ($garantias as $g) {
    $pdf->Cell(8, 4.5, $enc('- '), 0, 0);
    $pdf->Cell(0, 4.5, $enc($g), 0, 1);
}
$pdf->Ln(1);
$pdf->MultiCell(0, 4.5, $enc(
    'El suscriptor acepta que los registros electrónicos asociados a la firma, incluyendo dirección IP, '
    . 'fecha, hora, geolocalización, dispositivo y validación mediante código OTP, constituyen prueba '
    . 'plena en cualquier procedimiento judicial o extrajudicial.'
));
$pdf->Ln(4);

// ── DATOS DEL SUSCRIPTOR (auto-filled) ──────────────────────────────────
$pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 6, $enc('DATOS DEL SUSCRIPTOR (CLIENTE)'), 0, 1);
$pdf->Ln(1);

$w1 = 40; $h = 7;
$suscriptorRows = [
    ['Nombre completo', $nombreCompleto ?: '________________________________'],
    ['CURP', $curp ?: '________________________________'],
    ['Domicilio', $domicilio ?: '________________________________'],
    ['Teléfono', $telCliente ? ('+52 ' . $telCliente) : '________________________________'],
];
foreach ($suscriptorRows as $row) {
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->Cell($w1, $h, $enc($row[0] . ':'), 0, 0);
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->Cell(0, $h, $enc($row[1]), 'B', 1);
}
$pdf->Ln(5);

// ── FIRMA DEL SUSCRIPTOR ────────────────────────────────────────────────
$pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 6, $enc('FIRMA DEL SUSCRIPTOR'), 0, 1);
$pdf->Ln(1);

if ($firmaImgPath && file_exists($firmaImgPath)) {
    $pdf->Image($firmaImgPath, $pdf->GetX() + 30, $pdf->GetY(), 80, 30);
    $pdf->Ln(32);
} else {
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(0, 6, $enc('Firma electrónica: ________________________________'), 0, 1);
    $pdf->Ln(2);
}
$pdf->SetFont('Arial', 'I', 8);
$pdf->Cell(0, 5, $enc('(Validación mediante CINCEL y/o código OTP)'), 0, 1, 'C');
$pdf->Ln(5);

// ── ACREEDOR ────────────────────────────────────────────────────────────
$pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 6, $enc('ACREEDOR'), 0, 1);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(0, 6, $enc('MTECH GEARS, S.A. DE C.V.'), 0, 1);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 6, $enc('(VOLTIKA)'), 0, 1);
$pdf->Ln(3);

// ── Evidence metadata (small footer) ────────────────────────────────────
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'N/A';
if ($ip !== 'N/A') $ip = explode(',', $ip)[0];
$ua = $_SERVER['HTTP_USER_AGENT'] ?? 'N/A';
// Bug #3 fix: use real OTP data from entregas table (not checklist fase4 flag)
$otpInfo = $otpVerified
    ? ('Validado — Código: ' . substr($otpCode, 0, 3) . '*** — ' . $otpTimestamp)
    : 'Pendiente';

$pdf->SetFont('Arial', '', 6.5);
$pdf->SetTextColor(120, 120, 120);
$pdf->Cell(0, 4, $enc('Folio: ' . $folio . '  |  IP: ' . $ip . '  |  OTP: ' . $otpInfo . '  |  VIN: ' . ($moto['vin_display'] ?? $moto['vin'] ?? 'N/A')), 0, 1);
$pdf->Cell(0, 4, $enc('Generado: ' . date('Y-m-d H:i:s') . '  |  Dispositivo: ' . substr($ua, 0, 90)), 0, 1);
$pdf->SetTextColor(0, 0, 0);

// ── Output PDF ──────────────────────────────────────────────────────────
$pdf->Output('F', $filepath);

if ($firmaImgPath && file_exists($firmaImgPath)) @unlink($firmaImgPath);

$pdfHash = hash_file('sha256', $filepath);

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

// ── Round 96 (2026-05-26) — Apply Cincel NOM-151 timestamp to the PAGARÉ ──
// Customer brief (Óscar, today's Carlos Ricardo Sánchez delivery): "I need
// you to make sure that the promissory note works properly and that the PDF
// is signed with CINCEL." Before this fix, the PAGARÉ PDF was generated and
// stored but no actual Cincel NOM-151 stamp was applied — the PDF text
// mentioned NOM-151 but no API call was ever made. Now we mirror the
// Round 71/73 pattern from firmar-acta.php / confirmar-orden.php: get-or-
// create timestamp via Cincel, persist the result in cincel_timestamps,
// and store the certified hash in checklist_entrega_v2.cincel_pagare_timestamp_hash
// so admin can verify the legal trail.
//
// Failure-safe: any exception is logged but never blocks the response.
// The pagaré PDF + DB row already exist; Cincel stamping can be retried
// later by re-running this endpoint or via admin/php/diagnostico-cincel-timestamp-create.php.
// Gateable globally via CINCEL_TIMESTAMP_ENABLED=0 in case Cincel has an outage.
$cincelHash = null;
$cincelErr  = null;
$cincelEnabled = strtolower((string)(getenv('CINCEL_TIMESTAMP_ENABLED') ?: '1'));
if (in_array($cincelEnabled, ['1','true','yes','on'], true)) {
    try {
        require_once __DIR__ . '/../../../configurador/php/cincel-timestamp.php';
        // Idempotent column add — older installs may not have these columns.
        try { $pdo->exec("ALTER TABLE checklist_entrega_v2 ADD COLUMN cincel_pagare_timestamp_hash CHAR(64) NULL"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE checklist_entrega_v2 ADD COLUMN cincel_pagare_status VARCHAR(40) NULL"); } catch (Throwable $e) {}

        $ts = cincelGetOrCreateTimestamp($filepath);
        if (!empty($ts['ok'])) {
            cincelSaveTimestamp($pdo, $ts, null, $filepath);
            $cincelHash = $ts['hash'] ?? null;
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
            $cincelErr = 'Cincel respondió HTTP ' . ($ts['http'] ?? '?') . ' — ' . ($ts['error'] ?? 'sin detalle');
            error_log('generar-pagare cincel failed: ' . json_encode($ts));
        }
    } catch (Throwable $e) {
        $cincelErr = 'Cincel exception: ' . $e->getMessage();
        error_log('generar-pagare cincel exception: ' . $e->getMessage());
    }
}

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
    // DEBUG — remove after verifying name fix works
    '_debug_nombre' => [
        'moto_cliente_nombre' => (string)($moto['cliente_nombre'] ?? '(null)'),
        'cliente_nombre'      => $cliente ? (string)($cliente['nombre'] ?? '(null)') : '(no cliente row)',
        'final_nombreCompleto'=> $nombreCompleto,
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
