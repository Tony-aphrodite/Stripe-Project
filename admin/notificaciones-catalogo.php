<?php
/**
 * Catálogo completo de notificaciones del proceso de compra.
 *
 * Este documento es para revisión y edición del texto enviado al cliente
 * en cada etapa del proceso de compra (pedido → pago → logística → entrega → cobranza).
 *
 * Fuentes de verdad:
 *   - voltika-notify.php     → plantillas WhatsApp + SMS + cuerpo reutilizable
 *   - stripe-webhook.php     → email HTML de confirmación post-pago
 *   - create-payment-intent  → email recordatorio SPEI / OXXO
 */
require_once __DIR__ . '/php/bootstrap.php';
adminRequireAuth(['admin','cedis']);

// Load the runtime templates so this catalog stays in sync with production.
// The notify file lives in either configurador_prueba (prod) or
// configurador_prueba_test (test). Resolve the right one at runtime.
$notifyCandidates = [
    __DIR__ . '/../configurador_prueba/php/voltika-notify.php',
    __DIR__ . '/../configurador_prueba_test/php/voltika-notify.php',
];
$notifyPath = null;
foreach ($notifyCandidates as $p) {
    if (file_exists($p)) { $notifyPath = $p; break; }
}
if (!$notifyPath) {
    http_response_code(500);
    echo 'No se encontró voltika-notify.php en configurador_prueba/php/ ni en configurador_prueba_test/php/.';
    exit;
}
require_once $notifyPath;
if (!function_exists('voltikaNotifyTemplates')) {
    http_response_code(500);
    echo 'voltikaNotifyTemplates() no está definida en ' . htmlspecialchars($notifyPath);
    exit;
}
$templates = voltikaNotifyTemplates();

