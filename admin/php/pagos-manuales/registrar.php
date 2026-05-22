<?php
/**
 * Voltika Admin — Round 67 (2026-05-22).
 *
 * Register a MANUAL payment against a transaction (i.e. a payment that
 * happened OUTSIDE Stripe — cash, bank transfer, deposit, cheque, etc.)
 * Captures monto + medio_pago + referencia + customer data + uploaded
 * receipt file, persists into pagos_manuales, and marks the parent
 * transaction as pagada so the rest of the system treats the order as
 * paid normally.
 *
 * Customer brief 2026-05-22 (Óscar): "We want in the purchase a function
 * to register manual payment with fields: monto, medio_pago, no. de
 * referencia, datos del cliente, archivo de comprobante. Only for the
 * super-administrator role."
 *
 * Auth: role='admin' (the highest existing role). File lives under
 *       /admin/php/pagos-manuales/ — a brand-new module — so the
 *       per-user permisos fallback only matches users whose permisos
 *       JSON explicitly contains 'pagos-manuales', which nobody has by
 *       default. Effectively super-admin without adding a role enum.
 *
 * URL: POST /admin/php/pagos-manuales/registrar.php
 * Body (multipart/form-data):
 *   transaccion_id    : int  (required)
 *   monto             : decimal (required)
 *   medio_pago        : 'efectivo'|'transferencia'|'deposito'|'cheque'|'otro' (required)
 *   referencia        : string (optional)
 *   cliente_nombre    : string (optional — auto-fills from transaction)
 *   cliente_email     : string (optional)
 *   cliente_telefono  : string (optional)
 *   notas             : text   (optional)
 *   comprobante       : file   (required — image or PDF, ≤10 MB)
 *
 * Response: { ok, pago_manual_id, transaccion_id, monto, medio_pago,
 *             comprobante_url, message }
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
$adminId = adminRequireAuth(['admin']);

$pdo = getDB();

// ── Idempotent schema setup ───────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pagos_manuales (
        id                 INT AUTO_INCREMENT PRIMARY KEY,
        transaccion_id     INT NOT NULL,
        monto              DECIMAL(12,2) NOT NULL,
        medio_pago         VARCHAR(50)  NOT NULL,
        referencia         VARCHAR(120) NULL,
        cliente_nombre     VARCHAR(200) NULL,
        cliente_email      VARCHAR(200) NULL,
        cliente_telefono   VARCHAR(30)  NULL,
        comprobante_archivo VARCHAR(255) NULL,
        comprobante_mime   VARCHAR(80)  NULL,
        comprobante_size   INT          NULL,
        notas              TEXT         NULL,
        admin_id           INT          NOT NULL,
        admin_nombre       VARCHAR(200) NULL,
        freg               DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_transaccion (transaccion_id),
        INDEX idx_freg        (freg)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) { error_log('pagos_manuales create: ' . $e->getMessage()); }

try {
    $cols = $pdo->query("SHOW COLUMNS FROM transacciones LIKE 'pago_manual_id'")->fetch();
    if (!$cols) {
        $pdo->exec("ALTER TABLE transacciones ADD COLUMN pago_manual_id INT NULL,
                                                ADD INDEX idx_pago_manual (pago_manual_id)");
    }
} catch (Throwable $e) { /* non-fatal */ }

// ── Validate inputs ───────────────────────────────────────────────────────
$transId      = (int)($_POST['transaccion_id'] ?? 0);
$monto        = (float)str_replace([',', '$', ' '], '', (string)($_POST['monto'] ?? ''));
$medioPago    = strtolower(trim((string)($_POST['medio_pago'] ?? '')));
$referencia   = trim((string)($_POST['referencia'] ?? ''));
$clienteNom   = trim((string)($_POST['cliente_nombre']   ?? ''));
$clienteEmail = trim((string)($_POST['cliente_email']    ?? ''));
$clienteTel   = trim((string)($_POST['cliente_telefono'] ?? ''));
$notas        = trim((string)($_POST['notas'] ?? ''));

if (!$transId) adminJsonOut(['error' => 'transaccion_id es requerido'], 400);
if ($monto <= 0) adminJsonOut(['error' => 'monto inválido (debe ser un número mayor a 0)'], 400);

$mediosValidos = ['efectivo','transferencia','deposito','cheque','otro'];
if (!in_array($medioPago, $mediosValidos, true)) {
    adminJsonOut(['error' => 'medio_pago no válido. Debe ser uno de: ' . implode(', ', $mediosValidos)], 400);
}

if (strlen($referencia) > 120) adminJsonOut(['error' => 'referencia demasiado larga (máx 120 caracteres)'], 400);
if (strlen($notas)      > 4000) adminJsonOut(['error' => 'notas demasiado largas (máx 4000 caracteres)'], 400);

