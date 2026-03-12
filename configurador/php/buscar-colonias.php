<?php
/**
 * Voltika - Buscar colonias por código postal
 * Uses the free Copomex API (SEPOMEX data) to return colonias for a given CP.
 *
 * GET /php/buscar-colonias.php?cp=76060
 * Response: { "ok": true, "estado": "...", "ciudad": "...", "colonias": ["..."] }
 */

header('Content-Type: application/json; charset=utf-8');

$cp = isset($_GET['cp']) ? preg_replace('/\D/', '', $_GET['cp']) : '';

if (strlen($cp) !== 5) {
    echo json_encode(['ok' => false, 'error' => 'CP debe ser 5 dígitos']);
    exit;
}

// Copomex API — free tier token (replace with your own for production)
$token = 'pruebas';
$url = "https://api.copomex.com/query/info_cp/{$cp}?type=simplified&token={$token}";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
]);
$body = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$body) {
    echo json_encode(['ok' => false, 'error' => 'No se pudo consultar el servicio postal']);
    exit;
}

$data = json_decode($body, true);

if (!is_array($data) || empty($data)) {
    echo json_encode(['ok' => false, 'error' => 'Código postal no encontrado']);
    exit;
}

// Extract colonias, estado, ciudad from Copomex response
$colonias = [];
$estado = '';
$ciudad = '';

foreach ($data as $item) {
    $r = isset($item['response']) ? $item['response'] : $item;

    if (!$estado && isset($r['estado'])) $estado = $r['estado'];
    if (!$ciudad && isset($r['municipio'])) $ciudad = $r['municipio'];

    $colonia = isset($r['asentamiento']) ? $r['asentamiento'] : '';
    if ($colonia && !in_array($colonia, $colonias)) {
        $colonias[] = $colonia;
    }
}

sort($colonias);

echo json_encode([
    'ok'       => true,
    'cp'       => $cp,
    'estado'   => $estado,
    'ciudad'   => $ciudad,
    'colonias' => $colonias
], JSON_UNESCAPED_UNICODE);
