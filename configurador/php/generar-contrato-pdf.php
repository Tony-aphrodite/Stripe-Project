<?php
/**
 * Voltika - Generar Contrato PDF + Cincel NOM-151 Timestamp
 *
 * Flow:
 * 1. Receive contract data + signature (base64 PNG) from frontend
 * 2. Generate PDF with FPDF (contract cover + terms + signature)
 * 3. Compute SHA-256 hash of the PDF
 * 4. Get JWT from Cincel API
 * 5. Upload PDF to Cincel as a document
 * 6. Request NOM-151 timestamp via hash
 * 7. Return result to frontend
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

// ── Read request ──────────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);

$nombre     = isset($input['nombre'])     ? trim($input['nombre'])     : '';
$email      = isset($input['email'])      ? trim($input['email'])      : '';
$telefono   = isset($input['telefono'])   ? trim($input['telefono'])   : '';
$modelo     = isset($input['modelo'])     ? trim($input['modelo'])     : '';
$color      = isset($input['color'])      ? trim($input['color'])      : '';
$metodoPago = isset($input['metodoPago']) ? trim($input['metodoPago']) : 'credito';
$ciudad     = isset($input['ciudad'])     ? trim($input['ciudad'])     : '';
$estado     = isset($input['estado'])     ? trim($input['estado'])     : '';
$cp         = isset($input['cp'])         ? trim($input['cp'])         : '';
$firma      = isset($input['firmaData'])  ? $input['firmaData']        : null;
$credito    = isset($input['credito'])    ? $input['credito']          : [];

// ── Cincel config ─────────────────────────────────────────────────────────
$cincelApiUrl   = defined('CINCEL_API_URL')  ? CINCEL_API_URL  : 'https://sandbox.api.cincel.digital/v3';
$cincelEmail    = defined('CINCEL_EMAIL')    ? CINCEL_EMAIL    : 'test@riactor.com';
$cincelPassword = defined('CINCEL_PASSWORD') ? CINCEL_PASSWORD : 'Prueba2026_';

// ── Step 1: Generate contract PDF ─────────────────────────────────────────
$pdfPath = null;
try {
    $pdfPath = generateContractPDF($nombre, $email, $telefono, $modelo, $color,
                                    $ciudad, $estado, $cp, $credito, $firma);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error generando PDF: ' . $e->getMessage()]);
    exit;
}

// ── Step 2: Send to Cincel for NOM-151 timestamp ──────────────────────────
$cincelResult = null;
if ($cincelEmail && $cincelPassword) {
    try {
        $cincelResult = sendToCincel($cincelApiUrl, $cincelEmail, $cincelPassword, $pdfPath, $nombre, $email);
    } catch (Exception $e) {
        error_log('Cincel error: ' . $e->getMessage());
        $cincelResult = ['status' => 'error', 'error' => $e->getMessage()];
    }
} else {
    $cincelResult = ['status' => 'skipped', 'reason' => 'Cincel credentials not configured'];
}

// ── Response ──────────────────────────────────────────────────────────────
echo json_encode([
    'ok'        => true,
    'pdf'       => $pdfPath ? basename($pdfPath) : null,
    'cincel'    => $cincelResult,
    'timestamp' => date('c')
]);


// ==========================================================================
// HELPER FUNCTIONS
// ==========================================================================

/**
 * Generate contract PDF using FPDF
 */
