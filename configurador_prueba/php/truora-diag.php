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

// 2. Test ping
if (!empty($_GET['test'])) {
    echo '<h2>2. Test create_check</h2><div class="step">';
    $body = http_build_query([
        'country' => 'MX', 'type' => 'identity', 'user_authorized' => 'true',
        'first_name' => 'JUAN', 'last_name' => 'GARCIA LOPEZ',
        'date_of_birth' => '1985-03-15', 'phone_number' => '5512345678',
        'email' => 'test@voltika.mx',
    ]);
    $ch = curl_init('https://api.truora.com/v1/checks');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Truora-API-Key: ' . $apiKey, 'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    echo '<strong>HTTP:</strong> ' . $code . '<br>';
    if ($err) echo '<span class="err">curl error: ' . htmlspecialchars($err) . '</span><br>';
    if ($code === 200 || $code === 201) echo '<span class="ok">✅ Truora API responde — sistema accesible</span>';
    elseif ($code === 401 || $code === 403) echo '<span class="err">❌ HTTP ' . $code . ' — API key sin permisos</span>';
    else echo '<span class="err">❌ HTTP ' . $code . '</span>';
    echo '<pre>' . htmlspecialchars($resp ?: '(vacío)') . '</pre>';
    echo '</div>';
} else {
    echo '<p><a class="btn" href="?key=voltika_cdc_2026&test=1">🚀 Ejecutar test ping a Truora</a></p>';
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
