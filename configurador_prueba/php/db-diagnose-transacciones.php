<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 * VOLTIKA TRANSACCIONES · DB DIAGNOSTIC
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Read-only diagnostic tool. Does NOT modify anything.
 *
 * Usage:
 *   https://voltika.mx/configurador_prueba/php/db-diagnose-transacciones.php?key=voltika-diag-2026
 *
 * Checks performed:
 *   1. Total count and ID range
 *   2. Missing ID sequence (gaps)
 *   3. Date coverage (min, max, monthly distribution)
 *   4. Day-by-day gap detection (period with zero transactions)
 *   5. Recovered vs newer records separation
 *   6. Duplicate pedido detection
 *   7. Null/empty field analysis (email, nombre, telefono)
 *   8. Stripe PI coverage
 *   9. Sales amount totals
 *   10. Last-backup-era marker vs post-recovery records
 * ═══════════════════════════════════════════════════════════════════════════
 */

header('Content-Type: text/html; charset=utf-8');

$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika-diag-2026') {
    http_response_code(403);
    exit('Forbidden — missing or invalid key');
}

require_once __DIR__ . '/config.php';

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Voltika — Diagnóstico de transacciones</title>
<style>
  body { font-family: 'Inter', -apple-system, sans-serif; max-width: 1100px; margin: 30px auto; padding: 20px; background: #f0f4f8; color: #0c2340; }
  .box { background: #fff; padding: 20px 24px; border-radius: 14px; box-shadow: 0 4px 20px rgba(12,35,64,.07); margin-bottom: 14px; }
  h1 { color: #0c2340; font-size: 24px; margin-bottom: 8px; }
  h2 { color: #039fe1; font-size: 16px; margin: 0 0 12px; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 8px; }
  .kpi-row { display: grid; grid-template-columns: repeat(auto-fill,minmax(170px,1fr)); gap: 10px; margin: 8px 0; }
  .kpi { background: linear-gradient(135deg,#039fe1,#027db0); color: #fff; padding: 14px; border-radius: 10px; }
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
  code { background: #f1f5f9; padding: 1px 6px; border-radius: 4px; font-size: 12px; }
  .alert-ok { background: #dcfce7; border-left: 4px solid #16a34a; padding: 10px 14px; border-radius: 8px; margin-bottom: 10px; color: #166534; }
  .alert-warn { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 10px 14px; border-radius: 8px; margin-bottom: 10px; color: #92400e; }
  .alert-err { background: #fee2e2; border-left: 4px solid #dc2626; padding: 10px 14px; border-radius: 8px; margin-bottom: 10px; color: #991b1b; }
  .bar-chart { display: flex; gap: 2px; align-items: flex-end; height: 100px; margin: 10px 0; padding: 8px; background: #f5f7fa; border-radius: 8px; }
  .bar-chart .bar { flex: 1; background: linear-gradient(to top,#039fe1,#0284c7); border-radius: 2px 2px 0 0; position: relative; min-height: 4px; }
  .bar-chart .bar::after { content: attr(data-label); position: absolute; bottom: -18px; left: 50%; transform: translateX(-50%); font-size: 9px; white-space: nowrap; color: #64748b; }
  .bar-chart .bar .v { position: absolute; top: -14px; left: 50%; transform: translateX(-50%); font-size: 10px; font-weight: 700; color: #0c2340; }
</style>
</head>
<body>

<div class="box">
  <h1>🩺 Diagnóstico DB · <code>transacciones</code></h1>
  <p style="color:#64748b;font-size:13px;">Ejecutado: <?= date('Y-m-d H:i:s') ?> · Modo: <strong style="color:#16a34a;">SOLO LECTURA</strong> (no modifica nada)</p>
</div>

<?php
try {
    $pdo = getDB();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── 1. Global counters ─────────────────────────────────────────────────
    $stats = $pdo->query("
        SELECT
          COUNT(*) AS total,
          MIN(id) AS min_id,
          MAX(id) AS max_id,
          COUNT(DISTINCT pedido) AS unique_pedidos,
          COUNT(DISTINCT email) AS unique_emails,
          COUNT(DISTINCT telefono) AS unique_phones,
          SUM(CASE WHEN stripe_pi IS NOT NULL AND stripe_pi <> '' THEN 1 ELSE 0 END) AS with_stripe_pi,
          SUM(CASE WHEN email IS NULL OR email = '' THEN 1 ELSE 0 END) AS missing_email,
          SUM(CASE WHEN nombre IS NULL OR nombre = '' THEN 1 ELSE 0 END) AS missing_nombre,
          SUM(CASE WHEN telefono IS NULL OR telefono = '' THEN 1 ELSE 0 END) AS missing_phone,
          SUM(CAST(REPLACE(total,'.','') AS UNSIGNED)) AS sum_total
        FROM transacciones
    ")->fetch(PDO::FETCH_ASSOC);

    echo "<div class='box'>";
    echo "<h2>📊 Estado global</h2>";
    echo "<div class='kpi-row'>";
    echo "<div class='kpi navy'><span class='l'>Total registros</span><span class='n'>" . number_format($stats['total']) . "</span></div>";
    echo "<div class='kpi'><span class='l'>Pedidos únicos</span><span class='n'>" . number_format($stats['unique_pedidos']) . "</span></div>";
    echo "<div class='kpi'><span class='l'>Emails únicos</span><span class='n'>" . number_format($stats['unique_emails']) . "</span></div>";
    echo "<div class='kpi'><span class='l'>Rango ID</span><span class='n'>{$stats['min_id']}–{$stats['max_id']}</span></div>";
    echo "<div class='kpi green'><span class='l'>Con Stripe PI</span><span class='n'>" . number_format($stats['with_stripe_pi']) . "</span></div>";
    echo "<div class='kpi warn'><span class='l'>Suma total</span><span class='n'>\$" . number_format($stats['sum_total']) . "</span></div>";
    echo "</div>";
    echo "</div>";

    // ── 2. ID gap analysis ─────────────────────────────────────────────────
    $ids = $pdo->query("SELECT id FROM transacciones ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $gaps = [];
    for ($i = 1; $i < count($ids); $i++) {
        if ($ids[$i] - $ids[$i-1] > 1) {
            $gaps[] = ['after' => $ids[$i-1], 'before' => $ids[$i], 'missing' => $ids[$i] - $ids[$i-1] - 1];
        }
    }
    echo "<div class='box'>";
    echo "<h2>🔢 Análisis de secuencia de IDs</h2>";
    if (empty($gaps)) {
        echo "<div class='alert-ok'>✓ Secuencia de IDs continua (sin huecos).</div>";
    } else {
        echo "<div class='alert-warn'>⚠ Se detectaron " . count($gaps) . " hueco(s) en la secuencia de IDs (registros eliminados):</div>";
        echo "<table><tr><th>Después de ID</th><th>Antes de ID</th><th>IDs faltantes</th></tr>";
        foreach ($gaps as $g) {
            echo "<tr><td>{$g['after']}</td><td>{$g['before']}</td><td>{$g['missing']} (IDs " . ($g['after']+1) . " – " . ($g['before']-1) . ")</td></tr>";
        }
        echo "</table>";
    }
    echo "</div>";

    // ── 3. Duplicate pedidos ───────────────────────────────────────────────
    $dupes = $pdo->query("
        SELECT pedido, COUNT(*) as cnt, GROUP_CONCAT(id ORDER BY id) as ids
        FROM transacciones
        WHERE pedido <> ''
        GROUP BY pedido HAVING cnt > 1
    ")->fetchAll(PDO::FETCH_ASSOC);
    echo "<div class='box'>";
    echo "<h2>🔁 Pedidos duplicados</h2>";
    if (empty($dupes)) {
        echo "<div class='alert-ok'>✓ Sin pedidos duplicados. Integridad OK.</div>";
    } else {
        echo "<div class='alert-err'>⚠ Se detectaron " . count($dupes) . " pedidos duplicados:</div>";
        echo "<table><tr><th>Pedido</th><th>Repeticiones</th><th>IDs</th></tr>";
        foreach ($dupes as $d) {
            echo "<tr><td><code>" . htmlspecialchars($d['pedido']) . "</code></td><td class='err'>{$d['cnt']}</td><td>{$d['ids']}</td></tr>";
        }
        echo "</table>";
    }
    echo "</div>";

    // ── 4. Date coverage (normalize date format) ────────────────────────────
    $dateStats = $pdo->query("
        SELECT
          MIN(SUBSTRING(REPLACE(freg,' / ',' '), 1, 10)) AS min_date,
          MAX(SUBSTRING(REPLACE(freg,' / ',' '), 1, 10)) AS max_date,
          COUNT(DISTINCT SUBSTRING(REPLACE(freg,' / ',' '), 1, 10)) AS unique_days
        FROM transacciones
        WHERE freg IS NOT NULL AND freg <> ''
    ")->fetch(PDO::FETCH_ASSOC);

    echo "<div class='box'>";
    echo "<h2>📅 Cobertura de fechas</h2>";
    echo "<div class='kpi-row'>";
    echo "<div class='kpi'><span class='l'>Fecha más antigua</span><span class='n'>" . htmlspecialchars($dateStats['min_date']) . "</span></div>";
    echo "<div class='kpi'><span class='l'>Fecha más reciente</span><span class='n'>" . htmlspecialchars($dateStats['max_date']) . "</span></div>";
    echo "<div class='kpi green'><span class='l'>Días con actividad</span><span class='n'>" . number_format($dateStats['unique_days']) . "</span></div>";
    echo "</div>";
    echo "</div>";

    // ── 5. Monthly distribution ─────────────────────────────────────────────
    $monthly = $pdo->query("
        SELECT
          SUBSTRING(REPLACE(freg,' / ',' '), 1, 7) AS ym,
          COUNT(*) AS cnt,
          SUM(CAST(REPLACE(total,'.','') AS UNSIGNED)) AS sum_total
        FROM transacciones
        WHERE freg IS NOT NULL AND freg <> ''
        GROUP BY ym ORDER BY ym
    ")->fetchAll(PDO::FETCH_ASSOC);

    $maxCnt = max(array_column($monthly, 'cnt')) ?: 1;

    echo "<div class='box'>";
    echo "<h2>📈 Distribución mensual</h2>";
    echo "<div class='bar-chart' style='margin-bottom:30px;'>";
    foreach ($monthly as $m) {
        $pct = ($m['cnt'] / $maxCnt) * 100;
        echo "<div class='bar' data-label='" . htmlspecialchars($m['ym']) . "' style='height:{$pct}%;'>";
        echo "<span class='v'>{$m['cnt']}</span>";
        echo "</div>";
    }
    echo "</div>";
    echo "<table><tr><th>Mes</th><th>Registros</th><th>Suma \$</th></tr>";
    foreach ($monthly as $m) {
        echo "<tr><td><strong>" . htmlspecialchars($m['ym']) . "</strong></td><td>{$m['cnt']}</td><td>\$" . number_format($m['sum_total']) . "</td></tr>";
    }
    echo "</table>";
    echo "</div>";

    // ── 6. Recovered vs current records ─────────────────────────────────────
    $backupPedidos = [
        '1756526853','1756527543','1756528241','1756529044',
        '1757993102','1757993584','1757994138',
        '1761168733','1762799790','1762989795','1764810641',
        '1770428461','1772420984','1772489560','1774575788',
        '1775401820','1775408079','1775413940','1775414052',
        '1775485653','1775496686','1775497188','1775502429'
    ];
    $placeholders = implode(',', array_fill(0, count($backupPedidos), '?'));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM transacciones WHERE pedido IN ($placeholders)");
    $stmt->execute($backupPedidos);
    $recoveredCount = (int)$stmt->fetchColumn();
    $newerCount = $stats['total'] - $recoveredCount;

    echo "<div class='box'>";
    echo "<h2>🗂 Origen de los registros</h2>";
    echo "<div class='kpi-row'>";
    echo "<div class='kpi'><span class='l'>Del backup (2025-08 → 04-06)</span><span class='n'>{$recoveredCount} / 23</span></div>";
    echo "<div class='kpi green'><span class='l'>Posteriores al backup</span><span class='n'>{$newerCount}</span></div>";
    echo "</div>";
    if ($recoveredCount === 23) {
        echo "<div class='alert-ok' style='margin-top:10px;'>✓ Los 23 registros del backup están presentes.</div>";
    } else {
        echo "<div class='alert-warn' style='margin-top:10px;'>⚠ Esperaba 23 registros del backup, hay {$recoveredCount}.</div>";
    }
    echo "</div>";

    // ── 7. Field completeness ────────────────────────────────────────────────
    echo "<div class='box'>";
    echo "<h2>📝 Integridad de campos</h2>";
    echo "<table><tr><th>Campo</th><th>Completos</th><th>Vacíos</th><th>% Completitud</th></tr>";

    $fields = ['nombre','email','telefono','rfc','direccion','stripe_pi','razon'];
    foreach ($fields as $f) {
        $row = $pdo->query("
            SELECT
              SUM(CASE WHEN $f IS NOT NULL AND $f <> '' THEN 1 ELSE 0 END) AS filled,
              SUM(CASE WHEN $f IS NULL OR $f = '' THEN 1 ELSE 0 END) AS empty
            FROM transacciones
        ")->fetch(PDO::FETCH_ASSOC);
        $pct = $stats['total'] > 0 ? round($row['filled'] / $stats['total'] * 100, 1) : 0;
        $color = $pct >= 90 ? 'ok' : ($pct >= 60 ? 'warn' : 'err');
        echo "<tr><td><code>$f</code></td><td>{$row['filled']}</td><td>{$row['empty']}</td><td class='$color'>{$pct}%</td></tr>";
    }
    echo "</table>";
    echo "</div>";

    // ── 8. Records by model ─────────────────────────────────────────────────
    $byModel = $pdo->query("
        SELECT modelo, COUNT(*) as cnt, SUM(CAST(REPLACE(total,'.','') AS UNSIGNED)) AS sum_total
        FROM transacciones
        WHERE modelo IS NOT NULL AND modelo <> ''
        GROUP BY modelo ORDER BY cnt DESC LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "<div class='box'>";
    echo "<h2>🏍 Top modelos vendidos</h2>";
    echo "<table><tr><th>Modelo</th><th>Cantidad</th><th>Suma \$</th></tr>";
    foreach ($byModel as $m) {
        echo "<tr><td>" . htmlspecialchars($m['modelo']) . "</td><td>{$m['cnt']}</td><td>\$" . number_format($m['sum_total']) . "</td></tr>";
    }
    echo "</table>";
    echo "</div>";

    // ── 9. Potential data issues ────────────────────────────────────────────
    $emptyNombre = $pdo->query("SELECT COUNT(*) FROM transacciones WHERE nombre IS NULL OR nombre = ''")->fetchColumn();
    $emptyEmail = $pdo->query("SELECT COUNT(*) FROM transacciones WHERE email IS NULL OR email = ''")->fetchColumn();
    $zeroTotal = $pdo->query("SELECT COUNT(*) FROM transacciones WHERE total IS NULL OR total = '' OR total = '0'")->fetchColumn();
    $testLike = $pdo->query("SELECT COUNT(*) FROM transacciones WHERE LOWER(nombre) LIKE '%test%' OR LOWER(nombre) LIKE '%prueba%' OR LOWER(email) LIKE '%test%'")->fetchColumn();

    echo "<div class='box'>";
    echo "<h2>🚩 Posibles incidencias</h2>";
    echo "<table><tr><th>Incidencia</th><th>Cantidad</th><th>Estado</th></tr>";
    echo "<tr><td>Registros sin nombre</td><td>$emptyNombre</td><td>" . ($emptyNombre == 0 ? "<span class='ok'>✓ OK</span>" : "<span class='warn'>⚠ Revisar</span>") . "</td></tr>";
    echo "<tr><td>Registros sin email</td><td>$emptyEmail</td><td>" . ($emptyEmail == 0 ? "<span class='ok'>✓ OK</span>" : "<span class='warn'>⚠ Revisar</span>") . "</td></tr>";
    echo "<tr><td>Registros con total = 0 o vacío</td><td>$zeroTotal</td><td>" . ($zeroTotal == 0 ? "<span class='ok'>✓ OK</span>" : "<span class='warn'>⚠ Revisar</span>") . "</td></tr>";
    echo "<tr><td>Registros con 'test' o 'prueba' (posibles tests)</td><td>$testLike</td><td>" . ($testLike == 0 ? "<span class='ok'>✓ Nada</span>" : "<span class='warn'>⚠ " . $testLike . " registros de prueba</span>") . "</td></tr>";
    echo "</table>";
    echo "</div>";

    // ── 10. Gap detection for April 2026 ────────────────────────────────────
    $aprilDays = $pdo->query("
        SELECT
          DISTINCT SUBSTRING(REPLACE(freg,' / ',' '), 1, 10) AS day
        FROM transacciones
        WHERE freg LIKE '2026-04%' OR freg LIKE '2026/04%'
        ORDER BY day
    ")->fetchAll(PDO::FETCH_COLUMN);

    $allAprilDays = [];
    for ($d = 1; $d <= 24; $d++) {
        $allAprilDays[] = sprintf('2026-04-%02d', $d);
    }
    $missingDays = array_diff($allAprilDays, $aprilDays);

    echo "<div class='box'>";
    echo "<h2>🔍 Análisis del hueco abril 2026</h2>";
    echo "<p style='font-size:12px;color:#64748b;margin-bottom:10px;'>Días con al menos 1 transacción vs. días sin actividad (del 1 al 24 de abril)</p>";
    echo "<div style='display:flex;gap:3px;flex-wrap:wrap;margin:10px 0;'>";
    foreach ($allAprilDays as $day) {
        $hasData = in_array($day, $aprilDays);
        $dayNum = (int)substr($day, -2);
        $color = $hasData ? '#22c55e' : '#fee2e2';
        $textColor = $hasData ? '#fff' : '#991b1b';
        echo "<div title='$day' style='width:32px;height:32px;background:$color;color:$textColor;display:flex;align-items:center;justify-content:center;border-radius:6px;font-weight:700;font-size:12px;'>$dayNum</div>";
    }
    echo "</div>";
    echo "<p style='font-size:12px;'>";
    echo "<span style='display:inline-block;width:12px;height:12px;background:#22c55e;border-radius:3px;vertical-align:middle;'></span> Con transacciones &nbsp;&nbsp;";
    echo "<span style='display:inline-block;width:12px;height:12px;background:#fee2e2;border:1px solid #dc2626;border-radius:3px;vertical-align:middle;'></span> Sin datos";
    echo "</p>";
    if (count($missingDays) > 0) {
        echo "<p class='warn' style='margin-top:8px;'>Días sin datos: " . count($missingDays) . " días</p>";
        echo "<p style='font-size:12px;color:#64748b;'>" . implode(' · ', $missingDays) . "</p>";
    }
    echo "</div>";

    // ── 11. Summary ─────────────────────────────────────────────────────────
    $allOk = empty($gaps) && empty($dupes) && $recoveredCount === 23;
    echo "<div class='box' style='background:" . ($allOk ? '#dcfce7' : '#fef3c7') . ";border-left:4px solid " . ($allOk ? '#16a34a' : '#f59e0b') . ";'>";
    echo "<h2>🏁 Resumen del diagnóstico</h2>";
    echo "<ul style='line-height:1.8;'>";
    echo "<li>Total registros: <strong>{$stats['total']}</strong></li>";
    echo "<li>Registros del backup recuperados: <strong>{$recoveredCount} / 23</strong> " . ($recoveredCount === 23 ? '✓' : '⚠') . "</li>";
    echo "<li>Huecos en IDs: <strong>" . count($gaps) . "</strong></li>";
    echo "<li>Pedidos duplicados: <strong>" . count($dupes) . "</strong></li>";
    echo "<li>Cobertura: <strong>" . htmlspecialchars($dateStats['min_date']) . " → " . htmlspecialchars($dateStats['max_date']) . "</strong></li>";
    echo "<li>Suma total ventas: <strong>\$" . number_format($stats['sum_total']) . " MXN</strong></li>";
    echo "<li>Registros con Stripe PI: <strong>{$stats['with_stripe_pi']} / {$stats['total']}</strong></li>";
    echo "<li>Días sin datos en abril (hueco 7-20): <strong>" . count($missingDays) . "</strong> días</li>";
    echo "</ul>";
    if ($allOk) {
        echo "<p style='margin-top:10px;'><strong>✅ Estado general: SALUDABLE</strong></p>";
    } else {
        echo "<p style='margin-top:10px;'><strong>⚠ Revisa los puntos marcados arriba.</strong></p>";
    }
    echo "</div>";

} catch (Throwable $e) {
    echo "<div class='box' style='background:#fee2e2;border-left:4px solid #dc2626;'>";
    echo "<h2>❌ Error</h2>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    echo "</div>";
}
?>

</body>
</html>
