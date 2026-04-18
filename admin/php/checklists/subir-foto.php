<?php
/**
 * POST — Upload photo(s) for a checklist
 * Multipart form: checklist_tipo, moto_id, campo, foto (file)
 * Returns: { ok, url, filename }
 *
 * campo values:
 *   origen:   fotos (legacy), foto_unidad_completa, foto_vin, foto_tablero_encendido,
 *             foto_bateria, foto_contenido_previo_cierre, foto_caja_cerrada, foto_sellos,
 *             foto_detalle_calcomanias, foto_empaque_accesorios, foto_empaque_llaves
 *   ensamble: fotos_fase1, fotos_base, fotos_manubrio, fotos_llanta, fotos_espejos, fotos_fase3
 *   entrega:  fotos_identidad, fotos_unidad
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$tipo   = $_POST['checklist_tipo'] ?? '';
$motoId = (int)($_POST['moto_id'] ?? 0);
$campo  = $_POST['campo'] ?? '';

if (!$motoId || !$tipo || !$campo) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Parámetros incompletos']);
    exit;
}

// Validate tipo
$validTipos = ['origen', 'ensamble', 'entrega'];
if (!in_array($tipo, $validTipos)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Tipo de checklist inválido']);
    exit;
}

// Validate campo per tipo
$validCampos = [
    'origen'   => [
        'fotos',
        'foto_unidad_completa','foto_vin','foto_tablero_encendido','foto_bateria',
        'foto_contenido_previo_cierre','foto_caja_cerrada','foto_sellos',
        'foto_detalle_calcomanias','foto_empaque_accesorios','foto_empaque_llaves',
    ],
    'ensamble' => ['fotos_fase1','fotos_base','fotos_manubrio','fotos_llanta','fotos_espejos','fotos_fase3'],
    'entrega'  => ['fotos_identidad','fotos_unidad'],
];
if (!in_array($campo, $validCampos[$tipo] ?? [])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Campo de foto inválido para este checklist']);
    exit;
}

// Validate file
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

// Validate MIME type
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
$allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

if (!isset($allowedMimes[$mime])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Solo se permiten imágenes (JPG, PNG, WebP, GIF)']);
    exit;
}

// Max 10MB
if ($file['size'] > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Imagen muy grande (máx 10 MB)']);
    exit;
}

$ext = $allowedMimes[$mime];
$filename = $tipo . '_' . $motoId . '_' . $campo . '_' . time() . '_' . mt_rand(100,999) . '.' . $ext;

$uploadDir = __DIR__ . '/../../uploads/checklists/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}
// Fallback to temp directory if permanent storage is not available
if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
    $uploadDir = sys_get_temp_dir() . '/voltika_checklists/';
    if (!is_dir($uploadDir)) {
        if (!@mkdir($uploadDir, 0775, true)) {
            error_log('subir-foto: No se pudo crear directorio ' . $uploadDir);
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo crear directorio de almacenamiento']);
            exit;
        }
    }
}

$destPath = $uploadDir . $filename;
if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    $lastErr = error_get_last();
    error_log('subir-foto: move_uploaded_file falló — dest: ' . $destPath . ' error: ' . ($lastErr['message'] ?? 'desconocido'));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al guardar archivo']);
    exit;
}

// Update the JSON array in the corresponding checklist table
try {
    $pdo = getDB();
    $tableMap = ['origen' => 'checklist_origen', 'ensamble' => 'checklist_ensamble', 'entrega' => 'checklist_entrega_v2'];
    $table = $tableMap[$tipo];

    // Get existing record
    $stmt = $pdo->prepare("SELECT id, `$campo` FROM `$table` WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
    $stmt->execute([$motoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $relativeUrl = 'php/checklists/serve-foto.php?f=' . $filename;

    if ($row) {
        // Append to existing JSON array
        $existing = json_decode($row[$campo] ?: '[]', true) ?: [];
        $existing[] = $relativeUrl;
        $pdo->prepare("UPDATE `$table` SET `$campo`=? WHERE id=?")->execute([
            json_encode($existing, JSON_UNESCAPED_UNICODE),
            $row['id']
        ]);
    } else {
        // Create minimal record so photo is linked
        $fotos = json_encode([$relativeUrl], JSON_UNESCAPED_UNICODE);
        $pdo->prepare("INSERT INTO `$table` (moto_id, dealer_id, `$campo`) VALUES (?,?,?)")
            ->execute([$motoId, $uid, $fotos]);
    }
} catch (Throwable $e) {
    error_log('subir-foto DB error: ' . $e->getMessage());
    // File was saved successfully, just DB link failed — return success with warning
    echo json_encode([
        'ok'       => true,
        'url'      => 'php/checklists/serve-foto.php?f=' . $filename,
        'filename' => $filename,
        'warning'  => 'Foto guardada pero no se pudo vincular al checklist: ' . $e->getMessage(),
    ]);
    exit;
}

adminLog('checklist_foto', ['tipo' => $tipo, 'moto_id' => $motoId, 'campo' => $campo, 'file' => $filename]);

echo json_encode([
    'ok'       => true,
    'url'      => $relativeUrl,
    'filename' => $filename,
]);
