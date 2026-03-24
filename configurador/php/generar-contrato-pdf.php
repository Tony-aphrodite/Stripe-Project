<?php
/**
 * Voltika - Generar Contrato PDF (Carátula de Crédito) + Cincel NOM-151
 *
 * Template: "Carátula de contrato VF Marzo 22" from legal team
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
    'ok'        => true,
    'pdf'       => $pdfPath ? basename($pdfPath) : null,
    'cincel'    => $cincelResult,
    'emailSent' => $emailSent,
    'timestamp' => date('c')
]);


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
 * Generate Carátula de Crédito PDF with FPDF
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

    // Title
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, $enc('CARÁTULA DE CRÉDITO'), 0, 1, 'C');
    $pdf->Ln(3);

    // Company info table
    $pdf->SetFont('Arial', 'B', 9);
    $w1 = 45; $w2 = 145;
    $h = 6;

    $companyRows = [
        ['Denominacion', 'MTECH GEARS S.A. DE C.V.'],
        ['RFC', 'MGE230316KA2'],
        ['Domicilio', 'Jaime Balmes 71 Int 101, Despacho C, Colonia Polanco, Miguel Hidalgo, Ciudad de Mexico, CDMX C.P. 11510, Mexico'],
        ['Telefonos', '(55) 55579619 y WhatsApp +52 (55) 79440982'],
        ['Correo electronico', 'legal@voltika.mx'],
        ['Folio de contrato', $folio],
        ['Fecha', $fechaFirma],
        ['Localidad', 'Ciudad de Mexico, CDMX'],
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
    $pdf->Cell(0, 8, $enc('DATOS DEL CLIENTE CONSUMIDOR'), 0, 1);
    $pdf->SetFont('Arial', '', 8);

    $clientRows = [
        ['Nombre', $nombre],
        ['Domicilio', $domicilio],
        ['CURP', $curp ?: 'Por confirmar'],
        ['Correo electronico', $email],
        ['Telefono (validado mediante OTP)', '+52 ' . $telefono],
    ];
    foreach ($clientRows as $row) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell($w1, $h, $enc($row[0] . ':'), 1);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell($w2, $h, $enc($row[1]), 1, 1);
    }

    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->MultiCell(0, 4, $enc('El numero telefonico senalado sera el medio de identificacion de EL CLIENTE para efectos de validacion, autorizacion y entrega.'));
    $pdf->Ln(2);

    // CARACTERISTICAS DE LA MOTOCICLETA
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, $enc('CARACTERÍSTICAS DE LA MOTOCICLETA'), 0, 1);

    $motoRows = [
        ['Marca', 'VOLTIKA'],
        ['Submarca', 'TROMOX'],
        ['Tipo o version', $modelo],
        ['Color', $color],
        ['Ano-modelo', '2026'],
    ];
    foreach ($motoRows as $row) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell($w1, $h, $enc($row[0] . ':'), 1);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell($w2, $h, $enc($row[1]), 1, 1);
    }

    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->MultiCell(0, 4, $enc('El numero de serie (VIN/NIV) sera asignado y confirmado en el acta de entrega correspondiente.'));

    // DETALLE DEL VEHICULO Y PRECIO
    $pdf->Ln(3);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, $enc('DETALLE DEL VEHÍCULO Y PRECIO'), 0, 1);

    $precioRows = [
        ['Precio del vehiculo (Sin IVA)', $fmt($precioSinIVA)],
        ['IVA del vehiculo (16%)', $fmt($ivaVehiculo)],
        ['Precio total del vehiculo', $fmt($precioContado)],
    ];
    foreach ($precioRows as $row) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(80, $h, $enc($row[0] . ':'), 1);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(110, $h, $enc($row[1]), 1, 1, 'R');
    }
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->Cell(0, 5, $enc('El precio incluye costos logisticos, traslado y entrega'), 0, 1);

    // TOTAL DEL VEHICULO Y ACCESORIOS
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, $enc('TOTAL DEL VEHÍCULO Y ACCESORIOS'), 0, 1);

    $totalRows = [
        ['Total del vehiculo', $fmt($precioContado)],
        ['Total de accesorios', '$0.00'],
        ['Total del vehiculo y accesorios', $fmt($precioContado)],
    ];
    foreach ($totalRows as $row) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(80, $h, $enc($row[0] . ':'), 1);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(110, $h, $enc($row[1]), 1, 1, 'R');
    }

    // ── Page 2: Condiciones + Legal ───────────────────────────────────────
    $pdf->AddPage();

    // CONDICIONES DE PAGO
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, $enc('CONDICIONES DE PAGO Y FINANCIAMIENTO'), 0, 1);

    $condRows = [
        ['Enganche', $fmt($enganche)],
        ['Monto Financiado', $fmt($montoFinanciado)],
        ['Numero total de Pagos', $numPagos],
        ['Periodicidad', 'Semanal'],
        ['Monto por pago semanal', $fmt($pagoSemanal)],
        ['Total de Intereses y cargos', $fmt($totalIntereses)],
        ['MONTO TOTAL A PAGAR', $fmt($montoTotalPagar)],
    ];
    foreach ($condRows as $i => $row) {
        $isLast = ($i === count($condRows) - 1);
        $pdf->SetFont('Arial', $isLast ? 'B' : 'B', $isLast ? 9 : 8);
        $pdf->Cell(80, $h, $enc($row[0] . ':'), 1);
        $pdf->SetFont('Arial', $isLast ? 'B' : '', $isLast ? 9 : 8);
        $pdf->Cell(110, $h, $enc(is_numeric($row[1]) ? strval($row[1]) : $row[1]), 1, 1, 'R');
    }

    $pdf->Ln(3);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(0, 5, $enc('ACTIVACIÓN DE PAGOS:'), 0, 1);
    $pdf->SetFont('Arial', '', 7);
    $pdf->Cell(0, 4, $enc('Fecha Estimada primer pago: A partir de la fecha de entrega del vehiculo'), 0, 1);
    $pdf->Cell(0, 4, $enc('El pago se activara unicamente al momento de la entrega del vehiculo.'), 0, 1);

    // LEGAL SECTIONS
    $pdf->Ln(4);
    $legalSections = [
        'VALIDACIÓN ELECTRÓNICA, PAGO Y ENTREGA' => 'EL CLIENTE reconoce que su identidad sera validada mediante mecanismos electronicos, incluyendo codigos de seguridad (OTP) enviados a su numero telefonico registrado. Asimismo, acepta que la confirmacion de sus datos personales y de la compra, incluyendo apellido y modelo adquirido, junto con la validacion del codigo OTP, constituira: (i) confirmacion de identidad, (ii) manifestacion expresa de voluntad, (iii) autorizacion para la entrega de la motocicleta, y (iv) aceptacion plena de la recepcion del producto. La entrega se considerara realizada en el momento en que el sistema registre dicha validacion electronica, constituyendo cumplimiento de la obligacion de entrega por parte de VOLTIKA. EL CLIENTE reconoce que dicha validacion tendra efectos legales equivalentes a una firma autografa conforme al Codigo de Comercio y podra ser utilizada como prueba en procesos de aclaracion, contracargos o disputas ante instituciones financieras o emisores de tarjetas.',

        'NATURALEZA DEL FINANCIAMIENTO' => 'El financiamiento otorgado forma parte de una operacion comercial de compraventa a plazo. VOLTIKA no es una institucion de credito ni entidad financiera y no esta sujeta a supervision de la Comision Nacional Bancaria y de Valores (CNBV). EL CLIENTE reconoce que el credito tiene caracter mercantil y privado.',

        'RESERVA DE DOMINIO Y RECUPERACIÓN' => 'La propiedad de la motocicleta permanecera en favor de VOLTIKA hasta el pago total del credito. En caso de incumplimiento, VOLTIKA podra ejercer acciones legales para la recuperacion del vehiculo. EL CLIENTE autoriza el uso de tecnologias de geolocalizacion y control remoto para fines de seguridad y recuperacion del bien.',
    ];

    foreach ($legalSections as $title => $text) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(0, 6, $enc($title), 0, 1);
        $pdf->SetFont('Arial', '', 7);
        $pdf->MultiCell(0, 4, $enc($text));
        $pdf->Ln(2);
    }

    // Privacy notice
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->MultiCell(0, 4, $enc('Previo a la celebracion del presente contrato, el Distribuidor dio a conocer a EL CLIENTE el aviso de privacidad para el tratamiento de sus datos personales.'));

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
    $pdf->MultiCell(0, 4, $enc('La presente caratula forma parte integral del contrato de credito, terminos y condiciones, pagare y acta de entrega. Su firma, ya sea autografa o electronica, implica aceptacion total de los mismos.'));

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
 * Generate Contrato de Financiamiento pages (appended to Carátula PDF)
 */
