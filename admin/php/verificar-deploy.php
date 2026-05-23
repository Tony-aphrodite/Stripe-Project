<?php
/**
 * Voltika Admin — Verifica los fixes ACTIVOS / EN PRUEBAS están
 * desplegados en el servidor. Solo muestra los rounds más recientes
 * que aún requieren validación. Rounds finalizados se removieron
 * para mantener la página enfocada.
 *
 * Customer brief 2026-05-13: la gente sube archivos al servidor pero
 * no sabe si llegaron correctos o si PHP OPcache sigue sirviendo la
 * versión vieja. Este checker:
 *   1. Lee el contenido de cada archivo modificado
 *   2. Busca un "marcador" único que sólo existe en la versión nueva
 *   3. Reporta deployed=true/false por cada uno
 *   4. Permite resetear OPcache con un click
 *
 * URL: /admin/php/verificar-deploy.php
 */
require_once __DIR__ . '/bootstrap.php';
$adminId = adminRequireAuth(['admin']);

function _checkFile(string $path, string $marker, string $description): array {
    if (!file_exists($path)) {
        return ['ok' => false, 'desc' => $description, 'path' => $path,
                'reason' => 'archivo no encontrado en el servidor'];
    }
    $content = @file_get_contents($path);
    if ($content === false) {
        return ['ok' => false, 'desc' => $description, 'path' => $path,
                'reason' => 'no se pudo leer (permisos?)'];
    }
    $found = strpos($content, $marker) !== false;
    return [
        'ok' => $found,
        'desc' => $description,
        'path' => $path,
        'marker' => $marker,
        'size_bytes' => strlen($content),
        'mtime' => date('Y-m-d H:i:s', filemtime($path)),
        'reason' => $found ? 'nuevo código presente' : 'marcador no encontrado — versión vieja todavía',
    ];
}

$base = realpath(__DIR__ . '/../..');

// ─────────────────────────────────────────────────────────────────────────
// NOTE: Rounds 59–68 + 70 + 70 v2 + 70 v3 fueron desplegados y validados
// previamente — sus markers se removieron de este verificador para que el
// admin solo vea lo que sigue activo o en pruebas. Los archivos siguen
// en disco. Si necesitas re-validar algún round viejo, consulta git
// history de este archivo.
// ─────────────────────────────────────────────────────────────────────────
$checks = [
    // ── Round 69 (2026-05-23) — Cincel TIMESTAMP diagnostic (current focus) ──
    'r69_cincel_timestamp_diagnostic' => _checkFile(
        $base . '/admin/php/diagnostico-cincel-timestamp.php',
        'Diagn\xc3\xb3stico Cincel Timestamp (Round 69, 2026-05-23)',
        'Round 69 — diagnostico-cincel-timestamp.php: 3 probes contra GET /v3/timestamps/{hash} (endpoint público SIN auth, NO consume créditos). Confirma si Cincel está alcanzable para integrar el servicio de estampas de tiempo NOM-151 (lo que realmente necesita el cliente — no la firma completa).'
    ),

    // ── Round 70 v4 (2026-05-23) — Customer portal respects pago_estado ──
    'r70_v4_inicio_pago_estado_aware' => _checkFile(
        $base . '/clientes/js/modules/inicio.js',
        'Round 70 v4 (2026-05-23',
        'Round 70 v4 — clientes/js/modules/inicio.js: el card "RESUMEN DE PAGO" respeta compra.pago_estado: pagada→verde "Pagado al 100%"; parcial→azul; fallido→rojo; pendiente→ámbar "Pago pendiente". Fix del bug VK-1826-0004 (SPEI sin pagar mostraba "Tu compra está liquidada").'
    ),

    // ── Round 70 v5 (2026-05-23) — Auto-reload customer SPA on new build ──
    'r70_v5_version_endpoint' => _checkFile(
        $base . '/clientes/php/version.php',
        'Round 70 v5 (2026-05-23)',
        'Round 70 v5 — clientes/php/version.php: endpoint que devuelve hash de mtimes de los JS del portal cliente. SPA lo consulta al arrancar y fuerza reload cuando cambia → los clientes ya no se quedan con JS viejo después de un deploy.'
    ),
    'r70_v5_app_version_check' => _checkFile(
        $base . '/clientes/js/app.js',
        '_checkBuildVersion',
        'Round 70 v5 — clientes/js/app.js: VKApp.start() hace fetch a version.php; si la versión guardada en localStorage difiere, fuerza window.location.reload(). Auto-cache-bust permanente.'
    ),
];

// Live runtime checks — sanity-test the actual responses
$runtimeChecks = [];

