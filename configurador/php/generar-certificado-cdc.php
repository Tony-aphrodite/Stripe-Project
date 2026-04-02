<?php
/**
 * Voltika — Generador de certificados para Círculo de Crédito
 * Genera llave privada + certificado en memoria y los muestra directamente.
 * No depende de escribir archivos en disco (evita problemas de permisos).
 *
 * Acceso: ?key=voltika_cdc_cert_2026
 * Descarga: ?key=voltika_cdc_cert_2026&download=cert
 *           ?key=voltika_cdc_cert_2026&download=key
 */

$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika_cdc_cert_2026') {
    http_response_code(403);
    exit('Forbidden');
}

session_start();

// ── DN for certificate ───────────────────────────────────────────────────────
$dn = [
    'countryName'            => 'MX',
    'stateOrProvinceName'    => 'Ciudad de Mexico',
    'localityName'           => 'CDMX',
    'organizationName'       => 'Voltika MX',
    'organizationalUnitName' => 'Tecnologia',
    'commonName'             => 'voltika.mx',
    'emailAddress'           => 'ivan.clavel@voltika.mx',
];

// ── Generate or retrieve from session ────────────────────────────────────────
if (!empty($_SESSION['cdc_cert_pem']) && !empty($_SESSION['cdc_key_pem'])) {
    $certPem = $_SESSION['cdc_cert_pem'];
    $keyPem  = $_SESSION['cdc_key_pem'];
} else {
    // Generate RSA 2048 private key
    $privateKey = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    if (!$privateKey) {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<h2 style="color:red;">Error generando llave privada</h2>';
        echo '<pre>' . openssl_error_string() . '</pre>';
        exit;
    }

    // Export private key to string
    openssl_pkey_export($privateKey, $keyPem);

    // Generate CSR + self-sign for 365 days
    $csr = openssl_csr_new($dn, $privateKey, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $privateKey, 365, ['digest_alg' => 'sha256']);
    openssl_x509_export($cert, $certPem);

    // Store in session so we can download later
    $_SESSION['cdc_cert_pem'] = $certPem;
    $_SESSION['cdc_key_pem']  = $keyPem;

    // Also try to save to disk (may fail due to permissions — that's OK)
    $certsDir = __DIR__ . '/certs';
    @mkdir($certsDir, 0700, true);
    @file_put_contents($certsDir . '/cdc_private.key', $keyPem);
    @file_put_contents($certsDir . '/cdc_certificate.pem', $certPem);
}

// ── Download mode ────────────────────────────────────────────────────────────
$download = $_GET['download'] ?? '';
if ($download === 'cert' && $certPem) {
    header('Content-Type: application/x-pem-file');
    header('Content-Disposition: attachment; filename="cdc_certificate.pem"');
    header('Content-Length: ' . strlen($certPem));
    echo $certPem;
    exit;
}
if ($download === 'key' && $keyPem) {
    header('Content-Type: application/x-pem-file');
    header('Content-Disposition: attachment; filename="cdc_private.key"');
    header('Content-Length: ' . strlen($keyPem));
    echo $keyPem;
    exit;
}

// ── Display page ─────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=UTF-8');

echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Voltika — Certificado CDC</title>';
echo '<style>body{font-family:Arial,sans-serif;max-width:800px;margin:20px auto;padding:0 20px;color:#333;}';
echo 'h1{color:#10b981;} textarea{width:100%;font-family:monospace;font-size:11px;background:#f5f5f5;border:1px solid #ddd;border-radius:6px;padding:10px;}';
echo '.btn{display:inline-block;padding:12px 24px;background:#039fe1;color:#fff;text-decoration:none;border-radius:8px;font-weight:700;font-size:14px;margin:5px 0;}';
echo '.btn:hover{background:#027db0;} .warn{color:#C62828;font-weight:700;}</style></head><body>';

echo '<h1>✅ Certificados generados exitosamente</h1>';

// Certificate details
$certData = openssl_x509_parse($certPem);
echo '<table border="1" cellpadding="8" style="border-collapse:collapse;margin-bottom:20px;">';
echo '<tr><th>Campo</th><th>Valor</th></tr>';
echo '<tr><td>Organización</td><td>' . ($certData['subject']['O'] ?? '') . '</td></tr>';
echo '<tr><td>Common Name</td><td>' . ($certData['subject']['CN'] ?? '') . '</td></tr>';
echo '<tr><td>Email</td><td>' . ($certData['subject']['emailAddress'] ?? '') . '</td></tr>';
echo '<tr><td>País</td><td>' . ($certData['subject']['C'] ?? '') . '</td></tr>';
echo '<tr><td>Válido desde</td><td>' . date('Y-m-d', $certData['validFrom_time_t']) . '</td></tr>';
echo '<tr><td>Válido hasta</td><td>' . date('Y-m-d', $certData['validTo_time_t']) . '</td></tr>';
echo '</table>';

// Download buttons
echo '<h3>Paso 1: Descargar el certificado</h3>';
echo '<p><a class="btn" href="generar-certificado-cdc.php?key=voltika_cdc_cert_2026&download=cert">📥 Descargar cdc_certificate.pem</a></p>';

// Copy/paste fallback
echo '<p style="margin-top:16px;font-size:13px;color:#666;">Si el botón no funciona, copia <strong>TODO</strong> el contenido del cuadro y guárdalo en un archivo llamado <code>cdc_certificate.pem</code>:</p>';
echo '<textarea rows="12" readonly onclick="this.select();document.execCommand(\'copy\');alert(\'Copiado!\');">' . htmlspecialchars($certPem) . '</textarea>';

// Next steps
echo '<hr style="margin:24px 0;">';
echo '<h3>📋 Siguientes pasos</h3>';
echo '<ol style="line-height:2.2;">';
echo '<li><strong>Ir al portal:</strong> <a href="https://developer.circulodecredito.com.mx" target="_blank">developer.circulodecredito.com.mx</a></li>';
echo '<li><strong>Iniciar sesión</strong> con credenciales de Voltika</li>';
echo '<li><strong>Subir el certificado</strong> (<code>cdc_certificate.pem</code>) en la sección de certificados del API Hub</li>';
echo '<li><strong>Descargar el certificado de Círculo de Crédito</strong> desde el portal</li>';
echo '<li><strong>Prueba de seguridad:</strong> <a href="https://developer.circulodecredito.com.mx/prueba_de_seguridad" target="_blank">Ejecutar prueba</a></li>';
echo '<li><strong>Pase a producción:</strong> <a href="https://developer.circulodecredito.com.mx/pase_a_produccion" target="_blank">Solicitar</a></li>';
echo '</ol>';

echo '<hr style="margin:24px 0;">';
echo '<p class="warn">⚠️ IMPORTANTE: Eliminar este script después de completar el proceso.</p>';

echo '</body></html>';