function generateContratoPages($pdf, $enc, $folio, $nombre, $firmaImgPath, $fechaFirma) {

    $pdf->AddPage();

    // Header with Folio
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, $enc('CONTRATO DE APERTURA DE CRÉDITO'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 7);
    $pdf->Cell(0, 5, $enc('Folio: ' . $folio), 0, 1, 'R');
    $pdf->Ln(3);

    // Opening paragraph
    $pdf->SetFont('Arial', '', 7);
    $pdf->MultiCell(0, 3.5, $enc('QUE CELEBRAN POR UNA PARTE MTECH GEARS, S.A. DE C.V. (EN LO SUCESIVO VOLTIKA); Y POR LA OTRA PARTE POR PROPIO DERECHO LA PERSONA FISICA CUYOS DATOS GENERALES SE ENCONTRARAN EN LA CARATULA DEL PRESENTE CONTRATO, MISMA QUE FORMA PARTE INTEGRAL DEL MISMO (EN LO SUCESIVO CLIENTE); Y EN CONJUNTO CON VOLTIKA SE LES DENOMINARA LAS PARTES AL TENOR DE LAS SIGUIENTES DECLARACIONES, DEFINICIONES Y CLAUSULAS:'));
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
        'EL CLIENTE ha recibido, revisado y aceptado la caratula del contrato de credito.',
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
        'Solicitud: Documento con datos generales de EL CLIENTE.',
        'Caratula: Documento con elementos esenciales de la operacion (producto, precio, enganche, monto financiado, pagos, monto total).',
        'Monto de Credito: Limite de credito autorizado por VOLTIKA.',
        'Saldo Insoluto: Monto pendiente de pago conforme al plan de pagos.',
        'Tabla de Pagos: Documento con numero total de pagos, periodicidad, monto de cada pago, fecha estimada de primer pago.',
        'Autorizacion para consulta de Informacion Crediticia: Autorizacion irrevocable vigente por tres anos o mientras exista relacion juridica.',
        'Validacion electronica: Autenticacion mediante codigos OTP, confirmaciones digitales, registros electronicos, evidencia fotografica y mensajes de datos.',
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
        ['PRIMERA. OBJETO', 'Otorgamiento de un credito simple. EL CLIENTE reconoce que la entrega del vehiculo podra realizarse mediante validacion electronica (OTP, registros digitales, evidencia fotografica).'],
        ['SEGUNDA. DESTINO', 'El credito se destinara exclusivamente a la adquisicion del producto descrito en la Caratula.'],
        ['TERCERA. PLAZO DEL CONTRATO', 'Segun numero total de pagos y periodicidad en la Caratula/Tabla de Pagos.'],
        ['CUARTA. DOMICILIACION', 'EL CLIENTE autoriza cargos recurrentes a tarjeta bancaria o cuenta registrada. La cancelacion o rechazo de cargo no extingue la obligacion de pago.'],
        ['QUINTA. DISPOSICIONES DEL CREDITO', 'Sujeta a asignacion de unidad especifica conforme a disponibilidad.'],
        ['SEXTA. PAGO DE ENGANCHE', 'El pago inicial constituye deposito en garantia para reserva del vehiculo. Si EL CLIENTE no continua tras aprobacion, VOLTIKA podra retener gastos administrativos. El pago del enganche por medios electronicos constituye aceptacion expresa.'],
        ['SEXTA BIS. PAGOS DEL CREDITO', 'Pagos conforme a la Caratula y Tabla de Pagos. Si fecha de pago cae en dia inhabil, el pago sera el dia habil inmediato anterior.'],
        ['SEPTIMA. CARGOS DEL FINANCIAMIENTO', 'El monto total a pagar incluye precio del vehiculo y cargos de financiamiento. VOLTIKA no se obliga a desglosar tasa de interes o CAT distinto al contenido en la Caratula.'],
        ['OCTAVA. INCUMPLIMIENTO DE PAGO', 'VOLTIKA podra ejercer acciones de cobro, incluyendo vencimiento anticipado. Cargos por atraso conforme a politicas vigentes de cobranza.'],
        ['NOVENA. MEDIOS DE ACREDITACION Y REGISTRO', 'VOLTIKA conservara registros fisicos y electronicos (mensajes de datos, IPs, OTP, evidencia fotografica, firma electronica). Constituiran evidencia suficiente.'],
        ['DECIMA. LUGAR Y FORMA DE PAGO', 'Medios autorizados: cargos automaticos, transferencias electronicas, pagos referenciados, tiendas de conveniencia. EL CLIENTE debe mantener forma de pago vigente.'],
        ['DECIMA PRIMERA. INFORMACION DE PAGOS', 'VOLTIKA pondra a disposicion informacion de pagos por medios electronicos o portal de cliente.'],
        ['DECIMA SEGUNDA. PAGOS ANTICIPADOS', 'Sin penalizacion, conforme a medios autorizados.'],
        ['DECIMA TERCERA. OBLIGACIONES', 'Mantener vehiculo en condiciones adecuadas; permitir inspecciones previo aviso; en caso de incumplimiento, aceptar devolucion voluntaria.'],
        ['DECIMA TERCERA BIS. VALIDACION DE INFORMACION', 'VOLTIKA podra solicitar documentacion adicional. Informacion falsa podra dar lugar a cancelacion o restriccion del credito.'],
        ['DECIMA CUARTA. RESERVA DE DOMINIO', 'VOLTIKA mantiene la propiedad de la motocicleta hasta liquidacion total. El vehiculo podra contar con dispositivos de geolocalizacion y monitoreo. En caso de incumplimiento, VOLTIKA podra implementar restricciones operativas y recuperar el vehiculo.'],
        ['DECIMA CUARTA BIS. GARANTIA PRENDARIA', 'EL CLIENTE constituye prenda en primer lugar a favor de VOLTIKA sobre el bien adquirido.'],
        ['DECIMA QUINTA. TIEMPOS DE ENTREGA', 'Plazo estimado de hasta 28 dias naturales a partir de firma del Contrato. La validacion mediante OTP, firma electronica y evidencia digital constituira constancia de entrega.'],
        ['DECIMA SEXTA. POSESION DEL VEHICULO', 'EL CLIENTE conserva posesion como depositario.'],
        ['DECIMA SEPTIMA. OBLIGADO SOLIDARIO', 'Se constituye obligado solidario conforme a los datos en el Contrato y la Caratula.'],
        ['DECIMA OCTAVA. OPCIONES DE PROTECCION', 'VOLTIKA podra ofrecer seguros o mecanismos de proteccion opcionales.'],
        ['DECIMA NOVENA. RESPONSABILIDAD SOBRE EL VEHICULO', 'EL CLIENTE es responsable del uso, resguardo y conservacion. Dano, perdida, robo o siniestro no libera de obligaciones de pago.'],
        ['VIGESIMA. IMPUESTOS', 'EL CLIENTE pagara impuestos, derechos u obligaciones fiscales generados por el Contrato.'],
        ['VIGESIMA PRIMERA. CAUSAS DE VENCIMIENTO ANTICIPADO', 'Falta de pago, incumplimiento, informacion falsa, venta no autorizada del vehiculo, siniestro no notificado, incumplimiento de otros contratos con VOLTIKA. Cancelacion sin responsabilidad dentro de 5 dias habiles si no ha recibido vehiculo.'],
        ['VIGESIMA SEGUNDA. COMPENSACION', 'VOLTIKA autorizada para cargar contra cuenta de EL CLIENTE el monto de pagos sin necesidad de requerimiento.'],
        ['VIGESIMA TERCERA. CESION DEL CREDITO', 'EL CLIENTE no puede ceder sin consentimiento. VOLTIKA puede transmitir, ceder o titularizar el credito.'],
        ['VIGESIMA CUARTA. RESTRICCION Y DENUNCIA', 'VOLTIKA se reserva el derecho de denunciar o restringir el Contrato (art. 294 LGTOC).'],
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

    $content  = "CARATULA DE CREDITO - MTECH GEARS S.A. DE C.V.\n";
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
