<?php
/**
 * Voltika Admin — Verifica que los fixes recientes (Round 59 → 62) están
 * desplegados en el servidor.
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

$checks = [
    // ── Round 59 (2026-05-19) — Backfill de firmas en contratos legacy ────
    'r59_diagnostico_firmas' => _checkFile(
        $base . '/admin/php/diagnostico-firmas.php',
        'Voltika Admin — Round 59 (2026-05-19)',
        'Round 59 — diagnostico-firmas.php: herramienta de diagnóstico + backfill que clasifica cada contrato (OK / RECUPERABLE / PENDIENTE / DATOS_PERDIDOS) y permite regenerar todos los contratos legacy a través del código actual (Round 15+42)'
    ),
    // ── Round 60 (2026-05-20) — listar.php incluye contrato_pdf_path ──────
    'r60_listar_contrato_pdf_path_in_response' => _checkFile(
        $base . '/admin/php/ventas/listar.php',
        "Round 60 (2026-05-20)",
        'Round 60 — listar.php: agrega contrato_pdf_path al array de respuesta JSON. Antes el SQL lo seleccionaba pero el PHP nunca lo mapeaba al output → la SPA siempre evaluaba _hasContractPdf=false → toda orden de crédito mostraba "Pagado · Falta firma" aunque tuviera firma + PDF persistidos.'
    ),
    // ── Round 61 (2026-05-20) — Reasignación de moto a otro punto ─────────
    'r61_desasignar_resets_estado' => _checkFile(
        $base . '/admin/php/ventas/desasignar-moto.php',
        "Round 61 (2026-05-20, Óscar)",
        'Round 61 — desasignar-moto.php: además de limpiar cliente/pedido_num/punto, ahora resetea estado="recibida" + fecha_estado=NOW() + log audit + supersede de envíos activos. Sin esto la moto quedaba en estado="por_llegar" tras desasignar y motos-disponibles.php (que filtra por estado IN recibida/lista_para_entrega) la ocultaba → admin no podía reasignar a otro punto.'
    ),
    'r61_asignar_punto_fallback_inventario' => _checkFile(
        $base . '/admin/php/inventario/asignar-punto.php',
        "Round 61 (2026-05-20)",
        'Round 61 — asignar-punto.php: si tipo=venta y no hay transaccion_id resolvible (moto recién desasignada), cae a tipo=inventario en vez de error 400. Permite reasignar una moto en un solo click aunque la UI envíe el contexto viejo.'
    ),
    // ── Round 62 (2026-05-20) — Picker de motos cross-punto ────────────────
    'r62_motos_disponibles_cross_punto' => _checkFile(
        $base . '/admin/php/ventas/motos-disponibles.php',
        "Round 62 (2026-05-20, Óscar — VIN 0049)",
        'Round 62 — motos-disponibles.php: ya no excluye motos que están en un punto distinto al del pedido. Ahora muestra TODAS las motos disponibles (activas, sin pedido_num, estado libre) y agrega `ubicacion`/`ubicacion_label`/`necesita_traslado` para que la UI muestre la ubicación de cada moto. Ordena: mismo punto → CEDIS → otros puntos.'
    ),
    'r62_admin_ventas_location_pill' => _checkFile(
        $base . '/admin/js/modules/admin-ventas.js',
        'Round 62: location pill',
        'Round 62 — admin-ventas.js: en el modal "Asignar moto" cada tarjeta muestra una pill de ubicación con icono+color (📍 En este punto / 🏭 CEDIS / 🚛 En Punto X — traslado requerido). El admin ahora ve TODAS las motos asignables y entiende cuáles necesitan traslado.'
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

<h1>🚀 Verificación de despliegue — Round 59 → 62 (2026-05-19 / 2026-05-20)</h1>
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
  <strong>Round 59 — Backfill de firmas en contratos legacy:</strong>
  <ul>
    <li>Abrir <code>/admin/php/diagnostico-firmas.php?key=voltika_diag_2026</code>.</li>
    <li>Ver el resumen (OK / Regenerables / Pendientes / Datos perdidos).</li>
    <li>Click en <strong>"Regenerar todos los X contratos regenerables"</strong> para backfill masivo.</li>
    <li>✅ Cada contrato regenerado embebe la firma autógrafa guardada en <code>firmas_contratos.firma_base64</code> y aplica Round 15 (sanitizer de nombre) + Round 42 (aspect ratio).</li>
  </ul>

  <strong style="display:block;margin-top:14px;">Round 60 — Panel Ventas: badge "Firmado" para crédito:</strong>
  <ul>
    <li>Admin → Ventas → cualquier orden de crédito con firma + PDF (ej. <strong>VK-1826-0001 Carlos Ricardo</strong>).</li>
    <li>✅ Esperado: badge verde/azul <strong>"✓ Firmado YYYY-MM-DD"</strong> bajo el tipo de pago, NO el amarillo "⚠ Pagado · Falta firma".</li>
    <li>Verifica el JSON: abrir <code>/admin/php/ventas/listar.php</code> → la fila debe incluir <code>"contrato_pdf_path": "contratos/..."</code>.</li>
  </ul>

  <strong style="display:block;margin-top:14px;">Round 61 — Reasignación de moto a otro punto:</strong>
  <ul>
    <li>Admin → Ventas → orden con moto asignada → click <strong>"Desasignar moto"</strong>.</li>
    <li>✅ Tras desasignar, el <code>inventario_motos.estado</code> debe quedar en <strong>"recibida"</strong> (no en "por_llegar").</li>
    <li>✅ Los envíos activos para esa moto pasan a <code>completado_no_exitoso</code> (no quedan duplicados en el panel Envíos).</li>
    <li>Click <strong>"Asignar moto"</strong> de nuevo en cualquier orden → la moto recién desasignada aparece en el picker y se puede mover a otro punto en un click.</li>
  </ul>

  <strong style="display:block;margin-top:14px;">Round 62 — Picker de motos incluye todos los puntos:</strong>
  <ul>
    <li>Admin → Ventas → orden sin moto asignada → click <strong>"Asignar moto"</strong>.</li>
    <li>✅ El picker ahora muestra <strong>TODAS</strong> las motos disponibles del mismo modelo, incluyendo las que están en otros puntos Voltika.</li>
    <li>Cada tarjeta muestra una pill de ubicación:
      <ul>
        <li>📍 verde <strong>"En este punto"</strong> — listo para entregar.</li>
        <li>🏭 azul <strong>"CEDIS"</strong> — envío estándar.</li>
        <li>🚛 ámbar <strong>"En [Punto X]"</strong> con nota "Se reasignará al punto del pedido".</li>
      </ul>
    </li>
    <li>Test específico: la moto con VIN terminado en <strong>0049</strong> (que tenía origen + ensamble completados pero estaba en otro punto) ahora debe aparecer en el picker para la orden objetivo.</li>
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
