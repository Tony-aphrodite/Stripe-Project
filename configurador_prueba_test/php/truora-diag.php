<?php
/**
 * Truora Diagnostic — show recent Truora API calls + test endpoint
 *
 * Access: ?key=voltika_cdc_2026
 *         ?test=1 to send a test create_check request
 */

$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika_cdc_2026') { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/config.php';
session_start();

header('Content-Type: text/html; charset=UTF-8');
echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Truora Diagnostic</title>';
echo '<style>body{font-family:Arial,sans-serif;max-width:1100px;margin:20px auto;padding:0 20px;color:#333}';
echo 'h1{color:#039fe1} pre{background:#1a1a1a;color:#0f0;padding:10px;border-radius:6px;font-size:11px;white-space:pre-wrap;word-break:break-all;max-height:300px;overflow-y:auto}';
echo '.step{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin:10px 0}';
echo '.ok{color:#10b981;font-weight:700}.err{color:#C62828;font-weight:700}.warn{color:#d97706;font-weight:700}';
echo 'table{border-collapse:collapse;width:100%;font-size:12px;margin:10px 0}td,th{border:1px solid #ddd;padding:6px 8px;text-align:left;vertical-align:top}';
echo '.btn{display:inline-block;padding:10px 20px;background:#039fe1;color:#fff;text-decoration:none;border-radius:6px;font-weight:700}</style></head><body>';
echo '<h1>🔍 Truora Diagnostic</h1>';

// 1. API Key info
echo '<h2>1. Configuración actual</h2><div class="step">';
$apiKey = defined('TRUORA_API_KEY') ? TRUORA_API_KEY : '';
if (!$apiKey) {
    echo '<span class="err">❌ TRUORA_API_KEY no definido</span>';
} else {
    $parts = explode('.', $apiKey);
    if (count($parts) === 3) {
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) ?: [];
        $isLive = ($payload['key_name'] ?? '') === 'voltikalive';
        $tag    = $isLive ? '<span class="ok">✅ PRODUCTION (key_name=voltikalive)</span>' : '<span class="warn">⚠️ NO production: key_name=' . htmlspecialchars($payload['key_name'] ?? '?') . '</span>';
        echo $tag . '<br>';
        echo '<table>';
        echo '<tr><td>key_name</td><td>' . htmlspecialchars($payload['key_name'] ?? '?') . '</td></tr>';
        echo '<tr><td>key_type</td><td>' . htmlspecialchars($payload['key_type'] ?? '?') . '</td></tr>';
        echo '<tr><td>username</td><td>' . htmlspecialchars($payload['username'] ?? '?') . '</td></tr>';
        echo '<tr><td>iat</td><td>' . (($payload['iat'] ?? 0) ? date('Y-m-d', $payload['iat']) : '?') . '</td></tr>';
        echo '<tr><td>exp</td><td>' . (($payload['exp'] ?? 0) ? date('Y-m-d', $payload['exp']) : '?') . '</td></tr>';
        echo '</table>';
    }
}
echo '</div>';

