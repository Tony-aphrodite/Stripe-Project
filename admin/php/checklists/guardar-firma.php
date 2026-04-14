<?php
/**
 * POST — Save digital signature for delivery checklist
 * Body: { moto_id, tipo: 'acta'|'pagare', firma_data (base64 PNG) }
 * For tipo=pagare:
 *   1. Re-generates pagaré PDF with signature embedded
 *   2. Hashes the FINAL PDF (not the signature image)
 *   3. Sends PDF hash to CINCEL API for NOM-151 timestamp
 *   4. Saves evidence metadata
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

$stmt = $pdo->prepare("SELECT id, completado, otp_code, otp_timestamp, fase4_completada, fase4_fecha
    FROM checklist_entrega_v2 WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$stmt->execute([$motoId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) adminJsonOut(['error' => 'Checklist de entrega no encontrado'], 404);
if ($row['completado']) adminJsonOut(['error' => 'Checklist ya completado'], 403);

$checkId = $row['id'];
$result = ['ok' => true, 'tipo' => $tipo];

if ($tipo === 'acta') {
    // ── Simple signature save — acta de entrega ─────────────────────────
    $pdo->prepare("UPDATE checklist_entrega_v2 SET firma_acta_data=?, firma_digital=1 WHERE id=?")
        ->execute([$firmaB64, $checkId]);

    // Also save to legacy firma_data for backwards compatibility
    $pdo->prepare("UPDATE checklist_entrega_v2 SET firma_data=? WHERE id=?")
        ->execute([$firmaB64, $checkId]);

} else {
    // ── Pagaré signature — PDF generation + CINCEL NOM-151 ──────────────

    // Save raw signature to DB
    $pdo->prepare("UPDATE checklist_entrega_v2 SET firma_pagare_data=? WHERE id=?")
        ->execute([$firmaB64, $checkId]);

    // Step 1: Re-generate pagaré PDF WITH the signature embedded
    $pdfResult = regeneratePagarePDFWithSignature($motoId, $firmaB64, $checkId, $pdo);

    if (!$pdfResult['ok']) {
        // Even if PDF generation fails, signature is saved
        $pdo->prepare("UPDATE checklist_entrega_v2 SET firma_pagare_timestamp=NOW() WHERE id=?")
            ->execute([$checkId]);
        $result['timestamp'] = date('Y-m-d H:i:s');
        $result['pdf_warning'] = $pdfResult['error'];
    } else {
        $pdfHash = $pdfResult['pdf_hash'];
        $pdfFilename = $pdfResult['pdf_path'];

        // Step 2: Send PDF hash to CINCEL for NOM-151 timestamp
        $cincelResult = cincelTimestampPDF($pdfHash, $motoId);

        // Collect evidence
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip) $ip = explode(',', $ip)[0];
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $evidencia = json_encode([
            'ip' => $ip,
            'user_agent' => substr($ua, 0, 500),
            'fecha_hora' => date('Y-m-d H:i:s'),
            'otp_validado' => $row['fase4_completada'] ? true : false,
            'otp_code' => $row['otp_code'] ?? null,
            'otp_timestamp' => $row['otp_timestamp'] ?? null,
            'pdf_hash' => $pdfHash,
            'cincel_ok' => $cincelResult['ok'],
            'cincel_id' => $cincelResult['cincel_id'] ?? null,
            'generado_por' => $uid,
        ], JSON_UNESCAPED_UNICODE);

        if ($cincelResult['ok']) {
            $pdo->prepare("UPDATE checklist_entrega_v2
                SET firma_pagare_timestamp=NOW(), firma_pagare_cincel_id=?,
                    pagare_pdf_path=?, pagare_pdf_hash=?,
                    pagare_ip=?, pagare_user_agent=?, pagare_evidencia=?
                WHERE id=?")
                ->execute([
                    $cincelResult['cincel_id'], $pdfFilename, $pdfHash,
                    $ip, substr($ua, 0, 500), $evidencia, $checkId
                ]);
            $result['timestamp'] = date('Y-m-d H:i:s');
            $result['cincel_id'] = $cincelResult['cincel_id'];
            $result['pdf_hash'] = $pdfHash;
            $result['pdf_path'] = $pdfFilename;
        } else {
            // Save everything except cincel_id
            $pdo->prepare("UPDATE checklist_entrega_v2
                SET firma_pagare_timestamp=NOW(),
                    pagare_pdf_path=?, pagare_pdf_hash=?,
                    pagare_ip=?, pagare_user_agent=?, pagare_evidencia=?
                WHERE id=?")
                ->execute([$pdfFilename, $pdfHash, $ip, substr($ua, 0, 500), $evidencia, $checkId]);
            $result['timestamp'] = date('Y-m-d H:i:s');
            $result['pdf_hash'] = $pdfHash;
            $result['pdf_path'] = $pdfFilename;
            $result['cincel_warning'] = $cincelResult['error'];
        }
    }
}

adminLog('checklist_firma_' . $tipo, ['moto_id' => $motoId, 'checklist_id' => $checkId]);
adminJsonOut($result);


// ── Re-generate pagaré PDF with signature embedded ──────────────────────

function regeneratePagarePDFWithSignature(int $motoId, string $firmaB64, int $checkId, PDO $pdo): array {
    try {
        // Call generar-pagare internally by including its logic
        // We make an internal HTTP call to reuse the full PDF generation
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $url = $baseUrl . dirname($_SERVER['SCRIPT_NAME']) . '/generar-pagare.php';

        // Build the request with session cookie for auth
        $sessionName = session_name();
        $sessionId = session_id();
        $cookie = $sessionName . '=' . $sessionId;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Cookie: ' . $cookie,
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'moto_id' => $motoId,
                'firma_data' => $firmaB64,
            ]),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr || $httpCode >= 400) {
            error_log("Pagare PDF regen failed: HTTP {$httpCode}, err: {$curlErr}, resp: " . substr($resp, 0, 500));
            return ['ok' => false, 'error' => 'Error generando PDF: ' . ($curlErr ?: "HTTP {$httpCode}")];
        }

        $data = json_decode($resp, true);
        if (!$data || empty($data['ok'])) {
            return ['ok' => false, 'error' => $data['error'] ?? 'Respuesta PDF inválida'];
        }

        return [
            'ok' => true,
            'pdf_path' => $data['pdf_path'],
            'pdf_hash' => $data['pdf_hash'],
        ];
    } catch (Throwable $e) {
        error_log('Pagare PDF exception: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}


// ── CINCEL NOM-151 Timestamp (for PDF hash) ─────────────────────────────

function cincelTimestampPDF(string $pdfHash, int $motoId): array {
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

    // Step 2: Create timestamp with PDF hash
    $ch = curl_init($apiUrl . '/timestamps');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'hash'        => $pdfHash,
            'algorithm'   => 'SHA-256',
            'description' => 'Pagaré PDF firmado — Moto ID ' . $motoId,
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

    $cincelId = $tsResp['id'] ?? $tsResp['timestamp_id'] ?? $tsResp['data']['id'] ?? $pdfHash;
    return ['ok' => true, 'cincel_id' => (string)$cincelId];
}
