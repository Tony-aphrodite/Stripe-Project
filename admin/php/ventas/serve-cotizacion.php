<?php
/**
 * GET — Stream a quotation file (seguro|placas) with auth + correct MIME.
 *
 * Params: transaccion_id, tipo, [inline=1|0]
 *
 * Auth: admin/cedis OR the client owning the transaction (cookie session).
 * For this first iteration we only allow admin/cedis — the client-portal
 * variant lives in /clientes/php/seguros/descargar.php.
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$txId = (int)($_GET['transaccion_id'] ?? 0);
$tipo = $_GET['tipo'] ?? '';
if (!$txId) { http_response_code(400); exit('transaccion_id requerido'); }
if (!in_array($tipo, ['seguro','placas'], true)) { http_response_code(400); exit('tipo inválido'); }

$col = $tipo . '_cotizacion_';
$pdo = getDB();
$stmt = $pdo->prepare("SELECT {$col}archivo AS archivo, {$col}mime AS mime, {$col}size AS size
                         FROM transacciones WHERE id=?");
$stmt->execute([$txId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row || empty($row['archivo'])) { http_response_code(404); exit('Sin archivo'); }

// Support both relative paths (under admin/) and absolute fallbacks
// (tmp dir used when all persistent candidates were read-only).
$stored = $row['archivo'];
$abs    = (strlen($stored) > 0 && $stored[0] === '/') || preg_match('#^[A-Z]:[\\\\/]#', $stored)
    ? $stored
    : dirname(__DIR__, 2) . '/' . ltrim($stored, '/');
if (!is_file($abs)) { http_response_code(404); exit('Archivo no encontrado en disco'); }

$mime = $row['mime'] ?: 'application/octet-stream';
$size = $row['size'] ?: filesize($abs);
$fname = 'cotizacion-' . $tipo . '-' . $txId . '.' . pathinfo($abs, PATHINFO_EXTENSION);
$inline = !empty($_GET['inline']);

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $fname . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=60');
readfile($abs);
