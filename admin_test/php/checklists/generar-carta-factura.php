<?php
/**
 * POST — Generate Carta Factura PDF (vehicle temporary invoice).
 * Body: { moto_id }
 * Returns: { ok, pdf_path, pdf_hash, folio }
 *
 * Carta Factura is a Mexican legal document issued AT DELIVERY per
 * Cláusula Décima Novena of the v5 contract. It serves as a temporary
 * proof-of-purchase valid for license-plate (emplacamiento) and vehicle
 * registration trámites until the original CFDI invoice is delivered:
 *
 *   - For CONTADO/MSI/SPEI/OXXO: original CFDI is delivered with the
 *     vehicle and the Carta Factura supports the registration window
 *     (typically 30 days while plates are processed).
 *
 *   - For CRÉDITO (compraventa a plazos): Voltika retains the CFDI
 *     original in custody until full price is paid; the Carta Factura is
 *     the only document the customer holds during the financing period.
 *
 * Mirrors the generar-pagare.php / generar-acta.php pattern: FPDF + SHA-256.
 * The CFDI itself is generated separately by the PAC integration (when
 * implemented); this generator references the CFDI folio if available.
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$motoId = (int)($d['moto_id'] ?? 0);
if (!$motoId) adminJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();

// ── 1. Lazy schema (Carta Factura columns on checklist) ─────────────────
foreach ([
    'carta_factura_pdf_path' => "ADD COLUMN carta_factura_pdf_path VARCHAR(255) NULL",
    'carta_factura_pdf_hash' => "ADD COLUMN carta_factura_pdf_hash CHAR(64)     NULL",
    'carta_factura_folio'    => "ADD COLUMN carta_factura_folio    VARCHAR(40)  NULL",
    'carta_factura_fecha'    => "ADD COLUMN carta_factura_fecha    DATETIME     NULL",
    'cfdi_uuid'              => "ADD COLUMN cfdi_uuid              VARCHAR(64)  NULL",
    'cfdi_folio'             => "ADD COLUMN cfdi_folio             VARCHAR(40)  NULL",
] as $col => $alter) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM checklist_entrega_v2 LIKE '" . $col . "'")->fetchAll();
        if (!$cols) $pdo->exec("ALTER TABLE checklist_entrega_v2 " . $alter);
    } catch (Throwable $e) { error_log('generar-carta-factura column ' . $col . ': ' . $e->getMessage()); }
}

// ── 2. Get checklist (auto-create if missing) ───────────────────────────
$stmt = $pdo->prepare("SELECT id, completado, otp_code, fase4_completada, fase5_fecha,
        punto_taller, dealer_nombre_firma, tipo_acta, metodo_pago_acta,
        cfdi_uuid, cfdi_folio
    FROM checklist_entrega_v2 WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$stmt->execute([$motoId]);
$cl = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cl) {
    $pdo->prepare("INSERT INTO checklist_entrega_v2 (moto_id, dealer_id, fase_actual) VALUES (?, ?, 'fase1')")
        ->execute([$motoId, $uid]);
    $newId = (int)$pdo->lastInsertId();
    $cl = ['id' => $newId, 'completado' => 0, 'otp_code' => null,
           'fase4_completada' => 0, 'fase5_fecha' => null,
           'punto_taller' => null, 'dealer_nombre_firma' => null,
           'tipo_acta' => 'credito', 'metodo_pago_acta' => null,
           'cfdi_uuid' => null, 'cfdi_folio' => null];
}
$checkId = $cl['id'];

// ── 3. Get moto + client + transaction data ─────────────────────────────
$stmt = $pdo->prepare("SELECT m.*, pv.nombre AS punto_nombre_pv, pv.direccion AS punto_direccion
    FROM inventario_motos m
    LEFT JOIN puntos_voltika pv ON pv.id = m.punto_voltika_id
    WHERE m.id = ?");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) adminJsonOut(['error' => 'Moto no encontrada'], 404);

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

$cliente = null;
if (!empty($moto['cliente_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
    $stmt->execute([$moto['cliente_id']]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ── 4. Build display data ───────────────────────────────────────────────
$nombreCompleto = $moto['cliente_nombre'] ?? '';
if ($cliente) {
    $parts = array_filter([$cliente['nombre'] ?? '', $cliente['apellido_paterno'] ?? '', $cliente['apellido_materno'] ?? '']);
    if ($parts) $nombreCompleto = implode(' ', $parts);
}

$rfcCliente   = $cliente['rfc']    ?? ($trans['rfc'] ?? 'XAXX010101000');
$curpCliente  = $cliente['curp']   ?? '';
$emailCliente = $cliente['email']  ?? ($trans['email']    ?? $moto['cliente_email']    ?? '');
$telCliente   = $cliente['telefono'] ?? ($trans['telefono'] ?? $moto['cliente_telefono'] ?? '');
$domicilioCli = $cliente['domicilio'] ?? '';
if (!$domicilioCli && $trans) {
    $partsAddr = array_filter([
        $trans['ciudad'] ?? '', $trans['estado'] ?? '', $trans['cp'] ?? '',
    ]);
    $domicilioCli = implode(', ', $partsAddr);
}

$tipoActa  = $cl['tipo_acta']  ?? (in_array(strtolower($trans['tpago'] ?? ''), ['credito','enganche','parcial'], true) ? 'credito' : 'contado');
$isCredito = $tipoActa === 'credito';
$metodoPago = $cl['metodo_pago_acta'] ?? ($trans['tpago'] ?? 'tarjeta');
$metodoPagoLabel = [
    'tarjeta' => 'Tarjeta (PUE - Pago Único en una Exhibición)',
    'spei'    => 'Transferencia electrónica SPEI',
    'oxxo'    => 'OXXO / pago referenciado',
    'contado' => 'Pago de contado',
    'msi'     => '9 Meses sin intereses (MSI)',
    'credito' => 'Crédito Voltika (compraventa a plazos)',
    'enganche'=> 'Crédito Voltika (compraventa a plazos)',
    'otro'    => 'Otro',
][$metodoPago] ?? $metodoPago;

$precioVehiculo = floatval($trans['total'] ?? $trans['precio'] ?? $moto['precio_venta'] ?? 0);
$ivaIncluido    = $precioVehiculo > 0 ? round($precioVehiculo * 16 / 116, 2) : 0;
$subtotalSinIva = $precioVehiculo - $ivaIncluido;

$folio          = 'CF-' . $motoId . '-' . date('Ymd-His');
$fechaEmision   = date('d/m/Y H:i');
$fechaEntrega   = $cl['fase5_fecha'] ? date('d/m/Y H:i', strtotime($cl['fase5_fecha'])) : $fechaEmision;
$puntoNombre    = $cl['punto_taller'] ?? ($moto['punto_nombre'] ?? $moto['punto_nombre_pv'] ?? 'Punto Voltika Autorizado');
$dealerNombre   = $cl['dealer_nombre_firma'] ?? 'Personal Voltika autorizado';

// CFDI cross-reference (filled by PAC integration when implemented)
$cfdiUuid  = $cl['cfdi_uuid']  ?? '';
$cfdiFolio = $cl['cfdi_folio'] ?? '';

// ── 5. Locate FPDF ──────────────────────────────────────────────────────
$fpdfPaths = [
    __DIR__ . '/../lib/fpdf.php',
    __DIR__ . '/../../../configurador_prueba/php/vendor/fpdf/fpdf.php',
    __DIR__ . '/../../../configurador_prueba/php/vendor/setasign/fpdf/fpdf.php',
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

$storageDir = sys_get_temp_dir() . '/voltika_carta_factura/';
if (!is_dir($storageDir)) @mkdir($storageDir, 0777, true);

$filename = 'carta_factura_moto' . $motoId . '_' . date('Ymd_His') . '.pdf';
$filepath = $storageDir . $filename;

// ── 6. Build PDF ────────────────────────────────────────────────────────
$enc = function($s) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string)$s); };
$fmt = function($n) { return '$' . number_format((float)$n, 2, '.', ',') . ' MXN'; };

$pdf = new FPDF('P', 'mm', 'Letter');
$pdf->SetAutoPageBreak(true, 18);
$pdf->SetTitle($enc('Carta Factura - Voltika'));
$pdf->SetAuthor('Voltika - MTECH GEARS, S.A. DE C.V.');
$pdf->AddPage();

// Brand bar
$pdf->SetFillColor(26, 58, 92);
$pdf->Rect(0, 0, 215.9, 11, 'F');
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 11);
$pdf->SetXY(15, 3);
$pdf->Cell(0, 5, $enc('VOLTIKA · CARTA FACTURA — Documento provisional'), 0, 0, 'L');
$pdf->SetTextColor(0);
$pdf->SetY(16);

// Folio + emisión
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(95, 4, $enc('FOLIO CARTA FACTURA: ' . $folio), 0, 0);
$pdf->Cell(95, 4, $enc('FECHA DE EMISIÓN: ' . $fechaEmision), 0, 1, 'R');
if ($cfdiFolio || $cfdiUuid) {
    $pdf->Cell(95, 4, $enc($cfdiFolio ? 'CFDI FOLIO: ' . $cfdiFolio : ''), 0, 0);
    $pdf->Cell(95, 4, $enc($cfdiUuid  ? 'CFDI UUID: '  . $cfdiUuid  : ''), 0, 1, 'R');
}
$pdf->Ln(3);

$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 7, $enc('CARTA FACTURA'), 0, 1, 'C');
$pdf->SetFont('Arial', '', 8.5);
$pdf->Cell(0, 4, $enc('Documento provisional para trámites de emplacamiento y registro vehicular estatal'), 0, 1, 'C');
$pdf->Ln(3);

// Validity notice (yellow callout)
$pdf->SetFillColor(254, 243, 199);
$pdf->SetDrawColor(245, 158, 11);
$pdf->SetTextColor(120, 53, 15);
$y = $pdf->GetY();
$pdf->Rect(15, $y, 180.9, 14, 'DF');
$pdf->SetXY(18, $y + 1.5);
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->Cell(0, 4, $enc('AVISO DE VALIDEZ'), 0, 1);
$pdf->SetXY(18, $pdf->GetY());
$pdf->SetFont('Arial', '', 7.5);
$pdf->MultiCell(174, 3.4, $enc('La presente CARTA FACTURA es un documento provisional emitido por VOLTIKA conforme a la Cláusula Décima Novena del Contrato de Compraventa. Su validez se mantiene hasta que se entregue la FACTURA ORIGINAL (CFDI) al adquirente o, tratándose de operaciones a plazos, hasta la liquidación total del precio.'));
$pdf->SetTextColor(0);
$pdf->SetDrawColor(0);
$pdf->SetY($y + 16);

// ── DATOS DEL PROVEEDOR ────────────────────────────────────────────────
_cfSectionTitle($pdf, 'DATOS DEL PROVEEDOR');
_cfTable($pdf, [
    ['Razón social',          'MTECH GEARS, S.A. DE C.V.'],
    ['Nombre comercial',      'VOLTIKA'],
    ['RFC',                   'MGE230316KA2'],
    ['Domicilio fiscal',      'Jaime Balmes 71, despacho 101 C, Polanco I Sección, Miguel Hidalgo, C.P. 11510, Ciudad de México'],
    ['Régimen fiscal',        '601 — General de Ley Personas Morales'],
    ['Contacto',              'WhatsApp +52 55 1341 6370 · contacto@voltika.mx'],
], $enc);

// ── DATOS DEL ADQUIRENTE ───────────────────────────────────────────────
_cfSectionTitle($pdf, 'DATOS DEL ADQUIRENTE');
_cfTable($pdf, [
    ['Nombre completo',  $nombreCompleto],
    ['RFC',              $rfcCliente],
    ['CURP',             $curpCliente ?: '—'],
    ['Domicilio',        $domicilioCli ?: '—'],
    ['Correo electrónico', $emailCliente ?: '—'],
    ['Teléfono',         $telCliente ?: '—'],
], $enc);

// ── DATOS DEL VEHÍCULO ─────────────────────────────────────────────────
_cfSectionTitle($pdf, 'DATOS DEL VEHÍCULO');
_cfTable($pdf, [
    ['Marca',                'VOLTIKA'],
    ['Submarca',             'TROMOX'],
    ['Modelo',               $moto['modelo']    ?? '—'],
    ['Color',                $moto['color']     ?? '—'],
    ['Año-modelo',           date('Y')],
    ['Tipo de combustible',  'Eléctrico'],
    ['Número de Identificación Vehicular (VIN/NIV)', $moto['vin_display'] ?? $moto['vin'] ?? '—'],
    ['Número de motor',      $moto['num_motor'] ?? 'Por asignar'],
    ['Número de pedido',     $moto['pedido_num'] ?? '—'],
], $enc);

// ── DATOS DE LA OPERACIÓN ──────────────────────────────────────────────
_cfSectionTitle($pdf, 'DATOS DE LA OPERACIÓN');
_cfTable($pdf, [
    ['Modalidad de pago',                $metodoPagoLabel],
    ['Subtotal (sin IVA)',               $fmt($subtotalSinIva)],
    ['IVA 16% incluido',                 $fmt($ivaIncluido)],
    ['Precio total con IVA',             $fmt($precioVehiculo)],
    ['Fecha de entrega física',          $fechaEntrega],
    ['Punto de entrega autorizado',      $puntoNombre],
    ['Personal de entrega',              $dealerNombre],
], $enc);

// ── REPUVE (Article 23) ────────────────────────────────────────────────
_cfSectionTitle($pdf, 'REGISTRO ANTE REPUVE (Art. 23 Ley del Registro Público Vehicular)');
$pdf->SetFont('Arial', '', 8);
$pdf->MultiCell(0, 4.2, $enc('Conforme al artículo 23 de la Ley del Registro Público Vehicular, VOLTIKA presentará al REPUVE el aviso de compraventa correspondiente, indicando los datos del adquirente como nuevo propietario, dentro del día hábil siguiente al de la facturación. EL ADQUIRENTE podrá obtener su constancia de inscripción REPUVE en www.repuve.gob.mx.'), 0, 'J');
$pdf->Ln(2);

// ── CUSTODIA DE FACTURA ORIGINAL (credit only) ─────────────────────────
if ($isCredito) {
    _cfSectionTitle($pdf, 'CUSTODIA DE LA FACTURA ORIGINAL');
    $pdf->SetFont('Arial', '', 8);
    $pdf->MultiCell(0, 4.2, $enc('De conformidad con la Cláusula Décima Novena del Contrato de Compraventa a Plazos con Reserva de Dominio, VOLTIKA conservará la FACTURA ORIGINAL (CFDI) en custodia hasta la liquidación total del precio a plazo pactado. Una vez liquidado el precio total y cumplidas todas las obligaciones del adquirente, VOLTIKA entregará la factura original al adquirente.'), 0, 'J');
    $pdf->Ln(2);
} else {
    _cfSectionTitle($pdf, 'ENTREGA DE FACTURA');
    $pdf->SetFont('Arial', '', 8);
    $pdf->MultiCell(0, 4.2, $enc('Tratándose de una operación pagada en su totalidad, la FACTURA ORIGINAL (CFDI) se entregará al adquirente dentro de un plazo de 15 (quince) días hábiles contados a partir de la fecha de la entrega física del vehículo, conforme al Contrato de Compraventa.'), 0, 'J');
    $pdf->Ln(2);
}

// ── FIRMA DEL PROVEEDOR ────────────────────────────────────────────────
_cfSectionTitle($pdf, 'FIRMA DEL PROVEEDOR');
$pdf->Ln(8);
$x = $pdf->GetX();
$pdf->Line($x + 50, $pdf->GetY(), $x + 140, $pdf->GetY());
$pdf->Ln(3);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 5, $enc('Por VOLTIKA — MTECH GEARS, S.A. DE C.V.'), 0, 1, 'C');
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(0, 4, $enc($dealerNombre), 0, 1, 'C');
$pdf->Cell(0, 4, $enc('Personal autorizado del Punto de Entrega'), 0, 1, 'C');

// Footer
$pdf->Ln(4);
$pdf->SetFont('Arial', 'I', 7);
$pdf->SetTextColor(120);
$pdf->MultiCell(0, 3.5, $enc('Documento generado automáticamente por el sistema Voltika. La validez de la presente Carta Factura se mantiene hasta la entrega del CFDI original conforme a la Cláusula Décima Novena del Contrato. Folio: ' . $folio), 0, 'C');
$pdf->SetTextColor(0);

$pdf->Output('F', $filepath);

if (!file_exists($filepath) || filesize($filepath) === 0) {
    adminJsonOut(['error' => 'PDF no se escribió'], 500);
}

$pdfHash = hash_file('sha256', $filepath);

// ── 7. Persist + return ─────────────────────────────────────────────────
$pdo->prepare("UPDATE checklist_entrega_v2
    SET carta_factura_pdf_path=?, carta_factura_pdf_hash=?, carta_factura_folio=?, carta_factura_fecha=NOW()
    WHERE id=?")
    ->execute([$filename, $pdfHash, $folio, $checkId]);

adminLog('carta_factura_generada', [
    'moto_id'      => $motoId,
    'checklist_id' => $checkId,
    'folio'        => $folio,
    'pdf_hash'     => $pdfHash,
]);

adminJsonOut([
    'ok'        => true,
    'folio'     => $folio,
    'pdf_path'  => $filename,
    'pdf_hash'  => $pdfHash,
    'datos'     => [
        'nombre' => $nombreCompleto,
        'vin'    => $moto['vin_display'] ?? $moto['vin'] ?? '',
        'precio' => $precioVehiculo,
        'modalidad' => $metodoPagoLabel,
    ],
]);

// ── Layout helpers ──────────────────────────────────────────────────────

function _cfSectionTitle(FPDF $pdf, string $title): void {
    $pdf->SetFillColor(241, 245, 249);
    $pdf->SetTextColor(26, 58, 92);
    $pdf->SetFont('Arial', 'B', 9);
    $enc = function($s) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string)$s); };
    $pdf->Cell(0, 6, $enc(' ' . $title), 0, 1, 'L', true);
    $pdf->SetTextColor(0);
    $pdf->Ln(0.5);
}

function _cfTable(FPDF $pdf, array $rows, callable $enc): void {
    $w1 = 70; $w2 = 109.9; $h = 5.5;
    foreach ($rows as $r) {
        $label = (string)$r[0];
        $value = (string)$r[1];
        if ($value === '') $value = '—';

        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetFillColor(248, 250, 252);
        $pdf->Cell($w1, $h, $enc($label), 1, 0, 'L', true);

        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell($w2, $h, $enc($value), 1, 1, 'L');
    }
    $pdf->Ln(1);
}
