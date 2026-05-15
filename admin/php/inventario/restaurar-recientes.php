<?php
/**
 * Voltika Admin — Round 41 recovery tool (2026-05-16).
 *
 * One-time admin utility to restore motos soft-deleted from the punto panel
 * in the last few hours. Built specifically for the Round 41 incident where
 * an operator clicked the 🗑 button on "Venta por referido" by accident
 * and removed 4 motos. The endpoint is admin-only and safety-bounded:
 *
 *   ✓ Only flips activo=0 → activo=1 (no data loss possible)
 *   ✓ Time-bounded: 6 h default, max 24 h (configurable via ?hours=)
 *   ✓ Per-punto filter optional (?punto_id=)
 *   ✓ GET = preview (read-only); POST = actually restore
 *   ✓ Audit-logged via adminLog
 *
 * URLs:
 *   GET  /admin/php/inventario/restaurar-recientes.php
 *        → HTML page listing recently deleted motos + "Restaurar todas" button
 *   POST same URL with {ids:[...]} → restore those exact ids
 *
 * Once the 4 motos are back, this file can be deleted — it's intentionally
 * a single-shot recovery tool, not a permanent admin feature.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
$adminId = adminRequireAuth(['admin']);

$pdo = getDB();

// ─────────────────────────────────────────────────────────────────────────
// POST: actually restore the supplied ids (also time-bounded as safety)
// ─────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    $body = adminJsonIn();
    $ids  = $body['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        echo json_encode(['ok' => false, 'error' => 'ids array requerido']);
        exit;
    }
    // Normalize to int + dedupe, cap to 50 ids per call to avoid runaway requests.
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_filter($ids, fn($v) => $v > 0);
    if (count($ids) > 50) $ids = array_slice($ids, 0, 50);

    // Hours window — server-side gate independent of client input.
    $hours = max(1, min(24, (int)($body['hours'] ?? 6)));

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge($ids, [$hours]);
    $stmt = $pdo->prepare(
        "UPDATE inventario_motos
            SET activo = 1,
                eliminado_motivo = CONCAT('[RESTAURADO 2026-05-16 by admin] ', IFNULL(eliminado_motivo, '')),
                eliminado_por = NULL,
                eliminado_fecha = NULL
          WHERE id IN ($placeholders)
            AND activo = 0
            AND eliminado_fecha >= DATE_SUB(NOW(), INTERVAL ? HOUR)"
    );
    $stmt->execute($params);
    $rows = $stmt->rowCount();

    adminLog('moto_restaurada_admin', [
        'ids'      => $ids,
        'rows'     => $rows,
        'hours'    => $hours,
        'admin_id' => (int)$adminId,
    ]);

    echo json_encode([
        'ok'             => true,
        'rows_restored'  => $rows,
        'ids_requested'  => $ids,
        'hours_window'   => $hours,
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// GET: preview — list recently deleted motos
// ─────────────────────────────────────────────────────────────────────────
$hours    = max(1, min(24, (int)($_GET['hours'] ?? 6)));
$puntoId  = isset($_GET['punto_id']) ? (int)$_GET['punto_id'] : 0;

$sql = "SELECT m.id, m.vin_display, m.vin, m.modelo, m.color,
               m.punto_voltika_id, m.eliminado_por, m.eliminado_motivo,
               m.eliminado_fecha,
               pv.nombre AS punto_nombre,
               du.nombre AS deletor_nombre
          FROM inventario_motos m
          LEFT JOIN puntos_voltika pv ON pv.id = m.punto_voltika_id
          LEFT JOIN dealer_usuarios du ON du.id = m.eliminado_por
         WHERE m.activo = 0
           AND m.eliminado_fecha >= DATE_SUB(NOW(), INTERVAL ? HOUR)";
$params = [$hours];
if ($puntoId > 0) { $sql .= " AND m.punto_voltika_id = ?"; $params[] = $puntoId; }
$sql .= " ORDER BY m.eliminado_fecha DESC LIMIT 100";

$rows = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('restaurar-recientes: ' . $e->getMessage());
}

header('Content-Type: text/html; charset=UTF-8');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Voltika Admin — Restaurar motos eliminadas</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fb;color:#0c2340;padding:24px;max-width:1080px;margin:0 auto;}
h1{font-size:22px;margin:0 0 4px;}
.sub{color:#64748b;font-size:13px;margin-bottom:18px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:14px;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th{text-align:left;padding:8px 6px;border-bottom:2px solid #cbd5e1;color:#475569;font-weight:700;font-size:12px;}
td{padding:8px 6px;border-bottom:1px solid #f1f5f9;}
.btn{background:#039fe1;color:#fff;border:0;padding:10px 18px;border-radius:6px;font-weight:600;cursor:pointer;font-size:13px;}
.btn-restore{background:#16a34a;}
.btn-restore:disabled{background:#86efac;cursor:not-allowed;}
.muted{color:#94a3b8;font-size:11px;}
code{background:#f1f5f9;color:#1e293b;padding:1px 5px;border-radius:3px;font-size:11px;}
.empty{padding:30px;text-align:center;color:#94a3b8;}
.banner{padding:12px 14px;border-radius:8px;font-size:13px;margin:12px 0;}
.banner-warn{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.banner-err{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
.filter input{padding:6px 10px;border:1px solid #cbd5e1;border-radius:5px;font-size:13px;}
input[type=checkbox]{width:16px;height:16px;}
</style></head><body>

<h1>🔧 Restaurar motos eliminadas recientemente</h1>
<div class="sub">
  Herramienta puntual de recuperación (Round 41). Lista motos con <code>activo=0</code> y
  <code>eliminado_fecha</code> dentro de la ventana indicada. Marca las que quieras restaurar y presiona "Restaurar seleccionadas".
</div>

<div class="card filter">
  <form method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <label>Ventana: <input type="number" name="hours" min="1" max="24" value="<?= htmlspecialchars((string)$hours) ?>"> horas</label>
    <label>Punto (opcional, 0 = todos): <input type="number" name="punto_id" value="<?= htmlspecialchars((string)$puntoId) ?>"></label>
    <button class="btn" type="submit">Filtrar</button>
  </form>
</div>

<?php if (empty($rows)): ?>
  <div class="card empty">
    No se encontraron motos eliminadas dentro de las últimas <?= $hours ?> horas
    <?= $puntoId ? ' para el punto ' . $puntoId : '' ?>.
  </div>
<?php else: ?>
  <div class="banner banner-warn">
    <strong>⚠ <?= count($rows) ?> motos listadas.</strong>
    Verifica las filas antes de restaurar — la operación marca <code>activo=1</code> y
    deja una nota en <code>eliminado_motivo</code> con el prefijo "[RESTAURADO...]".
  </div>

  <div class="card">
    <table>
      <thead>
        <tr>
          <th style="width:32px;"><input type="checkbox" id="chkAll" checked></th>
          <th>ID</th>
          <th>Modelo · color</th>
          <th>VIN</th>
          <th>Punto</th>
          <th>Eliminado por</th>
          <th>Motivo</th>
          <th>Fecha eliminación</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><input type="checkbox" class="chk" data-id="<?= (int)$r['id'] ?>" checked></td>
            <td><strong><?= (int)$r['id'] ?></strong></td>
            <td><?= htmlspecialchars(($r['modelo'] ?? '') . ' · ' . ($r['color'] ?? '')) ?></td>
            <td><code><?= htmlspecialchars($r['vin_display'] ?? $r['vin'] ?? '—') ?></code></td>
            <td><?= htmlspecialchars($r['punto_nombre'] ?? ('#' . (int)$r['punto_voltika_id'])) ?></td>
            <td><?= htmlspecialchars($r['deletor_nombre'] ?? ('#' . (int)$r['eliminado_por'])) ?></td>
            <td><?= htmlspecialchars($r['eliminado_motivo'] ?? '—') ?></td>
            <td class="muted"><?= htmlspecialchars($r['eliminado_fecha'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div style="display:flex;gap:10px;align-items:center;">
    <button class="btn btn-restore" id="btnRestore">✓ Restaurar seleccionadas</button>
    <span id="restoreStatus" class="muted"></span>
  </div>
<?php endif; ?>

<script>
document.getElementById('chkAll')?.addEventListener('change', function(){
  var on = this.checked;
  document.querySelectorAll('.chk').forEach(function(c){ c.checked = on; });
});

document.getElementById('btnRestore')?.addEventListener('click', function(){
  var ids = Array.from(document.querySelectorAll('.chk:checked')).map(function(c){ return parseInt(c.getAttribute('data-id'), 10); });
  if (!ids.length) { alert('Selecciona al menos una moto.'); return; }
  if (!confirm('¿Restaurar ' + ids.length + ' moto(s)? Esto las hará visibles otra vez en su punto.')) return;
  var btn = this;
  var status = document.getElementById('restoreStatus');
  btn.disabled = true;
  status.textContent = 'Procesando...';
  fetch('/admin/php/inventario/restaurar-recientes.php', {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ids: ids, hours: <?= (int)$hours ?> })
  })
  .then(function(r){ return r.json(); })
  .then(function(j){
    if (j.ok) {
      status.textContent = '✓ Restauradas: ' + j.rows_restored + ' / ' + (j.ids_requested || []).length;
      setTimeout(function(){ location.reload(); }, 1500);
    } else {
      status.textContent = '✗ ' + (j.error || 'falló');
      btn.disabled = false;
    }
  })
  .catch(function(e){
    status.textContent = '✗ ' + e.message;
    btn.disabled = false;
  });
});
</script>

</body></html>
