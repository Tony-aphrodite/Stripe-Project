<?php
/**
 * One-shot migration — add `pedido_corto` column to transacciones and
 * backfill every existing row with a VK-YYMM-NNNN code.
 *
 *   /admin/php/migrar-pedido-corto.php
 *
 * Idempotent — running it twice is safe: only rows whose pedido_corto is
 * NULL get filled. Admin-only UI.
 */
require_once __DIR__ . '/bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$report = ['added_column' => false, 'already_had_column' => false, 'backfilled' => 0, 'skipped' => 0, 'errors' => []];

try {
    // ── Add column + unique index (idempotent) ──────────────────────────────
    $col = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                           WHERE TABLE_SCHEMA=DATABASE()
                             AND TABLE_NAME='transacciones'
                             AND COLUMN_NAME='pedido_corto'");
    $col->execute();
    if ((int)$col->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE transacciones ADD COLUMN pedido_corto VARCHAR(20) NULL");
        $pdo->exec("ALTER TABLE transacciones ADD UNIQUE INDEX idx_pedido_corto (pedido_corto)");
        $report['added_column'] = true;
    } else {
        $report['already_had_column'] = true;
    }

    // ── Backfill: iterate orders by registration date ASC, assign per-month ─
    $rows = $pdo->query("SELECT id, freg FROM transacciones
                          WHERE pedido_corto IS NULL OR pedido_corto = ''
                          ORDER BY freg ASC, id ASC")
                ->fetchAll(PDO::FETCH_ASSOC);

    $counters = []; // yymm → current counter
    $upd = $pdo->prepare("UPDATE transacciones SET pedido_corto = ? WHERE id = ?");

    foreach ($rows as $r) {
        $dt = null;
        try { $dt = new DateTime($r['freg'] ?? 'now'); } catch (Throwable $e) { $dt = new DateTime(); }
        $yymm = $dt->format('ym');

        if (!isset($counters[$yymm])) {
            // Seed from any existing pedido_corto in this yymm (in case backfill
            // was partial previously).
            $q = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(pedido_corto, '-', -1) AS UNSIGNED))
                                 FROM transacciones
                                WHERE pedido_corto LIKE ?");
            $q->execute(["VK-$yymm-%"]);
            $counters[$yymm] = (int)($q->fetchColumn() ?: 0);
        }
        $counters[$yymm]++;
        $short = sprintf('VK-%s-%04d', $yymm, $counters[$yymm]);

        try {
            $upd->execute([$short, $r['id']]);
            $report['backfilled']++;
        } catch (Throwable $e) {
            // Unique collision — extremely unlikely but retry with +random offset
            try {
                $counters[$yymm] += random_int(1, 9);
                $short2 = sprintf('VK-%s-%04d', $yymm, $counters[$yymm]);
                $upd->execute([$short2, $r['id']]);
                $report['backfilled']++;
            } catch (Throwable $e2) {
                $report['errors'][] = ['id' => $r['id'], 'error' => $e2->getMessage()];
            }
        }
    }

    $report['skipped'] = (int)$pdo->query("SELECT COUNT(*) FROM transacciones WHERE pedido_corto IS NOT NULL")->fetchColumn() - $report['backfilled'];
    $report['total']   = (int)$pdo->query("SELECT COUNT(*) FROM transacciones")->fetchColumn();

    adminLog('migrar_pedido_corto', [
        'added_column' => $report['added_column'],
        'backfilled' => $report['backfilled'],
        'errors' => count($report['errors']),
    ]);
} catch (Throwable $e) {
    $report['errors'][] = ['fatal' => $e->getMessage()];
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Migrar pedido_corto</title>
<style>
  body{font-family:-apple-system,Segoe UI,Arial,sans-serif;background:#f5f7fa;color:#1a3a5c;margin:0;padding:24px;}
  .card{background:#fff;border:1px solid #e1e8ee;border-radius:10px;padding:20px;max-width:720px;margin:0 auto 18px;}
  h1{margin:0 0 10px;font-size:20px;}
  p{font-size:13px;line-height:1.6;margin:6px 0;}
  .ok{color:#0e8f55;font-weight:700;}
  .err{color:#c62828;font-weight:700;}
  .banner{padding:12px 14px;border-radius:8px;font-size:13.5px;margin-bottom:12px;}
  .banner.ok{background:#ecfdf5;border-left:4px solid #0e8f55;color:#065f46;}
  .banner.bad{background:#fef2f2;border-left:4px solid #dc2626;color:#7a0e1f;}
  table{width:100%;border-collapse:collapse;font-size:13px;}
  th,td{text-align:left;padding:8px 12px;border-bottom:1px solid #eef2f5;}
  th{background:#f5f7fa;}
  code{background:#f7fafc;padding:2px 6px;border-radius:3px;font-size:12px;}
</style>
</head>
<body>
<div class="card">
  <h1>🔢 Migración — pedido_corto</h1>
  <p>Genera códigos cortos <code>VK-YYMM-NNNN</code> para todos los pedidos. Seguro de ejecutar varias veces.</p>

  <?php if (!empty($report['errors'])): ?>
    <div class="banner bad">⚠️ Hubo errores durante la migración. Revisa los detalles abajo.</div>
  <?php else: ?>
    <div class="banner ok">✅ Migración completada sin errores.</div>
  <?php endif; ?>

  <table>
    <tr><th>Columna pedido_corto agregada</th><td><?= $report['added_column'] ? '<span class="ok">sí (nuevo)</span>' : 'ya existía' ?></td></tr>
    <tr><th>Pedidos rellenados</th><td><strong><?= (int)$report['backfilled'] ?></strong></td></tr>
    <tr><th>Pedidos con código previo</th><td><?= (int)$report['skipped'] ?></td></tr>
    <tr><th>Total de pedidos</th><td><?= (int)($report['total'] ?? 0) ?></td></tr>
    <tr><th>Errores</th><td><?= count($report['errors']) ?></td></tr>
  </table>

  <?php if (!empty($report['errors'])): ?>
    <h3 style="margin-top:18px;font-size:14px;">Errores</h3>
    <pre style="background:#fef2f2;padding:10px;border-radius:6px;font-size:11px;overflow:auto;"><?php
      foreach ($report['errors'] as $err) {
          echo htmlspecialchars(json_encode($err, JSON_UNESCAPED_UNICODE)) . "\n";
      }
    ?></pre>
  <?php endif; ?>

  <?php
    // Sample of first 5 backfilled
    $sample = $pdo->query("SELECT pedido_corto, pedido, nombre, freg
                            FROM transacciones
                           WHERE pedido_corto IS NOT NULL
                           ORDER BY freg ASC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
  ?>
  <?php if ($sample): ?>
    <h3 style="margin-top:18px;font-size:14px;">Ejemplos (primeros 5)</h3>
    <table>
      <tr><th>Código corto</th><th>Pedido original</th><th>Cliente</th><th>Fecha</th></tr>
      <?php foreach ($sample as $s): ?>
        <tr>
          <td><strong><?= htmlspecialchars($s['pedido_corto']) ?></strong></td>
          <td style="font-size:11px;font-family:monospace;"><?= htmlspecialchars($s['pedido']) ?></td>
          <td><?= htmlspecialchars($s['nombre']) ?></td>
          <td><?= htmlspecialchars($s['freg']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
</body>
</html>
