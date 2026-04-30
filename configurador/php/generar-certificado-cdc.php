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

require_once __DIR__ . '/config.php';

// CN should match the CDC API username (RMD004694MGE). Many CDC/Apigee
// deployments map the uploaded cert to an API key via CN = username.
// Override via ?cn=... for testing.
$cdcUser = defined('CDC_USER') ? CDC_USER : 'RMD004694MGE';
$cn = $_GET['cn'] ?? $cdcUser;

$dn = [
    'countryName'            => 'MX',
    'stateOrProvinceName'    => 'Ciudad de Mexico',
    'localityName'           => 'CDMX',
    'organizationName'       => 'Voltika MX',
    'organizationalUnitName' => 'Tecnologia',
    'commonName'             => $cn,
    'emailAddress'           => 'ivan.clavel@voltika.mx',
];

// Force regeneration if requested — also deactivates the DB row so a fresh
// cert is created instead of being re-loaded below.
if (!empty($_GET['regen'])) {
    unset($_SESSION['cdc_cert_pem'], $_SESSION['cdc_key_pem']);
    try {
        require_once __DIR__ . '/config.php';
        getDB()->exec("UPDATE cdc_certificates SET active = 0");
    } catch (Throwable $e) {}
}

// CRITICAL: before generating, try to load the existing active cert from DB.
// Previously this script generated a NEW cert every time the session was
// empty (different browser, expired cookie), which silently broke the whole
// CDC integration — the private key in DB diverged from the public cert
// uploaded to CDC portal → every signature failed verification.
if (empty($_SESSION['cdc_cert_pem']) || empty($_SESSION['cdc_key_pem'])) {
    try {
        require_once __DIR__ . '/config.php';
        $row = getDB()->query("SELECT private_key, certificate FROM cdc_certificates WHERE active=1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $_SESSION['cdc_cert_pem'] = $row['certificate'];
            $_SESSION['cdc_key_pem']  = $row['private_key'];
        }
    } catch (Throwable $e) {}
}

