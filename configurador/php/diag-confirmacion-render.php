<?php
/**
 * Voltika — confirmation/portal email render diagnostic.
 *
 * Customer brief 2026-05-09 (first real credit sale): the confirmation
 * email rendered "\$" (literal backslash + dollar) with no figure, the
 * VOLTIKA logo was barely readable, and the footer logo was missing.
 * This page renders the post-purchase flow (compra_confirmada_credito_*
 * + portal_plazos) twice — once with a known weekly amount, once with
 * an empty one — so the fix can be visually verified without waiting
 * for another real credit sale.
 *
 * Usage:
 *   ?token=voltika_diag_2026
 *     → side-by-side render of all 4 emails (HTML in iframes).
 *
 *   ?token=voltika_diag_2026&raw=1&tipo=compra_confirmada_credito_punto&monto=1500.00
 *     → raw HTML for one template (used by the iframes above).
 *     → set monto= to '' to render the empty-amount fallback.
 *
 *   ?token=voltika_diag_2026&send=you@you.com
 *     → ALSO emails all 4 variants to the supplied address so you can
 *       see the rendering in a real mail client (iOS Mail, Gmail mobile,
 *       Outlook…). Sends 4 messages tagged [DIAG] in the subject so
 *       they're easy to find / delete.
 *
 * Read-only by default — no DB writes, no SMS / WhatsApp / cron triggered.
 *
 * DELETE THIS FILE AFTER VERIFICATION (`rm` it via FileZilla / SSH).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/voltika-notify.php';

if (($_GET['token'] ?? '') !== 'voltika_diag_2026') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "invalid token\n";
    exit;
}

$cases = [
    ['compra_confirmada_credito_punto',     '🎉 Confirmación crédito · CON punto',       '1,500.00'],
    ['compra_confirmada_credito_punto',     '🎉 Confirmación crédito · CON punto (sin monto)', ''],
    ['compra_confirmada_credito_sin_punto', '🎉 Confirmación crédito · SIN punto',       '1,500.00'],
    ['compra_confirmada_credito_sin_punto', '🎉 Confirmación crédito · SIN punto (sin monto)', ''],
    ['portal_plazos',                       '🔐 Portal acceso · Plazos',                  '1,500.00'],
    ['portal_plazos',                       '🔐 Portal acceso · Plazos (sin monto)',     ''],
];

function vkDiagSampleData(string $monto): array {
    return [
        'pedido'          => '1826-0001',
        'pedido_corto'    => 'VK-1826-0001',
        'nombre'          => 'Carlos Ricardo Sánchez',
        'modelo'          => 'M05',
        'color'           => 'negro',
        'punto'           => 'DJ Moctezuma',
        'ciudad'          => 'CDMX, Ciudad de México',
        'direccion_punto' => 'Norte 17 Núm. 135, Col. Moctezuma Alcaldía Venustiano Carranza, C.P: 15530, CDMX',
        'link_maps'       => 'https://maps.google.com/?q=DJ+Moctezuma',
        'fecha_estimada'  => '19/5/2026',
        'monto_semanal'   => $monto,
    ];
}

function vkDiagRender(string $tipo, string $monto): string {
    $tpls = voltikaNotifyTemplates();
    if (!isset($tpls[$tipo])) return '';
    $tpl  = $tpls[$tipo];
    $html = !empty($tpl['email_html']) ? $tpl['email_html'] : '';
    if ($html === '') return '';
    return voltikaNotifyInterpolate($html, vkDiagSampleData($monto));
}

// ── Raw HTML for a single template (iframe target) ─────────────────────────
if (!empty($_GET['raw']) && !empty($_GET['tipo'])) {
    $tipo  = (string)$_GET['tipo'];
    $monto = (string)($_GET['monto'] ?? '');
    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    echo vkDiagRender($tipo, $monto);
    exit;
}

// ── Optional send to a real address ────────────────────────────────────────
$sendTo  = trim((string)($_GET['send'] ?? ''));
$sendLog = [];
if ($sendTo !== '' && function_exists('sendMail')) {
    foreach ($cases as [$tipo, $label, $monto]) {
        $tpls = voltikaNotifyTemplates();
        if (!isset($tpls[$tipo])) continue;
        $tpl     = $tpls[$tipo];
        $data    = vkDiagSampleData($monto);
        $subject = '[DIAG] ' . voltikaNotifyInterpolate($tpl['subject'] ?? 'Voltika', $data);
        $html    = voltikaNotifyInterpolate($tpl['email_html'] ?? '', $data);
        $ok = false;
        try { $ok = (bool) @sendMail($sendTo, 'Voltika DIAG', $subject, $html); } catch (Throwable $e) { $sendLog[] = $e->getMessage(); }
        $sendLog[] = sprintf('%s %s | monto=%s → %s', $ok ? '✓' : '✗', $tipo, $monto !== '' ? $monto : '(empty)', $ok ? 'sent' : 'FAILED');
    }
}

// ── Debug snapshot of the deployed code path ──────────────────────────────
// Customer reported the logo still rendered as broken alt text after the
// base64 fix was uploaded. To pinpoint why, capture: (a) which
// voltika-notify.php is actually loaded, (b) whether voltikaEmailLogoSrc()
// exists in this build, (c) what it resolves to right now, (d) whether
// the PNG file is reachable on this filesystem.
$debug = [];
$debug['notify_path']      = (new ReflectionFunction('voltikaNotifyTemplates'))->getFileName();
$debug['notify_mtime']     = is_readable($debug['notify_path']) ? date('Y-m-d H:i:s', filemtime($debug['notify_path'])) : '(unreadable)';
$debug['logo_helper']      = function_exists('voltikaEmailLogoSrc') ? 'defined ✓' : 'MISSING ✗ (old build)';
$debug['logo_local_path']  = __DIR__ . '/../img/logo_w.png';
$debug['logo_local_real']  = realpath($debug['logo_local_path']) ?: '(not found)';
$debug['logo_readable']    = is_readable($debug['logo_local_path']) ? 'YES ✓' : 'NO ✗';
$debug['logo_size_bytes']  = is_readable($debug['logo_local_path']) ? filesize($debug['logo_local_path']) : 0;
$debug['logo_resolved']    = function_exists('voltikaEmailLogoSrc') ? voltikaEmailLogoSrc() : '(helper missing)';
$debug['logo_kind']        = strpos((string)$debug['logo_resolved'], 'data:') === 0 ? 'INLINE base64 ✓' : 'REMOTE URL (fallback)';
$debug['logo_resolved_preview'] = substr((string)$debug['logo_resolved'], 0, 90) . (strlen((string)$debug['logo_resolved']) > 90 ? '…' : '');
$debug['php_opcache']      = function_exists('opcache_get_status') && opcache_get_status(false) ? 'enabled (may need flush)' : 'disabled';

header('Content-Type: text/html; charset=utf-8');
$qs = function (array $p) { return '?' . http_build_query(array_merge(['token' => 'voltika_diag_2026'], $p)); };
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Diag · Confirmación email render</title>
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f3f4f6;color:#111;margin:0;padding:24px;}
  h1{font-size:20px;margin:0 0 6px;}
  p.lead{color:#555;margin:0 0 18px;font-size:14px;}
  .grid{display:grid;grid-template-columns:repeat(2,1fr);gap:18px;}
  .card{background:#fff;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,0.08);overflow:hidden;}
  .card h3{font-size:14px;margin:0;padding:10px 14px;background:#1a3a5c;color:#fff;font-weight:600;}
  .card .meta{font-size:12px;padding:6px 14px;background:#eef2f7;color:#475569;border-bottom:1px solid #e2e8f0;}
  .card iframe{width:100%;height:720px;border:0;display:block;background:#fff;}
  .send-form{background:#fff;border-radius:10px;padding:14px 18px;margin:0 0 18px;box-shadow:0 1px 3px rgba(0,0,0,0.08);font-size:14px;}
  .send-form input[type=email]{padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;width:300px;}
  .send-form button{padding:7px 14px;background:#039fe1;color:#fff;border:0;border-radius:6px;font-weight:600;cursor:pointer;}
  .log{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;background:#f1f5f9;padding:8px 12px;border-radius:6px;margin-top:10px;white-space:pre-wrap;}
  .warn{background:#fef3c7;border-left:4px solid #f59e0b;padding:10px 14px;border-radius:6px;font-size:13px;margin:0 0 18px;color:#92400e;}
</style>
</head>
<body>
  <h1>Voltika · Confirmation/Portal render diagnostic</h1>
  <p class="lead">Renderiza los 3 templates del flujo post-compra a crédito, con monto conocido y vacío, para verificar que la corrección del bug de <code>\$</code> y el fallback funcionan. Lee este archivo: <code>configurador/php/diag-confirmacion-render.php</code> — bórralo cuando termines.</p>

  <div class="warn">⚠️ Token de acceso visible en la URL — no compartas este enlace y borra el archivo después de verificar.</div>

  <div class="card" style="margin-bottom:18px;">
    <h3>🔎 Debug — deployed build snapshot</h3>
    <div class="log" style="margin:0;border-radius:0;">
<?php foreach ($debug as $k => $v): ?>
<?= sprintf('%-25s %s', $k, htmlspecialchars((string)$v)) . PHP_EOL ?>
<?php endforeach; ?>
    </div>
  </div>

  <form class="send-form" method="get">
    <input type="hidden" name="token" value="voltika_diag_2026">
    <label>Enviar las 6 variantes a un correo real para ver el render en cliente:&nbsp;</label>
    <input type="email" name="send" placeholder="tu@correo.com" value="<?= htmlspecialchars($sendTo) ?>" required>
    <button type="submit">Enviar [DIAG]</button>
    <?php if ($sendLog): ?>
      <div class="log"><?= htmlspecialchars(implode("\n", $sendLog)) ?></div>
    <?php endif; ?>
  </form>

  <div class="grid">
    <?php foreach ($cases as [$tipo, $label, $monto]): ?>
      <div class="card">
        <h3><?= htmlspecialchars($label) ?></h3>
        <div class="meta">tipo=<?= htmlspecialchars($tipo) ?> · monto_semanal=<?= $monto !== '' ? htmlspecialchars($monto) : '<em>(vacío — fallback)</em>' ?></div>
        <iframe src="<?= htmlspecialchars($qs(['raw' => 1, 'tipo' => $tipo, 'monto' => $monto])) ?>" loading="lazy"></iframe>
      </div>
    <?php endforeach; ?>
  </div>
</body>
</html>
