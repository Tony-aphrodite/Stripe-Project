<?php
/**
 * Voltika — Círculo de Crédito Security Test (Prueba de Seguridad)
 *
 * Automatically runs the CDC security test:
 * 1. Signs a test message with our private key
 * 2. Sends POST to /v1/securitytest
 * 3. Verifies the response signature with CDC's certificate
 *
 * Access: ?key=voltika_cdc_cert_2026
 */

$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika_cdc_cert_2026') {
    http_response_code(403);
    exit('Forbidden');
}

session_start();
require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=UTF-8');

echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Voltika — CDC Security Test</title>';
echo '<style>body{font-family:Arial,sans-serif;max-width:800px;margin:20px auto;padding:0 20px;color:#333;}';
echo '.ok{color:#10b981;font-weight:700;} .err{color:#C62828;font-weight:700;}';
echo '.step{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:14px;margin:10px 0;}';
echo '.step-title{font-weight:700;margin-bottom:6px;} pre{background:#1a1a1a;color:#0f0;padding:12px;border-radius:6px;overflow-x:auto;font-size:12px;}';
echo '.btn{display:inline-block;padding:10px 20px;background:#039fe1;color:#fff;text-decoration:none;border-radius:8px;font-weight:700;margin:5px 0;}';
echo '</style></head><body>';

echo '<h1>🔐 Prueba de Seguridad — Círculo de Crédito</h1>';

// ── Step 1: Load our private key + cert ──────────────────────────────────────
// Resolution order matches consultar-buro.php: session → DB → disk.
// Disk on Plesk is often non-writable, so DB is the canonical source after
// the active=1 row in cdc_certificates is the source of truth.
echo '<div class="step"><div class="step-title">Paso 1: Cargar llave privada</div>';

$keySource  = 'none';
$dbRowCount = 0;
$keyPem  = $_SESSION['cdc_key_pem']  ?? null;
$certPem = $_SESSION['cdc_cert_pem'] ?? null;
if ($keyPem) $keySource = 'session';

try {
    $dbRowCount = (int) getDB()->query("SELECT COUNT(*) FROM cdc_certificates WHERE active = 1")->fetchColumn();
} catch (Throwable $e) {}

if (!$keyPem || !$certPem) {
    try {
        $row = getDB()->query("SELECT private_key, certificate FROM cdc_certificates WHERE active = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if (!$keyPem)  { $keyPem  = $row['private_key']; $keySource = 'db'; }
            if (!$certPem) { $certPem = $row['certificate']; }
        }
    } catch (Throwable $e) {}
}

if (!$keyPem) {
    $keyFile = __DIR__ . '/certs/cdc_private.key';
    if (file_exists($keyFile)) { $keyPem = file_get_contents($keyFile); $keySource = 'disk'; }
}

if (!$keyPem) {
    echo '<span class="err">❌ No se encontró la llave privada. <a href="generar-certificado-cdc.php?key=voltika_cdc_cert_2026">Generar primero</a></span>';
    echo '</div></body></html>';
    exit;
}

$privateKey = openssl_pkey_get_private($keyPem);
if (!$privateKey) {
    echo '<span class="err">❌ Error cargando llave privada: ' . openssl_error_string() . '</span>';
    echo '</div></body></html>';
    exit;
}

$keyDetails = openssl_pkey_get_details($privateKey);
$keyType = $keyDetails['type'] === OPENSSL_KEYTYPE_EC ? 'ECDSA' : 'RSA';
$certFingerprint = $certPem ? (@openssl_x509_fingerprint($certPem, 'sha256') ?: '') : '';
echo '<span class="ok">✅ Llave privada cargada (' . $keyType . ')</span>';
echo '<div style="font-size:12px;color:#666;margin-top:4px;">';
echo 'Fuente: <code>' . $keySource . '</code> &nbsp;|&nbsp; DB active=1 rows: <code>' . $dbRowCount . '</code><br>';
echo 'Cert fingerprint SHA-256: <code>' . htmlspecialchars($certFingerprint) . '</code>';
echo '</div></div>';

// ── Step 2: Sign request body ────────────────────────────────────────────────
// Same convention as consultar-buro.php: sign the exact JSON body bytes with
// OPENSSL_ALGO_SHA256, then hex-encode for the x-signature header.
echo '<div class="step"><div class="step-title">Paso 2: Firmar cuerpo de petición</div>';

$testMessage = 'Esto es un mensaje de prueba';
$requestBody = json_encode(['Peticion' => $testMessage]);

$signature = '';
if (!openssl_sign($requestBody, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
    echo '<span class="err">❌ Error firmando: ' . openssl_error_string() . '</span>';
    echo '</div></body></html>';
    exit;
}
$signatureHex = bin2hex($signature);
echo '<span class="ok">✅ Body firmado correctamente</span>';
echo '<div style="font-size:12px;color:#666;margin-top:6px;">';
echo 'Body: <code>' . htmlspecialchars($requestBody) . '</code><br>';
echo 'Firma (HEX, primeros 80): <code style="word-break:break-all;">' . htmlspecialchars(substr($signatureHex, 0, 80)) . '...</code>';
echo '</div></div>';

// ── Step 3: Send to CDC SecurityTest ─────────────────────────────────────────
echo '<div class="step"><div class="step-title">Paso 3: Enviar a Círculo de Crédito</div>';

$apiUrl = 'https://services.circulodecredito.com.mx/v1/securitytest';
$apiKey = defined('CDC_API_KEY') ? CDC_API_KEY : '';

// cert was already loaded in Paso 1 (session → DB → disk). Only fall back to
// disk now if somehow still empty.
if (!$certPem) {
    $certFile = __DIR__ . '/certs/cdc_certificate.pem';
    if (file_exists($certFile)) $certPem = file_get_contents($certFile);
}

$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'x-api-key: ' . $apiKey,
    'x-signature: ' . $signatureHex,
];
if (defined('CDC_USER') && CDC_USER) $headers[] = 'username: ' . CDC_USER;
if (defined('CDC_PASS') && CDC_PASS) $headers[] = 'password: ' . CDC_PASS;

