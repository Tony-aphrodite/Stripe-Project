<?php
/**
 * Voltika Admin — Verifica que los fixes del 2026-05-13 (round 14) están
 * desplegados en el servidor.
 *
 * Customer brief 2026-05-13: la gente sube archivos al servidor pero
 * no sabe si llegaron correctos o si PHP OPcache sigue sirviendo la
 * versión vieja. Este checker:
 *   1. Lee el contenido de cada archivo modificado
 *   2. Busca un "marcador" único que sólo existe en la versión nueva
 *   3. Reporta deployed=true/false por cada uno
 *   4. Para los endpoints reales, hace una llamada de prueba
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
    'recover_truora' => _checkFile(
        $base . '/configurador/recover-truora.php',
        '?recovered=1&paso=credito-identidad',
        'Issue 1 — Email recovery link incluye ?paso= explícito'
    ),
    'configurador_js' => _checkFile(
        $base . '/configurador/js/configurador.js',
        "URLSearchParams(window.location.search).get('paso')",
        'Issue 1 — SPA configurador.js maneja ?paso= URL parameter'
    ),
    'compras_php' => _checkFile(
        $base . '/clientes/php/cliente/compras.php',
        'duplicate_attempts',
        'Issue 2 — Mis compras hace dedup de duplicados'
    ),
    'cdc_excel' => _checkFile(
        $base . '/admin/php/buro/exportar.php',
        'NOMBRE_CLIENTE',
        'Issue 3 — CDC Excel: 16 columnas oficiales NIP-CIEC PF'
    ),
    'cdc_excel_order' => _checkFile(
        $base . '/admin/php/buro/exportar.php',
        "'Estado',                      // lowercase 'e'",
        'Issue 3 — CDC Excel: columna Estado con minúscula (template oficial)'
    ),
    // ── Round 15 (2026-05-14) — Contrato DÉCIMA SÉPTIMA ────────────────────
    'r15_name_sanitizer' => _checkFile(
        $base . '/configurador/php/contrato-contado.php',
        'function contratoContadoSanitizeFullName(',
        'Round 15 — Contrato: sanitizer de nombre (no más "Adrian Montoya Diaz Montoya Diaz")'
    ),
    'r15_cincel_fetcher' => _checkFile(
        $base . '/configurador/php/contrato-contado.php',
        'function contratoContadoFetchCincelAudit(',
        'Round 15 — Contrato: fetcher de Cincel / NOM-151 audit'
    ),
    'r15_registro_cincel_row' => _checkFile(
        $base . '/configurador/php/contrato-contado.php',
        'Firma electrónica avanzada (Acta de Entrega)',
        'Round 15 — Contrato REGISTRO: fila Cincel / firma electrónica avanzada visible'
    ),
    'r15_registro_nom151_row' => _checkFile(
        $base . '/configurador/php/contrato-contado.php',
        'Constancia NOM-151-SCFI-2016',
        'Round 15 — Contrato REGISTRO: fila Constancia NOM-151-SCFI-2016'
    ),
    'r15_registro_ip_fallback' => _checkFile(
        $base . '/configurador/php/contrato-contado.php',
        'No capturada por el sistema en el momento de la aceptación',
        'Round 15 — Contrato REGISTRO: fallback explícito para IP / Geo / Dispositivo vacíos'
    ),
    'r15_confirmar_uses_sanitizer' => _checkFile(
        $base . '/configurador/php/confirmar-orden.php',
        'contratoContadoSanitizeFullName(',
        'Round 15 — confirmar-orden.php usa el sanitizer en lugar del implode crudo'
    ),
    'r15_descargar_uses_sanitizer' => _checkFile(
        $base . '/configurador/php/descargar-contrato.php',
        'contratoContadoSanitizeFullName(',
        'Round 15 — descargar-contrato.php regen sanitiza el nombre persistido'
    ),
    // ── Round 16 (2026-05-14) — Dossier de Defensa PDF ─────────────────────
    'r16_dossier_uses_sanitizer' => _checkFile(
        $base . '/configurador/php/dossier-defensa.php',
        'contratoContadoSanitizeFullName(',
        'Round 16 — Dossier PDF: sanitizer aplicado al nombre del cliente'
    ),
    'r16_dossier_modalidad_normalized' => _checkFile(
        $base . '/configurador/php/dossier-defensa.php',
        "'CONTADO'",
        'Round 16 — Dossier PDF: Modalidad de pago "UNICO" normalizada a "CONTADO"'
    ),
    'r16_dossier_ip_fallback' => _checkFile(
        $base . '/configurador/php/dossier-defensa.php',
        'No capturada por el sistema en el momento de la aceptación',
        'Round 16 — Dossier PDF: fallback explícito para IP / Geo / Dispositivo / SHA-256'
    ),
    // ── Round 17 (2026-05-14) — Checklist drill-in con autor + fotos ───────
    'r17_detalle_dealer_enrichment' => _checkFile(
        $base . '/admin/php/checklists/detalle.php',
        '_detalleDealerInfo(',
        'Round 17 — detalle.php expone _dealer_nombre / _dealer_rol / _dealer_punto por fase'
    ),
    'r17_detalle_fotos_decoder' => _checkFile(
        $base . '/admin/php/checklists/detalle.php',
        '_detalleDecodePhotos(',
        'Round 17 — detalle.php expone arrays de URLs de fotos por columna'
    ),
    'r17_ensamble_dealer_snapshot' => _checkFile(
        $base . '/admin/php/checklists/guardar-ensamble.php',
        'dealer_nombre_snapshot',
        'Round 17 — guardar-ensamble.php captura snapshot del nombre del dealer'
    ),
    'r17_entrega_dealer_snapshot' => _checkFile(
        $base . '/admin/php/checklists/guardar-entrega.php',
        'dealer_nombre_snapshot',
        'Round 17 — guardar-entrega.php captura snapshot del nombre del dealer'
    ),
    'r17_ventas_drill_in' => _checkFile(
        $base . '/admin/js/modules/admin-ventas.js',
        'renderChecklistDetail(',
        'Round 17 — admin-ventas.js: drill-in con items + fotos + autor + timestamp'
    ),
];

// Live runtime checks — sanity-test the actual responses
$runtimeChecks = [];

// Check recover-truora.php produces redirect with ?paso=
try {
    $url = 'https://' . $_SERVER['HTTP_HOST'] . '/configurador/recover-truora.php?t=invalid';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    // Token is invalid, but the file itself should produce expected error
    $runtimeChecks['recover_truora_reachable'] = [
        'ok' => $http > 0 && strpos((string)$body, 'recover-truora') !== false || $http >= 400,
        'http' => $http,
        'note' => 'Endpoint alcanzable (con token inválido, esperamos 400/403 — solo confirmamos que el archivo responde)',
    ];
} catch (Throwable $e) { $runtimeChecks['recover_truora_reachable'] = ['ok'=>false, 'error'=>$e->getMessage()]; }

// Stat the SPA bundle for cache-bust indicator
$spaJsPath = $base . '/configurador/js/configurador.js';
if (file_exists($spaJsPath)) {
    $runtimeChecks['spa_mtime'] = [
        'ok' => true,
        'mtime' => date('Y-m-d H:i:s', filemtime($spaJsPath)),
        'size_kb' => round(filesize($spaJsPath) / 1024, 1),
        'note' => 'Si esta fecha es nueva, el archivo se subió. Si el navegador no ve el cambio, hace falta Ctrl+Shift+R o purgar CDN.',
    ];
}

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

<h1>🚀 Verificación de despliegue — Round 14 + 15 + 16 + 17 (2026-05-13 / 2026-05-14)</h1>
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

  <?php if (isset($runtimeChecks['spa_mtime'])): ?>
    <div class="row">
      <div class="status ok">📅</div>
      <div class="desc">
        <div class="title">SPA configurador.js — última modificación</div>
        <div class="meta">
          <strong><?= htmlspecialchars($runtimeChecks['spa_mtime']['mtime']) ?></strong>
          (<?= $runtimeChecks['spa_mtime']['size_kb'] ?> KB) — <?= htmlspecialchars($runtimeChecks['spa_mtime']['note']) ?>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<h2>3. Pruebas manuales — checklist</h2>
<div class="card" style="font-size:13.5px;line-height:1.7;">
  <strong>Issue 1 — Link de Truora del email:</strong>
  <ul>
    <li>Pide al boss que mande otro link a un cliente con preaprobación.</li>
    <li>Cliente abre el email y da click en <strong>"Continuar verificación"</strong>.</li>
    <li>✅ Esperado: aterriza directo en <strong>verificación de identidad de Truora</strong> (foto INE + selfie), no en el selector de modelo.</li>
    <li>URL en la barra debe terminar en <code>?recovered=1&amp;paso=credito-identidad</code></li>
  </ul>

  <strong style="display:block;margin-top:14px;">Issue 2 — Mis compras duplicadas:</strong>
  <ul>
    <li>Pide al cliente con duplicados (la cliente del screenshot anterior) que entre a <code>voltika.mx/clientes/</code></li>
    <li>Click en <strong>Mis compras</strong> en el menú inferior.</li>
    <li>✅ Esperado: <strong>una sola card</strong> de M05 negro crédito (no dos).</li>
    <li>Si necesitas comparar — la API ahora devuelve <code>total_raw: 2</code> y <code>total: 1</code> mostrando que dedupeó.</li>
  </ul>

  <strong style="display:block;margin-top:14px;">Issue 3 — Excel CDC:</strong>
  <ul>
    <li>Login admin → sección <strong>Consultas Buró</strong> → click en <strong>Exportar Excel</strong>.</li>
    <li>✅ Esperado: 16 columnas en orden oficial NIP-CIEC PF (FOLIO_CDC, FECHA_APROBACION_DE_CONSULTA, …, ACEPTACION_TERMINOS_Y_CONDICIONES).</li>
    <li>Columna <strong>I</strong> debe llamarse <code>Estado</code> con <strong>e minúscula</strong> (no <code>ESTADO</code>).</li>
    <li><code>NOMBRE_CLIENTE</code> en formato <code>APELLIDO_PATERNO APELLIDO_MATERNO NOMBRES</code> MAYÚSCULAS.</li>
  </ul>

  <strong style="display:block;margin-top:14px;">Round 17 — Checklist drill-in (autor + fotos + timestamps):</strong>
  <ul>
    <li>Admin → cualquier orden con checklist (ej. K-2605-0002) → "Documentos del pedido" → desplegar la tarjeta <strong>"Checklist (origen · ensamble · entrega)"</strong>.</li>
    <li>Cada una de las 3 sub-tarjetas (Origen / Ensamble / Entrega) ahora muestra "📷 N fotos" + "por [Nombre]" + flecha <strong>"Ver detalle →"</strong>.</li>
    <li>Click en cualquier sub-tarjeta abre la vista detalle in-line con:
      <ul>
        <li>✅ <strong>Completado por</strong>: nombre del dealer + rol + punto (snapshot inmune a futuras ediciones).</li>
        <li>✅ <strong>Sello de tiempo (sistema)</strong>: freg, fmod, fecha_inicio, fecha_completado, fase1_fecha, fase2_fecha, …</li>
        <li>✅ <strong>Items del checklist</strong> (collapsable): cuántos / cuántos completados + listado individual con ✓ / ○.</li>
        <li>✅ <strong>Fotos</strong>: galería agrupada por categoría con thumbnails clicables (abren en nueva pestaña).</li>
        <li>Botón <strong>"← Volver"</strong> regresa al grid de 3 tarjetas.</li>
      </ul>
    </li>
  </ul>

  <strong style="display:block;margin-top:14px;">Round 16 — Dossier de Defensa (DATOS DE LA OPERACIÓN + ACEPTACIÓN ELECTRÓNICA):</strong>
  <ul>
    <li>Admin → orden K-2605-0002 → "Documentos del pedido" → <strong>Dossier de Defensa (ZIP)</strong> → descargar.</li>
    <li>Abrir el PDF <code>00_INDICE.pdf</code> dentro del ZIP.</li>
    <li>✅ <strong>"Cliente (nombre completo)"</strong>: ahora dice <code>Adrian Montoya Diaz</code> (sin duplicación).</li>
    <li>✅ <strong>"Modalidad de pago"</strong>: dice <code>CONTADO</code> (no <code>UNICO</code>).</li>
    <li>✅ Bloque <strong>"ACEPTACIÓN ELECTRÓNICA DEL CONTRATO"</strong>: IP / Geo / User-Agent / SHA-256 muestran texto explicativo (no <code>--</code>).</li>
    <li>✅ <strong>"Código OTP validado"</strong>: dice <code>Pendiente — modalidad contado (OTP final al momento de la entrega)</code> en lugar de <code>No</code>.</li>
  </ul>

  <strong style="display:block;margin-top:14px;">Round 15 — Contrato DÉCIMA SÉPTIMA (REGISTRO DE ACEPTACIÓN):</strong>
  <ul>
    <li>Cliente con duplicación de nombre ("Adrian Montoya Diaz Montoya Diaz") descarga su contrato de nuevo desde "Mis compras" → <strong>Descargar contrato</strong>.</li>
    <li>✅ Esperado: <strong>"Adrian Montoya Diaz"</strong> (sin duplicación) en la fila "Nombre de EL COMPRADOR".</li>
    <li>Si IP / Geolocalización / Dispositivo estaban vacíos, ahora deben mostrar texto explicativo <strong>(no <code>--</code>)</strong>.</li>
    <li>Nuevas filas en el REGISTRO: <strong>Firma electrónica avanzada (Acta de Entrega)</strong>, <strong>Folio Cincel</strong>, <strong>Estado Cincel</strong>, <strong>Constancia NOM-151-SCFI-2016</strong>, <strong>Valor probatorio</strong>.</li>
    <li>Antes de la firma del Acta: estas filas dicen "Pendiente — Acta de Entrega". Después de la firma: aparece el folio real de Cincel.</li>
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
