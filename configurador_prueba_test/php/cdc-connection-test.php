<?php
/**
 * CDC Connection Test — staging mirror.
 * See production copy at ../../configurador_prueba/php/cdc-connection-test.php
 */

$secret = $_GET['key'] ?? '';
$expected = getenv('CDC_DIAG_KEY') ?: 'voltika_cdc_2026';
if ($secret !== $expected) { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=utf-8');

$cdcBase   = defined('CDC_BASE_URL') ? CDC_BASE_URL : (getenv('CDC_BASE_URL') ?: 'https://services.circulodecredito.com.mx/v2/rccficoscore');
$cdcApiKey = defined('CDC_API_KEY') ? CDC_API_KEY : (getenv('CDC_API_KEY') ?: '');
$cdcUser   = defined('CDC_USER')    ? CDC_USER    : (getenv('CDC_USER')    ?: '');
$cdcPass   = defined('CDC_PASS')    ? CDC_PASS    : (getenv('CDC_PASS')    ?: '');
$cdcFolio  = defined('CDC_FOLIO')   ? CDC_FOLIO   : (getenv('CDC_FOLIO')   ?: '');

function mask(string $s): string {
    $len = strlen($s);
    if ($len === 0) return '(vacío)';
    if ($len <= 4)  return str_repeat('*', $len);
    return substr($s, 0, 2) . str_repeat('*', max(1, $len - 4)) . substr($s, -2);
}

function charInventory(string $s): string {
    $out = [];
    for ($i = 0, $L = strlen($s); $i < $L; $i++) {
        $c = $s[$i];
        $code = ord($c);
        if (!ctype_alnum($c)) {
            $out[] = "pos {$i}: '" . htmlspecialchars($c) . "' (0x" . strtoupper(dechex($code)) . ")";
        }
    }
    return $out ? implode(', ', $out) : '(sin caracteres especiales)';
}

$keyPem = null; $certPem = null; $keySource = 'missing'; $certSource = 'missing';
try {
    $pdoTmp = getDB();
    $row = $pdoTmp->query("SELECT private_key, certificate FROM cdc_certificates WHERE active = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        if (!empty($row['private_key']))  { $keyPem  = $row['private_key'];  $keySource  = 'DB'; }
        if (!empty($row['certificate']))  { $certPem = $row['certificate']; $certSource = 'DB'; }
    }
} catch (Throwable $e) { }

$keyFile  = __DIR__ . '/certs/cdc_private.key';
$certFile = __DIR__ . '/certs/cdc_certificate.pem';
if (!$keyPem  && file_exists($keyFile))  { $keyPem  = @file_get_contents($keyFile);  $keySource  = 'disk'; }
if (!$certPem && file_exists($certFile)) { $certPem = @file_get_contents($certFile); $certSource = 'disk'; }

$privValid = false;
if ($keyPem) {
    $priv = @openssl_pkey_get_private($keyPem);
    $privValid = (bool)$priv;
}

function cdcPing(string $url, string $apiKey, string $user, string $pass, ?string $keyPem, ?string $certPem): array {
    $body = [
        'primerNombre'    => 'JUAN',
        'apellidoPaterno' => 'PEREZ',
        'apellidoMaterno' => 'LOPEZ',
        'fechaNacimiento' => '1990-01-15',
        'nacionalidad'    => 'MX',
        'domicilio' => [
            'direccion'           => 'AVENIDA INSURGENTES 100',
            'coloniaPoblacion'    => 'CENTRO',
            'delegacionMunicipio' => 'CUAUHTEMOC',
            'ciudad'              => 'CIUDAD DE MEXICO',
            'estado'              => 'CDMX',
            'CP'                  => '06000',
        ],
        'RFC'  => 'PELJ900115XXX',
        'CURP' => 'PELJ900115HDFRPN09',
    ];
    $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE);

    $signatureHex = '';
    if ($keyPem) {
        $priv = @openssl_pkey_get_private($keyPem);
        if ($priv) {
            $sig = '';
            if (openssl_sign($jsonBody, $sig, $priv, OPENSSL_ALGO_SHA256)) {
                $signatureHex = bin2hex($sig);
            }
        }
    }

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'x-api-key: ' . $apiKey,
    ];
    if ($user) $headers[] = 'username: ' . $user;
    if ($pass) $headers[] = 'password: ' . $pass;
    if ($signatureHex) $headers[] = 'x-signature: ' . $signatureHex;

    $tmpCert = null; $tmpKey = null;
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonBody,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($certPem && $keyPem) {
        $tmpCert = tempnam(sys_get_temp_dir(), 'cdc_c_');
        $tmpKey  = tempnam(sys_get_temp_dir(), 'cdc_k_');
        file_put_contents($tmpCert, $certPem);
        file_put_contents($tmpKey,  $keyPem);
        $opts[CURLOPT_SSLCERT] = $tmpCert;
        $opts[CURLOPT_SSLKEY]  = $tmpKey;
    }

    $t0 = microtime(true);
    $ch = curl_init();
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($tmpCert) @unlink($tmpCert);
    if ($tmpKey)  @unlink($tmpKey);

    return [
        'user'     => $user,
        'elapsed'  => round((microtime(true) - $t0) * 1000),
        'http'     => $code,
        'body'     => (string)$resp,
        'err'      => $err,
        'has_sig'  => $signatureHex !== '',
        'sig_len'  => strlen($signatureHex),
    ];
}

