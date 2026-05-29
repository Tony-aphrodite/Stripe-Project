<?php
/**
 * Voltika Admin — Serve the signed ACTA DE ENTREGA PDF for any moto.
 * (Round 82, 2026-05-26)
 *
 * Customer brief (Óscar, 2026-05-26): admin couldn't view the signed ACTA
 * PDF from the admin panel even though the customer portal showed the
 * delivery as completed. Investigation found:
 *   - The PDF DOES exist (saved by Round 73 / Round 80 flows to /tmp).
 *   - The existing serve-acta.php endpoint had a strict filename pattern
 *     '/^acta_moto\d+_\d{8}_\d{6}\.pdf$/' that DIDN'T match the actual
 *     filenames produced by Round 80 ('acta_cliente_*' / 'acta_directa_*').
 *   - So the PDF was unreachable through the admin UI even though it
 *     existed on disk.
 *
 * This endpoint takes a moto_id, reads the canonical PDF path from
 * inventario_motos.cincel_acta_pdf_path, validates the path is in a known
 * safe directory, and streams the PDF inline.
 *
 * URL: /admin/php/inventario/view-acta.php?moto_id=147
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin', 'cedis']);

$motoId = (int)($_GET['moto_id'] ?? 0);
if ($motoId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Falta moto_id";
    exit;
}

$pdo = getDB();

// Load the moto's PDF path (canonical source: inventario_motos.cincel_acta_pdf_path)
$row = null;
try {
    $st = $pdo->prepare("SELECT id, vin, vin_display, modelo, color,
                                cliente_nombre, cliente_acta_firmada,
                                cincel_acta_pdf_path, cincel_acta_status,
                                cincel_acta_timestamp_hash
                           FROM inventario_motos WHERE id = ? LIMIT 1");
    $st->execute([$motoId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error de DB: " . $e->getMessage();
    exit;
}

if (!$row) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Moto $motoId no encontrada";
    exit;
}

$pdfPath = (string)($row['cincel_acta_pdf_path'] ?? '');

// Fallback: scan for files matching this moto if cincel_acta_pdf_path is empty.
// Round 73 / Round 80 may have saved without persisting the path. We look in
// the same candidate dirs the generators use.
if ($pdfPath === '' || !is_file($pdfPath)) {
    $candidateDirs = [
        sys_get_temp_dir() . '/voltika_actas',
        __DIR__ . '/../../../configurador/php/uploads/actas',
        __DIR__ . '/../../../configurador/contratos/actas',
    ];
    $candidatePatterns = [
        "acta_cliente_{$motoId}_*.pdf",
        "acta_directa_{$motoId}_*.pdf",
        "acta_moto{$motoId}_*.pdf",
    ];
    $found = null;
    foreach ($candidateDirs as $dir) {
        if (!is_dir($dir)) continue;
        foreach ($candidatePatterns as $pattern) {
            $matches = glob($dir . '/' . $pattern);
            if ($matches) {
                // Pick the most recent
                usort($matches, function($a,$b){ return filemtime($b) <=> filemtime($a); });
                $found = $matches[0];
                break 2;
            }
        }
    }
    if ($found) $pdfPath = $found;
}

if ($pdfPath === '' || !is_file($pdfPath)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html><head><meta charset='utf-8'><title>ACTA no disponible</title>"
       . "<style>body{font-family:system-ui,sans-serif;max-width:560px;margin:60px auto;padding:24px;color:#0c2340;line-height:1.6}"
       . ".card{background:#fff;border:1px solid #fecaca;border-left:5px solid #dc2626;border-radius:10px;padding:20px;}"
       . "h1{font-size:18px;margin:0 0 8px;color:#991b1b}</style></head><body>"
       . "<div class='card'><h1>⚠ ACTA no disponible para la moto $motoId</h1>"
       . "<p>El PDF del ACTA DE ENTREGA todavía no ha sido generado en disco. Posibles causas:</p>"
       . "<ul>"
       . "<li>El cliente <strong>NO ha firmado</strong> el ACTA (cliente_acta_firmada=" . (int)($row['cliente_acta_firmada'] ?? 0) . ")</li>"
       . "<li>El checklist de entrega del punto está incompleto y nunca se llegó a generar el PDF</li>"
       . "<li>El PDF se generó pero el archivo se perdió (limpieza de /tmp)</li>"
       . "</ul>"
       . "<p><strong>Estado actual:</strong></p>"
       . "<ul style='font-family:ui-monospace,monospace;font-size:12px'>"
       . "<li>cliente_acta_firmada: " . ((int)($row['cliente_acta_firmada'] ?? 0) === 1 ? '✓ sí' : '✗ no') . "</li>"
       . "<li>cincel_acta_status: " . htmlspecialchars((string)($row['cincel_acta_status'] ?? 'sin estado')) . "</li>"
       . "<li>cincel_acta_pdf_path: " . htmlspecialchars((string)($row['cincel_acta_pdf_path'] ?? 'NULL')) . "</li>"
       . "<li>cincel_acta_timestamp_hash: " . htmlspecialchars((string)($row['cincel_acta_timestamp_hash'] ?? 'NULL')) . "</li>"
       . "</ul>"
       . "<p><strong>Solución:</strong> usa la herramienta en "
       . "<a href='/admin/php/checklists/herramienta-firma-acta.php'>Generar link de firma de ACTA</a> "
       . "para enviar al cliente un link de Round 80, o completa el checklist de entrega desde el panel del punto.</p>"
       . "</div></body></html>";
    exit;
}

// Path validation — must be inside one of the safe directories.
$realPdfPath = realpath($pdfPath);
$safeRoots = array_filter([
    realpath(sys_get_temp_dir() . '/voltika_actas'),
    realpath(__DIR__ . '/../../../configurador/php/uploads/actas'),
    realpath(__DIR__ . '/../../../configurador/contratos/actas'),
]);
$isSafe = false;
foreach ($safeRoots as $root) {
    if ($realPdfPath !== false && strpos($realPdfPath, $root) === 0) { $isSafe = true; break; }
}
if (!$isSafe) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Acceso denegado — el archivo no está en un directorio seguro";
    exit;
}

// Stream the PDF.
$filename = 'acta_' . $motoId . '_' . ($row['vin_display'] ?? $row['vin'] ?? 'sin-vin') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . filesize($realPdfPath));
header('Cache-Control: no-cache, no-store, must-revalidate');
readfile($realPdfPath);
exit;
