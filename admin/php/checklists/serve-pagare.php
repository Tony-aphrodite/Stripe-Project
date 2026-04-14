<?php
/**
 * GET ?f=filename — Serve pagaré PDF from temp storage
 * Similar to serve-foto.php but for PDF files
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$filename = basename($_GET['f'] ?? '');
if (!$filename || !preg_match('/^pagare_moto\d+_\d{8}_\d{6}\.pdf$/', $filename)) {
    http_response_code(400);
    exit('Nombre de archivo inválido');
}

$storageDir = sys_get_temp_dir() . '/voltika_pagares/';
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
