<?php
/**
 * POST — Save digital signature for delivery checklist
 * Body: { moto_id, tipo: 'acta'|'pagare', firma_data (base64 PNG) }
 * For tipo=pagare, calls CINCEL API for NOM-151 timestamp
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$motoId   = (int)($d['moto_id'] ?? 0);
$tipo     = $d['tipo'] ?? 'acta';
$firmaB64 = $d['firma_data'] ?? '';

if (!$motoId || !$firmaB64) adminJsonOut(['error' => 'moto_id y firma_data requeridos'], 400);
if (!in_array($tipo, ['acta', 'pagare'])) adminJsonOut(['error' => 'Tipo de firma inválido'], 400);

// Validate base64 image
if (strpos($firmaB64, 'data:image/png;base64,') !== 0) {
    adminJsonOut(['error' => 'Formato de firma inválido'], 400);
}

$pdo = getDB();

$stmt = $pdo->prepare("SELECT id, completado FROM checklist_entrega_v2 WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$stmt->execute([$motoId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) adminJsonOut(['error' => 'Checklist de entrega no encontrado'], 404);
if ($row['completado']) adminJsonOut(['error' => 'Checklist ya completado'], 403);

$checkId = $row['id'];
$result = ['ok' => true, 'tipo' => $tipo];

if ($tipo === 'acta') {
    // Simple signature save — acta de entrega
    $pdo->prepare("UPDATE checklist_entrega_v2 SET firma_acta_data=?, firma_digital=1 WHERE id=?")
        ->execute([$firmaB64, $checkId]);

    // Also save to legacy firma_data for backwards compatibility
    $pdo->prepare("UPDATE checklist_entrega_v2 SET firma_data=? WHERE id=?")
        ->execute([$firmaB64, $checkId]);

} else {
    // Pagaré signature — save + CINCEL NOM-151 timestamp
    $pdo->prepare("UPDATE checklist_entrega_v2 SET firma_pagare_data=? WHERE id=?")
        ->execute([$firmaB64, $checkId]);

    // Call CINCEL API for NOM-151 timestamp
    $cincelResult = cincelTimestamp($firmaB64, $motoId);
    if ($cincelResult['ok']) {
        $pdo->prepare("UPDATE checklist_entrega_v2 SET firma_pagare_timestamp=NOW(), firma_pagare_cincel_id=? WHERE id=?")
            ->execute([$cincelResult['cincel_id'], $checkId]);
        $result['timestamp'] = date('Y-m-d H:i:s');
        $result['cincel_id'] = $cincelResult['cincel_id'];
    } else {
        // Save timestamp locally even if CINCEL fails
        $pdo->prepare("UPDATE checklist_entrega_v2 SET firma_pagare_timestamp=NOW() WHERE id=?")
            ->execute([$checkId]);
        $result['timestamp'] = date('Y-m-d H:i:s');
        $result['cincel_warning'] = $cincelResult['error'];
    }
}

adminLog('checklist_firma_' . $tipo, ['moto_id' => $motoId, 'checklist_id' => $checkId]);
adminJsonOut($result);

// ── CINCEL NOM-151 Timestamp ─────────────────────────────────────────────

function cincelTimestamp(string $firmaB64, int $motoId): array {
    $apiUrl  = defined('CINCEL_API_URL')  ? CINCEL_API_URL  : '';
    $email   = defined('CINCEL_EMAIL')    ? CINCEL_EMAIL    : '';
    $pass    = defined('CINCEL_PASSWORD') ? CINCEL_PASSWORD : '';

    if (!$apiUrl || !$email || !$pass) {
        return ['ok' => false, 'error' => 'CINCEL no configurado'];
    }

    // Step 1: Authenticate
    $ch = curl_init($apiUrl . '/auth/tokens');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'password' => $pass]),
        CURLOPT_TIMEOUT => 15,
    ]);
    $authResp = json_decode(curl_exec($ch), true);
    $authCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $token = $authResp['access_token'] ?? $authResp['token'] ?? null;
    if (!$token || $authCode >= 400) {
        error_log('CINCEL auth failed: ' . json_encode($authResp));
        return ['ok' => false, 'error' => 'CINCEL autenticación fallida'];
    }

    // Step 2: Create timestamp request
    // Convert base64 PNG to raw binary for hash
    $rawData = base64_decode(str_replace('data:image/png;base64,', '', $firmaB64));
    $hash = hash('sha256', $rawData);

    $ch = curl_init($apiUrl . '/timestamps');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'hash'        => $hash,
            'algorithm'   => 'SHA-256',
            'description' => 'Firma pagaré — Moto ID ' . $motoId,
        ]),
        CURLOPT_TIMEOUT => 30,
    ]);
    $tsResp = json_decode(curl_exec($ch), true);
    $tsCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $tsErr  = curl_error($ch);
    curl_close($ch);

    if ($tsErr || $tsCode >= 400) {
        error_log('CINCEL timestamp failed: ' . json_encode($tsResp) . ' err: ' . $tsErr);
        return ['ok' => false, 'error' => 'CINCEL timestamp fallido'];
    }

    $cincelId = $tsResp['id'] ?? $tsResp['timestamp_id'] ?? $tsResp['data']['id'] ?? $hash;
    return ['ok' => true, 'cincel_id' => (string)$cincelId];
}
