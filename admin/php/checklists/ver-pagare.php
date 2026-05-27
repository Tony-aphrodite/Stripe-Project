<?php
/**
 * GET — Serve a PAGARÉ PDF file for viewing in the browser.
 * Auth: admin only.
 * Usage: /admin/php/checklists/ver-pagare.php?file=pagare_moto142_20260527_224059.pdf
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$file = basename(trim((string)($_GET['file'] ?? '')));
if ($file === '' || !preg_match('/^pagare_moto\d+_\d{8}_\d{6}(_v2)?\.pdf$/', $file)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Parámetro file inválido.';
    exit;
}

$path = sys_get_temp_dir() . '/voltika_pagares/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'PDF no encontrado en disco.';
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $file . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
