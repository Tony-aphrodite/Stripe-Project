<?php
/**
 * Voltika — Contrato de Compraventa al Contado (PDF generator + email)
 *
 * Applies to all "100% payment" methods: contado / 9 MSI / SPEI / OXXO.
 * NOT used by the credit (financed) flow — that flow uses
 * generar-contrato-pdf.php with a different template ("Carátula a plazos")
 * and Cincel NOM-151 signature.
 *
 * Acceptance model per the contract text itself (Cláusula Tercera):
 *   checkbox + OTP + payment confirmation = consentimiento expreso
 *   (artículo 89 Código de Comercio).
 *
 * No Cincel signature is captured at this point — Cincel is only invoked
 * later for the Acta de Entrega when the customer physically receives
 * the bike. That is intentional per customer brief 2026-04-28.
 *
 * Public functions:
 *   contratoContadoEnsureSchema(PDO)              — lazy-add 5 columns
 *   contratoContadoGenerate(array $data): array   — writes the PDF
 *   contratoContadoSendEmail(array, string): bool — sends email + attachment
 *   contratoContadoDownloadToken(string): string  — opaque link token
 *   contratoContadoVerifyToken(string,string): bool
 */

require_once __DIR__ . '/config.php';

// FPDF (already used by generar-contrato-pdf.php). We try the same paths
// it does so a single vendor install serves both contract generators.
if (!class_exists('FPDF')) {
    foreach ([
        __DIR__ . '/vendor/fpdf/fpdf.php',
        __DIR__ . '/vendor/setasign/fpdf/fpdf.php',
    ] as $_p) {
        if (file_exists($_p)) { require_once $_p; break; }
    }
    if (!class_exists('FPDF')) {
        $_a = __DIR__ . '/vendor/autoload.php';
        if (file_exists($_a)) require_once $_a;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Schema
// ─────────────────────────────────────────────────────────────────────────

function contratoContadoEnsureSchema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $cols = [
        'contrato_pdf_path'        => "ADD COLUMN contrato_pdf_path        VARCHAR(255) NULL",
        'contrato_aceptado_at'     => "ADD COLUMN contrato_aceptado_at     DATETIME     NULL",
        'contrato_aceptado_ip'     => "ADD COLUMN contrato_aceptado_ip     VARCHAR(45)  NULL",
        'contrato_aceptado_ua'     => "ADD COLUMN contrato_aceptado_ua     VARCHAR(500) NULL",
        'contrato_geolocation'     => "ADD COLUMN contrato_geolocation     VARCHAR(120) NULL",
        'contrato_otp_validated'   => "ADD COLUMN contrato_otp_validated   TINYINT(1)   NOT NULL DEFAULT 0",
    ];
    try {
        $existing = $pdo->query("SHOW COLUMNS FROM transacciones")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($cols as $name => $alter) {
            if (!in_array($name, $existing, true)) {
                try { $pdo->exec("ALTER TABLE transacciones " . $alter); }
                catch (PDOException $e) { error_log('contratoContadoEnsureSchema(' . $name . '): ' . $e->getMessage()); }
            }
        }
    } catch (PDOException $e) {
        error_log('contratoContadoEnsureSchema: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Token (opaque per-order code so the PDF URL can't be enumerated by
// guessing pedido numbers). HMAC-SHA256 of (pedido + stripe_pi) keyed by
// SMTP_PASS as a project-secret proxy. 12 hex chars is enough — guessing
// space ~10^14 vs at most a few thousand orders.
// ─────────────────────────────────────────────────────────────────────────

function contratoContadoDownloadToken(string $pedido, string $stripePi = ''): string {
    $key = defined('SMTP_PASS') ? (string)SMTP_PASS : 'voltika-secret';
    return substr(hash_hmac('sha256', $pedido . '|' . $stripePi, $key), 0, 16);
}

function contratoContadoVerifyToken(string $pedido, string $stripePi, string $token): bool {
    if ($token === '') return false;
    return hash_equals(contratoContadoDownloadToken($pedido, $stripePi), $token);
}

// ─────────────────────────────────────────────────────────────────────────
// Output paths
// ─────────────────────────────────────────────────────────────────────────

function contratoContadoOutputDir(): string {
    $dir = __DIR__ . '/../contratos/contado';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        @chmod($dir, 0775);
    }
    // Plesk-style hosting often denies the PHP runtime user write access
    // to code-tree directories. Fall back to /tmp so the contrato write
    // never silently fails (chargeback evidence MUST be available).
    if (!is_writable($dir)) {
        $alt = sys_get_temp_dir() . '/voltika_contratos_contado';
        if (!is_dir($alt)) @mkdir($alt, 0777, true);
        @chmod($alt, 0777);
        if (is_writable($alt)) {
            $GLOBALS['_contrato_using_temp_dir'] = true;
            return $alt;
        }
    }
    return $dir;
}

function contratoContadoPdfPath(string $pedido): string {
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $pedido);
    return contratoContadoOutputDir() . '/contrato_contado_' . $safe . '.pdf';
}

// Public URL relative to the configurador root. The descargar-contrato
// endpoint is the supported access path; we return the file path here
// only for record-keeping (used as the value persisted in transacciones).
//
// When /tmp fallback is active we persist the absolute path so the
// download endpoint can find the file on the next request — otherwise
// the relative "contratos/..." path resolves to an empty location.
function contratoContadoRelativePath(string $pedido): string {
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $pedido);
    $filename = 'contrato_contado_' . $safe . '.pdf';
    if (!empty($GLOBALS['_contrato_using_temp_dir'])) {
        return contratoContadoOutputDir() . '/' . $filename;  // absolute /tmp path
    }
    return 'contratos/contado/' . $filename;
}

// ─────────────────────────────────────────────────────────────────────────
// Method-name helpers (placeholder fill)
// ─────────────────────────────────────────────────────────────────────────

function contratoContadoMethodLabel(string $pagoTipo): string {
    switch ($pagoTipo) {
        case 'contado':
        case 'unico':   return 'Pago único con tarjeta';
        case 'msi':     return '9 Meses sin intereses (MSI)';
        case 'spei':    return 'Transferencia electrónica (SPEI)';
        case 'oxxo':    return 'Pago en OXXO';
        default:        return ucfirst($pagoTipo);
    }
}

function contratoContadoProcessor(string $pagoTipo): string {
    switch ($pagoTipo) {
        case 'spei':    return 'Stripe / Banco emisor (CLABE)';
        case 'oxxo':    return 'Stripe / OXXO';
        case 'msi':
        case 'contado':
        case 'unico':
        default:        return 'Stripe (tarjeta)';
    }
}

// ─────────────────────────────────────────────────────────────────────────
// PDF generation
// ─────────────────────────────────────────────────────────────────────────

/**
 * Generate the personalized PDF for a single order.
 *
 * Required keys in $data:
 *   pedido, customer_full_name, customer_email, customer_phone, customer_zip,
 *   vehicle_model, vehicle_color, vehicle_year, vehicle_price, logistics_cost,
 *   total_amount, payment_method (contado|msi|spei|oxxo), payment_reference,
 *   payment_date, contract_date, estimated_delivery_date,
 *   acceptance_timestamp, acceptance_ip, acceptance_user_agent,
 *   acceptance_geolocation, otp_validated
 *
 * Returns ['ok' => bool, 'path' => string|null, 'error' => string|null].
 */
function contratoContadoGenerate(array $data): array {
    if (!class_exists('FPDF')) {
        return ['ok' => false, 'path' => null, 'error' => 'FPDF no disponible'];
    }

    $pedido = (string)($data['pedido'] ?? '');
    if ($pedido === '') {
        return ['ok' => false, 'path' => null, 'error' => 'pedido vacío'];
    }

    $path = contratoContadoPdfPath($pedido);

    try {
        $pdf = _contratoContadoBuildPdf($data);
        $pdf->Output('F', $path);
    } catch (Throwable $e) {
        return ['ok' => false, 'path' => null, 'error' => $e->getMessage()];
    }

    if (!file_exists($path) || filesize($path) === 0) {
        return ['ok' => false, 'path' => null, 'error' => 'Archivo no escrito'];
    }
    // SHA-256 integrity hash (Tech Spec EN §6 — required for legal evidence).
    $hash = hash_file('sha256', $path);
    return ['ok' => true, 'path' => $path, 'hash' => $hash, 'error' => null];
}

function _contratoContadoEnc(string $s): string {
    // FPDF core fonts only support ISO-8859-1; transliterate UTF-8 → Latin1.
    $r = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
    return $r === false ? $s : $r;
}

function _contratoContadoBuildPdf(array $d): FPDF {
    $enc = '_contratoContadoEnc';
    $fmtMx = function($v) {
        if ($v === '' || $v === null) return '—';
        if (is_numeric($v)) return '$' . number_format((float)$v, 2, '.', ',') . ' MXN';
        return (string)$v;
    };

    $pdf = new FPDF('P', 'mm', 'Letter');
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AliasNbPages();
    $pdf->SetTitle($enc('Contrato de Compraventa - Voltika'));
    $pdf->SetAuthor('Voltika - MTECH GEARS, S.A. DE C.V.');

    // Page header / footer
    $pdf->SetMargins(18, 18, 18);

    // ─── Page 1: Cover + Datos de la operación ───────────────────────────
    $pdf->AddPage();

    // Brand bar
    $pdf->SetFillColor(26, 58, 92);
    $pdf->Rect(0, 0, 215.9, 12, 'F');
    $pdf->SetTextColor(255);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetXY(18, 3.5);
    $pdf->Cell(0, 5, $enc('VOLTIKA · CONTRATO DE COMPRAVENTA AL CONTADO'), 0, 0, 'L');
    $pdf->SetTextColor(0);
    $pdf->SetY(18);

    $pdf->SetFont('Arial', '', 8);
    // Show the customer-facing folio (VK-YYYYMMDD-XXX) when available.
    $folioDisplay = (string)($d['folio'] ?? $d['pedido'] ?? '');
    $pdf->Cell(95, 5, $enc('FOLIO: ') . $enc($folioDisplay), 0, 0);
    $pdf->Cell(95, 5, $enc('FECHA: ') . $enc((string)($d['contract_date'] ?? '')), 0, 1, 'R');
    $pdf->Ln(3);

    $pdf->SetFont('Arial', 'B', 13);
    $pdf->MultiCell(0, 6, $enc('CONTRATO DE COMPRAVENTA DE MOTOCICLETA NUEVA AL CONTADO'), 0, 'C');
    $pdf->Ln(2);

    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 4.5, $enc('Que celebran por una parte MTECH GEARS, S.A. DE C.V., comercialmente conocida como VOLTIKA (en lo sucesivo EL VENDEDOR o VOLTIKA); y por la otra parte, por propio derecho, la persona física cuyos datos se indican en el apartado de "DATOS DE LA OPERACIÓN" del presente Contrato (en lo sucesivo EL COMPRADOR); y en conjunto LAS PARTES, al tenor del siguiente glosario, declaraciones y cláusulas:'));
    $pdf->Ln(3);

    // ── Datos de la operación ────────────────────────────────────────────
    _ccPdfH2($pdf, 'DATOS DE LA OPERACIÓN');
    _ccPdfH3($pdf, 'Datos de EL COMPRADOR');
    _ccPdfTable($pdf, [
        ['Nombre completo',     (string)($d['customer_full_name'] ?? '')],
        ['Correo electrónico',  (string)($d['customer_email']     ?? '')],
        ['Teléfono',            (string)($d['customer_phone']     ?? '')],
        ['Código Postal',       (string)($d['customer_zip']       ?? '')],
    ]);

    _ccPdfH3($pdf, 'Datos del vehículo');
    _ccPdfTable($pdf, [
        ['Marca',         'Voltika'],
        ['Modelo',        (string)($d['vehicle_model'] ?? '')],
        ['Color',         (string)($d['vehicle_color'] ?? '')],
        ['Año-modelo',    (string)($d['vehicle_year']  ?? '')],
        ['VIN/NIV',       'Por asignar al momento de la entrega'],
    ]);

    _ccPdfH3($pdf, 'Monto de la operación');
    _ccPdfTable($pdf, [
        ['Precio del vehículo (IVA incluido)',  $fmtMx($d['vehicle_price']  ?? '')],
        ['Costo logístico (aplica solo en MSI)', $fmtMx($d['logistics_cost'] ?? 0)],
        ['Monto total de la operación',         $fmtMx($d['total_amount']   ?? '')],
    ]);

    _ccPdfH3($pdf, 'Forma de pago');
    _ccPdfTable($pdf, [
        ['Modalidad seleccionada', contratoContadoMethodLabel((string)($d['payment_method'] ?? ''))],
        ['Procesador / Medio',     contratoContadoProcessor((string)($d['payment_method'] ?? ''))],
        ['Referencia de pago',     (string)($d['payment_reference'] ?? '')],
        ['Fecha de pago',          (string)($d['payment_date']      ?? '')],
    ]);

    _ccPdfH3($pdf, 'Punto de entrega');
    _ccPdfTable($pdf, [
        ['Punto autorizado',           'Por definir'],
        ['Fecha estimada de entrega',  (string)($d['estimated_delivery_date'] ?? '')],
    ]);

    _ccPdfPara($pdf, 'EL COMPRADOR reconoce y acepta que el PUNTO DE ENTREGA AUTORIZADO será asignado por VOLTIKA con posterioridad a la firma del presente Contrato y notificado a EL COMPRADOR mediante los medios de contacto registrados, formando parte integral del presente Contrato.');
    _ccPdfPara($pdf, 'Previo a la celebración del presente contrato, EL VENDEDOR dio a conocer a EL COMPRADOR el Aviso de Privacidad para el tratamiento de sus datos personales, disponible en https://www.voltika.mx/docs/privacidad_2026.');

    // ── Aceptación electrónica ───────────────────────────────────────────
    _ccPdfH2($pdf, 'ACEPTACIÓN ELECTRÓNICA DEL CONTRATO');
    _ccPdfPara($pdf, 'EL COMPRADOR manifiesta su CONSENTIMIENTO EXPRESO al presente Contrato mediante los siguientes mecanismos de aceptación electrónica realizados durante el proceso de compra en el configurador de VOLTIKA (voltika.mx):');
    _ccPdfList($pdf, [
        'a) Selección de la casilla de aceptación (checkbox) de los Términos y Condiciones, el Aviso de Privacidad y el presente Contrato.',
        'b) Validación del Código OTP enviado al TELÉFONO REGISTRADO en el apartado DATOS DE LA OPERACIÓN.',
        'c) Realización del pago total de la operación por los medios autorizados.',
    ]);
    _ccPdfPara($pdf, 'Las partes reconocen expresamente que la combinación del checkbox aceptado, el OTP validado y el pago confirmado constituye CONSENTIMIENTO EXPRESO Y VINCULANTE para todos los efectos legales del presente Contrato, conforme al artículo 89 del Código de Comercio.');
    _ccPdfPara($pdf, 'VOLTIKA conservará registro electrónico completo de la aceptación, incluyendo: timestamp UTC, dirección IP, geolocalización, dispositivo utilizado, código OTP validado y datos del pago realizado.');
    _ccPdfPara($pdf, 'La firma electrónica avanzada conforme a la NOM-151-SCFI-2016 a través de Cincel S.A.P.I. de C.V. o cualquier PSC autorizado se aplicará exclusivamente al ACTA DE ENTREGA al momento de la recepción física de EL VEHÍCULO, conforme a la Cláusula correspondiente.');

    // ── Glosario ─────────────────────────────────────────────────────────
    _ccPdfH2($pdf, 'GLOSARIO');
    _ccPdfDefList($pdf, [
        ['Consumidor', 'Persona física que adquiere en propiedad la motocicleta. Se le denominará EL COMPRADOR.'],
        ['Proveedor', 'Persona moral que ofrece en venta motocicleta nueva con motor de propulsión eléctrica. Se le denominará EL VENDEDOR o VOLTIKA.'],
        ['Motocicleta nueva', 'Unidad de dos ruedas con motor de propulsión eléctrica que no ha sido previamente registrada ni emplacada ante autoridad de control vehicular.'],
        ['Aceptación Electrónica', 'Mecanismo por el cual EL COMPRADOR manifiesta su consentimiento al presente Contrato mediante checkbox, validación OTP y pago, conforme al artículo 89 del Código de Comercio.'],
        ['Validación de Identidad', 'Procedimiento al momento de la entrega que combina la presentación de identificación oficial (INE) con coincidencia de nombre, y la validación de Código OTP enviado al teléfono registrado.'],
        ['Descriptor de Cargo', 'Identificación con la que aparece la operación en el estado de cuenta del medio de pago utilizado: "VOLTIKA MX" o el descriptor que VOLTIKA designe.'],
        ['Carta Factura', 'Documento que VOLTIKA entrega a EL COMPRADOR AL MOMENTO DE LA ENTREGA del vehículo, válido para los trámites de emplacamiento y registro vehicular estatal hasta que se entregue la factura original.'],
        ['Factura (CFDI)', 'Comprobante Fiscal Digital por Internet emitido por VOLTIKA AL MOMENTO DE LA ENTREGA física de EL VEHÍCULO a EL COMPRADOR.'],
        ['REPUVE', 'Registro Público Vehicular conforme a la Ley del Registro Público Vehicular.'],
        ['Punto de Entrega', 'Establecimiento autorizado por VOLTIKA designado con posterioridad a la firma del Contrato para realizar la entrega física del vehículo.'],
        ['Acta de Entrega', 'Documento firmado electrónicamente mediante Cincel u otro PSC autorizado, por EL COMPRADOR al recibir el vehículo, mediante el cual reconoce expresamente la recepción y conformidad con el bien.'],
        ['PSC', 'Prestador de Servicios de Certificación acreditado por la Secretaría de Economía conforme a la NOM-151-SCFI-2016.'],
    ]);

    // ── Declaraciones ────────────────────────────────────────────────────
    _ccPdfH2($pdf, 'DECLARACIONES');

    _ccPdfH3($pdf, 'PRIMERA. Declara EL VENDEDOR.');
    _ccPdfList($pdf, [
        'Ser una sociedad mercantil mexicana debidamente constituida bajo la legislación de los Estados Unidos Mexicanos.',
        'Estar debidamente representada por persona con plenas facultades para obligarla.',
        'Tener como domicilio fiscal el ubicado en Jaime Balmes 71, despacho 101 C, Polanco I Sección, Miguel Hidalgo, C.P. 11510, Ciudad de México.',
        'Estar inscrita en el Registro Federal de Contribuyentes con clave MGE230316KA2.',
        'Contar con personal capacitado para atender quejas y reclamaciones mediante WhatsApp +52 55 1341 6370 y correo contacto@voltika.mx, en horario de 9:00 a 19:00 horas, lunes a viernes.',
        'Que la motocicleta objeto del contrato cumple con las disposiciones legales y Normas Oficiales Mexicanas vigentes.',
        'Contar con la infraestructura para proporcionar servicios de garantía conforme a la póliza correspondiente.',
    ]);

    _ccPdfH3($pdf, 'SEGUNDA. Declara EL COMPRADOR.');
    _ccPdfList($pdf, [
        'Ser persona física con capacidad jurídica y económica para obligarse en los términos del presente Contrato.',
        'Llamarse como ha quedado anotado en el apartado de DATOS DE LA OPERACIÓN del presente contrato.',
        'Haber recibido del VENDEDOR toda la información relativa a la motocicleta materia de este contrato, incluyendo especificaciones técnicas, autonomía y características de uso.',
        'Que el TELÉFONO REGISTRADO en el apartado DATOS DE LA OPERACIÓN será considerado como el IDENTIFICADOR ÚNICO DE VALIDACIÓN DE IDENTIDAD para todos los efectos del presente contrato, incluyendo la aceptación electrónica del Contrato y la entrega del vehículo.',
    ]);
    _ccPdfPara($pdf, 'BAJO PROTESTA DE DECIR VERDAD, EL COMPRADOR DECLARA: (i) que es el legítimo titular del medio de pago utilizado, o cuenta con autorización expresa, vigente y suficiente del titular legítimo para utilizarlo en esta operación; (ii) que la información proporcionada durante el proceso de contratación es verdadera, completa y actualizada; (iii) que reconoce y acepta que el cargo aparecerá identificado en su estado de cuenta como "VOLTIKA MX" o descriptor similar designado por VOLTIKA; y (iv) que reconoce que cualquier falsedad en estas declaraciones podrá constituir el delito de fraude en términos del artículo 386 del Código Penal Federal.');

    _ccPdfH3($pdf, 'TERCERA. Protección de Datos Personales.');
    _ccPdfPara($pdf, 'EL COMPRADOR otorga su consentimiento para el tratamiento de sus datos personales conforme al Aviso de Privacidad de VOLTIKA, para finalidades de identificación, procesamiento del pago, entrega del producto, atención de aclaraciones, cumplimiento de obligaciones legales (CFDI, REPUVE) y captura de datos técnicos de la operación (IP, geolocalización, dispositivo, OTP). EL COMPRADOR podrá ejercer en cualquier momento sus derechos ARCO conforme al Aviso de Privacidad.');

    // ── Cláusulas ────────────────────────────────────────────────────────
    _ccPdfH2($pdf, 'CLÁUSULAS');

    _ccPdfH3($pdf, 'PRIMERA. Objeto.');
    _ccPdfPara($pdf, 'VOLTIKA vende a EL COMPRADOR y éste adquiere en propiedad la motocicleta eléctrica cuyas características se detallan en el apartado DATOS DE LA OPERACIÓN del presente Contrato, en adelante EL VEHÍCULO. La compraventa se realiza al CONTADO; la propiedad de EL VEHÍCULO se transfiere a EL COMPRADOR una vez acreditado el pago total y firmada el Acta de Entrega al momento de la recepción física. El Número de Identificación Vehicular (VIN/NIV) y el Punto de Entrega serán asignados por VOLTIKA con posterioridad a la firma del presente Contrato y notificados a EL COMPRADOR previo a la entrega, formando parte integral del presente Contrato.');

    _ccPdfH3($pdf, 'SEGUNDA. Precio y forma de pago.');
    _ccPdfPara($pdf, 'EL COMPRADOR se obliga a pagar el precio total de la operación conforme al apartado DATOS DE LA OPERACIÓN, mediante alguna de las siguientes modalidades autorizadas por VOLTIKA:');
    _ccPdfList($pdf, [
        'a) PAGO ÚNICO CON TARJETA: cargo único a tarjeta de crédito o débito por el monto total.',
        'b) MESES SIN INTERESES (MSI): pago a 9 meses sin intereses con tarjeta de crédito participante. Esta modalidad incluye COSTO LOGÍSTICO ADICIONAL al punto de entrega asignado.',
        'c) TRANSFERENCIA ELECTRÓNICA (SPEI): a la cuenta bancaria que VOLTIKA designe.',
        'd) PAGO EN OXXO: mediante referencia generada por VOLTIKA.',
    ]);
    _ccPdfPara($pdf, 'La operación se considera pagada hasta que VOLTIKA reciba la confirmación efectiva de la acreditación del monto total por parte del procesador de pago, banco o institución correspondiente.');

    _ccPdfH3($pdf, 'TERCERA. Aceptación electrónica y consentimiento.');
    _ccPdfPara($pdf, 'EL COMPRADOR reconoce expresamente que ha aceptado el presente Contrato mediante los mecanismos electrónicos descritos en el apartado ACEPTACIÓN ELECTRÓNICA DEL CONTRATO, los cuales constituyen consentimiento expreso y vinculante conforme al artículo 89 del Código de Comercio. La aceptación realizada mediante checkbox, validación OTP y pago confirmado tiene PLENO VALOR PROBATORIO para los efectos del presente Contrato. EL COMPRADOR no podrá alegar desconocimiento, repudio o falta de identificación respecto del Contrato así aceptado.');
    _ccPdfPara($pdf, 'VOLTIKA conservará registros electrónicos completos de la aceptación, incluyendo timestamp, IP, geolocalización, dispositivo, código OTP validado y datos del pago, los cuales podrán ser presentados como prueba documental plena en cualquier procedimiento judicial, administrativo, ante PROFECO o ante procesadores de pago.');

    _ccPdfH3($pdf, 'CUARTA. Garantía.');
    _ccPdfPara($pdf, 'VOLTIKA garantiza el correcto funcionamiento de EL VEHÍCULO conforme a la póliza de garantía entregada al momento de la operación, cuya vigencia no podrá ser inferior a 90 (noventa) días naturales conforme al artículo 77 de la Ley Federal de Protección al Consumidor. Ante desperfectos dentro del periodo de vigencia, EL COMPRADOR debe contactar a VOLTIKA mediante los canales oficiales. VOLTIKA informará en un plazo no mayor a 10 (diez) días naturales sobre la procedencia o improcedencia de la reparación. En caso de proceder, VOLTIKA reemplazará cualquier pieza o componente defectuoso sin costo adicional. El tiempo que transcurra desde la solicitud hasta la devolución de EL VEHÍCULO reparado no se computará dentro de la vigencia de la garantía. Si VOLTIKA no cuenta con las refacciones necesarias en un plazo máximo de 60 (sesenta) días naturales, asumirá los costos por incumplimiento conforme a la NOM aplicable y políticas vigentes.');

    _ccPdfH3($pdf, 'QUINTA. Procedimiento de aclaración previa.');
    _ccPdfPara($pdf, 'Antes de presentar cualquier aclaración, queja o solicitud de reversión de cargo (contracargo) ante su institución financiera, EL COMPRADOR se obliga a contactar primero a VOLTIKA mediante los canales oficiales, con la finalidad de:');
    _ccPdfList($pdf, [
        'a) Verificar la información de la operación.',
        'b) Recibir aclaración sobre cualquier cargo no reconocido.',
        'c) Buscar solución a cualquier inconformidad.',
    ]);
    _ccPdfPara($pdf, 'VOLTIKA atenderá la solicitud dentro de un plazo máximo de 5 (cinco) días hábiles, conforme al artículo 99 de la Ley Federal de Protección al Consumidor. EL COMPRADOR reconoce que una vez entregado EL VEHÍCULO, firmados el Acta de Entrega y emitida la factura CFDI, la transacción se considera cumplida por VOLTIKA. En caso de disputa improcedente, VOLTIKA podrá ejercer las acciones legales que correspondan.');

    _ccPdfH3($pdf, 'SEXTA. Medios de acreditación y registro.');
    _ccPdfPara($pdf, 'VOLTIKA podrá conservar registros físicos y electrónicos relacionados con la contratación, pago, validación, entrega y cumplimiento del presente contrato, incluyendo mensajes de datos, registros de plataforma, direcciones IP, geolocalización, fecha y hora de operación, validaciones OTP, evidencia fotográfica, aceptación electrónica del Contrato y firma electrónica del Acta de Entrega. Dichos registros constituirán evidencia suficiente de la operación y podrán ser utilizados por VOLTIKA para fines de aclaración, prevención de fraude, atención de contracargos y defensa en cualquier procedimiento legal o administrativo.');

    _ccPdfH3($pdf, 'SÉPTIMA. Emisión de factura y documentos de entrega.');
    _ccPdfPara($pdf, 'Las partes reconocen y acuerdan que la emisión de los siguientes documentos se realizará AL MOMENTO DE LA ENTREGA FÍSICA del vehículo:');
    _ccPdfList($pdf, [
        'a) FACTURA (CFDI): VOLTIKA emitirá el Comprobante Fiscal Digital por Internet correspondiente a la operación al momento de la entrega física de EL VEHÍCULO, conforme a las disposiciones fiscales aplicables.',
        'b) CARTA FACTURA: documento entregado a EL COMPRADOR al momento de la entrega, válido para emplacamiento y registro vehicular estatal.',
        'c) PÓLIZA DE GARANTÍA: debidamente sellada y firmada.',
        'd) MANUAL DEL USUARIO: en idioma español.',
        'e) LLAVES Y ACCESORIOS: estándar.',
    ]);
    _ccPdfPara($pdf, 'VOLTIKA entregará a EL COMPRADOR la FACTURA ORIGINAL del vehículo dentro de un plazo de 15 (quince) días hábiles contados a partir de la fecha de la entrega física.');

    _ccPdfH3($pdf, 'OCTAVA. Registro ante REPUVE.');
    _ccPdfPara($pdf, 'Conforme al artículo 23 de la Ley del Registro Público Vehicular, VOLTIKA presentará al REPUVE el aviso de compraventa correspondiente, indicando los datos de EL COMPRADOR como nuevo propietario, dentro del día hábil siguiente al de la facturación. EL COMPRADOR podrá obtener su constancia de inscripción REPUVE en www.repuve.gob.mx.');

    _ccPdfH3($pdf, 'NOVENA. Tiempos de entrega.');
    _ccPdfPara($pdf, 'VOLTIKA se compromete a realizar la entrega de EL VEHÍCULO conforme a los siguientes plazos, contados a partir de la confirmación efectiva del pago total:');
    _ccPdfList($pdf, [
        'a) ENTREGA ESTÁNDAR: hasta 60 (sesenta) días naturales, cuando el modelo y color se encuentre disponible en inventario.',
        'b) ENTREGA EXTENDIDA: hasta 90 (noventa) días naturales, cuando el modelo o color esté sujeto a reposición de inventario, importación o ensamble.',
        'c) FUERZA MAYOR: hasta 30 (treinta) días naturales adicionales por causas ajenas al control de VOLTIKA, previa notificación a EL COMPRADOR.',
    ]);
    _ccPdfPara($pdf, 'Si transcurrido el plazo total aplicable VOLTIKA no ha entregado EL VEHÍCULO, EL COMPRADOR podrá: a) continuar esperando con compensación equivalente conforme a las políticas vigentes de VOLTIKA, o b) solicitar la cancelación del Contrato con devolución íntegra del monto pagado en plazo no mayor a 10 (diez) días hábiles.');

    _ccPdfH3($pdf, 'DÉCIMA. Asignación y notificación del punto de entrega.');
    _ccPdfPara($pdf, 'EL COMPRADOR reconoce y acepta que el PUNTO DE ENTREGA AUTORIZADO por VOLTIKA será asignado con posterioridad a la firma del presente Contrato, conforme a la disponibilidad operativa, código postal de EL COMPRADOR y logística de la operación. VOLTIKA notificará a EL COMPRADOR el punto de entrega asignado, incluyendo dirección, fecha y hora, mediante los medios de contacto registrados.');
    _ccPdfPara($pdf, 'EL COMPRADOR se obliga a: a) acudir personalmente al punto de entrega; b) presentar identificación oficial vigente original (INE, pasaporte o cédula profesional); c) mantener disponible el TELÉFONO REGISTRADO para recibir el código OTP de validación; d) realizar la inspección de EL VEHÍCULO; e) suscribir el Acta de Entrega correspondiente mediante firma electrónica avanzada.');

    _ccPdfH3($pdf, 'DÉCIMA PRIMERA. Validación de identidad, inspección y acta de entrega.');
    _ccPdfPara($pdf, 'A) VALIDACIÓN DE IDENTIDAD. Al momento de la entrega, EL COMPRADOR deberá acreditar que es la misma persona que celebró el contrato mediante DOS factores: (a) presentación de identificación oficial vigente original cuyo nombre coincida con el registrado, y (b) validación del Código OTP enviado al teléfono registrado. EL COMPRADOR reconoce que el teléfono registrado constituye el IDENTIFICADOR ÚNICO DE VALIDACIÓN.');
    _ccPdfPara($pdf, 'VOLTIKA NO ENTREGARÁ EL VEHÍCULO si: el nombre en la identificación oficial NO coincide con el registrado en el contrato, el Código OTP no es validado correctamente al teléfono registrado, o existe inconsistencia razonable en la validación de identidad.');
    _ccPdfPara($pdf, 'B) INSPECCIÓN DEL VEHÍCULO. EL COMPRADOR deberá inspeccionar físicamente EL VEHÍCULO. Tiene derecho de RECHAZAR la entrega si detecta cualquier inconformidad sustancial. En tal caso, VOLTIKA podrá reparar, sustituir el vehículo o reversar la operación con devolución íntegra.');
    _ccPdfPara($pdf, 'C) ACTA DE ENTREGA CON FIRMA ELECTRÓNICA AVANZADA. Al recibir EL VEHÍCULO conforme, EL COMPRADOR firmará el ACTA DE ENTREGA mediante firma electrónica avanzada a través de Cincel S.A.P.I. de C.V. o cualquier PSC autorizado conforme a la NOM-151-SCFI-2016. La firma electrónica del Acta de Entrega es equivalente a la firma autógrafa para todos los efectos legales conforme al artículo 89 del Código de Comercio.');
    _ccPdfPara($pdf, 'NO HABRÁ ENTREGA SIN: validación de identidad (INE coincidente + OTP validado al teléfono registrado) + inspección satisfactoria + Acta de Entrega firmada electrónicamente.');

    _ccPdfH3($pdf, 'DÉCIMA SEGUNDA. Revocación y cancelación.');
    _ccPdfPara($pdf, '(i) REVOCACIÓN ANTES DEL CFDI Y DE LA ENTREGA: EL COMPRADOR podrá revocar su consentimiento mediante notificación por escrito a VOLTIKA en un plazo de 48 (cuarenta y ocho) horas posteriores a la aceptación electrónica del contrato, siempre que aún no se haya emitido el CFDI ni entregado EL VEHÍCULO. VOLTIKA reembolsará el monto pagado en un plazo no mayor a 10 (diez) días hábiles, descontando los gastos administrativos y operativos efectivamente incurridos.');
    _ccPdfPara($pdf, '(ii) CANCELACIÓN POSTERIOR A LA ENTREGA Y EMISIÓN DEL CFDI: Una vez entregado EL VEHÍCULO, firmada el Acta de Entrega y emitido el CFDI, las partes reconocen que la cancelación con devolución total no procederá de manera automática debido a que la cancelación del CFDI requiere aceptación del receptor (reglas 2.7.1.34 y 2.7.1.35 RMF), el aviso REPUVE requiere proceso administrativo formal, el IVA ha sido enterado al SAT y el vehículo es susceptible de depreciación inmediata.');
    _ccPdfPara($pdf, '(iii) DERECHOS PRESERVADOS: EL COMPRADOR conserva derechos sustantivos: rechazo al momento de la entrega; reclamos por defectos de fábrica conforme a póliza; vicios ocultos (art. 77 LFPC); defectos graves (art. 79 LFPC: reparación gratuita o sustitución); servicio post-venta conforme a las pólizas vigentes.');

    _ccPdfH3($pdf, 'DÉCIMA TERCERA. Rescisión.');
    _ccPdfPara($pdf, 'Son causas de rescisión del presente contrato el incumplimiento sustancial de los términos por cualquiera de las partes. La parte cumplida notificará el incumplimiento a la otra y, en caso de que el incumplimiento sea imputable a VOLTIKA, ésta devolverá la cantidad recibida en plazo no mayor a 30 (treinta) días hábiles a partir de la notificación.');

    _ccPdfH3($pdf, 'DÉCIMA CUARTA. Domicilios y notificaciones.');
    _ccPdfPara($pdf, 'Para todos los efectos del presente contrato, las notificaciones se realizarán en los datos de contacto registrados. EL COMPRADOR acepta recibir notificaciones mediante correo electrónico, SMS o WhatsApp. EL COMPRADOR deberá notificar a VOLTIKA cualquier cambio en su correo electrónico o teléfono con cuando menos 5 (cinco) días hábiles de anticipación, considerando que el teléfono es el identificador único de validación de identidad para la entrega.');

    _ccPdfH3($pdf, 'DÉCIMA QUINTA. Atención a clientes.');
    _ccPdfPara($pdf, 'Para cualquier aclaración, queja o reclamación: correo electrónico contacto@voltika.mx, WhatsApp +52 55 1341 6370 o portal web www.voltika.mx.');

    _ccPdfH3($pdf, 'DÉCIMA SEXTA. Jurisdicción y competencia.');
    _ccPdfPara($pdf, '(i) DOMICILIO CONVENCIONAL: las partes designan como domicilio convencional la Ciudad de México. (ii) JURISDICCIÓN EXPRESA: las partes se someten EXPRESAMENTE a la jurisdicción de los tribunales competentes en la CIUDAD DE MÉXICO, renunciando expresamente a cualquier otro fuero, conforme al artículo 1093 del Código de Comercio. (iii) PROFECO: sin perjuicio de lo anterior, las partes reconocen la competencia de la Procuraduría Federal del Consumidor en la vía administrativa, conforme al artículo 99 LFPC.');

    _ccPdfH3($pdf, 'DÉCIMA SÉPTIMA. Declaraciones finales de EL COMPRADOR.');
    _ccPdfPara($pdf, 'Al aceptar electrónicamente el presente Contrato, EL COMPRADOR manifiesta y declara, bajo protesta de decir verdad: que ha leído íntegramente el Contrato; que conoce y acepta el precio total y la modalidad de pago; que es titular o cuenta con autorización del medio de pago; que reconoce que el cargo aparecerá como "VOLTIKA MX"; que acepta los plazos de entrega; que la validación de identidad al momento de la entrega se realizará mediante INE coincidente con el nombre registrado + OTP validado al teléfono registrado; que reconoce que la aceptación electrónica (checkbox + OTP + pago) tiene pleno valor probatorio conforme al artículo 89 del Código de Comercio; y que reconoce que la firma electrónica avanzada del Acta de Entrega a través de Cincel u otro PSC conforme a la NOM-151-SCFI-2016 tiene pleno valor probatorio equivalente a la firma autógrafa.');
    _ccPdfPara($pdf, 'Las partes reconocen que el presente Contrato es aceptado y vinculante a partir de la confirmación electrónica del consentimiento por parte de EL COMPRADOR (checkbox + OTP + pago confirmado).');

    // ── Registro de aceptación ───────────────────────────────────────────
    _ccPdfH2($pdf, 'REGISTRO DE ACEPTACIÓN ELECTRÓNICA');
    _ccPdfPara($pdf, 'Apartado completado automáticamente por el sistema al momento de la aceptación electrónica:');
    _ccPdfTable($pdf, [
        ['Folio del Contrato',                (string)($d['folio'] ?? $d['pedido'] ?? '')],
        ['Nombre de EL COMPRADOR',            (string)($d['customer_full_name']    ?? '')],
        ['Fecha y hora de aceptación (UTC)',  (string)($d['acceptance_timestamp']  ?? '')],
        ['Dirección IP',                      (string)($d['acceptance_ip']         ?? '')],
        ['Geolocalización',                   (string)($d['acceptance_geolocation'] ?? 'No proporcionada')],
        ['Dispositivo',                       (string)($d['acceptance_user_agent'] ?? '')],
        ['OTP validado',                      !empty($d['otp_validated']) ? 'Sí' : 'No'],
        ['Referencia de pago',                (string)($d['payment_reference']     ?? '')],
    ]);

    // Footer disclaimer
    $pdf->Ln(4);
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->SetTextColor(120);
    $pdf->MultiCell(0, 3.5, $enc('VOLTIKA · MTECH GEARS, S.A. DE C.V. · Aceptación electrónica registrada conforme al artículo 89 del Código de Comercio.'), 0, 'C');
    $pdf->SetTextColor(0);

    return $pdf;
}

