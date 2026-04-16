<?php
/**
 * GET — Download/generate document PDF for authenticated portal client
 * ?tipo=carta_factura | contrato | pagare | comprobantes | acta_entrega
 *
 * Strategy:
 *  1. Try to find a pre-generated file on disk (contratos/, pagares/, etc.)
 *  2. If not found, generate the PDF on-the-fly from DB data using FPDF
 */
require_once __DIR__ . '/../bootstrap.php';
header_remove('Content-Type');

$cid  = portalRequireAuth();
$tipo = $_GET['tipo'] ?? '';
$pdo  = getDB();

$info = portalComputeAccountState($cid);
$alCorriente = in_array($info['state'], ['account_current','payment_due_soon','payment_due_today']);

if ($tipo === 'carta_factura' && !$alCorriente) {
    header('Content-Type: application/json');
    portalJsonOut(['error' => 'Para activar tu carta factura, tu compra debe estar al corriente.'], 403);
}

// Log download
try {
    $stmt = $pdo->prepare("INSERT INTO portal_descargas_log (cliente_id, doc_type, ip, user_agent) VALUES (?,?,?,?)");
    $stmt->execute([$cid, $tipo, $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
} catch (Throwable $e) {}

// ── Load client + subscription + moto data ─────────────────────────────
$cliente = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
    $stmt->execute([$cid]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$sub = $info['subscripcion'] ?? null;

$moto = null;
try {
    if ($sub && !empty($sub['inventario_moto_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ?");
        $stmt->execute([$sub['inventario_moto_id']]);
        $moto = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$moto && $cliente) {
        $tel = $cliente['telefono'] ?? '';
        if ($tel) {
            $stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE cliente_telefono = ? AND activo = 1 ORDER BY fmod DESC LIMIT 1");
            $stmt->execute([$tel]);
            $moto = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $e) {}

$trans = null;
try {
    if ($moto && !empty($moto['transaccion_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM transacciones WHERE id = ?");
        $stmt->execute([$moto['transaccion_id']]);
        $trans = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$trans && $cliente && !empty($cliente['email'])) {
        $stmt = $pdo->prepare("SELECT * FROM transacciones WHERE email = ? ORDER BY freg DESC LIMIT 1");
        $stmt->execute([$cliente['email']]);
        $trans = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$trans && $cliente && !empty($cliente['telefono'])) {
        $stmt = $pdo->prepare("SELECT * FROM transacciones WHERE telefono = ? ORDER BY freg DESC LIMIT 1");
        $stmt->execute([$cliente['telefono']]);
        $trans = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}

$nombreCompleto = '';
if ($cliente) {
    $parts = array_filter([$cliente['nombre'] ?? '', $cliente['apellido_paterno'] ?? '', $cliente['apellido_materno'] ?? '']);
    $nombreCompleto = $parts ? implode(' ', $parts) : '';
}
if (!$nombreCompleto && $moto) $nombreCompleto = $moto['cliente_nombre'] ?? '';
if (!$nombreCompleto && $trans) $nombreCompleto = $trans['nombre'] ?? '';

// ── FPDF loader ────────────────────────────────────────────────────────
function loadFPDF(): bool {
    if (class_exists('FPDF')) return true;
    $paths = [
        __DIR__ . '/../../../configurador_prueba/php/vendor/fpdf/fpdf.php',
        __DIR__ . '/../../../admin/php/lib/fpdf.php',
    ];
    foreach ($paths as $p) {
        if (file_exists($p)) { require_once $p; return true; }
    }
    return false;
}

// ── Helper: serve PDF from string ──────────────────────────────────────
function servePDF(string $content, string $filename): void {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}

// ── Helper: serve PDF from file ────────────────────────────────────────
function serveFile(string $path, string $filename): void {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// CONTRATO
// ═══════════════════════════════════════════════════════════════════════
if ($tipo === 'contrato') {
    // 1. Try pre-generated file on disk
    $searchDirs = [
        __DIR__ . '/../../../configurador_prueba/php/contratos/',
        __DIR__ . '/../../../configurador_prueba/php/uploads/contratos/',
        sys_get_temp_dir() . '/voltika_contratos/',
    ];
    foreach ($searchDirs as $dir) {
        if (!is_dir($dir)) continue;
        $files = glob($dir . 'contrato_*' . date('Ymd') . '*.pdf');
        // Also search by client name
        if ($nombreCompleto) {
            $namePart = preg_replace('/[^a-zA-Z0-9]/', '_', $nombreCompleto);
            $nameFiles = glob($dir . 'contrato_*' . $namePart . '*.pdf');
            if ($nameFiles) $files = array_merge($files, $nameFiles);
        }
        // Search all contrato files, newest first
        if (!$files) $files = glob($dir . 'contrato_*.pdf');
        if ($files) {
            usort($files, function($a, $b) { return filemtime($b) - filemtime($a); });
            serveFile($files[0], 'contrato_voltika.pdf');
        }
    }

    // 2. Check firmas_contratos table for pdf_file reference
    try {
        $stmt = $pdo->prepare("SELECT pdf_file FROM firmas_contratos WHERE email = ? OR telefono = ? ORDER BY freg DESC LIMIT 1");
        $email = $cliente['email'] ?? ($trans['email'] ?? '');
        $tel   = $cliente['telefono'] ?? ($trans['telefono'] ?? '');
        $stmt->execute([$email, $tel]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['pdf_file']) {
            foreach ($searchDirs as $dir) {
                $f = $dir . $row['pdf_file'];
                if (file_exists($f)) serveFile($f, 'contrato_voltika.pdf');
            }
        }
    } catch (Throwable $e) {}

    // 3. Generate minimal contrato PDF on-the-fly
    if (loadFPDF() && ($nombreCompleto || $trans)) {
        $enc = function($s) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string)$s); };
        $fmt = function($n) { return '$' . number_format((float)$n, 2) . ' MXN'; };
        $pdf = new FPDF();
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, $enc('CONTRATO DE COMPRAVENTA'), 0, 1, 'C');
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(0, 5, $enc('MTECH GEARS S.A. DE C.V. (VOLTIKA)'), 0, 1, 'C');
        $pdf->Ln(6);

        $modelo = $sub['modelo'] ?? ($moto['modelo'] ?? ($trans['modelo'] ?? '—'));
        $color  = $sub['color']  ?? ($moto['color']  ?? ($trans['color']  ?? '—'));
        $total  = $trans['total'] ?? ($sub['precio_contado'] ?? 0);

        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 8, $enc('DATOS DEL CLIENTE'), 0, 1);
        $w1 = 45; $h = 7;
        $rows = [
            ['Nombre', $nombreCompleto],
            ['Email', $cliente['email'] ?? ($trans['email'] ?? '—')],
            ['Telefono', $cliente['telefono'] ?? ($trans['telefono'] ?? '—')],
        ];
        foreach ($rows as $r) {
            $pdf->SetFont('Arial', 'B', 9); $pdf->Cell($w1, $h, $enc($r[0] . ':'), 1);
            $pdf->SetFont('Arial', '', 9);  $pdf->Cell(0, $h, $enc($r[1]), 1, 1);
        }

        $pdf->Ln(4);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 8, $enc('DATOS DEL VEHICULO'), 0, 1);
        $vRows = [
            ['Marca', 'VOLTIKA'],
            ['Modelo', $modelo],
            ['Color', $color],
            ['VIN', $moto['vin_display'] ?? ($moto['vin'] ?? 'Por asignar')],
        ];
        foreach ($vRows as $r) {
            $pdf->SetFont('Arial', 'B', 9); $pdf->Cell($w1, $h, $enc($r[0] . ':'), 1);
            $pdf->SetFont('Arial', '', 9);  $pdf->Cell(0, $h, $enc($r[1]), 1, 1);
        }

        $pdf->Ln(4);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 8, $enc('CONDICIONES'), 0, 1);
        $cRows = [['Precio total', $fmt($total)]];
        if ($sub) {
            $cRows[] = ['Enganche', $fmt($sub['enganche'] ?? 0)];
            $cRows[] = ['Pago semanal', $fmt($sub['monto_semanal'] ?? 0)];
            $cRows[] = ['Plazo', ($sub['plazo_semanas'] ?? ($sub['plazo_meses'] ? round($sub['plazo_meses'] * 4.33) : '—')) . ' semanas'];
        }
        foreach ($cRows as $r) {
            $pdf->SetFont('Arial', 'B', 9); $pdf->Cell($w1, $h, $enc($r[0] . ':'), 1);
            $pdf->SetFont('Arial', '', 9);  $pdf->Cell(0, $h, $enc($r[1]), 1, 1);
        }

        $pdf->Ln(6);
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->Cell(0, 5, $enc('Documento generado el ' . date('d/m/Y H:i')), 0, 1, 'R');

        servePDF($pdf->Output('S'), 'contrato_voltika.pdf');
    }

    header('Content-Type: application/json');
    portalJsonOut(['error' => 'Contrato no disponible todavia'], 404);
}

// ═══════════════════════════════════════════════════════════════════════
// PAGARE
// ═══════════════════════════════════════════════════════════════════════
if ($tipo === 'pagare') {
    // 1. Check checklist_entrega_v2 for saved pagaré
    try {
        $stmt = $pdo->prepare("SELECT ce.pagare_pdf_path FROM checklist_entrega_v2 ce
            JOIN inventario_motos m ON m.id = ce.moto_id
            WHERE (m.cliente_id = ? OR m.cliente_telefono = ?) AND ce.pagare_pdf_path IS NOT NULL
            ORDER BY ce.freg DESC LIMIT 1");
        $tel = $cliente['telefono'] ?? '';
        $stmt->execute([$cid, $tel]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['pagare_pdf_path']) {
            $pagareFile = sys_get_temp_dir() . '/voltika_pagares/' . basename($row['pagare_pdf_path']);
            if (file_exists($pagareFile)) serveFile($pagareFile, 'pagare_voltika.pdf');
        }
    } catch (Throwable $e) {}

    // 2. Search disk
    $searchDirs = [
        sys_get_temp_dir() . '/voltika_pagares/',
        __DIR__ . '/../../../configurador_prueba/php/uploads/pagares/',
    ];
    if ($moto) {
        foreach ($searchDirs as $dir) {
            if (!is_dir($dir)) continue;
            $files = glob($dir . 'pagare_moto' . $moto['id'] . '_*.pdf');
            if ($files) {
                usort($files, function($a, $b) { return filemtime($b) - filemtime($a); });
                serveFile($files[0], 'pagare_voltika.pdf');
            }
        }
    }

    // 3. Generate minimal pagaré on-the-fly
    if (loadFPDF() && $sub) {
        $enc = function($s) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string)$s); };
        $fmt = function($n) { return '$' . number_format((float)$n, 2) . ' MXN'; };

        $enganche = (float)($sub['enganche'] ?? 0);
        $pagoSemanal = (float)($sub['monto_semanal'] ?? $sub['pago_semanal'] ?? 0);
        $plazoMeses = (int)($sub['plazo_meses'] ?? 36);
        $numPagos = round($plazoMeses * 4.33);
        $montoTotal = $enganche + ($pagoSemanal * $numPagos);
        if (!$montoTotal) $montoTotal = (float)($sub['precio_contado'] ?? 0);

        $pdf = new FPDF();
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        $pdf->SetFont('Arial', 'B', 20);
        $pdf->Cell(0, 12, $enc('PAGARE'), 0, 1, 'C');
        $pdf->Ln(3);

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 7, $enc('Por la cantidad de ' . $fmt($montoTotal)), 0, 1, 'C');
        $pdf->Ln(4);

        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(95, 6, $enc('Lugar: Ciudad de Mexico, CDMX'), 0, 0);
        $pdf->Cell(95, 6, $enc('Fecha: ' . date('d/m/Y')), 0, 1, 'R');
        $pdf->Ln(4);

        $pdf->SetFont('Arial', '', 8.5);
        $pdf->MultiCell(0, 4.5, $enc(
            'DEBO Y PAGARE incondicionalmente a la orden de MTECH GEARS, S.A. DE C.V. (VOLTIKA), '
            . 'la cantidad senalada en el presente documento, obligandome a cubrirla en el domicilio del '
            . 'acreedor o en el lugar que este designe.'
        ));
        $pdf->Ln(3);

        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(0, 6, $enc('DATOS DEL SUSCRIPTOR'), 0, 1);
        $w1 = 40; $h = 7;
        $sRows = [
            ['Nombre', $nombreCompleto],
            ['Telefono', $cliente['telefono'] ?? ''],
            ['Email', $cliente['email'] ?? ''],
        ];
        foreach ($sRows as $r) {
            $pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell($w1, $h, $enc($r[0] . ':'), 0);
            $pdf->SetFont('Arial', '', 8.5);   $pdf->Cell(0, $h, $enc($r[1]), 'B', 1);
        }

        $pdf->Ln(4);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(0, 6, 'ACREEDOR', 0, 1);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(0, 6, 'MTECH GEARS, S.A. DE C.V. (VOLTIKA)', 0, 1);

        $pdf->Ln(6);
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->Cell(0, 5, $enc('Documento generado el ' . date('d/m/Y H:i')), 0, 1, 'R');

        servePDF($pdf->Output('S'), 'pagare_voltika.pdf');
    }

    header('Content-Type: application/json');
    portalJsonOut(['error' => 'Pagare no disponible todavia'], 404);
}

// ═══════════════════════════════════════════════════════════════════════
// CARTA FACTURA
// ═══════════════════════════════════════════════════════════════════════
if ($tipo === 'carta_factura') {
    // Must be al corriente (already checked above)
    if (!loadFPDF()) {
        header('Content-Type: application/json');
        portalJsonOut(['error' => 'Generador de PDF no disponible'], 500);
    }

    $modelo = $sub['modelo'] ?? ($moto['modelo'] ?? ($trans['modelo'] ?? '—'));
    $color  = $sub['color']  ?? ($moto['color']  ?? ($trans['color']  ?? '—'));
    $vin    = $moto['vin_display'] ?? ($moto['vin'] ?? 'Por asignar');
    $email  = $cliente['email'] ?? ($trans['email'] ?? '');
    $tel    = $cliente['telefono'] ?? ($trans['telefono'] ?? '');

    $enc = function($s) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string)$s); };

    $pdf = new FPDF();
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();

    // Header
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, $enc('CARTA FACTURA'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 5, $enc('MTECH GEARS S.A. DE C.V.'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(0, 5, 'RFC: MGE230316KA2', 0, 1, 'C');
    $pdf->Cell(0, 5, $enc('Jaime Balmes 71 Int 101, Despacho C, Col. Polanco, Miguel Hidalgo, CDMX, C.P. 11510'), 0, 1, 'C');
    $pdf->Ln(6);

    // Folio and date
    $folio = 'CF-' . $cid . '-' . date('Ymd');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(95, 6, $enc('Folio: ' . $folio), 0, 0);
    $pdf->Cell(95, 6, $enc('Fecha: ' . date('d/m/Y')), 0, 1, 'R');
    $pdf->Ln(4);

    // Recipient
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 7, $enc('A QUIEN CORRESPONDA:'), 0, 1);
    $pdf->Ln(2);

    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 5, $enc(
        'Por medio de la presente, MTECH GEARS S.A. DE C.V., con nombre comercial VOLTIKA, '
        . 'hace constar que el(la) Sr(a). ' . $nombreCompleto . ' es propietario(a) del siguiente vehiculo:'
    ));
    $pdf->Ln(4);

    // Vehicle details table
    $w1 = 50; $h = 7;
    $vRows = [
        ['Marca', 'VOLTIKA'],
        ['Submarca', 'TROMOX'],
        ['Modelo/Version', $modelo],
        ['Color', $color],
        ['Ano-modelo', '2026'],
        ['Numero de serie (VIN)', $vin],
    ];
    foreach ($vRows as $r) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell($w1, $h, $enc($r[0] . ':'), 1);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(0, $h, $enc($r[1]), 1, 1);
    }
    $pdf->Ln(4);

    // Owner details
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 7, $enc('DATOS DEL PROPIETARIO:'), 0, 1);
    $oRows = [
        ['Nombre completo', $nombreCompleto],
        ['Correo electronico', $email],
        ['Telefono', $tel],
    ];
    foreach ($oRows as $r) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell($w1, $h, $enc($r[0] . ':'), 1);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(0, $h, $enc($r[1]), 1, 1);
    }
    $pdf->Ln(4);

    // Body text
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 5, $enc(
        'La presente carta factura se expide como constancia de propiedad del vehiculo arriba descrito, '
        . 'para los efectos legales que al interesado convengan, incluyendo el tramite de emplacamiento '
        . 'ante las autoridades correspondientes.'
    ));
    $pdf->Ln(2);
    $pdf->MultiCell(0, 5, $enc(
        'Se hace constar que el vehiculo se encuentra libre de gravamen y que la compra ha sido '
        . 'debidamente documentada y pagada conforme a los terminos del contrato celebrado entre las partes.'
    ));
    $pdf->Ln(6);

    // Signature
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 6, 'ATENTAMENTE,', 0, 1);
    $pdf->Ln(12);
    $pdf->Cell(80, 0.3, '', 1, 1);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 6, 'MTECH GEARS S.A. DE C.V.', 0, 1);
    $pdf->Cell(0, 5, '(VOLTIKA)', 0, 1);
    $pdf->Ln(6);

    $pdf->SetFont('Arial', 'I', 7);
    $pdf->Cell(0, 4, $enc('Este documento tiene validez oficial para tramites de emplacamiento.'), 0, 1);
    $pdf->Cell(0, 4, $enc('Folio: ' . $folio . ' | Generado: ' . date('d/m/Y H:i:s')), 0, 1);

    servePDF($pdf->Output('S'), 'carta_factura_voltika.pdf');
}