$runPing = !isset($_GET['no_ping']);
$tryBoth = !empty($_GET['try_both']);

$results = [];
if ($runPing) {
    $results[] = cdcPing($cdcBase, $cdcApiKey, $cdcUser, $cdcPass, $keyPem, $certPem);
    if ($tryBoth) {
        $alt = '';
        if (preg_match('/^([A-Z]{3})0*(\d+)([A-Z]+)$/', $cdcUser, $m)) {
            if (strpos($cdcUser, $m[1] . '00') === 0) {
                $alt = $m[1] . $m[2] . $m[3];
            } else {
                $alt = $m[1] . '00' . $m[2] . $m[3];
            }
        }
        if ($alt && $alt !== $cdcUser) {
            $results[] = cdcPing($cdcBase, $cdcApiKey, $alt, $cdcPass, $keyPem, $certPem);
        }
    }
}

function httpBadge(int $code): string {
    if ($code === 0)                 return '<span style="color:#ef4444">curl-error</span>';
    if ($code >= 200 && $code < 300) return '<span style="color:#10b981">' . $code . ' OK</span>';
    if ($code === 400 || $code === 404) return '<span style="color:#f59e0b">' . $code . ' (auth OK, body issue)</span>';
    if ($code === 401 || $code === 403) return '<span style="color:#ef4444">' . $code . ' AUTH FAIL</span>';
    if ($code === 503)               return '<span style="color:#ef4444">503 Apigee</span>';
    return '<span style="color:#6b7280">' . $code . '</span>';
}

function verdict(int $code, string $err): string {
    if ($err)                           return '❌ TLS/network error — revisar curl_err';
    if ($code === 0)                    return '❌ No llegó respuesta';
    if ($code >= 200 && $code < 300)    return '✅ CDC respondió OK — auth completo, firma válida';
    if ($code === 400)                  return '✅ Auth OK (CDC recibió y firmó). 400 = body inválido (esperado con datos falsos)';
    if ($code === 404)                  return '✅ Auth OK. 404.1 = persona no encontrada (esperado con datos falsos)';
    if ($code === 401 || $code === 403) return '❌ Auth fallido — usuario/contraseña/api-key incorrecto';
    if ($code === 503)                  return '❌ 503 — probablemente la contraseña rompe HTTP headers o firma inválida';
    return '⚠ Respuesta inesperada — revisar cuerpo';
}