// PDF layout helpers ───────────────────────────────────────────────────────

function _ccPdfH2(FPDF $pdf, string $title): void {
    $pdf->Ln(3);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(241, 245, 249);
    $pdf->SetTextColor(26, 58, 92);
    $pdf->Cell(0, 6, _contratoContadoEnc(' ' . $title), 0, 1, 'L', true);
    $pdf->SetTextColor(0);
    $pdf->Ln(1);
}

function _ccPdfH3(FPDF $pdf, string $title): void {
    $pdf->Ln(1.5);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(60, 60, 60);
    $pdf->MultiCell(0, 4.5, _contratoContadoEnc($title));
    $pdf->SetTextColor(0);
}

function _ccPdfPara(FPDF $pdf, string $text): void {
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->MultiCell(0, 4.2, _contratoContadoEnc($text), 0, 'J');
    $pdf->Ln(1);
}

function _ccPdfList(FPDF $pdf, array $items): void {
    $pdf->SetFont('Arial', '', 8.5);
    foreach ($items as $it) {
        $pdf->Cell(4); // indent
        $pdf->MultiCell(0, 4.2, _contratoContadoEnc($it), 0, 'J');
        $pdf->Ln(0.3);
    }
    $pdf->Ln(0.5);
}

function _ccPdfTable(FPDF $pdf, array $rows): void {
    $w1 = 60;
    $w2 = 119.9;
    foreach ($rows as $r) {
        $label = (string)$r[0];
        $value = (string)$r[1];
        if ($value === '') $value = '—';

        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetFillColor(248, 250, 252);
        // Determine the row height by measuring the value's wrapped lines.
        $valLines = max(1, _ccPdfLineCount($pdf, $value, $w2 - 4));
        $h = max(5.5, $valLines * 4.2 + 1.5);

        $x = $pdf->GetX();
        $y = $pdf->GetY();

        // Trigger a page break if the row won't fit
        if ($y + $h > ($pdf->GetPageHeight() - 18)) {
            $pdf->AddPage();
            $y = $pdf->GetY();
            $x = $pdf->GetX();
        }

        $pdf->Rect($x, $y, $w1, $h, 'DF');
        $pdf->SetXY($x + 1.5, $y + 1.2);
        $pdf->Cell($w1 - 3, 4.2, _contratoContadoEnc($label), 0, 0, 'L');

        $pdf->SetFont('Arial', '', 8);
        $pdf->SetFillColor(255);
        $pdf->Rect($x + $w1, $y, $w2, $h, 'D');
        $pdf->SetXY($x + $w1 + 2, $y + 1.2);
        $pdf->MultiCell($w2 - 4, 4.2, _contratoContadoEnc($value), 0, 'L');

        $pdf->SetXY($x, $y + $h);
    }
    $pdf->Ln(1.5);
}

