<?php
/**
 * Voltika Admin — Backfill orphan moto assignments (Round 79, 2026-05-25).
 *
 * Customer brief (Óscar via Tony, 2026-05-25): Leobardo's order showed
 * "Sin asignar" in Ventas while CEDIS showed 2 motos tagged with the
 * synthetic "VK-TX32" pedido_num. The root cause (asignar-moto.php only
 * wrote pedido_num strings, never transacciones.moto_id) was fixed in
 * Round 79 forward. But existing orphan assignments (every moto stamped
 * with VK-TX{id} before today) still need to be reconciled.
 *
 * This page does that reconciliation safely:
 *   1. Find every moto with pedido_num LIKE 'VK-TX%' AND no matching
 *      transacciones.moto_id FK set.
 *   2. Parse the {id} from the pedido_num to identify the transaccion.
 *   3. For each transaccion with MULTIPLE candidate orphans (e.g. Leobardo
 *      with 2 motos), show them all and let the admin pick which is the
 *      "real" one — the others get desasignar'd.
 *   4. For transacciones with exactly ONE orphan, offer one-click bind.
 *
 * URL: /admin/php/ventas/backfill-asignaciones.php?key=voltika_diag_2026
 *
 * Idempotent: running twice is safe — already-fixed assignments are
 * detected and skipped. Never writes without admin confirmation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
$adminId = adminRequireAuth(['admin']);

$expected = 'voltika_diag_2026';
$key = $_GET['key'] ?? $_POST['key'] ?? '';
if (!hash_equals($expected, (string)$key)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Acceso denegado. Usa ?key=voltika_diag_2026";
    exit;
}

$pdo = getDB();

// ── Ensure transacciones.moto_id column exists ───────────────────────────
$hasTxnMotoId = false;
try {
    $hasTxnMotoId = (bool)$pdo->query("SHOW COLUMNS FROM transacciones LIKE 'moto_id'")->fetch();
    if (!$hasTxnMotoId) {
        try {
            $pdo->exec("ALTER TABLE transacciones ADD COLUMN moto_id INT NULL, ADD INDEX idx_moto_id (moto_id)");
            $hasTxnMotoId = true;
        } catch (Throwable $e) { /* not fatal — page still renders */ }
    }
} catch (Throwable $e) {}

// ── POST: execute a bind (link a specific moto to its transaccion) ──────
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    $action  = (string)($body['action']  ?? '');
    $motoId  = (int)($body['moto_id']    ?? 0);
    $txnId   = (int)($body['transaccion_id'] ?? 0);

    header('Content-Type: application/json; charset=utf-8');

    if ($action === 'bind' && $motoId > 0 && $txnId > 0) {
        try {
            // Verify both rows exist
            $m = $pdo->prepare("SELECT id, vin, cliente_nombre, pedido_num FROM inventario_motos WHERE id = ? LIMIT 1");
            $m->execute([$motoId]);
            $moto = $m->fetch(PDO::FETCH_ASSOC) ?: null;

            $t = $pdo->prepare("SELECT id, nombre, pedido_corto, moto_id FROM transacciones WHERE id = ? LIMIT 1");
            $t->execute([$txnId]);
            $txn = $t->fetch(PDO::FETCH_ASSOC) ?: null;

            if (!$moto || !$txn) {
                echo json_encode(['ok' => false, 'error' => 'not_found',
                    'message' => 'No existe moto o transaccion']);
                exit;
            }

            // Refuse if the txn is already bound to a DIFFERENT moto
            if (!empty($txn['moto_id']) && (int)$txn['moto_id'] !== $motoId) {
                echo json_encode(['ok' => false, 'error' => 'txn_already_bound',
                    'message' => 'Esa transaccion ya tiene moto_id=' . (int)$txn['moto_id'] . ' bindeada. Desasigna primero esa moto si quieres re-bindear esta.']);
                exit;
            }

            $pdo->prepare("UPDATE transacciones SET moto_id = ? WHERE id = ?")
                ->execute([$motoId, $txnId]);

            if (function_exists('adminLog')) {
                adminLog('backfill_asignacion_bind', [
                    'moto_id' => $motoId, 'txn_id' => $txnId,
                    'pedido_num' => $moto['pedido_num'] ?? '',
                ]);
            }
            echo json_encode(['ok' => true, 'message' => 'Bindeado: moto ' . $motoId . ' ↔ txn ' . $txnId]);
            exit;
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'sql_error', 'message' => $e->getMessage()]);
            exit;
        }
    }

    echo json_encode(['ok' => false, 'error' => 'bad_action']);
    exit;
}

