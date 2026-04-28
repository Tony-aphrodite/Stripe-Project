<?php
/**
 * Voltika - Generar Contrato PDF (Carátula de Compraventa a Plazos) + Cincel NOM-151
 *
 * Template: "Carátula de compraventa a plazos VF April 8" from legal team
 * Autofill fields from configurador state + Truora INE + Stripe
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

// Additional fields from Truora/credit form
$curp       = isset($input['curp'])       ? trim($input['curp'])       : '';
$domicilio  = isset($input['domicilio'])  ? trim($input['domicilio'])  : '';
$customerId = isset($input['customerId']) ? trim($input['customerId']) : '';

// ── Cincel config ─────────────────────────────────────────────────────────
$cincelApiUrl   = defined('CINCEL_API_URL')  ? CINCEL_API_URL  : 'https://api.cincel.digital/v3';
$cincelEmail    = defined('CINCEL_EMAIL')    ? CINCEL_EMAIL    : 'test@riactor.com';
$cincelPassword = defined('CINCEL_PASSWORD') ? CINCEL_PASSWORD : 'Prueba2026_';

// ── Step 1: Generate contract PDF ─────────────────────────────────────────
$pdfPath = null;
try {
    $pdfPath = generateContractPDF($nombre, $email, $telefono, $modelo, $color,
                                    $ciudad, $estado, $cp, $credito, $firma,
                                    $curp, $domicilio, $customerId);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error generando PDF: ' . $e->getMessage()]);
    exit;
}

// ── Step 1b: Audit-trail row for the captured signature ──────────────────
// Persists IP, user-agent, timestamp and a SHA-256 of the signature image so
// we can prove later that this exact signature came from this exact session.
// Required for legal disputes and the CDC NIP-CIEC compliance file.
$firmaAuditId = saveFirmaAudit([
    'nombre'      => $nombre,
    'email'       => $email,
    'telefono'    => $telefono,
    'curp'        => $curp,
    'modelo'      => $modelo,
    'pdf_file'    => $pdfPath ? basename($pdfPath) : null,
    'firma_base64'=> $firma,
    'customer_id' => $customerId,
]);

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

// ── Step 3: Contract email — DISABLED at purchase time per client request ──
// Contract confirmation email will be sent AFTER motorcycle delivery (Stage 2)
// Only purchase order confirmation is sent at this stage
$emailSent = false;
// if ($email && $pdfPath && file_exists($pdfPath)) {
//     try {
//         $emailSent = sendContractEmail($email, $nombre, $modelo, $pdfPath);
//     } catch (Exception $e) {
//         error_log('Contract email error: ' . $e->getMessage());
//     }
// }

// ── Response ──────────────────────────────────────────────────────────────
echo json_encode([
    'ok'             => true,
    'pdf'            => $pdfPath ? basename($pdfPath) : null,
    'cincel'         => $cincelResult,
    'emailSent'      => $emailSent,
    'firma_audit_id' => $firmaAuditId,
    'timestamp'      => date('c')
]);

/**
 * Save a row in `firmas_contratos` so we have a tamper-evident record of who
 * signed, from where, and what they signed. The signature image itself is
 * stored as the original base64 string plus a SHA-256 hash for integrity.
 *
 * Returns the new row id, or null on failure.
 */
function saveFirmaAudit(array $data): ?int {
    if (empty($data['firma_base64'])) return null;
    try {
        $pdo = getDB();
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
        )");

        $hash = hash('sha256', (string)$data['firma_base64']);
        $ip   = $_SERVER['HTTP_X_FORWARDED_FOR']
              ?? $_SERVER['REMOTE_ADDR']
              ?? null;
        $ua   = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if ($ip) $ip = substr(explode(',', $ip)[0], 0, 64);
        if ($ua) $ua = substr($ua, 0, 500);

        $stmt = $pdo->prepare("
            INSERT INTO firmas_contratos
                (nombre, email, telefono, curp, modelo, pdf_file, customer_id,
                 firma_base64, firma_sha256, ip, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['nombre']      ?? null,
            $data['email']       ?? null,
            $data['telefono']    ?? null,
            $data['curp']        ?? null,
            $data['modelo']      ?? null,
            $data['pdf_file']    ?? null,
            $data['customer_id'] ?? null,
            $data['firma_base64'],
            $hash,
            $ip,
            $ua,
        ]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log('Voltika firmas_contratos error: ' . $e->getMessage());
        return null;
    }
}


// ==========================================================================
// HELPER FUNCTIONS
// ==========================================================================

function generateContractPDF($nombre, $email, $telefono, $modelo, $color,
                              $ciudad, $estado, $cp, $credito, $firmaBase64,
                              $curp, $domicilio, $customerId) {

    $uploadDir = __DIR__ . '/contratos/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0777, true)) {
            $uploadDir = sys_get_temp_dir() . '/voltika_contratos/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        }
    }

    $filename = 'contrato_' . preg_replace('/[^a-zA-Z0-9]/', '_', $nombre) . '_' . date('Ymd_His') . '.pdf';
    $filepath = $uploadDir . $filename;

    // Extract credit details
    $precioContado   = isset($credito['precioContado'])   ? floatval($credito['precioContado'])   : 0;
    $enganche        = isset($credito['enganche'])        ? floatval($credito['enganche'])        : 0;
    $plazoMeses      = isset($credito['plazoMeses'])      ? intval($credito['plazoMeses'])        : 36;
    $pagoSemanal     = isset($credito['pagoSemanal'])     ? floatval($credito['pagoSemanal'])     : 0;
    $montoFinanciado = isset($credito['montoFinanciado']) ? floatval($credito['montoFinanciado']) : 0;
    $numPagos        = round($plazoMeses * 4.33);

    // Calculated fields
    $precioSinIVA    = round($precioContado / 1.16, 2);
    $ivaVehiculo     = round($precioContado - $precioSinIVA, 2);
    $totalIntereses  = round(($pagoSemanal * $numPagos) - $montoFinanciado, 2);
    $montoTotalPagar = round($enganche + ($pagoSemanal * $numPagos), 2);

    // Folio
    $folio = $customerId ?: ('VK-' . date('Ymd') . '-' . substr(md5($nombre . $email), 0, 6));
    $fechaFirma = date('d/m/Y');

    // Domicilio fallback
    if (empty($domicilio)) {
        $domicilio = $ciudad . ', ' . $estado . ' C.P. ' . $cp;
    }

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
        $fpdfPath = __DIR__ . '/vendor/setasign/fpdf/fpdf.php';
    }
    if (!file_exists($fpdfPath)) {
        // Try autoload
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (file_exists($autoload)) require_once $autoload;
        if (class_exists('FPDF')) {
            $fpdfPath = true; // already loaded
        }
    }

    if ($fpdfPath && (class_exists('FPDF') || (is_string($fpdfPath) && file_exists($fpdfPath)))) {
        if (is_string($fpdfPath)) require_once $fpdfPath;
        return generateCaratulaPDF($filepath, $nombre, $email, $telefono, $modelo, $color,
                                     $ciudad, $estado, $cp, $precioContado, $precioSinIVA,
                                     $ivaVehiculo, $enganche, $montoFinanciado, $numPagos,
                                     $pagoSemanal, $totalIntereses, $montoTotalPagar,
                                     $folio, $fechaFirma, $curp, $domicilio, $firmaImgPath);
    }

    // Fallback: minimal text PDF
    return generateMinimalPDF($filepath, $nombre, $email, $telefono, $modelo, $color,
                               $ciudad, $estado, $cp, $precioContado, $enganche,
                               $montoFinanciado, $numPagos, $pagoSemanal,
                               $totalIntereses, $montoTotalPagar, $folio, $fechaFirma,
                               $curp, $domicilio, $firmaBase64);
}

/**
 * Generate Carátula de Compraventa a Plazos PDF with FPDF
 */