// ═══════════════════════════════════════════════════════════════════════
// COMPROBANTES DE PAGO
// ═══════════════════════════════════════════════════════════════════════
if ($tipo === 'comprobantes') {
    $stmt = $pdo->prepare("SELECT semana_num, fecha_vencimiento, monto, estado, stripe_payment_intent
        FROM ciclos_pago WHERE cliente_id = ? AND estado IN ('paid_manual','paid_auto') ORDER BY semana_num");
    $stmt->execute([$cid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (loadFPDF() && $rows) {
        $enc = function($s) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string)$s); };
        $fmt = function($n) { return '$' . number_format((float)$n, 2); };

        $pdf = new FPDF();
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(0, 10, $enc('COMPROBANTES DE PAGO'), 0, 1, 'C');
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(0, 5, 'VOLTIKA - MTECH GEARS S.A. DE C.V.', 0, 1, 'C');
        $pdf->Ln(4);

        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(0, 6, $enc('Cliente: ' . $nombreCompleto . ' (#' . $cid . ')'), 0, 1);
        $pdf->Ln(3);

        // Table header
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetFillColor(240, 244, 248);
        $pdf->Cell(25, 7, 'Semana', 1, 0, 'C', true);
        $pdf->Cell(40, 7, 'Vencimiento', 1, 0, 'C', true);
        $pdf->Cell(35, 7, 'Monto', 1, 0, 'C', true);
        $pdf->Cell(30, 7, 'Estado', 1, 0, 'C', true);
        $pdf->Cell(60, 7, 'Referencia Stripe', 1, 1, 'C', true);

        $totalPagado = 0;
        $pdf->SetFont('Arial', '', 8);
        foreach ($rows as $r) {
            $monto = (float)$r['monto'];
            $totalPagado += $monto;
            $pdf->Cell(25, 6, $r['semana_num'], 1, 0, 'C');
            $pdf->Cell(40, 6, $r['fecha_vencimiento'], 1, 0, 'C');
            $pdf->Cell(35, 6, $fmt($monto), 1, 0, 'R');
            $pdf->Cell(30, 6, $enc($r['estado'] === 'paid_auto' ? 'Auto' : 'Manual'), 1, 0, 'C');
            $pdf->Cell(60, 6, substr($r['stripe_payment_intent'] ?? '', 0, 27), 1, 1, 'L');
        }

        // Total row
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(65, 7, 'TOTAL PAGADO', 1, 0, 'R');
        $pdf->Cell(35, 7, $fmt($totalPagado), 1, 0, 'R');
        $pdf->Cell(90, 7, count($rows) . ' pagos', 1, 1, 'C');

        $pdf->Ln(6);
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->Cell(0, 5, $enc('Generado: ' . date('d/m/Y H:i:s')), 0, 1, 'R');

        servePDF($pdf->Output('S'), 'comprobantes_voltika.pdf');
    }

    // Fallback: text
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="comprobantes_voltika.txt"');
    echo "VOLTIKA - Comprobantes de pago\n";
    echo "Cliente: $nombreCompleto (#$cid)\n";
    echo str_repeat('=', 60) . "\n\n";
    foreach ($rows as $r) {
        echo "Semana {$r['semana_num']}\tVenc: {$r['fecha_vencimiento']}\t\${$r['monto']}\t[{$r['estado']}]\n";
        if ($r['stripe_payment_intent']) echo "  Ref: {$r['stripe_payment_intent']}\n";
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// ACTA DE ENTREGA
// ═══════════════════════════════════════════════════════════════════════
if ($tipo === 'acta_entrega') {
    // Try pre-generated file
    $searchDirs = [
        __DIR__ . '/../../../configurador_prueba/php/uploads/actas/',
        sys_get_temp_dir() . '/voltika_actas/',
    ];
    foreach ($searchDirs as $dir) {
        if (!is_dir($dir)) continue;
        $files = glob($dir . '*cliente_' . $cid . '*.pdf');
        if (!$files) $files = glob($dir . '*.pdf');
        if ($files) {
            usort($files, function($a, $b) { return filemtime($b) - filemtime($a); });
            serveFile($files[0], 'acta_entrega_voltika.pdf');
        }
    }

    // Generate on-the-fly if moto was delivered
    if (loadFPDF() && $moto) {
        $enc = function($s) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string)$s); };
        $modelo = $moto['modelo'] ?? ($sub['modelo'] ?? '—');
        $color  = $moto['color']  ?? ($sub['color']  ?? '—');
        $vin    = $moto['vin_display'] ?? ($moto['vin'] ?? 'N/A');

        $pdf = new FPDF();
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, $enc('ACTA DE ENTREGA'), 0, 1, 'C');
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(0, 5, 'VOLTIKA - MTECH GEARS S.A. DE C.V.', 0, 1, 'C');
        $pdf->Ln(6);

        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell(0, 5, $enc(
            'Conste por el presente documento que en la fecha indicada, se realizo la entrega del siguiente '
            . 'vehiculo al cliente que se identifica a continuacion:'
        ));
        $pdf->Ln(4);

        $w1 = 50; $h = 7;
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 7, $enc('VEHICULO ENTREGADO:'), 0, 1);
        $vRows = [
            ['Marca', 'VOLTIKA'],
            ['Modelo', $modelo],
            ['Color', $color],
            ['VIN', $vin],
        ];
        foreach ($vRows as $r) {
            $pdf->SetFont('Arial', 'B', 9); $pdf->Cell($w1, $h, $enc($r[0] . ':'), 1);
            $pdf->SetFont('Arial', '', 9);  $pdf->Cell(0, $h, $enc($r[1]), 1, 1);
        }
        $pdf->Ln(4);

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 7, $enc('RECIBIDO POR:'), 0, 1);
        $oRows = [
            ['Nombre', $nombreCompleto],
            ['Telefono', $cliente['telefono'] ?? ''],
            ['Email', $cliente['email'] ?? ''],
        ];
        foreach ($oRows as $r) {
            $pdf->SetFont('Arial', 'B', 9); $pdf->Cell($w1, $h, $enc($r[0] . ':'), 1);
            $pdf->SetFont('Arial', '', 9);  $pdf->Cell(0, $h, $enc($r[1]), 1, 1);
        }
        $pdf->Ln(4);

        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell(0, 5, $enc(
            'El cliente declara haber recibido el vehiculo en las condiciones pactadas, '
            . 'verificando que corresponde al modelo, color y especificaciones contratadas.'
        ));
        $pdf->Ln(6);

        $pdf->SetFont('Arial', 'I', 8);
        $pdf->Cell(0, 5, $enc('Documento generado el ' . date('d/m/Y H:i')), 0, 1, 'R');

        servePDF($pdf->Output('S'), 'acta_entrega_voltika.pdf');
    }

    header('Content-Type: application/json');
    portalJsonOut(['error' => 'Acta de entrega no disponible todavia'], 404);
}

// ═══════════════════════════════════════════════════════════════════════
// DEFAULT: unknown tipo
// ═══════════════════════════════════════════════════════════════════════
header('Content-Type: application/json');
portalJsonOut(['error' => 'Tipo de documento no reconocido: ' . ($tipo ?: 'vacio')], 400);
