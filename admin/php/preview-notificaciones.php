<?php
/**
 * Preview tool — render any voltikaNotify email template in the browser
 * with sample placeholder data so the visual design can be inspected
 * without actually sending email/WhatsApp/SMS.
 *
 *   /admin/php/preview-notificaciones.php
 *   /admin/php/preview-notificaciones.php?tipo=moto_enviada
 *   /admin/php/preview-notificaciones.php?tipo=moto_enviada&raw=1   (raw HTML)
 *
 * Admin-only. Read-only — touches no DB.
 */
require_once __DIR__ . '/bootstrap.php';
adminRequireAuth(['admin']);

// Resolve voltika-notify.php (test or prod)
$notifyPath = null;
foreach ([
    __DIR__ . '/../../configurador_prueba_test/php/voltika-notify.php',
    __DIR__ . '/../../configurador_prueba/php/voltika-notify.php',
] as $p) {
    if (is_file($p)) { $notifyPath = $p; break; }
}
if (!$notifyPath) {
    http_response_code(500);
    exit('voltika-notify.php no encontrado');
}
require_once $notifyPath;

// Sample data covering every placeholder used by any template
$sample = [
    'nombre'              => 'Pepe Lopez',
    'pedido_corto'        => 'VK-2604-0042',
    'pedido'              => '1776470613-fae6',
    'modelo'              => 'M05',
    'color'               => 'negro',
    'punto'               => 'Voltika QRO Centro',
    'ciudad'              => 'Querétaro',
    'direccion_punto'     => 'Av. Universidad 123, Centro CP 76000',
    'link_maps'           => 'https://www.google.com/maps/search/?api=1&query=20.5888,-100.3899',
    'fecha'               => '5 de mayo de 2026',
    'fecha_estimada'      => '5 de mayo de 2026',
    'fecha_llegada_punto' => '3 de mayo de 2026',
    'fecha_limite'        => '20 de mayo de 2026',
    'fecha_entrega'       => '5 de mayo de 2026 14:30',
    'fecha_reporte'       => '20 de abril de 2026 11:25',
    'numero_caso'         => 'CASO-20260420-0042',
    'mensoje'             => '',
    'mensaje'             => 'La batería se descarga muy rápido en menos de 30 km.',
    'vin'                 => 'VIN1234567890ABCD',
    'otp'                 => '478215',
    'monto'               => '850.00',
    'monto_semanal'       => '850.00',
    'fecha_vencimiento'   => '7 de mayo de 2026',
    'semana'              => '5',
    'proximo_pago'        => '14 de mayo de 2026',
    'payment_link'        => 'https://voltika.mx/clientes/?action=pay',
    'rol'                 => 'operador',
    'url'                 => 'voltika.mx/admin',
    'email'               => 'pepe@example.com',
    'password'            => 'TempPass-2026',
    'monto_semanal_label' => '$850.00',
    'monto_label'         => '$850.00',
];

$templates = voltikaNotifyTemplates();
$tipo = $_GET['tipo'] ?? '';
$raw  = !empty($_GET['raw']);

// Order keys with friendly labels for the sidebar
$ordered = [
    'compra_confirmada_contado_punto'     => '🎉 Compra confirmada — Contado · con punto',
    'compra_confirmada_contado_sin_punto' => '🎉 Compra confirmada — Contado · sin punto',
    'compra_confirmada_credito_punto'     => '🎉 Compra confirmada — Crédito · con punto',
    'compra_confirmada_credito_sin_punto' => '🎉 Compra confirmada — Crédito · sin punto',
    'portal_contado'                      => '🔐 Portal acceso — Contado',
    'portal_msi'                          => '🔐 Portal acceso — MSI',
    'portal_plazos'                       => '🔐 Portal acceso — Plazos',
    'credenciales_punto'                  => '🏪 Credenciales para punto',
    'punto_asignado'                      => '📍 A) Punto asignado',
    'moto_enviada'                        => '🚚 B) Moto enviada',
    'moto_recibida'                       => '🔧 C) Moto recibida',
    'moto_lista_entrega'                  => '✅ D) Moto lista para entrega',
    'otp_entrega'                         => '🔐 OTP entrega',
    'acta_firmada'                        => '✅ Acta firmada',
    'entrega_completada'                  => '✅ Entrega completada (alias)',
    'recepcion_incidencia'                => '⚠️ Incidencia recibida',
    'recordatorio_pago_2dias'             => '⏰ Cobranza M1 — 2 días antes',
    'pago_vence_hoy'                      => '🔔 Cobranza M2 — vence hoy',
    'pago_vencido_48h'                    => '⚠️ Cobranza M3 — 48h vencido',
    'pago_vencido_96h'                    => '🔴 Cobranza M4 — 96h vencido',
    'incentivo_adelanto'                  => '💡 Cobranza M5 — incentivo adelanto',
    'pago_recibido'                       => '✅ Cobranza M6 — pago recibido',
];