function generateCaratulaPDF($filepath, $nombre, $email, $telefono, $modelo, $color,
                               $ciudad, $estado, $cp, $precioContado, $precioSinIVA,
                               $ivaVehiculo, $enganche, $montoFinanciado, $numPagos,
                               $pagoSemanal, $totalIntereses, $montoTotalPagar,
                               $folio, $fechaFirma, $curp, $domicilio, $firmaImgPath) {

    $pdf = new FPDF();
    $pdf->SetAutoPageBreak(true, 20);

    // Helper: safe encoding
    $enc = function($s) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s); };
    $fmt = function($n) { return '$' . number_format($n, 2) . ' MXN'; };

    // ── Page 1: Carátula ──────────────────────────────────────────────────
    $pdf->AddPage();

    // Title + subtitle
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, $enc('CARÁTULA DE COMPRAVENTA A PLAZOS'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 5, $enc('Resumen de condiciones de la operación comercial'), 0, 1, 'C');
    $pdf->Ln(3);

    // Company info table
    $w1 = 45; $w2 = 145;
    $h = 6;

    $companyRows = [
        ['Denominación', 'MTECH GEARS S.A. DE C.V.'],
        ['RFC', 'MGE230316KA2'],
        ['Domicilio', 'Jaime Balmes 71 Int 101, Despacho C, Colonia Polanco, Miguel Hidalgo, Ciudad de México, CDMX C.P. 11510, México'],
        ['Teléfonos', '(55) 55579619 y WhatsApp +52 (55) 79440982'],
        ['Correo electrónico', 'legal@voltika.mx'],
        ['Folio de contrato', $folio],
        ['Fecha', $fechaFirma],
        ['Localidad', 'Ciudad de México, CDMX'],
    ];
    foreach ($companyRows as $row) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell($w1, $h, $enc($row[0] . ':'), 1);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell($w2, $h, $enc($row[1]), 1, 1);
    }

    $pdf->Ln(4);

    // DATOS DEL CLIENTE
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, $enc('DATOS DEL CLIENTE CONSUMIDOR:'), 0, 1);
    $pdf->SetFont('Arial', '', 8);

    $clientRows = [
        ['Nombre', $nombre],
        ['Domicilio', $domicilio],
        ['CURP', $curp ?: 'Por confirmar'],
        ['Correo electrónico', $email],
        ['Teléfono (validado mediante OTP)', '+52 ' . $telefono],
    ];
    foreach ($clientRows as $row) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell($w1, $h, $enc($row[0] . ':'), 1);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell($w2, $h, $enc($row[1]), 1, 1);
    }

    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->MultiCell(0, 4, $enc('El número telefónico señalado será el medio de identificación de EL CLIENTE para efectos de validación, autorización y entrega.'));
    $pdf->Ln(1);
    $pdf->MultiCell(0, 4, $enc('EL CLIENTE reconoce que es responsable del uso y resguardo de su número telefónico, por lo que VOLTIKA no será responsable por validaciones realizadas mediante dicho número cuando deriven de uso indebido, negligencia o acceso por terceros no autorizados.'));
    $pdf->Ln(2);

    // CARACTERISTICAS DE LA MOTOCICLETA
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, $enc('CARACTERÍSTICAS DE LA MOTOCICLETA:'), 0, 1);

    $motoRows = [
        ['Marca', 'VOLTIKA'],
        ['Submarca', 'TROMOX'],
        ['Tipo o versión', $modelo],
        ['Color', $color],
        ['Año-modelo', '2026'],
    ];
    foreach ($motoRows as $row) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell($w1, $h, $enc($row[0] . ':'), 1);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell($w2, $h, $enc($row[1]), 1, 1);
    }

    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->MultiCell(0, 4, $enc('El número de serie (VIN/NIV) será asignado y confirmado en el acta de entrega correspondiente, quedando vinculado al presente contrato.'));
    $pdf->Ln(1);
    $pdf->MultiCell(0, 4, $enc('EL CLIENTE acepta que la motocicleta entregada conforme al modelo, versión y características descritas en el presente documento se considerará plenamente vinculada a este contrato, independientemente de la asignación posterior del número de serie.'));

    // DETALLE DEL VEHICULO Y PRECIO
    $pdf->Ln(3);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, $enc('DETALLE DEL VEHÍCULO Y PRECIO:'), 0, 1);

    $precioRows = [
        ['Precio del vehículo (Sin IVA)', $fmt($precioSinIVA)],
        ['IVA del vehículo (16%)', $fmt($ivaVehiculo)],
        ['Precio de Contado', $fmt($precioContado)],
    ];
    foreach ($precioRows as $row) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(80, $h, $enc($row[0] . ':'), 1);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(110, $h, $enc($row[1]), 1, 1, 'R');
    }
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->Cell(0, 5, $enc('El precio incluye costos logísticos, traslado y entrega'), 0, 1);

    // TOTAL DEL VEHICULO Y ACCESORIOS
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, $enc('TOTAL DEL VEHÍCULO Y ACCESORIOS (SI APLICA):'), 0, 1);

    $totalRows = [
        ['Total del vehículo', $fmt($precioContado)],
        ['Total de accesorios', '$0.00'],
        ['Total del vehículo y accesorios', $fmt($precioContado)],
    ];
    foreach ($totalRows as $row) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(80, $h, $enc($row[0] . ':'), 1);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(110, $h, $enc($row[1]), 1, 1, 'R');
    }

    // EQUIPO Y ACCESORIOS ADICIONALES
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, $enc('EQUIPO Y ACCESORIOS ADICIONALES (SI APLICA):'), 0, 1);

    $accRows = [
        ['Descripción', 'No aplica'],
        ['Subtotal Sin IVA', '$0.00'],
        ['IVA (16%)', '$0.00'],
        ['Total equipo y accesorios', '$0.00'],
    ];
    foreach ($accRows as $row) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(80, $h, $enc($row[0] . ':'), 1);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(110, $h, $enc($row[1]), 1, 1, 'R');
    }

    // ACTIVACION DE PAGOS
    $pdf->Ln(3);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, $enc('ACTIVACIÓN DE PAGOS:'), 0, 1);
    $pdf->SetFont('Arial', '', 7);
    $pdf->MultiCell(0, 4, $enc('Fecha Estimada primer pago: Conforme a la fecha de entrega del vehículo y al plan de pagos aceptados.'));
    $pdf->Ln(1);
    $pdf->MultiCell(0, 4, $enc('La obligación de pago se activará a partir de la entrega del vehículo conforme al presente contrato y a la tabla de pagos aplicable.'));

    // ── Page 2: Condiciones + Legal ───────────────────────────────────────
    $pdf->AddPage();

    // CONDICIONES DE COMPRA A PLAZOS
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, $enc('CONDICIONES DE COMPRA A PLAZOS:'), 0, 1);

    $condRows = [
        ['Precio de Contado', $fmt($precioContado)],
        ['Enganche', $fmt($enganche)],
        ['Saldo pendiente de pago', $fmt($montoFinanciado)],
        ['Número total de Pagos', $numPagos],
        ['Periodicidad', 'Semanal'],
        ['Monto por pago semanal', $fmt($pagoSemanal)],
        ['Precio total a plazo', $fmt($montoTotalPagar)],
        ['Diferencia entre precio de contado y precio total a plazo', $fmt($totalIntereses)],
    ];
    foreach ($condRows as $i => $row) {
        $isLast = ($i === count($condRows) - 1);
        $pdf->SetFont('Arial', 'B', $isLast ? 9 : 8);
        $pdf->Cell(80, $h, $enc($row[0] . ':'), 1);
        $pdf->SetFont('Arial', $isLast ? 'B' : '', $isLast ? 9 : 8);
        $pdf->Cell(110, $h, $enc(is_numeric($row[1]) ? strval($row[1]) : $row[1]), 1, 1, 'R');
    }

    $pdf->Ln(3);
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->MultiCell(0, 4, $enc('El precio total a plazo es mayor al precio de contado debido a la facilidad de pago en parcialidades.'));
    $pdf->Ln(1);
    $pdf->MultiCell(0, 4, $enc('EL CLIENTE manifiesta que previamente a la contratación le fue informado de manera clara, veraz y comprensible el precio de contado, el precio total a plazo, el monto del enganche, el número y periodicidad de los pagos, el importe de cada pago y el monto total a pagar, aceptando dichas condiciones de manera libre y voluntaria.'));

    // LEGAL SECTIONS
    $pdf->Ln(4);

    // VALIDACION ELECTRONICA
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 6, $enc('VALIDACIÓN ELECTRÓNICA, PAGO Y ENTREGA:'), 0, 1);
    $pdf->SetFont('Arial', '', 7);

    $validacionParagraphs = [
        'EL CLIENTE reconoce que su identidad será validada mediante mecanismos electrónicos, incluyendo códigos de seguridad (OTP) enviados a su número telefónico registrado.',
        'Asimismo, acepta que la confirmación de sus datos personales y de la compra, incluyendo apellido y modelo adquirido, junto con la validación del código OTP, constituirá: (i) confirmación de identidad, (ii) manifestación expresa de voluntad, (iii) autorización para la entrega de la motocicleta, y (iv) aceptación plena de la recepción del producto.',
        'La entrega se considerará realizada en el momento en que el sistema registre dicha validación electrónica, constituyendo cumplimiento de la obligación de entrega por parte de VOLTIKA.',
        'EL CLIENTE reconoce que dicha validación tendrá efectos legales equivalentes a una firma autógrafa conforme al Código de Comercio y podrá ser utilizada como prueba en procesos de aclaración, contracargos o disputas ante instituciones financieras o emisores de tarjetas.',
        'EL CLIENTE acepta que no procederá reclamación por falta de entrega cuando exista evidencia de validación electrónica conforme al presente documento, incluyendo procesos de aclaración, contracargos o disputas ante instituciones financieras o emisores de tarjetas.',
        'EL CLIENTE reconoce que VOLTIKA podrá generar y conservar evidencia digital de la operación y entrega, incluyendo registros electrónicos, direcciones IP, fecha, hora, validaciones OTP, geolocalización y evidencia fotográfica, los cuales constituirán prueba plena en cualquier procedimiento legal, administrativo o ante instituciones financieras.',
        'La entrega podrá realizarse en puntos aliados, talleres o ubicaciones designadas, los cuales actúan únicamente como facilitadores logísticos, sin facultades para validar identidad o autorizar la entrega.',
        'La validación electrónica será el único mecanismo válido para la liberación del vehículo.',
    ];
    foreach ($validacionParagraphs as $p) {
        $pdf->MultiCell(0, 4, $enc($p));
        $pdf->Ln(1);
    }

    // NATURALEZA DE LA OPERACION
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 6, $enc('NATURALEZA DE LA OPERACIÓN:'), 0, 1);
    $pdf->SetFont('Arial', '', 7);

    $naturalezaParagraphs = [
        'La presente operación corresponde a una compraventa a plazos.',
        'El precio total a plazo incluye el costo comercial derivado de la venta a plazos.',
        'VOLTIKA es una empresa comercial y no una institución financiera.',
        'EL CLIENTE reconoce que conoce el precio de contado, el precio total a plazo, el monto del enganche, el número y monto de los pagos, así como el monto total a pagar.',
    ];
    foreach ($naturalezaParagraphs as $p) {
        $pdf->MultiCell(0, 4, $enc($p));
        $pdf->Ln(1);
    }

    // RESERVA DE DOMINIO Y RECUPERACION
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 6, $enc('RESERVA DE DOMINIO Y RECUPERACIÓN'), 0, 1);
    $pdf->SetFont('Arial', '', 7);

    $reservaParagraphs = [
        'La propiedad de la motocicleta permanecerá en favor de VOLTIKA hasta el pago total del precio a plazo.',
        'En caso de incumplimiento, VOLTIKA podrá ejercer acciones legales para la recuperación del vehículo.',
        'EL CLIENTE autoriza el uso de tecnologías de geolocalización y control remoto para fines de seguridad y recuperación del bien.',
    ];
    foreach ($reservaParagraphs as $p) {
        $pdf->MultiCell(0, 4, $enc($p));
        $pdf->Ln(1);
    }

    // Privacy notice
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->MultiCell(0, 4, $enc('Previo a la celebración de la presente operación, VOLTIKA puso a disposición de EL CLIENTE el aviso de privacidad para el tratamiento de sus datos personales.'));

    // Signature area
    $pdf->Ln(6);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 6, $enc('FIRMA DEL CLIENTE:'), 0, 1);

    if ($firmaImgPath && file_exists($firmaImgPath)) {
        $pdf->Image($firmaImgPath, $pdf->GetX() + 10, $pdf->GetY(), 60, 30);
        $pdf->Ln(35);
    } else {
        $pdf->Ln(5);
        $pdf->Cell(80, 0.3, '', 1, 1);
        $pdf->Ln(3);
    }

    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(0, 5, $enc('Nombre: ' . $nombre), 0, 1);
    $pdf->Cell(0, 5, $enc('Fecha: ' . $fechaFirma), 0, 1);

    $pdf->Ln(6);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->MultiCell(0, 4, $enc('La presente carátula forma parte integral del contrato de compraventa a plazos, términos y condiciones, pagaré y acta de entrega.'));
    $pdf->Ln(1);
    $pdf->MultiCell(0, 4, $enc('Su firma, ya sea autógrafa o electrónica, implica aceptación total de los mismos.'));

    // ══════════════════════════════════════════════════════════════════════
    // CONTRATO DE FINANCIAMIENTO (pages 3+)
    // ══════════════════════════════════════════════════════════════════════
    generateContratoPages($pdf, $enc, $folio, $nombre, $firmaImgPath, $fechaFirma);

    $pdf->Output('F', $filepath);

    // Clean up temp signature file
    if ($firmaImgPath && file_exists($firmaImgPath)) {
        unlink($firmaImgPath);
    }

    return $filepath;
}

