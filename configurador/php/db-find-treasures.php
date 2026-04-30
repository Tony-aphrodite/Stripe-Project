<?php
/**
 * VOLTIKA · TREASURE DEEP-DIVE
 * Analyzes firmas_contratos, stripe_webhook_phantom, transacciones_pv in detail
 * Shows pre-2026-04-05 data, cross-references, and generates recovery SQL.
 *
 * Usage: ?key=voltika-treasure-2026 [&mode=dry|apply]
 */

set_time_limit(600);
header('Content-Type: text/html; charset=utf-8');
$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika-treasure-2026') { http_response_code(403); exit('Forbidden'); }

$mode = $_GET['mode'] ?? 'analyze';
require_once __DIR__ . '/config.php';

?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<title>Voltika · Treasures</title>
<style>
  body { font-family: 'Inter', sans-serif; max-width: 1400px; margin: 20px auto; padding: 20px; background: #f0f4f8; color: #0c2340; }
  .box { background: #fff; padding: 18px 22px; border-radius: 14px; box-shadow: 0 4px 20px rgba(12,35,64,.07); margin-bottom: 14px; }
  h1 { color: #0c2340; font-size: 22px; }
  h2 { color: #039fe1; font-size: 15px; margin: 0 0 10px; padding-bottom: 6px; border-bottom: 1px solid #e2e8f0; }
  table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
  th, td { padding: 6px 10px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
  th { background: #f5f7fa; font-size: 10.5px; text-transform: uppercase; color: #64748b; }
  .ok { color: #16a34a; font-weight: 700; }
  .warn { color: #d97706; font-weight: 700; }
  .err { color: #dc2626; font-weight: 700; }
  .kpi { display: inline-block; padding: 10px 16px; border-radius: 10px; margin: 4px; color: #fff; }
  .kpi.blue { background: linear-gradient(135deg,#039fe1,#027db0); }
  .kpi.green { background: linear-gradient(135deg,#22c55e,#16a34a); }
  .kpi.red { background: linear-gradient(135deg,#ef4444,#dc2626); }
  .kpi.navy { background: linear-gradient(135deg,#0c2340,#1e3a5f); }
  .kpi.gold { background: linear-gradient(135deg,#f59e0b,#d97706); }
  .kpi .n { font-size: 22px; font-weight: 800; display: block; }
  .kpi .l { font-size: 10px; text-transform: uppercase; opacity: .85; }
  code { background: #f1f5f9; padding: 1px 6px; border-radius: 4px; font-size: 10.5px; }
  .treasure { background: linear-gradient(135deg,#fef3c7,#fde68a); border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 8px; margin-bottom: 10px; }
  .alert-ok { background: #dcfce7; border-left: 4px solid #16a34a; padding: 10px 14px; border-radius: 8px; color: #166534; }
</style></head><body>

<div class="box"><h1>💎 Voltika · Análisis profundo de tesoros de datos</h1>
<p style="font-size:12px;color:#64748b;">Mode: <strong><?= strtoupper($mode) ?></strong></p></div>

<?php
try {
    $pdo = getDB();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ═══ 1. firmas_contratos ═══════════════════════════════════════════════
    echo "<div class='box'><h2>📜 <code>firmas_contratos</code> · Contratos firmados (clientes reales)</h2>";
    $fcCols = $pdo->query("SHOW COLUMNS FROM firmas_contratos")->fetchAll(PDO::FETCH_COLUMN);
    echo "<p><strong>Columnas:</strong> " . implode(', ', array_map(fn($c)=>"<code>$c</code>", $fcCols)) . "</p>";

    $fcCount = (int)$pdo->query("SELECT COUNT(*) FROM firmas_contratos")->fetchColumn();
    echo "<span class='kpi navy'><span class='l'>Total</span><span class='n'>$fcCount</span></span>";

    // Try date column
    $dateCol = null;
    foreach (['freg','fecha_firma','fecha','created_at','fmod'] as $d) {
        if (in_array($d, $fcCols)) { $dateCol = $d; break; }
    }
    if ($dateCol) {
        $pre = (int)$pdo->query("SELECT COUNT(*) FROM firmas_contratos WHERE $dateCol < '2026-04-05'")->fetchColumn();
        $post = (int)$pdo->query("SELECT COUNT(*) FROM firmas_contratos WHERE $dateCol >= '2026-04-05'")->fetchColumn();
        echo "<span class='kpi gold'><span class='l'>Antes de 4/5</span><span class='n'>$pre</span></span>";
        echo "<span class='kpi green'><span class='l'>Después de 4/5</span><span class='n'>$post</span></span>";
    }

    // Sample records
    $fcData = $pdo->query("SELECT * FROM firmas_contratos ORDER BY id LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    if (count($fcData) > 0) {
        echo "<h3 style='margin-top:14px;font-size:13px;'>📋 Todos los contratos:</h3>";
        echo "<div style='overflow-x:auto;max-height:500px;overflow-y:auto;'><table><tr>";
        foreach (array_keys($fcData[0]) as $k) echo "<th>$k</th>";
        echo "</tr>";
        foreach ($fcData as $r) {
            echo "<tr>";
            foreach ($r as $v) echo "<td>" . htmlspecialchars(mb_substr((string)$v, 0, 60)) . "</td>";
            echo "</tr>";
        }
        echo "</table></div>";
    }

    // Cross-reference with transacciones
    $fcInTx = (int)$pdo->query("SELECT COUNT(DISTINCT f.email) FROM firmas_contratos f INNER JOIN transacciones t ON LOWER(t.email) = LOWER(f.email) WHERE f.email <> ''")->fetchColumn();
    $fcNotInTx = (int)$pdo->query("SELECT COUNT(DISTINCT f.email) FROM firmas_contratos f WHERE f.email <> '' AND NOT EXISTS (SELECT 1 FROM transacciones t WHERE LOWER(t.email) = LOWER(f.email))")->fetchColumn();
    echo "<div style='margin-top:10px;'>";
    echo "<span class='kpi blue'><span class='l'>Emails en transacciones</span><span class='n'>$fcInTx</span></span>";
    echo "<span class='kpi red'><span class='l'>Emails NO en transacciones</span><span class='n'>$fcNotInTx</span></span>";
    echo "</div>";
    echo "</div>";

    // ═══ 2. stripe_webhook_phantom ═════════════════════════════════════════
    echo "<div class='box'><h2>👻 <code>stripe_webhook_phantom</code> · Webhooks huérfanos de Stripe</h2>";
    $swCols = $pdo->query("SHOW COLUMNS FROM stripe_webhook_phantom")->fetchAll(PDO::FETCH_COLUMN);
    echo "<p><strong>Columnas:</strong> " . implode(', ', array_map(fn($c)=>"<code>$c</code>", $swCols)) . "</p>";

    $swCount = (int)$pdo->query("SELECT COUNT(*) FROM stripe_webhook_phantom")->fetchColumn();
    echo "<span class='kpi navy'><span class='l'>Total</span><span class='n'>$swCount</span></span>";

    $dateColSw = null;
    foreach (['freg','fecha','created_at','timestamp','event_time'] as $d) {
        if (in_array($d, $swCols)) { $dateColSw = $d; break; }
    }
    if ($dateColSw) {
        $pre = (int)$pdo->query("SELECT COUNT(*) FROM stripe_webhook_phantom WHERE $dateColSw < '2026-04-05'")->fetchColumn();
        $post = (int)$pdo->query("SELECT COUNT(*) FROM stripe_webhook_phantom WHERE $dateColSw >= '2026-04-05'")->fetchColumn();
        echo "<span class='kpi gold'><span class='l'>Antes de 4/5</span><span class='n'>$pre</span></span>";
        echo "<span class='kpi green'><span class='l'>Después de 4/5</span><span class='n'>$post</span></span>";
    }

    $swData = $pdo->query("SELECT * FROM stripe_webhook_phantom ORDER BY id LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    if (count($swData) > 0) {
        echo "<h3 style='margin-top:14px;font-size:13px;'>📋 Primeros 50 webhooks:</h3>";
        echo "<div style='overflow-x:auto;max-height:500px;overflow-y:auto;'><table><tr>";
        foreach (array_keys($swData[0]) as $k) echo "<th>$k</th>";
        echo "</tr>";
        foreach ($swData as $r) {
            echo "<tr>";
            foreach ($r as $v) {
                $s = (string)$v;
                if (strlen($s) > 80) $s = substr($s, 0, 77) . '…';
                echo "<td>" . htmlspecialchars($s) . "</td>";
            }
            echo "</tr>";
        }
        echo "</table></div>";
    }

    // Cross-reference: webhooks NOT in transacciones
    $swNotInTx = (int)$pdo->query("
        SELECT COUNT(*) FROM stripe_webhook_phantom sw
        WHERE sw.stripe_pi IS NOT NULL AND sw.stripe_pi <> ''
          AND NOT EXISTS (SELECT 1 FROM transacciones t WHERE t.stripe_pi = sw.stripe_pi)
    ")->fetchColumn();
    echo "<div style='margin-top:10px;'>";
    echo "<span class='kpi red'><span class='l'>Webhooks sin transacciones</span><span class='n'>$swNotInTx</span></span>";
    echo "</div>";
    echo "</div>";

    // ═══ 3. transacciones_pv ═══════════════════════════════════════════════
    echo "<div class='box'><h2>🏪 <code>transacciones_pv</code> · Transacciones en Puntos Voltika (tiene e_* fields!)</h2>";
    $pvCols = $pdo->query("SHOW COLUMNS FROM transacciones_pv")->fetchAll(PDO::FETCH_COLUMN);
    echo "<p><strong>Columnas:</strong> " . implode(', ', array_map(fn($c)=>"<code>$c</code>", $pvCols)) . "</p>";

    $pvData = $pdo->query("SELECT * FROM transacciones_pv ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    echo "<span class='kpi navy'><span class='l'>Total</span><span class='n'>" . count($pvData) . "</span></span>";

    if (count($pvData) > 0) {
        echo "<h3 style='margin-top:14px;font-size:13px;'>📋 Registros completos:</h3>";
        echo "<div style='overflow-x:auto;'><table><tr>";
        foreach (array_keys($pvData[0]) as $k) echo "<th>$k</th>";
        echo "</tr>";
        foreach ($pvData as $r) {
            echo "<tr>";
            foreach ($r as $v) {
                $s = (string)$v;
                if (strlen($s) > 80) $s = substr($s, 0, 77) . '…';
                echo "<td>" . htmlspecialchars($s) . "</td>";
            }
            echo "</tr>";
        }
        echo "</table></div>";
    }
    echo "</div>";

    // ═══ 4. Additional exploration ═════════════════════════════════════════
    echo "<div class='box'><h2>🔎 Exploración extra</h2>";

    // clientes table
    echo "<h3 style='font-size:13px;'>📇 <code>clientes</code> (7 registros)</h3>";
    $cliCols = $pdo->query("SHOW COLUMNS FROM clientes")->fetchAll(PDO::FETCH_COLUMN);
    echo "<p><strong>Columnas:</strong> " . implode(', ', array_map(fn($c)=>"<code>$c</code>", $cliCols)) . "</p>";
    $cli = $pdo->query("SELECT * FROM clientes ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    if ($cli) {
        echo "<table><tr>";
        foreach (array_keys($cli[0]) as $k) echo "<th>$k</th>";
        echo "</tr>";
        foreach ($cli as $r) {
            echo "<tr>";
            foreach ($r as $v) echo "<td>" . htmlspecialchars(mb_substr((string)$v, 0, 60)) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

    // Check for admin_log (might have old order records)
    try {
        $alCount = (int)$pdo->query("SELECT COUNT(*) FROM admin_log")->fetchColumn();
        echo "<p style='margin-top:16px;'><code>admin_log</code> tiene <strong>$alCount</strong> registros</p>";
    } catch (Exception $e) {}

    // Check for notificaciones_log
    try {
        $nlCount = (int)$pdo->query("SELECT COUNT(*) FROM notificaciones_log")->fetchColumn();
        echo "<p><code>notificaciones_log</code> tiene <strong>$nlCount</strong> registros · puede contener direcciones en el cuerpo del mensaje</p>";
    } catch (Exception $e) {}

    echo "</div>";

    // ═══ 5. Recovery recommendation ════════════════════════════════════════
    echo "<div class='treasure'>";
    echo "<h2 style='color:#92400e;border:none;margin:0 0 8px;'>🏆 RECOMENDACIÓN DE RECOVERY</h2>";
    echo "<p>Basado en este análisis:</p>";
    echo "<ol style='line-height:1.8;'>";
    echo "<li><strong>firmas_contratos ($fcCount)</strong> es la fuente <strong>MÁS valiosa</strong> — son clientes reales que firmaron contrato. Revisa arriba cuántos son anteriores a 4/5.</li>";
    echo "<li><strong>stripe_webhook_phantom ($swCount)</strong> representa pagos <strong>que Stripe confirmó pero se perdieron en transacciones</strong>. Son recuperables si existen antes de 4/5.</li>";
    echo "<li><strong>transacciones_pv (" . count($pvData) . ")</strong> tiene datos completos con <code>e_*</code> — si hay registros antes de 4/5 podemos copiarlos a transacciones.</li>";
    echo "<li><strong>clientes (7)</strong> puede tener direcciones históricas usables vía email matching.</li>";
    echo "</ol>";
    echo "<p><strong>Siguiente paso:</strong> Revisa esta pantalla y dime:<br>";
    echo "1. ¿Cuántos registros en firmas_contratos son PRE-4/5? (verás en las kpi arriba)<br>";
    echo "2. ¿stripe_webhook_phantom tiene fechas antiguas?<br>";
    echo "3. ¿transacciones_pv tiene datos con dirección de envío?</p>";
    echo "<p>Con esa respuesta, construyo el script final que unifica todo en transacciones.</p>";
    echo "</div>";

} catch (Throwable $e) {
    echo "<div class='box'><p class='err'>Error: " . htmlspecialchars($e->getMessage()) . "</p></div>";
}
?>
</body></html>
