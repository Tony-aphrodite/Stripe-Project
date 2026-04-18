<?php
/**
 * Migration — normalize tpago='unico' → tpago='contado' in transacciones.
 *
 * Rationale: 'unico' is a Stripe-specific label we used internally for
 * single-payment PaymentIntents. Operationally it is identical to 'contado'
 * (pago único, tarjeta). Client reported confusion in admin detail view
 * where "Tipo de pago: unico" showed up. We unify both values under 'contado'.
 *
 * Safe to run multiple times (idempotent — affects only rows still at 'unico').
 *
 * Usage:
 *   GET  /admin_test/reparar-unico-a-contado.php         → preview count (dry run)
 *   POST /admin_test/reparar-unico-a-contado.php?run=1   → apply UPDATE
 */
require_once __DIR__ . '/php/bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$isRun = ($_SERVER['REQUEST_METHOD'] === 'POST') && (($_GET['run'] ?? '') === '1');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Voltika — Migrar tpago 'unico' → 'contado'</title>
<style>
  body { font-family: system-ui, sans-serif; max-width: 860px; margin: 2rem auto; padding: 0 1rem; background: #f7f8fa; color:#1a3a5c; }
  h1 { font-size: 1.3rem; }
  .card { background: #fff; border-radius: 10px; padding: 1.2rem; box-shadow: 0 2px 8px rgba(0,0,0,.06); margin-bottom: 1rem; }
  button { padding: .7rem 1.4rem; border: none; border-radius: 7px; font-size: 1rem; font-weight: 700; cursor: pointer; margin-right: .5rem; }
  .btn-run { background: #c41e3a; color: #fff; }
  .ok   { background: #e6f4ea; color: #1e7e34; padding:1rem;border-radius:8px;font-weight:700; }
  .warn { background: #fffbe6; color: #ad6800; padding:1rem;border-radius:8px; }
  code { font-family: ui-monospace, Consolas, monospace; background: #f5f5f5; padding: 1px 6px; border-radius: 3px; }
  table { width: 100%; border-collapse: collapse; font-size: .88rem; margin-top:8px; }
  th, td { text-align: left; padding: .4rem .6rem; border-bottom: 1px solid #eee; }
  th { background: #fafafa; }
</style>
</head>
<body>
<h1>Migrar transacciones <code>tpago = 'unico'</code> → <code>'contado'</code></h1>

<?php
try {
    // Preview counts
    $countStmt = $pdo->query("SELECT COUNT(*) FROM transacciones WHERE tpago = 'unico'");
    $pendientes = (int)$countStmt->fetchColumn();

    $sampleStmt = $pdo->query("SELECT id, pedido, nombre, email, modelo, color, total, freg
                               FROM transacciones WHERE tpago = 'unico'
                               ORDER BY id DESC LIMIT 20");
    $sample = $sampleStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($isRun) {
        $upd = $pdo->prepare("UPDATE transacciones SET tpago = 'contado' WHERE tpago = 'unico'");
        $upd->execute();
        $affected = $upd->rowCount();
        echo '<div class="ok">Migración aplicada. Filas actualizadas: <strong>' . $affected . '</strong></div>';
        echo '<p><a href="reparar-unico-a-contado.php">Volver a verificar</a></p>';
    } else {
        echo '<div class="card">';
        echo '<p>Filas actuales con <code>tpago = \'unico\'</code>: <strong>' . $pendientes . '</strong></p>';
        if ($pendientes > 0) {
            echo '<form method="POST" action="reparar-unico-a-contado.php?run=1" '
               . 'onsubmit="return confirm(\'¿Convertir ' . $pendientes . ' transacciones de unico a contado?\');">';
            echo '<button class="btn-run" type="submit">Aplicar migración ahora</button>';
            echo '</form>';
            if (!empty($sample)) {
                echo '<h3 style="margin-top:18px">Vista previa (últimas 20):</h3>';
                echo '<table><thead><tr><th>ID</th><th>Pedido</th><th>Cliente</th><th>Modelo</th><th>Color</th><th>Total</th><th>Fecha</th></tr></thead><tbody>';
                foreach ($sample as $r) {
                    echo '<tr>'
                       . '<td>' . (int)$r['id'] . '</td>'
                       . '<td>' . htmlspecialchars($r['pedido'] ?? '') . '</td>'
                       . '<td>' . htmlspecialchars(($r['nombre'] ?? '') . ' · ' . ($r['email'] ?? '')) . '</td>'
                       . '<td>' . htmlspecialchars($r['modelo'] ?? '') . '</td>'
                       . '<td>' . htmlspecialchars($r['color'] ?? '') . '</td>'
                       . '<td>$' . number_format((float)$r['total'], 2) . '</td>'
                       . '<td>' . htmlspecialchars($r['freg'] ?? '') . '</td>'
                       . '</tr>';
                }
                echo '</tbody></table>';
            }
        } else {
            echo '<div class="ok">No hay transacciones con <code>tpago = \'unico\'</code>. Nada que migrar.</div>';
        }
        echo '</div>';
    }
} catch (Throwable $e) {
    echo '<div class="warn"><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>
</body>
</html>
