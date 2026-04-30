<?php
/**
 * VOLTIKA · HISTORICAL DATA FINDER
 * Searches ALL tables for customer data from BEFORE 2026-04-05
 * Read-only. Shows where pre-wipe data might still exist.
 *
 * Usage: ?key=voltika-find-2026
 */

header('Content-Type: text/html; charset=utf-8');
$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika-find-2026') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/config.php';

?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><title>Voltika · Búsqueda histórica</title>
<style>
  body { font-family: 'Inter', sans-serif; max-width: 1300px; margin: 20px auto; padding: 20px; background: #f0f4f8; color: #0c2340; }
  .box { background: #fff; padding: 18px 22px; border-radius: 14px; box-shadow: 0 4px 20px rgba(12,35,64,.07); margin-bottom: 14px; }
  h1 { color: #0c2340; font-size: 22px; }
  h2 { color: #039fe1; font-size: 15px; margin: 0 0 10px; padding-bottom: 6px; border-bottom: 1px solid #e2e8f0; }
  table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
  th, td { padding: 6px 10px; border-bottom: 1px solid #e2e8f0; text-align: left; }
  th { background: #f5f7fa; font-size: 10.5px; text-transform: uppercase; color: #64748b; }
  .ok { color: #16a34a; font-weight: 700; }
  .warn { color: #d97706; font-weight: 700; }
  .err { color: #dc2626; font-weight: 700; }
  .kpi { display: inline-block; background: linear-gradient(135deg,#039fe1,#027db0); color: #fff; padding: 8px 16px; border-radius: 10px; margin: 4px; }
  .kpi.green { background: linear-gradient(135deg,#22c55e,#16a34a); }
  .kpi.warn { background: linear-gradient(135deg,#f59e0b,#d97706); }
  .kpi .n { font-size: 18px; font-weight: 800; display: block; }
  .kpi .l { font-size: 9px; text-transform: uppercase; opacity: .8; }
  code { background: #f1f5f9; padding: 1px 6px; border-radius: 4px; font-size: 10.5px; }
</style></head><body>

<div class="box"><h1>🔍 Voltika · Búsqueda de datos históricos (pre-2026-04-05)</h1></div>

<?php
try {
    $pdo = getDB();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get ALL tables in the database
    $allTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    // Tables likely to have customer/sales data
    $targetTables = [
        'transacciones', 'pedidos', 'facturacion', 'ventas_log',
        'inventario_motos', 'consultas_buro', 'preaprobaciones',
        'verificaciones_identidad', 'documentos_cliente',
        'entregas', 'clientes', 'usuarios', 'ordenes',
        'pagos', 'pagos_log', 'stripe_events', 'webhook_log',
        'notificaciones_enviadas', 'otp_codes', 'sessions',
        'pedido_detalles', 'contratos', 'transacciones_errores',
        'buro_consultas', 'cedis_movimientos'
    ];

    echo "<div class='box'><h2>📚 Tablas en esta base de datos (" . count($allTables) . ")</h2>";
    echo "<p style='font-size:11px;color:#64748b;'>" . implode(' · ', array_map(fn($t) => "<code>$t</code>", $allTables)) . "</p></div>";

    // For each target table that exists, check schema and counts
    foreach ($targetTables as $t) {
        if (!in_array($t, $allTables)) continue;

        $count = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        $cols = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN);

        // Skip if empty
        if ($count === 0) {
            echo "<div class='box'><h2>📦 <code>$t</code></h2><p class='warn'>Vacía (0 registros)</p></div>";
            continue;
        }

        echo "<div class='box'>";
        echo "<h2>📦 <code>$t</code> <span class='kpi green'><span class='l'>Total</span><span class='n'>" . number_format($count) . "</span></span></h2>";

        // Try to find date columns
        $dateCol = null;
        foreach (['freg', 'fecha', 'created_at', 'fecha_registro', 'date', 'timestamp'] as $dc) {
            if (in_array($dc, $cols)) { $dateCol = $dc; break; }
        }

        // Try to find customer info columns
        $nameCol = null;
        foreach (['nombre', 'cliente_nombre', 'customer_name', 'name'] as $nc) {
            if (in_array($nc, $cols)) { $nameCol = $nc; break; }
        }
        $emailCol = null;
        foreach (['email', 'cliente_email', 'correo'] as $ec) {
            if (in_array($ec, $cols)) { $emailCol = $ec; break; }
        }
        $phoneCol = null;
        foreach (['telefono', 'cliente_telefono', 'phone', 'whatsapp'] as $pc) {
            if (in_array($pc, $cols)) { $phoneCol = $pc; break; }
        }

        echo "<p style='font-size:11px;'><strong>Columnas:</strong> " . implode(', ', array_map(fn($c) => "<code>$c</code>", $cols)) . "</p>";

        // If has date column, analyze date distribution
        if ($dateCol) {
            try {
                $pre = (int)$pdo->query("SELECT COUNT(*) FROM `$t` WHERE `$dateCol` < '2026-04-05'")->fetchColumn();
                $post = (int)$pdo->query("SELECT COUNT(*) FROM `$t` WHERE `$dateCol` >= '2026-04-05'")->fetchColumn();
                $min = $pdo->query("SELECT MIN(`$dateCol`) FROM `$t`")->fetchColumn();
                $max = $pdo->query("SELECT MAX(`$dateCol`) FROM `$t`")->fetchColumn();

                echo "<div>";
                echo "<span class='kpi warn'><span class='l'>Antes de 4/5</span><span class='n'>$pre</span></span>";
                echo "<span class='kpi green'><span class='l'>Después de 4/5</span><span class='n'>$post</span></span>";
                echo "<span class='kpi'><span class='l'>Rango</span><span class='n' style='font-size:11px;'>" . htmlspecialchars($min) . " → " . htmlspecialchars($max) . "</span></span>";
                echo "</div>";
            } catch (Exception $e) {
                // date column might be text
            }
        }

        // Show sample records with customer info if available
        if ($nameCol || $emailCol) {
            $selectCols = [];
            $selectCols[] = in_array('id', $cols) ? 'id' : '1 AS id';
            if ($nameCol) $selectCols[] = "`$nameCol` AS nombre";
            if ($emailCol) $selectCols[] = "`$emailCol` AS email";
            if ($phoneCol) $selectCols[] = "`$phoneCol` AS telefono";
            if ($dateCol) $selectCols[] = "`$dateCol` AS fecha";

            $order = $dateCol ? "ORDER BY `$dateCol`" : ($dateCol ? "ORDER BY `$dateCol`" : '');

            $sql = "SELECT " . implode(', ', $selectCols) . " FROM `$t` " . ($dateCol ? "WHERE `$dateCol` < '2026-04-05' $order" : "") . " LIMIT 30";

            try {
                $samples = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                if (count($samples) > 0) {
                    echo "<h3 style='font-size:12px;margin:12px 0 6px;'>📋 Muestra de registros PRE-2026-04-05 (máx 30):</h3>";
                    echo "<table><tr>";
                    foreach (array_keys($samples[0]) as $k) echo "<th>$k</th>";
                    echo "</tr>";
                    foreach ($samples as $r) {
                        echo "<tr>";
                        foreach ($r as $v) {
                            $display = mb_substr((string)$v, 0, 60);
                            echo "<td>" . htmlspecialchars($display) . "</td>";
                        }
                        echo "</tr>";
                    }
                    echo "</table>";
                }
            } catch (Exception $e) {
                echo "<p class='warn'>Error consultando: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
        }

        echo "</div>";
    }

    echo "<div class='box' style='background:#dbeafe;border-left:4px solid #3b82f6;'>";
    echo "<h2>💡 Siguiente paso</h2>";
    echo "<p>Si alguna tabla arriba muestra registros <strong>antes de 2026-04-05</strong>, esos datos pueden cruzarse con transacciones vía email/telefono para enriquecer la información. Comparte esta pantalla y dime qué tabla tiene más datos útiles — creo un script específico para copiar esos campos a transacciones.</p>";
    echo "</div>";

} catch (Throwable $e) {
    echo "<div class='box'><p class='err'>Error: " . htmlspecialchars($e->getMessage()) . "</p></div>";
}
?>
</body></html>
