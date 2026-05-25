<?php
/**
 * Voltika Admin — Diagnóstico de checklists por moto (Round 77 diag, 2026-05-25).
 *
 * Customer brief (Óscar via Tony): "still not showing the checklist of
 * reception in the admin dashboard, and also we can't check the checklist
 * of delivery of the motorcycle." Without the actual screen / moto_id
 * being affected, we can't pinpoint the bug.
 *
 * This page dumps EVERY row from every checklist-adjacent table for a
 * given moto in one view, so the admin (or boss) can see exactly what
 * exists vs what's missing.
 *
 * URL: /admin/php/diagnostico-checklists-moto.php?key=voltika_diag_2026&moto_id=N
 *
 * Tables surveyed:
 *   • inventario_motos          — the moto itself + estado + flags
 *   • recepcion_punto           — punto received the moto from CEDIS (the
 *                                 "checklist of reception")
 *   • checklist_origen          — CEDIS prep
 *   • checklist_ensamble        — punto assembly
 *   • checklist_entrega_v2      — customer delivery checklist
 *   • entregas                  — OTP + finalize_at + status
 *   • firmas_contratos          — customer autograph signatures (Round 70 v1)
 *   • cincel_timestamps         — NOM-151 stamps (Round 71)
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$adminId = adminRequireAuth(['admin']);

$expected = 'voltika_diag_2026';
$key = $_GET['key'] ?? '';
if (!hash_equals($expected, (string)$key)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Acceso denegado. Usa ?key=voltika_diag_2026";
    exit;
}

$motoId = (int)($_GET['moto_id'] ?? 0);
$pdo = getDB();

$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

function _tableExists(PDO $pdo, string $name): bool {
    try {
        return (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($name))->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function _fetchOne(PDO $pdo, string $table, string $sql, array $params): array {
    if (!_tableExists($pdo, $table)) {
        return ['present' => false, 'reason' => 'tabla no existe', 'row' => null];
    }
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        return ['present' => $row !== null, 'reason' => $row !== null ? 'OK' : 'sin fila para este moto', 'row' => $row];
    } catch (Throwable $e) {
        return ['present' => false, 'reason' => 'SQL error: ' . $e->getMessage(), 'row' => null];
    }
}

function _fetchAll(PDO $pdo, string $table, string $sql, array $params): array {
    if (!_tableExists($pdo, $table)) {
        return ['present' => false, 'reason' => 'tabla no existe', 'rows' => []];
    }
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return ['present' => count($rows) > 0, 'reason' => count($rows) . ' fila(s)', 'rows' => $rows];
    } catch (Throwable $e) {
        return ['present' => false, 'reason' => 'SQL error: ' . $e->getMessage(), 'rows' => []];
    }
}

// Recent motos for quick picker
$candidates = [];
try {
    $candidates = $pdo->query("SELECT id, vin, modelo, color, estado, cliente_nombre, freg
       FROM inventario_motos
       WHERE activo = 1
       ORDER BY id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// Load all checklist rows for the selected moto.
$moto = null;
$rep = [];
if ($motoId > 0) {
    $moto = _fetchOne($pdo, 'inventario_motos',
        "SELECT * FROM inventario_motos WHERE id = ? LIMIT 1", [$motoId]);
    $rep['recepcion_punto'] = _fetchOne($pdo, 'recepcion_punto',
        "SELECT * FROM recepcion_punto WHERE moto_id = ? ORDER BY id DESC LIMIT 1", [$motoId]);
    $rep['checklist_origen'] = _fetchOne($pdo, 'checklist_origen',
        "SELECT * FROM checklist_origen WHERE moto_id = ? ORDER BY id DESC LIMIT 1", [$motoId]);
    $rep['checklist_ensamble'] = _fetchOne($pdo, 'checklist_ensamble',
        "SELECT * FROM checklist_ensamble WHERE moto_id = ? ORDER BY id DESC LIMIT 1", [$motoId]);
    $rep['checklist_entrega_v2'] = _fetchOne($pdo, 'checklist_entrega_v2',
        "SELECT * FROM checklist_entrega_v2 WHERE moto_id = ? ORDER BY id DESC LIMIT 1", [$motoId]);
    $rep['entregas'] = _fetchOne($pdo, 'entregas',
        "SELECT * FROM entregas WHERE moto_id = ? ORDER BY id DESC LIMIT 1", [$motoId]);
    $rep['firmas_contratos'] = _fetchAll($pdo, 'firmas_contratos',
        "SELECT id, email, telefono, LENGTH(firma_base64) AS sig_bytes, firma_sha256, freg
           FROM firmas_contratos
           WHERE telefono = (SELECT cliente_telefono FROM inventario_motos WHERE id = ?)
              OR email    = (SELECT cliente_email    FROM inventario_motos WHERE id = ?)
           ORDER BY id DESC LIMIT 5", [$motoId, $motoId]);
    $rep['cincel_timestamps'] = _fetchAll($pdo, 'cincel_timestamps',
        "SELECT id, pdf_hash_sha256, nom151_file, freg
           FROM cincel_timestamps
           WHERE transaccion_id IN (SELECT transaccion_id FROM inventario_motos WHERE id = ?)
           ORDER BY id DESC LIMIT 10", [$motoId]);
}

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Voltika — Diagnóstico Checklists por Moto</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fb;color:#0c2340;padding:24px;max-width:1180px;margin:0 auto;line-height:1.55;}
h1{font-size:22px;margin:0 0 6px;}
h2{font-size:14px;color:#475569;margin:22px 0 8px;text-transform:uppercase;letter-spacing:.4px;}
.muted{color:#94a3b8;font-size:12px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:12px;}
.card.ok    {border-left:4px solid #16a34a;}
.card.bad   {border-left:4px solid #dc2626;}
.card.warn  {border-left:4px solid #d97706;}
.card.empty {border-left:4px solid #94a3b8;}
table{border-collapse:collapse;width:100%;font-size:12.5px;}
th,td{border-bottom:1px solid #e2e8f0;padding:6px 8px;text-align:left;vertical-align:top;}
th{background:#f1f5f9;color:#475569;font-weight:600;font-size:11.5px;text-transform:uppercase;letter-spacing:.4px;}
tr:hover td{background:#f8fafc;}
.kv{display:grid;grid-template-columns:240px 1fr;gap:4px 12px;font-size:12.5px;}
.kv .k{color:#64748b;}
.kv .v{font-weight:600;color:#0c2340;word-break:break-all;}
.kv .v.null{color:#94a3b8;font-weight:400;font-style:italic;}
code{background:#1e293b;color:#e2e8f0;padding:2px 6px;border-radius:3px;font-size:11px;font-family:ui-monospace,monospace;word-break:break-all;}
pre{background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px;font-size:11px;white-space:pre-wrap;word-break:break-all;max-height:280px;overflow:auto;margin:6px 0;}
.banner{padding:10px 12px;border-radius:8px;font-size:13px;margin-bottom:10px;font-weight:600;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.banner-bad{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
.banner-warn{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;}
.banner-empty{background:#f1f5f9;border:1px solid #cbd5e1;color:#475569;}
a.btn-pick{display:inline-block;padding:5px 10px;border-radius:6px;font-size:11.5px;font-weight:600;text-decoration:none;background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;}
.section-status{display:inline-block;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700;}
.st-ok    {background:#dcfce7;color:#166534;}
.st-empty {background:#f1f5f9;color:#475569;}
.st-bad   {background:#fee2e2;color:#991b1b;}
.st-warn  {background:#fff7ed;color:#9a3412;}
</style></head><body>

<h1>📋 Diagnóstico de checklists por moto</h1>
<div class="muted"><?= $h(date('Y-m-d H:i:s')) ?> · Servidor: <?= $h($_SERVER['HTTP_HOST'] ?? '') ?></div>

<h2>1. Elige un moto</h2>
<div class="card">
  <?php if (!$candidates): ?>
    <div class="banner banner-warn">No se encontraron motos en inventario_motos. Ingresa el id manualmente.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>id</th><th>VIN</th><th>Modelo</th><th>Estado</th><th>Cliente</th><th>Acción</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($candidates, 0, 15) as $c): ?>
        <tr>
          <td><code><?= (int)$c['id'] ?></code></td>
          <td><code style="font-size:10px;"><?= $h($c['vin'] ?? '—') ?></code></td>
          <td><?= $h(($c['modelo'] ?? '') . ' · ' . ($c['color'] ?? '')) ?></td>
          <td><?= $h($c['estado'] ?? '—') ?></td>
          <td><?= $h($c['cliente_nombre'] ?? '—') ?></td>
          <td><a class="btn-pick" href="?key=<?= urlencode($expected) ?>&moto_id=<?= (int)$c['id'] ?>">Inspeccionar</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <form method="get" style="margin-top:10px;display:flex;gap:8px;align-items:center;font-size:13px;">
    <input type="hidden" name="key" value="<?= $h($expected) ?>">
    <label>O ingresa moto_id manualmente:</label>
    <input type="number" name="moto_id" value="<?= (int)$motoId ?>" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;width:140px;">
    <button type="submit" style="cursor:pointer;border:1px solid #cbd5e1;background:#f1f5f9;color:#475569;padding:6px 12px;border-radius:6px;">Cargar</button>
  </form>
</div>

<?php if ($motoId > 0 && $moto && $moto['present']): ?>
  <h2>2. Resumen</h2>
  <div class="card">
    <div class="kv">
      <span class="k">moto_id</span><span class="v"><?= (int)$moto['row']['id'] ?></span>
      <span class="k">VIN</span><span class="v"><?= $h($moto['row']['vin'] ?? '—') ?></span>
      <span class="k">Modelo · color</span><span class="v"><?= $h(($moto['row']['modelo'] ?? '') . ' · ' . ($moto['row']['color'] ?? '')) ?></span>
      <span class="k">Estado</span><span class="v"><?= $h($moto['row']['estado'] ?? '—') ?></span>
      <span class="k">Punto asignado</span><span class="v"><?= $h($moto['row']['punto_voltika_id'] ?? '—') ?></span>
      <span class="k">Cliente</span><span class="v"><?= $h($moto['row']['cliente_nombre'] ?? '—') ?></span>
      <span class="k">cliente_telefono</span><span class="v"><?= $h($moto['row']['cliente_telefono'] ?? '—') ?></span>
      <span class="k">cliente_email</span><span class="v"><?= $h($moto['row']['cliente_email'] ?? '—') ?></span>
      <span class="k">cliente_acta_firmada</span><span class="v"><?= !empty($moto['row']['cliente_acta_firmada']) ? '✓ sí' : '— no' ?></span>
      <span class="k">cincel_acta_status</span><span class="v"><?= $h($moto['row']['cincel_acta_status'] ?? '—') ?></span>
    </div>
  </div>

  <h2>3. Estado de cada checklist</h2>

  <?php
    $stages = [
      'recepcion_punto'       => ['titulo' => '🧰 Recepción en el punto', 'descripcion' => 'Cuando el punto recibe la moto desde CEDIS — la "checklist de recepción".'],
      'checklist_origen'      => ['titulo' => '🏭 Checklist Origen (CEDIS)', 'descripcion' => 'Preparación en CEDIS antes de enviar la moto al punto.'],
      'checklist_ensamble'    => ['titulo' => '🔧 Checklist Ensamble', 'descripcion' => 'Ensamble de la moto en el punto, después de recepción.'],
      'checklist_entrega_v2'  => ['titulo' => '📦 Checklist Entrega (cliente)', 'descripcion' => 'La "checklist de entrega" cuando el punto entrega la moto al cliente final.'],
      'entregas'              => ['titulo' => '🚀 Tabla entregas (OTP + finalize)', 'descripcion' => 'OTP de entrega + estado del proceso de finalización.'],
      'firmas_contratos'      => ['titulo' => '✍ Firmas autógrafas (contrato)', 'descripcion' => 'Firmas que el cliente capturó durante checkout o vía retro-sign (Round 75).'],
      'cincel_timestamps'     => ['titulo' => '⏱ Cincel NOM-151 timestamps', 'descripcion' => 'Sellos NOM-151 aplicados a contrato/acta.'],
    ];
    foreach ($stages as $key => $meta):
      $info = $rep[$key] ?? ['present' => false, 'reason' => 'no consultado', 'row' => null, 'rows' => []];
      $present = !empty($info['present']);
      $cardCls = $present ? 'ok' : 'empty';
      $pillCls = $present ? 'st-ok' : 'st-empty';
      $pillTxt = $present ? '✓ Datos presentes' : '— Sin datos';
      if (strpos((string)$info['reason'], 'SQL error') !== false) { $cardCls = 'bad'; $pillCls = 'st-bad'; $pillTxt = '✗ Error'; }
      elseif (strpos((string)$info['reason'], 'tabla no existe') !== false) { $cardCls = 'warn'; $pillCls = 'st-warn'; $pillTxt = '⚠ Tabla no existe'; }
  ?>
    <div class="card <?= $cardCls ?>">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <div>
          <div style="font-weight:700;font-size:15px;"><?= $h($meta['titulo']) ?></div>
          <div class="muted" style="font-size:11.5px;margin-top:2px;"><?= $h($meta['descripcion']) ?> · tabla: <code><?= $h($key) ?></code></div>
        </div>
        <span class="section-status <?= $pillCls ?>"><?= $h($pillTxt) ?> · <?= $h($info['reason']) ?></span>
      </div>

      <?php if (!empty($info['row'])): ?>
        <details style="margin-top:10px;">
          <summary class="muted" style="font-size:11.5px;cursor:pointer;">Ver fila completa</summary>
          <pre><?= $h(json_encode($info['row'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
        </details>
      <?php elseif (!empty($info['rows'])): ?>
        <details style="margin-top:10px;" open>
          <summary class="muted" style="font-size:11.5px;cursor:pointer;">Ver <?= count($info['rows']) ?> fila(s)</summary>
          <pre><?= $h(json_encode($info['rows'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
        </details>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <h2>4. Interpretación rápida</h2>
  <div class="card">
    <ul style="margin:0;padding-left:18px;line-height:1.7;font-size:13px;">
      <li><strong>🧰 Recepción en el punto sin datos</strong> y la moto está en estado <code>recibida</code> / <code>lista_para_entrega</code> / etc → data inconsistente, el admin movió el estado sin pasar por el flujo del punto. El admin (o el punto desde su panel) debe recibir físicamente la moto para crear la fila en <code>recepcion_punto</code>.</li>
      <li><strong>📦 Checklist Entrega sin datos</strong> y el cliente ya recibió OTP / firmó acta → el punto no abrió el checklist de entrega. Pídele al operador del punto que abra la moto desde su panel y complete las 5 fases.</li>
      <li><strong>✍ Firmas autógrafas sin datos</strong> y el contrato es pre-2026-05-23 → normal. Usa el flujo Round 75 (<code>solicitar-firma-contrato.php</code>) para pedirle al cliente que firme retroactivamente.</li>
      <li><strong>⏱ Cincel NOM-151 timestamps sin datos</strong> → el sello no se aplicó. Verificar con <code>diagnostico-cincel-timestamp-create.php</code>.</li>
    </ul>
  </div>

<?php elseif ($motoId > 0): ?>
  <div class="banner banner-bad">No existe el moto <?= (int)$motoId ?> en inventario_motos.</div>
<?php else: ?>
  <div class="banner banner-empty">Selecciona un moto de la lista o ingresa un moto_id manualmente para ver el diagnóstico completo.</div>
<?php endif; ?>

</body></html>