function generateContractPDF($nombre, $email, $telefono, $modelo, $color,
                              $ciudad, $estado, $cp, $credito, $firmaBase64) {

    $uploadDir = __DIR__ . '/contratos/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filename = 'contrato_' . preg_replace('/[^a-zA-Z0-9]/', '_', $nombre) . '_' . date('Ymd_His') . '.pdf';
    $filepath = $uploadDir . $filename;

    // Extract credit details
    $precioContado   = isset($credito['precioContado'])   ? $credito['precioContado']   : 0;
    $enganche        = isset($credito['enganche'])        ? $credito['enganche']        : 0;
    $enganchePct     = isset($credito['enganchePct'])     ? $credito['enganchePct']     : 0.25;
    $plazoMeses      = isset($credito['plazoMeses'])      ? $credito['plazoMeses']      : 36;
    $pagoSemanal     = isset($credito['pagoSemanal'])     ? $credito['pagoSemanal']     : 0;
    $montoFinanciado = isset($credito['montoFinanciado']) ? $credito['montoFinanciado'] : 0;
    $plazoSemanas    = $plazoMeses * 4.33;

    // Save signature image
    $firmaImgPath = null;
    if ($firmaBase64 && strpos($firmaBase64, 'data:image/png;base64,') === 0) {
        $firmaData = base64_decode(str_replace('data:image/png;base64,', '', $firmaBase64));
        $firmaImgPath = $uploadDir . 'firma_' . date('Ymd_His') . '.png';
        file_put_contents($firmaImgPath, $firmaData);
    }

    // Check if FPDF is available
    $fpdfPath = __DIR__ . '/vendor/fpdf/fpdf.php';
    if (!file_exists($fpdfPath)) {
        // Try composer autoload
        $fpdfPath = __DIR__ . '/vendor/setasign/fpdf/fpdf.php';
    }

    if (file_exists($fpdfPath)) {
        require_once $fpdfPath;
        return generateWithFPDF($filepath, $nombre, $email, $telefono, $modelo, $color,
                                 $ciudad, $estado, $cp, $precioContado, $enganche, $enganchePct,
                                 $plazoMeses, $plazoSemanas, $pagoSemanal, $montoFinanciado,
                                 $firmaImgPath);
    }

    // Fallback: generate minimal text PDF
    return generateMinimalPDF($filepath, $nombre, $email, $telefono, $modelo, $color,
                               $ciudad, $estado, $cp, $precioContado, $enganche, $enganchePct,
                               $plazoMeses, $pagoSemanal, $montoFinanciado, $firmaBase64);
}

/**
 * Generate PDF with FPDF library
 */
