<?php
/**
 * POST — Generate immutable Pagaré PDF with auto-populated client data
 * Body: { moto_id, firma_data (base64 PNG, optional — added later by guardar-firma) }
 * Returns: { ok, pdf_path, pdf_hash, folio }
 *
 * Flow: Called from Fase 5 before or during signature capture.
 * If firma_data is provided, it's embedded in the PDF.
 * The PDF is saved to temp storage and its SHA-256 hash recorded for NOM-151.
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$motoId   = (int)($d['moto_id'] ?? 0);
$firmaB64 = $d['firma_data'] ?? '';

if (!$motoId) adminJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();

// ── 1. Get checklist ────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT id, completado, otp_code, otp_timestamp,
        fase4_completada, fase4_fecha
    FROM checklist_entrega_v2 WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$stmt->execute([$motoId]);
$cl = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cl) adminJsonOut(['error' => 'Checklist de entrega no encontrado'], 404);
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

// Get transaction data for credit details
$trans = null;
if (!empty($moto['transaccion_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM transacciones WHERE id = ?");
    $stmt->execute([$moto['transaccion_id']]);
    $trans = $stmt->fetch(PDO::FETCH_ASSOC);
}
// Fallback: search by client email
if (!$trans && !empty($moto['cliente_email'])) {
    $stmt = $pdo->prepare("SELECT * FROM transacciones WHERE email = ? ORDER BY freg DESC LIMIT 1");
    $stmt->execute([$moto['cliente_email']]);
    $trans = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get subscription/credit details
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

// Get client master data
$cliente = null;
if (!empty($moto['cliente_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
    $stmt->execute([$moto['cliente_id']]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ── 3. Build data for PDF ───────────────────────────────────────────────
$nombreCompleto = $moto['cliente_nombre'] ?? '';
if ($cliente) {
    $parts = array_filter([$cliente['nombre'] ?? '', $cliente['apellido_paterno'] ?? '', $cliente['apellido_materno'] ?? '']);
    if ($parts) $nombreCompleto = implode(' ', $parts);
}

$montoTotal = 0;
$enganche = 0;
$pagoSemanal = 0;
$numPagos = 0;
$montoFinanciado = 0;

if ($trans) {
    $montoTotal = floatval($trans['total'] ?? $trans['precio'] ?? 0);
}
if ($credito) {
    $enganche = floatval($credito['enganche'] ?? 0);
    $pagoSemanal = floatval($credito['pago_semanal'] ?? 0);
    $plazoMeses = intval($credito['plazo_meses'] ?? 36);
    $numPagos = round($plazoMeses * 4.33);
    $montoFinanciado = floatval($credito['monto_financiado'] ?? ($montoTotal - $enganche));
}
if (!$montoTotal && $moto['precio_venta']) {
    $montoTotal = floatval($moto['precio_venta']);
}

$folio = 'PAG-' . $motoId . '-' . date('Ymd-His');
$fechaEmision = date('d/m/Y');
$lugarEmision = 'Ciudad de México, CDMX';

// ── 4. Generate PDF ─────────────────────────────────────────────────────
$fpdfPath = __DIR__ . '/../../../configurador_prueba/php/vendor/fpdf/fpdf.php';
if (!file_exists($fpdfPath)) {
    adminJsonOut(['error' => 'FPDF library not found'], 500);
}
require_once $fpdfPath;

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

// Helper functions
$enc = function($s) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string)$s); };
$fmt = function($n) { return '$' . number_format((float)$n, 2) . ' MXN'; };

$pdf = new FPDF();
$pdf->SetAutoPageBreak(true, 20);

// ── Page 1: Pagaré ──────────────────────────────────────────────────────
$pdf->AddPage();

// Header
$pdf->SetFont('Arial', 'B', 18);
$pdf->Cell(0, 12, $enc('PAGARÉ'), 0, 1, 'C');
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(0, 5, $enc('Documento con valor legal — LGTOC Art. 170'), 0, 1, 'C');
$pdf->Ln(2);

// Folio and date bar
$pdf->SetFillColor(240, 240, 240);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(95, 6, $enc('  Folio: ' . $folio), 1, 0, 'L', true);
$pdf->Cell(95, 6, $enc('  Fecha de emisión: ' . $fechaEmision), 1, 1, 'L', true);
$pdf->Ln(3);

// ── Company info (acreedor) ─────────────────────────────────────────────
$w1 = 45; $w2 = 145; $h = 6;

$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 7, $enc('ACREEDOR (BENEFICIARIO):'), 0, 1);

$acreedorRows = [
    ['Denominación', 'MTECH GEARS S.A. DE C.V.'],
    ['RFC', 'MGE230316KA2'],
    ['Domicilio', 'Jaime Balmes 71 Int 101, Despacho C, Col. Polanco, Miguel Hidalgo, CDMX C.P. 11510'],
    ['Correo', 'legal@voltika.mx'],
];
foreach ($acreedorRows as $row) {
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($w1, $h, $enc($row[0] . ':'), 1);
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell($w2, $h, $enc($row[1]), 1, 1);
}

$pdf->Ln(3);

// ── Client info (deudor) ────────────────────────────────────────────────
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 7, $enc('DEUDOR (SUSCRIPTOR):'), 0, 1);

$curp = $cliente['curp'] ?? '';
$domicilio = $cliente['domicilio'] ?? ($trans['domicilio'] ?? '');
$emailCliente = $moto['cliente_email'] ?? ($cliente['email'] ?? '');
$telCliente = $moto['cliente_telefono'] ?? ($cliente['telefono'] ?? '');

$deudorRows = [
    ['Nombre completo', $nombreCompleto ?: 'Por confirmar'],
    ['CURP', $curp ?: 'Por confirmar'],
    ['Domicilio', $domicilio ?: 'Por confirmar'],
    ['Correo electrónico', $emailCliente],
    ['Teléfono', '+52 ' . $telCliente],
];
foreach ($deudorRows as $row) {
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($w1, $h, $enc($row[0] . ':'), 1);
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell($w2, $h, $enc($row[1]), 1, 1);
}

$pdf->Ln(3);

// ── Vehicle info ────────────────────────────────────────────────────────
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 7, $enc('VEHÍCULO RELACIONADO:'), 0, 1);

$vehiculoRows = [
    ['Marca / Modelo', 'VOLTIKA ' . ($moto['modelo'] ?? '')],
    ['Color', $moto['color'] ?? ''],
    ['VIN/NIV', $moto['vin_display'] ?? $moto['vin'] ?? 'Por asignar'],
    ['Año-modelo', '2026'],
];
foreach ($vehiculoRows as $row) {
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($w1, $h, $enc($row[0] . ':'), 1);
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell($w2, $h, $enc($row[1]), 1, 1);
}

$pdf->Ln(3);

// ── Financial details (MONTO) ───────────────────────────────────────────
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 7, $enc('MONTO Y CONDICIONES DE PAGO:'), 0, 1);

$montoRows = [
    ['Precio de contado', $fmt($montoTotal)],
    ['Enganche pagado', $fmt($enganche)],
    ['Monto financiado', $fmt($montoFinanciado)],
    ['Número de pagos', $numPagos . ' semanales'],
    ['Monto por pago', $fmt($pagoSemanal)],
];

$totalAPlazo = $enganche + ($pagoSemanal * $numPagos);
$montoRows[] = ['MONTO TOTAL A PAGAR', $fmt($totalAPlazo)];

foreach ($montoRows as $i => $row) {
    $isLast = ($i === count($montoRows) - 1);
    $pdf->SetFont('Arial', 'B', $isLast ? 9 : 8);
    $pdf->Cell(80, $h, $enc($row[0] . ':'), 1);
    $pdf->SetFont('Arial', $isLast ? 'B' : '', $isLast ? 9 : 8);
    $pdf->Cell(110, $h, $enc($row[1]), 1, 1, 'R');
}

// ── Page 2: Legal text + Signature ──────────────────────────────────────
$pdf->AddPage();

$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 7, $enc('TEXTO LEGAL DEL PAGARÉ:'), 0, 1);
$pdf->Ln(2);

$pdf->SetFont('Arial', '', 8);

$montoLetra = numberToSpanishWords($montoFinanciado ?: $totalAPlazo);
$montoNum = $fmt($montoFinanciado ?: $totalAPlazo);

$legalText = "Debo(emos) y pagaré(mos) incondicionalmente a la orden de MTECH GEARS S.A. DE C.V., "
    . "en {$lugarEmision}, la cantidad de {$montoNum} ({$montoLetra}), "
    . "valor recibido a mi(nuestra) entera satisfacción. "
    . "Este pagaré forma parte de una serie de {$numPagos} pagarés, todos de esta misma fecha, "
    . "y es pagadero en pagos semanales conforme a la tabla de amortización acordada.\n\n"
    . "En caso de falta de pago oportuno, el suscriptor se obliga a pagar intereses moratorios "
    . "a razón del 2% mensual sobre saldos insolutos, sin que por ello se entienda concedida prórroga alguna.\n\n"
    . "El suscriptor renuncia expresamente al beneficio de orden y excusión, "
    . "así como al fuero de su domicilio, sometiéndose a la jurisdicción de los tribunales "
    . "competentes de la Ciudad de México.\n\n"
    . "Este documento ha sido generado de forma electrónica y firmado digitalmente de conformidad "
    . "con lo establecido en la Ley de Firma Electrónica Avanzada (NOM-151-SCFI), "
    . "teniendo plena validez jurídica.\n\n"
    . "Lugar de emisión: {$lugarEmision}\n"
    . "Fecha de emisión: {$fechaEmision}";

$pdf->MultiCell(0, 4.5, $enc($legalText));
$pdf->Ln(4);

// ── Signature area ──────────────────────────────────────────────────────
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 7, $enc('FIRMA DEL SUSCRIPTOR (DEUDOR):'), 0, 1);
$pdf->Ln(2);

if ($firmaImgPath && file_exists($firmaImgPath)) {
    $pdf->Image($firmaImgPath, $pdf->GetX() + 30, $pdf->GetY(), 80, 30);
    $pdf->Ln(32);
} else {
    // Empty signature box
    $pdf->SetDrawColor(180, 180, 180);
    $pdf->Rect($pdf->GetX() + 30, $pdf->GetY(), 120, 30);
    $pdf->Ln(32);
    $pdf->SetDrawColor(0, 0, 0);
}

$pdf->SetFont('Arial', '', 8);
$pdf->Cell(0, 5, $enc('Nombre: ' . ($nombreCompleto ?: '____________________________')), 0, 1, 'C');
$pdf->Cell(0, 5, $enc('Fecha: ' . $fechaEmision), 0, 1, 'C');

$pdf->Ln(5);

// ── Evidence metadata ───────────────────────────────────────────────────
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 6, $enc('EVIDENCIA DE FIRMA ELECTRÓNICA:'), 0, 1);
$pdf->SetFont('Arial', '', 7);
$pdf->SetFillColor(245, 245, 245);

$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'N/A';
if ($ip !== 'N/A') $ip = explode(',', $ip)[0];
$ua = $_SERVER['HTTP_USER_AGENT'] ?? 'N/A';
$otpInfo = $cl['fase4_completada'] ? ('Validado — ' . ($cl['otp_timestamp'] ?? $cl['fase4_fecha'] ?? 'N/A')) : 'Pendiente';

$evidRows = [
    ['IP del dispositivo', $ip],
    ['Dispositivo', substr($ua, 0, 80)],
    ['Fecha y hora', date('Y-m-d H:i:s')],
    ['OTP validación', $otpInfo],
    ['VIN relacionado', $moto['vin_display'] ?? $moto['vin'] ?? 'Por asignar'],
    ['Moto ID', (string)$motoId],
    ['Folio pagaré', $folio],
];
foreach ($evidRows as $row) {
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Cell(40, 5, $enc($row[0] . ':'), 1, 0, 'L', true);
    $pdf->SetFont('Arial', '', 7);
    $pdf->Cell(150, 5, $enc($row[1]), 1, 1, 'L', true);
}

$pdf->Ln(3);

// NOM-151 placeholder
$pdf->SetFont('Arial', 'I', 7);
$pdf->MultiCell(0, 4, $enc('Sellado NOM-151 y hash de integridad serán incorporados al completar la firma digital mediante CINCEL.'));

// ── Legal footer ────────────────────────────────────────────────────────
$pdf->Ln(4);
$pdf->SetFont('Arial', 'B', 8);
$pdf->MultiCell(0, 4, $enc('La presente carátula forma parte integral del contrato de compraventa a plazos, '
    . 'términos y condiciones, pagaré y acta de entrega firmados entre las partes.'));

// ── Output PDF ──────────────────────────────────────────────────────────
$pdf->Output('F', $filepath);

// Clean up temp signature image
if ($firmaImgPath && file_exists($firmaImgPath)) @unlink($firmaImgPath);

// Calculate hash of the generated PDF
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

adminJsonOut([
    'ok' => true,
    'folio' => $folio,
    'pdf_path' => $filename,
    'pdf_hash' => $pdfHash,
    'datos' => [
        'nombre' => $nombreCompleto,
        'monto' => $montoFinanciado ?: $totalAPlazo,
        'monto_fmt' => $fmt($montoFinanciado ?: $totalAPlazo),
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
        if ($n >= 100) {
            $result .= $centenas[(int)($n / 100)] . ' ';
            $n %= 100;
        }
        if ($n >= 10 && $n <= 19) {
            $result .= $especiales[$n - 10];
            return trim($result);
        }
        if ($n >= 20 && $n <= 29) {
            $result .= 'veinti' . $unidades[$n - 20];
            return trim($result);
        }
        if ($n >= 30) {
            $result .= $decenas[(int)($n / 10)];
            $n %= 10;
            if ($n > 0) $result .= ' y ' . $unidades[$n];
            return trim($result);
        }
        if ($n > 0) {
            $result .= $unidades[$n];
        }
        return trim($result);
    };

    if ($entero === 0) {
        $texto = 'cero';
    } else {
        $texto = '';
        if ($entero >= 1000000) {
            $millones = (int)($entero / 1000000);
            $texto .= ($millones === 1 ? 'un millón' : $convertGroup($millones) . ' millones') . ' ';
            $entero %= 1000000;
        }
        if ($entero >= 1000) {
            $miles = (int)($entero / 1000);
            $texto .= ($miles === 1 ? 'mil' : $convertGroup($miles) . ' mil') . ' ';
            $entero %= 1000;
        }
        if ($entero > 0) {
            $texto .= $convertGroup($entero);
        }
    }

    $texto = trim($texto) . ' pesos';
    if ($centavos > 0) {
        $texto .= ' ' . str_pad((string)$centavos, 2, '0') . '/100 M.N.';
    } else {
        $texto .= ' 00/100 M.N.';
    }

    return mb_strtoupper($texto);
}