function _ccPdfDefList(FPDF $pdf, array $pairs): void {
    foreach ($pairs as $p) {
        $term = $p[0];
        $def  = $p[1];
        $pdf->SetFont('Arial', 'B', 8.5);
        $pdf->Cell(0, 4.2, _contratoContadoEnc($term . ':'), 0, 1);
        $pdf->SetFont('Arial', '', 8.5);
        $pdf->Cell(4);
        $pdf->MultiCell(0, 4.2, _contratoContadoEnc($def), 0, 'J');
        $pdf->Ln(0.6);
    }
}

function _ccPdfLineCount(FPDF $pdf, string $text, float $width): int {
    // Approximation — FPDF doesn't expose wrap-line count cleanly. We
    // build a trial cell into a temporary buffer by splitting on spaces.
    $text = _contratoContadoEnc($text);
    $words = preg_split('/\s+/', $text);
    $line = '';
    $lines = 1;
    foreach ($words as $w) {
        $trial = $line === '' ? $w : ($line . ' ' . $w);
        if ($pdf->GetStringWidth($trial) > $width) {
            $lines++;
            $line = $w;
        } else {
            $line = $trial;
        }
    }
    return $lines;
}

// ─────────────────────────────────────────────────────────────────────────
// Email with attachment
// ─────────────────────────────────────────────────────────────────────────