/**
 * Generate Contrato de Compraventa a Plazos pages (appended to Carátula PDF).
 *
 * Body content updated 2026-04-29 to the legal team's "Contrato Voltika v5
 * Español" — final, signed-off version. The Carátula generator above is
 * intentionally kept as-is per the customer brief: it remains the cover
 * page (datos del cliente, precios, plan de pagos), and this function
 * appends the full v5 body (DECLARACIONES + DEFINICIONES + 33 cláusulas
 * + firmas) starting on a new page. The body references the carátula's
 * folio in its header, matching the {{customer_id}} placeholder of the
 * source document.
 *
 * NOM-151 / Cincel signature flow is unchanged — Cláusula Trigésima
 * Primera of v5 explicitly endorses it.
 */
function generateContratoPages($pdf, $enc, $folio, $nombre, $firmaImgPath, $fechaFirma) {

    // ── Layout helpers — local closures keep the function self-contained
    // and reuse the $enc transliterator the caller already wired.
    $h2 = function($title) use ($pdf, $enc) {
        $pdf->Ln(2);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 6, $enc($title), 0, 1);
        $pdf->Ln(0.5);
    };
    $h3 = function($title) use ($pdf, $enc) {
        $pdf->Ln(1);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->MultiCell(0, 4, $enc($title));
    };
    $para = function($text) use ($pdf, $enc) {
        $pdf->SetFont('Arial', '', 7.5);
        $pdf->MultiCell(0, 3.6, $enc($text), 0, 'J');
        $pdf->Ln(0.8);
    };
    $list = function(array $items) use ($pdf, $enc) {
        $pdf->SetFont('Arial', '', 7.5);
        foreach ($items as $it) {
            $pdf->Cell(3); // small indent
            $pdf->MultiCell(0, 3.6, $enc($it), 0, 'J');
            $pdf->Ln(0.3);
        }
        $pdf->Ln(0.4);
    };
    $defList = function(array $pairs) use ($pdf, $enc) {
        $pdf->SetFont('Arial', '', 7.5);
        foreach ($pairs as $p) {
            $pdf->SetFont('Arial', 'B', 7.5);
            // Print term inline-bold then the rest of the definition justified.
            $term = $p[0] . ': ';
            $pdf->Write(3.6, $enc($term));
            $pdf->SetFont('Arial', '', 7.5);
            $pdf->Write(3.6, $enc($p[1]));
            $pdf->Ln(4.6);
        }
        $pdf->Ln(0.4);
    };

    // ─── Page header (new page after carátula) ───────────────────────────
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 7);
    $pdf->Cell(0, 4, $enc('CONTRATO ASOCIADO AL FOLIO: ' . $folio), 0, 1, 'R');
    $pdf->Ln(1);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->MultiCell(0, 6, $enc('CONTRATO DE COMPRAVENTA A PLAZOS CON RESERVA DE DOMINIO'), 0, 'C');
    $pdf->Ln(2);

    $para('Que celebran por una parte MTECH GEARS, S.A. DE C.V. (en lo sucesivo VOLTIKA); y por la otra parte por propio derecho la persona física cuyos datos generales se encontrarán en la Carátula del presente Contrato, misma que forma parte integral del mismo (en lo sucesivo EL CLIENTE); y en conjunto con VOLTIKA se les denominará LAS PARTES al tenor de las siguientes Declaraciones, Definiciones y Cláusulas:');

    // ─── DECLARACIONES ───────────────────────────────────────────────────
    $h2('DECLARACIONES');

    $h3('I. Declara EL CLIENTE, por su propio derecho:');
    $list([
        'Es una persona física con la capacidad jurídica y económica para obligarse bajo los términos y condiciones del presente Contrato.',
        'Para los efectos del presente contrato se señala como domicilio y medios de comunicación los señalados en la Carátula del Contrato.',
        'EL CLIENTE reconoce que el número telefónico registrado será considerado como medio de autenticación válido para efectos del presente contrato.',
    ]);
    $para('BAJO PROTESTA DE DECIR VERDAD, EL CLIENTE DECLARA: (i) que es el legítimo titular del medio de pago utilizado para el enganche, los pagos periódicos y/o el pago total de contado, según corresponda; o bien, que cuenta con autorización expresa, vigente y suficiente del titular legítimo para utilizarlo en esta operación; (ii) que la información proporcionada durante el proceso de contratación es verdadera, completa y actualizada; (iii) que reconoce y acepta que el cargo aparecerá identificado en su estado de cuenta como "VOLTIKA MX" o descriptor similar designado por VOLTIKA, procesado a través de instituciones de pago autorizadas como Stripe, MercadoPago u otras; y (iv) que reconoce que cualquier falsedad en estas declaraciones podrá constituir el delito de fraude en términos del artículo 386 del Código Penal Federal y demás disposiciones aplicables.');
    $para('Para proceder a la celebración de este Contrato, EL CLIENTE deberá exhibir los siguientes documentos originales, los cuales le serán devueltos previa digitalización:');
    $list([
        'Identificación oficial vigente emitida por la autoridad competente, con fotografía y firma.',
        'Comprobante de domicilio con una antigüedad no mayor a 3 (tres) meses de su fecha de emisión.',
    ]);

    $h3('II. Protección de Datos Personales.');
    $para('VOLTIKA hace del conocimiento de EL CLIENTE que los datos personales proporcionados durante el proceso de contratación, incluyendo datos de identificación, contacto, financieros, patrimoniales y, en su caso, biométricos, serán tratados conforme al Aviso de Privacidad Integral disponible en https://www.voltika.mx/docs/privacidad_2026. EL CLIENTE manifiesta que ha leído, entendido y aceptado el contenido del Aviso de Privacidad, otorgando su consentimiento para el tratamiento de sus datos personales para las siguientes finalidades:');
    $list([
        'Identificación y validación de identidad.',
        'Evaluación de la operación comercial.',
        'Procesamiento de pagos.',
        'Entrega del producto.',
        'Administración de la relación contractual.',
        'Prevención de fraude y atención de aclaraciones o disputas.',
        'Cobranza y recuperación.',
        'Cumplimiento de obligaciones legales.',
        'Captura, almacenamiento y validación de datos técnicos de la operación, incluyendo dirección IP, geolocalización del pago y de la entrega, dispositivo utilizado, hora y fecha de cada interacción, validaciones OTP y confirmaciones de email.',
    ]);
    $para('EL CLIENTE autoriza a VOLTIKA para compartir sus datos personales con terceros necesarios para la operación, incluyendo procesadores de pago (Stripe, MercadoPago, Openpay y similares), redes de tarjetas (Visa, Mastercard, American Express), bancos emisores y adquirentes, sociedades de información crediticia, proveedores tecnológicos de validación de identidad y aliados logísticos, exclusivamente para el cumplimiento de las finalidades antes señaladas. EL CLIENTE podrá ejercer en cualquier momento sus derechos de acceso, rectificación, cancelación u oposición (derechos ARCO), conforme a los mecanismos establecidos en el Aviso de Privacidad.');

    $h3('III.');
    $para('Que ha recibido, revisado y aceptado la carátula de la operación de compraventa a plazos, identificada con el Folio que aparece al encabezado, y reconoce que la misma forma parte integral del contrato.');

    $h3('IV. Declara VOLTIKA, a través de su representante legal, que:');
    $para('Es una sociedad mexicana debidamente constituida bajo la legislación aplicable de los Estados Unidos Mexicanos, y cuenta con la capacidad jurídica y económica para obligarse bajo los términos y condiciones del presente Contrato.');

    $h3('V.');
    $para('Cuenta con un Registro Federal de Contribuyentes.');

    $h3('VI.');
    $para('Para los efectos del presente contrato se señala como domicilio el ubicado en Jaime Balmes 71, despacho 101 C, Polanco I Sección, Miguel Hidalgo, C.P. 11510, Ciudad de México.');

    $para('Las partes se reconocen mutuamente la capacidad con la que concurren a este acto, la personalidad para expresar su voluntad y en consecuencia para obligarse en los términos del presente Contrato.');

    // ─── DEFINICIONES ────────────────────────────────────────────────────
    $h2('DEFINICIONES');
    $para('Las partes acuerdan que, para efectos del presente Contrato, los términos siguientes tendrán el significado que enseguida se enuncia:');
    $defList([
        ['Solicitud',         'Documento generado por VOLTIKA y firmado por EL CLIENTE, en el que se establecen los datos generales de EL CLIENTE.'],
        ['Carátula',          'Documento generado por VOLTIKA y firmado por EL CLIENTE, que contiene los elementos esenciales de la operación: identificación del producto, precio de contado, precio total a plazo, enganche, saldo pendiente, número y periodicidad de pagos, monto por pago y monto total a pagar.'],
        ['Saldo Pendiente de Pago', 'Parte del precio total a plazo pendiente de pago por EL CLIENTE.'],
        ['Saldo Insoluto',    'Monto pendiente de pago conforme al plan establecido en la Carátula y Tabla de Pagos.'],
        ['Tabla de Pagos',    'Documento que refleja el número total de pagos, su periodicidad, el monto de cada pago y la fecha estimada de primer pago.'],
        ['Pagaré',            'Título de crédito ejecutivo suscrito por EL CLIENTE conforme a los artículos 170 y 173 de la Ley General de Títulos y Operaciones de Crédito, que ampara el saldo total a plazo.'],
        ['Validación Electrónica', 'Mecanismo de autenticación mediante códigos de seguridad (OTP), confirmaciones digitales, registros electrónicos, evidencia fotográfica, captura de IP, geolocalización, firmas electrónicas y demás mensajes de datos generados durante la contratación, pago y entrega, con efectos jurídicos conforme a la legislación aplicable.'],
        ['Descriptor de Cargo', 'Identificación con la que aparece la operación en el estado de cuenta del medio de pago utilizado por EL CLIENTE, siendo "VOLTIKA MX" o el descriptor que VOLTIKA designe.'],
        ['Contracargo o Disputa', 'Procedimiento mediante el cual el titular de un medio de pago solicita a su banco emisor o procesador la reversión de un cargo previamente realizado.'],
        ['Carta Factura',     'Documento que VOLTIKA entrega a EL CLIENTE como comprobante temporal de la operación, válido para los trámites de emplacamiento y registro vehicular estatal, mientras VOLTIKA conserva la factura original en custodia hasta la liquidación total del precio a plazo.'],
        ['REPUVE',            'Registro Público Vehicular, conforme a la Ley del Registro Público Vehicular.'],
        ['Punto de Entrega',  'Establecimiento autorizado por VOLTIKA designado para realizar la entrega física del vehículo a EL CLIENTE, mismo que queda registrado en la Carátula del presente Contrato.'],
        ['Acta de Entrega',   'Documento firmado por EL CLIENTE al recibir el vehículo, mediante el cual reconoce expresamente la recepción del mismo y su conformidad con las condiciones físicas y funcionales del bien.'],
        ['PSC',               'Prestador de Servicios de Certificación acreditado por la Secretaría de Economía conforme a la NOM-151-SCFI-2016 para emitir Constancias de Conservación de Mensajes de Datos y Sellos Digitales de Tiempo.'],
    ]);

    // ─── CLÁUSULAS (33 total) ────────────────────────────────────────────
    $h2('CLÁUSULAS');

    $h3('PRIMERA. OBJETO.');
    $para('El objeto del presente Contrato es la compraventa a plazos de una motocicleta eléctrica, en adelante EL VEHÍCULO, por lo que VOLTIKA vende a EL CLIENTE el vehículo descrito en la Carátula, reservándose la propiedad del mismo hasta el pago total del precio a plazo pactado. Todos los datos de identificación, características, modalidades y demás elementos constitutivos de la operación son los que se especifican en la Carátula. El precio total a plazo incluye el precio del vehículo y el costo comercial derivado de la venta a plazos. EL CLIENTE reconoce que la entrega del vehículo se realizará mediante validación electrónica conforme a la Carátula, incluyendo códigos de seguridad (OTP), registros digitales, evidencia fotográfica y firma del Acta de Entrega, los cuales constituirán evidencia suficiente de la entrega conforme a la legislación aplicable.');

    $h3('SEGUNDA. DESTINO.');
    $para('EL CLIENTE reconoce que la presente operación tiene como finalidad exclusiva la adquisición del producto descrito en la Carátula y, en su caso, de los servicios adicionales contratados.');

    $h3('TERCERA. PLAZO DEL CONTRATO.');
    $para('El plazo del Contrato será el que resulte del número total de pagos y su periodicidad establecidos en la Carátula y, en su caso, en la Tabla de Pagos. El presente Contrato seguirá surtiendo sus efectos legales mientras existan saldos insolutos a cargo de EL CLIENTE.');

    $h3('CUARTA. DOMICILIACIÓN Y MEDIOS DE PAGO.');
    $para('EL CLIENTE registra como medio de pago la(s) tarjeta(s) bancaria(s) o cuenta(s) señalada(s) en LA SOLICITUD, para la domiciliación de los pagos derivados de la presente compraventa a plazos. EL CLIENTE autoriza expresamente a VOLTIKA para realizar cargos recurrentes a la tarjeta bancaria o cuenta registrada durante la contratación, con el fin de cubrir los pagos periódicos del mismo. EL CLIENTE se obliga a:');
    $list([
        'a) Mantener vigente y operativa una forma de pago autorizada durante toda la vigencia del contrato.',
        'b) Notificar a VOLTIKA cualquier cambio, cancelación, vencimiento o pérdida de la tarjeta registrada, dentro de los 5 (cinco) días naturales siguientes.',
        'c) Registrar nueva tarjeta válida en caso de cancelación, vencimiento o imposibilidad de cargo, en un plazo no mayor a 5 (cinco) días hábiles.',
        'd) Realizar el pago por medios alternativos autorizados por VOLTIKA en caso de no contar con tarjeta vigente.',
    ]);
    $para('EL CLIENTE reconoce que el medio de pago registrado forma parte del proceso de autenticación y cumplimiento del presente Contrato. La cancelación, rechazo o imposibilidad de cargo NO extingue la obligación de pago.');

    $h3('QUINTA. ASIGNACIÓN DE LA UNIDAD.');
    $para('La entrega y asignación del vehículo estarán sujetas a la disponibilidad de inventario, modelo, color y al proceso de entrega establecido en la Carátula y en el presente Contrato.');

    $h3('SEXTA. PAGO DE ENGANCHE Y RETENCIÓN POR CANCELACIÓN.');
    $para('EL CLIENTE reconoce que el pago inicial, referido como ENGANCHE, realizado a VOLTIKA constituye un depósito en garantía para la reserva del vehículo seleccionado y el inicio del proceso de validación. Dicho depósito será aplicado al enganche de la operación una vez firmado el contrato correspondiente. En caso de que EL CLIENTE decida no continuar con la contratación después de haber sido aprobada la operación, VOLTIKA podrá retener un porcentaje del enganche pagado correspondiente a los gastos administrativos y operativos efectivamente incurridos hasta ese momento, los cuales podrán incluir, de manera enunciativa más no limitativa:');
    $list([
        'Validación crediticia ante sociedades de información crediticia.',
        'Verificación de identidad y biométrica.',
        'Análisis de la operación y aprobación crediticia.',
        'Reserva de inventario.',
        'Costos logísticos comprometidos.',
        'Asignación operativa de la unidad.',
    ]);
    $para('La retención correspondiente será determinada CASO POR CASO conforme al avance del proceso al momento de la cancelación, con un mínimo del 10% (diez por ciento) del enganche pagado, pudiendo ser mayor cuando los gastos efectivamente incurridos así lo justifiquen, sin exceder los gastos reales y documentados.');
    $para('EL CLIENTE reconoce que la validez, activación y ejecución del presente Contrato se encuentra condicionada a la acreditación efectiva del pago del enganche mediante los medios autorizados por VOLTIKA. El pago del enganche realizado por EL CLIENTE mediante medios electrónicos constituye aceptación expresa de la operación comercial, de la Carátula y del presente Contrato, así como autorización para la asignación del vehículo y el inicio del proceso de entrega.');

    $h3('SÉPTIMA. PAGOS DE LA OPERACIÓN A PLAZOS.');
    $para('EL CLIENTE pagará a VOLTIKA el saldo pendiente de pago mediante el número total de pagos, con la periodicidad y por el monto establecido en la Carátula y, en su caso, en la Tabla de Pagos. Los pagos deberán realizarse en las fechas que correspondan conforme al plan aceptado por EL CLIENTE. En caso de que una fecha de pago coincida con un día inhábil bancario, el pago deberá efectuarse el día hábil inmediato anterior o por el medio alternativo autorizado por VOLTIKA. VOLTIKA notificará a EL CLIENTE previo a cada cobro periódico mediante correo electrónico, WhatsApp o medio de contacto registrado.');

    $h3('OCTAVA. PRECIO TOTAL A PLAZO.');
    $para('EL CLIENTE reconoce que el monto total a pagar señalado en la Carátula incluye el precio del vehículo y el costo comercial derivado de la venta a plazos. EL CLIENTE manifiesta que conoce y acepta el precio de contado, el precio total a plazo, la periodicidad de los pagos, el importe de cada uno de ellos y el monto total a pagar, mismos que le fueron informados de manera clara, veraz y comprensible antes de la contratación, conforme al artículo 7 de la Ley Federal de Protección al Consumidor.');

    $h3('NOVENA. INCUMPLIMIENTO DE PAGO.');
    $para('En caso de que EL CLIENTE no pague puntualmente cualquier cantidad exigible conforme al presente Contrato, VOLTIKA podrá ejercer las acciones de cobro correspondientes, incluyendo el vencimiento anticipado de los pagos pendientes. VOLTIKA podrá aplicar cargos por atraso por cada evento de incumplimiento, los cuales serán determinados conforme a las políticas vigentes de cobranza.');

    $h3('DÉCIMA. PROCEDIMIENTO DE ACLARACIÓN PREVIA.');
    $para('Antes de presentar cualquier aclaración, queja o solicitud de reversión de cargo (contracargo) ante su institución financiera, EL CLIENTE se obliga a contactar primero a VOLTIKA mediante los canales oficiales de atención al cliente, con la finalidad de:');
    $list([
        'a) Verificar la información de la operación.',
        'b) Recibir aclaración sobre cualquier cargo no reconocido.',
        'c) Buscar solución a cualquier inconformidad.',
    ]);
    $para('VOLTIKA atenderá la solicitud dentro de un plazo máximo de 5 (cinco) días hábiles, conforme al artículo 99 de la Ley Federal de Protección al Consumidor. EL CLIENTE reconoce que: a) Una vez entregado el vehículo, firmados el contrato, el pagaré y el Acta de Entrega, y emitida la factura CFDI, la transacción se considera cumplida por VOLTIKA. b) Cualquier disputa relacionada con la operación debe seguir el procedimiento de aclaración establecido. c) En caso de disputa improcedente por acreditarse la entrega y la legitimidad de la operación, VOLTIKA podrá ejercer las acciones que se detallan en este Contrato, incluyendo recuperación del vehículo, cobro del saldo conforme al pagaré ejecutivo, reporte a sociedades de información crediticia y acciones legales que correspondan. Lo anterior sin perjuicio de los derechos de EL CLIENTE para reclamar cualquier vicio del producto conforme a la legislación aplicable.');

    $h3('DÉCIMA PRIMERA. MEDIOS DE ACREDITACIÓN Y REGISTRO.');
    $para('EL CLIENTE reconoce y acepta que VOLTIKA podrá conservar registros físicos y electrónicos relacionados con la contratación, pago, validación, entrega y cumplimiento del presente contrato, incluyendo mensajes de datos, registros de plataforma, direcciones IP, geolocalización, fecha y hora de operación, validaciones OTP, evidencia fotográfica, firma electrónica y cualquier otra constancia digital generada durante la operación. Dichos registros constituirán evidencia suficiente de la operación y podrán ser utilizados por VOLTIKA para fines de aclaración, cobranza, prevención de fraude, atención de contracargos, recuperación del vehículo y defensa en cualquier procedimiento legal o administrativo.');

    $h3('DÉCIMA SEGUNDA. LUGAR Y FORMA DE PAGO.');
    $para('Los pagos que deba efectuar EL CLIENTE en favor de VOLTIKA al amparo del presente Contrato deberán realizarse en las fechas convenidas conforme a la Carátula y al plan de pagos aceptado. EL CLIENTE podrá efectuar los pagos correspondientes mediante los medios autorizados por VOLTIKA, incluyendo cargos automáticos a tarjeta o cuenta bancaria (domiciliación), transferencias electrónicas (SPEI), pagos referenciados, tiendas de conveniencia u otros medios habilitados. EL CLIENTE reconoce que los pagos realizados mediante medios electrónicos, así como las validaciones electrónicas realizadas mediante códigos de verificación (OTP) y registros digitales, constituyen evidencia suficiente de la operación, aceptación del cargo y cumplimiento del presente Contrato. La domiciliación de pagos mediante tarjeta bancaria o cuenta autorizada será un requisito obligatorio para la vigencia del contrato. La falta de un medio de pago activo y válido podrá ser considerada como incumplimiento.');

    $h3('DÉCIMA TERCERA. INFORMACIÓN DE PAGOS.');
    $para('VOLTIKA pondrá a disposición de EL CLIENTE, por medios electrónicos o a través del portal de cliente, la información relativa al estado de sus pagos, incluyendo pagos realizados, pagos pendientes y fecha estimada del siguiente pago.');

    $h3('DÉCIMA CUARTA. PAGOS ANTICIPADOS.');
    $para('EL CLIENTE podrá realizar pagos anticipados o adelantar pagos en cualquier momento, sin penalización, conforme a los medios autorizados por VOLTIKA. Dichos pagos se aplicarán al saldo pendiente de la operación conforme al plan aceptado.');

    $h3('DÉCIMA QUINTA. OBLIGACIONES DEL CLIENTE.');
    $para('EL CLIENTE está obligado a cumplir durante la vigencia de este Contrato o mientras exista saldo insoluto las obligaciones siguientes:');
    $list([
        'a) Mantener y conservar en condiciones adecuadas de servicio el bien objeto del presente Contrato.',
        'b) Permitir que VOLTIKA efectúe, previo aviso razonable, inspecciones del vehículo objeto de la garantía prendaria.',
        'c) En caso de incumplimiento de pago, aceptar la devolución voluntaria del vehículo y la recuperación de su posesión por medios legales y procedimientos extrajudiciales permitidos por la legislación aplicable.',
        'd) Mantener vigente la cobertura de seguro contratada o, en su defecto, asumir los riesgos del vehículo.',
        'e) Notificar a VOLTIKA cualquier robo, siniestro o afectación del vehículo dentro de las 24 horas siguientes a su conocimiento.',
    ]);

    $h3('DÉCIMA SEXTA. RESERVA DE DOMINIO.');
    $para('VOLTIKA conservará la propiedad del vehículo entregado en este contrato hasta que EL CLIENTE haya liquidado en su totalidad todos los gastos contemplados, así como la totalidad del precio total a plazo pactado. Mientras no se cumpla dicha condición, EL CLIENTE tendrá la posesión y uso de la motocicleta, pero no adquirirá la propiedad. EL CLIENTE reconoce y acepta que la motocicleta cuenta con dispositivos tecnológicos de geolocalización (GPS) y monitoreo, los cuales serán utilizados exclusivamente para fines de seguridad, prevención de fraude, protección del bien y seguimiento durante la vigencia del contrato. En caso de incumplimiento en el pago, VOLTIKA podrá implementar medidas razonables de control sobre el vehículo, conforme a la legislación aplicable, únicamente cuando el vehículo se encuentre estacionado y sin operación activa. El incumplimiento de pago faculta a VOLTIKA para ejercer las acciones de cobro, rescisión del contrato y recuperación del vehículo por los medios legales aplicables, incluyendo procedimientos extrajudiciales permitidos por la legislación vigente.');

    $h3('DÉCIMA SÉPTIMA. GARANTÍA PRENDARIA.');
    $para('En garantía del pago exacto y oportuno del precio a plazos y demás obligaciones derivadas del presente Contrato, EL CLIENTE constituye prenda en primer lugar a favor de VOLTIKA sobre el bien objeto de la presente compraventa a plazos. La ejecución de la garantía prendaria y la recuperación del vehículo podrán realizarse por los medios legales aplicables, incluyendo procedimientos extrajudiciales permitidos por la legislación vigente.');

    $h3('DÉCIMA OCTAVA. PAGARÉ EJECUTIVO Y FIRMA AL ENTREGAR.');
    $para('EL CLIENTE se obliga a SUSCRIBIR EN EL MOMENTO DE LA ENTREGA FÍSICA DEL VEHÍCULO, un PAGARÉ por el monto del saldo total a plazo de la operación, mismo que constituirá título ejecutivo a favor de VOLTIKA conforme a los artículos 170, 171, 172 y 173 de la Ley General de Títulos y Operaciones de Crédito. La firma del Pagaré es REQUISITO INDISPENSABLE para la entrega del vehículo. Sin la suscripción del Pagaré, VOLTIKA NO realizará la entrega del vehículo. El Pagaré garantiza el cumplimiento de las obligaciones de pago de EL CLIENTE y subsistirá hasta la liquidación total del precio a plazo. Su firma podrá ser autógrafa o electrónica conforme a la legislación aplicable. EL CLIENTE reconoce que el Pagaré es independiente del presente Contrato y constituye obligación causal autónoma, ejecutable por la vía mercantil ejecutiva conforme al artículo 1391 del Código de Comercio.');

    $h3('DÉCIMA NOVENA. CARTA FACTURA Y REGISTRO ANTE REPUVE.');
    $para('Considerando que la presente operación es de compraventa a plazos con reserva de dominio, las partes reconocen y acuerdan lo siguiente:');
    $list([
        'a) VOLTIKA emitirá el Comprobante Fiscal Digital por Internet (CFDI) correspondiente a la operación, conforme a las disposiciones fiscales aplicables.',
        'b) VOLTIKA conservará la FACTURA ORIGINAL en custodia hasta la liquidación total del precio a plazo, conforme a la práctica estándar de financiamiento automotriz en México.',
        'c) EL CLIENTE recibirá CARTA FACTURA con vigencia conforme a la legislación aplicable, válida para los trámites de emplacamiento y registro vehicular estatal.',
        'd) Una vez liquidado el precio total a plazo y cumplidas todas las obligaciones de EL CLIENTE conforme al presente Contrato, VOLTIKA entregará a EL CLIENTE la FACTURA ORIGINAL del vehículo.',
        'e) Conforme al artículo 23 de la Ley del Registro Público Vehicular, VOLTIKA presentará al REPUVE el aviso de compraventa correspondiente, indicando los datos de EL CLIENTE como nuevo propietario, dentro del día hábil siguiente al de la facturación.',
        'f) EL CLIENTE podrá obtener su constancia de inscripción REPUVE a través de la consulta ciudadana en el portal oficial www.repuve.gob.mx, o acudiendo a los módulos físicos del REPUVE.',
        'g) EL CLIENTE manifiesta haber sido informado de manera previa, clara y comprensible sobre las implicaciones de la inscripción ante el REPUVE y de la entrega de carta factura, conforme al artículo 7 de la Ley Federal de Protección al Consumidor.',
    ]);

    $h3('VIGÉSIMA. TIEMPOS DE ENTREGA.');
    $para('VOLTIKA se compromete a realizar la entrega del producto conforme a los siguientes plazos, contados a partir de la firma del Contrato y la acreditación del enganche:');
    $list([
        'a) ENTREGA ESTÁNDAR: hasta 60 (sesenta) días naturales, cuando el modelo y color seleccionado se encuentre disponible en inventario.',
        'b) ENTREGA EXTENDIDA: hasta 90 (noventa) días naturales, cuando el modelo o color esté sujeto a reposición de inventario, importación, ensamble o procesos logísticos especiales.',
        'c) FUERZA MAYOR: en caso de causas ajenas al control de VOLTIKA, el plazo aplicable podrá extenderse hasta 30 (treinta) días naturales adicionales, previa notificación a EL CLIENTE.',
    ]);
    $para('VOLTIKA informará a EL CLIENTE el plazo aplicable a su operación al momento de la confirmación del pedido. EL CLIENTE acepta expresamente el plazo informado. La fecha definitiva de entrega será confirmada una vez asignada la unidad específica a EL CLIENTE, incluyendo modelo, color y número de serie (VIN/NIV).');
    $para('Si transcurrido el plazo total aplicable VOLTIKA no ha entregado el vehículo, EL CLIENTE podrá: a) Continuar esperando con compensación equivalente conforme a las políticas vigentes de VOLTIKA. b) Solicitar la cancelación del Contrato con devolución íntegra del enganche y pagos realizados, en un plazo no mayor a 10 (diez) días hábiles.');

    $h3('VIGÉSIMA PRIMERA. PUNTO DE ENTREGA.');
    $para('EL CLIENTE acepta que el vehículo será entregado en el PUNTO DE ENTREGA AUTORIZADO POR VOLTIKA seleccionado por EL CLIENTE durante el proceso de compra, mismo que quedará registrado en la Carátula del presente Contrato. EL CLIENTE se obliga a:');
    $list([
        'a) Acudir PERSONALMENTE al punto de entrega asignado en la fecha y hora confirmada por VOLTIKA.',
        'b) Presentarse con identificación oficial vigente original (INE, pasaporte o cédula profesional).',
        'c) Realizar la inspección del vehículo conforme a la Cláusula Vigésima Segunda.',
        'd) Suscribir los documentos correspondientes (Acta de Entrega y Pagaré).',
    ]);
    $para('VOLTIKA se reserva el derecho de NO ENTREGAR el vehículo en caso de que EL CLIENTE no se presente con la documentación e identificación requeridas, o cuando exista duda razonable sobre la identidad de la persona que se presenta. El cambio de punto de entrega deberá solicitarse con al menos 5 (cinco) días hábiles de anticipación a la fecha de entrega programada y estará sujeto a disponibilidad operativa de VOLTIKA. La entrega en domicilio del cliente, cuando sea aplicable, se considerará como punto de entrega para todos los efectos del presente Contrato.');

    $h3('VIGÉSIMA SEGUNDA. INSPECCIÓN, VALIDACIÓN DE IDENTIDAD Y CONFORMIDAD DE LA ENTREGA.');
    $para('El proceso de entrega incluirá los siguientes pasos obligatorios:');

    $para('A) VALIDACIÓN DE IDENTIDAD. Al momento de la entrega, EL CLIENTE deberá presentarse PERSONALMENTE en el punto de entrega asignado y acreditar que es la misma persona que celebró el contrato mediante:');
    $list([
        'a) Identificación oficial vigente (INE/IFE, pasaporte o cédula profesional) con fotografía y firma, presentada en original.',
        'b) Validación de Código OTP enviado al teléfono registrado por EL CLIENTE.',
        'c) Coincidencia de la firma autógrafa de EL CLIENTE con la firma del contrato y de la identificación oficial.',
        'd) Verificación visual por el personal de entrega de VOLTIKA o del punto autorizado.',
    ]);
    $para('VOLTIKA NO ENTREGARÁ EL VEHÍCULO si la persona que se presenta NO ES la misma que celebró el contrato, o si existe inconsistencia en la validación de identidad. En tal caso, la entrega se reprogramará y EL CLIENTE deberá acreditar su identidad mediante los procedimientos adicionales que VOLTIKA determine.');

    $para('B) INSPECCIÓN DEL VEHÍCULO. Al momento de la entrega, EL CLIENTE deberá:');
    $list([
        'a) Inspeccionar físicamente el vehículo en presencia del personal de entrega.',
        'b) Verificar que coincida con lo pactado en la Carátula (modelo, color, especificaciones, accesorios).',
        'c) Revisar el estado físico del vehículo y la ausencia de daños o desperfectos visibles.',
        'd) Probar el funcionamiento básico (encendido, luces, frenos, sistema eléctrico).',
        'e) Verificar la documentación entregada (carta factura, manuales, llaves, accesorios).',
        'f) Manifestar cualquier inconformidad ANTES de firmar el Acta de Entrega.',
    ]);
    $para('EL CLIENTE tiene el derecho de RECHAZAR la entrega si detecta cualquier inconformidad sustancial. En tal caso, VOLTIKA podrá: a) Reparar el desperfecto detectado. b) Sustituir el vehículo por otro en buen estado. c) En caso de imposibilidad técnica, las partes acordarán los términos del retorno y la reversión de la operación conforme a la legislación aplicable.');

    $para('C) ACTA DE ENTREGA. Al recibir el vehículo conforme, EL CLIENTE firmará el ACTA DE ENTREGA que deberá contener al menos:');
    $list([
        'a) Datos generales de EL CLIENTE.',
        'b) Datos del vehículo entregado (VIN/NIV, modelo, color, año).',
        'c) Fecha, hora y punto de entrega.',
        'd) Validación OTP capturada.',
        'e) Firma autógrafa o electrónica de EL CLIENTE.',
        'f) Firma del personal de entrega.',
        'g) Foto de EL CLIENTE con el vehículo.',
        'h) Manifestación expresa de conformidad con la entrega.',
    ]);
    $para('La firma del Acta de Entrega por EL CLIENTE constituye MANIFESTACIÓN EXPRESA de conformidad con las condiciones del vehículo recibido y con la operación celebrada. NO HABRÁ ENTREGA SIN: validación de identidad + inspección satisfactoria + Acta de Entrega firmada + Pagaré firmado. Posteriormente a la firma del Acta de Entrega, las inconformidades se atenderán conforme al procedimiento de garantía y vicios ocultos previstos en la legislación aplicable.');

    $h3('VIGÉSIMA TERCERA. CANCELACIÓN.');
    $para('Considerando la naturaleza específica del bien (vehículo automotor) y las disposiciones fiscales y administrativas aplicables (Código Fiscal de la Federación, Resolución Miscelánea Fiscal vigente, Ley del Registro Público Vehicular), las partes acuerdan los siguientes supuestos de cancelación:');
    $para('(i) CANCELACIÓN ANTES DE LA EMISIÓN DEL CFDI: EL CLIENTE podrá solicitar la cancelación del Contrato mediante notificación por escrito a VOLTIKA, en cualquier momento previo a la emisión del CFDI correspondiente. Aplicará la retención de gastos administrativos y operativos conforme a la Cláusula Sexta del presente Contrato.');
    $para('(ii) CANCELACIÓN POSTERIOR A LA EMISIÓN DEL CFDI: Una vez emitido el CFDI a nombre de EL CLIENTE y presentado el aviso correspondiente al REPUVE, las partes reconocen que la cancelación con devolución total del precio pagado NO PROCEDERÁ de manera automática, debido a que: a) Conforme a las reglas 2.7.1.34 y 2.7.1.35 de la Resolución Miscelánea Fiscal vigente y al artículo 29-A del Código Fiscal de la Federación, la cancelación del CFDI requiere aceptación del receptor y conlleva trámites fiscales específicos. b) El aviso al REPUVE requiere proceso administrativo formal para su reversión. c) El IVA correspondiente a la operación es enterado al SAT. d) El vehículo es susceptible de depreciación inmediata por el solo registro y uso potencial.');
    $para('(iii) DERECHOS PRESERVADOS DE EL CLIENTE: No obstante lo anterior, EL CLIENTE conserva los siguientes derechos sustantivos:');
    $list([
        'a) RECHAZO AL MOMENTO DE LA ENTREGA conforme a la Cláusula Vigésima Segunda.',
        'b) RECLAMOS POR DEFECTOS DE FÁBRICA conforme a la póliza de garantía aplicable.',
        'c) VICIOS OCULTOS conforme al artículo 77 de la Ley Federal de Protección al Consumidor.',
        'd) DEFECTOS GRAVES conforme al artículo 79 de la Ley Federal de Protección al Consumidor: reparación gratuita o sustitución por otro vehículo equivalente.',
        'e) SERVICIO POST-VENTA conforme a las pólizas vigentes.',
    ]);
    $para('EL CLIENTE manifiesta que esta información le ha sido proporcionada de manera previa, clara y comprensible antes de la celebración del presente Contrato.');

    $h3('VIGÉSIMA CUARTA. POSESIÓN DEL VEHÍCULO.');
    $para('EL CLIENTE conservará la posesión del vehículo objeto del presente Contrato, en su carácter de depositario, obligándose a su cuidado, resguardo, conservación y uso adecuado conforme a lo establecido en el presente Contrato. EL CLIENTE reconoce que VOLTIKA mantiene la propiedad del vehículo hasta el pago total del precio a plazo conforme a la reserva de dominio.');

    $h3('VIGÉSIMA QUINTA. RESPONSABILIDAD SOBRE EL VEHÍCULO.');
    $para('EL CLIENTE reconoce que es responsable del uso, resguardo y conservación del vehículo. En caso de daño, pérdida, robo o cualquier siniestro que afecte el vehículo, EL CLIENTE no quedará liberado de sus obligaciones de pago conforme al presente Contrato. EL CLIENTE reconoce que el uso del vehículo es bajo su exclusiva responsabilidad. VOLTIKA no será responsable por actos, hechos, daños, infracciones o delitos cometidos por EL CLIENTE o por terceros que utilicen el vehículo una vez realizada la entrega. EL CLIENTE se obliga a sacar en paz y a salvo a VOLTIKA de cualquier reclamación, procedimiento o responsabilidad derivada del uso del vehículo posterior a su entrega.');

    $h3('VIGÉSIMA SEXTA. CAUSAS DE VENCIMIENTO ANTICIPADO.');
    $para('VOLTIKA tendrá derecho a declarar el vencimiento anticipado del presente Contrato, sin necesidad de declaración judicial previa, en caso de que ocurra cualquiera de las siguientes causas:');
    $list([
        '1) Si EL CLIENTE no paga puntual e íntegramente cualquier cantidad exigible conforme al presente Contrato.',
        '2) Si EL CLIENTE incumple cualquiera de las obligaciones establecidas, incluyendo obligaciones de hacer o no hacer.',
        '3) Si EL CLIENTE proporciona información falsa, inexacta o incompleta durante el proceso de contratación o durante la vigencia del Contrato.',
        '4) Si EL CLIENTE vende, cede, grava o dispone del vehículo sin autorización previa y por escrito de VOLTIKA.',
        '5) Si el vehículo es robado, siniestrado, embargado o afectado de cualquier forma y EL CLIENTE no notifica a VOLTIKA dentro de un plazo razonable.',
        '6) Si EL CLIENTE presenta una disputa o contracargo improcedente sin haber agotado el procedimiento de aclaración previa establecido en la Cláusula Décima.',
    ]);
    $para('En caso de actualizarse cualquiera de las causas anteriores, VOLTIKA podrá declarar por vencido anticipadamente el plazo del Contrato y EL CLIENTE deberá pagar de manera inmediata el saldo insoluto pendiente, así como cualquier cantidad exigible conforme al Pagaré ejecutivo.');

    $h3('VIGÉSIMA SÉPTIMA. COMPENSACIÓN.');
    $para('En el supuesto de que EL CLIENTE incumpla con su obligación de pago, autoriza y faculta irrevocablemente a VOLTIKA para que cargue contra la cuenta o tarjeta autorizada para domiciliar el pago, por la cantidad igual al monto del plan aceptado, sin necesidad de requerimiento, aviso o demanda alguna.');

    $h3('VIGÉSIMA OCTAVA. CESIÓN DE DERECHOS DE COBRO.');
    $para('EL CLIENTE no podrá ceder sus derechos u obligaciones conforme a este contrato, ni interés en el mismo, sin el consentimiento previo y por escrito de VOLTIKA. Por su parte, VOLTIKA podrá transmitir, ceder, negociar o titularizar los derechos de cobro derivados de la presente operación de compraventa a plazos.');

    $h3('VIGÉSIMA NOVENA. DOMICILIOS Y NOTIFICACIONES.');
    $para('Para todos los efectos del presente contrato LAS PARTES señalan como sus domicilios los proporcionados en los respectivos apartados. Asimismo, EL CLIENTE acepta recibir notificaciones mediante correo electrónico, SMS o WhatsApp. Mientras EL CLIENTE no notifique a VOLTIKA por escrito el cambio de domicilio, los emplazamientos, notificaciones y demás diligencias judiciales o extrajudiciales se practicarán en el domicilio antes señalado. EL CLIENTE deberá informar a VOLTIKA del cambio de su domicilio con cuando menos 10 (diez) días hábiles de anticipación.');

    $h3('TRIGÉSIMA. ATENCIÓN A CLIENTES.');
    $para('Para cualquier aclaración, queja o reclamación relacionada con el presente Contrato, EL CLIENTE podrá comunicarse a los medios de contacto autorizados por VOLTIKA: correo electrónico contacto@voltika.mx, WhatsApp +52 55 1341 6370, o portal web www.voltika.mx.');

    $h3('TRIGÉSIMA PRIMERA. FIRMA ELECTRÓNICA AVANZADA Y CONSERVACIÓN NOM-151.');
    $para('Las partes podrán firmar el presente Contrato, sus anexos, la Carátula, el Acta de Entrega y el Pagaré de manera autógrafa o mediante FIRMA ELECTRÓNICA a través de la plataforma de Cincel S.A.P.I. de C.V. (en adelante "CINCEL") o cualquier otro Prestador de Servicios de Certificación (PSC) autorizado por la Secretaría de Economía conforme a la NOM-151-SCFI-2016. EL CLIENTE reconoce y acepta que la firma electrónica realizada a través de CINCEL, o el PSC autorizado que VOLTIKA designe, cumple con los siguientes estándares legales:');
    $list([
        'a) Es firma electrónica conforme al artículo 89 del Código de Comercio, equivalente a la firma autógrafa para todos los efectos legales.',
        'b) Cumple con la NORMA OFICIAL MEXICANA NOM-151-SCFI-2016 "Requisitos que deben observarse para la conservación de mensajes de datos y digitalización de documentos".',
        'c) Es certificada por un Prestador de Servicios de Certificación (PSC) acreditado por la Secretaría de Economía.',
        'd) Genera CONSTANCIA DE CONSERVACIÓN DE MENSAJES DE DATOS (CCMD) que acredita la fecha cierta, integridad e inalterabilidad del documento firmado.',
        'e) Incorpora SELLO DIGITAL DE TIEMPO (timestamp fiable) que prueba la fecha y hora exacta de la firma.',
        'f) Incluye HUELLA DE AUDITORÍA completa con registros del proceso de firma, geolocalización, dirección IP, dispositivo utilizado y validación OTP.',
    ]);
    $para('EL CLIENTE manifiesta expresamente que la firma electrónica realizada conforme a los estándares anteriores tiene PLENO VALOR PROBATORIO equivalente al de la firma autógrafa, conforme a los artículos 89 a 95 del Código de Comercio, sin que pueda alegar desconocimiento, repudio o falta de identificación al respecto.');
    $para('Las Partes reconocen adicionalmente que los mecanismos de validación electrónica utilizados por VOLTIKA durante el proceso de contratación, pago y entrega, incluyendo códigos de verificación (OTP) enviados al teléfono registrado, registros digitales, captura de IP, geolocalización, confirmación de datos por correo electrónico y WhatsApp, evidencia fotográfica y cualquier interacción realizada a través de las plataformas autorizadas, forman parte integral del proceso de contratación, ejecución y cumplimiento del presente Contrato, teniendo validez jurídica y efectos probatorios plenos conforme a la legislación aplicable.');
    $para('La Constancia de Conservación de Mensajes de Datos emitida por el PSC podrá ser presentada por VOLTIKA como prueba documental plena en cualquier procedimiento judicial, administrativo, ante PROFECO, ante el SAT, ante autoridades vehiculares o ante procesadores de pago en caso de aclaraciones, contracargos o disputas.');

    $h3('TRIGÉSIMA SEGUNDA. JURISDICCIÓN, COMPETENCIA Y DOMICILIO CONVENCIONAL.');
    $para('Para todos los efectos derivados del presente Contrato, incluyendo su interpretación, ejecución, cumplimiento, incumplimiento, rescisión y demás consecuencias jurídicas, las partes acuerdan lo siguiente:');
    $para('(i) DOMICILIO CONVENCIONAL: Las partes designan como DOMICILIO CONVENCIONAL para todos los efectos del presente Contrato la Ciudad de México, específicamente las oficinas de VOLTIKA ubicadas en Jaime Balmes 71, despacho 101 C, Polanco I Sección, Miguel Hidalgo, C.P. 11510.');
    $para('(ii) JURISDICCIÓN EXPRESA: Las partes se someten EXPRESAMENTE a la jurisdicción y competencia de los tribunales competentes en la CIUDAD DE MÉXICO, renunciando expresamente a cualquier otro fuero que pudiera corresponderles por razón de sus domicilios presentes o futuros, lo anterior con fundamento en el artículo 1093 del Código de Comercio que permite la sumisión expresa de las partes a la jurisdicción de tribunales determinados.');
    $para('(iii) FUNDAMENTO DE LA SUMISIÓN: Esta cláusula se sustenta en: a) El artículo 1093 del Código de Comercio, que faculta a las partes en materia mercantil a someterse expresamente a la jurisdicción de tribunales determinados. b) El principio de autonomía de la voluntad de las partes en materia mercantil. c) La justificación operativa de VOLTIKA, cuyo domicilio fiscal y operativo principal se encuentra en la Ciudad de México. d) La naturaleza mercantil del Contrato y la operación de compraventa a plazos.');
    $para('(iv) RECONOCIMIENTO DE DERECHOS DEL CONSUMIDOR: Las partes reconocen que EL CLIENTE conserva en todo momento sus derechos previstos en la Ley Federal de Protección al Consumidor, incluyendo la facultad de presentar reclamaciones administrativas ante PROFECO en su entidad federativa, conforme al artículo 99 y demás aplicables de la LFPC, sin que ello afecte la sumisión expresa pactada para los efectos jurisdiccionales mercantiles.');
    $para('(v) ACEPTACIÓN INFORMADA: EL CLIENTE manifiesta haber sido informado de manera previa, clara y comprensible sobre esta cláusula de jurisdicción, aceptándola expresamente como parte de las condiciones de la operación, conforme al artículo 7 de la Ley Federal de Protección al Consumidor.');

    $h3('TRIGÉSIMA TERCERA. DECLARACIONES FINALES DE EL CLIENTE.');
    $para('Al firmar el presente Contrato, EL CLIENTE manifiesta y declara, bajo protesta de decir verdad, lo siguiente:');
    $list([
        '(i) Ha leído íntegramente el presente Contrato y la Carátula adjunta.',
        '(ii) Ha tenido oportunidad de hacer preguntas y aclarar dudas antes de firmar.',
        '(iii) Conoce y acepta el precio de contado, el precio total a plazo, el monto del enganche, el número y periodicidad de los pagos, el monto de cada pago y el monto total a pagar.',
        '(iv) Es titular o cuenta con autorización suficiente del medio de pago utilizado.',
        '(v) Reconoce que el cargo aparecerá identificado como "VOLTIKA MX" o similar en su estado de cuenta.',
        '(vi) Acepta los plazos de entrega establecidos (60 días estándar / 90 días extendido / hasta 30 días adicionales por fuerza mayor).',
        '(vii) Acepta el punto de entrega asignado registrado en la Carátula.',
        '(viii) Conoce y acepta que el vehículo cuenta con dispositivo GPS para fines de seguridad.',
        '(ix) Reconoce que VOLTIKA mantendrá la propiedad del vehículo hasta el pago total (reserva de dominio).',
        '(x) Acepta firmar el Pagaré al momento de la entrega como requisito indispensable.',
        '(xi) Acepta el procedimiento de aclaración previa a cualquier disputa de cargo.',
        '(xii) Acepta recibir notificaciones por correo electrónico, WhatsApp y/o SMS al número y correo registrados.',
        '(xiii) Reconoce que los datos técnicos de la operación (IP, geolocalización, OTP, fecha/hora) serán capturados para fines de validación y seguridad.',
        '(xiv) Conoce el régimen de carta factura y la inscripción ante REPUVE.',
        '(xv) Acepta que la cancelación posterior a la emisión del CFDI requiere proceso administrativo formal y no procede de manera automática.',
        '(xvi) Conoce sus derechos de rechazo al recibir, garantía, vicios ocultos y defectos graves.',
        '(xvii) Acepta que la entrega solo procederá si la persona que se presenta es la misma que celebró el contrato y supera la validación de identidad.',
        '(xviii) Reconoce expresamente que la firma electrónica realizada a través de Cincel u otro PSC autorizado conforme a la NOM-151-SCFI-2016 tiene pleno valor probatorio equivalente a la firma autógrafa, sin posibilidad de desconocimiento o repudio.',
        '(xix) Acepta el sometimiento a la jurisdicción de los tribunales competentes en la Ciudad de México conforme a la Cláusula Trigésima Segunda.',
    ]);
    $para('EL CLIENTE manifiesta que ha celebrado este Contrato de manera libre, voluntaria, informada y sin vicios del consentimiento.');
    $para('Leído que fue por las Partes el presente Contrato y enteradas de su contenido y alcance jurídico, lo firman para constancia el día de su fecha, por duplicado, en los espacios indicados para tal efecto, quedando un ejemplar en poder de VOLTIKA y otro en poder de EL CLIENTE.');

    // ─── FIRMAS ─────────────────────────────────────────────────────────
    $pdf->Ln(4);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 7, $enc('FIRMAS'), 0, 1, 'C');
    $pdf->Ln(2);

    // EL CLIENTE block
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 5, $enc('EL CLIENTE'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(0, 5, $enc('Nombre: ' . $nombre), 0, 1, 'C');

    if ($firmaImgPath && file_exists($firmaImgPath)) {
        $pdf->Image($firmaImgPath, $pdf->GetX() + 65, $pdf->GetY(), 60, 26);
        $pdf->Ln(28);
    } else {
        $pdf->Ln(8);
        $x = $pdf->GetX();
        $pdf->Line($x + 50, $pdf->GetY(), $x + 140, $pdf->GetY());
        $pdf->Ln(3);
    }
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->Cell(0, 4, $enc('Firma autógrafa o electrónica'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(0, 4, $enc('Folio del Contrato: ' . $folio), 0, 1, 'C');
    $pdf->Cell(0, 4, $enc('Fecha: ' . $fechaFirma), 0, 1, 'C');

    // VOLTIKA block
    $pdf->Ln(6);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 5, $enc('VOLTIKA — MTECH GEARS, S.A. DE C.V.'), 0, 1, 'C');
    $pdf->Ln(8);
    $x = $pdf->GetX();
    $pdf->Line($x + 50, $pdf->GetY(), $x + 140, $pdf->GetY());
    $pdf->Ln(3);
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->Cell(0, 4, $enc('Representante Legal'), 0, 1, 'C');

    $pdf->Ln(4);
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->MultiCell(0, 3.5, $enc('Este documento ha sido firmado electrónicamente y será certificado conforme a la NOM-151-SCFI-2016 mediante Cincel S.A.P.I. de C.V. o el PSC autorizado que VOLTIKA designe. La firma electrónica avanzada tiene la misma validez jurídica que una firma autógrafa conforme al artículo 89 del Código de Comercio.'), 0, 'C');
}

/**
 * Fallback: minimal text PDF (no FPDF library)
 */
function generateMinimalPDF($filepath, $nombre, $email, $telefono, $modelo, $color,
                             $ciudad, $estado, $cp, $precioContado, $enganche,
                             $montoFinanciado, $numPagos, $pagoSemanal,
                             $totalIntereses, $montoTotalPagar, $folio, $fechaFirma,
                             $curp, $domicilio, $firmaBase64) {

    $fmt = function($n) { return '$' . number_format($n, 2) . ' MXN'; };

    $content  = "CARATULA DE COMPRAVENTA A PLAZOS - MTECH GEARS S.A. DE C.V.\n";
    $content .= "Folio: {$folio} | Fecha: {$fechaFirma}\n\n";
    $content .= "CLIENTE: {$nombre}\n";
    $content .= "Domicilio: {$domicilio}\n";
    $content .= "CURP: " . ($curp ?: 'Por confirmar') . "\n";
    $content .= "Email: {$email} | Tel: +52 {$telefono}\n\n";
    $content .= "MOTOCICLETA: VOLTIKA {$modelo} - Color: {$color} - 2026\n\n";
    $content .= "PRECIO: " . $fmt($precioContado) . "\n";
    $content .= "Enganche: " . $fmt($enganche) . "\n";
    $content .= "Monto Financiado: " . $fmt($montoFinanciado) . "\n";
    $content .= "Pagos: {$numPagos} semanales de " . $fmt($pagoSemanal) . "\n";
    $content .= "Total Intereses: " . $fmt($totalIntereses) . "\n";
    $content .= "MONTO TOTAL A PAGAR: " . $fmt($montoTotalPagar) . "\n\n";
    $content .= "FIRMA: " . ($firmaBase64 ? "[CAPTURADA]" : "[PENDIENTE]") . "\n";

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

    $written = file_put_contents($filepath, $pdf);
    if ($written === false || $written === 0) {
        $tmpPath = sys_get_temp_dir() . '/' . basename($filepath);
        $written = file_put_contents($tmpPath, $pdf);
        if ($written > 0) return $tmpPath;
        throw new Exception('Cannot write PDF file');
    }
    return $filepath;
}

/**
 * Send PDF to Cincel API for NOM-151 timestamp
 */
function sendToCincel($apiUrl, $email, $password, $pdfPath, $signerName, $signerEmail) {

    $authHeader = 'Authorization: Basic ' . base64_encode($email . ':' . $password);

    if (!file_exists($pdfPath) || filesize($pdfPath) === 0) {
        throw new Exception('PDF file not found or empty: ' . $pdfPath);
    }
    $pdfContent = file_get_contents($pdfPath);
    if ($pdfContent === false || strlen($pdfContent) === 0) {
        throw new Exception('Failed to read PDF file: ' . $pdfPath);
    }
    $hash = hash('sha256', $pdfContent);
    error_log("Cincel: PDF size=" . strlen($pdfContent) . " hash={$hash} path={$pdfPath}");

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
    curl_close($ch);

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
    }

    if ($tsCode !== 200 && $tsCode !== 202) {
        return [
            'status'   => 'pending',
            'hash'     => $hash,
            'message'  => "PDF generated. Timestamp pending (HTTP {$tsCode}).",
        ];
    }

    // Try to download .asn1
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
        $asn1Path = dirname($pdfPath) . '/' . $hash . '.asn1';
        file_put_contents($asn1Path, $asn1Response);
    }

    return [
        'status'   => ($asn1Code === 200) ? 'timestamped' : 'processing',
        'hash'     => $hash,
        'asn1Ready'=> ($asn1Code === 200),
        'asn1File' => $asn1Path ? basename($asn1Path) : null,
        'message'  => ($asn1Code === 200)
            ? 'NOM-151 timestamp created successfully.'
            : 'Timestamp requested, certificate pending.'
    ];
}

