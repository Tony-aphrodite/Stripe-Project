<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 * VOLTIKA · STRIPE → DB FULL SYNC RECOVERY
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Purpose:
 *   Queries Stripe's API for ALL historical PaymentIntents, compares against
 *   the current `transacciones` table, and recovers any missing transactions
 *   that exist in Stripe but not in the DB.
 *
 * This is the MOST COMPLETE recovery method because Stripe retains every
 * payment forever, across all dates.
 *
 * Usage:
 *   1. SCAN (safe, read-only — lists missing transactions):
 *        ?key=voltika-stripe-sync-2026&mode=scan
 *
 *   2. PREVIEW SQL (shows what INSERTs would run, no changes):
 *        ?key=voltika-stripe-sync-2026&mode=preview
 *
 *   3. APPLY (actually inserts the missing records, transactional):
 *        ?key=voltika-stripe-sync-2026&mode=apply
 *
 * Optional filters:
 *   &from=2025-01-01  — start date (default: 2025-01-01)
 *   &to=2026-12-31    — end date (default: today)
 *   &status=succeeded — filter status (default: succeeded only)
 *
 * Safety:
 *   - Read-only until mode=apply is used explicitly
 *   - Wrapped in DB transaction (auto-rollback on error)
 *   - Matches by stripe_pi (prevents duplicates of already-recovered records)
 *   - Uses LIVE or TEST Stripe keys based on APP_ENV in .env
 * ═══════════════════════════════════════════════════════════════════════════
 */

set_time_limit(300); // up to 5 minutes for large fetches
header('Content-Type: text/html; charset=utf-8');

$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika-stripe-sync-2026') {
    http_response_code(403);
    exit('Forbidden — missing or invalid key');
}

