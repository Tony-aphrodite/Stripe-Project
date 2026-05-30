<?php
/**
 * GET — Serve checklist photo from secure storage
 * Usage: serve-foto.php?f=filename.jpg
 *
 * Permissive auth — accepts ANY authenticated session (admin/cedis or punto
 * operator). Customer brief 2026-05-30: strict punto-ownership check was
 * causing 403s for read-only views of completed checklists (e.g. admin
 * viewing PoS-uploaded photos from desktop, or a different punto operator
 * viewing a moto that was reassigned). Filename has a random suffix so
 * enumeration is hard; the only data exposed is the image itself.
 */
require_once __DIR__ . '/../bootstrap.php';

$filename = basename((string)($_GET['f'] ?? ''));
if (!$filename) {
    http_response_code(400);
    exit;
}

$isAuthed = !empty($_SESSION['admin_user_id']) || !empty($_SESSION['punto_user_id']);
if (!$isAuthed) {
    http_response_code(401);
    exit;
}

// Check every known storage location. Photos uploaded via PoS land in
// admin/uploads/checklists (primary) but fall back to the system temp dir
// when admin/uploads is not writable on Plesk.
$candidates = [
    __DIR__ . '/../../uploads/checklists/' . $filename,
    sys_get_temp_dir() . '/voltika_checklists/' . $filename,
    '/var/www/vhosts/voltika.mx/private_storage/checklists/' . $filename,
    '/var/www/vhosts/voltika.mx/private_storage/uploads/checklists/' . $filename,
    '/var/www/vhosts/voltika.mx/httpdocs/admin/uploads/checklists/' . $filename,
];
$filePath = null;
foreach ($candidates as $p) {
    if (is_file($p)) { $filePath = $p; break; }
}
if (!$filePath) {
    http_response_code(404);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($filePath);
if (strpos((string)$mime, 'image/') !== 0) {
    http_response_code(403);
    exit;
}

header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit;
