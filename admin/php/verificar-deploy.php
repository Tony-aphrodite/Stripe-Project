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
        'Diagnóstico Cincel Timestamp (Round 69, 2026-05-23)',
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

    // ── Round 71 (2026-05-23) — Cincel NOM-151 timestamp integration ──
    'r71_cincel_timestamp_module' => _checkFile(
        $base . '/configurador/php/cincel-timestamp.php',
        'Round 71, 2026-05-23',
        'Round 71 — configurador/php/cincel-timestamp.php: módulo de producción para timestamps NOM-151 de Cincel. Funciones: cincelGetJWT() con cache 4h, cincelTimestampExists() (GET público sin auth), cincelCreateTimestamp() (POST autenticado con auto-refresh), cincelGetOrCreateTimestamp() (end-to-end idempotente por hash), cincelEnsureSchema() y cincelSaveTimestamp() (persistencia en cincel_timestamps).'
    ),
    'r71_cincel_diagnostico_create' => _checkFile(
        $base . '/admin/php/diagnostico-cincel-timestamp-create.php',
        'Round 71, 2026-05-23',
        'Round 71 — admin/php/diagnostico-cincel-timestamp-create.php: ejecuta el flujo COMPLETO (JWT → check → POST). Lista PDFs de contratos en disco; el admin elige uno y prueba check (sin gasto) o create (1 crédito si nuevo). Confirma que el módulo está listo antes de hookearlo en confirmar-orden.'
    ),
    'r71_cincel_hook_confirmar_orden' => _checkFile(
        $base . '/configurador/php/confirmar-orden.php',
        'Round 71 (2026-05-23) — NOM-151 timestamp via Cincel',
        'Round 71 — configurador/php/confirmar-orden.php: después de generar el PDF del contrato CONTADO, llama cincelGetOrCreateTimestamp() y guarda el resultado en cincel_timestamps. Gateable con CINCEL_TIMESTAMP_ENABLED=0 si Cincel tiene outage. Toda excepción se loggea sin abortar el flujo de orden.'
    ),

    // ── Round 72 (2026-05-23) — Reintentar CDC para preap_id existente ──
    'r72_cdc_call_helper' => _checkFile(
        $base . '/configurador/php/cdc-call.php',
        'Round 72, 2026-05-23',
        'Round 72 — configurador/php/cdc-call.php: helper reusable para hacer consultas CDC desde código admin (sin pasar por consultar-buro.php). Expone cdcQueryPersona($persona) con firma + mTLS + retry + parseo. Funciones cdcAscii/cdcComputeRFC/cdcEstadoEnum/extractPreaprobacionData con function_exists() para coexistir con consultar-buro.php.'
    ),
    'r72_reconsultar_cdc_endpoint' => _checkFile(
        $base . '/admin/php/preaprobaciones/reconsultar-cdc.php',
        'Round 72, 2026-05-23',
        'Round 72 — admin/php/preaprobaciones/reconsultar-cdc.php: endpoint que recibe preap_id, carga los datos del solicitante, llama cdcQueryPersona() y actualiza score/circulo_source/pago_mensual_buro/dpd90_flag/dpd_max + pti_total. Devuelve fetched={score, circulo_source, person_found, …} para que el frontend recargue.'
    ),
    'r72_reintentar_cdc_button' => _checkFile(
        $base . '/admin/js/modules/admin-preaprobaciones.js',
        'Round 72 (2026-05-23)',
        'Round 72 — admin/js/modules/admin-preaprobaciones.js: botón "🔁 Reintentar consulta CDC" debajo del card de recomendación cuando circulo_source es estimado o cdc_sin_score. POSTea a reconsultar-cdc.php y recarga la lista al terminar.'
    ),

    // ── Round 73 (2026-05-24) — Unstick "Preparando documento…" en entrega ──
    'r73_acta_skip_cincel_ceremony' => _checkFile(
        $base . '/clientes/php/entrega/cincel-firma-acta.php',
        'Round 73 (2026-05-24) — Skip Cincel signature ceremony',
        'Round 73 — clientes/php/entrega/cincel-firma-acta.php: tras generar el PDF, retorna fallback_autograph inmediatamente (sin intentar auth a Cincel que tardaba hasta 30s y fallaba). Customer brief Round 71 (Óscar): "solo necesitamos el timestamp de Cincel". Persiste también cincel_acta_pdf_path para que firmar-acta.php pueda sellar el PDF luego.'
    ),
    'r73_acta_nom151_timestamp' => _checkFile(
        $base . '/clientes/php/entrega/firmar-acta.php',
        'Round 73 (2026-05-24) — Apply Cincel NOM-151 timestamp',
        'Round 73 — clientes/php/entrega/firmar-acta.php: después de guardar la firma autógrafa, llama cincelGetOrCreateTimestamp() sobre el PDF del acta y guarda el hash en cincel_acta_timestamp_hash. Idempotente por hash. Si Cincel falla, la entrega NO se bloquea (la firma se guarda igual).'
    ),
    'r73_entrega_js_wording' => _checkFile(
        $base . '/clientes/js/modules/entrega.js',
        'Round 73 (2026-05-24)',
        'Round 73 — clientes/js/modules/entrega.js: el mensaje del panel de firma autógrafa ya no dice "Cincel no está disponible". Ahora explica claramente que la firma se sella con timestamp NOM-151 a través de Cincel — sin alarmar al cliente.'
    ),

    // ── Round 74 (2026-05-25) — Stepper consistente cuando no hay punto ──
    'r74_estado_stepper_clamp' => _checkFile(
        $base . '/clientes/php/entrega/estado.php',
        'Round 74 (2026-05-25)',
        'Round 74 — clientes/php/entrega/estado.php: si punto_voltika_id es null, $estadoUi se clampa a "pendiente" (paso 1) sin importar otros flags. Evita la contradicción "Entregada ✓ + Asignando punto…" que aparecía cuando la data quedaba inconsistente (test seed o admin override).'
    ),
    'r74_v2_entrega_js_suppress' => _checkFile(
        $base . '/clientes/js/modules/entrega.js',
        'Round 74 v2 (2026-05-25)',
        'Round 74 v2 — clientes/js/modules/entrega.js: cuando no hay punto asignado, se oculta el card de Envío (que mostraba "Recibida en el punto" en verde) y el banner "Tu moto está en tránsito al punto de entrega". Aparece un solo mensaje amarillo claro: "Estamos asignando tu punto de entrega…". UI consistente con el stepper en paso 1 + badge "Asignando punto…".'
    ),

    // ── Round 75 (2026-05-25) — Retro-firma autógrafa del contrato ──
    'r75_solicitar_firma_endpoint' => _checkFile(
        $base . '/admin/php/ventas/solicitar-firma-contrato.php',
        'Round 75, 2026-05-25',
        'Round 75 — admin/php/ventas/solicitar-firma-contrato.php: endpoint admin que recibe transaccion_id, genera token de 40 hex, lo guarda en firma_contrato_requests (TTL 48h), envía link por email al cliente y devuelve copy_text listo para pegar en WhatsApp. Customer brief Óscar: "Is there any way to resend the signature request to the client".'
    ),
    'r75_firmar_contrato_page' => _checkFile(
        $base . '/clientes/firmar-contrato-retro.php',
        'Round 75, 2026-05-25',
        'Round 75 — clientes/firmar-contrato-retro.php: página pública (sin login) que valida el token, muestra los datos del contrato + canvas de firma con el dedo (touch+mouse, HiDPI). Submit POSTea a firmar-contrato-retro-guardar.php.'
    ),
    'r75_firmar_contrato_save' => _checkFile(
        $base . '/clientes/php/firmar-contrato-retro-guardar.php',
        'Round 75, 2026-05-25',
        'Round 75 — clientes/php/firmar-contrato-retro-guardar.php: backend del flujo retro. Guarda firma en firmas_contratos, regenera el PDF con autógrafa embebida (contratoContadoGenerate), aplica NOM-151 vía Cincel, actualiza transacciones (path/hash/cincel_timestamp_hash), marca el token como signed. Lock con FOR UPDATE para evitar doble firma.'
    ),

    // ── Round 76 (2026-05-25) — Auto cache-bust hardening ──
    'r76_app_js_first_visit_reload' => _checkFile(
        $base . '/clientes/js/app.js',
        'Round 76 (2026-05-25)',
        'Round 76 — clientes/js/app.js: en el primer arranque donde el localStorage no tiene vk_build_version NI vk_build_primed, se asume caché del navegador desconocido y se fuerza ONE reload (con primed=1 para no caer en loop). Cierra el agujero del v5 original donde clientes con JS pre-Round-70-v5 nunca disparaban auto-reload y se quedaban con "Preparando documento…" stuck.'
    ),

    // ── Round 77 (2026-05-25) — Surface "no recepción" + diagnóstico ──
    'r77_admin_inventario_recepcion_placeholder' => _checkFile(
        $base . '/admin/js/modules/admin-inventario.js',
        'Round 77 (2026-05-25)',
        'Round 77 — admin/js/modules/admin-inventario.js: si recepcion_punto no tiene fila para el moto, el card "Recepción en el punto" ya no desaparece silenciosamente. Ahora muestra el header siempre, con 3 estados distintos según moto.estado: ⚠ Inconsistencia (estado=recibida/entregada pero sin fila), ⏳ En tránsito, o — pendiente. Customer brief Óscar: "still not showing the checklist of reception in the admin dashboard".'
    ),
    'r77_diagnostico_checklists' => _checkFile(
        $base . '/admin/php/diagnostico-checklists-moto.php',
        'Round 77 diag, 2026-05-25',
        'Round 77 — admin/php/diagnostico-checklists-moto.php: dado un moto_id, vuelca el estado COMPLETO de cada checklist (recepcion_punto, checklist_origen, checklist_ensamble, checklist_entrega_v2, entregas, firmas_contratos, cincel_timestamps). El admin o boss puede ver de un vistazo qué tabla tiene fila y qué no — útil para reportar bugs específicos en vez de "no se ve nada".'
    ),

    // ── Round 79 (2026-05-25) — Fix asignar/desasignar data-sync bug ──
    'r79_asignar_moto_fk' => _checkFile(
        $base . '/admin/php/ventas/asignar-moto.php',
        'Round 79 (2026-05-25) — Direct FK on transacciones',
        'Round 79 — admin/php/ventas/asignar-moto.php: además de poblar inventario_motos.cliente_*, ahora también escribe transacciones.moto_id = ? para que Ventas pueda hacer JOIN directo (sin depender de string matching de pedido_num). Caso Leobardo: la asignación stamping VK-TX32 nunca era visible en Ventas porque listar.php no reconocía ese formato.'
    ),
    'r79_listar_prefer_fk' => _checkFile(
        $base . '/admin/php/ventas/listar.php',
        'Round 79 (2026-05-25) — Prefer the direct FK link',
        'Round 79 — admin/php/ventas/listar.php: el JOIN ahora prioriza m.id = t.moto_id (FK directo). Mantiene los 5 fallbacks legacy (pedido_corto, pedido, etc.) + agrega reconocimiento del formato sintético CONCAT(VK-TX, t.id) para recuperar huérfanos de asignaciones pre-Round-79.'
    ),
    'r79_desasignar_clear_fk' => _checkFile(
        $base . '/admin/php/ventas/desasignar-moto.php',
        'Round 79 (2026-05-25) — Also clear the direct FK link',
        'Round 79 — admin/php/ventas/desasignar-moto.php: añade limpieza simétrica de transacciones.moto_id = NULL cuando el admin desasigna una moto. Sin este paso quedarían filas con FK colgante apuntando a un moto ya liberado.'
    ),
    'r79_backfill_tool' => _checkFile(
        $base . '/admin/php/ventas/backfill-asignaciones.php',
        'Round 79, 2026-05-25',
        'Round 79 — admin/php/ventas/backfill-asignaciones.php: herramienta admin que encuentra TODAS las motos con pedido_num en formato sintético "VK-TX{id}" (asignaciones huérfanas de antes del fix). Las agrupa por transacción y permite Bindear (link FK) o Desasignar cada una con un click. Idempotente — correr múltiples veces es seguro. Caso Leobardo se resuelve aquí.'
    ),

    // ── Round 78 (2026-05-25) — Estado vs checklist consistency banner ──
    'r78_estado_inconsistencia_banner' => _checkFile(
        $base . '/admin/js/modules/admin-inventario.js',
        'Round 78 (2026-05-25)',
        'Round 78 — admin/js/modules/admin-inventario.js: al abrir el detalle de un moto, aparece un banner ROJO/AMARILLO arriba cuando moto.estado está adelantado de los checklists requeridos. Detecta 3 casos: (1) estado>=recibida sin recepcion_punto o con recepción.completado=0, (2) estado>=lista_para_entrega sin ensamble.completado=1, (3) estado=entregada sin entrega.completado=1. Surfacing inmediato — el admin ya no tiene que adivinar por qué la moto "está mal".'
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

  <strong style="display:block;margin-top:14px;">Round 79 — Fix asignar-moto bug + backfill huérfanos (Leobardo):</strong>
  <ul>
    <li>Bug: asignar-moto.php solo escribía cliente_* en inventario_motos. Ventas (listar.php) hacía JOIN por string matching de pedido_num. Cuando caía al fallback "VK-TX{id}", el JOIN nunca matcheaba → Ventas mostraba "Sin asignar" mientras CEDIS mostraba la moto asignada.</li>
    <li>Fix Round 79: asignar-moto.php ahora también escribe <code>transacciones.moto_id</code> directamente. listar.php prioriza ese FK + reconoce el formato VK-TX{id} como último recurso.</li>
    <li>Para huérfanos pre-Round-79: abrir <code>/admin/php/ventas/backfill-asignaciones.php?key=voltika_diag_2026</code>. Lista todas las motos con pedido_num="VK-TX{id}" agrupadas por transacción. Botón verde "Bindear" para fijar el FK, botón rojo "Desasignar" para liberar duplicados.</li>
    <li>Test: para Leobardo (txn 32), abrir el backfill → deberían aparecer 2 motos huérfanas marcadas como "VK-TX32" → elegir UNA para bindear, desasignar la otra → recargar Ventas → la fila ya muestra la moto asignada con su VIN.</li>
  </ul>

  <strong style="display:block;margin-top:14px;">Round 76 — Cache-bust automático en primera visita:</strong>
  <ul>
    <li>Antes: si <code>localStorage.vk_build_version</code> nunca había sido escrito, el v5 original NO forzaba reload — los clientes que tenían Voltika abierto antes de v5 quedaban atrapados con JS viejo (síntoma: "Preparando documento…" se cuelga porque su JS no entiende <code>fallback_autograph</code>).</li>
    <li>Ahora: en el primer arranque sin <code>vk_build_version</code>, se asume caché desconocido y se hace UN reload guiado por la flag <code>vk_build_primed</code> (no hay loop infinito).</li>
    <li>Test: DevTools → Application → Local Storage → borrar <code>vk_build_version</code> y <code>vk_build_primed</code> → recargar voltika.mx/clientes → debe recargar UNA vez sola para limpiar caché.</li>
    <li>Efecto secundario positivo: cuando shippeemos un nuevo Round (77, 78…), todos los clientes con cache pre-v5 se actualizarán automáticamente en su próxima visita sin intervención manual.</li>
  </ul>

  <strong style="display:block;margin-top:14px;">Round 75 — Solicitar firma autógrafa retroactiva a un cliente:</strong>
  <ul>
    <li>El cliente ya pagó pero su contrato no tiene firma autógrafa (compras de antes de 2026-05-23, o cualquier caso similar).</li>
    <li>Desde el admin, hacer POST a <code>/admin/php/ventas/solicitar-firma-contrato.php</code> con <code>{ transaccion_id: N }</code>.</li>
    <li>El sistema genera un token de 40 hex, lo guarda en <code>firma_contrato_requests</code> (TTL 48h), y envía el link al email del cliente. También devuelve <code>copy_text</code> listo para pegar en WhatsApp.</li>
    <li>Cliente abre el link (<code>voltika.mx/clientes/firmar-contrato-retro.php?token=…</code>) en su celular → ve sus datos + canvas de firma → firma con el dedo → click "Firmar".</li>
    <li>✅ Resultado esperado: banner verde "Tu firma quedó sellada con NOM-151" + link para descargar el contrato actualizado.</li>
    <li>Verificar en DB:
      <ul>
        <li><code>firma_contrato_requests.estado='signed'</code>, <code>signed_at</code> con fecha</li>
        <li><code>firmas_contratos</code> nueva fila con <code>firma_base64</code> y sha256</li>
        <li><code>transacciones.contrato_pdf_hash</code> y <code>cincel_timestamp_hash</code> actualizados</li>
        <li>Una fila nueva en <code>cincel_timestamps</code> con el nuevo hash + URLs de descarga</li>
      </ul>
    </li>
    <li>El PDF regenerado tendrá la sección "FIRMA AUTÓGRAFA DEL COMPRADOR" con el PNG embebido (Round 70 v2).</li>
  </ul>

  <strong style="display:block;margin-top:14px;">Round 74 — Stepper consistente cuando aún no hay punto:</strong>
  <ul>
    <li>Antes: si una moto tenía <code>estado='entregada'</code> o <code>cliente_acta_firmada=1</code> pero <code>punto_voltika_id IS NULL</code>, el portal mostraba <strong>paso 7 (Entregada) + badge amarillo "Asignando punto…"</strong> al mismo tiempo. Contradictorio.</li>
    <li>Ahora: si no hay punto asignado, el stepper se clampa al paso 1 (En tránsito) sin importar otros flags. El badge "Asignando punto…" sigue visible — eso le da contexto correcto al cliente.</li>
    <li>Test: abre el portal con un cliente cuyo moto tenga <code>punto_voltika_id = NULL</code> y verifica que el stepper esté en paso 1, no en paso 6/7.</li>
  </ul>

  <strong style="display:block;margin-top:14px;">Round 73 — "Preparando documento…" se desbloquea en la entrega:</strong>
  <ul>
    <li>Cliente entra a su portal y va a <strong>Entrega</strong> → <strong>Firmar ACTA</strong>.</li>
    <li>Click en <strong>Iniciar firma con Cincel</strong> → en ~1 segundo aparece el recuadro de firma autógrafa (antes se quedaba 30s en "Preparando documento…" y no avanzaba).</li>
    <li>Mensaje del recuadro debe decir: <em>"Firma con tu dedo en el recuadro de abajo. Tu firma se sellará con un timestamp NOM-151 a través de Cincel…"</em>. NO debe decir "Cincel no está disponible".</li>
    <li>Cliente firma con el dedo → server guarda la firma + aplica sello NOM-151 al PDF del ACTA → ACTA queda firmada.</li>
    <li>Verificar en DB: <code>inventario_motos.cincel_acta_timestamp_hash</code> tiene un sha256 de 64 chars, <code>cincel_acta_status='signed_with_timestamp'</code>.</li>
    <li>Una fila nueva en <code>cincel_timestamps</code> con el mismo hash + nom151_file/timestamp_file/bitcoin_file.</li>
  </ul>

  <strong style="display:block;margin-top:14px;">Round 72 — Reintentar CDC para casos con "CDC sin respuesta":</strong>
  <ul>
    <li>Abrir cualquier preaprobación que tenga la recomendación amarilla <em>"⚠ Recomendación del sistema: No se pudo consultar Círculo de Crédito"</em>.</li>
    <li>Debajo del card amarillo aparece un botón azul: <strong>🔁 Reintentar consulta CDC</strong>.</li>
    <li>Click → "Consultando CDC…" → en ~3-10s muestra "✓ CDC respondió con score real. Recomendación actualizada · FICO N · fuente=real".</li>
    <li>La lista se recarga sola y el mismo card amarillo se vuelve naranja/verde/rojo dependiendo del score real.</li>
    <li>Si CDC aún falla → "⚠ CDC no respondió correctamente: HTTP N". Vuelve a presionarlo cuando la conectividad esté restaurada.</li>
  </ul>

  <strong style="display:block;margin-top:14px;">Round 71 — Cincel NOM-151 timestamp en producción:</strong>
  <ul>
    <li><strong>1)</strong> Abrir <code>/admin/php/diagnostico-cincel-timestamp-create.php?key=voltika_diag_2026</code>.</li>
    <li>✅ La sección "Estado del JWT" debe estar verde — el módulo obtuvo token con las credenciales de <code>local-secrets.php</code>.</li>
    <li>✅ La lista muestra los PDFs de contratos disponibles. Click en <strong>Check</strong> de cualquiera → respuesta limpia (existe / no existe).</li>
    <li><strong>2)</strong> Click en <strong>Create</strong> de un PDF cuyo hash sepamos que es nuevo → pantalla de confirmación → "Sí, crear timestamp ahora".</li>
    <li>✅ Resultado: banner verde "Timestamp NOM-151 creado correctamente". Links de descarga para nom151/timestamp/bitcoin.</li>
    <li>✅ DB: fila en <code>cincel_timestamps</code> + actualización de <code>transacciones.cincel_timestamp_hash</code>.</li>
    <li><strong>3)</strong> Hacer una compra CONTADO real (o sandbox) → al confirmar la orden y generar el contrato PDF, la integración debe correr en automático y agregar un sello NOM-151 sin intervención manual.</li>
    <li>Para deshabilitar temporalmente (e.g. outage Cincel): poner <code>CINCEL_TIMESTAMP_ENABLED=0</code> en .env / local-secrets.</li>
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
