<?php
/**
 * VOLTIKA · .env DIAGNOSTIC
 * Checks why APP_ENV=live is not taking effect.
 * Reads the .env file directly AND compares with what PHP actually sees.
 *
 * Usage: ?key=voltika-envdiag-2026
 */

header('Content-Type: text/html; charset=utf-8');
$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika-envdiag-2026') { http_response_code(403); exit('Forbidden'); }

$envFile = __DIR__ . '/../.env';

?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<title>Voltika · .env Diagnosis</title>
<style>
  body { font-family: 'Inter', sans-serif; max-width: 1100px; margin: 20px auto; padding: 20px; background: #f0f4f8; color: #0c2340; }
  .box { background: #fff; padding: 18px 22px; border-radius: 14px; box-shadow: 0 4px 20px rgba(12,35,64,.07); margin-bottom: 14px; }
  h1 { color: #0c2340; font-size: 22px; }
  h2 { color: #039fe1; font-size: 15px; margin: 0 0 10px; padding-bottom: 6px; border-bottom: 1px solid #e2e8f0; }
  table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
  th, td { padding: 8px 12px; border-bottom: 1px solid #e2e8f0; text-align: left; }
  th { background: #f5f7fa; font-size: 11px; text-transform: uppercase; color: #64748b; }
  .ok { color: #16a34a; font-weight: 700; }
  .err { color: #dc2626; font-weight: 700; }
  .warn { color: #d97706; font-weight: 700; }
  code { background: #f1f5f9; padding: 2px 8px; border-radius: 4px; font-size: 11.5px; font-family: monospace; }
  pre { background: #0c2340; color: #e0f4fd; padding: 14px; border-radius: 8px; overflow-x: auto; font-size: 11px; }
  .alert-ok { background: #dcfce7; border-left: 4px solid #16a34a; padding: 12px 16px; border-radius: 8px; color: #166534; }
  .alert-err { background: #fee2e2; border-left: 4px solid #dc2626; padding: 12px 16px; border-radius: 8px; color: #991b1b; }
  .alert-warn { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 8px; color: #92400e; }
</style></head><body>

<div class="box"><h1>🔍 Voltika · Diagnóstico de <code>.env</code></h1>
<p style="font-size:12px;color:#64748b;">Ejecutado: <?= date('Y-m-d H:i:s') ?></p></div>

<?php

// ═══ 1. Check if .env file exists ═══════════════════════════════════════════
echo "<div class='box'><h2>📂 Paso 1: Archivo <code>.env</code></h2>";
echo "<table>";
echo "<tr><td>Ruta esperada:</td><td><code>" . htmlspecialchars($envFile) . "</code></td></tr>";

if (!file_exists($envFile)) {
    echo "<tr><td>Existe:</td><td class='err'>❌ NO</td></tr>";
    echo "</table>";
    echo "<div class='alert-err'>El archivo .env no existe en la ruta esperada.</div>";
    echo "</div></body></html>";
    exit;
}

echo "<tr><td>Existe:</td><td class='ok'>✓ SÍ</td></tr>";
echo "<tr><td>Tamaño:</td><td>" . filesize($envFile) . " bytes</td></tr>";
echo "<tr><td>Última modificación:</td><td>" . date('Y-m-d H:i:s', filemtime($envFile)) . "</td></tr>";
echo "<tr><td>Permisos:</td><td><code>" . substr(sprintf('%o', fileperms($envFile)), -4) . "</code></td></tr>";
echo "<tr><td>Legible:</td><td>" . (is_readable($envFile) ? "<span class='ok'>✓ SÍ</span>" : "<span class='err'>❌ NO</span>") . "</td></tr>";
echo "</table></div>";

// ═══ 2. Parse .env file manually ═══════════════════════════════════════════
echo "<div class='box'><h2>📖 Paso 2: Contenido del archivo (keys relevantes)</h2>";

$envContent = file_get_contents($envFile);
$lines = explode("\n", $envContent);

$envVars = [];
$showKeys = ['APP_ENV', 'STRIPE_SECRET_KEY_TEST', 'STRIPE_SECRET_KEY_LIVE', 'STRIPE_PUBLISHABLE_KEY_TEST', 'STRIPE_PUBLISHABLE_KEY_LIVE', 'STRIPE_WEBHOOK_SECRET_LIVE'];

echo "<table><tr><th>Variable</th><th>Valor en archivo</th><th>Longitud</th></tr>";
foreach ($lines as $lineNum => $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    if (strpos($line, '=') === false) continue;
    list($k, $v) = explode('=', $line, 2);
    $k = trim($k);
    $v = trim($v);
    // Remove surrounding quotes if any
    $v = preg_replace('/^["\'](.*)["\']$/', '$1', $v);
    $envVars[$k] = $v;

    if (in_array($k, $showKeys)) {
        $display = $v;
        // Mask secret keys but show prefix
        if (strpos($k, 'SECRET') !== false || strpos($k, 'PUBLISHABLE') !== false) {
            if (strlen($v) > 15) {
                $display = substr($v, 0, 12) . '...' . substr($v, -4);
            }
        }
        $expected = '';
        $highlight = '';
        if ($k === 'APP_ENV' && strtolower($v) === 'live') {
            $highlight = "style='background:#dcfce7;'";
            $expected = ' <span class="ok">← CORRECTO</span>';
        } elseif ($k === 'APP_ENV' && strtolower($v) !== 'live') {
            $highlight = "style='background:#fee2e2;'";
            $expected = ' <span class="err">← Debe ser: <code>live</code></span>';
        } elseif ($k === 'STRIPE_SECRET_KEY_LIVE' && strpos($v, 'sk_live_') === 0) {
            $highlight = "style='background:#dcfce7;'";
            $expected = ' <span class="ok">← CORRECTO</span>';
        } elseif ($k === 'STRIPE_SECRET_KEY_LIVE' && empty($v)) {
            $highlight = "style='background:#fee2e2;'";
            $expected = ' <span class="err">← VACÍO (debe empezar con sk_live_)</span>';
        }
        echo "<tr $highlight><td><code>$k</code>$expected</td><td><code>$display</code></td><td>" . strlen($v) . " chars</td></tr>";
    }
}
echo "</table></div>";

// ═══ 3. Check what PHP actually sees via getenv() ═══════════════════════════
echo "<div class='box'><h2>🐘 Paso 3: ¿Qué ve PHP?</h2>";

// Manually load .env like config.php does
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    if (strpos($line, '=') === false) continue;
    putenv($line);
}

echo "<table><tr><th>Variable</th><th>Valor que PHP ve</th><th>Estado</th></tr>";
foreach ($showKeys as $k) {
    $v = getenv($k) ?: '';
    $display = strlen($v) > 15 ? substr($v, 0, 12) . '...' . substr($v, -4) : $v;
    $status = empty($v) ? "<span class='warn'>vacío</span>" : "<span class='ok'>✓ cargado</span>";
    echo "<tr><td><code>$k</code></td><td><code>" . htmlspecialchars($display) . "</code></td><td>$status</td></tr>";
}
echo "</table></div>";

// ═══ 4. Load config.php and check constants ═══════════════════════════════
echo "<div class='box'><h2>⚙️ Paso 4: Constantes de config.php</h2>";

try {
    require_once __DIR__ . '/config.php';
    echo "<table><tr><th>Constante</th><th>Valor</th><th>Estado</th></tr>";

    echo "<tr><td><code>APP_ENV</code></td><td><code>" . (defined('APP_ENV') ? APP_ENV : 'NO DEFINIDA') . "</code></td>";
    $appEnvOk = defined('APP_ENV') && APP_ENV === 'live';
    echo "<td>" . ($appEnvOk ? "<span class='ok'>✓ LIVE</span>" : "<span class='err'>❌ " . (defined('APP_ENV') ? APP_ENV : 'undefined') . "</span>") . "</td></tr>";

    if (defined('STRIPE_SECRET_KEY')) {
        $sk = STRIPE_SECRET_KEY;
        $display = strlen($sk) > 15 ? substr($sk, 0, 12) . '...' . substr($sk, -4) : $sk;
        $isLive = strpos($sk, 'sk_live_') === 0;
        echo "<tr><td><code>STRIPE_SECRET_KEY</code></td><td><code>" . htmlspecialchars($display) . "</code></td>";
        echo "<td>" . ($isLive ? "<span class='ok'>✓ LIVE KEY</span>" : "<span class='err'>❌ " . (empty($sk) ? 'vacía' : 'TEST KEY') . "</span>") . "</td></tr>";
    } else {
        echo "<tr><td><code>STRIPE_SECRET_KEY</code></td><td>—</td><td class='err'>NO DEFINIDA</td></tr>";
    }

    echo "</table>";
} catch (Throwable $e) {
    echo "<div class='alert-err'>Error al cargar config.php: " . htmlspecialchars($e->getMessage()) . "</div>";
}
echo "</div>";

// ═══ 5. Diagnosis and recommendations ═══════════════════════════════════════
echo "<div class='box'>";
echo "<h2>💡 Diagnóstico</h2>";

$appEnvInFile = strtolower($envVars['APP_ENV'] ?? '');
$liveKeyInFile = $envVars['STRIPE_SECRET_KEY_LIVE'] ?? '';
$liveKeyStartsRight = strpos($liveKeyInFile, 'sk_live_') === 0;

if ($appEnvInFile === 'live' && $liveKeyStartsRight) {
    if (defined('APP_ENV') && APP_ENV === 'live' && defined('STRIPE_SECRET_KEY') && strpos(STRIPE_SECRET_KEY, 'sk_live_') === 0) {
        echo "<div class='alert-ok'>✅ Todo está configurado correctamente. Prueba SCAN de nuevo.</div>";
    } else {
        echo "<div class='alert-warn'>";
        echo "<strong>⚠ El archivo .env está correcto, pero PHP no está leyendo los valores nuevos.</strong><br>";
        echo "Esto puede deberse a:";
        echo "<ol>";
        echo "<li><strong>OPcache cacheado</strong> — el servidor tiene archivos PHP en caché</li>";
        echo "<li><strong>PHP-FPM necesita reiniciar</strong></li>";
        echo "<li><strong>Apache/Nginx necesita reiniciar</strong></li>";
        echo "</ol>";
        echo "<p><strong>Soluciones (en orden):</strong></p>";
        echo "<ol>";
        echo "<li>Reinicia PHP-FPM: <code>sudo systemctl restart php-fpm</code> o <code>sudo service php8.1-fpm restart</code></li>";
        echo "<li>Reinicia web server: <code>sudo systemctl restart apache2</code> o <code>nginx -s reload</code></li>";
        echo "<li>En cPanel: Software → Select PHP Version → Switch To PHP Options → Reset to default, o bien ir a MultiPHP Manager</li>";
        echo "<li>Ejecuta este script mismo — ya llama <code>putenv()</code> antes de <code>config.php</code> así debería funcionar</li>";
        echo "</ol>";
        echo "</div>";
    }
} elseif ($appEnvInFile !== 'live') {
    echo "<div class='alert-err'>";
    echo "<strong>❌ APP_ENV NO está en <code>live</code> en el archivo .env</strong><br>";
    echo "Valor actual en archivo: <code>" . htmlspecialchars($appEnvInFile) . "</code><br>";
    echo "Edita el archivo <code>$envFile</code> y cambia <code>APP_ENV=test</code> por <code>APP_ENV=live</code>";
    echo "</div>";
} elseif (empty($liveKeyInFile)) {
    echo "<div class='alert-err'>";
    echo "<strong>❌ STRIPE_SECRET_KEY_LIVE está VACÍO</strong><br>";
    echo "Debes agregar tu clave live en el .env:<br>";
    echo "<code>STRIPE_SECRET_KEY_LIVE=sk_live_51QpalAD...</code>";
    echo "</div>";
} elseif (!$liveKeyStartsRight) {
    echo "<div class='alert-err'>";
    echo "<strong>❌ STRIPE_SECRET_KEY_LIVE no empieza con <code>sk_live_</code></strong><br>";
    echo "Primeros caracteres actuales: <code>" . htmlspecialchars(substr($liveKeyInFile, 0, 15)) . "</code><br>";
    echo "Verifica que copiaste la clave correcta desde Stripe dashboard en modo LIVE.";
    echo "</div>";
}

// OPcache status
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status();
    if ($status && isset($status['opcache_enabled']) && $status['opcache_enabled']) {
        echo "<div class='alert-warn' style='margin-top:12px;'>";
        echo "<strong>⚠ OPcache está habilitado</strong> — esto puede ser la razón del caché.<br>";
        echo "Scripts cacheados: " . ($status['opcache_statistics']['num_cached_scripts'] ?? 'desconocido') . "<br>";
        echo "<p><strong>¿Quieres limpiar OPcache ahora?</strong> Agrega <code>&reset=1</code> a la URL.</p>";

        if (isset($_GET['reset']) && $_GET['reset'] === '1') {
            $ok = opcache_reset();
            echo "<p>" . ($ok ? "<span class='ok'>✅ OPcache LIMPIADO. Recarga la página y ejecuta SCAN de nuevo.</span>" : "<span class='err'>Error al limpiar OPcache</span>") . "</p>";
        }
        echo "</div>";
    }
}

echo "</div>";

?>
</body></html>
