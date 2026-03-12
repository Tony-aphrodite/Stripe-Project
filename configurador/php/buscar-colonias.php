<?php
/**
 * Voltika - Buscar colonias por código postal
 * Tries multiple free SEPOMEX APIs to return colonias for a given CP.
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

// ── Helper: cURL fetch ──────────────────────────────────────────────────────
function fetchUrl($url, $timeout = 8) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($httpCode === 200 && $body) ? $body : null;
}

$colonias = [];
$estado   = '';
$ciudad   = '';

// ── Strategy 1: Zippopotam.us (free, no token) ─────────────────────────────
$body = fetchUrl("https://api.zippopotam.us/mx/{$cp}");
if ($body) {
    $data = json_decode($body, true);
    if (isset($data['places']) && is_array($data['places'])) {
        foreach ($data['places'] as $place) {
            $name = isset($place['place name']) ? trim($place['place name']) : '';
            if ($name && !in_array($name, $colonias)) {
                $colonias[] = $name;
            }
            if (!$estado && isset($place['state'])) $estado = $place['state'];
        }
        // Zippopotam doesn't return municipio separately — use first place's state abbreviation
        if (!$ciudad && isset($data['places'][0]['place name'])) {
            // Ciudad comes from our static data (will be overridden by JS if needed)
            $ciudad = '';
        }
    }
}

// ── Strategy 2: Copomex API (if zippopotam returned no results) ─────────────
if (empty($colonias)) {
    // Try copomex with test token — may return limited data
    $body2 = fetchUrl("https://api.copomex.com/query/info_cp/{$cp}?type=simplified&token=pruebas");
    if ($body2) {
        $data2 = json_decode($body2, true);
        if (is_array($data2)) {
            foreach ($data2 as $item) {
                $r = isset($item['response']) ? $item['response'] : $item;
                if (!$estado && isset($r['estado'])) $estado = $r['estado'];
                if (!$ciudad && isset($r['municipio'])) $ciudad = $r['municipio'];
                $col = isset($r['asentamiento']) ? trim($r['asentamiento']) : '';
                if ($col && !in_array($col, $colonias)) {
                    $colonias[] = $col;
                }
            }
        }
    }
}

// ── Return result ───────────────────────────────────────────────────────────
if (empty($colonias)) {
    echo json_encode(['ok' => false, 'error' => 'No se encontraron colonias para este CP']);
    exit;
}

sort($colonias);

echo json_encode([
    'ok'       => true,
    'cp'       => $cp,
    'estado'   => $estado,
    'ciudad'   => $ciudad,
    'colonias' => $colonias
], JSON_UNESCAPED_UNICODE);
