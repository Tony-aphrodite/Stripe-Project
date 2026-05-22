<?php
/**
 * Voltika Admin — Round 67 (2026-05-22).
 *
 * Serve a manual-payment receipt with admin authentication. Receipt files
 * contain sensitive financial info (bank reference numbers, customer IDs)
 * so they must NEVER be exposed via a plain URL — only via this gated
 * endpoint after adminRequireAuth passes.
 *
 * URL: GET /admin/php/pagos-manuales/serve-comprobante.php?id=<pago_manual_id>
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
$adminId = adminRequireAuth(['admin']);

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'id requerido';
    exit;
}

$pdo = getDB();
$row = $pdo->prepare("SELECT comprobante_archivo, comprobante_mime, transaccion_id
                       FROM pagos_manuales WHERE id = ? LIMIT 1");
$row->execute([$id]);
$rec = $row->fetch(PDO::FETCH_ASSOC);

if (!$rec || empty($rec['comprobante_archivo'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Comprobante no encontrado.';
    exit;
}

$fname = (string)$rec['comprobante_archivo'];

// Resolve the file across the same candidate dirs the writer used.
$candidates = [
    __DIR__ . '/../../../configurador/php/uploads/pagos-manuales',
    __DIR__ . '/../data/pagos-manuales',
    __DIR__ . '/../../../uploads/pagos-manuales',
    sys_get_temp_dir() . '/voltika_pagos_manuales',
];
$path = '';
foreach ($candidates as $c) {
    $p = $c . '/' . $fname;
    if (is_file($p)) { $path = $p; break; }
}
if ($path === '') {
    http_response_code(410);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'El archivo del comprobante ya no existe en el servidor.';
    exit;
}

$mime = $rec['comprobante_mime'] ?: 'application/octet-stream';
$size = (int)@filesize($path);

// Decide inline vs download based on type. PDF and images render inline,
// everything else triggers a download dialog.
$disposition = (strpos($mime, 'image/') === 0 || $mime === 'application/pdf')
    ? 'inline' : 'attachment';

adminLog('pago_manual_comprobante_view', [
    'pago_manual_id' => $id,
    'transaccion_id' => (int)$rec['transaccion_id'],
    'admin_id'       => $adminId,
]);

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Content-Disposition: ' . $disposition . '; filename="' . basename($fname) . '"');
// Don't cache — every fetch must re-auth.
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($path);