// 2. SSL handshake diagnosis — try multiple TLS configurations
if (!empty($_GET['test'])) {
    echo '<h2>2. Test SSL/TLS — probando múltiples configuraciones</h2>';

    $body = http_build_query([
        'country' => 'MX', 'type' => 'identity', 'user_authorized' => 'true',
        'first_name' => 'JUAN', 'last_name' => 'GARCIA LOPEZ',
        'date_of_birth' => '1985-03-15', 'phone_number' => '5512345678',
        'email' => 'test@voltika.mx',
    ]);

    function truoraTry($label, $opts, $body, $apiKey) {
        $ch = curl_init('https://api.truora.com/v1/checks');
        $verboseStream = fopen('php://temp', 'w+');
        $base = [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Truora-API-Key: ' . $apiKey, 'Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_VERBOSE => true, CURLOPT_STDERR => $verboseStream,
        ];
        curl_setopt_array($ch, $base + $opts);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        rewind($verboseStream);
        $verbose = stream_get_contents($verboseStream);
        fclose($verboseStream);
        $css = ($code >= 200 && $code < 500 && $code != 0) ? 'ok' : 'err';
        echo '<div class="step">';
        echo '<strong>' . htmlspecialchars($label) . '</strong> → <span class="' . $css . '">HTTP ' . $code . '</span><br>';
        if ($err) echo '<span class="err">curl: ' . htmlspecialchars($err) . '</span><br>';
        echo '<details><summary>Verbose log</summary><pre>' . htmlspecialchars(substr($verbose, 0, 2500)) . '</pre></details>';
        if ($resp) echo '<pre style="max-height:150px">' . htmlspecialchars(substr($resp, 0, 800)) . '</pre>';
        echo '</div>';
        return ['code' => $code, 'err' => $err];
    }

    truoraTry('A. Default (no TLS forcing)', [], $body, $apiKey);
    truoraTry('B. Force TLS 1.2', [CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2], $body, $apiKey);
    truoraTry('C. Force TLS 1.3', [CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_3], $body, $apiKey);
    truoraTry('D. TLS 1.2 + custom UA', [CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2, CURLOPT_USERAGENT => 'Mozilla/5.0'], $body, $apiKey);
    truoraTry('E. TLS 1.2 + cipher list (modern)', [
        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
        CURLOPT_SSL_CIPHER_LIST => 'ECDHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-AES128-GCM-SHA256',
    ], $body, $apiKey);
    truoraTry('F. SSL_VERIFYPEER off (DEBUG only)', [
        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
    ], $body, $apiKey);
    truoraTry('G. HTTP/1.1 forced', [
        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ], $body, $apiKey);

    // Try different Truora URLs
    echo '<h3 style="margin-top:20px">URLs alternativas</h3>';
    function truoraTryUrl($label, $url, $body, $apiKey) {
        $ch = curl_init($url);
        $verboseStream = fopen('php://temp', 'w+');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Truora-API-Key: ' . $apiKey, 'Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_VERBOSE => true, CURLOPT_STDERR => $verboseStream,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        rewind($verboseStream); $verbose = stream_get_contents($verboseStream); fclose($verboseStream);
        $css = ($code >= 200 && $code < 500 && $code != 0) ? 'ok' : 'err';
        echo '<div class="step"><strong>' . htmlspecialchars($label) . '</strong> → <span class="' . $css . '">HTTP ' . $code . '</span><br>';
        echo '<small>' . htmlspecialchars($url) . '</small><br>';
        if ($err) echo '<span class="err">curl: ' . htmlspecialchars($err) . '</span><br>';
        echo '<details><summary>Verbose</summary><pre style="font-size:10px">' . htmlspecialchars(substr($verbose, 0, 1500)) . '</pre></details>';
        if ($resp) echo '<pre style="max-height:120px;font-size:10px">' . htmlspecialchars(substr($resp, 0, 600)) . '</pre>';
        echo '</div>';
    }

    truoraTryUrl('H. api.identity.truora.com',     'https://api.identity.truora.com/v1/checks', $body, $apiKey);
    truoraTryUrl('I. api.checks.truora.com',       'https://api.checks.truora.com/v1/checks', $body, $apiKey);
    truoraTryUrl('J. api-mexico.truora.com',       'https://api-mexico.truora.com/v1/checks', $body, $apiKey);
    truoraTryUrl('K. mx.api.truora.com',           'https://mx.api.truora.com/v1/checks', $body, $apiKey);
    truoraTryUrl('L. api.validations.truora.com',  'https://api.validations.truora.com/v1/checks', $body, $apiKey);

    // Body type variations (api.checks accepted auth, just rejected "type")
    echo '<h3 style="margin-top:20px">api.checks.truora.com — probando "type" values</h3>';
    foreach (['identity','background','identity_questions','identity-validation','document','document-validation','person'] as $checkType) {
        $b = http_build_query([
            'country' => 'MX', 'type' => $checkType, 'user_authorized' => 'true',
            'first_name' => 'JUAN', 'last_name' => 'GARCIA LOPEZ',
            'date_of_birth' => '1985-03-15', 'phone_number' => '5512345678', 'email' => 'test@voltika.mx',
        ]);
        truoraTryUrl('type=' . $checkType, 'https://api.checks.truora.com/v1/checks', $b, $apiKey);
    }

    // type=person field discovery — incrementally add fields to find required schema
    echo '<h3 style="margin-top:20px">api.checks.truora.com type=person — descubriendo campos</h3>';
    $personBase = [
        'country' => 'MX', 'type' => 'person', 'user_authorized' => 'true',
        'first_name' => 'JUAN', 'last_name' => 'GARCIA LOPEZ',
        'date_of_birth' => '1985-03-15',
    ];
    $variants = [
        'P1: + gender=M'                          => $personBase + ['gender' => 'M'],
        'P2: + gender + national_id'              => $personBase + ['gender' => 'M', 'national_id' => 'GALJ850315'],
        'P3: + gender + phone'                    => $personBase + ['gender' => 'M', 'phone_number' => '5512345678'],
        'P4: + gender + phone + email'            => $personBase + ['gender' => 'M', 'phone_number' => '5512345678', 'email' => 'test@voltika.mx'],
        'P5: gender=F'                            => $personBase + ['gender' => 'F'],
        // state_id discovery
        'P6: gender + state_id=CDMX'              => $personBase + ['gender' => 'M', 'state_id' => 'CDMX'],
        'P7: gender + state_id=DF'                => $personBase + ['gender' => 'M', 'state_id' => 'DF'],
        'P8: gender + state_id=09'                => $personBase + ['gender' => 'M', 'state_id' => '09'],
        // CURP variants (national_id seems to be parsed as CURP)
        'P9: + state_id + CURP 18ch'              => $personBase + ['gender' => 'M', 'state_id' => 'CDMX', 'national_id' => 'GALJ850315HDFRRR07'],
        'P10: + national_id_type=curp'            => $personBase + ['gender' => 'M', 'state_id' => 'CDMX', 'national_id' => 'GALJ850315HDFRRR07', 'national_id_type' => 'curp'],
        'P11: minimal valid + email'              => $personBase + ['gender' => 'M', 'state_id' => 'CDMX', 'national_id' => 'GALJ850315HDFRRR07', 'email' => 'test@voltika.mx'],
    ];
    foreach ($variants as $label => $params) {
        truoraTryUrl($label, 'https://api.checks.truora.com/v1/checks', http_build_query($params), $apiKey);
    }

    // Also try Bearer auth header
    echo '<h3 style="margin-top:20px">api.identity.truora.com — Authorization Bearer header</h3>';
    {
        $ch = curl_init('https://api.identity.truora.com/v1/checks');
        $verboseStream = fopen('php://temp', 'w+');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_VERBOSE => true, CURLOPT_STDERR => $verboseStream,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        rewind($verboseStream); $verbose = stream_get_contents($verboseStream); fclose($verboseStream);
        $css = ($code >= 200 && $code < 500 && $code != 0) ? 'ok' : 'err';
        echo '<div class="step"><strong>Bearer + identity</strong> → <span class="' . $css . '">HTTP ' . $code . '</span><br>';
        if ($err) echo '<span class="err">' . htmlspecialchars($err) . '</span><br>';
        echo '<details><summary>Verbose</summary><pre style="font-size:10px">' . htmlspecialchars(substr($verbose, 0, 1500)) . '</pre></details>';
        if ($resp) echo '<pre style="max-height:120px">' . htmlspecialchars(substr($resp, 0, 600)) . '</pre>';
        echo '</div>';
    }

    // Test if we can reach Truora at all (just GET to base URL)
    echo '<h3 style="margin-top:20px">Test conectividad básica</h3>';
    function truoraConnTest($label, $url) {
        $ch = curl_init($url);
        $verboseStream = fopen('php://temp', 'w+');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_NOBODY => true,
            CURLOPT_VERBOSE => true, CURLOPT_STDERR => $verboseStream,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        rewind($verboseStream); $verbose = stream_get_contents($verboseStream); fclose($verboseStream);
        echo '<div class="step"><strong>' . htmlspecialchars($label) . '</strong> → HTTP ' . $code;
        if ($err) echo ' <span class="err">' . htmlspecialchars($err) . '</span>';
        echo '<details><summary>Verbose</summary><pre style="font-size:10px">' . htmlspecialchars(substr($verbose, 0, 800)) . '</pre></details></div>';
    }
    truoraConnTest('GET https://truora.com/', 'https://truora.com/');
    truoraConnTest('GET https://www.truora.com/', 'https://www.truora.com/');
    truoraConnTest('GET https://api.truora.com/', 'https://api.truora.com/');
} else {
    echo '<h2>2. Probar SSL handshake</h2>';
    echo '<p><a class="btn" href="?key=voltika_cdc_2026&test=1">🚀 Probar 7 configuraciones SSL</a></p>';
    echo '<div class="step"><strong>OpenSSL version on this server:</strong> ' . htmlspecialchars(OPENSSL_VERSION_TEXT) . '<br>';
    echo '<strong>cURL version:</strong> ' . htmlspecialchars(curl_version()['version']) . '<br>';
    echo '<strong>cURL SSL version:</strong> ' . htmlspecialchars(curl_version()['ssl_version']) . '</div>';
}