// Check if PHP OPcache is enabled — it may be caching old code
$opcacheStatus = function_exists('opcache_get_status') ? opcache_get_status(false) : null;
$runtimeChecks['opcache'] = [
    'enabled'    => $opcacheStatus['opcache_enabled'] ?? false,
    'note'       => ($opcacheStatus['opcache_enabled'] ?? false)
                  ? 'OPcache ACTIVO — si subiste un .php pero no ves el cambio, hay que resetear OPcache o esperar revalidación'
                  : 'OPcache desactivado — cambios .php se aplican inmediato',
];

// Allow one-click OPcache reset (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = adminJsonIn();
    if (($body['action'] ?? '') === 'reset_opcache') {
        if (function_exists('opcache_reset')) {
            $ok = @opcache_reset();
            adminLog('verificar_deploy_opcache_reset', ['ok' => $ok]);
            echo json_encode(['ok' => $ok, 'message' => $ok ? 'OPcache reseteado' : 'No se pudo resetear']);
        } else {
            echo json_encode(['ok' => false, 'error' => 'opcache_reset no disponible']);
        }
        exit;
    }
    echo json_encode(['ok' => false, 'error' => 'acción desconocida']);
    exit;
}

$allOk = true;
foreach ($checks as $c) { if (!$c['ok']) { $allOk = false; break; } }

