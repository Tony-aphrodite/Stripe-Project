<?php
/**
 * Voltika PUNTOVOLTIKA — Round 30 v2 (2026-05-14).
 *
 * Serve recepción photos via PHP instead of direct filesystem URL.
 *
 * WHY: photos are stored at /configurador/php/uploads/recepcion/<file>.
 * That folder is often blocked by Plesk default .htaccess (the "PHP only"
 * web policy refuses direct access to image files inside php/). Result:
 * the lightbox shows "No se pudo cargar la imagen" even though the file
 * exists on disk. This serve helper reads the file with PHP (bypassing
 * the .htaccess restriction), validates the path (no traversal), and
 * sends it back with the correct Content-Type + a short browser cache.
 *
 * GET /puntosvoltika/php/recepcion/serve-foto.php?f=<basename>
 *   → 200 image/jpeg|png|webp + binary
 *   → 404 if missing
 *   → 400 if filename is malformed
 *
 * Auth: requires a logged-in punto session (same as historial.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();  // returns punto session context or 401s

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
// Restrict to known recepción photo naming convention so this endpoint
// can't be abused as a general file browser.
//   recibir.php saves files as <tipo>_<motoId>_<ts>_<rand>.jpg where
//   tipo ∈ {sello, vin_label, unidad}, plus legacy fotos_extra.
//   We accept any image extension because legacy uploads vary.
if (!preg_match('/^[A-Za-z0-9_.-]+\.(jpe?g|png|webp|heic|gif)$/i', $basename)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Filename pattern not allowed';
    exit;
}

$uploadsDir = realpath(__DIR__ . '/../../../configurador/php/uploads/recepcion');
if (!$uploadsDir) {
    // Directory doesn't exist on disk at all.
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Uploads directory missing';
    exit;
}
$path = $uploadsDir . '/' . $basename;
$realPath = realpath($path);
if (!$realPath || strpos($realPath, $uploadsDir) !== 0 || !is_file($realPath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'File not found: ' . $basename;
    exit;
}

// Pick a Content-Type from the file extension. finfo would be more
// authoritative but isn't worth the deps for image extensions we already
// validate at upload time.
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
// Cache for 1 hour — photos are immutable once saved (new uploads get
// a different timestamp+random suffix so URLs differ).
header('Cache-Control: private, max-age=3600');
readfile($realPath);
