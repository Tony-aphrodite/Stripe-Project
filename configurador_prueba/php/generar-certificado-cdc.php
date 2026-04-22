<?php
/**
 * Voltika — Generador de certificados para Círculo de Crédito
 * Genera llave privada ECDSA secp384r1 + certificado (según especificación CDC).
 *
 * Acceso: ?key=voltika_cdc_cert_2026
 * Descarga cert: ?key=voltika_cdc_cert_2026&download=cert
 * Descarga key:  ?key=voltika_cdc_cert_2026&download=key
 * Regenerar:     ?key=voltika_cdc_cert_2026&regen=1
 */

$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika_cdc_cert_2026') {
    http_response_code(403);
    exit('Forbidden');
}

session_start();

$dn = [
    'countryName'            => 'MX',
    'stateOrProvinceName'    => 'Ciudad de Mexico',
    'localityName'           => 'CDMX',
    'organizationName'       => 'Voltika MX',
    'organizationalUnitName' => 'Tecnologia',
    'commonName'             => 'voltika.mx',
    'emailAddress'           => 'ivan.clavel@voltika.mx',
];

// Force regeneration if requested
if (!empty($_GET['regen'])) {
    unset($_SESSION['cdc_cert_pem'], $_SESSION['cdc_key_pem']);
}

