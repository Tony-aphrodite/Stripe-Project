<?php
/**
 * Voltika Admin — Round 21 v4 (2026-05-14).
 *
 * Debug viewer for raw Truora API responses. Given a process_id (or a
 * verif_id), shows: (a) the cached payload from verificaciones_identidad.
 * raw_truora_payload, (b) every probe row from truora_fetch_log so we
 * can see which endpoints returned what, (c) a syntax-highlighted JSON
 * preview to spot photo URL fields by eye.
 *
 * GET  ?process_id=IDP...
 * GET  ?verif_id=N
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$pid     = trim((string)($_GET['process_id'] ?? ''));
$verifId = (int)($_GET['verif_id'] ?? 0);

$pdo = getDB();

$verif = null;
if ($verifId > 0) {
    $st = $pdo->prepare("SELECT * FROM verificaciones_identidad WHERE id = ? LIMIT 1");
    $st->execute([$verifId]);
    $verif = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($verif && $pid === '') $pid = (string)($verif['truora_process_id'] ?? '');
} elseif ($pid !== '') {
    $st = $pdo->prepare("SELECT * FROM verificaciones_identidad
                          WHERE truora_process_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$pid]);
    $verif = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$logs = [];
if ($pid !== '') {
    try {
        $st = $pdo->prepare("SELECT id, url, http_code, LEFT(response, 8000) AS response,
                                    curl_err, fetched_at
                               FROM truora_fetch_log
                              WHERE process_id = ?
                              ORDER BY id DESC LIMIT 30");
        $st->execute([$pid]);
        $logs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { /* table may not exist yet */ }
}