// ── Validate uploaded receipt file ────────────────────────────────────────
if (empty($_FILES['comprobante']) || ($_FILES['comprobante']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    adminJsonOut(['error' => 'Comprobante de pago requerido (archivo subido).'], 400);
}
$file = $_FILES['comprobante'];
if ($file['size'] > 10 * 1024 * 1024) {
    adminJsonOut(['error' => 'El comprobante supera 10 MB. Comprime el archivo antes de subirlo.'], 413);
}
if ($file['size'] < 256) {
    adminJsonOut(['error' => 'El comprobante es demasiado pequeño (probablemente vacío).'], 400);
}
$mime = function_exists('mime_content_type') ? @mime_content_type($file['tmp_name']) : ($file['type'] ?? '');
$mimeAllowed = preg_match('#^image/(jpe?g|png|webp|heic|gif)$#i', (string)$mime) === 1
            || strtolower((string)$mime) === 'application/pdf';
if (!$mimeAllowed) {
    adminJsonOut(['error' => 'Solo se aceptan imágenes (JPG, PNG, WEBP, HEIC, GIF) o PDF como comprobante.'], 415);
}

// ── Find the transaction (auto-fill missing client data) ─────────────────
$tq = $pdo->prepare("SELECT id, pedido, pedido_corto, nombre, email, telefono, total, pago_estado
                       FROM transacciones WHERE id = ? LIMIT 1");
$tq->execute([$transId]);
$tx = $tq->fetch(PDO::FETCH_ASSOC);
if (!$tx) adminJsonOut(['error' => 'Transacción no encontrada.', 'transaccion_id' => $transId], 404);

if ($clienteNom   === '') $clienteNom   = (string)($tx['nombre']   ?? '');
if ($clienteEmail === '') $clienteEmail = (string)($tx['email']    ?? '');
if ($clienteTel   === '') $clienteTel   = (string)($tx['telefono'] ?? '');

// ── Locate a writable upload dir for the receipt (multi-candidate) ───────
$candidates = [
    __DIR__ . '/../../../configurador/php/uploads/pagos-manuales',
    __DIR__ . '/../data/pagos-manuales',
    __DIR__ . '/../../../uploads/pagos-manuales',
    sys_get_temp_dir() . '/voltika_pagos_manuales',
];
$uploadDir = '';
foreach ($candidates as $c) {
    if (!is_dir($c)) { @mkdir($c, 0775, true); @chmod($c, 0775); }
    if (is_dir($c) && is_writable($c)) { $uploadDir = $c; break; }
}
if ($uploadDir === '') {
    adminJsonOut(['error' => 'El servidor no pudo encontrar una carpeta donde guardar el comprobante. Contacta a soporte técnico.'], 500);
}

// ── Decide the file extension from mime ──────────────────────────────────
$ext = 'jpg';
if (preg_match('#^image/(jpe?g|png|webp|heic|gif)$#i', (string)$mime, $m)) {
    $ext = strtolower($m[1]);
    if ($ext === 'jpeg') $ext = 'jpg';
} elseif (strtolower((string)$mime) === 'application/pdf') {
    $ext = 'pdf';
}

$fname = 'comprobante_tx' . $transId . '_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 6) . '.' . $ext;
$dest  = $uploadDir . '/' . $fname;

if (!@move_uploaded_file($file['tmp_name'], $dest)) {
    $bin = @file_get_contents($file['tmp_name']);
    if ($bin === false || @file_put_contents($dest, $bin) === false) {
        adminJsonOut(['error' => 'No se pudo guardar el comprobante en disco.', 'dir' => basename($uploadDir)], 500);
    }
}
@chmod($dest, 0640);

// ── Resolve admin name for audit trail ──────────────────────────────────
$adminNombre = '';
try {
    $du = $pdo->prepare("SELECT nombre FROM dealer_usuarios WHERE id = ? LIMIT 1");
    $du->execute([$adminId]);
    $adminNombre = (string)($du->fetchColumn() ?: '');
} catch (Throwable $e) { /* non-fatal */ }

// ── Insert the manual payment row + flip transaction to pagada ──────────
try {
    $pdo->beginTransaction();

    $ins = $pdo->prepare("INSERT INTO pagos_manuales
        (transaccion_id, monto, medio_pago, referencia,
         cliente_nombre, cliente_email, cliente_telefono,
         comprobante_archivo, comprobante_mime, comprobante_size,
         notas, admin_id, admin_nombre)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $ins->execute([
        $transId, $monto, $medioPago, $referencia ?: null,
        $clienteNom ?: null, $clienteEmail ?: null, $clienteTel ?: null,
        $fname, (string)$mime, (int)$file['size'],
        $notas ?: null, $adminId, $adminNombre ?: null,
    ]);
    $pagoManualId = (int)$pdo->lastInsertId();

    $pdo->prepare("UPDATE transacciones
                      SET pago_estado    = 'pagada',
                          pago_manual_id = ?,
                          fmod           = NOW()
                    WHERE id = ?")
        ->execute([$pagoManualId, $transId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // Best-effort cleanup of orphaned file.
    @unlink($dest);
    error_log('pagos-manuales registrar: ' . $e->getMessage());
    adminJsonOut(['error' => 'Error de base de datos al registrar el pago: ' . $e->getMessage()], 500);
}

adminLog('pago_manual_registrado', [
    'pago_manual_id'  => $pagoManualId,
    'transaccion_id'  => $transId,
    'pedido'          => $tx['pedido_corto'] ?: ($tx['pedido'] ?: ''),
    'monto'           => $monto,
    'medio_pago'      => $medioPago,
    'referencia'      => $referencia,
    'admin_id'        => $adminId,
    'comprobante'     => $fname,
]);

adminJsonOut([
    'ok'              => true,
    'pago_manual_id'  => $pagoManualId,
    'transaccion_id'  => $transId,
    'monto'           => $monto,
    'medio_pago'      => $medioPago,
    'referencia'      => $referencia,
    'comprobante_url' => '/admin/php/pagos-manuales/serve-comprobante.php?id=' . $pagoManualId,
    'message'         => 'Pago manual registrado correctamente. La transacción quedó marcada como pagada.',
]);