// Metadata for each template — when it fires, who gets it, which channels, source.
$catalog = [
    // ── 1. POST-COMPRA INMEDIATO ─────────────────────────────────────────────
    'compra_punto_definido' => [
        'etapa'      => '1. Confirmación de compra',
        'cuando'     => 'Cuando el cliente completa el pago y eligió un punto de entrega en el configurador.',
        'audiencia'  => 'Cliente',
        'canales'    => ['WhatsApp','SMS'],
        'origen'     => 'stripe-webhook.php → sendPurchaseWhatsApp()',
    ],
    'compra_punto_pendiente' => [
        'etapa'      => '1. Confirmación de compra',
        'cuando'     => 'Cuando el cliente completa el pago SIN haber elegido un punto (centro-cercano).',
        'audiencia'  => 'Cliente',
        'canales'    => ['WhatsApp','SMS'],
        'origen'     => 'stripe-webhook.php → sendPurchaseWhatsApp()',
    ],

    // ── 2. ACCESO AL PORTAL ─────────────────────────────────────────────────
    'portal_contado' => [
        'etapa'      => '2. Acceso al portal cliente',
        'cuando'     => 'Tras confirmación de compra de contado (tarjeta / SPEI / OXXO).',
        'audiencia'  => 'Cliente',
        'canales'    => ['WhatsApp','SMS'],
        'origen'     => 'stripe-webhook.php',
    ],
    'portal_msi' => [
        'etapa'      => '2. Acceso al portal cliente',
        'cuando'     => 'Tras confirmación de compra en MSI (meses sin intereses).',
        'audiencia'  => 'Cliente',
        'canales'    => ['WhatsApp','SMS'],
        'origen'     => 'stripe-webhook.php',
    ],
    'portal_plazos' => [
        'etapa'      => '2. Acceso al portal cliente',
        'cuando'     => 'Tras confirmación de compra en Plazos Voltika (crédito interno).',
        'audiencia'  => 'Cliente',
        'canales'    => ['WhatsApp','SMS'],
        'origen'     => 'cliente-credito.php',
    ],

    // ── 3. LOGÍSTICA Y ENTREGA ──────────────────────────────────────────────
    'punto_asignado' => [
        'etapa'      => '3. Logística',
        'cuando'     => 'CEDIS asigna o cambia el punto de entrega del cliente.',
        'audiencia'  => 'Cliente',
        'canales'    => ['WhatsApp','SMS'],
        'origen'     => 'admin-cedis.php → mover_punto',
        'nota'       => 'Legacy — en uso.',
    ],
    'moto_enviada' => [
        'etapa'      => '3. Logística',
        'cuando'     => 'CEDIS asigna una moto física a la orden y la despacha hacia el punto.',
        'audiencia'  => 'Cliente',
        'canales'    => ['WhatsApp','SMS'],
        'origen'     => 'admin/ventas/asignar-moto.php',
    ],
    'moto_en_punto' => [
        'etapa'      => '3. Logística',
        'cuando'     => 'El punto escanea el VIN y recibe físicamente la moto (recepcion/recibir.php).',
        'audiencia'  => 'Cliente',
        'canales'    => ['WhatsApp','SMS'],
        'origen'     => 'puntosvoltika/recepcion/recibir.php',
    ],
    'lista_para_recoger' => [
        'etapa'      => '4. Entrega',
        'cuando'     => 'El punto marca la moto "lista para entrega" con fecha de recolección.',
        'audiencia'  => 'Cliente',
        'canales'    => ['WhatsApp','SMS'],
        'origen'     => 'puntosvoltika/inventario/cambiar-estado.php',
    ],
    'otp_entrega' => [
        'etapa'      => '4. Entrega',
        'cuando'     => 'El cliente llega al punto. Se envía OTP de seguridad para validar identidad.',
        'audiencia'  => 'Cliente',
        'canales'    => ['SMS'],
        'origen'     => 'puntosvoltika/entrega/*',
    ],
    'acta_firmada' => [
        'etapa'      => '4. Entrega',
        'cuando'     => 'Cliente firma el ACTA DE ENTREGA digital.',
        'audiencia'  => 'Cliente',
        'canales'    => ['WhatsApp','SMS'],
        'origen'     => 'cliente portal → firmar-acta.php',
    ],
    'entrega_completada' => [
        'etapa'      => '4. Entrega',
        'cuando'     => 'Punto marca estado=entregada tras checklist y firma del cliente.',
        'audiencia'  => 'Cliente',
        'canales'    => ['WhatsApp','SMS'],
        'origen'     => 'puntosvoltika/entrega/*',
    ],
    'recepcion_incidencia' => [
        'etapa'      => '4. Entrega',
        'cuando'     => 'Cliente reporta incidencia durante la recepción.',
        'audiencia'  => 'Cliente',
        'canales'    => ['WhatsApp','SMS'],
        'origen'     => 'cliente portal',
    ],

    // ── 5. COBRANZA SEMANAL (Plazos Voltika) ────────────────────────────────
    'recordatorio_pago_2dias' => [
        'etapa'      => '5. Cobranza semanal',
        'cuando'     => '2 días antes del vencimiento del ciclo de pago.',
        'audiencia'  => 'Cliente (plazos)',
        'canales'    => ['WhatsApp','SMS'],
        'origen'     => 'cron/cobranza-diaria.php',
    ],
    'pago_vence_hoy' => [
        'etapa'      => '5. Cobranza semanal',
        'cuando'     => 'Día del vencimiento.',
        'audiencia'  => 'Cliente (plazos)',
        'canales'    => ['WhatsApp','SMS'],
        'origen'     => 'cron/cobranza-diaria.php',
    ],
    'pago_vencido_48h' => [
        'etapa'      => '5. Cobranza semanal',
        'cuando'     => '48h después del vencimiento sin pago.',
        'audiencia'  => 'Cliente (plazos)',
        'canales'    => ['WhatsApp','SMS'],
        'origen'     => 'cron/cobranza-diaria.php',
    ],
    'pago_vencido_96h' => [
        'etapa'      => '5. Cobranza semanal',
        'cuando'     => '96h después del vencimiento sin pago — tono crítico.',
        'audiencia'  => 'Cliente (plazos)',
        'canales'    => ['WhatsApp','SMS'],
        'origen'     => 'cron/cobranza-diaria.php',
    ],
    'incentivo_adelanto' => [
        'etapa'      => '5. Cobranza semanal',
        'cuando'     => 'Enviado periódicamente a clientes al corriente para fomentar adelantos.',
        'audiencia'  => 'Cliente (plazos)',
        'canales'    => ['WhatsApp','SMS'],
        'origen'     => 'cron/cobranza-diaria.php',
    ],
    'pago_recibido' => [
        'etapa'      => '5. Cobranza semanal',
        'cuando'     => 'Stripe confirma cobro automático o pago manual (OXXO/SPEI/adelanto).',
        'audiencia'  => 'Cliente (plazos)',
        'canales'    => ['WhatsApp','SMS'],
        'origen'     => 'stripe-webhook.php (invoice.paid)',
        'nota'       => 'Legacy — en uso activo.',
    ],
    'pago_vencido' => [
        'etapa'      => '5. Cobranza semanal',
        'cuando'     => 'Marcado manual de pago vencido desde admin.',
        'audiencia'  => 'Cliente (plazos)',
        'canales'    => ['WhatsApp','SMS'],
        'origen'     => 'legacy',
        'nota'       => 'Reemplazado por pago_vencido_48h/96h.',
    ],
    'recordatorio_pago' => [
        'etapa'      => '5. Cobranza semanal',
        'cuando'     => 'Recordatorio genérico.',
        'audiencia'  => 'Cliente (plazos)',
        'canales'    => ['WhatsApp','SMS'],
        'origen'     => 'legacy',
        'nota'       => 'Reemplazado por recordatorio_pago_2dias.',
    ],

    // ── 6. INTERNAS ─────────────────────────────────────────────────────────
    'credenciales_punto' => [
        'etapa'      => '0. Interno',
        'cuando'     => 'Se crea un dealer/admin/punto nuevo.',
        'audiencia'  => 'Dealer / Admin',
        'canales'    => ['WhatsApp','SMS'],
        'origen'     => 'admin-dealer-create.php',
    ],
    'admin_extras_placas' => [
        'etapa'      => '0. Interno',
        'cuando'     => 'Cliente agrega "Asesoría de placas" durante el checkout.',
        'audiencia'  => 'Admin Voltika',
        'canales'    => ['WhatsApp','SMS'],
        'origen'     => 'stripe-webhook.php',
    ],
    'admin_extras_seguro' => [
        'etapa'      => '0. Interno',
        'cuando'     => 'Cliente agrega seguro Quálitas durante el checkout.',
        'audiencia'  => 'Admin Voltika',
        'canales'    => ['WhatsApp','SMS'],
        'origen'     => 'stripe-webhook.php',
    ],
];

