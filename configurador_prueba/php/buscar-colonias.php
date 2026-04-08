<?php
/**
 * Voltika - Buscar colonias por código postal
 * Uses local SEPOMEX data (140K+ records, 32K+ postal codes).
 *
 * GET /php/buscar-colonias.php?cp=76060
 * Response: { "ok": true, "estado": "...", "municipio": "...", "ciudad": "...", "colonias": ["..."] }
 */

header('Content-Type: application/json; charset=utf-8');

$cp = isset($_GET['cp']) ? preg_replace('/\D/', '', $_GET['cp']) : '';

if (strlen($cp) !== 5) {
    echo json_encode(['ok' => false, 'error' => 'CP debe ser 5 dígitos']);
    exit;
}

// Load SEPOMEX data (cached by PHP opcache on subsequent requests)
$dataFile = __DIR__ . '/sepomex_data.json';
if (!file_exists($dataFile)) {
    echo json_encode(['ok' => false, 'error' => 'Archivo de datos no encontrado']);
    exit;
}

$raw = file_get_contents($dataFile);
$data = json_decode($raw, true);

if (!isset($data[$cp])) {
    echo json_encode(['ok' => false, 'error' => 'Código postal no encontrado']);
    exit;
}

$entry = $data[$cp];

echo json_encode([
    'ok'        => true,
    'cp'        => $cp,
    'estado'    => $entry['estado'] ?? '',
    'municipio' => $entry['municipio'] ?? '',
    'ciudad'    => $entry['ciudad'] ?? '',
    'colonias'  => $entry['colonias'] ?? []
], JSON_UNESCAPED_UNICODE);