function contratoContadoSendEmail(array $data, string $pdfPath): bool {
    if (!file_exists($pdfPath)) {
        error_log('contratoContadoSendEmail: PDF not found at ' . $pdfPath);
        return false;
    }
    $to     = (string)($data['customer_email'] ?? '');
    $name   = (string)($data['customer_full_name'] ?? '');
    $pedido = (string)($data['pedido'] ?? '');
    if ($to === '') {
        error_log('contratoContadoSendEmail: empty recipient for pedido ' . $pedido);
        return false;
    }

    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) require_once $autoload;
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        error_log('contratoContadoSendEmail: PHPMailer not available');
        return false;
    }

    $subject = 'Contrato de compraventa Voltika · Pedido ' . $pedido;
    $body    = _contratoContadoEmailHtml($data);

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->SMTPAuth   = true;
        $mail->Host       = SMTP_HOST;
        $mail->Port       = defined('SMTP_PORT') ? SMTP_PORT : 465;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->setFrom(SMTP_USER, 'Voltika México');
        $mail->addAddress($to, $name);
        // BCC legal/operations so we keep an internal copy of every issued contract
        $mail->addBCC('legal@voltika.mx');
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->isHTML(true);
        $mail->Subject  = $subject;
        $mail->Body     = $body;
        $mail->AltBody  = strip_tags($body);
        $mail->addAttachment($pdfPath, 'Contrato_Voltika_' . $pedido . '.pdf');
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('contratoContadoSendEmail PHPMailer error: ' . $e->getMessage());
        return false;
    }
}

