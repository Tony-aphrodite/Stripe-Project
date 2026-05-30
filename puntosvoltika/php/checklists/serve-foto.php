<?php
/**
 * GET — Serve checklist photo for PoS / admin / customer-portal viewers.
 *
 * Customer brief 2026-05-30: photos still showed as broken thumbnails on PoS
 * checklist (mobile view). Root cause: puntoRequireAuth() returned JSON 401
 * whenever the session was missing or the moto's punto_voltika_id didn't
 * match the viewer's punto. Both cases caused broken-image icons.
 *
 * Relaxed auth — accepts ANY authenticated session (admin / punto). The
 * filename includes a random suffix and timestamp so enumeration is hard,
 * and the only data exposed is the photo image itself (no PII text). The
 * ownership check is skipped to handle:
 *   - admin viewing PoS checklists from desktop
 *   - punto operator viewing a moto reassigned to a different punto
 *   - completed read-only checklists where the original uploader is gone
 *
 * Filename format: ensamble_{motoId}_{campo}_{ts}_{rand}.{ext}
 */

require_once __DIR__ . '/../bootstrap.php';

$filename = basename((string)($_GET['f'] ?? ''));
if (!$filename) {
    http_response_code(400);
    exit;
}

// Permissive auth: any authenticated session counts
$isAuthed = !empty($_SESSION['punto_user_id']) || !empty($_SESSION['admin_user_id']);
if (!$isAuthed) {
    http_response_code(401);
    exit;
}

// Look for the file in every known storage location. Photos uploaded via
// the PoS path land in admin/uploads/checklists (primary) but fall back to
// the system temp dir when admin/uploads is not writable on Plesk.
$candidates = [
    __DIR__ . '/../../../admin/uploads/checklists/' . $filename,
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
