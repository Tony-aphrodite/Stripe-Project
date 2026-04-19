<?php
/**
 * Diagnostic page — fires the 4 logistics notifications against a test
 * email/phone, then shows the notificaciones_log rows they produced.
 *
 *   /admin/php/diagnostico-notificaciones.php
 *
 * Admin-only. Safe to run: uses fake VIN/pedido numbers and does not touch
 * transacciones or inventario tables.
 */
require_once __DIR__ . '/bootstrap.php';
adminRequireAuth(['admin']);

$error   = '';
$results = [];
$email    = trim($_POST['email']    ?? $_GET['email']    ?? '');
$telefono = trim($_POST['telefono'] ?? $_GET['telefono'] ?? '');
$nombre   = trim($_POST['nombre']   ?? $_GET['nombre']   ?? 'Cliente Prueba');
$action   = $_POST['action']        ?? $_GET['action']   ?? '';

$pdo = getDB();

function dnCountLogs(string $tipo, string $destino): int {
    try {
        $s = getDB()->prepare("SELECT COUNT(*) FROM notificaciones_log WHERE tipo=? AND destino=?");
        $s->execute([$tipo, $destino]);
        return (int)$s->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

function dnRecentLogs(string $tipo, string $destino, int $since): array {
    try {
        $s = getDB()->prepare("SELECT canal, destino, status, error, freg
                                 FROM notificaciones_log
                                WHERE tipo = ? AND destino = ? AND id > ?
                                ORDER BY id ASC");
        $s->execute([$tipo, $destino, $since]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

if ($action === 'send') {
    if (!$email && !$telefono) {
        $error = 'Ingresa al menos email o teléfono';
    } else {
        // Resolve notify helper
        $notifyPath = null;
        foreach ([
            __DIR__ . '/../../configurador_prueba_test/php/voltika-notify.php',
            __DIR__ . '/../../configurador_prueba/php/voltika-notify.php',
        ] as $p) {
            if (is_file($p)) { $notifyPath = $p; break; }
        }
        if (!$notifyPath) {
            $error = 'No se encontró voltika-notify.php en configurador_prueba ni _test';
        } else {
            try { require_once $notifyPath; }
            catch (Throwable $e) { $error = 'Error al incluir voltika-notify: ' . $e->getMessage(); }

            if (!$error && !function_exists('voltikaNotify')) {
                $error = 'voltikaNotify() no está definida después de include';
            }

            if (!$error) {
                // Shared sample data — rich enough to fill every template placeholder.
                $fechaEst   = function_exists('voltikaFormatFechaHuman')
                    ? voltikaFormatFechaHuman(date('Y-m-d', strtotime('+10 days'))) : '';
                $fechaLleg  = function_exists('voltikaFormatFechaHuman')
                    ? voltikaFormatFechaHuman(date('Y-m-d', strtotime('+5 days'))) : '';
                $fechaLim   = function_exists('voltikaFormatFechaHuman')
                    ? voltikaFormatFechaHuman(date('Y-m-d', strtotime('+15 days'))) : '';
                $testPedido = 'TEST-' . date('YmdHis');

                $baseData = [
                    'nombre'              => $nombre,
                    'pedido'              => $testPedido,
                    'modelo'              => 'M05',
                    'color'               => 'negro',
                    'punto'               => 'Punto Prueba Diagnóstico',
                    'ciudad'              => 'Ciudad de México',
                    'direccion_punto'     => 'Av. Insurgentes Sur 1234, Col. Centro CP 03000',
                    'link_maps'           => 'https://www.google.com/maps/search/?api=1&query=19.4326,-99.1332',
                    'fecha_estimada'      => $fechaEst,
                    'fecha_llegada_punto' => $fechaLleg,
                    'fecha_limite'        => $fechaLim,
                    'telefono'            => $telefono,
                    'email'               => $email,
                    'whatsapp'            => $telefono,
                    'cliente_id'          => null,
                ];

                $stages = [
                    ['punto_asignado',     '🎉 A) punto_asignado'],
                    ['moto_enviada',       '🚚 B) moto_enviada'],
                    ['moto_recibida',      '🔧 C) moto_recibida'],
                    ['moto_lista_entrega', '✅ D) moto_lista_entrega'],
                    // Batch 2 — OTP / acta / incidencia / cobranza
                    ['otp_entrega',               '🔐 OTP entrega'],
                    ['entrega_completada',        '✅ Acta firmada'],
                    ['recepcion_incidencia',      '⚠️ Incidencia'],
                    ['recordatorio_pago_2dias',   '⏰ Cobranza M1 (2 días antes)'],
                    ['pago_vence_hoy',            '🔔 Cobranza M2 (hoy)'],
                    ['pago_vencido_48h',          '⚠️ Cobranza M3 (48h)'],
                    ['pago_vencido_96h',          '🔴 Cobranza M4 (96h)'],
                    ['incentivo_adelanto',        '💡 Cobranza M5 (adelanto)'],
                    ['pago_recibido',             '✅ Cobranza M6 (recibido)'],
                ];

                // Extra fields required by the batch-2 templates
                $baseData['otp']           = '547821';
                $baseData['vin']           = 'VIN987654321987';
                $baseData['fecha_entrega'] = $fechaLleg;
                $baseData['fecha_reporte'] = date('Y-m-d H:i');
                $baseData['numero_caso']   = 'CASO-' . date('Ymd') . '-0001';
                $baseData['mensaje']       = 'La batería no carga al 100% (reporte de prueba)';
                $baseData['monto']         = '235.00';
                $baseData['monto_semanal'] = '235.00';
                $baseData['semana']        = '3';
                $baseData['proximo_pago']  = $fechaEst;
                $baseData['payment_link']  = 'https://voltika.mx/mi-cuenta';

                // Anchor id for "recent log" query — capture MAX(id) before sending.
                try {
                    $sinceId = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM notificaciones_log")->fetchColumn();
                } catch (Throwable $e) { $sinceId = 0; }

                foreach ($stages as [$tipo, $label]) {
                    $row = ['label' => $label, 'tipo' => $tipo];
                    try {
                        $ret = voltikaNotify($tipo, $baseData);
                        $row['returned'] = $ret;
                        $row['ok']       = true;
                    } catch (Throwable $e) {
                        $row['returned'] = ['error' => $e->getMessage()];
                        $row['ok']       = false;
                    }

                    // Collect log rows created for this stage during this run.
                    $row['logs'] = [];
                    foreach (['sms', 'whatsapp', 'email'] as $canal) {
                        $destino = $canal === 'email' ? $email : $telefono;
                        if (!$destino) continue;
                        $row['logs'] = array_merge($row['logs'], dnRecentLogs($tipo, $destino, $sinceId));
                    }
                    $results[] = $row;
                }
            }
        }
    }
}

// Last 20 log rows regardless of current run
$recent = [];
try {
    $recent = $pdo->query("SELECT id, tipo, canal, destino, status, error, freg
                             FROM notificaciones_log
                            ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Diagnóstico de notificaciones</title>
<style>
  body { font-family: -apple-system, Segoe UI, Arial, sans-serif; background:#f5f7fa; color:#1a3a5c; margin:0; padding:24px; }
  h1 { margin:0 0 6px; font-size:22px; }
  p.lead { color:#555; margin:0 0 18px; font-size:13px; }
  .card { background:#fff; border:1px solid #e1e8ee; border-radius:10px; padding:20px; max-width:960px; margin:0 auto 18px; box-shadow:0 1px 4px rgba(12,35,64,.04); }
  label { display:block; font-size:12px; font-weight:600; color:#334; margin:8px 0 4px; text-transform:uppercase; letter-spacing:.5px; }
  input[type=text], input[type=email] { width:100%; box-sizing:border-box; padding:10px 12px; border:1px solid #c9d4de; border-radius:7px; font-size:14px; }
  button { background:#039fe1; color:#fff; border:none; padding:11px 22px; border-radius:7px; font-size:14px; font-weight:700; cursor:pointer; margin-top:14px; }
  button:hover { background:#027bb2; }
  table { width:100%; border-collapse:collapse; font-size:13px; margin-top:10px; }
  th, td { text-align:left; padding:8px 10px; border-bottom:1px solid #eef2f5; vertical-align:top; }
  th { background:#f5f7fa; font-weight:700; font-size:12px; color:#334; }
  .ok { color:#0e8f55; font-weight:700; }
  .err { color:#c62828; font-weight:700; }
  .pill { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; }
  .pill.sent { background:#ecfdf5; color:#0e8f55; }
  .pill.error { background:#fef2f2; color:#c62828; }
  .pill.skip { background:#f5f7fa; color:#888; }
  pre { background:#f7fafc; border:1px solid #e1e8ee; border-radius:5px; padding:8px; font-size:11px; overflow:auto; margin:4px 0 0; max-height:120px; }
  .error-box { background:#fef2f2; border-left:4px solid #dc2626; padding:12px 14px; border-radius:6px; color:#7a0e1f; font-size:13px; margin-bottom:14px; }
  .hint { background:#fffbeb; border-left:4px solid #f59e0b; padding:10px 14px; border-radius:6px; font-size:12.5px; color:#7a4f08; margin:10px 0 0; line-height:1.5; }
  .stage-label { font-weight:800; font-size:15px; color:#1a3a5c; margin:14px 0 4px; }
</style>
</head>
<body>

<div class="card">
  <h1>🧪 Diagnóstico de notificaciones logísticas</h1>
  <p class="lead">Dispara las 4 etapas (A punto asignado · B moto enviada · C moto recibida · D moto lista) contra un contacto de prueba y muestra lo que quedó registrado en <code>notificaciones_log</code>.</p>

  <?php if ($error): ?>
    <div class="error-box"><strong>Error:</strong> <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="send">
    <label>Nombre</label>
    <input type="text" name="nombre" value="<?= htmlspecialchars($nombre) ?>" placeholder="Cliente Prueba">
    <label>Email (tu buzón de prueba)</label>
    <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" placeholder="tu-correo@ejemplo.com">
    <label>Teléfono (WhatsApp + SMS)</label>
    <input type="text" name="telefono" value="<?= htmlspecialchars($telefono) ?>" placeholder="+5215512345678 o 5512345678">
    <button type="submit">🚀 Disparar las 4 notificaciones</button>
  </form>

  <div class="hint">Ingresa al menos email <em>o</em> teléfono. Las 4 etapas se disparan en serie en ~1–2 segundos. Después revisa tu buzón y WhatsApp.</div>
</div>

<?php if ($results): ?>
<div class="card">
  <h1>📋 Resultado de este disparo</h1>
  <?php foreach ($results as $r): ?>
    <div class="stage-label"><?= htmlspecialchars($r['label']) ?> <span style="font-weight:400;color:#888;font-size:12px;">(<?= htmlspecialchars($r['tipo']) ?>)</span></div>
    <?php if (empty($r['logs'])): ?>
      <div class="pill skip">Sin filas registradas en notificaciones_log</div>
      <pre><?= htmlspecialchars(json_encode($r['returned'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre>
    <?php else: ?>
      <table>
        <thead><tr><th>Canal</th><th>Destino</th><th>Estado</th><th>Error</th><th>Fecha</th></tr></thead>
        <tbody>
          <?php foreach ($r['logs'] as $lg): ?>
            <tr>
              <td><?= htmlspecialchars($lg['canal']) ?></td>
              <td><?= htmlspecialchars($lg['destino']) ?></td>
              <td><span class="pill <?= $lg['status']==='sent'?'sent':'error' ?>"><?= htmlspecialchars($lg['status']) ?></span></td>
              <td><?= htmlspecialchars($lg['error'] ?? '') ?></td>
              <td><?= htmlspecialchars($lg['freg']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
  <h1>📜 Últimas 20 filas de <code>notificaciones_log</code></h1>
  <?php if (!$recent): ?>
    <p class="lead">La tabla está vacía o no existe todavía.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>ID</th><th>Tipo</th><th>Canal</th><th>Destino</th><th>Estado</th><th>Error</th><th>Fecha</th></tr></thead>
      <tbody>
        <?php foreach ($recent as $lg): ?>
          <tr>
            <td><?= (int)$lg['id'] ?></td>
            <td><?= htmlspecialchars($lg['tipo']) ?></td>
            <td><?= htmlspecialchars($lg['canal']) ?></td>
            <td><?= htmlspecialchars($lg['destino']) ?></td>
            <td><span class="pill <?= $lg['status']==='sent'?'sent':'error' ?>"><?= htmlspecialchars($lg['status']) ?></span></td>
            <td style="max-width:260px;word-break:break-word;"><?= htmlspecialchars($lg['error'] ?? '') ?></td>
            <td><?= htmlspecialchars($lg['freg']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

</body>
</html>