header('Content-Type: text/html; charset=UTF-8');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Voltika — Verificación de despliegue</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fb;color:#0c2340;padding:24px;max-width:980px;margin:0 auto;}
h1{font-size:22px;margin:0 0 4px;}
h2{font-size:14px;color:#475569;margin:24px 0 10px;text-transform:uppercase;letter-spacing:.4px;}
.sub{color:#64748b;font-size:13px;margin-bottom:18px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:12px;}
.row{display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid #f1f5f9;}
.row:last-child{border-bottom:0;}
.status{font-size:24px;line-height:1;width:32px;text-align:center;}
.status.ok{color:#16a34a;} .status.bad{color:#dc2626;}
.desc{flex:1;}
.title{font-weight:700;font-size:14px;color:#0c2340;}
.path{font-size:11px;color:#64748b;font-family:ui-monospace,monospace;margin-top:2px;word-break:break-all;}
.meta{font-size:11px;color:#94a3b8;margin-top:4px;}
.alert{padding:12px 14px;border-radius:8px;font-size:13px;margin-bottom:14px;}
.alert-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.alert-err{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
.btn{background:#039fe1;color:#fff;border:0;padding:9px 16px;border-radius:6px;font-weight:600;cursor:pointer;font-size:13px;}
code{background:#1e293b;color:#e2e8f0;padding:1px 6px;border-radius:3px;font-size:11px;}
ul{margin:0;padding-left:18px;font-size:13px;line-height:1.7;}
</style></head><body>

<h1>🚀 Verificación de despliegue — items activos</h1>
<div class="sub">Confirma que los archivos modificados llegaron al servidor con la versión correcta.</div>

<?php if ($allOk): ?>
  <div class="alert alert-ok">
    <strong>✅ Todos los archivos están desplegados correctamente.</strong> El servidor ya tiene el código nuevo.
    Si todavía ves el comportamiento viejo en el navegador → es caché del navegador (Ctrl+Shift+R) o CDN.
  </div>
<?php else: ?>
  <div class="alert alert-err">
    <strong>⚠ Algunos archivos no tienen el código nuevo.</strong> Revisa abajo cuál falta — quizás
    no se subió o PHP OPcache sigue sirviendo la versión anterior.
  </div>
<?php endif; ?>

<h2>1. Archivos modificados</h2>
<div class="card">
  <?php foreach ($checks as $key => $c): ?>
    <div class="row">
      <div class="status <?= $c['ok'] ? 'ok' : 'bad' ?>"><?= $c['ok'] ? '✓' : '✗' ?></div>
      <div class="desc">
        <div class="title"><?= htmlspecialchars($c['desc']) ?></div>
        <div class="path"><?= htmlspecialchars($c['path']) ?></div>
        <div class="meta">
          <strong>Estado:</strong> <?= htmlspecialchars($c['reason']) ?>
          <?php if (isset($c['size_bytes'])): ?>
            · <strong>Tamaño:</strong> <?= number_format($c['size_bytes']) ?> bytes
            · <strong>Modificado:</strong> <?= htmlspecialchars($c['mtime']) ?>
          <?php endif; ?>
        </div>
        <?php if (isset($c['marker'])): ?>
          <details style="margin-top:6px;">
            <summary style="cursor:pointer;font-size:11px;color:#94a3b8;">Marcador buscado</summary>
            <code><?= htmlspecialchars($c['marker']) ?></code>
          </details>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<h2>2. Verificación runtime</h2>
<div class="card">
  <div class="row">
    <div class="status <?= ($runtimeChecks['opcache']['enabled'] ?? false) ? 'bad' : 'ok' ?>">
      <?= ($runtimeChecks['opcache']['enabled'] ?? false) ? '⚠' : '✓' ?>
    </div>
    <div class="desc">
      <div class="title">PHP OPcache</div>
      <div class="meta">
        <?= htmlspecialchars($runtimeChecks['opcache']['note'] ?? '') ?>
      </div>
      <?php if ($runtimeChecks['opcache']['enabled'] ?? false): ?>
        <div style="margin-top:8px;">
          <button class="btn" id="btnResetOpcache" style="background:#d97706;">🔄 Resetear OPcache ahora</button>
          <span id="resetResult" style="margin-left:10px;font-size:12px;color:#475569;"></span>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<h2>3. Pruebas manuales — checklist</h2>
<div class="card" style="font-size:13.5px;line-height:1.7;">
  <strong>Round 69 — Cincel TIMESTAMP diagnostic:</strong>
  <ul>
    <li>Abrir <code>/admin/php/diagnostico-cincel-timestamp.php?key=voltika_diag_2026</code>.</li>
    <li>3 probes corren automáticamente contra <code>GET /v3/timestamps/{hash}</code>.</li>
    <li>✅ Esperado en el veredicto: <em>"El endpoint de timestamps RESPONDE correctamente"</em> (HTTP 200 ó 404 limpio).</li>
    <li>Si verde → seguir con la integración de timestamps NOM-151. Si rojo/amarillo → WAF aún activo, esperar y reintentar.</li>
  </ul>

  <strong style="display:block;margin-top:14px;">Round 70 v4 — Portal cliente respeta pago_estado:</strong>
  <ul>
    <li>Cliente con orden SPEI/OXXO no pagada → abre <code>voltika.mx/clientes/</code> e inicia sesión.</li>
    <li>✅ Card RESUMEN DE PAGO muestra <strong style="color:#9a3412;">🟠 Pago pendiente</strong> (no verde "Pagado al 100%").</li>
    <li>Footer en ámbar: hint específico por método ("Espera la confirmación del depósito SPEI…").</li>
  </ul>

  <strong style="display:block;margin-top:14px;">Round 70 v5 — Auto-cache-bust del portal cliente:</strong>
  <ul>
    <li>Endpoint funcionando: <code>voltika.mx/clientes/php/version.php</code> devuelve <code>{ version: "&lt;hash&gt;", files: {…} }</code>.</li>
    <li>Cliente con app abierta en pestaña/PWA → próxima vez que entre o navegue, la app hace <code>fetch</code> a <code>version.php</code>; si la versión cambió, se recarga sola.</li>
    <li>Test: DevTools → Application → Local Storage → editar <code>vk_build_version</code> a "x" → recargar → la página se debe re-recargar sola para traer JS fresco.</li>
  </ul>
</div>

<h2>4. Si algo no está OK</h2>
<div class="card" style="font-size:13.5px;line-height:1.7;">
  <strong>Si un archivo dice ✗ "marcador no encontrado":</strong>
  <ol style="margin:6px 0 0 18px;">
    <li>Verifica que subiste el archivo desde tu local correcto (no una versión vieja por error).</li>
    <li>Verifica permisos del archivo en el servidor (debe ser legible por PHP).</li>
    <li>Si OPcache está activo arriba, dale click a "Resetear OPcache".</li>
    <li>Recarga esta página (Ctrl+Shift+R) — debería detectar el archivo nuevo.</li>
  </ol>

  <strong style="display:block;margin-top:14px;">Si los archivos están OK pero el comportamiento sigue viejo en el navegador:</strong>
  <ol style="margin:6px 0 0 18px;">
    <li>Caché del navegador: Ctrl+Shift+R (Windows/Linux) o Cmd+Shift+R (Mac).</li>
    <li>Si hay CDN (Cloudflare/etc.): hacer "Purge cache" para los archivos cambiados.</li>
    <li>Abrir DevTools → Network → desmarcar "Disable cache" → recargar.</li>
  </ol>
</div>

<script>
var btn = document.getElementById('btnResetOpcache');
if (btn) btn.addEventListener('click', function() {
  if (!confirm('¿Resetear OPcache? Esto fuerza a PHP a re-leer todos los .php desde disco.')) return;
  btn.disabled = true;
  document.getElementById('resetResult').textContent = 'Procesando...';
  fetch('/admin/php/verificar-deploy.php', {
    method:'POST', credentials:'include',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'reset_opcache'})
  }).then(function(r){ return r.json(); }).then(function(j){
    document.getElementById('resetResult').textContent = j.ok ? '✓ ' + (j.message||'OK') : '✗ ' + (j.error||'fallo');
    btn.disabled = false;
    setTimeout(function(){ location.reload(); }, 1500);
  }).catch(function(e){
    document.getElementById('resetResult').textContent = '✗ ' + e.message;
    btn.disabled = false;
  });
});
</script>
</body></html>