header('Content-Type: text/html; charset=UTF-8');
$esc = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8">
<title>Truora payload debug</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fb;color:#0c2340;padding:20px;max-width:1200px;margin:0 auto;}
h1{font-size:20px;margin:0 0 6px;}
h2{font-size:14px;margin:18px 0 8px;color:#0c2340;border-bottom:1px solid #cbd5e1;padding-bottom:4px;}
.sub{color:#64748b;font-size:13px;margin-bottom:18px;}
.box{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin-bottom:14px;}
pre{background:#0f172a;color:#94a3b8;padding:10px 12px;border-radius:6px;font-size:11.5px;line-height:1.5;overflow-x:auto;max-height:480px;white-space:pre-wrap;word-break:break-all;}
table{width:100%;border-collapse:collapse;}
th{text-align:left;background:#1a3a5c;color:#fff;padding:6px 8px;font-size:11px;}
td{padding:6px 8px;font-size:12px;border-bottom:1px solid #f1f5f9;vertical-align:top;}
.code{font-family:ui-monospace,monospace;font-size:11px;color:#475569;}
.ok{color:#16a34a;font-weight:700;}
.bad{color:#b91c1c;font-weight:700;}
input,select{padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;}
form{margin-bottom:16px;}
.hilite{background:#fef3c7;padding:0 2px;}
</style></head><body>

<h1>🔍 Truora payload debug</h1>
<div class="sub">Round 21 v4 — inspeccionar la respuesta cruda de Truora para entender por qué no llegaron fotos.</div>

<form method="get" class="box">
  <label style="font-size:12px;color:#64748b;">verif_id:</label>
  <input name="verif_id" value="<?= $esc($verifId ?: '') ?>" placeholder="69" style="width:80px;">
  <span style="margin:0 6px;color:#94a3b8;">o</span>
  <label style="font-size:12px;color:#64748b;">process_id:</label>
  <input name="process_id" value="<?= $esc($pid) ?>" placeholder="IDP..." style="width:340px;">
  <button type="submit" class="ad-btn primary" style="padding:6px 14px;background:#0ea5e9;color:#fff;border:0;border-radius:4px;cursor:pointer;">Consultar</button>
</form>

<?php if (!$pid && !$verifId): ?>
  <div class="box">Indica un <code>verif_id</code> o un <code>process_id</code> para empezar.</div>
<?php endif; ?>

<?php if ($verif): ?>
  <h2>📋 verificaciones_identidad row</h2>
  <div class="box">
    <div><strong>id:</strong> <?= $esc($verif['id']) ?> ·
         <strong>cliente:</strong> <?= $esc(trim(($verif['nombre'] ?? '') . ' ' . ($verif['apellidos'] ?? ''))) ?> ·
         <strong>teléfono:</strong> <?= $esc($verif['telefono'] ?? '') ?> ·
         <strong>email:</strong> <?= $esc($verif['email'] ?? '') ?></div>
    <div style="margin-top:6px;">
      <strong>truora_process_id:</strong> <span class="code"><?= $esc($verif['truora_process_id'] ?? '—') ?></span><br>
      <strong>truora_account_id:</strong> <span class="code"><?= $esc($verif['truora_account_id'] ?? '—') ?></span><br>
      <strong>truora_status:</strong> <?= $esc($verif['truora_status'] ?? '—') ?> ·
      <strong>approved:</strong> <?= $verif['approved'] === null ? '—' : ($verif['approved'] ? 'sí' : 'no') ?>
    </div>
    <?php if (!empty($verif['files_saved'])): ?>
      <div style="margin-top:6px;"><strong>files_saved:</strong> <span class="code"><?= $esc($verif['files_saved']) ?></span></div>
    <?php endif; ?>
  </div>

  <?php if (!empty($verif['raw_truora_payload'])): ?>
    <h2>💾 raw_truora_payload (Round 21 v2 cache)</h2>
    <div class="box">
      <?php
        $raw = $verif['raw_truora_payload'];
        $decoded = @json_decode($raw, true);
        $pretty = is_array($decoded) ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $raw;
        // Highlight any URL-shaped strings so admin can spot photo links by eye.
        $hi = preg_replace('#(https?://[^\s"\\\\]+)#i', '<span class="hilite">$1</span>', $esc($pretty));
      ?>
      <pre><?= $hi ?></pre>
    </div>
  <?php else: ?>
    <div class="box" style="color:#64748b;">Sin raw_truora_payload guardado. Ejecuta "🔄 Sync" desde el diagnóstico para poblarlo.</div>
  <?php endif; ?>
<?php endif; ?>

<?php if (!empty($logs)): ?>
  <h2>📜 truora_fetch_log — últimos 30 intentos para este process_id</h2>
  <table>
    <thead><tr><th>id</th><th>fetched_at</th><th>http</th><th>url</th><th>response (primeros 8 KB)</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $L): ?>
      <tr>
        <td><?= $esc($L['id']) ?></td>
        <td class="code"><?= $esc($L['fetched_at']) ?></td>
        <td>
          <?php $c = (int)$L['http_code']; ?>
          <?= $c>=200 && $c<300 ? '<span class="ok">'.$c.'</span>'
              : ($c>=400 ? '<span class="bad">'.$c.'</span>' : $c) ?>
        </td>
        <td class="code" style="max-width:380px;word-break:break-all;"><?= $esc($L['url']) ?></td>
        <td><pre style="max-height:160px;font-size:10.5px;"><?php
              $resp = (string)$L['response'];
              $deco = @json_decode($resp, true);
              $pp   = is_array($deco) ? json_encode($deco, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $resp;
              echo preg_replace('#(https?://[^\s"\\\\]+)#i', '<span class="hilite">$1</span>', $esc($pp));
            ?></pre></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php elseif ($pid): ?>
  <div class="box" style="color:#64748b;">Sin entradas en <code>truora_fetch_log</code> para este process_id.</div>
<?php endif; ?>

<div style="margin-top:18px;font-size:11px;color:#94a3b8;">
  💡 Las URLs amarillas en el JSON son candidatos a fotos. Si no ves ninguna, Truora no expuso URLs en este flow.
</div>
</body></html>