// 2b. Face-recognition endpoint probe
if (!empty($_GET['test'])) {
    echo '<h2>2b. Face-recognition endpoint probe</h2>';
    // Create a tiny test image on the fly
    $tmpImg1 = tempnam(sys_get_temp_dir(), 'img1') . '.jpg';
    $tmpImg2 = tempnam(sys_get_temp_dir(), 'img2') . '.jpg';
    $img = imagecreate(100, 100);
    imagecolorallocate($img, 255, 200, 150);
    imagejpeg($img, $tmpImg1);
    imagejpeg($img, $tmpImg2);
    imagedestroy($img);

    function faceProbe($label, $url, $fields, $apiKey) {
        $ch = curl_init($url);
        $verboseStream = fopen('php://temp', 'w+');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields,
            CURLOPT_HTTPHEADER => ['Truora-API-Key: ' . $apiKey],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_VERBOSE => true, CURLOPT_STDERR => $verboseStream,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $css = ($code >= 200 && $code < 500 && $code != 0) ? 'ok' : 'err';
        echo '<div class="step"><strong>' . htmlspecialchars($label) . '</strong> → <span class="' . $css . '">HTTP ' . $code . '</span><br>';
        echo '<small>' . htmlspecialchars($url) . '</small><br>';
        if ($err) echo '<span class="err">curl: ' . htmlspecialchars($err) . '</span><br>';
        if ($resp) echo '<pre style="max-height:150px;font-size:11px">' . htmlspecialchars(substr($resp, 0, 800)) . '</pre>';
        echo '</div>';
    }

    $f1 = new CURLFile($tmpImg1, 'image/jpeg', 'selfie.jpg');
    $f2 = new CURLFile($tmpImg2, 'image/jpeg', 'ine.jpg');

    faceProbe('F1: /v1/checks type=face-recognition + selfie_image+document_image',
        'https://api.checks.truora.com/v1/checks',
        ['country'=>'MX','type'=>'face-recognition','user_authorized'=>'true','selfie_image'=>$f1,'document_image'=>$f2],
        $apiKey);
    faceProbe('F2: /v1/checks type=face-recognition + image1+image2',
        'https://api.checks.truora.com/v1/checks',
        ['country'=>'MX','type'=>'face-recognition','user_authorized'=>'true','image1'=>$f1,'image2'=>$f2],
        $apiKey);
    faceProbe('F3: /v1/face-recognition',
        'https://api.checks.truora.com/v1/face-recognition',
        ['country'=>'MX','selfie_image'=>$f1,'document_image'=>$f2],
        $apiKey);
    faceProbe('F4: api.validations /v1/face-recognition',
        'https://api.validations.truora.com/v1/face-recognition',
        ['country'=>'MX','selfie_image'=>$f1,'document_image'=>$f2],
        $apiKey);
    faceProbe('F5: api.validations /v1/face-validation',
        'https://api.validations.truora.com/v1/face-validation',
        ['country'=>'MX','image1'=>$f1,'image2'=>$f2],
        $apiKey);

    @unlink($tmpImg1); @unlink($tmpImg2);
}

