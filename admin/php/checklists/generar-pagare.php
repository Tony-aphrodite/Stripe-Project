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

// ── 3. Build data for PDF ───────────────────────────────────────────────
// Load canonical name sanitizer if available (Round 83 v2 pattern)
$_ccPath = __DIR__ . '/../../../configurador/php/contrato-contado.php';
if (is_file($_ccPath) && !function_exists('contratoContadoSanitizeFullName')) {
    @require_once $_ccPath;
}

$curp = (string)($cliente['curp'] ?? '');
$domicilio = (string)($cliente['domicilio'] ?? ($trans['domicilio'] ?? ''));
$telCliente = (string)($moto['cliente_telefono'] ?? ($cliente['telefono'] ?? ''));

// Round 110 (2026-05-27) — Fix PAGARÉ amount calculation. Before this fix:
// - Amount used transacciones.total which is the ENGANCHE (already paid)
// - For Carlos: showed $12,065 (enganche) instead of the REMAINING debt
// - Name only showed first name from clientes.nombre
// - CURP/domicilio empty because clientes table is sparse
//
// The PAGARÉ amount MUST be the SALDO PENDIENTE (what the customer still
// owes after the enganche) — that's the legal obligation the pagaré secures.
// Source of truth: subscripciones_credito.monto_semanal * weeks.
//
// Catalog prices for computing precioContado when not in DB:
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

$enganche = 0;
$pagoSemanal = 0;
$numPagos = 0;
$montoFinanciado = 0;
$plazoMeses = 36;

// For credit customers, transacciones.total IS the enganche (not the bike price)
if ($trans) {
    $enganche = floatval($trans['total'] ?? $trans['precio'] ?? 0);
}

if ($credito) {
    // Use subscription data for payment schedule (source of truth for billing)
    $pagoSemanal = floatval($credito['monto_semanal'] ?? 0);
    $plazoMeses  = intval($credito['plazo_meses'] ?? 36);
    $plazoSem    = intval($credito['plazo_semanas'] ?? 0);
    $numPagos    = $plazoSem > 0 ? $plazoSem : (int)round($plazoMeses * 4.33);
    // Override enganche from sub if available
    if (!empty($credito['enganche'])) $enganche = floatval($credito['enganche']);
    $montoFinanciado = floatval($credito['monto_financiado'] ?? 0);
    if (!$montoFinanciado && $precioContado > 0) {
        $montoFinanciado = $precioContado - $enganche;
    }
}

// PAGARÉ amount = SALDO PENDIENTE (what customer still owes, excluding already-paid enganche)
// This is the sum of all future weekly payments = pagoSemanal × numPagos
$saldoPendiente = $pagoSemanal > 0 && $numPagos > 0
    ? round($pagoSemanal * $numPagos, 2)
    : ($montoFinanciado > 0 ? $montoFinanciado : ($precioContado - $enganche));
// Never let the PAGARÉ amount be the enganche itself (that's already paid!)
if ($saldoPendiente <= 0 && $precioContado > 0) $saldoPendiente = $precioContado - $enganche;
$pagoTotalPlazos = $saldoPendiente;

// Full customer name — use inventario_motos.cliente_nombre as primary (most reliable),
// fall back to clientes table joined name.
if (function_exists('contratoContadoSanitizeFullName')) {
    $nombreCompleto = contratoContadoSanitizeFullName(
        (string)($moto['cliente_nombre'] ?? $cliente['nombre'] ?? ''),
        (string)($cliente['apellido_paterno'] ?? ''),
        (string)($cliente['apellido_materno'] ?? '')
    );
} else {
    $nombreCompleto = (string)($moto['cliente_nombre'] ?? '');
    if ($cliente) {
        $parts = array_filter([$cliente['nombre'] ?? '', $cliente['apellido_paterno'] ?? '', $cliente['apellido_materno'] ?? '']);
        if ($parts) $nombreCompleto = implode(' ', $parts);
    }
}
if ($nombreCompleto === '') $nombreCompleto = (string)($moto['cliente_nombre'] ?? 'Cliente');

// CURP — try clientes, then verificaciones_identidad
if (!$curp && !empty($moto['cliente_email'])) {
    try {
        $vq = $pdo->prepare("SELECT expected_curp, verified_curp FROM verificaciones_identidad
            WHERE (email = ? OR telefono = ?) AND (expected_curp IS NOT NULL OR verified_curp IS NOT NULL)
            ORDER BY id DESC LIMIT 1");
        $vq->execute([$moto['cliente_email'], $moto['cliente_telefono'] ?? '']);
        $vc = $vq->fetch(PDO::FETCH_ASSOC) ?: [];
        $curp = (string)($vc['verified_curp'] ?: $vc['expected_curp'] ?: '');
    } catch (Throwable $e) {}
}

// Domicilio — try transacciones ciudad+estado+cp
if (!$domicilio && $trans) {
    $parts = array_filter([
        $trans['ciudad'] ?? '', $trans['estado'] ?? '',
        !empty($trans['cp']) ? ('C.P. ' . $trans['cp']) : '',
    ]);
    if ($parts) $domicilio = implode(', ', $parts);
}

$montoLetra = numberToSpanishWords($pagoTotalPlazos);
$montoNum = '$' . number_format($pagoTotalPlazos, 2) . ' MXN';

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

// Place and date
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(95, 6, $enc('Lugar de suscripción: ' . $lugarSuscripcion), 0, 0);
$pdf->Cell(95, 6, $enc('Fecha de suscripción: ' . $fechaSuscripcion), 0, 1, 'R');
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

// ── VENCIMIENTO ANTICIPADO ──────────────────────────────────────────────
$pdf->SetDrawColor(180, 180, 180);
$pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 6, $enc('VENCIMIENTO ANTICIPADO'), 0, 1);
$pdf->SetFont('Arial', '', 8.5);
$pdf->MultiCell(0, 4.5, $enc(
    'En caso de incumplimiento en cualquiera de los pagos pactados, el saldo insoluto del presente '
    . 'pagaré se considerará vencido anticipadamente y será exigible en su totalidad de forma inmediata, '
    . 'sin necesidad de requerimiento, aviso previo, interpelación judicial o extrajudicial.'
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
    'El suscriptor reconoce y acepta expresamente la tasa pactada.'
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
    'Reconoce que la obligación es exigible por la vía ejecutiva mercantil.',
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
$otpInfo = $cl['fase4_completada'] ? ('Validado — ' . ($cl['otp_timestamp'] ?? $cl['fase4_fecha'] ?? 'N/A')) : 'Pendiente';

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
$evidencia = json_encode([
    'ip' => $ip,
    'user_agent' => substr($ua, 0, 500),
    'fecha_hora' => date('Y-m-d H:i:s'),
    'otp_validado' => $cl['fase4_completada'] ? true : false,
    'otp_code' => $cl['otp_code'] ?? null,
    'otp_timestamp' => $cl['otp_timestamp'] ?? null,
    'vin' => $moto['vin_display'] ?? $moto['vin'] ?? null,
    'folio' => $folio,
    'moto_id' => $motoId,
    'generado_por' => $uid,
], JSON_UNESCAPED_UNICODE);

$pdo->prepare("UPDATE checklist_entrega_v2
    SET pagare_pdf_path=?, pagare_pdf_hash=?, pagare_ip=?, pagare_user_agent=?, pagare_evidencia=?
    WHERE id=?")
    ->execute([$filename, $pdfHash, $ip, substr($ua, 0, 500), $evidencia, $checkId]);

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
