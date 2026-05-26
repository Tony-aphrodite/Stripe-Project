<?php
/**
 * Voltika — Shared ACTA DE ENTREGA PDF generator (Round 83, 2026-05-26).
 *
 * Customer brief (Óscar, 2026-05-26): three problems on Adrian Montoya's
 * signed ACTA — (1) the signature image was NOT embedded in the PDF even
 * though the customer signed via the SPA, (2) NOM-151 status stuck at
 * 'autograph_pending', (3) name showed duplicated as "Adrian Montoya Diaz
 * Montoya Diaz".
 *
 * Root cause: the SPA path (firmar-acta.php) saved the signature to
 * firmas_contratos and applied a NOM-151 stamp to the EXISTING blank
 * PDF — but never regenerated the PDF with the autograph inside. The
 * standalone Round 80 path (firmar-acta-directa-guardar.php) DID
 * regenerate, so two different code paths produced different output.
 *
 * This module consolidates the FPDF generation in one place so both
 * paths produce IDENTICAL output: a PDF with the autograph embedded and
 * a properly-deduped customer name.
 *
 * Function:
 *   generarActaPdf($pdo, $moto, $signatureDataUrl, $ip): ?string
 *     Returns absolute disk path of the saved PDF, or null on failure.
 */

declare(strict_types=1);

// Round 83 v2 (2026-05-26) — Load the canonical sanitizer up-front.
// The pre-v2 code only used the function if it was ALREADY loaded — but
// nothing else in the regen path loads contrato-contado.php, so the proper
// dedup never ran and names like "Adrian Montoya Diaz Montoya Diaz"
// passed through untouched. Force-load the file here so we always have
// the heavy-duty collapseTail logic.
$_contratoContadoPath = __DIR__ . '/../../configurador/php/contrato-contado.php';
if (is_file($_contratoContadoPath) && !function_exists('contratoContadoSanitizeFullName')) {
    @require_once $_contratoContadoPath;
}

if (!function_exists('voltikaActaSanitizeFullName')) {
    /**
     * Dedupe and clean customer's full name.
     * Prefers contratoContadoSanitizeFullName (proven dedup with collapseTail
     * for "Apellido Apellido" repetition). Falls back to a tail-collapse
     * implementation when the canonical helper isn't available.
     */
    function voltikaActaSanitizeFullName(string $nombre, string $apPaterno = '', string $apMaterno = ''): string {
        if (function_exists('contratoContadoSanitizeFullName')) {
            return contratoContadoSanitizeFullName($nombre, $apPaterno, $apMaterno);
        }
        // Stronger fallback — actually collapses tail duplication.
        // Example: "Adrian Montoya Diaz Montoya Diaz" → "Adrian Montoya Diaz"
        $norm = function ($s) { return trim(preg_replace('/\s+/u', ' ', (string)$s) ?: ''); };
        $tokens = preg_split('/\s+/u', $norm($nombre . ' ' . $apPaterno . ' ' . $apMaterno)) ?: [];
        $n = count($tokens);
        // Look for the longest k where the last k tokens equal the previous k tokens; remove the trailing copy.
        for ($k = (int) floor($n / 2); $k >= 1; $k--) {
            $tail   = array_slice($tokens, -$k);
            $before = array_slice($tokens, -2 * $k, $k);
            $eq = true;
            for ($i = 0; $i < $k; $i++) {
                if (mb_strtolower($tail[$i]) !== mb_strtolower($before[$i])) { $eq = false; break; }
            }
            if ($eq) {
                $tokens = array_slice($tokens, 0, $n - $k);
                $n = count($tokens);
                $k = (int) floor($n / 2) + 1; // restart the loop
            }
        }
        return implode(' ', $tokens);
    }
}