function _contratoContadoEmailHtml(array $d): string {
    $n   = htmlspecialchars((string)($d['customer_full_name'] ?? ''));
    $ped = htmlspecialchars((string)($d['pedido'] ?? ''));
    $mod = htmlspecialchars((string)($d['vehicle_model'] ?? ''));
    $col = htmlspecialchars((string)($d['vehicle_color'] ?? ''));
    $tot = htmlspecialchars((string)($d['total_amount']  ?? ''));
    $met = htmlspecialchars(contratoContadoMethodLabel((string)($d['payment_method'] ?? '')));

    return '<!DOCTYPE html><html><body style="font-family:Arial,Helvetica,sans-serif;color:#1a1a1a;line-height:1.6;">'
        . '<div style="max-width:560px;margin:0 auto;padding:24px;">'
        . '<h2 style="color:#1a3a5c;margin:0 0 12px;">Tu contrato de compraventa, ' . $n . '</h2>'
        . '<p>Adjunto encontrarás el <strong>Contrato de Compraventa al Contado</strong> firmado electrónicamente para tu pedido <strong>' . $ped . '</strong>.</p>'
        . '<p style="background:#f1f9ff;border-left:3px solid #039fe1;padding:10px 14px;margin:12px 0;">'
        . '<strong>Modelo:</strong> ' . $mod . ' · ' . $col . '<br>'
        . '<strong>Modalidad:</strong> ' . $met . '<br>'
        . '<strong>Monto:</strong> $' . number_format((float)$tot, 2, '.', ',') . ' MXN'
        . '</p>'
        . '<p>Este contrato quedó aceptado mediante checkbox + OTP + pago confirmado, conforme al artículo 89 del Código de Comercio. Conserva este correo y el archivo adjunto para tus registros.</p>'
        . '<p style="font-size:13px;color:#555;">Cualquier aclaración: WhatsApp <a href="https://wa.me/525513416370">+52 55 1341 6370</a> · contacto@voltika.mx</p>'
        . '<p style="font-size:11px;color:#888;margin-top:18px;border-top:1px solid #eee;padding-top:10px;">'
        . 'Voltika · MTECH GEARS, S.A. DE C.V. · Aceptación electrónica registrada el ' . htmlspecialchars((string)($d['acceptance_timestamp'] ?? '')) . ' UTC.'
        . '</p>'
        . '</div></body></html>';
}