$curlOpts = [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $requestBody,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
];
$tmpCert = $tmpKey = null;
if ($certPem && $keyPem) {
    $tmpCert = tempnam(sys_get_temp_dir(), 'cdc_cert_');
    $tmpKey  = tempnam(sys_get_temp_dir(), 'cdc_key_');
    file_put_contents($tmpCert, $certPem);
    file_put_contents($tmpKey, $keyPem);
    $curlOpts[CURLOPT_SSLCERT] = $tmpCert;
    $curlOpts[CURLOPT_SSLKEY]  = $tmpKey;
}

$ch = curl_init($apiUrl);
curl_setopt_array($ch, $curlOpts);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);
if ($tmpCert) @unlink($tmpCert);
if ($tmpKey)  @unlink($tmpKey);

echo '<div style="font-size:12px;color:#666;margin-bottom:8px;">';
echo 'URL: <code>' . $apiUrl . '</code><br>';
echo 'HTTP Code: <strong>' . $httpCode . '</strong>';
echo '</div>';

if ($curlErr) {
    echo '<span class="err">❌ Error de conexión: ' . htmlspecialchars($curlErr) . '</span>';
    echo '<div style="margin-top:8px;font-size:12px;color:#666;">Esto puede significar que el certificado aún no ha sido subido al API Hub de Círculo de Crédito, o que la API key no tiene acceso al SecurityTest.</div>';
} elseif ($httpCode >= 200 && $httpCode < 300) {
    echo '<span class="ok">✅ Respuesta recibida exitosamente</span>';
} elseif ($httpCode === 401 || $httpCode === 403) {
    echo '<span class="err">❌ Acceso denegado (HTTP ' . $httpCode . '). Verificar que el certificado fue subido al API Hub y la API key tiene acceso al SecurityTest.</span>';
} else {
    echo '<span class="err">❌ Error HTTP ' . $httpCode . '</span>';
}

echo '<pre>' . htmlspecialchars($response ?: '(sin respuesta)') . '</pre>';
echo '</div>';

// ── Step 4: Verify response signature ────────────────────────────────────────
if ($response && $httpCode >= 200 && $httpCode < 300) {
    echo '<div class="step"><div class="step-title">Paso 4: Verificar firma de respuesta</div>';

    $responseData = json_decode($response, true);

    if ($responseData) {
        echo '<span class="ok">✅ Respuesta JSON válida</span>';

        // Check if response contains signature
        $respSignature = $responseData['x-signature'] ?? $responseData['signature'] ?? null;
        $respMessage   = $responseData['Peticion'] ?? $responseData['mensaje'] ?? $responseData['message'] ?? null;

        if ($respMessage) {
            echo '<div style="font-size:12px;color:#666;margin-top:6px;">Mensaje de respuesta: <code>' . htmlspecialchars($respMessage) . '</code></div>';
        }

        if ($respSignature) {
            echo '<div style="font-size:12px;color:#666;margin-top:4px;">Firma de CDC recibida</div>';

            // If we have CDC's certificate, verify their signature
            $cdcCertFile = __DIR__ . '/certs/cdc_server_certificate.pem';
            if (file_exists($cdcCertFile)) {
                $cdcCert = file_get_contents($cdcCertFile);
                $cdcPubKey = openssl_pkey_get_public($cdcCert);
                $verifyResult = openssl_verify($respMessage, base64_decode($respSignature), $cdcPubKey, OPENSSL_ALGO_SHA256);

                if ($verifyResult === 1) {
                    echo '<span class="ok">✅ Firma de CDC verificada correctamente</span>';
                } elseif ($verifyResult === 0) {
                    echo '<span class="err">❌ La firma de CDC no coincide</span>';
                } else {
                    echo '<span class="err">❌ Error verificando firma: ' . openssl_error_string() . '</span>';
                }
            } else {
                echo '<div style="font-size:12px;color:#f59e0b;margin-top:4px;">⚠️ No se encontró el certificado de CDC para verificar. Descargar del portal y guardar como <code>php/certs/cdc_server_certificate.pem</code></div>';
            }
        }

        echo '<h3 style="color:#10b981;margin-top:16px;">🎉 Prueba de seguridad completada</h3>';
    } else {
        echo '<span class="err">❌ La respuesta no es JSON válido</span>';
    }
    echo '</div>';
}

// Summary
echo '<hr style="margin:24px 0;">';
echo '<h3>Resumen</h3>';
echo '<table border="1" cellpadding="8" style="border-collapse:collapse;">';
echo '<tr><td>Llave privada</td><td class="ok">✅ OK</td></tr>';
echo '<tr><td>Firma del mensaje</td><td class="ok">✅ OK</td></tr>';
echo '<tr><td>Envío a CDC</td><td class="' . ($httpCode >= 200 && $httpCode < 300 ? 'ok">✅ OK' : 'err">❌ Error') . '</td></tr>';
echo '</table>';

echo '<hr style="margin:24px 0;">';
echo '<p><a class="btn" href="generar-certificado-cdc.php?key=voltika_cdc_cert_2026">← Volver a certificados</a></p>';
echo '<p style="color:#C62828;font-size:12px;font-weight:700;">⚠️ Eliminar este script después de completar el proceso.</p>';

echo '</body></html>';
