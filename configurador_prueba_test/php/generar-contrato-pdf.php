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
 * Generate Contrato de Compraventa a Plazos pages (appended to Carátula PDF)
 */
function generateContratoPages($pdf, $enc, $folio, $nombre, $firmaImgPath, $fechaFirma) {

    $pdf->AddPage();

    // Header with Folio
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, $enc('CONTRATO DE COMPRAVENTA A PLAZOS'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 7);
    $pdf->Cell(0, 5, $enc('Folio: ' . $folio), 0, 1, 'R');
    $pdf->Ln(3);

    // Opening paragraph
    $pdf->SetFont('Arial', '', 7);
    $pdf->MultiCell(0, 3.5, $enc('CON RESERVA DE DOMINIO QUE CELEBRAN POR UNA PARTE MTECH GEARS, S.A. DE C.V. (EN LO SUCESIVO VOLTIKA); Y POR LA OTRA PARTE POR PROPIO DERECHO LA PERSONA FISICA CUYOS DATOS GENERALES SE ENCONTRARAN EN LA CARATULA DEL PRESENTE CONTRATO, MISMA QUE FORMA PARTE INTEGRAL DEL MISMO (EN LO SUCESIVO CLIENTE); Y EN CONJUNTO CON VOLTIKA SE LES DENOMINARA LAS PARTES AL TENOR DE LAS SIGUIENTES DECLARACIONES, DEFINICIONES Y CLAUSULAS:'));
    $pdf->Ln(3);

    // DECLARACIONES
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 6, 'DECLARACIONES', 0, 1);
    $pdf->SetFont('Arial', '', 7);
    $decls = [
        'Declara EL CLIENTE que es una persona fisica con capacidad juridica y economica para obligarse.',
        'Domicilio y medios de comunicacion senalados en la caratula del Contrato.',
        'EL CLIENTE reconoce que el numero telefonico registrado sera considerado como medio de autenticacion valido.',
        'Documentos requeridos: identificacion oficial vigente y comprobante de domicilio (no mayor a 3 meses).',
        'Aviso de Privacidad disponible en: https://www.voltika.mx/docs/privacidad_2026',
        'EL CLIENTE ha recibido, revisado y aceptado la caratula de la operacion de compraventa a plazos.',
        'VOLTIKA es una sociedad mexicana constituida bajo legislacion aplicable, con domicilio en Jaime Balmes 71, despacho 101 C, Polanco I Seccion, Miguel Hidalgo, C.P. 11510, Ciudad de Mexico.',
    ];
    foreach ($decls as $d) {
        $pdf->MultiCell(0, 3.5, $enc('- ' . $d));
        $pdf->Ln(1);
    }

    // DEFINICIONES
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 6, 'DEFINICIONES', 0, 1);
    $pdf->SetFont('Arial', '', 7);
    $defs = [
        'Solicitud: Documento generado por VOLTIKA y firmado por EL CLIENTE, en el que se establecen en forma especifica los datos generales de EL CLIENTE. Forma parte integrante del presente Contrato.',
        'Caratula: Documento generado por VOLTIKA y firmado por EL CLIENTE, que contiene los elementos esenciales de la operacion comercial de compraventa a plazos, incluyendo la identificacion del producto, precio de contado, precio total a plazo, enganche, saldo pendiente de pago, numero y periodicidad de pagos, monto por pago y monto total a pagar. Forma parte integrante del presente Contrato.',
        'Saldo pendiente de pago o Saldo a Plazo: Parte del precio total a plazo pendiente de pago por EL CLIENTE conforme a la Caratula y al plan de pagos aceptado.',
        'Saldo Insoluto: Monto pendiente de pago conforme al plan de pagos establecido en la Caratula y, en su caso, en la Tabla de Pagos.',
        'Solicitud de Compra a Plazos: Documento generado por VOLTIKA y firmado por EL CLIENTE que contiene sus datos de identificacion personal, laboral y financiera para evaluar la viabilidad comercial de la operacion.',
        'Tabla de Pagos: Documento puesto a disposicion de EL CLIENTE por medios fisicos o electronicos, que refleja el numero total de pagos, su periodicidad, el monto de cada pago y, en su caso, la fecha estimada de primer pago. Forma parte integrante del presente Contrato.',
        'Autorizacion para consulta y monitoreo de Informacion Crediticia: Autorizacion otorgada por EL CLIENTE a VOLTIKA para solicitar, obtener o verificar informacion crediticia. Caracter irrevocable, vigente por tres anos o mientras exista relacion juridica.',
        'Validacion electronica: Mecanismo de autenticacion mediante codigos de seguridad (OTP) enviados al numero telefonico registrado por EL CLIENTE, asi como confirmaciones digitales, registros electronicos, evidencia fotografica y demas mensajes de datos generados durante la contratacion, pago y entrega, con efectos juridicos conforme a la legislacion aplicable.',
    ];
    foreach ($defs as $d) {
        $pdf->MultiCell(0, 3.5, $enc('- ' . $d));
        $pdf->Ln(1);
    }

    // CLAUSULAS
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 6, 'CLAUSULAS', 0, 1);

    $clausulas = [
        ['PRIMERA. OBJETO', 'Compraventa a plazos de una motocicleta electrica. VOLTIKA vende a EL CLIENTE el vehiculo descrito en la Caratula, reservandose la propiedad del mismo hasta el pago total del precio a plazo pactado. EL CLIENTE reconoce que la entrega del vehiculo podra realizarse mediante validacion electronica (OTP, registros digitales, evidencia fotografica).'],
        ['SEGUNDA. DESTINO', 'La presente operacion tiene como finalidad exclusiva la adquisicion del producto descrito en la Caratula.'],
        ['TERCERA. PLAZO DEL CONTRATO', 'Segun numero total de pagos y periodicidad en la Caratula/Tabla de Pagos.'],
        ['CUARTA. DOMICILIACION', 'EL CLIENTE autoriza cargos recurrentes a tarjeta bancaria o cuenta registrada. La cancelacion o rechazo de cargo no extingue la obligacion de pago.'],
        ['QUINTA. ASIGNACIÓN DE LA UNIDAD', 'La entrega y asignacion del vehiculo estaran sujetas a la disponibilidad de inventario, modelo, color y al proceso de entrega establecido en la Caratula y en el presente Contrato.'],
        ['SEXTA. PAGO DE ENGANCHE', 'El pago inicial constituye deposito en garantia para reserva del vehiculo. Si EL CLIENTE no continua tras aprobacion, VOLTIKA podra retener gastos administrativos. El pago del enganche por medios electronicos constituye aceptacion expresa.'],
        ['SEXTA BIS. PAGOS DE LA OPERACIÓN A PLAZOS', 'EL CLIENTE pagara a VOLTIKA el saldo pendiente de pago mediante el numero total de pagos, con la periodicidad y por el monto establecido en la Caratula y en la Tabla de Pagos. Si fecha de pago cae en dia inhabil, el pago sera el dia habil inmediato anterior.'],
        ['SEPTIMA. PRECIO TOTAL A PLAZO', 'EL CLIENTE reconoce que el monto total a pagar incluye el precio del vehiculo y el costo comercial derivado de la venta a plazos. EL CLIENTE manifiesta que conoce y acepta el precio de contado, el precio total a plazo, la periodicidad de los pagos, el importe de cada uno de ellos y el monto total a pagar.'],
        ['OCTAVA. INCUMPLIMIENTO DE PAGO', 'VOLTIKA podra ejercer acciones de cobro, incluyendo vencimiento anticipado. Cargos por atraso conforme a politicas vigentes de cobranza.'],
        ['NOVENA. MEDIOS DE ACREDITACION Y REGISTRO', 'VOLTIKA conservara registros fisicos y electronicos (mensajes de datos, IPs, OTP, evidencia fotografica, firma electronica). Constituiran evidencia suficiente.'],
        ['DECIMA. LUGAR Y FORMA DE PAGO', 'Medios autorizados: cargos automaticos, transferencias electronicas, pagos referenciados, tiendas de conveniencia. EL CLIENTE debe mantener forma de pago vigente.'],
        ['DECIMA PRIMERA. INFORMACION DE PAGOS', 'VOLTIKA pondra a disposicion informacion de pagos por medios electronicos o portal de cliente.'],
        ['DECIMA SEGUNDA. PAGOS ANTICIPADOS', 'Sin penalizacion, conforme a medios autorizados.'],
        ['DECIMA TERCERA. OBLIGACIONES', 'Mantener vehiculo en condiciones adecuadas; permitir inspecciones previo aviso; en caso de incumplimiento, aceptar devolucion voluntaria.'],
        ['DECIMA TERCERA BIS. VALIDACION DE INFORMACION', 'VOLTIKA podra solicitar documentacion adicional. Informacion falsa podra dar lugar a cancelacion o restriccion de la operacion.'],
        ['DECIMA CUARTA. RESERVA DE DOMINIO Y RECUPERACIÓN', 'La propiedad de la motocicleta permanecera en favor de VOLTIKA hasta el pago total del precio a plazo. En caso de incumplimiento, VOLTIKA podra ejercer acciones legales para la recuperacion del vehiculo. EL CLIENTE autoriza el uso de tecnologias de geolocalizacion y control remoto para fines de seguridad y recuperacion del bien.'],
        ['DECIMA CUARTA BIS. GARANTIA PRENDARIA', 'En garantia del pago exacto y oportuno del precio a plazos y demas obligaciones derivadas del presente Contrato, EL CLIENTE constituye prenda en primer lugar a favor de VOLTIKA sobre el bien objeto de la presente compraventa a plazos.'],
        ['DECIMA QUINTA. TIEMPOS DE ENTREGA', 'Plazo estimado de hasta 28 dias naturales a partir de firma del Contrato. La validacion mediante OTP, firma electronica y evidencia digital constituira constancia de entrega.'],
        ['DECIMA SEXTA. POSESION DEL VEHICULO', 'EL CLIENTE conserva posesion como depositario.'],
        ['DECIMA SEPTIMA. OBLIGADO SOLIDARIO', 'Se constituye obligado solidario conforme a los datos en el Contrato y la Caratula.'],
        ['DECIMA OCTAVA. OPCIONES DE PROTECCION', 'VOLTIKA podra ofrecer seguros o mecanismos de proteccion opcionales.'],
        ['DECIMA NOVENA. RESPONSABILIDAD SOBRE EL VEHICULO', 'EL CLIENTE es responsable del uso, resguardo y conservacion. Dano, perdida, robo o siniestro no libera de obligaciones de pago.'],
        ['VIGESIMA. IMPUESTOS', 'EL CLIENTE pagara impuestos, derechos u obligaciones fiscales generados por el Contrato.'],
        ['VIGESIMA PRIMERA. CAUSAS DE VENCIMIENTO ANTICIPADO', 'Falta de pago, incumplimiento, informacion falsa, venta no autorizada del vehiculo, siniestro no notificado, incumplimiento de otros contratos con VOLTIKA. Cancelacion sin responsabilidad dentro de 5 dias habiles si no ha recibido vehiculo.'],
        ['VIGESIMA SEGUNDA. COMPENSACION', 'VOLTIKA autorizada para cargar contra cuenta de EL CLIENTE el monto de pagos sin necesidad de requerimiento.'],
        ['VIGESIMA TERCERA. CESION DE LOS DERECHOS DE COBRO', 'VOLTIKA podra transmitir, ceder, negociar o titularizar los derechos de cobro derivados de la presente operacion de compraventa a plazos. EL CLIENTE no podra ceder sus derechos u obligaciones sin el consentimiento previo y por escrito de VOLTIKA.'],
        ['VIGESIMA CUARTA. TERMINACION ANTICIPADA POR CAUSA JUSTIFICADA', 'VOLTIKA podra dar por terminado anticipadamente el presente Contrato unicamente por causa justificada, incluyendo el incumplimiento de EL CLIENTE o la imposibilidad legal o material de continuar con la operacion.'],
        ['VIGESIMA QUINTA. DOMICILIOS', 'EL CLIENTE acepta notificaciones por correo electronico, SMS o WhatsApp. Cambio de domicilio con 10 dias habiles de anticipacion.'],
        ['VIGESIMA SEXTA. TERMINACION DEL CONTRATO', 'VOLTIKA: 15 dias de anticipacion. EL CLIENTE: surte efectos al dia habil siguiente si no hay adeudos. Constancia de terminacion dentro de 10 dias habiles tras pago.'],
        ['VIGESIMA SEPTIMA. JURISDICCION Y COMPETENCIA', 'Tribunales en la Ciudad de Mexico o del domicilio de EL CLIENTE, a eleccion de la parte actora.'],
        ['VIGESIMA OCTAVA. FIRMA ELECTRONICA', 'Firma electronica simple o avanzada mediante plataforma designada por VOLTIKA. Validaciones OTP, registros digitales y confirmaciones tienen validez juridica. EL CLIENTE tuvo acceso previo al documento.'],
    ];

    foreach ($clausulas as $cl) {
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->Cell(0, 4, $enc($cl[0] . '.'), 0, 1);
        $pdf->SetFont('Arial', '', 7);
        $pdf->MultiCell(0, 3.5, $enc($cl[1]));
        $pdf->Ln(1.5);
    }

    // ── Signature section at the end ──────────────────────────────────────
    $pdf->Ln(6);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 6, $enc('FIRMA ELECTRÓNICA DEL CLIENTE'), 0, 1, 'C');
    $pdf->Ln(2);

    // Signature image
    if ($firmaImgPath && file_exists($firmaImgPath)) {
        $pdf->Image($firmaImgPath, $pdf->GetX() + 50, $pdf->GetY(), 60, 30);
        $pdf->Ln(35);
    } else {
        $pdf->Cell(0, 15, '', 0, 1);
        $x = $pdf->GetX();
        $pdf->Line($x + 40, $pdf->GetY(), $x + 150, $pdf->GetY());
        $pdf->Ln(3);
    }

    // Name and Folio below signature
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 5, $enc('Nombre: ' . $nombre), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(0, 5, $enc('Folio del Contrato: ' . $folio), 0, 1, 'C');
    $pdf->Cell(0, 5, $enc('Fecha: ' . $fechaFirma), 0, 1, 'C');

    $pdf->Ln(6);
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->MultiCell(0, 3.5, $enc('Este documento ha sido firmado electronicamente y sera certificado con NOM-151 mediante Cincel Digital. La firma electronica tiene la misma validez juridica que una firma autografa conforme al Codigo de Comercio.'));
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
