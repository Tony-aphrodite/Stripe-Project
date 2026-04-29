<?php
/**
 * GET ?f=filename — Serve Carta Factura PDF from temp storage.
 * Mirrors serve-pagare.php / serve-acta.php (admin-only access).
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$filename = basename($_GET['f'] ?? '');
if (!$filename || !preg_match('/^carta_factura_moto\d+_\d{8}_\d{6}\.pdf$/', $filename)) {
    http_response_code(400);
    exit('Nombre de archivo inválido');
}

$storageDir = sys_get_temp_dir() . '/voltika_carta_factura/';
$filePath = $storageDir . $filename;

if (!file_exists($filePath)) {
    http_response_code(404);
    exit('Archivo no encontrado');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache, no-store, must-revalidate');
readfile($filePath);
