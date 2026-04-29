<?php
/**
 * POST — Generate immutable Acta de Entrega PDF (FPDF) at delivery.
 * Body: { moto_id, firma_data (base64 PNG, optional) }
 * Returns: { ok, pdf_path, pdf_hash, folio }
 *
 * Mirrors generar-pagare.php. The Acta de Entrega is the legal record of
 * the in-person delivery: identity validation (INE + OTP), inspection
 * conformity, signed by the customer at the point of delivery. v5
 * Cláusula Vigésima Segunda (and Cláusula Trigésima Primera for NOM-151)
 * make this document Cincel-timestamped after signing.
 *
 * Until 2026-04-29 the Acta was only emitted as an HTML/print page (no
 * stable PDF, no SHA-256, no Cincel timestamp). This generator closes
 * that compliance gap without removing the existing HTML viewer
 * (admin-generar-acta-pdf.php) — both can coexist.
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$motoId   = (int)($d['moto_id'] ?? 0);
$firmaB64 = $d['firma_data'] ?? '';

if (!$motoId) adminJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();

// ── 1. Get checklist (auto-create if missing) ───────────────────────────
$stmt = $pdo->prepare("SELECT id, completado, otp_code, otp_timestamp,
        fase4_completada, fase4_fecha, fase5_fecha, dealer_nombre_firma,
        punto_taller, tipo_acta, metodo_pago_acta
    FROM checklist_entrega_v2 WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$stmt->execute([$motoId]);
$cl = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cl) {
    $pdo->prepare("INSERT INTO checklist_entrega_v2 (moto_id, dealer_id, fase_actual) VALUES (?, ?, 'fase1')")
        ->execute([$motoId, $uid]);
    $newId = (int)$pdo->lastInsertId();
    $cl = [
        'id' => $newId, 'completado' => 0, 'otp_code' => null, 'otp_timestamp' => null,
        'fase4_completada' => 0, 'fase4_fecha' => null, 'fase5_fecha' => null,
        'dealer_nombre_firma' => null, 'punto_taller' => null,
        'tipo_acta' => 'credito', 'metodo_pago_acta' => null,
    ];
}

if ($cl['completado']) adminJsonOut(['error' => 'Checklist ya completado'], 403);
$checkId = $cl['id'];

// ── 2. Lazy-add columns the new Acta-Cincel flow needs ──────────────────
foreach ([
    'acta_pdf_path'        => "ADD COLUMN acta_pdf_path        VARCHAR(255) NULL",
    'acta_pdf_hash'        => "ADD COLUMN acta_pdf_hash        CHAR(64)     NULL",
    'acta_pdf_cincel_id'   => "ADD COLUMN acta_pdf_cincel_id   VARCHAR(120) NULL",
    'acta_pdf_timestamp'   => "ADD COLUMN acta_pdf_timestamp   DATETIME     NULL",
    'acta_ip'              => "ADD COLUMN acta_ip              VARCHAR(45)  NULL",
    'acta_user_agent'      => "ADD COLUMN acta_user_agent      VARCHAR(500) NULL",
    'acta_evidencia'       => "ADD COLUMN acta_evidencia       MEDIUMTEXT   NULL",
] as $col => $alter) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM checklist_entrega_v2 LIKE '" . $col . "'")->fetchAll();
        if (!$cols) $pdo->exec("ALTER TABLE checklist_entrega_v2 " . $alter);
    } catch (Throwable $e) { error_log('generar-acta column ' . $col . ': ' . $e->getMessage()); }
}

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

$tipoActa  = $cl['tipo_acta']        ?? ($trans['tpago'] === 'credito' || $trans['tpago'] === 'enganche' ? 'credito' : 'contado');
$isContado = $tipoActa !== 'credito';
$metodoPago = $cl['metodo_pago_acta'] ?? ($trans['tpago'] ?? 'tarjeta');
$metodoPagoLabel = [
    'tarjeta' => 'Tarjeta (incluye MSI)',
    'spei'    => 'Transferencia SPEI',
    'oxxo'    => 'OXXO / pago referenciado',
    'contado' => 'Pago de contado',
    'msi'     => '9 Meses sin intereses (MSI)',
    'credito' => 'Crédito Voltika',
    'enganche'=> 'Crédito Voltika',
    'otro'    => 'Otro',
][$metodoPago] ?? $metodoPago;

$fechaEntrega   = $cl['fase5_fecha'] ? date('d/m/Y H:i', strtotime($cl['fase5_fecha'])) : date('d/m/Y H:i');
$puntoNombre    = $cl['punto_taller'] ?? ($moto['punto_nombre'] ?? $moto['punto_nombre_pv'] ?? 'Punto Voltika Autorizado');
$puntoDireccion = $moto['punto_direccion'] ?? '';
$dealerNombre   = $cl['dealer_nombre_firma'] ?? 'Personal Voltika autorizado';

$folio = 'ACT-' . $motoId . '-' . date('Ymd-His');

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

$storageDir = sys_get_temp_dir() . '/voltika_actas/';
if (!is_dir($storageDir)) @mkdir($storageDir, 0777, true);

$filename = 'acta_moto' . $motoId . '_' . date('Ymd_His') . '.pdf';
$filepath = $storageDir . $filename;

// Save signature image if provided
$firmaImgPath = null;
if ($firmaB64 && strpos($firmaB64, 'data:image/png;base64,') === 0) {
    $firmaData = base64_decode(str_replace('data:image/png;base64,', '', $firmaB64));
    $firmaImgPath = $storageDir . 'firma_acta_' . $motoId . '_' . date('Ymd_His') . '.png';
    file_put_contents($firmaImgPath, $firmaData);
}

// ── 6. Build PDF ────────────────────────────────────────────────────────
$enc = function($s) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string)$s); };

$pdf = new FPDF('P', 'mm', 'Letter');
$pdf->SetAutoPageBreak(true, 15);
$pdf->SetTitle($enc('Acta de Entrega - Voltika'));
$pdf->SetAuthor('Voltika - MTECH GEARS, S.A. DE C.V.');
$pdf->AddPage();

// Brand bar
$pdf->SetFillColor(26, 58, 92);
$pdf->Rect(0, 0, 215.9, 11, 'F');
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 11);
$pdf->SetXY(15, 3);
$pdf->Cell(0, 5, $enc('VOLTIKA · ACTA DE ENTREGA DE MOTOCICLETA ELÉCTRICA'), 0, 0, 'L');
$pdf->SetTextColor(0);
$pdf->SetY(16);

// Subtitle + folio
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(95, 4, $enc('FOLIO: ' . $folio), 0, 0);
$pdf->Cell(95, 4, $enc('FECHA Y HORA: ' . $fechaEntrega), 0, 1, 'R');
$pdf->Ln(2);

$pdf->SetFont('Arial', 'B', 13);
$pdf->Cell(0, 7, $enc('ACTA DE ENTREGA'), 0, 1, 'C');
$pdf->SetFont('Arial', '', 8.5);
$pdf->Cell(0, 4, $enc('MTECH GEARS, S.A. DE C.V. — Voltika'), 0, 1, 'C');
$pdf->Ln(3);

// Opening declaration
$pdf->SetFont('Arial', '', 9);
if ($isContado) {
    $pdf->MultiCell(0, 4.5, $enc('En este acto, EL CLIENTE declara haber recibido la motocicleta eléctrica descrita en el presente documento, en condiciones óptimas de funcionamiento, completa y conforme a lo contratado, derivado de una operación de compraventa pagada en su totalidad.'), 0, 'J');
} else {
    $pdf->MultiCell(0, 4.5, $enc('En este acto, EL CLIENTE declara haber recibido la motocicleta eléctrica descrita en el presente documento, en condiciones óptimas de funcionamiento, completa y conforme a lo contratado, en el marco del Contrato de Compraventa a Plazos con Reserva de Dominio celebrado con VOLTIKA.'), 0, 'J');
}
$pdf->Ln(3);

// ── DATOS DE LA OPERACIÓN ──────────────────────────────────────────────
_actaSectionTitle($pdf, 'DATOS DE LA OPERACIÓN');
$rows = [
    ['Nombre completo del cliente', $nombreCompleto],
    ['Modelo',                       $moto['modelo'] ?? '—'],
    ['Color',                        $moto['color'] ?? '—'],
    ['Número de Identificación Vehicular (VIN/NIV)', $moto['vin_display'] ?? $moto['vin'] ?? '—'],
    [$isContado ? 'Número de pedido / folio' : 'Número de contrato', $moto['pedido_num'] ?? '—'],
    ['Modalidad de pago',            $metodoPagoLabel],
    ['Fecha y hora de entrega',      $fechaEntrega],
    ['Punto de entrega autorizado',  $puntoNombre . ($puntoDireccion ? ' — ' . $puntoDireccion : '')],
];
_actaTable($pdf, $rows, $enc);

// ── VALIDACIÓN DE IDENTIDAD ────────────────────────────────────────────
_actaSectionTitle($pdf, 'VALIDACIÓN DE IDENTIDAD (Cláusula Vigésima Segunda)');
$pdf->SetFont('Arial', '', 9);
$pdf->MultiCell(0, 4.5, $enc('Al momento de la entrega, EL CLIENTE acreditó su identidad mediante los siguientes mecanismos:'), 0, 'J');
$pdf->Ln(1);
$validacion = [
    ['Identificación oficial vigente presentada en original (INE / pasaporte / cédula profesional)', '✓'],
    ['Coincidencia de nombre con el registrado en el Contrato',                                       '✓'],
    ['Validación de Código OTP enviado al teléfono registrado',                                       $cl['fase4_completada'] ? '✓ Validado' : '— Pendiente'],
    ['Verificación visual por personal de entrega de VOLTIKA',                                        '✓'],
];
_actaChecklist($pdf, $validacion, $enc);
if (!empty($cl['otp_code'])) {
    $pdf->Ln(0.5);
    $pdf->SetFont('Arial', 'I', 7.5);
    $pdf->SetTextColor(100);
    $pdf->Cell(0, 4, $enc('Código OTP validado: ' . $cl['otp_code']
        . ($cl['otp_timestamp'] ? ' (a las ' . date('H:i:s', strtotime($cl['otp_timestamp'])) . ' UTC)' : '')), 0, 1);
    $pdf->SetTextColor(0);
}
$pdf->Ln(2);

// ── INSPECCIÓN DEL VEHÍCULO ────────────────────────────────────────────
_actaSectionTitle($pdf, 'INSPECCIÓN DEL VEHÍCULO');
$pdf->SetFont('Arial', '', 9);
$pdf->MultiCell(0, 4.5, $enc('EL CLIENTE inspeccionó físicamente el vehículo y verificó los siguientes aspectos:'), 0, 'J');
$pdf->Ln(1);
$inspeccion = [
    ['Coincidencia con lo pactado en la Carátula (modelo, color, especificaciones, accesorios)', '✓'],
    ['Estado físico del vehículo, sin daños ni desperfectos visibles',                            '✓'],
    ['Funcionamiento básico (encendido, luces, frenos, sistema eléctrico)',                       '✓'],
    ['Documentación entregada (carta factura, manuales, llaves, accesorios)',                     '✓'],
];
_actaChecklist($pdf, $inspeccion, $enc);
$pdf->Ln(2);

// ── DECLARACIONES DE CONFORMIDAD ───────────────────────────────────────
_actaSectionTitle($pdf, 'DECLARACIONES DE CONFORMIDAD');
$pdf->SetFont('Arial', '', 8.5);
$declaraciones = [
    'EL CLIENTE manifiesta su conformidad expresa con las condiciones del vehículo recibido y con la operación celebrada.',
    'EL CLIENTE reconoce que la firma de la presente Acta constituye MANIFESTACIÓN EXPRESA de aceptación y recepción del vehículo, conforme a las cláusulas correspondientes del Contrato.',
    'EL CLIENTE reconoce que, posteriormente a la firma del Acta de Entrega, las inconformidades se atenderán conforme al procedimiento de garantía y vicios ocultos previstos en la legislación aplicable (artículos 77 y 79 LFPC).',
];
if (!$isContado) {
    $declaraciones[] = 'EL CLIENTE reconoce que VOLTIKA mantiene la propiedad del vehículo hasta el pago total del precio a plazo conforme a la Cláusula Décima Sexta (Reserva de Dominio).';
    $declaraciones[] = 'EL CLIENTE confirma que ha suscrito el PAGARÉ correspondiente al saldo total a plazo, conforme a la Cláusula Décima Octava.';
}
foreach ($declaraciones as $decl) {
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(4, 4.5, $enc('•'), 0, 0);
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->MultiCell(0, 4.5, $enc($decl), 0, 'J');
    $pdf->Ln(0.4);
}
$pdf->Ln(2);

// ── FIRMA ──────────────────────────────────────────────────────────────
_actaSectionTitle($pdf, 'FIRMA');
$pdf->Ln(2);

if ($firmaImgPath && file_exists($firmaImgPath)) {
    $pdf->Image($firmaImgPath, $pdf->GetX() + 20, $pdf->GetY(), 70, 25);
    $pdf->Ln(28);
} else {
    $pdf->Ln(10);
    $x = $pdf->GetX();
    $pdf->Line($x + 20, $pdf->GetY(), $x + 110, $pdf->GetY());
    $pdf->Ln(3);
}

$pdf->SetFont('Arial', '', 9);
$pdf->Cell(0, 5, $enc('Nombre: ' . $nombreCompleto), 0, 1);
$pdf->Cell(0, 5, $enc('Folio del Acta: ' . $folio), 0, 1);
$pdf->Cell(0, 5, $enc('Fecha y hora: ' . $fechaEntrega), 0, 1);

$pdf->Ln(4);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(0, 5, $enc('Por VOLTIKA — ' . $dealerNombre), 0, 1);
$pdf->Ln(8);
$x = $pdf->GetX();
$pdf->Line($x + 20, $pdf->GetY(), $x + 110, $pdf->GetY());
$pdf->Ln(3);
$pdf->SetFont('Arial', 'I', 8);
$pdf->Cell(0, 4, $enc('Personal autorizado del Punto de Entrega'), 0, 1);

// ── EVIDENCIA TÉCNICA ──────────────────────────────────────────────────
$pdf->Ln(4);
_actaSectionTitle($pdf, 'EVIDENCIA TÉCNICA DE LA OPERACIÓN');
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip) $ip = trim(explode(',', $ip)[0]);
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$evidenciaRows = [
    ['Folio del Acta',                $folio],
    ['Folio del pedido / contrato',   $moto['pedido_num'] ?? '—'],
    ['VIN / NIV',                     $moto['vin'] ?? '—'],
    ['Fecha y hora (UTC)',            gmdate('Y-m-d H:i:s')],
    ['Dirección IP',                  $ip ?: '—'],
    ['Dispositivo',                   substr($ua, 0, 90) ?: '—'],
    ['OTP validado',                  $cl['fase4_completada'] ? 'Sí' : 'No'],
    ['Operador',                      'admin_id=' . $uid],
];
_actaTable($pdf, $evidenciaRows, $enc, 7.5);

// Footer
$pdf->Ln(2);
$pdf->SetFont('Arial', 'I', 7);
$pdf->SetTextColor(100);
$pdf->MultiCell(0, 3.5, $enc('Documento generado automáticamente y certificado conforme a la NOM-151-SCFI-2016 mediante Cincel S.A.P.I. de C.V. La firma electrónica avanzada tiene la misma validez jurídica que una firma autógrafa conforme al artículo 89 del Código de Comercio.'), 0, 'C');
$pdf->SetTextColor(0);

$pdf->Output('F', $filepath);

// Clean up signature temp
if ($firmaImgPath && file_exists($firmaImgPath)) {
    @unlink($firmaImgPath);
}

if (!file_exists($filepath) || filesize($filepath) === 0) {
    adminJsonOut(['error' => 'PDF no se escribió'], 500);
}

$pdfHash = hash_file('sha256', $filepath);

// ── 7. Persist evidence + return ────────────────────────────────────────
$evidencia = json_encode([
    'ip'             => $ip,
    'user_agent'     => substr($ua, 0, 500),
    'fecha_hora'     => date('Y-m-d H:i:s'),
    'otp_validado'   => $cl['fase4_completada'] ? true : false,
    'otp_code'       => $cl['otp_code']      ?? null,
    'otp_timestamp'  => $cl['otp_timestamp'] ?? null,
    'vin'            => $moto['vin']         ?? null,
    'folio'          => $folio,
    'moto_id'        => $motoId,
    'generado_por'   => $uid,
], JSON_UNESCAPED_UNICODE);

$pdo->prepare("UPDATE checklist_entrega_v2
    SET acta_pdf_path=?, acta_pdf_hash=?, acta_ip=?, acta_user_agent=?, acta_evidencia=?
    WHERE id=?")
    ->execute([$filename, $pdfHash, $ip, substr($ua, 0, 500), $evidencia, $checkId]);

adminLog('acta_pdf_generado', [
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
        'punto'  => $puntoNombre,
        'fecha'  => $fechaEntrega,
    ],
]);

// ── Layout helpers ──────────────────────────────────────────────────────

function _actaSectionTitle(FPDF $pdf, string $title): void {
    $pdf->SetFillColor(241, 245, 249);
    $pdf->SetTextColor(26, 58, 92);
    $pdf->SetFont('Arial', 'B', 9);
    $enc = function($s) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string)$s); };
    $pdf->Cell(0, 6, $enc(' ' . $title), 0, 1, 'L', true);
    $pdf->SetTextColor(0);
    $pdf->Ln(1);
}

function _actaTable(FPDF $pdf, array $rows, callable $enc, float $fontSize = 8.5): void {
    $w1 = 70; $w2 = 109.9; $h = 5.5;
    foreach ($rows as $r) {
        $label = (string)$r[0];
        $value = (string)$r[1];
        if ($value === '') $value = '—';

        $pdf->SetFont('Arial', 'B', $fontSize);
        $pdf->SetFillColor(248, 250, 252);
        $pdf->Cell($w1, $h, $enc($label), 1, 0, 'L', true);

        $pdf->SetFont('Arial', '', $fontSize);
        $pdf->Cell($w2, $h, $enc($value), 1, 1, 'L');
    }
    $pdf->Ln(1);
}

function _actaChecklist(FPDF $pdf, array $items, callable $enc): void {
    $pdf->SetFont('Arial', '', 8.5);
    foreach ($items as $it) {
        $label = (string)$it[0];
        $mark  = (string)$it[1];
        $isCheck = strpos($mark, '✓') !== false || strpos($mark, '\u{2713}') !== false || $mark === 'Validado';
        $pdf->SetTextColor($isCheck ? 16 : 200, $isCheck ? 185 : 50, $isCheck ? 129 : 50);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(6, 4.5, $enc($mark), 0, 0);
        $pdf->SetTextColor(0);
        $pdf->SetFont('Arial', '', 8.5);
        $pdf->MultiCell(0, 4.5, $enc($label));
        $pdf->Ln(0.3);
    }
}