// If a tipo was selected and raw=1 → output ONLY the email_html (for iframe).
// bootstrap.php sets Content-Type: application/json by default, so we must
// override it here — otherwise the browser treats the iframe payload as JSON
// and shows a "Pretty-print" source view instead of rendering the email HTML.
if ($tipo && $raw) {
    if (!isset($templates[$tipo])) { http_response_code(404); exit('Plantilla no existe'); }
    if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
    $tpl = $templates[$tipo];
    $html = !empty($tpl['email_html']) ? $tpl['email_html'] : nl2br(htmlspecialchars($tpl['body'] ?? ''));
    echo voltikaNotifyInterpolate($html, $sample);
    exit;
}

$selected = $tipo && isset($templates[$tipo]) ? $tipo : 'moto_enviada';
$selTpl   = $templates[$selected] ?? null;

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Preview · Notificaciones Voltika</title>
<style>
  :root{ --p:#039fe1; --b:#1a3a5c; --g:#22c55e; }
  *{box-sizing:border-box}
  body{font-family:-apple-system,Segoe UI,Arial,sans-serif;background:#f5f7fa;color:#1a3a5c;margin:0;}
  .wrap{display:flex;min-height:100vh;}
  aside{width:280px;background:#fff;border-right:1px solid #e1e8ee;padding:18px 14px;overflow-y:auto;flex-shrink:0;}
  aside h1{font-size:16px;margin:0 0 4px;}
  aside .lead{font-size:11.5px;color:#666;margin:0 0 14px;line-height:1.5;}
  .nav{display:flex;flex-direction:column;gap:2px;}
  .nav a{display:block;padding:8px 10px;font-size:12.5px;color:#334;text-decoration:none;border-radius:6px;border:1px solid transparent;}
  .nav a:hover{background:#f5f7fa;}
  .nav a.active{background:var(--p);color:#fff;font-weight:700;}
  .nav .group-h{font-size:10.5px;font-weight:700;color:#888;letter-spacing:.6px;text-transform:uppercase;margin:14px 8px 4px;}
  main{flex:1;padding:18px 24px;overflow-y:auto;}
  .toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;}
  .toolbar h2{font-size:18px;margin:0;}
  .toolbar .meta{font-size:12px;color:#666;}
  .toolbar a.btn{background:var(--p);color:#fff;padding:7px 14px;border-radius:6px;text-decoration:none;font-size:12px;font-weight:700;}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
  @media(max-width:1100px){.grid{grid-template-columns:1fr;}}
  .col{background:#fff;border:1px solid #e1e8ee;border-radius:10px;padding:14px;}
  .col h3{font-size:13px;margin:0 0 10px;color:var(--p);text-transform:uppercase;letter-spacing:.4px;}
  iframe{width:100%;height:780px;border:0;border-radius:6px;background:#fff;}
  pre{background:#0f1722;color:#e2e8f0;border-radius:6px;padding:14px;font-size:11.5px;line-height:1.6;overflow:auto;max-height:780px;margin:0;white-space:pre-wrap;word-break:break-word;}
  .meta-row{display:flex;gap:10px;font-size:12px;color:#666;flex-wrap:wrap;margin-bottom:8px;}
  .pill{background:#f5f7fa;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;color:#555;}
  .pill.has{background:#ecfdf5;color:#0e8f55;}
  .pill.miss{background:#fef2f2;color:#c62828;}
  details{background:#fff;border:1px solid #e1e8ee;border-radius:8px;padding:10px 14px;margin-top:14px;}
  summary{cursor:pointer;font-weight:700;font-size:13px;color:var(--b);}
</style>
</head>
<body>

<div class="wrap">
  <aside>
    <h1>📨 Preview</h1>
    <p class="lead">Renderiza cualquier plantilla con datos de muestra. No envía nada.</p>
    <div class="nav">
      <?php
      $groups = [
        'Compra' => ['compra_confirmada_contado_punto','compra_confirmada_contado_sin_punto','compra_confirmada_credito_punto','compra_confirmada_credito_sin_punto'],
        'Portal' => ['portal_contado','portal_msi','portal_plazos','credenciales_punto'],
        'Logística' => ['punto_asignado','moto_enviada','moto_recibida','moto_lista_entrega'],
        'Entrega' => ['otp_entrega','acta_firmada','recepcion_incidencia'],
        'Cobranza' => ['recordatorio_pago_2dias','pago_vence_hoy','pago_vencido_48h','pago_vencido_96h','incentivo_adelanto','pago_recibido'],
      ];
      foreach ($groups as $gName => $keys):
      ?>
        <div class="group-h"><?= htmlspecialchars($gName) ?></div>
        <?php foreach ($keys as $k):
          if (!isset($ordered[$k])) continue;
          $cls = $k === $selected ? 'active' : '';
        ?>
          <a href="?tipo=<?= urlencode($k) ?>" class="<?= $cls ?>"><?= htmlspecialchars($ordered[$k]) ?></a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  </aside>

  <main>
    <?php if (!$selTpl): ?>
      <h2>Plantilla no encontrada</h2>
    <?php else: ?>
      <?php
        $subject  = voltikaNotifyInterpolate($selTpl['subject'] ?? '', $sample);
        $hasHtml  = !empty($selTpl['email_html']);
        $hasBody  = !empty($selTpl['body']);
        $hasSms   = !empty($selTpl['sms']);
      ?>
      <div class="toolbar">
        <div>
          <h2><?= htmlspecialchars($ordered[$selected] ?? $selected) ?></h2>
          <div class="meta">
            <strong>Subject:</strong> <?= htmlspecialchars($subject) ?>
          </div>
          <div class="meta-row" style="margin-top:6px;">
            <span class="pill <?= $hasHtml?'has':'miss' ?>">email_html: <?= $hasHtml?'sí':'NO' ?></span>
            <span class="pill <?= $hasBody?'has':'miss' ?>">body (WA): <?= $hasBody?'sí':'NO' ?></span>
            <span class="pill <?= $hasSms?'has':'miss' ?>">sms: <?= $hasSms?'sí':'NO' ?></span>
          </div>
        </div>
        <a class="btn" href="?tipo=<?= urlencode($selected) ?>&raw=1" target="_blank">Abrir HTML en pestaña nueva ↗</a>
      </div>

      <div class="grid">
        <div class="col">
          <h3>📧 Email · vista renderizada</h3>
          <?php if ($hasHtml): ?>
            <iframe src="?tipo=<?= urlencode($selected) ?>&raw=1"></iframe>
          <?php else: ?>
            <div style="padding:30px;text-align:center;color:#888;">Esta plantilla no tiene <code>email_html</code>. Solo se enviaría como WhatsApp/SMS.</div>
          <?php endif; ?>
        </div>

        <div class="col">
          <h3>💬 WhatsApp · body</h3>
          <pre><?= htmlspecialchars(voltikaNotifyInterpolate($selTpl['body'] ?? '(sin body)', $sample)) ?></pre>
        </div>

        <div class="col">
          <h3>📱 SMS</h3>
          <pre><?= htmlspecialchars(voltikaNotifyInterpolate($selTpl['sms'] ?? '(sin SMS)', $sample)) ?></pre>
        </div>

        <div class="col">
          <h3>🔧 Datos de muestra usados</h3>
          <pre><?php
            $shown = [];
            $allTxt = ($selTpl['subject'] ?? '') . ' ' . ($selTpl['body'] ?? '') . ' ' . ($selTpl['sms'] ?? '') . ' ' . ($selTpl['email_html'] ?? '');
            preg_match_all('/\{([a-z_]+)\}/i', $allTxt, $m);
            $usedKeys = array_unique($m[1] ?? []);
            sort($usedKeys);
            foreach ($usedKeys as $k) {
              $v = $sample[$k] ?? '(no definido en muestra)';
              echo htmlspecialchars(str_pad($k, 24)) . ' = ' . htmlspecialchars($v) . "\n";
            }
            if (!$usedKeys) echo '(esta plantilla no usa placeholders)';
          ?></pre>
        </div>
      </div>
    <?php endif; ?>
  </main>
</div>

</body>
</html>