// 3. Recent Truora calls
echo '<h2>3. Últimas 20 llamadas a Truora (BD)</h2><div class="step">';
try {
    $pdo = getDB();
    $rows = $pdo->query("SELECT id, action, nombre, apellidos, email, http_code, LEFT(response, 800) AS resp, curl_err, freg
        FROM truora_query_log ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        echo '<span class="warn">⚠️ Sin llamadas todavía. Sube los nuevos archivos al servidor y haz un upload de identidad nuevo.</span>';
    } else {
        echo '<table>';
        echo '<tr><th>Fecha</th><th>Cliente</th><th>HTTP</th><th>Respuesta</th><th>Error</th></tr>';
        foreach ($rows as $r) {
            $codeCss = ($r['http_code'] >= 200 && $r['http_code'] < 300) ? 'ok' : 'err';
            echo '<tr>';
            echo '<td>' . htmlspecialchars($r['freg']) . '</td>';
            echo '<td>' . htmlspecialchars(($r['nombre'] ?? '') . ' ' . ($r['apellidos'] ?? '')) . '<br><small>' . htmlspecialchars($r['email']) . '</small></td>';
            echo '<td class="' . $codeCss . '">' . htmlspecialchars($r['http_code']) . '</td>';
            echo '<td><pre style="max-height:120px;font-size:10px">' . htmlspecialchars($r['resp']) . '</pre></td>';
            echo '<td>' . htmlspecialchars($r['curl_err'] ?: '-') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
} catch (Throwable $e) {
    echo '<span class="err">Error: ' . htmlspecialchars($e->getMessage()) . '</span>';
}
echo '</div>';

// 4. preaprobaciones with truora flag
echo '<h2>4. Últimas 5 preaprobaciones</h2><div class="step">';
try {
    $pdo = getDB();
    $rows = $pdo->query("SELECT id, nombre, apellido_paterno, email, status, truora_ok, synth_score, freg
        FROM preaprobaciones ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        echo '<table><tr><th>ID</th><th>Cliente</th><th>Status</th><th>Truora OK</th><th>Synth</th><th>Fecha</th></tr>';
        foreach ($rows as $r) {
            echo '<tr><td>' . $r['id'] . '</td><td>' . htmlspecialchars($r['nombre'] . ' ' . $r['apellido_paterno']) . '<br><small>' . htmlspecialchars($r['email']) . '</small></td>';
            echo '<td>' . htmlspecialchars($r['status']) . '</td>';
            echo '<td>' . ($r['truora_ok'] == 1 ? '<span class="ok">✅</span>' : '<span class="err">❌</span>') . '</td>';
            echo '<td>' . ($r['synth_score'] ?? '-') . '</td>';
            echo '<td>' . $r['freg'] . '</td></tr>';
        }
        echo '</table>';
    }
} catch (Throwable $e) {}
echo '</div>';

echo '</body></html>';