function generateWithFPDF($filepath, $nombre, $email, $telefono, $modelo, $color,
                           $ciudad, $estado, $cp, $precioContado, $enganche, $enganchePct,
                           $plazoMeses, $plazoSemanas, $pagoSemanal, $montoFinanciado,
                           $firmaImgPath) {

    $pdf = new FPDF();
    $fecha = date('d/m/Y');

    // ── Page 1: Carátula ──────────────────────────────────────────────────
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 18);
    $pdf->Cell(0, 15, iconv('UTF-8', 'ISO-8859-1', 'CARÁTULA DE CRÉDITO'), 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'VOLTIKA S.A. DE C.V.', 0, 1, 'C');
    $pdf->Ln(10);

    // Contract data table
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(60, 8, 'Acreditado:', 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, iconv('UTF-8', 'ISO-8859-1', $nombre), 1, 1);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(60, 8, 'Email:', 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, $email, 1, 1);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(60, 8, iconv('UTF-8', 'ISO-8859-1', 'Teléfono:'), 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, '+52 ' . $telefono, 1, 1);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(60, 8, 'Modelo:', 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, 'Voltika ' . $modelo, 1, 1);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(60, 8, 'Color:', 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, iconv('UTF-8', 'ISO-8859-1', $color), 1, 1);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(60, 8, 'Ciudad / Estado:', 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, iconv('UTF-8', 'ISO-8859-1', $ciudad . ', ' . $estado), 1, 1);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(60, 8, iconv('UTF-8', 'ISO-8859-1', 'Código Postal:'), 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, $cp, 1, 1);

    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, iconv('UTF-8', 'ISO-8859-1', 'CONDICIONES DEL CRÉDITO'), 0, 1, 'C');
    $pdf->Ln(3);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(60, 8, 'Precio contado:', 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, '$' . number_format($precioContado, 2) . ' MXN', 1, 1);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(60, 8, 'Enganche:', 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, '$' . number_format($enganche, 2) . ' MXN (' . round($enganchePct * 100) . '%)', 1, 1);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(60, 8, 'Monto financiado:', 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, '$' . number_format($montoFinanciado, 2) . ' MXN', 1, 1);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(60, 8, 'Plazo:', 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, $plazoMeses . ' meses (' . round($plazoSemanas) . ' pagos semanales)', 1, 1);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(60, 8, 'Pago semanal:', 1);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, '$' . number_format($pagoSemanal, 2) . ' MXN', 1, 1);

    $pdf->Ln(5);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 6, 'Fecha: ' . $fecha, 0, 1);

    // Signature area
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, iconv('UTF-8', 'ISO-8859-1', 'FIRMA DEL ACREDITADO:'), 0, 1);

    if ($firmaImgPath && file_exists($firmaImgPath)) {
        $pdf->Image($firmaImgPath, $pdf->GetX() + 10, $pdf->GetY(), 60, 30);
        $pdf->Ln(35);
    } else {
        $pdf->Ln(5);
        $pdf->Cell(80, 0.3, '', 1, 1); // signature line
        $pdf->Ln(3);
    }

    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, iconv('UTF-8', 'ISO-8859-1', 'Nombre y firma: ' . $nombre), 0, 1);

    // ── Page 2+: Contract terms ───────────────────────────────────────────
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, iconv('UTF-8', 'ISO-8859-1', 'CONTRATO DE CRÉDITO SIMPLE'), 0, 1, 'C');
    $pdf->Ln(5);

    $clauses = [
        'PRIMERA. OBJETO' => 'El Acreditante otorga al Acreditado una linea de credito para la adquisicion del vehiculo electrico descrito en la caratula de este contrato.',
        'SEGUNDA. DISPOSICION' => 'El Acreditado dispone del credito al momento de recibir el vehiculo, quedando obligado al pago del monto financiado mas los intereses correspondientes.',
        'TERCERA. PLAZO' => 'El plazo del credito sera el estipulado en la caratula. El Acreditado realizara pagos semanales mediante domiciliacion bancaria o el metodo acordado.',
        'CUARTA. TASA DE INTERES' => 'La tasa de interes ordinaria sera calculada sobre saldos insolutos. El Costo Anual Total (CAT) sera informado al Acreditado antes de la firma.',
        'QUINTA. PAGOS' => 'Los pagos semanales incluyen capital e intereses. El Acreditado puede realizar pagos anticipados sin penalizacion alguna.',
        'SEXTA. GARANTIA' => 'El vehiculo adquirido servira como garantia prendaria del presente credito hasta la liquidacion total del adeudo.',
        'SEPTIMA. MORA' => 'En caso de incumplimiento, se aplicara una tasa moratoria sobre el saldo vencido. El Acreditante podra iniciar el proceso de recuperacion del vehiculo.',
        'OCTAVA. SEGUROS' => 'El Acreditado se compromete a mantener el vehiculo asegurado durante la vigencia del credito.',
        'NOVENA. JURISDICCION' => 'Para la interpretacion y cumplimiento del presente contrato, las partes se someten a la jurisdiccion de los tribunales de la Ciudad de Mexico.',
    ];

    $pdf->SetFont('Arial', '', 10);
    foreach ($clauses as $title => $text) {
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 7, iconv('UTF-8', 'ISO-8859-1', $title . '.'), 0, 1);
        $pdf->SetFont('Arial', '', 10);
        $pdf->MultiCell(0, 6, iconv('UTF-8', 'ISO-8859-1', $text));
        $pdf->Ln(3);
    }

    // Footer signature
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(90, 8, '________________________', 0, 0, 'C');
    $pdf->Cell(90, 8, '________________________', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(90, 6, 'EL ACREDITANTE', 0, 0, 'C');
    $pdf->Cell(90, 6, 'EL ACREDITADO', 0, 1, 'C');
    $pdf->Cell(90, 6, 'VOLTIKA S.A. DE C.V.', 0, 0, 'C');
    $pdf->Cell(90, 6, iconv('UTF-8', 'ISO-8859-1', $nombre), 0, 1, 'C');

    $pdf->Output('F', $filepath);

    // Clean up temp signature file
    if ($firmaImgPath && file_exists($firmaImgPath)) {
        unlink($firmaImgPath);
    }

    return $filepath;
}

