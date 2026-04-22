<?php
/**
 * CDC Preflight — runs the full consultar-buro.php codepath end-to-end with
 * a synthetic customer body, so the admin can verify the fix works WITHOUT
 * the real customer having to retest.
 *
 * Access: ?key=voltika_cdc_2026
 *
 * Shows: body sent (post-normalization), signature presence, HTTP code,
 * raw CDC response body. All of this also lands in
 * php/logs/circulo-credito.log.
 */

$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika_cdc_2026') { http_response_code(403); exit('Forbidden'); }

header('Content-Type: text/html; charset=UTF-8');
echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>CDC Preflight</title>';
echo '<style>body{font-family:Arial,sans-serif;max-width:900px;margin:20px auto;padding:0 20px;color:#333}';
echo 'pre{background:#1a1a1a;color:#0f0;padding:12px;border-radius:6px;overflow-x:auto;font-size:12px;white-space:pre-wrap;word-break:break-all}';
echo '.ok{color:#10b981;font-weight:700}.err{color:#C62828;font-weight:700}.step{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:14px;margin:10px 0}</style></head><body>';
echo '<h1>🔐 CDC Preflight — consultar-buro end-to-end</h1>';

// Simulate what the frontend sends to consultar-buro.php for a real customer
$simulated = [
    'primerNombre'    => 'JUAN',
    'apellidoPaterno' => 'PEREZ',
    'apellidoMaterno' => 'LOPEZ',
    'fechaNacimiento' => '1985-03-15',
    'CP'              => '03100',
    'ciudad'          => 'CIUDAD DE MEXICO',
    'estado'          => 'Ciudad de México',          // tests estado normalizer
    'direccion'       => 'AV REFORMA 100',
    'colonia'         => 'JUAREZ',
    'municipio'       => 'CUAUHTEMOC',
    'tipo_consulta'   => 'PF',
    'ingreso_nip_ciec' => 'SI',
    'respuesta_leyenda'=> 'SI',
    'aceptacion_tyc'   => 'SI',
    // RFC deliberately omitted — tests the RFC auto-compute
];

echo '<div class="step"><div>Simulando llamada del frontend con body:</div>';
echo '<pre>' . htmlspecialchars(json_encode($simulated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre></div>';

// Call consultar-buro.php internally via curl (http loopback, so session is
// isolated the same way as a real customer call).
$selfUrl = 'http' . (!empty($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/consultar-buro.php';

$ch = curl_init($selfUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($simulated),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

echo '<div class="step"><div><strong>Respuesta de consultar-buro.php:</strong> HTTP ' . $code . ($err ? ' — ' . htmlspecialchars($err) : '') . '</div>';
echo '<pre>' . htmlspecialchars($resp ?: '(vacío)') . '</pre></div>';

// Show last log entry so we can see body sent to CDC + CDC's response
$logFile = __DIR__ . '/logs/circulo-credito.log';
if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $last  = end($lines);
    echo '<div class="step"><div><strong>Última entrada del log:</strong></div>';
    $pretty = json_decode($last, true);
    echo '<pre>' . htmlspecialchars($pretty ? json_encode($pretty, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $last) . '</pre></div>';

    // Interpretation hints
    if ($pretty) {
        echo '<div class="step"><div><strong>Diagnóstico:</strong></div><ul>';
        if (empty($pretty['has_sig'])) {
            echo '<li class="err">❌ Sin firma x-signature — la llave privada no está en el servidor. Ejecuta generar-certificado-cdc.php primero.</li>';
        } else {
            echo '<li class="ok">✅ Firma generada (' . $pretty['sig_len'] . ' chars hex)</li>';
        }
        if ($pretty['httpCode'] >= 200 && $pretty['httpCode'] < 300) {
            echo '<li class="ok">✅ CDC respondió 2xx — integración funcionando</li>';
        } elseif ($pretty['httpCode'] == 401 || $pretty['httpCode'] == 403) {
            echo '<li class="err">❌ HTTP ' . $pretty['httpCode'] . ' — CDC rechazó la autenticación. Verifica CDC_API_KEY / CDC_USER / CDC_PASS en .env y que el certificado público esté subido al portal de CDC.</li>';
        } elseif ($pretty['httpCode'] == 400) {
            echo '<li class="err">❌ HTTP 400 — CDC rechazó los datos. Revisa el body_sent y el response arriba para ver qué campo es inválido.</li>';
        } elseif ($pretty['httpCode'] == 503) {
            echo '<li class="err">❌ HTTP 503 — CDC service unavailable. Posibles causas: producto no activado para tu folio, mTLS requerido, o gateway Apigee caído.</li>';
        } else {
            echo '<li class="err">❌ HTTP ' . $pretty['httpCode'] . ' — ver response body arriba.</li>';
        }
        echo '</ul></div>';
    }
} else {
    echo '<div class="step err">Log file no existe: ' . htmlspecialchars($logFile) . '</div>';
}

// Cert/key presence
echo '<div class="step"><div><strong>Certificados en disco:</strong></div><ul>';
$certFile = __DIR__ . '/certs/cdc_certificate.pem';
$keyFile  = __DIR__ . '/certs/cdc_private.key';
echo '<li>' . ($keyFile  ? (file_exists($keyFile)  ? '<span class="ok">✅ cdc_private.key    (' . filesize($keyFile)  . ' bytes)</span>' : '<span class="err">❌ cdc_private.key    MISSING</span>') : '') . '</li>';
echo '<li>' . ($certFile ? (file_exists($certFile) ? '<span class="ok">✅ cdc_certificate.pem (' . filesize($certFile) . ' bytes)</span>' : '<span class="err">❌ cdc_certificate.pem MISSING</span>') : '') . '</li>';
echo '</ul></div>';

echo '</body></html>';