/**
 * Send contract PDF to customer via email
 */
function sendContractEmail($toEmail, $nombre, $modelo, $pdfPath) {
    $subject = 'Tu contrato Voltika - ' . $modelo;

    $htmlBody = '
    <div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">
        <div style="text-align:center;margin-bottom:20px;">
            <h1 style="color:#1a3a5c;margin:0;">Voltika</h1>
            <p style="color:#039fe1;font-size:14px;margin:4px 0;">Movilidad electrica inteligente</p>
        </div>

        <h2 style="color:#333;">Hola ' . htmlspecialchars($nombre) . ',</h2>

        <p style="font-size:15px;color:#555;line-height:1.6;">
            Adjunto encontraras tu <strong>Caratula de Credito</strong> para tu motocicleta
            <strong>Voltika ' . htmlspecialchars($modelo) . '</strong>.
        </p>

        <div style="background:#E8F4FD;border-radius:10px;padding:16px;margin:20px 0;border-left:4px solid #039fe1;">
            <p style="margin:0;font-size:14px;color:#1a3a5c;">
                <strong>Importante:</strong> Este documento forma parte integral de tu contrato de credito.
                Conservalo para tu referencia.
            </p>
        </div>

        <p style="font-size:14px;color:#555;line-height:1.6;">
            Tu firma ha sido registrada y sera certificada con <strong>NOM-151</strong> mediante Cincel Digital
            para garantizar su validez legal.
        </p>

        <div style="background:#F5F5F5;border-radius:8px;padding:14px;margin:20px 0;">
            <p style="margin:0 0 8px;font-size:13px;color:#888;">Proximos pasos:</p>
            <p style="margin:0 0 4px;font-size:14px;color:#333;">&#10003; Un asesor Voltika te contactara en maximo <strong>48 horas</strong></p>
            <p style="margin:0 0 4px;font-size:14px;color:#333;">&#10003; Confirmaremos tu punto de entrega</p>
            <p style="margin:0;font-size:14px;color:#333;">&#10003; Coordinaremos fecha y horario de entrega</p>
        </div>

        <p style="font-size:13px;color:#888;margin-top:30px;text-align:center;">
            Si tienes alguna duda, contactanos por WhatsApp al +52 (55) 79440982<br>
            o escribe a <a href="mailto:legal@voltika.mx" style="color:#039fe1;">legal@voltika.mx</a>
        </p>

        <div style="text-align:center;margin-top:20px;padding-top:20px;border-top:1px solid #eee;">
            <p style="font-size:12px;color:#999;">MTECH GEARS S.A. DE C.V. | voltika.mx</p>
        </div>
    </div>';

    // Use sendMail from config.php (PHPMailer with attachment)
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) require_once $autoload;

    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->SMTPAuth   = true;
            $mail->Host       = SMTP_HOST;
            $mail->Port       = SMTP_PORT;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $mail->setFrom(SMTP_USER, 'Voltika Mexico');
            $mail->addAddress($toEmail, $nombre);
            $mail->addBCC('redes@voltika.com.mx');
            $mail->CharSet    = 'UTF-8';
            $mail->Encoding   = 'base64';
            $mail->isHTML(true);
            $mail->Subject    = $subject;
            $mail->Body       = $htmlBody;
            $mail->AltBody    = strip_tags($htmlBody);
            $mail->addAttachment($pdfPath, 'Contrato_Voltika_' . preg_replace('/[^a-zA-Z0-9]/', '_', $nombre) . '.pdf');
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log('Contract email PHPMailer error: ' . $e->getMessage());
            return false;
        }
    }

    // Fallback: PHP mail() without attachment
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Voltika Mexico <" . SMTP_USER . ">\r\n";
    return @mail($toEmail, $subject, $htmlBody, $headers);
}