// Generate or retrieve from session/DB
if (!empty($_SESSION['cdc_cert_pem']) && !empty($_SESSION['cdc_key_pem'])) {
    $certPem = $_SESSION['cdc_cert_pem'];
    $keyPem  = $_SESSION['cdc_key_pem'];
} else {
    // Key type — default ECDSA secp384r1, but allow RSA via ?type=rsa.
    // Empirically some CDC products verify RSA-SHA256 signatures even when
    // their docs say ECDSA, so RSA is a useful fallback to try.
    $keyType = ($_GET['type'] ?? 'ecdsa') === 'rsa' ? 'rsa' : 'ecdsa';
    $privateKey = $keyType === 'rsa'
        ? openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA])
        : openssl_pkey_new(['curve_name' => 'secp384r1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

    if (!$privateKey) {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<h2 style="color:red;">Error generando llave privada</h2>';
        echo '<pre>' . openssl_error_string() . '</pre>';
        exit;
    }

    openssl_pkey_export($privateKey, $keyPem);

    // Write an OpenSSL config file that marks the cert as an END-ENTITY
    // client cert (not a CA). Default PHP openssl.cnf signs with v3_ca
    // extensions, which some APIs (including Apigee with signature
    // verification policies) reject because CA certs should not be used
    // for client authentication or signing user payloads.
    $tmpCnf = tempnam(sys_get_temp_dir(), 'cdc_cnf_');
    file_put_contents($tmpCnf, "
[req]
distinguished_name = dn
req_extensions     = v3_req
prompt             = no

[dn]
CN = {$cn}

[v3_req]
basicConstraints     = critical, CA:FALSE
keyUsage             = critical, digitalSignature, nonRepudiation, keyEncipherment
extendedKeyUsage     = clientAuth, serverAuth
");

    $configArgs = [
        'digest_alg'       => 'sha256',
        'config'           => $tmpCnf,
        'req_extensions'   => 'v3_req',
        'x509_extensions'  => 'v3_req',
    ];

    $csr  = openssl_csr_new($dn, $privateKey, $configArgs);
    $cert = openssl_csr_sign($csr, null, $privateKey, 365, $configArgs);
    openssl_x509_export($cert, $certPem);
    @unlink($tmpCnf);

    $_SESSION['cdc_cert_pem'] = $certPem;
    $_SESSION['cdc_key_pem']  = $keyPem;

    // Persist to DB first (no file-permission dependency), then try disk as
    // backup. DB storage survives admin session expiry and is accessible to
    // every customer request via getDB().
    $GLOBALS['cdc_disk_status'] = ['db_saved'=>false,'dir_writable'=>false,'key_saved'=>false,'cert_saved'=>false,'errors'=>[]];

    try {
        require_once __DIR__ . '/config.php';
        $pdo = getDB();
        $pdo->exec("CREATE TABLE IF NOT EXISTS cdc_certificates (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            private_key   TEXT NOT NULL,
            certificate   TEXT NOT NULL,
            fingerprint   VARCHAR(80),
            active        TINYINT(1) NOT NULL DEFAULT 1,
            freg          DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("UPDATE cdc_certificates SET active = 0 WHERE active = 1");
        $fp = '';
        try { $fp = openssl_x509_fingerprint($certPem, 'sha256') ?: ''; } catch (Throwable $e) {}
        $ins = $pdo->prepare("INSERT INTO cdc_certificates (private_key, certificate, fingerprint, active) VALUES (?, ?, ?, 1)");
        $ins->execute([$keyPem, $certPem, $fp]);
        $GLOBALS['cdc_disk_status']['db_saved'] = true;
    } catch (Throwable $e) {
        $GLOBALS['cdc_disk_status']['errors'][] = 'DB save falló: ' . $e->getMessage();
    }

    // Best-effort disk write — not required if DB save succeeded
    $certsDir = __DIR__ . '/certs';
    if (!is_dir($certsDir)) @mkdir($certsDir, 0700, true);
    $GLOBALS['cdc_disk_status']['dir_writable'] = is_writable($certsDir);
    if ($GLOBALS['cdc_disk_status']['dir_writable']) {
        $keyFile  = $certsDir . '/cdc_private.key';
        $certFile = $certsDir . '/cdc_certificate.pem';
        $GLOBALS['cdc_disk_status']['key_saved']  = (@file_put_contents($keyFile,  $keyPem)  !== false);
        $GLOBALS['cdc_disk_status']['cert_saved'] = (@file_put_contents($certFile, $certPem) !== false);
        if ($GLOBALS['cdc_disk_status']['key_saved'])  @chmod($keyFile,  0600);
        if ($GLOBALS['cdc_disk_status']['cert_saved']) @chmod($certFile, 0644);
    }
}

// Download mode — ALWAYS serve the DB-active cert, never a session-stale one.
// This eliminates any chance of the downloaded file diverging from what
// consultar-buro.php uses to sign.
if (!empty($_GET['download'])) {
    try {
        require_once __DIR__ . '/config.php';
        $row = getDB()->query("SELECT private_key, certificate FROM cdc_certificates WHERE active=1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $certPem = $row['certificate'];
            $keyPem  = $row['private_key'];
        }
    } catch (Throwable $e) {}
}
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

// Detect actual key type from the cert's public key (not assumed)
$detectedType = 'desconocido';
try {
    $pub = openssl_pkey_get_public($certPem);
    if ($pub) {
        $det = openssl_pkey_get_details($pub);
        $detectedType = ($det['type'] === OPENSSL_KEYTYPE_EC) ? 'ECDSA secp384r1' : (($det['type'] === OPENSSL_KEYTYPE_RSA) ? 'RSA ' . ($det['bits'] ?? '?') : 'tipo #' . $det['type']);
    }
} catch (Throwable $e) {}
echo '<h1>✅ Certificado ' . $detectedType . ' generado</h1>';

$ds = $GLOBALS['cdc_disk_status'] ?? null;
if ($ds) {
    // DB save is the one that matters. Disk is best-effort backup.
    $ok  = !empty($ds['db_saved']);
    $bg  = $ok ? '#d1fae5' : '#fee2e2';
    $col = $ok ? '#065f46' : '#991b1b';
    echo '<div style="background:'.$bg.';color:'.$col.';padding:14px;border-radius:8px;margin-bottom:16px;">';
    echo '<strong>Persistencia:</strong><br>';
    echo ($ds['db_saved']     ? '✅' : '❌') . ' Guardado en base de datos (requerido)<br>';
    echo ($ds['dir_writable'] ? '✅' : '⚠️') . ' Directorio certs/ escribible (opcional)<br>';
    echo ($ds['key_saved']    ? '✅' : '⚠️') . ' cdc_private.key en disco (opcional)<br>';
    echo ($ds['cert_saved']   ? '✅' : '⚠️') . ' cdc_certificate.pem en disco (opcional)<br>';
    if (!$ok && !empty($ds['errors'])) {
        echo '<strong>Errores:</strong><ul>';
        foreach ($ds['errors'] as $e) echo '<li>' . htmlspecialchars($e) . '</li>';
        echo '</ul>';
    }
    echo '</div>';
} else {
    echo '<div style="background:#fef3c7;color:#78350f;padding:10px;border-radius:6px;margin-bottom:16px;">Llaves recuperadas de sesión (ya generadas antes). Para forzar regeneración + guardado en DB: <a href="?key=voltika_cdc_cert_2026&regen=1">Regenerar</a></div>';
}

$certData = openssl_x509_parse($certPem);
$fp = '';
try { $fp = openssl_x509_fingerprint($certPem, 'sha256') ?: ''; } catch (Throwable $e) {}
echo '<table border="1" cellpadding="8" style="border-collapse:collapse;margin-bottom:20px;">';
echo '<tr><th>Campo</th><th>Valor</th></tr>';
echo '<tr><td>Tipo de llave</td><td><strong>' . $detectedType . '</strong></td></tr>';
echo '<tr><td><strong>Fingerprint SHA-256</strong></td><td><code style="font-size:11px">' . htmlspecialchars($fp) . '</code><br><small style="color:#666">Verifica que este mismo fingerprint aparezca en el portal de CDC después de subir el cert.</small></td></tr>';
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
echo '<p style="margin:10px 0;">';
echo '<a href="generar-certificado-cdc.php?key=voltika_cdc_cert_2026&regen=1&type=ecdsa" style="display:inline-block;padding:8px 14px;background:#f3f4f6;color:#374151;text-decoration:none;border-radius:6px;font-size:12px;margin-right:8px;">🔄 Regenerar como ECDSA secp384r1</a>';
echo '<a href="generar-certificado-cdc.php?key=voltika_cdc_cert_2026&regen=1&type=rsa" style="display:inline-block;padding:8px 14px;background:#fef3c7;color:#78350f;text-decoration:none;border-radius:6px;font-size:12px;">🔄 Regenerar como RSA 2048 (si ECDSA no funciona)</a>';
echo '</p>';
echo '<p class="warn">⚠️ Eliminar este script después de completar el proceso.</p>';
echo '</body></html>';