/**
 * Fallback: minimal text PDF (no external library needed)
 */
function generateMinimalPDF($filepath, $nombre, $email, $telefono, $modelo, $color,
                             $ciudad, $estado, $cp, $precioContado, $enganche, $enganchePct,
                             $plazoMeses, $pagoSemanal, $montoFinanciado, $firmaBase64) {

    $fecha = date('d/m/Y H:i:s');

    $content  = "CONTRATO DE CREDITO SIMPLE - VOLTIKA S.A. DE C.V.\n";
    $content .= "Fecha: {$fecha}\n\n";
    $content .= "ACREDITADO: {$nombre}\n";
    $content .= "Email: {$email} | Tel: +52 {$telefono}\n";
    $content .= "Ciudad: {$ciudad}, {$estado} (CP {$cp})\n\n";
    $content .= "VEHICULO: Voltika {$modelo} - Color: {$color}\n";
    $content .= "Precio contado: \$" . number_format($precioContado, 2) . " MXN\n";
    $content .= "Enganche: \$" . number_format($enganche, 2) . " MXN (" . round($enganchePct * 100) . "%)\n";
    $content .= "Monto financiado: \$" . number_format($montoFinanciado, 2) . " MXN\n";
    $content .= "Plazo: {$plazoMeses} meses | Pago semanal: \${$pagoSemanal} MXN\n\n";
    $content .= "FIRMA ELECTRONICA: " . ($firmaBase64 ? "[CAPTURADA]" : "[PENDIENTE]") . "\n";
    $content .= "Fecha firma: {$fecha}\n";

    // Minimal valid PDF
    $lines = explode("\n", $content);
    $y = 742;
    $pdfContent = "BT /F1 10 Tf\n";
    foreach ($lines as $line) {
        $escapedLine = str_replace(['(', ')', '\\'], ['\\(', '\\)', '\\\\'], $line);
        $pdfContent .= "50 {$y} Td ({$escapedLine}) Tj\n";
        $y -= 14;
        if ($y < 50) break;
    }
    $pdfContent .= "ET";
    $pdfContentLen = strlen($pdfContent);

    $pdf  = "%PDF-1.4\n";
    $off1 = strlen($pdf);
    $pdf .= "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n";
    $off2 = strlen($pdf);
    $pdf .= "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n";
    $off3 = strlen($pdf);
    $pdf .= "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n";
    $off4 = strlen($pdf);
    $pdf .= "4 0 obj<</Length {$pdfContentLen}>>stream\n{$pdfContent}\nendstream\nendobj\n";
    $off5 = strlen($pdf);
    $pdf .= "5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Courier>>endobj\n";
    $xrefOff = strlen($pdf);
    $pdf .= "xref\n0 6\n";
    $pdf .= "0000000000 65535 f \n";
    $pdf .= sprintf("%010d 00000 n \n", $off1);
    $pdf .= sprintf("%010d 00000 n \n", $off2);
    $pdf .= sprintf("%010d 00000 n \n", $off3);
    $pdf .= sprintf("%010d 00000 n \n", $off4);
    $pdf .= sprintf("%010d 00000 n \n", $off5);
    $pdf .= "trailer<</Size 6/Root 1 0 R>>\n";
    $pdf .= "startxref\n{$xrefOff}\n%%EOF";

    file_put_contents($filepath, $pdf);
    return $filepath;
}

/**
 * Send PDF to Cincel API for NOM-151 timestamp
 * Uses Basic Auth directly (no JWT needed for timestamps)
 */