// Generate or retrieve from session
if (!empty($_SESSION['cdc_cert_pem']) && !empty($_SESSION['cdc_key_pem'])) {
    $certPem = $_SESSION['cdc_cert_pem'];
    $keyPem  = $_SESSION['cdc_key_pem'];
} else {
    // ECDSA secp384r1 as required by Círculo de Crédito
    $privateKey = openssl_pkey_new([
        'curve_name'       => 'secp384r1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);

    if (!$privateKey) {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<h2 style="color:red;">Error generando llave privada</h2>';
        echo '<pre>' . openssl_error_string() . '</pre>';
        exit;
    }

    openssl_pkey_export($privateKey, $keyPem);

    $csr = openssl_csr_new($dn, $privateKey, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $privateKey, 365, ['digest_alg' => 'sha256']);
    openssl_x509_export($cert, $certPem);

    $_SESSION['cdc_cert_pem'] = $certPem;
    $_SESSION['cdc_key_pem']  = $keyPem;

    // Persist to disk — this is REQUIRED for customer credit flows because
    // customer sessions don't have access to the admin's $_SESSION. If the
    // write fails here, every subsequent consultar-buro.php call will 503
    // because it can't sign the request.
    $certsDir = __DIR__ . '/certs';
    $GLOBALS['cdc_disk_status'] = ['dir_exists'=>false,'dir_writable'=>false,'key_saved'=>false,'cert_saved'=>false,'errors'=>[]];

    if (!is_dir($certsDir)) {
        if (!@mkdir($certsDir, 0700, true)) {
            $GLOBALS['cdc_disk_status']['errors'][] = 'mkdir falló: ' . (error_get_last()['message'] ?? 'unknown');
        }
    }
    $GLOBALS['cdc_disk_status']['dir_exists']   = is_dir($certsDir);
    $GLOBALS['cdc_disk_status']['dir_writable'] = is_writable($certsDir);

    if ($GLOBALS['cdc_disk_status']['dir_writable']) {
        $keyFile  = $certsDir . '/cdc_private.key';
        $certFile = $certsDir . '/cdc_certificate.pem';
        $keyOk  = @file_put_contents($keyFile,  $keyPem);
        $certOk = @file_put_contents($certFile, $certPem);
        $GLOBALS['cdc_disk_status']['key_saved']  = ($keyOk  !== false);
        $GLOBALS['cdc_disk_status']['cert_saved'] = ($certOk !== false);
        if ($keyOk  !== false) @chmod($keyFile,  0600);
        if ($certOk !== false) @chmod($certFile, 0644);
        if ($keyOk  === false) $GLOBALS['cdc_disk_status']['errors'][] = 'Escritura de cdc_private.key falló: '  . (error_get_last()['message'] ?? 'unknown');
        if ($certOk === false) $GLOBALS['cdc_disk_status']['errors'][] = 'Escritura de cdc_certificate.pem falló: ' . (error_get_last()['message'] ?? 'unknown');
    } else {
        $GLOBALS['cdc_disk_status']['errors'][] = 'Directorio no escribible: ' . $certsDir;
    }
}

// Download mode
$download = $_GET['download'] ?? '';
if ($download === 'cert' && $certPem) {
    header('Content-Type: application/x-pem-file');
    header('Content-Disposition: attachment; filename="cdc_certificate.pem"');
    echo $certPem;
    exit;
}
if ($download === 'key' && $keyPem) {
    header('Content-Type: application/x-pem-file');
    header('Content-Disposition: attachment; filename="cdc_private.key"');
    echo $keyPem;
    exit;
}

// Display page
header('Content-Type: text/html; charset=UTF-8');
echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Voltika — Certificado CDC</title>';
echo '<style>body{font-family:Arial,sans-serif;max-width:800px;margin:20px auto;padding:0 20px;color:#333;}';
echo 'h1{color:#10b981;} textarea{width:100%;font-family:monospace;font-size:11px;background:#f5f5f5;border:1px solid #ddd;border-radius:6px;padding:10px;}';
echo '.btn{display:inline-block;padding:12px 24px;background:#039fe1;color:#fff;text-decoration:none;border-radius:8px;font-weight:700;font-size:14px;margin:5px 0;}';
echo '.btn:hover{background:#027db0;} .warn{color:#C62828;font-weight:700;}</style></head><body>';

echo '<h1>✅ Certificado ECDSA secp384r1 generado</h1>';

// Disk-persistence status — critical for customer credit flows
$ds = $GLOBALS['cdc_disk_status'] ?? null;
if ($ds) {
    $allOk = $ds['key_saved'] && $ds['cert_saved'];
    $bg = $allOk ? '#d1fae5' : '#fee2e2';
    $col = $allOk ? '#065f46' : '#991b1b';
    echo '<div style="background:'.$bg.';color:'.$col.';padding:14px;border-radius:8px;margin-bottom:16px;">';
    echo '<strong>Persistencia en disco:</strong><br>';
    echo ($ds['dir_exists']   ? '✅' : '❌') . ' Directorio certs/ existe<br>';
    echo ($ds['dir_writable'] ? '✅' : '❌') . ' Directorio escribible<br>';
    echo ($ds['key_saved']    ? '✅' : '❌') . ' cdc_private.key guardado<br>';
    echo ($ds['cert_saved']   ? '✅' : '❌') . ' cdc_certificate.pem guardado<br>';
    if (!empty($ds['errors'])) {
        echo '<strong>Errores:</strong><ul>';
        foreach ($ds['errors'] as $e) echo '<li>' . htmlspecialchars($e) . '</li>';
        echo '</ul>';
        echo '<div style="margin-top:8px;font-size:13px;">Solución: por SSH ejecutar <code>chown www-data:www-data ' . __DIR__ . '/certs && chmod 700 ' . __DIR__ . '/certs</code></div>';
    }
    echo '</div>';
} else {
    echo '<div style="background:#fef3c7;color:#78350f;padding:10px;border-radius:6px;margin-bottom:16px;">Llaves recuperadas de sesión (ya generadas antes). Para forzar regeneración y re-escritura a disco: <a href="?key=voltika_cdc_cert_2026&regen=1">Regenerar</a></div>';
}

$certData = openssl_x509_parse($certPem);
echo '<table border="1" cellpadding="8" style="border-collapse:collapse;margin-bottom:20px;">';
echo '<tr><th>Campo</th><th>Valor</th></tr>';
echo '<tr><td>Tipo de llave</td><td><strong>ECDSA secp384r1</strong> (requerido por CDC)</td></tr>';
echo '<tr><td>Organización</td><td>' . ($certData['subject']['O'] ?? '') . '</td></tr>';
echo '<tr><td>Common Name</td><td>' . ($certData['subject']['CN'] ?? '') . '</td></tr>';
echo '<tr><td>Email</td><td>' . ($certData['subject']['emailAddress'] ?? '') . '</td></tr>';
echo '<tr><td>Válido desde</td><td>' . date('Y-m-d', $certData['validFrom_time_t']) . '</td></tr>';
echo '<tr><td>Válido hasta</td><td>' . date('Y-m-d', $certData['validTo_time_t']) . '</td></tr>';
echo '</table>';

// Download
echo '<h3>Paso 1: Descargar certificado</h3>';
echo '<p><a class="btn" href="generar-certificado-cdc.php?key=voltika_cdc_cert_2026&download=cert">📥 Descargar cdc_certificate.pem</a></p>';
echo '<p style="margin-top:12px;font-size:13px;color:#666;">Si no funciona, copia TODO el contenido:</p>';
echo '<textarea rows="10" readonly onclick="this.select();document.execCommand(\'copy\');alert(\'Copiado!\');">' . htmlspecialchars($certPem) . '</textarea>';

// Steps
echo '<hr style="margin:24px 0;">';
echo '<h3>📋 Siguientes pasos</h3>';
echo '<ol style="line-height:2.2;">';
echo '<li>Ir al portal: <a href="https://developer.circulodecredito.com.mx" target="_blank">developer.circulodecredito.com.mx</a></li>';
echo '<li>Iniciar sesión con credenciales de Voltika</li>';
echo '<li>Subir el certificado (<code>cdc_certificate.pem</code>) en el API Hub</li>';
echo '<li>Descargar el certificado de Círculo de Crédito y enviármelo</li>';
echo '<li><strong>Prueba de seguridad:</strong> <a class="btn" href="cdc-security-test.php?key=voltika_cdc_cert_2026" style="font-size:12px;padding:8px 16px;">🔐 Ejecutar prueba automática</a></li>';
echo '<li>Pase a producción: <a href="https://developer.circulodecredito.com.mx/pase_a_produccion" target="_blank">Solicitar</a></li>';
echo '</ol>';

echo '<hr>';
echo '<p><a href="generar-certificado-cdc.php?key=voltika_cdc_cert_2026&regen=1" style="color:#C62828;font-size:12px;">🔄 Regenerar certificados (descarta los actuales)</a></p>';
echo '<p class="warn">⚠️ Eliminar este script después de completar el proceso.</p>';
echo '</body></html>';
