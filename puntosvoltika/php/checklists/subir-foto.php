<?php
/**
 * POST — Upload photo(s) for an Ensamble checklist from the Point of Sale
 * panel. Mirrors admin/php/checklists/subir-foto.php but with puntoRequireAuth
 * and a moto-belongs-to-this-punto guard so a dealer can only attach photos
 * to motos in their own inventory.
 *
 * Bug 4.1 (customer brief 2026-05-08): "Assembly Checklist in the Point of
 * Sale Portal must be the same as in the Admin Portal". Photos were missing
 * on PoS — this endpoint adds them. Writes to the SAME `checklist_ensamble`
 * table the admin uses, so admin sees PoS-uploaded photos and vice-versa.
 *
 * Multipart form: checklist_tipo (= 'ensamble'), moto_id, campo, foto (file)
 * Returns: { ok, url, filename }
 *
 * Whitelisted campos (must match admin's list to keep schemas in sync):
 *   fotos_fase1, fotos_fase3,
 *   fotos_desembalaje, fotos_base, fotos_manubrio, fotos_llanta, fotos_espejos,
 *   fotos_3_1_frenos, fotos_3_2_iluminacion, fotos_3_3_electrico,
 *   fotos_3_4_motor, fotos_3_5_acceso, fotos_3_6_mecanica
 */
require_once __DIR__ . '/../bootstrap.php';
$auth = puntoRequireAuth();

$tipo   = $_POST['checklist_tipo'] ?? 'ensamble';
$motoId = (int)($_POST['moto_id'] ?? 0);
$campo  = $_POST['campo'] ?? '';

if (!$motoId || !$campo) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Parámetros incompletos']);
    exit;
}

// Only ensamble photos go through this endpoint for now — origen lives on
// admin (CEDIS uploads only) and entrega has its own flow.
if ($tipo !== 'ensamble') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Tipo de checklist no soportado en el panel del punto']);
    exit;
}

$validCampos = [
    'fotos_fase1','fotos_fase3',
    'fotos_desembalaje','fotos_base','fotos_manubrio','fotos_llanta','fotos_espejos',
    'fotos_3_1_frenos','fotos_3_2_iluminacion','fotos_3_3_electrico',
    'fotos_3_4_motor','fotos_3_5_acceso','fotos_3_6_mecanica',
];
if (!in_array($campo, $validCampos, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Campo de foto inválido']);
    exit;
}

if (empty($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
    $errMsg = 'Error al subir archivo';
    if (!empty($_FILES['foto'])) {
        $errCodes = [1 => 'Excede tamaño máximo del servidor', 2 => 'Excede tamaño máximo del formulario',
            3 => 'Archivo subido parcialmente', 4 => 'No se subió ningún archivo'];
        $errMsg = $errCodes[$_FILES['foto']['error']] ?? $errMsg;
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $errMsg]);
    exit;
}
$file = $_FILES['foto'];

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);
$allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
if (!isset($allowedMimes[$mime])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Solo se permiten imágenes (JPG, PNG, WebP, GIF)']);
    exit;
}
if ($file['size'] > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Imagen muy grande (máx 10 MB)']);
    exit;
}

// Verify moto belongs to this punto (security guard not present in admin
// since admin can touch any moto — but PoS users must be scoped).
$pdo = getDB();
$mq  = $pdo->prepare("SELECT id, punto_voltika_id FROM inventario_motos WHERE id=?");
$mq->execute([$motoId]);
$moto = $mq->fetch(PDO::FETCH_ASSOC);
if (!$moto) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Moto no encontrada']);
    exit;
}
if ((int)$moto['punto_voltika_id'] !== (int)$auth['punto_id']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Esta moto no pertenece a tu punto']);
    exit;
}

// Reuse admin's storage convention so files are visible from both panels.
$ext      = $allowedMimes[$mime];
$filename = 'ensamble_' . $motoId . '_' . $campo . '_' . time() . '_' . mt_rand(100,999) . '.' . $ext;

$uploadDir = __DIR__ . '/../../../admin/uploads/checklists/';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
    // Fallback if admin path isn't writable from this process — keep file
    // accessible via temp; the serve-foto endpoint should still find it.
    $uploadDir = sys_get_temp_dir() . '/voltika_checklists/';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
}
$destPath = $uploadDir . $filename;
if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    $lastErr = error_get_last();
    error_log('punto subir-foto: move_uploaded_file falló — ' . ($lastErr['message'] ?? 'desconocido'));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al guardar archivo']);
    exit;
}

// Append URL to the JSON array in checklist_ensamble.
try {
    // Ensure column exists (idempotent, mirrors admin's behavior).
    try {
        $colsStmt = $pdo->query("SHOW COLUMNS FROM `checklist_ensamble`");
        $existingCols = array_column($colsStmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        if (!in_array($campo, $existingCols, true)) {
            $pdo->exec("ALTER TABLE `checklist_ensamble` ADD COLUMN `$campo` TEXT NULL");
        }
    } catch (Throwable $colErr) {
        error_log('punto subir-foto ensure column: ' . $colErr->getMessage());
    }

    $stmt = $pdo->prepare("SELECT id, `$campo` FROM `checklist_ensamble` WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
    $stmt->execute([$motoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Use admin's serve endpoint so the same URL works whether the file
    // lives in admin/uploads (production path) or sys_get_temp_dir (fallback).
    $relativeUrl = '/admin/php/checklists/serve-foto.php?f=' . $filename;

    if ($row) {
        $existing = json_decode($row[$campo] ?: '[]', true) ?: [];
        $existing[] = $relativeUrl;
        $pdo->prepare("UPDATE `checklist_ensamble` SET `$campo`=? WHERE id=?")
            ->execute([json_encode($existing, JSON_UNESCAPED_UNICODE), $row['id']]);
    } else {
        $fotos = json_encode([$relativeUrl], JSON_UNESCAPED_UNICODE);
        $pdo->prepare("INSERT INTO `checklist_ensamble` (moto_id, dealer_id, `$campo`) VALUES (?,?,?)")
            ->execute([$motoId, $auth['user_id'], $fotos]);
    }
} catch (Throwable $e) {
    error_log('punto subir-foto DB: ' . $e->getMessage());
    echo json_encode([
        'ok'       => true,
        'url'      => '/admin/php/checklists/serve-foto.php?f=' . $filename,
        'filename' => $filename,
        'warning'  => 'Foto guardada, pero no se pudo vincular: ' . $e->getMessage(),
    ]);
    exit;
}

puntoLog('checklist_foto_ensamble', ['moto_id' => $motoId, 'campo' => $campo, 'file' => $filename]);

echo json_encode([
    'ok'       => true,
    'url'      => $relativeUrl,
    'filename' => $filename,
]);
