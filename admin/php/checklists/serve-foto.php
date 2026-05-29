<?php
/**
 * GET — Serve checklist photo from secure storage
 * Usage: serve-foto.php?f=filename.jpg
 *
 * Accepts BOTH admin/cedis sessions AND punto operator sessions. Photos
 * uploaded from the PoS panel before 2026-05-29 have stored URLs that point
 * to this admin endpoint — those thumbnails were rendering as broken images
 * because admin auth was missing for PoS users. Now PoS users with a valid
 * session can also fetch a photo, as long as the photo belongs to a moto in
 * their own punto (filename embeds moto_id: ensamble_{motoId}_...).
 */
require_once __DIR__ . '/../bootstrap.php';

$filename = basename((string)($_GET['f'] ?? ''));
if (!$filename) {
    http_response_code(400);
    exit;
}

// Auth: either an admin/cedis or a punto operator. Punto users are
// additionally restricted to motos in their own punto.
$isAdmin = !empty($_SESSION['admin_user_id']);
$isPunto = !empty($_SESSION['punto_user_id']);
if (!$isAdmin && !$isPunto) {
    http_response_code(401);
    exit;
}
if (!$isAdmin && $isPunto) {
    // Parse moto_id from filename (ensamble_{motoId}_{campo}_{ts}_{rand}.{ext})
    $motoId = 0;
    if (preg_match('/^ensamble_(\d+)_/', $filename, $m)) {
        $motoId = (int)$m[1];
    }
    if ($motoId <= 0) {
        http_response_code(403);
        exit;
    }
    try {
        $pdoOwn = getDB();
        $mq = $pdoOwn->prepare("SELECT punto_voltika_id FROM inventario_motos WHERE id = ?");
        $mq->execute([$motoId]);
        $moto = $mq->fetch(PDO::FETCH_ASSOC);
        if (!$moto || (int)$moto['punto_voltika_id'] !== (int)$_SESSION['punto_id']) {
            http_response_code(403);
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        exit;
    }
}

$storageDir = __DIR__ . '/../../uploads/checklists/';
$filePath = $storageDir . $filename;

// Fallback: check legacy temp directory for older photos
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
if (strpos($mime, 'image/') !== 0) {
    http_response_code(403);
    exit;
}

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
