<?php
/**
 * Backfill: re-verify transacciones.pago_estado against real Stripe status.
 *
 * Fixes historical rows written as 'pagada' by the old confirmar-orden.php
 * that did not query Stripe. For each transaccion with stripe_pi and
 * pago_estado IN ('pagada','pendiente'), we GET the PaymentIntent and
 * rewrite pago_estado to match Stripe's authoritative status.
 *
 *   succeeded                 → pagada
 *   canceled                  → fallido
 *   processing / requires_*   → pendiente
 *
 * Usage:
 *   GET  /admin_test/reparar-pago-estado-real.php             → dry run (report only)
 *   POST /admin_test/reparar-pago-estado-real.php?run=1       → write changes
 *
 * Safe to re-run; only updates rows whose DB state disagrees with Stripe.
 */
require_once __DIR__ . '/php/bootstrap.php';
adminRequireAuth(['admin']);

// bootstrap.php already loads master-bootstrap.php which already loads the
// right config.php (prod → configurador_prueba, test → configurador_prueba_test),
// so STRIPE_SECRET_KEY and getDB() are already available. Do NOT require
// another config.php — both files declare `function getDB()` without a guard,
// so double-including from the "other" environment triggers "Cannot redeclare
// function" fatal error.

$pdo = getDB();
$isRun = ($_SERVER['REQUEST_METHOD'] === 'POST') && (($_GET['run'] ?? '') === '1');

function stripeStatusFor($pi) {
    if (!$pi || !defined('STRIPE_SECRET_KEY') || !STRIPE_SECRET_KEY) return null;
    $ch = curl_init('https://api.stripe.com/v1/payment_intents/' . urlencode($pi));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . STRIPE_SECRET_KEY],
        CURLOPT_TIMEOUT        => 12,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return ['error' => 'http_' . $code];
    $d = json_decode($resp, true);
    return ['status' => $d['status'] ?? null, 'amount' => ($d['amount'] ?? 0) / 100];
}

function mapStripeToDb($st) {
    switch ($st) {
        case 'succeeded': return 'pagada';
        case 'canceled':  return 'fallido';
        default:          return 'pendiente';
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Reparar pago_estado real · Stripe</title>
<style>
  body { font-family: system-ui, sans-serif; max-width: 1100px; margin: 2rem auto; padding: 0 1rem; background: #f7f8fa; color:#1a3a5c; }
  h1 { font-size: 1.3rem; }
  .card { background: #fff; border-radius: 10px; padding: 1.2rem; box-shadow: 0 2px 8px rgba(0,0,0,.06); margin-bottom: 1rem; }
  button { padding: .7rem 1.4rem; border: none; border-radius: 7px; font-weight: 700; cursor: pointer; }
  .btn-run { background:#c41e3a; color:#fff; }
  .ok   { background:#e6f4ea; color:#1e7e34; padding:1rem;border-radius:8px;font-weight:700; }
  .warn { background:#fffbe6; color:#ad6800; padding:1rem;border-radius:8px; }
  .err  { background:#fde8e8; color:#c41e3a; padding:1rem;border-radius:8px; }
  table { width:100%;border-collapse:collapse;font-size:.85rem; margin-top:10px; }
  th,td { text-align:left; padding:.4rem .6rem; border-bottom:1px solid #eee; }
  th { background:#fafafa; }
  .diff-row { background:#fff3cd; }
  code { font-family: ui-monospace,Consolas,monospace; background:#f5f5f5; padding:1px 5px; border-radius:3px; font-size:.82rem; }
</style>
</head>
<body>
<h1>Verificar y sincronizar <code>pago_estado</code> con Stripe</h1>

<?php
if (!defined('STRIPE_SECRET_KEY') || !STRIPE_SECRET_KEY) {
    echo '<div class="err">STRIPE_SECRET_KEY no configurada — no se puede consultar Stripe.</div>';
    echo '</body></html>';
    exit;
}

try {
    // No LIMIT — scan all orders with a stripe_pi. Credit-family orders
    // (credito/enganche/parcial) skipped because 'parcial' is the intentional
    // state while the subscription is still active; don't flip them to 'pagada'
    // based on a single PI that only represents the enganche.
    $rows = $pdo->query("
        SELECT id, pedido, nombre, email, modelo, color, total, tpago, stripe_pi, pago_estado, freg
        FROM transacciones
        WHERE stripe_pi IS NOT NULL AND stripe_pi <> ''
          AND tpago NOT IN ('credito','enganche','parcial')
        ORDER BY id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $diffs = [];
    foreach ($rows as $r) {
        $real = stripeStatusFor($r['stripe_pi']);
        if (!is_array($real) || empty($real['status'])) continue;
        $expected = mapStripeToDb($real['status']);
        if (strtolower($r['pago_estado'] ?? '') !== $expected) {
            $r['_stripe_status'] = $real['status'];
            $r['_expected']      = $expected;
            $diffs[] = $r;
        }
    }

    if ($isRun) {
        $updated = 0;
        foreach ($diffs as $d) {
            $u = $pdo->prepare("UPDATE transacciones SET pago_estado = ? WHERE id = ?");
            $u->execute([$d['_expected'], $d['id']]);
            $updated += $u->rowCount();
            // Also propagate to inventario_motos
            $u2 = $pdo->prepare("UPDATE inventario_motos SET pago_estado = ? WHERE stripe_pi = ?");
            $u2->execute([$d['_expected'], $d['stripe_pi']]);
        }
        echo '<div class="ok">Migración aplicada. Filas actualizadas: <strong>' . $updated . '</strong></div>';
        echo '<p><a href="reparar-pago-estado-real.php">Volver a verificar</a></p>';
    } else {
        echo '<div class="card">';
        echo '<p>Se revisaron <strong>' . count($rows) . '</strong> transacciones con <code>stripe_pi</code>.</p>';
        echo '<p>Discrepancias detectadas: <strong>' . count($diffs) . '</strong></p>';
        if (count($diffs) > 0) {
            echo '<form method="POST" action="reparar-pago-estado-real.php?run=1" '
               . 'onsubmit="return confirm(\'¿Actualizar ' . count($diffs) . ' filas?\');">';
            echo '<button class="btn-run" type="submit">Sincronizar con Stripe ahora</button>';
            echo '</form>';
            echo '<table><thead><tr><th>ID</th><th>Pedido</th><th>Cliente</th><th>Monto</th><th>tpago</th>'
               . '<th>DB actual</th><th>Stripe real</th><th>→ Nuevo</th></tr></thead><tbody>';
            foreach ($diffs as $d) {
                echo '<tr class="diff-row">'
                   . '<td>' . (int)$d['id'] . '</td>'
                   . '<td>' . htmlspecialchars($d['pedido'] ?? '') . '</td>'
                   . '<td>' . htmlspecialchars(($d['nombre'] ?? '') . '<br><small>' . ($d['email'] ?? '') . '</small>') . '</td>'
                   . '<td>$' . number_format((float)$d['total'], 2) . '</td>'
                   . '<td>' . htmlspecialchars($d['tpago'] ?? '') . '</td>'
                   . '<td><code>' . htmlspecialchars($d['pago_estado'] ?? '—') . '</code></td>'
                   . '<td><code>' . htmlspecialchars($d['_stripe_status']) . '</code></td>'
                   . '<td><strong>' . htmlspecialchars($d['_expected']) . '</strong></td>'
                   . '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<div class="ok">Todas las transacciones coinciden con Stripe. Nada que cambiar.</div>';
        }
        echo '</div>';
    }
} catch (Throwable $e) {
    echo '<div class="err"><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>
</body>
</html>
