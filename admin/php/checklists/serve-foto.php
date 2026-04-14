<?php
/**
 * GET — Serve checklist photo from secure storage
 * Usage: serve-foto.php?f=filename.jpg
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$filename = basename($_GET['f'] ?? '');
if (!$filename) {
    http_response_code(400);
    exit;
}

$storageDir = sys_get_temp_dir() . '/voltika_checklists/';
$filePath = $storageDir . $filename;

if (!file_exists($filePath)) {
    http_response_code(404);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($filePath);
if (strpos($mime, 'image/') !== 0) {
    http_response_code(403);
    exit;
}

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
