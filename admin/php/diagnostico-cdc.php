<?php
/**
 * Voltika Admin — Round 50 comprehensive CDC diagnostic (2026-05-16).
 *
 * Standalone diagnostic for "CDC disconnected" incident. Verifies every
 * CDC requirement (credentials, certificate, signature) AND runs a live
 * test query against /v2/rccficoscore so the admin sees the EXACT HTTP
 * response CDC returns. Bypasses adminRequireAuth via the same shared
 * secret as the other diag tools.
 *
 * URL:
 *   https://voltika.mx/admin/php/diagnostico-cdc.php?key=voltika_diag_2026
 *
 * Sections:
 *   1. All CDC config: api_key length, user, pass structure, folio, base URL
 *   2. Private key + certificate presence (DB and disk)
 *   3. Live test query — sends a real /v2/rccficoscore call with synthetic
 *      but well-formed data and captures full request/response
 *   4. Recent cdc_query_log entries (last 10)
 *
 * Once CDC is verified working, this file can be deleted.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// Load config so CDC_* constants are defined.
foreach ([__DIR__ . '/../../configurador/php/config.php',
          __DIR__ . '/../../configurador_prueba_test/php/config.php'] as $cfg) {
    if (is_file($cfg)) { @require_once $cfg; break; }
}

$expected = 'voltika_diag_2026';
$key = $_GET['key'] ?? $_POST['key'] ?? '';
if (!hash_equals($expected, (string)$key)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Acceso denegado. Usa ?key=<secret>";
    exit;
}

$pdo = getDB();

// Helpers
function maskValue(string $v, int $tail = 6): string {
    $len = strlen($v);
    if ($len === 0) return '(vacío)';
    if ($len <= $tail) return str_repeat('*', $len);
    return str_repeat('*', $len - $tail) . substr($v, -$tail);
}

function passStructure(string $p): array {
    $upper = 0; $lower = 0; $digit = 0; $special = 0; $other = 0;
    $len = strlen($p);
    for ($i = 0; $i < $len; $i++) {
        $c = ord($p[$i]);
        if ($c >= 0x30 && $c <= 0x39)      $digit++;
        elseif ($c >= 0x41 && $c <= 0x5A)  $upper++;
        elseif ($c >= 0x61 && $c <= 0x7A)  $lower++;
        elseif ($c >= 0x20 && $c < 0x7F)   $special++;
        else                                $other++;
    }
    return compact('len','upper','lower','digit','special','other');
}

// ─────────────────────────────────────────────────────────────────────────
// POST: run a live CDC test query
// ─────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'live_test')) {
    // Round 50B (2026-05-17): CDC returned 403.2 "No se pudo autenticar".
    // To distinguish "wrong password" from "wrong signature", allow the
    // tester to override the password used JUST FOR THIS TEST without
    // changing anything in config. Lets us try the old password
    // `#KbC%Ro5XMM046` to confirm whether CDC's side has the new value
    // or still expects the old one.
    $passOverride = (string)($_POST['password_override'] ?? '');
    header('Content-Type: application/json; charset=utf-8');

    // Build synthetic but well-formed test data. Using a real-looking
    // sample so CDC doesn't reject for validation reasons — that way the
    // only failure modes left are auth-related.
    $body = [
        'primerNombre'    => trim((string)($_POST['primerNombre']    ?? 'JUAN')),
        'apellidoPaterno' => trim((string)($_POST['apellidoPaterno'] ?? 'PEREZ')),
        'apellidoMaterno' => trim((string)($_POST['apellidoMaterno'] ?? 'LOPEZ')),
        'fechaNacimiento' => trim((string)($_POST['fechaNacimiento'] ?? '1990-01-15')),
        'nacionalidad'    => 'MX',
        'domicilio' => [
            'direccion'           => 'CALLE FALSA 123',
            'coloniaPoblacion'    => 'CENTRO',
            'delegacionMunicipio' => 'BENITO JUAREZ',
            'ciudad'              => 'CIUDAD DE MEXICO',
            'estado'              => 'CDMX',
            'CP'                  => '03100',
        ],
    ];
    $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE);

    // Load private key (same resolution order as consultar-buro.php).
    $keyPem  = null;
    $certPem = null;
    try {
        $row = $pdo->query("SELECT private_key, certificate FROM cdc_certificates WHERE active = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) { $keyPem = $row['private_key']; $certPem = $row['certificate']; }
    } catch (Throwable $e) {}
    $kFile = __DIR__ . '/../../configurador/php/certs/cdc_private.key';
    $cFile = __DIR__ . '/../../configurador/php/certs/cdc_certificate.pem';
    if (!$keyPem  && file_exists($kFile))  $keyPem  = @file_get_contents($kFile);
    if (!$certPem && file_exists($cFile)) $certPem = @file_get_contents($cFile);

    if (!$keyPem) {
        echo json_encode(['ok' => false, 'error' => 'PRIVATE KEY MISSING — no se encontró en DB ni en disco',
                          'hint' => 'Sin private key NO se puede firmar x-signature → CDC rechazará con 403/503']);
        exit;
    }

    // Sign the body with SHA256 + RSA (CDC's x-signature spec).
    $sig = '';
    $signErr = null;
    $ok = @openssl_sign($jsonBody, $sigBin, $keyPem, OPENSSL_ALGO_SHA256);
    if (!$ok) {
        $signErr = openssl_error_string() ?: 'unknown openssl error';
    } else {
        $sig = bin2hex($sigBin);
    }

    if (!$sig) {
        echo json_encode(['ok' => false, 'error' => 'No se pudo firmar el body',
                          'openssl_error' => $signErr]);
        exit;
    }

    // Build headers exactly like consultar-buro.php does.
    $apiKey = defined('CDC_API_KEY') ? CDC_API_KEY : '';
    $user   = defined('CDC_USER')    ? CDC_USER    : '';
    $pass   = defined('CDC_PASS')    ? CDC_PASS    : '';
    $url    = defined('CDC_BASE_URL') ? CDC_BASE_URL : 'https://services.circulodecredito.com.mx/v2/rccficoscore';
    // Round 50B: optional one-shot password override for diagnostic only.
    $passOverrideActive = false;
    if ($passOverride !== '') {
        $pass = $passOverride;
        $passOverrideActive = true;
    }

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'x-api-key: ' . $apiKey,
    ];
    $headersInfo = [
        'Content-Type' => 'application/json',
        'Accept'       => 'application/json',
        'x-api-key'    => maskValue($apiKey),
        'username'     => $user !== '' ? maskValue($user) : '(NO ENVIADO — vacío)',
        'password'     => $pass !== '' ? maskValue($pass, 4) : '(NO ENVIADO — vacío)',
        'x-signature'  => substr($sig, 0, 16) . '... (' . strlen($sig) . ' hex chars)',
    ];
    if ($user !== '') $headers[] = 'username: ' . $user;
    if ($pass !== '') $headers[] = 'password: ' . $pass;
    $headers[] = 'x-signature: ' . $sig;

    // Capture response headers too.
    $respHeaderLines = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $jsonBody,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HEADERFUNCTION => function ($_c, $h) use (&$respHeaderLines) {
            $respHeaderLines[] = trim($h); return strlen($h);
        },
    ]);
    $start = microtime(true);
    $resp  = curl_exec($ch);
    $took  = round((microtime(true) - $start) * 1000);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $parsed = is_string($resp) ? json_decode($resp, true) : null;

    // Audit log
    try {
        $pdo->prepare("INSERT INTO admin_log (usuario_id, accion, detalle, ip)
                       VALUES (0, 'diagnostico_cdc_test', ?, ?)")
            ->execute([json_encode([
                'http'         => $httpCode,
                'took_ms'      => $took,
                'curl_err'     => $curlErr,
                'resp_short'   => substr((string)$resp, 0, 800),
                'user_sent'    => $user !== '',
                'pass_sent'    => $pass !== '',
                'sig_present'  => $sig !== '',
                'url'          => $url,
            ], JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) {}

    // Interpret the failure mode
    $diagnosis = null;
    if ($curlErr) {
        $diagnosis = 'NETWORK/SSL: curl falló antes de conectar — ' . $curlErr;
    } elseif ($httpCode === 401) {
        $diagnosis = 'AUTH (401): CDC rechazó las credenciales. La password / username / api-key no es válida en su sistema.';
    } elseif ($httpCode === 403) {
        $diagnosis = 'AUTH (403): CDC reconoció las credenciales pero las rechazó (firma inválida, IP bloqueada, o cuenta deshabilitada).';
    } elseif ($httpCode === 503) {
        $diagnosis = 'SERVER (503): CDC no disponible — puede ser firma faltante, proxy rechazando headers especiales, o problema temporal de CDC.';
    } elseif ($httpCode === 200 && is_array($parsed) && ($parsed['success'] ?? null) === true) {
        $diagnosis = '✓ CDC RESPONDIÓ OK — credenciales aceptadas, score recibido.';
    } elseif ($httpCode === 200) {
        $diagnosis = 'CDC respondió 200 pero el body no indica success — revisa el cuerpo abajo.';
    } else {
        $diagnosis = 'HTTP ' . $httpCode . ' — revisa cuerpo de respuesta abajo.';
    }

    echo json_encode([
        'ok'                => $httpCode >= 200 && $httpCode < 300 && !$curlErr && is_array($parsed) && !empty($parsed['success']),
        'http'              => $httpCode,
        'took_ms'           => $took,
        'diagnosis'         => $diagnosis,
        'password_overridden_for_test' => $passOverrideActive,
        'request' => [
            'url'         => $url,
            'headers'     => $headersInfo,
            'body'        => $body,
            'body_json'   => $jsonBody,
        ],
        'response' => [
            'http'         => $httpCode,
            'curl_err'     => $curlErr,
            'headers_raw'  => $respHeaderLines,
            'body_raw'     => $resp,
            'body_parsed'  => $parsed,
        ],
    ], JSON_PRETTY_PRINT);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// GET: dashboard
// ─────────────────────────────────────────────────────────────────────────

$apiKey = defined('CDC_API_KEY') ? CDC_API_KEY : '';
$user   = defined('CDC_USER')    ? CDC_USER    : '';
$pass   = defined('CDC_PASS')    ? CDC_PASS    : '';
$folio  = defined('CDC_FOLIO')   ? CDC_FOLIO   : '';
$url    = defined('CDC_BASE_URL') ? CDC_BASE_URL : 'https://services.circulodecredito.com.mx/v2/rccficoscore';

$passS = passStructure($pass);

// Certificate / private key status
$kFile = realpath(__DIR__ . '/../../configurador/php/certs/cdc_private.key');
$cFile = realpath(__DIR__ . '/../../configurador/php/certs/cdc_certificate.pem');
$keyOnDisk  = $kFile && is_file($kFile);
$certOnDisk = $cFile && is_file($cFile);

$diskKeyPem  = $keyOnDisk  ? @file_get_contents($kFile) : null;
$diskCertPem = $certOnDisk ? @file_get_contents($cFile) : null;

$keyInDb = false; $certInDb = false; $dbKeyPem = null; $dbCertPem = null;
try {
    $row = $pdo->query("SELECT private_key, certificate FROM cdc_certificates WHERE active = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $keyInDb  = !empty($row['private_key']);
        $certInDb = !empty($row['certificate']);
        $dbKeyPem  = $row['private_key']  ?? null;
        $dbCertPem = $row['certificate'] ?? null;
    }
} catch (Throwable $e) {}

// ─── Round 53 — Cert/Key match + algorithm + fingerprint analysis ───
function certInfo(?string $pem): array {
    if (!$pem) return ['present' => false];
    $x = @openssl_x509_read($pem);
    if (!$x) return ['present' => true, 'error' => 'PEM cert no es parseable'];
    $details = openssl_x509_parse($x);
    $pubKey = openssl_pkey_get_public($x);
    $pubDetails = $pubKey ? openssl_pkey_get_details($pubKey) : null;
    $alg = '?';
    if ($pubDetails) {
        if ($pubDetails['type'] === OPENSSL_KEYTYPE_RSA) $alg = 'RSA ' . $pubDetails['bits'] . ' bits';
        elseif ($pubDetails['type'] === OPENSSL_KEYTYPE_EC) $alg = 'EC (' . ($pubDetails['ec']['curve_name'] ?? 'unknown curve') . ') ' . $pubDetails['bits'] . ' bits';
        else $alg = 'type=' . $pubDetails['type'];
    }
    $fingerprint = openssl_x509_fingerprint($x, 'sha256') ?: null;
    return [
        'present'     => true,
        'subject'     => $details['subject']['CN'] ?? json_encode($details['subject'] ?? null),
        'issuer'      => $details['issuer']['CN']  ?? null,
        'not_before'  => isset($details['validFrom_time_t']) ? date('Y-m-d H:i', $details['validFrom_time_t']) : null,
        'not_after'   => isset($details['validTo_time_t'])   ? date('Y-m-d H:i', $details['validTo_time_t'])   : null,
        'algorithm'   => $alg,
        'fingerprint_sha256' => $fingerprint,
        'serial'      => $details['serialNumberHex'] ?? $details['serialNumber'] ?? null,
    ];
}
function keyInfo(?string $pem): array {
    if (!$pem) return ['present' => false];
    $k = @openssl_pkey_get_private($pem);
    if (!$k) return ['present' => true, 'error' => 'PEM key no es parseable'];
    $d = openssl_pkey_get_details($k);
    if ($d['type'] === OPENSSL_KEYTYPE_RSA) $alg = 'RSA ' . $d['bits'] . ' bits';
    elseif ($d['type'] === OPENSSL_KEYTYPE_EC) $alg = 'EC (' . ($d['ec']['curve_name'] ?? 'unknown') . ') ' . $d['bits'] . ' bits';
    else $alg = 'type=' . $d['type'];
    return ['present' => true, 'algorithm' => $alg, 'bits' => $d['bits']];
}
function keyMatchesCert(?string $keyPem, ?string $certPem): ?bool {
    if (!$keyPem || !$certPem) return null;
    $sample = 'voltika-cdc-test-' . bin2hex(random_bytes(8));
    $sig = '';
    if (!@openssl_sign($sample, $sig, $keyPem, OPENSSL_ALGO_SHA256)) return false;
    $pub = @openssl_pkey_get_public($certPem);
    if (!$pub) return false;
    return openssl_verify($sample, $sig, $pub, OPENSSL_ALGO_SHA256) === 1;
}

$diskCertI = certInfo($diskCertPem);
$dbCertI   = certInfo($dbCertPem);
$diskKeyI  = keyInfo($diskKeyPem);
$dbKeyI    = keyInfo($dbKeyPem);

$diskKeyVsDiskCert = keyMatchesCert($diskKeyPem, $diskCertPem);
$dbKeyVsDbCert     = keyMatchesCert($dbKeyPem,   $dbCertPem);
$dbKeyVsDiskCert   = keyMatchesCert($dbKeyPem,   $diskCertPem);
$diskKeyVsDbCert   = keyMatchesCert($diskKeyPem, $dbCertPem);
$certsIdentical    = ($diskCertPem && $dbCertPem) ? (trim((string)$diskCertPem) === trim((string)$dbCertPem)) : null;

// Recent CDC query log
$recentCdc = [];
try {
    $st = $pdo->query("SELECT id, freg, url, http_code, signature_sent, request_body, response_body, curl_error
                         FROM cdc_query_log
                        ORDER BY id DESC LIMIT 10");
    if ($st) $recentCdc = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Voltika — Diagnóstico CDC</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fb;color:#0c2340;padding:24px;max-width:1080px;margin:0 auto;line-height:1.5;}
h1{font-size:22px;margin:0 0 4px;} h2{font-size:14px;color:#475569;margin:24px 0 10px;text-transform:uppercase;letter-spacing:.4px;}
.muted{color:#94a3b8;font-size:11.5px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:14px;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th{text-align:left;padding:8px 6px;border-bottom:2px solid #cbd5e1;color:#475569;font-weight:700;font-size:12px;}
td{padding:8px 6px;border-bottom:1px solid #f1f5f9;vertical-align:top;}
code{background:#f1f5f9;color:#1e293b;padding:1px 5px;border-radius:3px;font-size:11.5px;font-family:ui-monospace,monospace;word-break:break-all;}
.ok{color:#16a34a;font-weight:700;} .bad{color:#dc2626;font-weight:700;} .warn{color:#d97706;font-weight:700;}
.banner{padding:12px 14px;border-radius:8px;font-size:13px;margin:12px 0;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.banner-bad{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
.banner-warn{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;}
.kv td:first-child{width:200px;color:#64748b;font-weight:700;font-size:12px;}
button{background:#039fe1;color:#fff;border:0;padding:10px 22px;border-radius:6px;font-weight:700;cursor:pointer;font-size:13px;}
input{padding:7px 11px;border:1px solid #cbd5e1;border-radius:5px;font-size:13px;width:200px;font-family:inherit;}
pre{background:#0b1322;color:#e2e8f0;padding:10px;border-radius:6px;font-size:11px;overflow-x:auto;max-height:320px;}
</style></head><body>

<h1>🔬 Diagnóstico CDC (Círculo de Crédito)</h1>
<div class="muted">Round 50 · servidor <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '') ?> · generado <?= date('Y-m-d H:i:s') ?></div>

<h2>1. Credenciales en runtime</h2>
<div class="card">
  <table class="kv">
    <tr><td>URL endpoint</td><td><code><?= htmlspecialchars($url) ?></code></td></tr>
    <tr><td>CDC_API_KEY</td><td>
      <?php if ($apiKey): ?>
        <span class="ok">✓</span> longitud <?= strlen($apiKey) ?> · <code><?= htmlspecialchars(maskValue($apiKey)) ?></code>
      <?php else: ?>
        <span class="bad">✗ VACÍO</span> — CDC rechazará la petición sin el header x-api-key
      <?php endif; ?>
    </td></tr>
    <tr><td>CDC_USER (header username:)</td><td>
      <?php if ($user): ?>
        <span class="ok">✓</span> longitud <?= strlen($user) ?> · <code><?= htmlspecialchars(maskValue($user, 3)) ?></code>
      <?php else: ?>
        <span class="bad">✗ VACÍO</span> — header <code>username:</code> NO se envía → CDC v2 lo rechaza
      <?php endif; ?>
    </td></tr>
    <tr><td>CDC_PASS (header password:)</td><td>
      <?php if ($pass): ?>
        <span class="ok">✓</span> longitud <?= $passS['len'] ?> · estructura:
        <?= $passS['upper'] ?> upper · <?= $passS['lower'] ?> lower · <?= $passS['digit'] ?> digit · <?= $passS['special'] ?> special<?= $passS['other'] ? ' · ' . $passS['other'] . ' OTRO' : '' ?>
        <?php if ($passS['len'] === 12 && $passS['upper'] === 2 && $passS['lower'] === 4 && $passS['digit'] === 4 && $passS['special'] === 2): ?>
          <br><span class="ok">✓ Coincide con "VoltiK2026#$" (12 chars, 2U+4L+4D+2S)</span>
        <?php elseif ($passS['len'] === 14 && $passS['upper'] === 6 && $passS['lower'] === 2 && $passS['digit'] === 4 && $passS['special'] === 2): ?>
          <br><span class="warn">⚠ Coincide con la contraseña vieja "#KbC%Ro5XMM046" (14 chars)</span>
        <?php else: ?>
          <br><span class="warn">⚠ No coincide con "VoltiK2026#$" ni con la vieja</span>
        <?php endif; ?>
      <?php else: ?>
        <span class="bad">✗ VACÍO</span> — header password: NO se envía → CDC rechaza
      <?php endif; ?>
    </td></tr>
    <tr><td>CDC_FOLIO</td><td>
      <?php if ($folio): ?>
        <code><?= htmlspecialchars(maskValue($folio, 4)) ?></code>
      <?php else: ?>
        <span class="warn">vacío</span> — no es estrictamente requerido para la API v2 pero se usa en logs
      <?php endif; ?>
    </td></tr>
  </table>
</div>

<h2>2. Llave privada + certificado (para x-signature)</h2>
<div class="card">
  <table class="kv">
    <tr><td>cdc_private.key en disco</td><td>
      <?= $keyOnDisk ? '<span class="ok">✓ ' . htmlspecialchars($kFile) . '</span>' : '<span class="warn">no</span>' ?>
      <?php if (!empty($diskKeyI['algorithm'])): ?> · <code><?= htmlspecialchars($diskKeyI['algorithm']) ?></code><?php endif; ?>
    </td></tr>
    <tr><td>cdc_certificate.pem en disco</td><td>
      <?= $certOnDisk ? '<span class="ok">✓ ' . htmlspecialchars($cFile) . '</span>' : '<span class="warn">no</span>' ?>
      <?php if (!empty($diskCertI['algorithm'])): ?>
        <br>algoritmo: <code><?= htmlspecialchars($diskCertI['algorithm']) ?></code>
        <br>fingerprint SHA-256: <code><?= htmlspecialchars((string)$diskCertI['fingerprint_sha256']) ?></code>
        <br>válido: <?= htmlspecialchars((string)$diskCertI['not_before']) ?> → <?= htmlspecialchars((string)$diskCertI['not_after']) ?>
      <?php endif; ?>
    </td></tr>
    <tr><td>private_key en cdc_certificates DB</td><td>
      <?= $keyInDb ? '<span class="ok">✓ presente</span>' : '<span class="warn">no</span>' ?>
      <?php if (!empty($dbKeyI['algorithm'])): ?> · <code><?= htmlspecialchars($dbKeyI['algorithm']) ?></code><?php endif; ?>
    </td></tr>
    <tr><td>certificate en cdc_certificates DB</td><td>
      <?= $certInDb ? '<span class="ok">✓ presente</span>' : '<span class="warn">no</span>' ?>
      <?php if (!empty($dbCertI['algorithm'])): ?>
        <br>algoritmo: <code><?= htmlspecialchars($dbCertI['algorithm']) ?></code>
        <br>fingerprint SHA-256: <code><?= htmlspecialchars((string)$dbCertI['fingerprint_sha256']) ?></code>
        <br>válido: <?= htmlspecialchars((string)$dbCertI['not_before']) ?> → <?= htmlspecialchars((string)$dbCertI['not_after']) ?>
      <?php endif; ?>
    </td></tr>
  </table>

  <?php if (!$keyOnDisk && !$keyInDb): ?>
    <div class="banner banner-bad" style="margin-top:10px;">
      ✗ <strong>SIN PRIVATE KEY:</strong> x-signature no se puede generar → CDC responderá 403 garantizado.
      Regenera con <code>generar-certificado-cdc.php?key=voltika_cdc_cert_2026&amp;regen=1</code>.
    </div>
  <?php endif; ?>

  <h3 style="font-size:13px;color:#475569;margin-top:18px;margin-bottom:8px;">Compatibilidad llave ↔ certificado</h3>
  <table class="kv">
    <?php
    $matchRow = function ($label, $result) {
      if ($result === null) return '<tr><td>' . $label . '</td><td class="muted">N/A (faltan datos)</td></tr>';
      if ($result)         return '<tr><td>' . $label . '</td><td><span class="ok">✓ COINCIDE — la llave puede firmar para este cert</span></td></tr>';
      return '<tr><td>' . $label . '</td><td><span class="bad">✗ NO COINCIDE — firmas no se podrán verificar</span></td></tr>';
    };
    echo $matchRow('Llave en disco ↔ Cert en disco',     $diskKeyVsDiskCert);
    echo $matchRow('Llave en DB    ↔ Cert en DB',         $dbKeyVsDbCert);
    echo $matchRow('Llave en DB    ↔ Cert en disco',     $dbKeyVsDiskCert);
    echo $matchRow('Llave en disco ↔ Cert en DB',         $diskKeyVsDbCert);
    ?>
    <tr><td>Disk cert idéntico a DB cert</td><td>
      <?php if ($certsIdentical === null): ?>
        <span class="muted">N/A</span>
      <?php elseif ($certsIdentical): ?>
        <span class="ok">✓ idénticos byte-a-byte</span>
      <?php else: ?>
        <span class="bad">✗ son DIFERENTES — probable causa del 403.2</span>
      <?php endif; ?>
    </td></tr>
  </table>

  <?php
  // Detect the danger pattern: signing uses DB key but uploaded cert was disk cert
  $signingKey  = $keyInDb ? 'DB' : ($keyOnDisk ? 'disk' : 'NONE');
  $uploadedToCdcAssumed = 'disk';   // we told user to upload disk file
  $signWithKey = $keyInDb ? $dbKeyPem : ($keyOnDisk ? $diskKeyPem : null);
  $signMatchesUpload = keyMatchesCert($signWithKey, $diskCertPem);
  ?>
  <?php if ($signMatchesUpload === false): ?>
    <div class="banner banner-bad" style="margin-top:12px;">
      🎯 <strong>POSIBLE CAUSA DEL 403.2:</strong> el código firma con la llave de la <strong><?= $signingKey ?></strong>,
      pero subimos a CDC el <strong>certificado del disco</strong>. Esa llave NO corresponde a ese certificado, por
      lo que CDC no puede verificar nuestra firma → rechaza con 403.2.<br>
      <strong>Fix:</strong> subir a CDC el certificado que SÍ corresponde a la llave usada para firmar
      (probablemente el certificado de la DB).
    </div>
  <?php elseif ($signMatchesUpload === true): ?>
    <div class="banner banner-ok" style="margin-top:12px;">
      ✓ La llave que el código usa para firmar SÍ corresponde al cert que subiste a CDC. Por aquí no es el problema.
    </div>
  <?php endif; ?>
</div>

<h2>3. Prueba en vivo — enviar una consulta real a CDC</h2>
<div class="card">
  <p style="margin-top:0;color:#475569;font-size:13px;">
    Se envía una petición sintética bien-formada a <code>/v2/rccficoscore</code> con tus credenciales actuales.
    La respuesta exacta de CDC te dirá si el problema es <strong>password incorrecto</strong>,
    <strong>username faltante</strong>, <strong>firma inválida</strong>, o <strong>cuenta deshabilitada</strong>.
  </p>
  <form id="testForm" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
    <input type="hidden" name="key" value="<?= htmlspecialchars($expected) ?>">
    <input type="hidden" name="action" value="live_test">
    <input type="hidden" name="password_override" id="passOverride" value="">
    <label>Nombre <input name="primerNombre" value="JUAN"></label>
    <label>Apellido P <input name="apellidoPaterno" value="PEREZ"></label>
    <label>Apellido M <input name="apellidoMaterno" value="LOPEZ"></label>
    <label>Nacimiento <input name="fechaNacimiento" value="1990-01-15"></label>
  </form>

  <div style="margin-top:14px;padding:12px;background:#f0f9ff;border:1px solid #93c5fd;border-radius:8px;">
    <div style="font-size:13px;color:#1e40af;margin-bottom:10px;line-height:1.5;">
      <strong>Round 50B — Comparar contraseñas:</strong> CDC respondió <code>403.2 "No se pudo autenticar"</code>.
      Para descartar si el problema es la contraseña nueva vs el cert/firma, prueba con AMBAS contraseñas a continuación.
      Estas pruebas <strong>NO modifican</strong> la configuración — solo envían un request al CDC con la contraseña indicada.
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:10px;">
      <button type="button" id="btnTestActive">
        🔍 Probar con contraseña ACTUAL (VoltiK2026#$)
      </button>
      <button type="button" id="btnTestOld" style="background:#d97706;">
        🔁 Probar con contraseña VIEJA (#KbC%Ro5XMM046)
      </button>
      <button type="button" id="btnTestCustom" style="background:#7c3aed;">
        ✍️ Probar con contraseña personalizada…
      </button>
    </div>
  </div>

  <div id="testStatus" style="margin-top:14px;font-size:13px;"></div>
  <div id="testResult"></div>
</div>

<h2>4. Últimos 10 intentos de consulta CDC (cdc_query_log)</h2>
<div class="card">
  <?php if (empty($recentCdc)): ?>
    <div class="muted">Sin registros en cdc_query_log.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Fecha</th><th>HTTP</th><th>Firma</th><th>Respuesta (corta)</th></tr></thead>
      <tbody>
        <?php foreach ($recentCdc as $r): ?>
          <tr>
            <td class="muted" style="white-space:nowrap;"><?= htmlspecialchars((string)$r['freg']) ?></td>
            <td>
              <?php $h = (int)$r['http_code']; ?>
              <strong style="color:<?= $h >= 200 && $h < 300 ? '#16a34a' : '#dc2626' ?>"><?= $h ?></strong>
            </td>
            <td><?= ((int)$r['signature_sent'] === 1) ? '<span class="ok">✓</span>' : '<span class="bad">✗</span>' ?></td>
            <td><code style="font-size:10.5px;"><?= htmlspecialchars(substr((string)$r['response_body'], 0, 250)) ?></code></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<script>
function runCdcTest(passOverride, label){
  var form = document.getElementById('testForm');
  var status = document.getElementById('testStatus');
  var result = document.getElementById('testResult');
  status.textContent = '⏳ Consultando CDC (' + label + ')...';
  result.innerHTML = '';
  document.getElementById('passOverride').value = passOverride || '';
  var fd = new FormData(form);
  fetch(location.pathname, { method: 'POST', credentials: 'include', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(j){
      var cls = j.ok ? 'banner-ok' : (j.http === 401 || j.http === 403 ? 'banner-bad' : 'banner-warn');
      var head = '<div class="banner ' + cls + '">' +
        '<strong>Test con: ' + label + '</strong><br>' +
        '<strong>HTTP ' + j.http + '</strong> · ' + j.took_ms + ' ms' +
        (j.password_overridden_for_test ? ' · 🔁 override temporal' : '') +
        '<br>' + (j.diagnosis || '') + '</div>';
      var sections = '';
      sections += '<div style="margin-top:14px;"><strong>Request enviado a CDC:</strong><pre>' + JSON.stringify(j.request, null, 2).replace(/[<>&]/g, function(c){return {'<':'&lt;','>':'&gt;','&':'&amp;'}[c]}) + '</pre></div>';
      sections += '<div><strong>Response recibida:</strong><pre>' + JSON.stringify(j.response, null, 2).replace(/[<>&]/g, function(c){return {'<':'&lt;','>':'&gt;','&':'&amp;'}[c]}) + '</pre></div>';
      status.textContent = '';
      result.innerHTML = head + sections;
    })
    .catch(function(e){
      status.textContent = '✗ ' + e.message;
    });
}
document.getElementById('btnTestActive').addEventListener('click', function(){
  runCdcTest('', 'contraseña ACTUAL en config (VoltiK2026#$)');
});
document.getElementById('btnTestOld').addEventListener('click', function(){
  if (!confirm('Esto enviará una consulta CDC con la contraseña VIEJA #KbC%Ro5XMM046 (solo para diagnóstico — no cambia la config). ¿Continuar?')) return;
  runCdcTest('#KbC%Ro5XMM046', 'contraseña VIEJA (#KbC%Ro5XMM046)');
});
document.getElementById('btnTestCustom').addEventListener('click', function(){
  var p = prompt('Escribe la contraseña a probar (solo para esta prueba, no se guarda):');
  if (!p) return;
  runCdcTest(p, 'contraseña personalizada (' + p.length + ' chars)');
});
</script>

</body></html>