$mode = $_GET['mode'] ?? '';
if (!in_array($mode, ['scan','preview','apply'])) {
    exit('Missing mode: add &mode=scan (safe scan) or &mode=preview or &mode=apply');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

// ── Parameters ──────────────────────────────────────────────────────────────
$from   = $_GET['from'] ?? '2025-01-01';
$to     = $_GET['to']   ?? date('Y-m-d');
$status = $_GET['status'] ?? 'succeeded';

$fromTs = strtotime($from . ' 00:00:00');
$toTs   = strtotime($to . ' 23:59:59');

if (!$fromTs || !$toTs) {
    exit('Invalid date format. Use YYYY-MM-DD.');
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Voltika · Stripe → DB Sync</title>
<style>
  body { font-family: 'Inter', -apple-system, sans-serif; max-width: 1200px; margin: 30px auto; padding: 20px; background: #f0f4f8; color: #0c2340; }
  .box { background: #fff; padding: 20px 24px; border-radius: 14px; box-shadow: 0 4px 20px rgba(12,35,64,.07); margin-bottom: 14px; }
  h1 { color: #0c2340; font-size: 22px; margin-bottom: 8px; }
  h2 { color: #039fe1; font-size: 16px; margin: 0 0 12px; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; }
  .kpi-row { display: grid; grid-template-columns: repeat(auto-fill,minmax(180px,1fr)); gap: 10px; margin: 8px 0; }
  .kpi { padding: 14px; border-radius: 10px; color: #fff; }
  .kpi.blue { background: linear-gradient(135deg,#039fe1,#027db0); }
  .kpi.navy { background: linear-gradient(135deg,#0c2340,#1e3a5f); }
  .kpi.green { background: linear-gradient(135deg,#22c55e,#16a34a); }
  .kpi.warn { background: linear-gradient(135deg,#f59e0b,#d97706); }
  .kpi.red { background: linear-gradient(135deg,#ef4444,#dc2626); }
  .kpi .n { font-size: 22px; font-weight: 800; display: block; }
  .kpi .l { font-size: 10px; text-transform: uppercase; opacity: .85; letter-spacing: .5px; }
  table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
  th, td { padding: 7px 10px; border-bottom: 1px solid #e2e8f0; text-align: left; }
  th { background: #f5f7fa; font-size: 10.5px; text-transform: uppercase; color: #64748b; letter-spacing: .5px; }
  .ok { color: #16a34a; font-weight: 700; }
  .warn { color: #d97706; font-weight: 700; }
  .err { color: #dc2626; font-weight: 700; }
  code { background: #f1f5f9; padding: 1px 6px; border-radius: 4px; font-size: 12px; font-family: 'SF Mono',Consolas,monospace; }
  .alert-ok { background: #dcfce7; border-left: 4px solid #16a34a; padding: 10px 14px; border-radius: 8px; margin-bottom: 10px; color: #166534; }
  .alert-warn { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 10px 14px; border-radius: 8px; margin-bottom: 10px; color: #92400e; }
  .alert-err { background: #fee2e2; border-left: 4px solid #dc2626; padding: 10px 14px; border-radius: 8px; margin-bottom: 10px; color: #991b1b; }
  pre { background: #0c2340; color: #e0f4fd; padding: 14px; border-radius: 8px; overflow-x: auto; font-size: 11px; max-height: 400px; }
  .mode-tag { display: inline-block; padding: 4px 10px; border-radius: 50px; font-size: 11px; font-weight: 700; margin-left: 8px; }
  .mode-scan { background: #dbeafe; color: #1e40af; }
  .mode-preview { background: #fef3c7; color: #92400e; }
  .mode-apply { background: #dcfce7; color: #166534; }
</style>
</head>
<body>

<div class="box">
  <h1>🔄 Voltika · Stripe → DB Recovery
    <span class="mode-tag mode-<?= $mode ?>"><?= strtoupper($mode) ?></span>
  </h1>
  <p style="font-size:13px;color:#64748b;">
    Rango: <strong><?= htmlspecialchars($from) ?></strong> → <strong><?= htmlspecialchars($to) ?></strong> ·
    Status: <code><?= htmlspecialchars($status) ?></code> ·
    Ejecutado: <?= date('Y-m-d H:i:s') ?>
  </p>
</div>

<?php
try {
    // ── Initialize Stripe ───────────────────────────────────────────────────
    if (!defined('STRIPE_SECRET_KEY') || !STRIPE_SECRET_KEY) {
        throw new Exception('STRIPE_SECRET_KEY no está configurada en .env');
    }
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
    $stripeMode = defined('APP_ENV') ? APP_ENV : 'unknown';

    echo "<div class='box'>";
    echo "<h2>🔑 Conexión Stripe</h2>";
    echo "<p>Modo API: <strong>" . strtoupper($stripeMode) . "</strong> · ";
    echo "Key prefix: <code>" . substr(STRIPE_SECRET_KEY, 0, 12) . "...</code></p>";
    echo "</div>";

    // ── Fetch ALL PaymentIntents from Stripe (paginated) ────────────────────
    echo "<div class='box'>";
    echo "<h2>📥 Descargando historial de Stripe…</h2>";
    echo "<p style='font-size:12px;color:#64748b;'>Puede tardar hasta 1-2 minutos si hay muchas transacciones.</p>";
    flush();

    $allPIs = [];
    $startingAfter = null;
    $pageCount = 0;

    do {
        $pageCount++;
        $params = [
            'limit' => 100,
            'created' => [
                'gte' => $fromTs,
                'lte' => $toTs,
            ],
        ];
        if ($startingAfter) $params['starting_after'] = $startingAfter;

        $response = \Stripe\PaymentIntent::all($params);
        foreach ($response->data as $pi) {
            if ($status === 'all' || $pi->status === $status) {
                $allPIs[] = $pi;
            }
        }

        if ($response->has_more && count($response->data) > 0) {
            $startingAfter = end($response->data)->id;
        } else {
            $startingAfter = null;
        }
    } while ($startingAfter && $pageCount < 50); // safety limit

    echo "<p>✓ Descarga completa: <strong>" . count($allPIs) . "</strong> PaymentIntents con status <code>$status</code> (páginas: $pageCount)</p>";
    echo "</div>";

    // ── Query current DB state ──────────────────────────────────────────────
    $pdo = getDB();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $dbStripePIs = $pdo->query(
        "SELECT stripe_pi FROM transacciones WHERE stripe_pi IS NOT NULL AND stripe_pi <> ''"
    )->fetchAll(PDO::FETCH_COLUMN);
    $dbStripeSet = array_flip($dbStripePIs);

    $dbTotalRows = (int)$pdo->query("SELECT COUNT(*) FROM transacciones")->fetchColumn();

    // ── Classify each Stripe PI as present/missing ──────────────────────────
    $missing = [];
    $present = [];
    foreach ($allPIs as $pi) {
        if (isset($dbStripeSet[$pi->id])) {
            $present[] = $pi;
        } else {
            $missing[] = $pi;
        }
    }

    echo "<div class='box'>";
    echo "<h2>📊 Comparación Stripe ↔ DB</h2>";
    echo "<div class='kpi-row'>";
    echo "<div class='kpi navy'><span class='l'>Total en DB</span><span class='n'>$dbTotalRows</span></div>";
    echo "<div class='kpi blue'><span class='l'>En Stripe (rango)</span><span class='n'>" . count($allPIs) . "</span></div>";
    echo "<div class='kpi green'><span class='l'>Ya sincronizados</span><span class='n'>" . count($present) . "</span></div>";
    echo "<div class='kpi " . (count($missing) > 0 ? 'red' : 'green') . "'><span class='l'>Faltantes en DB</span><span class='n'>" . count($missing) . "</span></div>";
    echo "</div>";
    echo "</div>";

    // ── Show missing transactions ───────────────────────────────────────────
    if (empty($missing)) {
        echo "<div class='alert-ok'><strong>✅ Perfecto — todos los PaymentIntents de Stripe ya están en la DB.</strong> No hay nada que recuperar.</div>";
        exit;
    }

    echo "<div class='box'>";
    echo "<h2>⚠ Transacciones faltantes en DB (" . count($missing) . ")</h2>";
    echo "<table><tr><th>#</th><th>Fecha</th><th>Stripe PI</th><th>Email</th><th>Nombre</th><th>Monto</th><th>Status</th></tr>";

    $totalMissingAmount = 0;
    foreach ($missing as $i => $pi) {
        $email = $pi->receipt_email ?? ($pi->charges->data[0]->billing_details->email ?? '—');
        $name = ($pi->charges->data[0]->billing_details->name ?? '—');
        $amount = ($pi->amount_received ?: $pi->amount) / 100;
        $totalMissingAmount += $amount;
        echo "<tr>";
        echo "<td>" . ($i + 1) . "</td>";
        echo "<td>" . date('Y-m-d H:i', $pi->created) . "</td>";
        echo "<td><code>" . htmlspecialchars($pi->id) . "</code></td>";
        echo "<td>" . htmlspecialchars($email) . "</td>";
        echo "<td>" . htmlspecialchars($name) . "</td>";
        echo "<td>\$" . number_format($amount, 2) . " " . strtoupper($pi->currency) . "</td>";
        echo "<td><span class='" . ($pi->status === 'succeeded' ? 'ok' : 'warn') . "'>" . $pi->status . "</span></td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "<p style='margin-top:10px;font-weight:700;'>Suma total faltante: <span style='color:#dc2626;'>\$" . number_format($totalMissingAmount, 2) . " MXN</span></p>";
    echo "</div>";

    // ── Build INSERT SQL for missing records ────────────────────────────────
    $insertRows = [];
    foreach ($missing as $pi) {
        $created = date('Y-m-d H:i', $pi->created);
        $charge = $pi->charges->data[0] ?? null;
        $email = $pi->receipt_email ?? ($charge->billing_details->email ?? '');
        $name = ($charge->billing_details->name ?? '');
        $phone = ($charge->billing_details->phone ?? '');
        $city = ($charge->billing_details->address->city ?? '');
        $state = ($charge->billing_details->address->state ?? '');
        $postal = ($charge->billing_details->address->postal_code ?? '');
        $amount = ($pi->amount_received ?: $pi->amount) / 100;
        $tpago = 'Tarjeta de débito o crédito';

        // Try to extract model/color/pedido from metadata
        $metadata = $pi->metadata ? $pi->metadata->toArray() : [];
        $modelo = $metadata['modelo'] ?? $metadata['model'] ?? '';
        $color = $metadata['color'] ?? '';
        $pedido = $metadata['pedido'] ?? ('STRIPE-' . substr($pi->id, 3, 12));

        $escape = function($v) use ($pdo) {
            if ($v === null || $v === '') return "''";
            return $pdo->quote((string)$v);
        };

        $insertRows[] = sprintf(
            "(%s, '', %s, %s, %s, '', '', '', %s, %s, %s, '', '', '', '', '', '', %s, %s, %s, '', %s, '0', %s, %s, %s)",
            $escape($pedido),
            $escape($name),
            $escape($phone),
            $escape($email),
            $escape($city),
            $escape($state),
            $escape($postal),
            $escape($modelo),
            $escape($color),
            $escape($tpago),
            $escape((string)$amount),
            $escape((string)$amount),
            $escape($created),
            $escape($pi->id)
        );
    }

    $insertSql = "INSERT IGNORE INTO `transacciones` (`pedido`, `referido`, `nombre`, `telefono`, `email`, `razon`, `rfc`, `direccion`, `ciudad`, `estado`, `cp`, `e_nombre`, `e_telefono`, `e_direccion`, `e_ciudad`, `e_estado`, `e_cp`, `modelo`, `color`, `tpago`, `tenvio`, `precio`, `penvio`, `total`, `freg`, `stripe_pi`) VALUES\n" . implode(",\n", $insertRows) . ";";

    // ── Preview mode: show SQL ──────────────────────────────────────────────
    if ($mode === 'preview' || $mode === 'scan') {
        echo "<div class='box'>";
        echo "<h2>" . ($mode === 'preview' ? '📋 SQL que se ejecutaría' : '🔍 Detalle del SQL (modo SCAN)') . "</h2>";
        echo "<p style='font-size:12px;color:#64748b;'>Columnas omitidas (se llenarán con NULL): <code>referido_id, referido_tipo, caso, folio_contrato, asesoria_placas, seguro_qualitas, punto_id</code>, etc. Los nuevos registros tendrán IDs auto-incrementados.</p>";
        echo "<pre>" . htmlspecialchars($insertSql) . "</pre>";
        echo "</div>";

        echo "<div class='alert-warn'>";
        echo "<strong>Modo " . strtoupper($mode) . ":</strong> No se han hecho cambios. ";
        echo "Para aplicar el INSERT, cambia <code>mode=$mode</code> por <code>mode=apply</code> en la URL.";
        echo "</div>";
        exit;
    }

    // ── APPLY mode: execute INSERT in transaction ───────────────────────────
    $pdo->beginTransaction();
    $startTime = microtime(true);

    $affected = $pdo->exec($insertSql);
    $elapsed = round((microtime(true) - $startTime) * 1000, 2);

    $countAfter = (int)$pdo->query("SELECT COUNT(*) FROM `transacciones`")->fetchColumn();

    $pdo->commit();

    echo "<div class='alert-ok'>";
    echo "<h2 style='color:#166534;'>✅ Recovery aplicado con éxito</h2>";
    echo "<div class='kpi-row'>";
    echo "<div class='kpi green'><span class='l'>Filas insertadas</span><span class='n'>$affected</span></div>";
    echo "<div class='kpi blue'><span class='l'>Tiempo</span><span class='n'>{$elapsed}ms</span></div>";
    echo "<div class='kpi navy'><span class='l'>Total ahora</span><span class='n'>$countAfter</span></div>";
    echo "<div class='kpi warn'><span class='l'>Recuperado</span><span class='n'>\$" . number_format($totalMissingAmount, 2) . "</span></div>";
    echo "</div>";
    echo "</div>";

    // Sample verification
    $verify = $pdo->query(
        "SELECT id, pedido, nombre, freg, total, stripe_pi
         FROM transacciones
         WHERE id > (SELECT MAX(id) - $affected FROM transacciones)
         ORDER BY id DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);
    echo "<div class='box'>";
    echo "<h2>🔍 Últimos 10 registros insertados</h2>";
    echo "<table><tr><th>ID</th><th>Pedido</th><th>Nombre</th><th>Fecha</th><th>Total</th><th>Stripe PI</th></tr>";
    foreach ($verify as $r) {
        echo "<tr>";
        echo "<td>{$r['id']}</td><td>" . htmlspecialchars($r['pedido']) . "</td>";
        echo "<td>" . htmlspecialchars($r['nombre']) . "</td>";
        echo "<td>" . htmlspecialchars($r['freg']) . "</td>";
        echo "<td>\$" . htmlspecialchars($r['total']) . "</td>";
        echo "<td><code>" . htmlspecialchars($r['stripe_pi']) . "</code></td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "</div>";

    echo "<div class='alert-warn'>";
    echo "<strong>⚠ Pasos siguientes:</strong>";
    echo "<ul>";
    echo "<li>Ejecuta un backup nuevo: <code>/configurador_prueba/php/db-backup.php?key=voltika-backup-2026</code></li>";
    echo "<li>Verifica los registros en phpMyAdmin (ordenados por <code>freg</code>)</li>";
    echo "<li>Elimina este archivo del servidor por seguridad</li>";
    echo "<li>Revisa los registros recuperados — los campos <code>rfc</code>, <code>direccion</code>, <code>razon</code> estarán vacíos (Stripe no tiene esa info) y deberán completarse manualmente o desde otro origen</li>";
    echo "</ul>";
    echo "</div>";

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "<div class='alert-err'>";
    echo "<h2 style='color:#991b1b;'>❌ Error</h2>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "\n" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
}
?>

</body>
</html>
