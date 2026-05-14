<?php
/**
 * Voltika Admin — Round 33 (2026-05-14, Óscar).
 *
 * Replace a missing recepción photo directly from the admin detail panel.
 * Customer brief: the placeholder thumbnails (Sello / VIN etiqueta /
 * Unidad) looked like buttons but did nothing when clicked. Now they
 * trigger a file picker and POST the chosen image here. The new image
 * is saved to disk (3-candidate writable-dir fallback) and the
 * matching column in recepcion_punto is updated. Subsequent detail
 * reloads display the photo normally.
 *
 * POST (multipart/form-data):
 *   moto_id : int
 *   tipo    : 'sello' | 'vin_label' | 'unidad'
 *   foto    : <file>  (image/* under 10 MB)
 *
 * Response: { ok, url, tipo, recepcion_id, message }
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$motoId = (int)($_POST['moto_id'] ?? 0);
$tipo   = strtolower(trim((string)($_POST['tipo'] ?? '')));
$allowedTipos = ['sello' => 'foto_sello_url',
                 'vin_label' => 'foto_vin_label_url',
                 'unidad' => 'foto_unidad_url'];

if (!$motoId || !isset($allowedTipos[$tipo])) {
    adminJsonOut(['error' => 'parametros_invalidos',
                  'message' => 'moto_id y tipo (sello|vin_label|unidad) son requeridos.'], 400);
}

if (empty($_FILES['foto']) || ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    adminJsonOut(['error' => 'archivo_faltante',
                  'message' => 'No se recibió archivo (parámetro "foto" debe ser un upload válido).'], 400);
}

$file = $_FILES['foto'];
if ($file['size'] > 10 * 1024 * 1024) {
    adminJsonOut(['error' => 'archivo_muy_grande',
                  'message' => 'El archivo supera 10 MB. Comprime la imagen antes de subirla.'], 413);
}
if ($file['size'] < 1024) {
    adminJsonOut(['error' => 'archivo_muy_pequeno',
                  'message' => 'El archivo es demasiado pequeño (corrupto o vacío).'], 400);
}
$mime = function_exists('mime_content_type') ? @mime_content_type($file['tmp_name']) : ($file['type'] ?? '');
if (!preg_match('#^image/(jpe?g|png|webp|heic|gif)$#i', (string)$mime)) {
    adminJsonOut(['error' => 'tipo_invalido',
                  'message' => 'Solo se aceptan imágenes (JPG, PNG, WEBP, HEIC, GIF).'], 415);
}

$pdo = getDB();

// Ensure the moto exists and find its most recent recepción row.
$stmt = $pdo->prepare("SELECT id FROM inventario_motos WHERE id = ? LIMIT 1");
$stmt->execute([$motoId]);
if (!$stmt->fetchColumn()) {
    adminJsonOut(['error' => 'moto_no_encontrada'], 404);
}

$rq = $pdo->prepare("SELECT id FROM recepcion_punto WHERE moto_id = ? ORDER BY freg DESC LIMIT 1");
$rq->execute([$motoId]);
$recepcionId = (int)$rq->fetchColumn();
if (!$recepcionId) {
    adminJsonOut(['error' => 'sin_recepcion',
                  'message' => 'Esta moto no tiene un registro de recepción todavía. El operador del punto debe completar la recepción primero.'], 409);
}

// Pick a writable upload dir (same 3-candidate probe as recibir.php).
$candidates = [
    __DIR__ . '/../../../configurador/php/uploads/recepcion',
    __DIR__ . '/../../uploads/recepcion-puntos',
    sys_get_temp_dir() . '/voltika_recepcion_fotos',
];
$uploadDir = '';
foreach ($candidates as $c) {
    if (!is_dir($c)) { @mkdir($c, 0775, true); @chmod($c, 0775); }
    if (is_dir($c) && is_writable($c)) { $uploadDir = $c; break; }
}
if ($uploadDir === '') {
    adminJsonOut(['error' => 'sin_directorio_escribible',
                  'message' => 'El servidor no pudo encontrar una carpeta donde guardar la foto. Contacta a soporte.'], 500);
}

// Build a unique filename matching the recibir.php convention so
// serve-recepcion-foto.php and the punto serve helper both find it.
$ext = 'jpg';
if (preg_match('#image/(jpe?g|png|webp|heic|gif)#i', (string)$mime, $m)) {
    $ext = strtolower($m[1]);
    if ($ext === 'jpeg') $ext = 'jpg';
}
$fname = $tipo . '_' . $motoId . '_' . time() . '_' . substr(md5(uniqid('', true)), 0, 6) . '.' . $ext;
$dest  = $uploadDir . '/' . $fname;

if (!@move_uploaded_file($file['tmp_name'], $dest)) {
    // Fallback to file_put_contents (some hosts deny move_uploaded_file).
    $bin = @file_get_contents($file['tmp_name']);
    if ($bin === false || @file_put_contents($dest, $bin) === false) {
        adminJsonOut(['error' => 'guardado_fallido',
                      'message' => 'No se pudo guardar el archivo en disco.',
                      'dir' => $uploadDir], 500);
    }
}

// Public URL that the detail panel rewriter understands.
$publicUrl = '/configurador/php/uploads/recepcion/' . $fname;

// Update the appropriate column in recepcion_punto.
$col = $allowedTipos[$tipo];
try {
    $pdo->prepare("UPDATE recepcion_punto SET $col = ?, fmod = NOW() WHERE id = ?")
        ->execute([$publicUrl, $recepcionId]);
} catch (Throwable $e) {
    // fmod column might not exist on older schemas — retry without it.
    try {
        $pdo->prepare("UPDATE recepcion_punto SET $col = ? WHERE id = ?")
            ->execute([$publicUrl, $recepcionId]);
    } catch (Throwable $e2) {
        error_log('upload-recepcion-foto UPDATE: ' . $e2->getMessage());
        adminJsonOut(['error' => 'db_update_failed', 'detail' => $e2->getMessage()], 500);
    }
}

adminLog('inventario_recepcion_foto_replace', [
    'moto_id'      => $motoId,
    'recepcion_id' => $recepcionId,
    'tipo'         => $tipo,
    'fname'        => $fname,
    'dir'          => basename($uploadDir),
    'admin_id'     => (int)$uid,
]);

adminJsonOut([
    'ok'           => true,
    'message'      => 'Foto subida correctamente.',
    'tipo'         => $tipo,
    'recepcion_id' => $recepcionId,
    // URL routed through the admin serve helper — matches what detalle.php
    // returns on next fetch, so the frontend can swap the placeholder
    // without a full reload if it wants.
    'url'          => '/admin/php/inventario/serve-recepcion-foto.php?f=' . rawurlencode($fname),
]);