// ── GET: find orphans and present them ──────────────────────────────────
$orphans = [];
$txnGroups = []; // grouped by inferred transaccion_id
try {
    $st = $pdo->query("
        SELECT im.id, im.vin, im.vin_display, im.modelo, im.color, im.estado,
               im.pedido_num, im.cliente_nombre, im.cliente_email, im.cliente_telefono,
               im.transaccion_id AS im_txn_id, im.cliente_acta_firmada, im.fmod
          FROM inventario_motos im
         WHERE im.activo = 1
           AND im.cliente_nombre IS NOT NULL
           AND im.cliente_nombre <> ''
           AND (im.pedido_num LIKE 'VK-TX%')
         ORDER BY im.fmod DESC
         LIMIT 200
    ");
    $orphans = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($orphans as &$o) {
        // Parse {id} out of "VK-TX{id}"
        if (preg_match('/^VK-TX(\d+)$/', (string)$o['pedido_num'], $mm)) {
            $o['inferred_txn_id'] = (int)$mm[1];
        } else {
            $o['inferred_txn_id'] = null;
        }
        if ($o['inferred_txn_id']) {
            // Lookup that txn
            $tq = $pdo->prepare("SELECT id, nombre, email, telefono, pedido_corto, moto_id, pago_estado FROM transacciones WHERE id = ? LIMIT 1");
            $tq->execute([$o['inferred_txn_id']]);
            $o['txn'] = $tq->fetch(PDO::FETCH_ASSOC) ?: null;
        } else {
            $o['txn'] = null;
        }
        // Group
        $gkey = $o['inferred_txn_id'] ?: 'unparseable';
        $txnGroups[$gkey][] = $o;
    }
    unset($o);
} catch (Throwable $e) {
    $loadErr = $e->getMessage();
}

$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Voltika — Backfill asignaciones</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fb;color:#0c2340;padding:24px;max-width:1180px;margin:0 auto;line-height:1.55;}
h1{font-size:22px;margin:0 0 6px;}
h2{font-size:14px;color:#475569;margin:22px 0 8px;text-transform:uppercase;letter-spacing:.4px;}
.muted{color:#94a3b8;font-size:12px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:12px;}
.group{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:18px;}
.group h3{margin:0 0 8px;font-size:14px;color:#0c2340;}
.banner{padding:10px 12px;border-radius:8px;font-size:13px;margin-bottom:10px;font-weight:600;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.banner-info{background:#dbeafe;border:1px solid #93c5fd;color:#1e40af;}
.banner-warn{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;}
.banner-bad{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
table{border-collapse:collapse;width:100%;font-size:12.5px;}
th,td{border-bottom:1px solid #e2e8f0;padding:6px 8px;text-align:left;vertical-align:top;}
th{background:#f1f5f9;color:#475569;font-weight:600;font-size:11.5px;text-transform:uppercase;letter-spacing:.4px;}
code{background:#1e293b;color:#e2e8f0;padding:2px 6px;border-radius:3px;font-size:11px;font-family:ui-monospace,monospace;word-break:break-all;}
button.btn-bind{background:#16a34a;color:#fff;border:0;padding:6px 12px;border-radius:6px;font-weight:700;cursor:pointer;font-size:12px;}
button.btn-bind:disabled{background:#cbd5e1;cursor:not-allowed;}
button.btn-unass{background:#dc2626;color:#fff;border:0;padding:6px 12px;border-radius:6px;font-weight:600;cursor:pointer;font-size:12px;}
.kv{display:grid;grid-template-columns:200px 1fr;gap:4px 12px;font-size:12.5px;}
.kv .k{color:#64748b;}
.kv .v{font-weight:600;color:#0c2340;word-break:break-all;}
.pill-ok    {background:#dcfce7;color:#166534;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;}
.pill-warn  {background:#fff7ed;color:#9a3412;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;}
.pill-info  {background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;}
</style></head><body>

<h1>🛠 Backfill de asignaciones huérfanas</h1>
<div class="muted"><?= $h(date('Y-m-d H:i:s')) ?> · Servidor: <?= $h($_SERVER['HTTP_HOST'] ?? '') ?></div>

<h2>1. Estado de la columna transacciones.moto_id</h2>
<div class="card">
  <?php if ($hasTxnMotoId): ?>
    <div class="banner banner-ok">✅ La columna <code>transacciones.moto_id</code> existe.</div>
  <?php else: ?>
    <div class="banner banner-bad">❌ La columna <code>transacciones.moto_id</code> NO existe y no pude crearla automáticamente. El backfill no puede operar. Verifica permisos del usuario MySQL (necesita ALTER).</div>
  <?php endif; ?>
</div>

<h2>2. Motos huérfanas (pedido_num LIKE 'VK-TX%' y sin link FK)</h2>

<?php if (!$orphans): ?>
  <div class="card">
    <div class="banner banner-ok">🎉 No hay motos huérfanas. Todas las asignaciones existentes ya están bien.</div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="banner banner-info">Se encontraron <strong><?= count($orphans) ?> moto(s)</strong> con <code>pedido_num</code> en formato sintético <code>VK-TX{id}</code>, agrupadas por la transacción que infieren. Estos son los huérfanos que la actualización al endpoint asignar-moto.php (Round 79) puede ahora reparar.</div>
  </div>

  <?php foreach ($txnGroups as $gkey => $list):
    $first = $list[0];
    $txn = $first['txn'] ?? null;
    $multi = count($list) > 1;
  ?>
    <div class="group">
      <h3>
        <?php if ($txn): ?>
          🧾 Transacción <code>id=<?= (int)$txn['id'] ?></code> ·
          <?= $h($txn['nombre'] ?? '—') ?> ·
          pedido_corto: <code><?= $h($txn['pedido_corto'] ?? '—') ?></code>
          <?php if (!empty($txn['moto_id'])): ?>
            · <span class="pill-warn">moto_id ya asignado: <?= (int)$txn['moto_id'] ?></span>
          <?php else: ?>
            · <span class="pill-info">moto_id = NULL</span>
          <?php endif; ?>
        <?php else: ?>
          ⚠ Transacción <?= $h((string)$gkey) ?> no encontrada (huérfanos sin destino)
        <?php endif; ?>
      </h3>

      <table>
        <thead><tr>
          <th>moto_id</th><th>VIN</th><th>Modelo</th><th>Estado</th>
          <th>Cliente en moto</th><th>pedido_num</th><th>Acta firmada</th><th>Acción</th>
        </tr></thead>
        <tbody>
        <?php foreach ($list as $o):
          $isBoundToTxn = $txn && !empty($txn['moto_id']) && (int)$txn['moto_id'] === (int)$o['id'];
          $isFirmada = !empty($o['cliente_acta_firmada']);
        ?>
          <tr>
            <td><code><?= (int)$o['id'] ?></code></td>
            <td><code style="font-size:10px;"><?= $h($o['vin_display'] ?? $o['vin'] ?? '—') ?></code></td>
            <td><?= $h(($o['modelo'] ?? '') . ' · ' . ($o['color'] ?? '')) ?></td>
            <td><?= $h($o['estado'] ?? '—') ?></td>
            <td><?= $h($o['cliente_nombre'] ?? '—') ?></td>
            <td><code><?= $h($o['pedido_num'] ?? '—') ?></code></td>
            <td><?= $isFirmada ? '✓' : '—' ?></td>
            <td>
              <?php if (!$txn): ?>
                <span class="muted">Sin txn destino</span>
              <?php elseif ($isBoundToTxn): ?>
                <span class="pill-ok">✓ Ya bindeada</span>
              <?php elseif (!empty($txn['moto_id'])): ?>
                <span class="pill-warn">txn bindeada a otro</span>
                <button class="btn-unass" data-moto="<?= (int)$o['id'] ?>">Desasignar esta</button>
              <?php else: ?>
                <button class="btn-bind" data-moto="<?= (int)$o['id'] ?>" data-txn="<?= (int)$txn['id'] ?>">
                  ✓ Bindear esta a la txn
                </button>
                <?php if ($multi): ?>
                  <button class="btn-unass" data-moto="<?= (int)$o['id'] ?>">Desasignar esta</button>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($multi && $txn && empty($txn['moto_id'])): ?>
        <div style="margin-top:8px;padding:10px 12px;background:#fff7ed;border-left:3px solid #f59e0b;border-radius:6px;font-size:12.5px;color:#7c2d12;line-height:1.5;">
          ⚠ <strong>Múltiples motos huérfanas para la misma transacción.</strong> Solo una puede ser la "real". Decide cuál vincular (botón verde) — las otras deberías desasignar (botón rojo) para que vuelvan al inventario disponible.
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<h2>3. ¿Qué hace cada botón?</h2>
<div class="card" style="font-size:13px;">
  <ul style="margin:0;padding-left:18px;line-height:1.7;">
    <li><span class="pill-ok">✓ Bindear</span> escribe <code>transacciones.moto_id = &lt;moto_id&gt;</code>. A partir de ese momento, Ventas verá la moto correctamente asignada vía el JOIN directo (Round 79).</li>
    <li><span class="pill-warn">Desasignar</span> llama <code>desasignar-moto.php</code> que limpia los campos cliente_* del moto y lo regresa a <code>estado='recibida'</code>. La moto vuelve al stock disponible para asignar a otra venta.</li>
  </ul>
</div>

<script>
document.querySelectorAll('button.btn-bind').forEach(function(btn){
  btn.addEventListener('click', function(){
    if (!confirm('¿Bindear moto ' + btn.dataset.moto + ' a transacción ' + btn.dataset.txn + '?')) return;
    btn.disabled = true; btn.textContent = '...';
    fetch(window.location.href, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({action:'bind', moto_id:+btn.dataset.moto, transaccion_id:+btn.dataset.txn})
    }).then(r=>r.json()).then(j=>{
      if (j.ok) { btn.textContent = '✓ bindeada'; btn.style.background='#16a34a'; setTimeout(()=>location.reload(), 800); }
      else { alert('Error: ' + (j.message || 'desconocido')); btn.disabled = false; btn.textContent = '✓ Bindear esta a la txn'; }
    }).catch(err=>{ alert('Error de red: ' + err.message); btn.disabled = false; btn.textContent = '✓ Bindear esta a la txn'; });
  });
});

document.querySelectorAll('button.btn-unass').forEach(function(btn){
  btn.addEventListener('click', function(){
    if (!confirm('¿Desasignar moto ' + btn.dataset.moto + '? Volverá al inventario disponible.')) return;
    btn.disabled = true; btn.textContent = '...';
    fetch('/admin/php/ventas/desasignar-moto.php', {
      method: 'POST',
      credentials: 'include',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({moto_id: +btn.dataset.moto})
    }).then(r=>r.json()).then(j=>{
      if (j.ok) { btn.textContent = '✓ desasignada'; btn.style.background='#dc2626'; setTimeout(()=>location.reload(), 800); }
      else { alert('Error: ' + (j.error || j.message || 'desconocido')); btn.disabled = false; btn.textContent = 'Desasignar esta'; }
    }).catch(err=>{ alert('Error de red: ' + err.message); btn.disabled = false; btn.textContent = 'Desasignar esta'; });
  });
});
</script>

</body></html>
