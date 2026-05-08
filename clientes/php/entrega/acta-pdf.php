<?php
/**
 * Customer-portal-scoped ACTA DE ENTREGA PDF generator.
 *
 * Bug 5.7 (customer brief 2026-05-08): the customer signs the ACTA via
 * Cincel from their own portal. Cincel needs a stable PDF URL/binary to
 * upload as a document, so this endpoint generates the slim, sign-ready
 * version of the ACTA on demand.
 *
 * Why a separate endpoint (vs. reusing admin/php/checklists/generar-acta.php):
 *   - The admin generator requires adminRequireAuth and includes additional
 *     fields (dealer signature, NOM-151 timestamp pipeline) that we don't
 *     want to expose to portal sessions.
 *   - Generating inline keeps the customer flow self-contained and avoids
 *     cross-folder auth bridges that would risk unrelated breakage.
 *
 * GET ?moto_id=N  → application/pdf (binary)
 * POST {moto_id}  → JSON { ok, pdf_path, pdf_url } (saves to disk + returns URL)
 *
 * Both modes auth via portalRequireAuth and verify ownership of the moto.
 */
require_once __DIR__ . '/../bootstrap.php';
$cid = portalRequireAuth();

$mode = $_SERVER['REQUEST_METHOD'] === 'POST' ? 'save' : 'stream';

if ($mode === 'save') {
    $in = portalJsonIn();
    $motoId = (int)($in['moto_id'] ?? 0);
} else {
    $motoId = (int)($_GET['moto_id'] ?? 0);
}
if (!$motoId) {
    if ($mode === 'save') portalJsonOut(['error' => 'moto_id requerido'], 400);
    http_response_code(400); exit('moto_id requerido');
}

$pdo = getDB();
$moto = portalFindOwnedMoto($cid, $motoId);
if (!$moto) {
    if ($mode === 'save') portalJsonOut(['error' => 'Moto no encontrada o no te pertenece'], 404);
    http_response_code(404); exit('Moto no encontrada');
}

// Punto info — for delivery address
$punto = null;
if (!empty($moto['punto_voltika_id'])) {
    $pq = $pdo->prepare("SELECT nombre, ciudad, estado, direccion FROM puntos_voltika WHERE id=?");
    $pq->execute([(int)$moto['punto_voltika_id']]);
    $punto = $pq->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Locate FPDF (reuses admin's vendored copy) ────────────────────────────
$fpdfPaths = [
    __DIR__ . '/../../../admin/php/lib/fpdf.php',
    __DIR__ . '/../../../admin_test/php/lib/fpdf.php',
    __DIR__ . '/../../../configurador/php/vendor/fpdf/fpdf.php',
    __DIR__ . '/../../../configurador/php/vendor/setasign/fpdf/fpdf.php',
];
$fpdfFound = false;
foreach ($fpdfPaths as $fp) {
    if (file_exists($fp)) { require_once $fp; $fpdfFound = true; break; }
}
if (!$fpdfFound) {
    if ($mode === 'save') portalJsonOut(['error' => 'FPDF no disponible en el servidor'], 500);
    http_response_code(500); exit('FPDF no disponible');
}

$enc = function($s) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string)$s); };

// ── Build PDF ─────────────────────────────────────────────────────────────
$pdf = new FPDF('P', 'mm', 'Letter');
$pdf->SetAutoPageBreak(true, 15);
$pdf->SetTitle($enc('Acta de Entrega - Voltika'));
$pdf->SetAuthor('Voltika - MTECH GEARS, S.A. DE C.V.');
$pdf->AddPage();

$folio = 'ACT-' . $motoId . '-' . date('Ymd-His');
$fechaEntrega = date('d/m/Y H:i');

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
$pdf->Cell(0, 7, $enc('ACTA DE ENTREGA DE VEHÍCULO'), 0, 1, 'C');
$pdf->SetFont('Arial', '', 8.5);
$pdf->Cell(0, 4, $enc('MTECH GEARS, S.A. DE C.V. — Voltika'), 0, 1, 'C');
$pdf->Ln(3);

