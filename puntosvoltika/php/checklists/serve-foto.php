<?php
/**
 * GET — Serve checklist photo from secure storage for PoS users.
 *
 * Customer brief 2026-05-29: photos uploaded from the Point of Sale assembly
 * checklist showed as broken thumbnails. Root cause: subir-foto.php returned
 * a URL pointing to /admin/php/checklists/serve-foto.php, which requires
 * admin auth — but the user is logged in as a punto operator, not admin.
 *
 * This endpoint mirrors the admin serve-foto.php but uses puntoRequireAuth
 * and additionally verifies the moto whose photo is being served belongs to
 * this punto (read from the filename which embeds the moto_id).
 *
 * Filename format (set by subir-foto.php):
 *   ensamble_{motoId}_{campo}_{timestamp}_{rand}.{ext}
 *
 * Usage: serve-foto.php?f=filename.jpg
 */
require_once __DIR__ . '/../bootstrap.php';
$auth = puntoRequireAuth();

$filename = basename((string)($_GET['f'] ?? ''));
if (!$filename) {
    http_response_code(400);
    exit;
}

// Parse moto_id from filename (ensamble_{motoId}_...)
$motoId = 0;
if (preg_match('/^ensamble_(\d+)_/', $filename, $m)) {
    $motoId = (int)$m[1];
}
if ($motoId <= 0) {
    http_response_code(400);
    exit;
}

// Verify moto belongs to this punto — same guard subir-foto.php uses on upload.
$pdo = getDB();
$mq = $pdo->prepare("SELECT punto_voltika_id FROM inventario_motos WHERE id = ?");
$mq->execute([$motoId]);
$moto = $mq->fetch(PDO::FETCH_ASSOC);
if (!$moto || (int)$moto['punto_voltika_id'] !== (int)$auth['punto_id']) {
    http_response_code(403);
    exit;
}

// Same storage convention as upload — primary path is admin/uploads/checklists.
$storageDir = __DIR__ . '/../../../admin/uploads/checklists/';
$filePath = $storageDir . $filename;
if (!file_exists($filePath)) {
    $legacyPath = sys_get_temp_dir() . '/voltika_checklists/' . $filename;
    if (file_exists($legacyPath)) {
        $filePath = $legacyPath;
    } else {
        http_response_code(404);
        exit;
    }
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