?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>CDC Connection Test (STAGING)</title>
<style>
body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background:#0f172a; color:#e2e8f0; margin:0; padding:24px; }
.container { max-width: 1000px; margin: 0 auto; }
h1 { color:#fbbf24; font-size:24px; margin:0 0 16px; }
h2 { color:#60a5fa; font-size:18px; margin:24px 0 12px; border-bottom:1px solid #334155; padding-bottom:6px; }
.card { background:#1e293b; border:1px solid #334155; border-radius:8px; padding:16px; margin:12px 0; }
.ok { color:#10b981; font-weight:600; }
.bad { color:#ef4444; font-weight:600; }
.warn { color:#f59e0b; font-weight:600; }
.kv { display:grid; grid-template-columns: 240px 1fr; gap:8px 16px; font-family: Consolas, Menlo, monospace; font-size:13px; }
.kv > div:nth-child(odd) { color:#94a3b8; }
pre { background:#0b1220; border:1px solid #1e293b; border-radius:6px; padding:12px; overflow:auto; font-size:12px; color:#cbd5e1; max-height:400px; }
.links a { color:#60a5fa; margin-right:16px; }
.badge { padding:2px 8px; border-radius:999px; background:#334155; font-size:11px; }
.row { display:flex; gap:12px; align-items:center; margin:6px 0; }
</style>
</head>
<body>
<div class="container">
<h1>🔌 CDC Connection Test <span style="color:#f59e0b;font-size:14px;">(STAGING)</span></h1>
<p class="links">
    <a href="?key=<?= htmlspecialchars($secret) ?>">▶ Ejecutar test</a>
    <a href="?key=<?= htmlspecialchars($secret) ?>&amp;try_both=1">▶ Probar ambos usuarios</a>
    <a href="?key=<?= htmlspecialchars($secret) ?>&amp;no_ping=1">▶ Solo mostrar env</a>
</p>

<h2>1. Environment</h2>
<div class="card">
<div class="kv">
    <div>CDC_BASE_URL</div><div><?= htmlspecialchars($cdcBase) ?></div>
    <div>CDC_API_KEY</div><div><?= mask($cdcApiKey) ?> <span class="badge"><?= strlen($cdcApiKey) ?> chars</span></div>
    <div>CDC_USER</div><div><strong><?= htmlspecialchars($cdcUser) ?: '<span class="bad">(vacío)</span>' ?></strong> <span class="badge"><?= strlen($cdcUser) ?> chars</span></div>
    <div>CDC_PASS</div><div><?= mask($cdcPass) ?> <span class="badge"><?= strlen($cdcPass) ?> chars</span></div>
    <div>CDC_PASS (especiales)</div><div><?= htmlspecialchars(charInventory($cdcPass)) ?></div>
    <div>CDC_FOLIO</div><div><?= htmlspecialchars($cdcFolio) ?: '<span class="warn">(vacío)</span>' ?></div>
</div>
</div>

<?php
if (preg_match('/^[A-Z]{3}00\d+[A-Z]+$/', $cdcUser)):
?>
<div class="card" style="border-color:#f59e0b;">
    <span class="warn">⚠ CDC_USER todavía tiene el formato antiguo con "00" (ej. RMD<b>00</b>4694MGE).</span><br>
    El equipo CDC pidió cambiarlo a <b>RMD4694MGE</b>. Actualiza Plesk → Environment Variables y reinicia PHP-FPM.
</div>
<?php elseif ($cdcUser === ''): ?>
<div class="card" style="border-color:#ef4444;">
    <span class="bad">❌ CDC_USER está vacío.</span> Configúralo en Plesk → Environment Variables.
</div>
<?php endif; ?>

<h2>2. Certificado / llave privada</h2>
<div class="card">
<div class="kv">
    <div>Private key</div><div><?= $keyPem ? '<span class="ok">cargada</span>' : '<span class="bad">no encontrada</span>' ?> <span class="badge">fuente: <?= $keySource ?></span></div>
    <div>Certificate</div><div><?= $certPem ? '<span class="ok">cargado</span>' : '<span class="bad">no encontrado</span>' ?> <span class="badge">fuente: <?= $certSource ?></span></div>
    <div>openssl_pkey_get_private</div><div><?= $privValid ? '<span class="ok">OK</span>' : '<span class="bad">no parseable</span>' ?></div>
</div>
</div>

<?php if ($runPing): ?>
<h2>3. Ping a CDC</h2>
<p style="color:#94a3b8;font-size:13px;">Enviamos un payload mínimo con datos ficticios. Lo importante es el HTTP code, no el resultado del buró.</p>

<?php foreach ($results as $idx => $r): ?>
<div class="card">
    <div class="row">
        <strong>Usuario:</strong> <code><?= htmlspecialchars($r['user']) ?: '(vacío)' ?></code>
        <strong>HTTP:</strong> <?= httpBadge((int)$r['http']) ?>
        <span class="badge"><?= $r['elapsed'] ?> ms</span>
        <?= $r['has_sig'] ? '<span class="badge" style="background:#065f46">firma ' . $r['sig_len'] . ' hex</span>' : '<span class="badge" style="background:#7f1d1d">sin firma</span>' ?>
    </div>
    <div style="margin:8px 0;"><?= verdict((int)$r['http'], (string)$r['err']) ?></div>
    <?php if ($r['err']): ?>
        <div><strong>curl_error:</strong> <code><?= htmlspecialchars($r['err']) ?></code></div>
    <?php endif; ?>
    <?php if ($r['body']): ?>
        <details <?= $idx === 0 ? 'open' : '' ?>>
            <summary>Respuesta CDC (<?= strlen($r['body']) ?> bytes)</summary>
            <pre><?php
                $decoded = json_decode($r['body'], true);
                echo htmlspecialchars($decoded ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $r['body']);
            ?></pre>
        </details>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<h2>4. Guía de interpretación</h2>
<div class="card" style="font-size:13px; line-height:1.7;">
    <div><span class="ok">HTTP 200</span> → CDC funcionando completamente.</div>
    <div><span class="warn">HTTP 400</span> → Auth OK. Cuerpo rechazado (esperado con datos falsos).</div>
    <div><span class="warn">HTTP 404</span> → Auth OK. Persona no encontrada.</div>
    <div><span class="bad">HTTP 401/403</span> → Credenciales mal. Verifica user/pass/api-key.</div>
    <div><span class="bad">HTTP 503</span> → Apigee rechazó. Firma inválida o password con caracteres peligrosos.</div>
    <div><span class="bad">curl-error</span> → TLS/mTLS. Verifica el par cert+key activo en el portal.</div>
</div>
<?php endif; ?>

<div style="text-align:center;color:#64748b;margin-top:40px;font-size:11px;">
    Voltika CDC diagnostic (staging) · <?= date('Y-m-d H:i:s') ?>
</div>
</div>
</body>
</html>