// Group templates by etapa for readable navigation
$byEtapa = [];
foreach ($catalog as $key => $meta) {
    if (!isset($templates[$key])) continue;
    $byEtapa[$meta['etapa']][$key] = $meta;
}
ksort($byEtapa);

// Helper — pretty print body preserving newlines, highlighting variables
function renderBodyHtml(string $body): string {
    $h = htmlspecialchars($body);
    $h = preg_replace('/\{([a-z0-9_]+)\}/i', '<span class="var">{$1}</span>', $h);
    return nl2br($h);
}
function extractVars(string $tpl): array {
    preg_match_all('/\{([a-z0-9_]+)\}/i', $tpl, $m);
    return array_values(array_unique($m[1] ?? []));
}
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Catálogo de notificaciones — Voltika</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
         max-width: 1200px; margin: 1.5rem auto; padding: 0 1.5rem; background: #f5f7fa; color: #1a3a5c; line-height: 1.55; }
  h1 { font-size: 1.6rem; margin: .5rem 0 .2rem; }
  .lead { color:#5b6b80; margin: 0 0 1.5rem; font-size: 14px; }
  .toc { background:#fff; padding: 16px 20px; border-radius: 10px; box-shadow:0 2px 10px rgba(0,0,0,.06); margin-bottom: 24px; font-size: 14px; }
  .toc h2 { margin:0 0 10px; font-size: 15px; color:#039fe1; letter-spacing:.5px; }
  .toc ul { margin:0; padding-left:20px; }
  .toc li { margin-bottom:2px; }
  .toc a { color:#1a3a5c; text-decoration:none; }
  .toc a:hover { color:#039fe1; }
  .etapa { background:#fff; border-radius:12px; padding:22px 26px; margin-bottom:26px; box-shadow:0 2px 10px rgba(0,0,0,.06); }
  .etapa h2 { margin:0 0 18px; font-size:18px; color:#039fe1; border-bottom:2px solid #e8eef5; padding-bottom:8px; letter-spacing:.3px; }
  .tpl { border:1px solid #e8eef5; border-radius:10px; padding:16px 18px; margin-bottom:16px; background:#fafbfd; }
  .tpl h3 { margin:0 0 4px; font-size:15px; color:#1a3a5c; display:flex; align-items:center; gap:10px; }
  .tpl .key { font-family: ui-monospace, Consolas, monospace; font-size: 11px; color:#039fe1; background:#E8F4FD; padding:2px 8px; border-radius:4px; }
  .meta { font-size:12px; color:#5b6b80; margin-bottom:10px; display:flex; flex-wrap:wrap; gap:12px; }
  .meta span strong { color:#1a3a5c; }
  .channel { display:inline-block; padding:1px 7px; border-radius:10px; font-size:10px; font-weight:700; letter-spacing:.3px; }
  .channel.email { background:#FDE7E9; color:#c41e3a; }
  .channel.sms { background:#E3F2FD; color:#0D47A1; }
  .channel.whatsapp { background:#E8F5E9; color:#1B5E20; }
  .block { background:#fff; border-radius:8px; padding:12px 14px; margin-top:8px; border:1px solid #eef2f7; }
  .block .label { font-size: 10px; font-weight:700; letter-spacing:.7px; color:#8395ab; text-transform:uppercase; margin-bottom:6px; }
  .subject { font-size:14px; font-weight:700; color:#1a3a5c; }
  .body { font-size:13px; color:#333; white-space: pre-wrap; font-family: ui-monospace, Consolas, monospace; line-height:1.7; }
  .vars { font-size:11px; color:#5b6b80; font-family: ui-monospace, Consolas, monospace; margin-top:6px; }
  .var { background:#FFF3E0; color:#E65100; padding:1px 5px; border-radius:3px; font-family: ui-monospace, Consolas, monospace; }
  .note { background:#FFFDE7; border-left:3px solid #FFC107; padding:8px 12px; font-size:12px; color:#705b00; margin-top:8px; border-radius:4px; }
  .email-html-box { max-height: 520px; overflow:auto; border:1px solid #e8eef5; border-radius:6px; margin-top:6px; background:#fff; }
  .email-html-box iframe { width:100%; height:520px; border:0; }
  details summary { cursor:pointer; font-size:12px; color:#039fe1; margin-top:6px; }
</style>
</head>
<body>

<h1>Catálogo de notificaciones — proceso de compra</h1>
<p class="lead">
  Todas las comunicaciones automáticas que el sistema envía al cliente (y algunas internas) durante el ciclo completo de compra.<br>
  Para editar un texto: modificar el archivo <code>configurador_prueba/php/voltika-notify.php</code> — función <code>voltikaNotifyTemplates()</code>.<br>
  Los valores entre llaves como <span class="var">{nombre}</span> son variables que el sistema reemplaza automáticamente antes de enviar.
</p>

<div class="toc">
  <h2>Índice</h2>
  <ul>
<?php foreach ($byEtapa as $etapa => $items): ?>
    <li><a href="#<?= htmlspecialchars(preg_replace('/[^a-z0-9]+/i','-', strtolower($etapa))) ?>"><?= htmlspecialchars($etapa) ?></a> (<?= count($items) ?>)</li>
<?php endforeach; ?>
    <li><a href="#email-confirmacion">Email HTML de confirmación post-pago</a></li>
    <li><a href="#email-spei-oxxo">Email recordatorio SPEI / OXXO</a></li>
  </ul>
</div>

<?php foreach ($byEtapa as $etapa => $items): ?>
<section class="etapa" id="<?= htmlspecialchars(preg_replace('/[^a-z0-9]+/i','-', strtolower($etapa))) ?>">
  <h2><?= htmlspecialchars($etapa) ?></h2>

  <?php foreach ($items as $key => $meta):
      $tpl = $templates[$key];
      $bodyText = $tpl['body']    ?? '';
      $subject  = $tpl['subject'] ?? '';
      $sms      = $tpl['sms']     ?? '';
      $vars     = extractVars($subject . ' ' . $bodyText . ' ' . $sms);
  ?>
  <div class="tpl">
    <h3>
      <?= htmlspecialchars($meta['cuando']) ?>
      <span class="key"><?= htmlspecialchars($key) ?></span>
    </h3>
    <div class="meta">
      <span><strong>Audiencia:</strong> <?= htmlspecialchars($meta['audiencia']) ?></span>
      <span><strong>Canales:</strong>
        <?php foreach ($meta['canales'] as $c): ?>
          <span class="channel <?= strtolower($c) ?>"><?= htmlspecialchars($c) ?></span>
        <?php endforeach; ?>
      </span>
      <span><strong>Origen:</strong> <code><?= htmlspecialchars($meta['origen']) ?></code></span>
    </div>

    <?php if (!empty($meta['nota'])): ?>
      <div class="note"><?= htmlspecialchars($meta['nota']) ?></div>
    <?php endif; ?>

    <div class="block">
      <div class="label">Asunto / Subject</div>
      <div class="subject"><?= renderBodyHtml($subject) ?></div>
    </div>

    <div class="block">
      <div class="label">Cuerpo WhatsApp / Body</div>
      <div class="body"><?= renderBodyHtml($bodyText) ?></div>
    </div>

    <?php if ($sms): ?>
    <div class="block">
      <div class="label">SMS</div>
      <div class="body"><?= renderBodyHtml($sms) ?></div>
    </div>
    <?php endif; ?>

    <?php if ($vars): ?>
    <div class="vars"><strong>Variables:</strong>
      <?php foreach ($vars as $v): ?><span class="var">{<?= htmlspecialchars($v) ?>}</span> <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</section>
<?php endforeach; ?>

<!-- ════════ EMAIL POST-PAGO (Stripe webhook) ════════ -->
<section class="etapa" id="email-confirmacion">
  <h2>Email HTML de confirmación post-pago</h2>
  <div class="tpl">
    <h3>Se envía inmediatamente tras el pago confirmado (tarjeta / SPEI / OXXO).
      <span class="key">sendConfirmationEmail</span>
    </h3>
    <div class="meta">
      <span><strong>Audiencia:</strong> Cliente</span>
      <span><strong>Canales:</strong> <span class="channel email">EMAIL</span></span>
      <span><strong>Origen:</strong> <code>configurador_prueba/php/stripe-webhook.php → sendConfirmationEmail()</code></span>
    </div>
    <div class="block">
      <div class="label">Asunto</div>
      <div class="subject">
        Si tiene punto definido: <em>Tu Voltika ya está en proceso 🚀 Orden #{pedido}</em><br>
        Si punto pendiente: <em>Tu Voltika está confirmada, Orden #{pedido}</em>
      </div>
    </div>
    <div class="block">
      <div class="label">Secciones del cuerpo (HTML rich email)</div>
      <ol style="margin:0;padding-left:20px;font-size:13px;color:#333;line-height:1.8;">
        <li>Header con logo Voltika (gradient azul navy → celeste)</li>
        <li>Saludo personalizado: <em>"Hola, {nombre} 👋"</em></li>
        <li><strong>DETALLE DE TU COMPRA</strong> — tabla con Cliente, Orden, Modelo, Color, Ciudad, Monto, Método de pago</li>
        <li><strong>PUNTO DE ENTREGA CONFIRMADO</strong> (si aplica) o <strong>¿QUÉ SIGUE?</strong> con 3 pasos de asignación</li>
        <li><strong>¿QUÉ SIGUE CON TU VOLTIKA?</strong> — 4 pasos: Preparación → Envío → Preparación en sitio → Entrega</li>
        <li><strong>ENTREGA SEGURA (IMPORTANTE)</strong> — número celular como llave, OTP, INE, confirmación de orden</li>
        <li><strong>INFORMACIÓN SOBRE TU PAGO</strong></li>
        <li><strong>CAMBIO DE DATOS</strong> — aviso sobre modificar teléfono/ciudad antes de asignar punto</li>
        <li><strong>SOPORTE Y ATENCIÓN</strong> — WhatsApp +52 55 1341 6370, correo redes@voltika.mx</li>
        <li>Términos, Aviso de Privacidad (enlaces a PDF)</li>
        <li>Footer con marca Voltika / Mtech Gears S.A. de C.V.</li>
      </ol>
      <p style="font-size:12px;color:#5b6b80;margin:8px 0 0;">
        Para ver el HTML completo del email con su diseño visual, abrir el archivo
        <code>configurador_prueba/php/stripe-webhook.php</code> a partir de la línea 214
        (función <code>sendConfirmationEmail()</code>). El HTML está integrado dentro
        de la función — modificar ahí el texto o diseño.
      </p>
    </div>
    <div class="vars"><strong>Variables:</strong>
      <span class="var">{nombre}</span>
      <span class="var">{pedido}</span>
      <span class="var">{modelo}</span>
      <span class="var">{color}</span>
      <span class="var">{ciudad}</span>
      <span class="var">{estado}</span>
      <span class="var">{total}</span>
      <span class="var">{methodLabel}</span>
      <span class="var">{punto_nombre}</span>
    </div>
  </div>
</section>

<!-- ════════ EMAIL RECORDATORIO SPEI / OXXO ════════ -->
<section class="etapa" id="email-spei-oxxo">
  <h2>Email recordatorio SPEI / OXXO</h2>
  <div class="tpl">
    <h3>Se envía al cliente que eligió SPEI u OXXO durante el checkout (antes de confirmar el pago).
      <span class="key">_sendReminderEmail</span>
    </h3>
    <div class="meta">
      <span><strong>Audiencia:</strong> Cliente</span>
      <span><strong>Canales:</strong> <span class="channel email">EMAIL</span></span>
      <span><strong>Origen:</strong> <code>configurador_prueba/php/create-payment-intent.php → _sendReminderEmail()</code></span>
    </div>
    <div class="block">
      <div class="label">Asunto / Contenido</div>
      <div class="body">Asunto: Completa tu pago — Voltika #&lt;pedido&gt;

Contenido: Tabla con datos para la transferencia SPEI (CLABE, banco, referencia)
o referencia OXXO con código de barras. Incluye contacto WhatsApp {whatsapp}
y enlace al portal voltika.mx/mi-cuenta.</div>
      <p style="font-size:12px;color:#5b6b80;margin:8px 0 0;">El HTML completo está en <code>create-payment-intent.php</code> a partir de la línea 21. La parte de texto editable más relevante es la tabla con los datos SPEI / OXXO y las instrucciones de pago.</p>
    </div>
    <div class="vars"><strong>Variables:</strong>
      <span class="var">{nombre}</span>
      <span class="var">{modelo}</span>
      <span class="var">{color}</span>
      <span class="var">{monto}</span>
      <span class="var">{metodo}</span>
      <span class="var">{clabe}</span>
      <span class="var">{oxxoRefs}</span>
      <span class="var">{whatsapp}</span>
    </div>
  </div>
</section>

<p style="color:#8395ab;font-size:12px;text-align:center;margin:32px 0;">
  Última generación: <?= date('Y-m-d H:i') ?>.<br>
  Para editar un texto, modificar el archivo de origen correspondiente. Los cambios se reflejarán aquí automáticamente en la próxima carga.
</p>

</body>
</html>
