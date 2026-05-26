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
// NOTE: Rounds 59–82 + Round 78 fueron desplegados y validados previamente —
// sus markers se removieron de este verificador para que el admin solo vea
// lo que sigue activo o en pruebas. Los archivos siguen en disco. Si
// necesitas re-validar algún round viejo, consulta git history de este
// archivo.
// ─────────────────────────────────────────────────────────────────────────
$checks = [
    // ── Round 83 (2026-05-26) — Real fix: regenerate PDF with signature ──
    'r83_acta_pdf_generator' => _checkFile(
        $base . '/clientes/php/acta-pdf-generator.php',
        'Round 83, 2026-05-26',
        'Round 83 — clientes/php/acta-pdf-generator.php: helper compartido generarActaPdf($pdo, $moto, $sigDataUrl, $ip). Construye el PDF de ACTA con FPDF, EMBEBE la imagen de la firma (PNG base64) en el espacio entre el nombre y la línea, y aplica voltikaActaSanitizeFullName() para deduplicar nombres como "Adrian Montoya Diaz Montoya Diaz". Usado por firmar-acta.php (SPA), firmar-acta-directa-guardar.php (Round 80) y regenerar-acta.php (admin).'
    ),
    'r83_firmar_acta_regen' => _checkFile(
        $base . '/clientes/php/entrega/firmar-acta.php',
        'Round 83 (2026-05-26)',
        'Round 83 — clientes/php/entrega/firmar-acta.php: después de guardar la firma, ahora llama generarActaPdf() para REGENERAR el PDF del ACTA con la firma embebida (antes solo guardaba el flag cliente_acta_firmada=1 sin tocar el PDF). El nuevo path se persiste y se sella con NOM-151. Corrige el bug donde el PDF mostraba línea de firma vacía aunque cliente_acta_firmada=1.'
    ),
    'r83_regenerar_acta_endpoint' => _checkFile(
        $base . '/admin/php/inventario/regenerar-acta.php',
        'Round 83, 2026-05-26',
        'Round 83 — admin/php/inventario/regenerar-acta.php: endpoint admin que regenera el ACTA PDF de cualquier moto, tomando la firma más reciente desde firmas_contratos (matched por email/teléfono). Aplica NOM-151 sobre el nuevo hash y actualiza inventario_motos.cincel_acta_pdf_path + cincel_acta_status. Fixea retroactivamente ACTAs con autograph_pending status (caso Adrian Montoya).'
    ),

    // ── Round 83 v2 (2026-05-26) — Hardening for sanitizer + signature regex ──
    'r83v2_acta_pdf_generator_hardened' => _checkFile(
        $base . '/clientes/php/acta-pdf-generator.php',
        'Round 83 v2 (2026-05-26)',
        'Round 83 v2 — clientes/php/acta-pdf-generator.php: dos fixes críticos descubiertos cuando la v1 regeneró el PDF de Adrian pero el nombre seguía duplicado y la firma seguía vacía. (1) Fuerza require_once de configurador/php/contrato-contado.php al cargar el módulo, para que contratoContadoSanitizeFullName() esté SIEMPRE disponible — antes voltikaActaSanitizeFullName() lo verificaba con function_exists() pero nada en el path de regen lo cargaba, así que el dedup real nunca corría. Fallback ahora tiene un collapseTail iterativo que sí elimina "Apellido Apellido" repetido. (2) El regex de la firma ahora acepta data:image/png, data:image/jpeg, data:image/jpg, y base64 raw sin prefijo — antes solo aceptaba PNG estricto, así que firmas guardadas en otros formatos se rechazaban y el PDF salía con línea vacía. Loguea cuando rechaza para diagnóstico.'
    ),

    // ── Round 84 (2026-05-26) — Mandatory entrega checklist enforcement ──
    'r84_checklist_mandatory_fields' => _checkFile(
        $base . '/puntosvoltika/php/entrega/checklist.php',
        'Round 84 (2026-05-26)',
        'Round 84 — puntosvoltika/php/entrega/checklist.php: hard-rechaza con HTTP 400 cualquier guardado de checklist con campos faltantes (los 16 items de F1+F2+F3 ahora son obligatorios). Antes el endpoint aceptaba payloads parciales en silencio y solo flipeaba los flags fase{N}_completada cuando ALL fields=1, lo que permitía a callers que bypasseaban la UI (devtools, harness legacy) avanzar el flujo con checklist incompleto. La respuesta de error incluye lista en español de qué items faltan. Customer brief Óscar: "put the next checklist mandatory all fields".'
    ),
    'r84_finalizar_checklist_gate' => _checkFile(
        $base . '/puntosvoltika/php/entrega/finalizar.php',
        'Round 84 (2026-05-26)',
        'Round 84 — puntosvoltika/php/entrega/finalizar.php: agrega gate que requiere checklist_entrega_v2.fase1/2/3_completada=1 (o legacy completado=1) ANTES de marcar inventario_motos.estado="entregada". Antes solo se verificaba cliente_acta_firmada + entregas.otp_verified — la moto podía entregarse con el checklist F1/F2/F3 en blanco, que es exactamente lo que produjo el caso Adrian (banner amarillo "Entrega cerrada sin checklist"). Ahora finalizar devuelve HTTP 409 con lista de fases pendientes si está incompleto.'
    ),

    // ── Round 85 (2026-05-26) — Sanitize duplicated names in portal cliente endpoints ──
    'r85_me_sanitize'           => _checkFile(
        $base . '/clientes/php/auth/me.php',
        'Round 85 (2026-05-26)',
        'Round 85 — clientes/php/auth/me.php: aplica contratoContadoSanitizeFullName() al construir nombre_completo. Corrige el saludo del portal del cliente que mostraba "¡Hola, Adrian Montoya Diaz Montoya Diaz!" — la duplicación venía de cómo clientes.nombre + apellido_paterno + apellido_materno se concatenan cuando datos legacy guardaron el nombre completo en `nombre` Y los apellidos separados también. El sanitizer canónico (Round 83 v2) hace tail-collapse y devuelve "Adrian Montoya Diaz". También limpia el campo `nombre` individual en la respuesta para que código downstream que lee c.nombre directamente vea la versión limpia.'
    ),
    'r85_login_sanitize'        => _checkFile(
        $base . '/clientes/php/auth/login-verify.php',
        'Round 85 (2026-05-26)',
        'Round 85 — clientes/php/auth/login-verify.php: aplica el mismo sanitizer canónico al construir nombre_completo justo después del login OTP. Sin este fix, la primera carga del portal post-login mostraba el nombre duplicado hasta que me.php se refrescaba (race condition que el cliente alcanzaba a ver).'
    ),
    'r85_perfil_sanitize'       => _checkFile(
        $base . '/clientes/php/cliente/perfil.php',
        'Round 85 (2026-05-26)',
        'Round 85 — clientes/php/cliente/perfil.php: aplica el mismo sanitizer canónico al endpoint que alimenta la pantalla "Mi Cuenta / Mi Perfil" del portal del cliente. Sin este fix, la pantalla de perfil seguía mostrando el nombre duplicado aunque el saludo de inicio ya estuviera limpio.'
    ),

    // ── Round 86 (2026-05-26) — Accept legacy tpago='unico' in portal lookups ──
    'r86_compras_unico'         => _checkFile(
        $base . '/clientes/php/cliente/compras.php',
        'Round 86 (2026-05-26)',
        'Round 86 — clientes/php/cliente/compras.php: agrega "unico" a la lista IN para que transacciones legacy con tpago="unico" (creadas antes de la normalización en stripe-webhook.php:598) aparezcan en "Mis compras". Caso Adrian Montoya (moto 147, pedido VK-2605-0002): pago único exitoso de $48,260 a través de Stripe pero invisible en el portal porque el filtro IN excluía "unico". También normaliza tpago al construir el campo `tipo` y `metodo` en la respuesta para que el SPA muestre "Contado" en vez de "UNICO".'
    ),
    'r86_estado_unico'          => _checkFile(
        $base . '/clientes/php/cliente/estado.php',
        'Round 86 (2026-05-26)',
        'Round 86 — clientes/php/cliente/estado.php: mismo fix en los 3 lookups de transacciones que alimentan el hero card de "Inicio". Sin esto, después de aplicar Round 86 a compras.php, la pantalla "Inicio" seguía mostrando "preparación" en vez del estado real de entrega para clientes con tpago="unico".'
    ),

    // ── Round 87 (2026-05-26) — Credit contract empty fields fix ──
    'r87_credito_contrato_full_data' => _checkFile(
        $base . '/configurador/js/modules/paso-credito-contrato.js',
        'Round 87 (2026-05-26)',
        'Round 87 — configurador/js/modules/paso-credito-contrato.js: añade precioContado y enganche al payload enviado a generar-contrato-pdf.php. Sin estos dos campos el backend (línea 249-250 de generar-contrato-pdf.php) recibía undefined y aplicaba el default floatval=0, produciendo un contrato de crédito con TODOS los montos en "$0.00 MXN" (caso Carlos Ricardo Sánchez, VIN R4WPDTA15T8000072, enganche real $12,065 pero el contrato firmado mostraba precio=$0, IVA=$0, total=$0). El cálculo correcto ya existía en credito.precioContado y credito.enganche — solo faltaba pasarlos en el JSON.'
    ),

    // ── Round 88 (2026-05-26) — Credit contract type misclassification fix ──
    'r88_pi_tpago_enganche_metadata' => _checkFile(
        $base . '/configurador/php/create-payment-intent.php',
        'Round 88 (2026-05-26)',
        'Round 88 — configurador/php/create-payment-intent.php: cuando el SPA marca el PI con tipo="enganche"/"credito" ($isEngancheFlow=true), el metadata.tpago se almacena como "enganche" SIN importar el método de pago elegido (card/oxxo/spei). Antes el fallback ($installments?"msi":$method) sobrescribía con "oxxo" para clientes de crédito que pagaban su enganche en OXXO. Resultado: stripe-webhook insertaba la transacción con tpago="oxxo" → confirmar-orden:775 evaluaba $esCredito=false → generaba el "Contrato de compraventa AL CONTADO" en vez del Carátula de crédito. Caso Leobardo Arreola (pedido VK-2605-0004, $14,478 enganche vía OXXO) recibió contrato CONTADO siendo cliente de crédito.'
    ),

    // ── Round 94 (2026-05-26) — Perfil.php inventario_motos fallback ──
    'r94_perfil_inventario_fallback' => _checkFile(
        $base . '/clientes/php/cliente/perfil.php',
        'Round 94 (2026-05-26)',
        'Round 94 — clientes/php/cliente/perfil.php: cuando no hay subscripcion_credito para el cliente, ahora consulta inventario_motos directamente (por cliente_id/email/teléfono) para alimentar el card "Mi Voltika" del Cuenta page. Sin esto, clientes contado como Adrian Montoya veían "Voltika / Color: — / Serie: —" en su tab de Cuenta porque perfil.php devolvía moto=null. Ahora se obtiene modelo/color/VIN reales desde inventario_motos.'
    ),

    // ── Round 93 (2026-05-26) — Contado view in Mi Voltika + Inicio ──
    'r93_mivoltika_contado_view' => _checkFile(
        $base . '/clientes/js/modules/mivoltika.js',
        'Round 93 (2026-05-26)',
        'Round 93 — clientes/js/modules/mivoltika.js: misma detección robusta de contado que Round 91/92 (3 señales: state.tipoPortal, state.activeCompra.tipo, presencia de estado.compra sin subscripción). Antes Adrian veía "Voltika / --- / Pendiente" porque renderCredito corría con e.subscripcion=null y caía a fallback strings. Ahora renderContado se ejecuta correctamente y muestra modelo/color/VIN reales.'
    ),
    'r93_inicio_contado_view' => _checkFile(
        $base . '/clientes/js/modules/inicio.js',
        'Round 93 (2026-05-26)',
        'Round 93 — clientes/js/modules/inicio.js: misma detección robusta de contado. Sin esto, el hero card de Inicio podría renderizar la versión de crédito ("Paga esta semana", "Adelanta pagos") para un cliente contado cuyo tipoPortal quedó stale en credito.'
    ),

    // ── Round 92 (2026-05-26) — Contado documents page fix ──
    'r92_documentos_contado_unlock' => _checkFile(
        $base . '/clientes/js/modules/documentos.js',
        'Round 92 (2026-05-26)',
        'Round 92 — clientes/js/modules/documentos.js: tres correcciones para que clientes contado puedan ver sus documentos. (1) Detección robusta de modo contado usando 3 señales (state.tipoPortal, state.activeCompra.tipo, presencia de estado.compra sin subscripción) — antes dependía solo de tipoPortal que se queda stale. (2) Agrega comprobante_contado, contrato_contado y acta_entrega al DOC_META_CONTADO y al keys list para que aparezcan en la pantalla. (3) Desbloquea cada doc según su condición real (pago=pagada → comprobante+contrato+confirmacion; moto=entregada → acta+factura+carta_factura) en lugar de depender del flag global DOCS_PROXIMAMENTE_MODE que mantenía todo bloqueado. Caso Adrian Montoya: contado $48,260, moto entregada, ACTA firmada, pero la pantalla Documentos mostraba TODO como Próximamente.'
    ),

    // ── Round 91 (2026-05-26) — Contado view for Mis Pagos ──
    'r91_pagos_contado_view' => _checkFile(
        $base . '/clientes/js/modules/pagos.js',
        'Round 91 (2026-05-26)',
        'Round 91 — clientes/js/modules/pagos.js: cuando la compra activa es CONTADO/MSI (no crédito), Mis Pagos renderiza una vista distinta: tarjeta verde "✓ Pagado al 100%", detalle de la transacción (modelo, monto, método de pago, fecha), e historial con UNA sola fila marcada Pagado. Antes mostraba el UI de crédito ("Pagado a la fecha $0", "0 de 0 pagos", "Avance 0%") porque pintaba el progreso semanal sin importar el tipo de compra — confuso para clientes contado como Adrian Montoya ($48,260 pagado en Stripe pero la pantalla decía $0).'
    ),

    // ── Round 89 (2026-05-26) — Library-mode guard on generar-contrato-pdf ──
    'r89_pdf_library_mode_guard' => _checkFile(
        $base . '/configurador/php/generar-contrato-pdf.php',
        'Round 89 (2026-05-26)',
        'Round 89 — configurador/php/generar-contrato-pdf.php: wrappea el handler HTTP top-level dentro de if(!defined("VOLTIKA_PDF_LIBRARY_MODE")) para que admin scripts puedan require_once el archivo sin disparar el flujo de generación-y-respuesta-JSON. Necesario por el 1-shot tool admin/php/inventario/regenerar-contrato-credito-once.php que necesita llamar generateContractPDF() programáticamente sin que el archivo intente leer php://input ni hacer echo+exit.'
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

<h2>3. Si algo no está OK</h2>
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