/**
 * Generate the ACTA DE ENTREGA PDF with the autograph signature embedded.
 *
 * @param PDO    $pdo                Active database connection (for punto lookup)
 * @param array  $moto               Row from inventario_motos
 * @param string $signatureDataUrl   Base64 data URL of the signature (data:image/png;base64,...)
 *                                   Pass empty string '' to generate the PDF without a signature.
 * @param ?string $ip                Customer's IP at signing time (for audit footer)
 * @return ?string  Absolute disk path of the saved PDF, or null on failure.
 */
function generarActaPdf(PDO $pdo, array $moto, string $signatureDataUrl, ?string $ip): ?string {
    // ── 1. Load FPDF ────────────────────────────────────────────────────
    if (!class_exists('FPDF')) {
        $fpdfPaths = [
            __DIR__ . '/../../admin/php/lib/fpdf.php',
            __DIR__ . '/../../configurador/php/vendor/fpdf/fpdf.php',
            __DIR__ . '/../../configurador/php/vendor/setasign/fpdf/fpdf.php',
        ];
        foreach ($fpdfPaths as $fp) {
            if (file_exists($fp)) { require_once $fp; break; }
        }
    }
    if (!class_exists('FPDF')) {
        error_log('generarActaPdf: FPDF not available');
        return null;
    }

    $motoId = (int)$moto['id'];
    $enc = function($s) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string)$s); };

    // ── 2. Resolve punto info ───────────────────────────────────────────
    $punto = null;
    if (!empty($moto['punto_voltika_id'])) {
        try {
            $pq = $pdo->prepare("SELECT nombre, ciudad FROM puntos_voltika WHERE id=? LIMIT 1");
            $pq->execute([(int)$moto['punto_voltika_id']]);
            $punto = $pq->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {}
    }

    // ── 3. Sanitize customer name (drops "Apellido Apellido" duplication) ──
    $nombreCompleto = voltikaActaSanitizeFullName(
        (string)($moto['cliente_nombre'] ?? ''),
        (string)($moto['cliente_apellido_paterno'] ?? ''),
        (string)($moto['cliente_apellido_materno'] ?? '')
    );
    if ($nombreCompleto === '') $nombreCompleto = (string)($moto['cliente_nombre'] ?? '—');

    // ── 4. Build PDF ────────────────────────────────────────────────────
    $pdf = new FPDF('P', 'mm', 'Letter');
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetTitle($enc('Acta de Entrega - Voltika'));
    $pdf->SetAuthor('Voltika - MTECH GEARS, S.A. DE C.V.');
    $pdf->AddPage();

    $folio        = 'ACT-' . $motoId . '-' . date('Ymd-His');
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

    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(95, 4, $enc('FOLIO: ' . $folio), 0, 0);
    $pdf->Cell(95, 4, $enc('FECHA Y HORA: ' . $fechaEntrega), 0, 1, 'R');
    $pdf->Ln(2);

    $pdf->SetFont('Arial', 'B', 13);
    $pdf->Cell(0, 7, $enc('ACTA DE ENTREGA DE VEHÍCULO'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->Cell(0, 4, $enc('MTECH GEARS, S.A. DE C.V. — Voltika'), 0, 1, 'C');
    $pdf->Ln(3);

    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 4.5, $enc('En este acto, EL CLIENTE declara haber recibido la motocicleta eléctrica descrita en el presente documento, en condiciones óptimas de funcionamiento, completa y conforme a lo contratado.'), 0, 'J');
    $pdf->Ln(3);

    // Data block
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
        ['Punto de entrega', ($punto['nombre'] ?? '—') . (!empty($punto['ciudad']) ? ' — ' . $punto['ciudad'] : '')],
    ];
    foreach ($rows as $r) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(60, 5, $enc($r[0] . ':'), 0);
        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell(0, 5, $enc((string)$r[1]), 0);
    }
    $pdf->Ln(3);

    // Declarations
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

    // ── 5. Embed signature image (the missing piece for Round 73 flow) ──
    $pdf->Ln(6);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 5, $enc('FIRMA DEL CLIENTE'), 0, 1);
    $pdf->Ln(2);

    // Round 83 v2 — accept multiple signature formats:
    //   • data:image/png;base64,XXX                  (canvas.toDataURL default)
    //   • data:image/jpeg;base64,XXX                 (jpeg-encoded sigs)
    //   • XXX                                        (raw base64 without prefix
    //                                                 — some legacy code paths)
    // Previously only the first format was matched, so signatures saved with
    // the other shapes silently dropped to "no image" and the PDF showed an
    // empty signature line.
    $tmpSig = null;
    $rawBase64 = '';
    $imgType   = 'PNG';
    if ($signatureDataUrl !== '') {
        if (preg_match('/^data:image\/(png|jpeg|jpg);base64,(.+)$/i', $signatureDataUrl, $mm)) {
            $rawBase64 = $mm[2];
            $imgType   = (strtolower($mm[1]) === 'png') ? 'PNG' : 'JPG';
        } elseif (preg_match('#^[A-Za-z0-9+/=\r\n]+$#', trim($signatureDataUrl))) {
            // Looks like raw base64 — assume PNG (most common from canvas)
            $rawBase64 = trim($signatureDataUrl);
            $imgType   = 'PNG';
        }
    }
    if ($rawBase64 !== '') {
        $bin = base64_decode($rawBase64, true);
        if ($bin === false || strlen($bin) < 50) {
            error_log('generarActaPdf: base64_decode failed or too small ('. strlen((string)$bin) .' bytes)');
            $pdf->Ln(20);
        } else {
            $ext = $imgType === 'JPG' ? '.jpg' : '.png';
            $tmpSig = tempnam(sys_get_temp_dir(), 'sig_') . $ext;
            file_put_contents($tmpSig, $bin);
            try {
                // Width 90mm — fills most of the signature line area cleanly.
                $pdf->Image($tmpSig, 20, $pdf->GetY(), 90, 0, $imgType);
                $pdf->Ln(40);
            } catch (Throwable $e) {
                error_log('generarActaPdf: image embed failed: ' . $e->getMessage());
                $pdf->Ln(20);
            }
        }
    } else {
        // No signature data — leave a blank signature line.
        if ($signatureDataUrl !== '') {
            error_log('generarActaPdf: signatureDataUrl rejected (first 80 chars): ' . substr($signatureDataUrl, 0, 80));
        }
        $pdf->Ln(20);
    }
    $pdf->Line(20, $pdf->GetY(), 110, $pdf->GetY());
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(90, 5, $enc($nombreCompleto), 0, 1);
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(90, 4, $enc('Firmado electrónicamente · sello NOM-151 a través de Cincel'), 0, 1);
    if ($ip) {
        $pdf->SetFont('Arial', '', 7);
        $pdf->Cell(0, 4, $enc('IP: ' . $ip . ' · ' . $fechaEntrega), 0, 1);
    }

    // ── 6. Persist to disk ──────────────────────────────────────────────
    // Use the same filename convention as cincel-firma-acta.php so the
    // regenerated PDF overwrites the old blank one, keeping cincel_acta_pdf_path
    // valid without DB update.
    $filename = 'acta_cliente_' . $motoId . '_' . date('Ymd_His') . '.pdf';
    $candidateDirs = [
        __DIR__ . '/../../configurador/php/uploads/actas',
        __DIR__ . '/../../configurador/contratos/actas',
        sys_get_temp_dir() . '/voltika_actas',
    ];
    $outPath = null;
    foreach ($candidateDirs as $dir) {
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (!is_writable($dir)) continue;
        $try = $dir . '/' . $filename;
        try {
            $pdf->Output('F', $try);
            if (is_file($try) && filesize($try) > 0) { $outPath = $try; break; }
        } catch (Throwable $e) {
            error_log('generarActaPdf output: ' . $e->getMessage());
        }
    }
    if ($tmpSig && is_file($tmpSig)) @unlink($tmpSig);
    return $outPath;
}