$nombreCompleto = trim(($moto['cliente_nombre'] ?? ''));
$puntoNombre    = $punto['nombre'] ?? '';
$puntoCiudad    = $punto['ciudad'] ?? ($moto['ciudad'] ?? 'México');

// Opening declaration
$pdf->SetFont('Arial', '', 9);
$pdf->MultiCell(0, 4.5, $enc('En este acto, EL CLIENTE declara haber recibido la motocicleta eléctrica descrita en el presente documento, en condiciones óptimas de funcionamiento, completa y conforme a lo contratado.'), 0, 'J');
$pdf->Ln(3);

// Datos
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 5, $enc('DATOS DE LA OPERACIÓN'), 0, 1);
$pdf->SetFont('Arial', '', 9);

$rows = [
    ['Cliente',         $nombreCompleto],
    ['Modelo',          $moto['modelo']  ?? '—'],
    ['Color',           $moto['color']   ?? '—'],
    ['VIN / NIV',       $moto['vin_display'] ?? $moto['vin'] ?? '—'],
    ['Pedido / folio',  $moto['pedido_num']  ?? '—'],
    ['Fecha y hora',    $fechaEntrega],
    ['Punto de entrega', $puntoNombre . ($puntoCiudad ? ' — ' . $puntoCiudad : '')],
];
foreach ($rows as $r) {
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(60, 5, $enc($r[0] . ':'), 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 5, $enc($r[1]), 0);
}
$pdf->Ln(3);

// Cláusulas
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 5, $enc('DECLARACIONES DEL CLIENTE'), 0, 1);
$pdf->SetFont('Arial', '', 9);
$decls = [
    'El vehículo fue entregado en perfectas condiciones físicas y mecánicas, con todos sus componentes y accesorios completos según el checklist verificado por el personal Voltika.',
    'A partir de este momento, el CLIENTE asume la responsabilidad total del uso, custodia y cuidado del vehículo.',
    'EL CLIENTE recibió información sobre garantía, uso correcto y medidas de seguridad del vehículo eléctrico.',
    'EL CLIENTE acreditó su identidad mediante INE original y el código OTP enviado a su teléfono registrado.',
    'EL CLIENTE acepta el contenido de la presente acta y firma electrónicamente con validez NOM-151 a través del proveedor Cincel.',
];
foreach ($decls as $i => $d) {
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(7, 5, ($i + 1) . '.', 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 4.8, $enc($d), 0, 'J');
    $pdf->Ln(1);
}

$pdf->Ln(8);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(0, 5, $enc('Firma electrónica del cliente:'), 0, 1);
$pdf->Ln(15);
$pdf->Line(20, $pdf->GetY(), 110, $pdf->GetY());
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(90, 5, $enc($nombreCompleto), 0, 1);
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(90, 4, $enc('Firmado mediante Cincel — NOM-151'), 0, 1);

if ($mode === 'stream') {
    // Direct download — used for preview from the customer portal.
    $pdf->Output('I', 'acta_' . $motoId . '.pdf');
    exit;
}

// Save mode — persist to disk and return a public URL Cincel can fetch.
$dir = __DIR__ . '/../../../configurador/php/uploads/actas';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
$filename = 'acta_cliente_' . $motoId . '_' . date('Ymd_His') . '.pdf';
$filepath = $dir . '/' . $filename;
$pdf->Output('F', $filepath);

// Return a URL Cincel can fetch from. We compose absolute scheme+host so
// remote services can reach it through the same CDN/proxy users hit.
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'voltika.mx';
$publicUrl = $scheme . '://' . $host . '/configurador/php/uploads/actas/' . $filename;

portalJsonOut([
    'ok'        => true,
    'pdf_path'  => 'configurador/php/uploads/actas/' . $filename,
    'pdf_url'   => $publicUrl,
    'pdf_hash'  => hash_file('sha256', $filepath),
    'folio'     => $folio,
]);
