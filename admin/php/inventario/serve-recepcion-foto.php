<?php
/**
 * Voltika Admin — Round 31 (2026-05-14).
 *
 * Serve recepción photos (sello / vin_label / unidad / extras) to the
 * admin moto-detail panel. Mirrors puntosvoltika/php/recepcion/
 * serve-foto.php but authenticates via the admin panel session
 * (adminRequireAuth) so admins can view photos without a punto session.
 *
 * Searches the same 3 candidate upload locations as recibir.php so this
 * endpoint finds files regardless of where the upload landed when the
 * canonical /configurador/php/uploads/recepcion/ folder is blocked by
 * Plesk's default .htaccess on the install.
 *
 * GET /admin/php/inventario/serve-recepcion-foto.php?f=<basename>
 *   → 200 image/jpeg|png|webp + binary
 *   → 404 if missing
 *   → 400 if filename is malformed
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$f = trim((string)($_GET['f'] ?? ''));
if ($f === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Missing file parameter';
    exit;
}

// Security: only allow basename — no directory traversal.
$basename = basename($f);
if ($basename !== $f) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid filename';
    exit;
}
// Allow recepción photo naming convention (sello_/vin_label_/unidad_)
// plus general image filenames so legacy / fotos_extra entries also work.
if (!preg_match('/^[A-Za-z0-9_.-]+\.(jpe?g|png|webp|heic|gif)$/i', $basename)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Filename pattern not allowed';
    exit;
}

// Candidate dirs — same order as recibir.php's writable-location probe.
$candidates = array_filter([
    realpath(__DIR__ . '/../../../configurador/php/uploads/recepcion'),
    realpath(__DIR__ . '/../../uploads/recepcion-puntos'),
    realpath(sys_get_temp_dir() . '/voltika_recepcion_fotos'),
]);

if (empty($candidates)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Uploads directory missing (no candidate location exists yet — no photos have been uploaded successfully)';
    exit;
}

$realPath = null;
$searchedIn = [];
foreach ($candidates as $dir) {
    $searchedIn[] = $dir;
    $tryPath = $dir . '/' . $basename;
    $resolved = realpath($tryPath);
    if ($resolved && strpos($resolved, $dir) === 0 && is_file($resolved)) {
        $realPath = $resolved;
        break;
    }
}
if (!$realPath) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "File not found: $basename\n\nSearched in:\n - " . implode("\n - ", $searchedIn);
    exit;
}

$ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
$mime = 'application/octet-stream';
switch ($ext) {
    case 'jpg': case 'jpeg': $mime = 'image/jpeg'; break;
    case 'png':              $mime = 'image/png';  break;
    case 'webp':             $mime = 'image/webp'; break;
    case 'heic':             $mime = 'image/heic'; break;
    case 'gif':              $mime = 'image/gif';  break;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($realPath));
header('Cache-Control: private, max-age=3600');
readfile($realPath);