function sendToCincel($apiUrl, $email, $password, $pdfPath, $signerName, $signerEmail) {

    $authHeader = 'Authorization: Basic ' . base64_encode($email . ':' . $password);

    // ── Step 1: Compute SHA-256 hash of PDF ───────────────────────────────
    if (!file_exists($pdfPath) || filesize($pdfPath) === 0) {
        throw new Exception('PDF file not found or empty: ' . $pdfPath);
    }
    $pdfContent = file_get_contents($pdfPath);
    if ($pdfContent === false || strlen($pdfContent) === 0) {
        throw new Exception('Failed to read PDF file: ' . $pdfPath);
    }
    $hash = hash('sha256', $pdfContent);
    error_log("Cincel: PDF size=" . strlen($pdfContent) . " hash={$hash} path={$pdfPath}");

    // ── Step 2: Request NOM-151 timestamp via hash ────────────────────────
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiUrl . '/timestamps/' . $hash,
        CURLOPT_HTTPGET        => true,
        CURLOPT_HTTPHEADER     => [$authHeader],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30
    ]);

    $tsResponse = curl_exec($ch);
    $tsCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError  = curl_error($ch);
    curl_close($ch);

    // Log for debugging
    error_log("Cincel timestamp request: hash={$hash} code={$tsCode} response={$tsResponse} curl_error={$curlError}");

    if ($tsCode === 402) {
        throw new Exception('Cincel: no timestamping credits available');
    }

    // If Basic Auth fails on individual hash, try without auth (sandbox may allow it)
    if ($tsCode === 401) {
        $ch2 = curl_init();
        curl_setopt_array($ch2, [
            CURLOPT_URL            => $apiUrl . '/timestamps/' . $hash,
            CURLOPT_HTTPGET        => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30
        ]);
        $tsResponse = curl_exec($ch2);
        $tsCode     = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);

        error_log("Cincel timestamp retry (no auth): code={$tsCode} response={$tsResponse}");
    }

    if ($tsCode !== 200 && $tsCode !== 202) {
        // Still save the hash for manual timestamp later
        return [
            'status'        => 'pending',
            'hash'          => $hash,
            'asn1Ready'     => false,
            'message'       => "PDF generated. Timestamp pending (HTTP {$tsCode}).",
            'timestampCode' => $tsCode
        ];
    }

    // ── Step 3: Try to download .asn1 certificate ─────────────────────────
    $asn1Path = null;
    $asn1Url  = $apiUrl . '/timestamps/' . $hash . '.asn1';

    $ch3 = curl_init();
    curl_setopt_array($ch3, [
        CURLOPT_URL            => $asn1Url,
        CURLOPT_HTTPGET        => true,
        CURLOPT_HTTPHEADER     => [$authHeader],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15
    ]);

    $asn1Response = curl_exec($ch3);
    $asn1Code     = curl_getinfo($ch3, CURLINFO_HTTP_CODE);
    curl_close($ch3);

    // If auth fails on .asn1, try without
    if ($asn1Code === 401) {
        $ch4 = curl_init();
        curl_setopt_array($ch4, [
            CURLOPT_URL            => $asn1Url,
            CURLOPT_HTTPGET        => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15
        ]);
        $asn1Response = curl_exec($ch4);
        $asn1Code     = curl_getinfo($ch4, CURLINFO_HTTP_CODE);
        curl_close($ch4);
    }

    if ($asn1Code === 200 && !empty($asn1Response)) {
        $asn1Dir = __DIR__ . '/contratos/';
        $asn1Path = $asn1Dir . $hash . '.asn1';
        file_put_contents($asn1Path, $asn1Response);
    }

    return [
        'status'        => ($asn1Code === 200) ? 'timestamped' : 'processing',
        'hash'          => $hash,
        'asn1Ready'     => ($asn1Code === 200),
        'asn1File'      => $asn1Path ? basename($asn1Path) : null,
        'timestampCode' => $tsCode,
        'message'       => ($asn1Code === 202)
            ? 'NOM-151 timestamp is being generated. Certificate will be ready in ~60 seconds.'
            : (($asn1Code === 200) ? 'NOM-151 timestamp created successfully.' : 'Timestamp requested.')
    ];
}
