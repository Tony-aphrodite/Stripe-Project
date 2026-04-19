<?php
/**
 * POST multipart/form-data — Upload a quotation file for seguro or placas.
 *
 * Fields:
 *   transaccion_id  int     required
 *   tipo            string  'seguro' | 'placas'
 *   file            binary  PDF or image (≤5MB)
 *
 * Response: { ok, filename, size, mime, url }
 *
 * Storage path:
 *   admin/uploads/cotizaciones/<tipo>/<tx_id>/<hash>.<ext>
 *
 * On success the DB columns {tipo}_cotizacion_archivo/mime/size/subido are
 * written to `transacciones`. The old file (if any) is deleted so each order
 * holds at most one quotation per tipo.
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$txId = (int)($_POST['transaccion_id'] ?? 0);
$tipo = $_POST['tipo'] ?? '';
if (!$txId) adminJsonOut(['error' => 'transaccion_id requerido'], 400);
if (!in_array($tipo, ['seguro', 'placas'], true)) {
    adminJsonOut(['error' => 'tipo inválido (seguro|placas)'], 400);
}

if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    adminJsonOut(['error' => 'Archivo no recibido o con error'], 400);
}

$f = $_FILES['file'];
$size = (int)($f['size'] ?? 0);
if ($size <= 0)                adminJsonOut(['error' => 'Archivo vacío'], 400);
if ($size > 5 * 1024 * 1024)   adminJsonOut(['error' => 'Archivo excede 5MB'], 400);

// MIME whitelist — detect from file contents, not just the client-sent string.
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($f['tmp_name']);
$allowedMimes = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
];
if (!isset($allowedMimes[$mime])) {
    adminJsonOut(['error' => 'Tipo de archivo no permitido (solo PDF, JPG, PNG, WEBP). Detectado: ' . $mime], 400);
}
$ext = $allowedMimes[$mime];

$pdo = getDB();

// Ensure target columns exist (idempotent — runs once the first time).
foreach (['seguro', 'placas'] as $t) {
    foreach ([
        "${t}_cotizacion_archivo VARCHAR(500) NULL",
        "${t}_cotizacion_mime    VARCHAR(80)  NULL",
        "${t}_cotizacion_size    INT          NULL",
        "${t}_cotizacion_subido  DATETIME     NULL",
    ] as $defRaw) {
        [$col] = explode(' ', trim($defRaw), 2);
        try {
            $pdo->exec("ALTER TABLE transacciones ADD COLUMN IF NOT EXISTS $defRaw");
        } catch (Throwable $e) {
            // MySQL <8 doesn't support IF NOT EXISTS on ADD COLUMN; fall back.
            try {
                $has = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                                       WHERE TABLE_SCHEMA=DATABASE()
                                         AND TABLE_NAME='transacciones'
                                         AND COLUMN_NAME=?");
                $has->execute([$col]);
                if (!(int)$has->fetchColumn()) {
                    $pdo->exec("ALTER TABLE transacciones ADD COLUMN $defRaw");
                }
            } catch (Throwable $e2) { error_log('cotizacion col add: ' . $e2->getMessage()); }
        }
    }
}

// Verify the transaction exists + carries the corresponding flag.
$stmt = $pdo->prepare("SELECT id, asesoria_placas, seguro_qualitas,
                              seguro_cotizacion_archivo, placas_cotizacion_archivo
                         FROM transacciones WHERE id=?");
$stmt->execute([$txId]);
$tx = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$tx) adminJsonOut(['error' => 'Transacción no encontrada'], 404);
if ($tipo === 'seguro' && empty($tx['seguro_qualitas'])) {
    adminJsonOut(['error' => 'Esta orden no tiene seguro solicitado'], 400);
}
if ($tipo === 'placas' && empty($tx['asesoria_placas'])) {
    adminJsonOut(['error' => 'Esta orden no tiene asesoría de placas'], 400);
}

// Storage directory — reuse admin/uploads/checklists/ which PHP already owns
// (created at runtime by subir-foto.php with the correct user). Cotización
// files live in a dedicated subfolder there; PHP can chmod + write freely
// because the parent is owned by the PHP user.
//
// Also try a couple of fallback roots in case the Plesk instance uses a
// different layout. First writable candidate wins.
$adminRoot = dirname(__DIR__, 2);
$candidates = [
    $adminRoot . '/uploads/checklists/cotizaciones/' . $tipo,  // primary — riding on checklists perms
    $adminRoot . '/uploads/cotizaciones/' . $tipo,             // fallback — if cotizaciones is fixed later
    sys_get_temp_dir() . '/voltika_cotizaciones/' . $tipo,     // last resort — tmp (non-persistent!)
];
$baseDir = null; $relRoot = null;
foreach ($candidates as $i => $cand) {
    if (!is_dir($cand)) @mkdir($cand, 0775, true);
    if (is_dir($cand) && is_writable($cand)) {
        $baseDir = $cand;
        // Compute the relative path we'll store in the DB; only the first two
        // candidates live under $adminRoot so they can be served back.
        if ($i === 0) $relRoot = 'uploads/checklists/cotizaciones/' . $tipo;
        elseif ($i === 1) $relRoot = 'uploads/cotizaciones/' . $tipo;
        else $relRoot = null; // tmp fallback — stored as absolute
        break;
    }
}
if (!$baseDir) {
    adminJsonOut([
        'error' => 'No se encontró ninguna carpeta escribible para cotizaciones',
        'hint'  => 'Revisa permisos de admin/uploads/checklists/ — si esa carpeta funciona para checklists, debería funcionar aquí también',
    ], 500);
}

// Filename embeds tx_id so admins can recognise files visually when browsing
// the folder via FTP, plus a random hash to prevent collisions on re-upload.
$newName  = $txId . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
$destPath = $baseDir . '/' . $newName;

if (!@move_uploaded_file($f['tmp_name'], $destPath)) {
    adminJsonOut(['error' => 'No se pudo mover el archivo subido'], 500);
}

// Delete the previous file (if any) — we only keep the latest quotation.
$oldRel = $tx[$tipo . '_cotizacion_archivo'] ?? '';
if ($oldRel) {
    $oldAbs = dirname(__DIR__, 2) . '/' . ltrim($oldRel, '/');
    if (is_file($oldAbs) && $oldAbs !== $destPath) @unlink($oldAbs);
}

// Relative path (from admin/) that we store — the serve endpoint re-anchors
// it. If the fallback was the tmp dir, $relRoot is null — store the absolute
// path so serve-cotizacion.php can still find the file.
$relPath = $relRoot ? ($relRoot . '/' . $newName) : $destPath;

$col = $tipo . '_cotizacion_';
$pdo->prepare("UPDATE transacciones SET
        {$col}archivo=?, {$col}mime=?, {$col}size=?, {$col}subido=NOW(),
        servicios_fmod=NOW(), servicios_admin_uid=?
    WHERE id=?")
   ->execute([$relPath, $mime, $size, $uid, $txId]);

adminLog('cotizacion_subida', ['tx_id' => $txId, 'tipo' => $tipo, 'size' => $size, 'mime' => $mime]);

adminJsonOut([
    'ok'       => true,
    'tipo'     => $tipo,
    'filename' => $newName,
    'size'     => $size,
    'mime'     => $mime,
    'url'      => 'php/ventas/serve-cotizacion.php?transaccion_id=' . $txId . '&tipo=' . $tipo,
]);
