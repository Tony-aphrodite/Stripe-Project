<?php
/**
 * Voltika — Generador de certificados para Círculo de Crédito
 * Ejecutar UNA SOLA VEZ para generar la llave privada y el certificado.
 *
 * Acceso: ?key=voltika_cdc_cert_2026
 *
 * Genera:
 *   - cdc_private.key  (llave privada RSA 2048)
 *   - cdc_certificate.pem (certificado autofirmado, válido 365 días)
 *
 * Después de generar:
 *   1. Descargar ambos archivos
 *   2. Subir cdc_certificate.pem al API Hub de Círculo de Crédito
 *   3. Descargar el certificado de Círculo de Crédito desde el portal
 *   4. Guardar todos los archivos en /configurador/php/certs/
 */

$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika_cdc_cert_2026') {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: text/html; charset=UTF-8');

$certsDir = __DIR__ . '/certs';
if (!is_dir($certsDir)) {
    mkdir($certsDir, 0700, true);
}

$privateKeyFile  = $certsDir . '/cdc_private.key';
$certificateFile = $certsDir . '/cdc_certificate.pem';

// Check if already generated
if (file_exists($privateKeyFile) && file_exists($certificateFile)) {
    echo '<h2>⚠️ Certificados ya existen</h2>';
    echo '<p>Los archivos ya fueron generados anteriormente:</p>';
    echo '<ul>';
    echo '<li><strong>Llave privada:</strong> ' . $privateKeyFile . ' (' . filesize($privateKeyFile) . ' bytes)</li>';
    echo '<li><strong>Certificado:</strong> ' . $certificateFile . ' (' . filesize($certificateFile) . ' bytes)</li>';
    echo '</ul>';
    echo '<p>Si necesitas regenerar, elimina los archivos existentes primero.</p>';
    echo '<hr>';
    echo '<h3>Descargar</h3>';
    echo '<p><a href="certs/cdc_certificate.pem" download>📥 Descargar certificado (.pem)</a></p>';
    echo '<p style="color:#C62828;">⚠️ La llave privada NO debe descargarse ni compartirse. Se queda en el servidor.</p>';
    exit;
}

// ── 1. Generate RSA 2048 private key ─────────────────────────────────────────
$keyConfig = [
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
];

$privateKey = openssl_pkey_new($keyConfig);

if (!$privateKey) {
    echo '<h2 style="color:red;">❌ Error generando llave privada</h2>';
    echo '<pre>' . openssl_error_string() . '</pre>';
    exit;
}

// Export private key to file
openssl_pkey_export_to_file($privateKey, $privateKeyFile);
chmod($privateKeyFile, 0600); // Read only by owner

// ── 2. Generate self-signed certificate (CSR + sign) ─────────────────────────
$dn = [
    'countryName'            => 'MX',
    'stateOrProvinceName'    => 'Ciudad de Mexico',
    'localityName'           => 'CDMX',
    'organizationName'       => 'Voltika MX',
    'organizationalUnitName' => 'Tecnologia',
    'commonName'             => 'voltika.mx',
    'emailAddress'           => 'ivan.clavel@voltika.mx',
];

// Create CSR
$csr = openssl_csr_new($dn, $privateKey, ['digest_alg' => 'sha256']);

if (!$csr) {
    echo '<h2 style="color:red;">❌ Error generando CSR</h2>';
    echo '<pre>' . openssl_error_string() . '</pre>';
    exit;
}

// Self-sign for 365 days
$certificate = openssl_csr_sign($csr, null, $privateKey, 365, ['digest_alg' => 'sha256']);

if (!$certificate) {
    echo '<h2 style="color:red;">❌ Error firmando certificado</h2>';
    echo '<pre>' . openssl_error_string() . '</pre>';
    exit;
}

// Export certificate to file
openssl_x509_export_to_file($certificate, $certificateFile);

// ── 3. Show results ──────────────────────────────────────────────────────────
echo '<h1 style="color:#10b981;">✅ Certificados generados exitosamente</h1>';

echo '<h3>Archivos creados:</h3>';
echo '<table border="1" cellpadding="8" style="border-collapse:collapse;">';
echo '<tr><th>Archivo</th><th>Ubicación</th><th>Tamaño</th></tr>';
echo '<tr><td>🔑 Llave privada</td><td>' . $privateKeyFile . '</td><td>' . filesize($privateKeyFile) . ' bytes</td></tr>';
echo '<tr><td>📜 Certificado</td><td>' . $certificateFile . '</td><td>' . filesize($certificateFile) . ' bytes</td></tr>';
echo '</table>';

echo '<hr>';
echo '<h3>📋 Siguientes pasos:</h3>';
echo '<ol>';
echo '<li><strong>Descargar el certificado:</strong> <a href="certs/cdc_certificate.pem" download>📥 Descargar cdc_certificate.pem</a></li>';
echo '<li><strong>Ir al portal de Círculo de Crédito:</strong> <a href="https://developer.circulodecredito.com.mx" target="_blank">developer.circulodecredito.com.mx</a></li>';
echo '<li><strong>Iniciar sesión</strong> con las credenciales de Voltika</li>';
echo '<li><strong>Subir el certificado</strong> (cdc_certificate.pem) en la sección de certificados del API Hub</li>';
echo '<li><strong>Descargar el certificado de Círculo de Crédito</strong> desde el portal y guardarlo en el servidor</li>';
echo '<li><strong>Ejecutar la prueba de seguridad:</strong> <a href="https://developer.circulodecredito.com.mx/prueba_de_seguridad" target="_blank">prueba_de_seguridad</a></li>';
echo '<li><strong>Solicitar pase a producción:</strong> <a href="https://developer.circulodecredito.com.mx/pase_a_produccion" target="_blank">pase_a_produccion</a></li>';
echo '</ol>';

echo '<hr>';
echo '<p style="color:#C62828;font-weight:bold;">⚠️ IMPORTANTE: La llave privada (cdc_private.key) NUNCA debe compartirse ni descargarse. Se queda en el servidor.</p>';
echo '<p style="color:#C62828;">⚠️ Eliminar este script (generar-certificado-cdc.php) después de completar el proceso.</p>';

// Show certificate details
echo '<hr>';
echo '<h3>Detalles del certificado:</h3>';
echo '<pre>';
$certData = openssl_x509_parse($certificate);
echo 'Subject: ' . json_encode($certData['subject'], JSON_PRETTY_PRINT) . "\n";
echo 'Valid from: ' . date('Y-m-d', $certData['validFrom_time_t']) . "\n";
echo 'Valid to: ' . date('Y-m-d', $certData['validTo_time_t']) . "\n";
echo 'Serial: ' . $certData['serialNumber'] . "\n";
echo '</pre>';
