<?php
/**
 * VOLTIKA · LOG TABLES MINING
 * Searches admin_log and notificaciones_log for pre-2026-04-05 customer data.
 * These are the LAST hope for finding historical order data in the database.
 *
 * Usage: ?key=voltika-logs-2026
 */

set_time_limit(600);
header('Content-Type: text/html; charset=utf-8');
$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika-logs-2026') { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/config.php';

?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<title>Voltika · Minería de logs</title>
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
  .kpi.gold { background: linear-gradient(135deg,#f59e0b,#d97706); }
  .kpi.green { background: linear-gradient(135deg,#22c55e,#16a34a); }
  .kpi.red { background: linear-gradient(135deg,#ef4444,#dc2626); }
  .kpi.navy { background: linear-gradient(135deg,#0c2340,#1e3a5f); }
  .kpi .n { font-size: 22px; font-weight: 800; display: block; }
  .kpi .l { font-size: 10px; text-transform: uppercase; opacity: .85; }
  code { background: #f1f5f9; padding: 1px 6px; border-radius: 4px; font-size: 10.5px; }
  .preview { background: #f9fafb; padding: 10px; border-radius: 6px; font-family: monospace; font-size: 10.5px; white-space: pre-wrap; max-height: 200px; overflow-y: auto; }
  .hit { background: #dcfce7; }
  .alert { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 14px 18px; border-radius: 8px; margin-bottom: 10px; color: #92400e; }
</style></head><body>

<div class="box"><h1>⛏ Voltika · Minería de logs — última esperanza pre-4/5</h1></div>

<?php
try {
    $pdo = getDB();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ═══ admin_log ══════════════════════════════════════════════════════════
    echo "<div class='box'><h2>📋 <code>admin_log</code> · Registros de actividad administrativa</h2>";
    $alCols = $pdo->query("SHOW COLUMNS FROM admin_log")->fetchAll(PDO::FETCH_COLUMN);
    echo "<p><strong>Columnas:</strong> " . implode(', ', array_map(fn($c)=>"<code>$c</code>", $alCols)) . "</p>";

    $alCount = (int)$pdo->query("SELECT COUNT(*) FROM admin_log")->fetchColumn();
    echo "<span class='kpi navy'><span class='l'>Total</span><span class='n'>" . number_format($alCount) . "</span></span>";

    $alDateCol = null;
    foreach (['freg','fecha','created_at','timestamp'] as $d) if (in_array($d,$alCols)) { $alDateCol = $d; break; }

    if ($alDateCol) {
        $pre = (int)$pdo->query("SELECT COUNT(*) FROM admin_log WHERE $alDateCol < '2026-04-05'")->fetchColumn();
        $post = (int)$pdo->query("SELECT COUNT(*) FROM admin_log WHERE $alDateCol >= '2026-04-05'")->fetchColumn();
        $min = $pdo->query("SELECT MIN($alDateCol) FROM admin_log")->fetchColumn();
        echo "<span class='kpi gold'><span class='l'>Antes de 4/5</span><span class='n'>$pre</span></span>";
        echo "<span class='kpi green'><span class='l'>Después de 4/5</span><span class='n'>$post</span></span>";
        echo "<span class='kpi blue'><span class='l'>Más antiguo</span><span class='n' style='font-size:11px;'>" . htmlspecialchars($min) . "</span></span>";

        if ($pre > 0) {
            // Show sample pre-April-5 records
            $samples = $pdo->query("SELECT * FROM admin_log WHERE $alDateCol < '2026-04-05' ORDER BY $alDateCol LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
            echo "<h3 style='font-size:13px;margin-top:14px;'>📋 Muestra PRE-4/5:</h3>";
            echo "<div style='overflow-x:auto;max-height:500px;overflow-y:auto;'><table><tr>";
            foreach (array_keys($samples[0]) as $k) echo "<th>$k</th>";
            echo "</tr>";
            foreach ($samples as $r) {
                echo "<tr>";
                foreach ($r as $k => $v) {
                    $s = (string)$v;
                    // Detect email/phone/RFC patterns
                    $isGold = preg_match('/@[a-z0-9.-]+\.\w+|\d{10,13}|[A-Z]{3,4}\d{6}/i', $s);
                    $cls = $isGold ? 'hit' : '';
                    if (strlen($s) > 120) $s = substr($s, 0, 117) . '…';
                    echo "<td class='$cls'>" . htmlspecialchars($s) . "</td>";
                }
                echo "</tr>";
            }
            echo "</table></div>";
        }
    }

    // Search for patterns that might indicate order/customer data
    $searchPatterns = [
        'compra' => 'Compras',
        'pedido' => 'Pedidos',
        'transaccion' => 'Transacciones',
        'venta' => 'Ventas',
        'order' => 'Órdenes',
        'stripe' => 'Stripe',
        'pago' => 'Pagos',
    ];

    echo "<h3 style='font-size:13px;margin-top:16px;'>🔎 Búsqueda por palabras clave:</h3>";
    echo "<table><tr><th>Palabra</th><th>Descripción</th><th>Hits</th></tr>";
    foreach ($searchPatterns as $pattern => $desc) {
        $actionCol = in_array('accion', $alCols) ? 'accion' : (in_array('action', $alCols) ? 'action' : null);
        $detailsCol = in_array('detalles', $alCols) ? 'detalles' : (in_array('details', $alCols) ? 'details' : null);

        $cnt = 0;
        if ($actionCol) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_log WHERE $actionCol LIKE ?");
            $stmt->execute(["%$pattern%"]);
            $cnt += (int)$stmt->fetchColumn();
        }
        if ($detailsCol) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_log WHERE $detailsCol LIKE ?");
            $stmt->execute(["%$pattern%"]);
            $cnt += (int)$stmt->fetchColumn();
        }
        echo "<tr><td><code>$pattern</code></td><td>$desc</td><td><strong>$cnt</strong></td></tr>";
    }
    echo "</table>";
    echo "</div>";

    // ═══ notificaciones_log ═══════════════════════════════════════════════════
    echo "<div class='box'><h2>📬 <code>notificaciones_log</code> · Notificaciones enviadas (email/SMS/WhatsApp)</h2>";
    $nlCols = $pdo->query("SHOW COLUMNS FROM notificaciones_log")->fetchAll(PDO::FETCH_COLUMN);
    echo "<p><strong>Columnas:</strong> " . implode(', ', array_map(fn($c)=>"<code>$c</code>", $nlCols)) . "</p>";

    $nlCount = (int)$pdo->query("SELECT COUNT(*) FROM notificaciones_log")->fetchColumn();
    echo "<span class='kpi navy'><span class='l'>Total</span><span class='n'>" . number_format($nlCount) . "</span></span>";

    $nlDateCol = null;
    foreach (['freg','fecha','created_at','timestamp','fecha_envio'] as $d) if (in_array($d,$nlCols)) { $nlDateCol = $d; break; }

    if ($nlDateCol) {
        $pre = (int)$pdo->query("SELECT COUNT(*) FROM notificaciones_log WHERE $nlDateCol < '2026-04-05'")->fetchColumn();
        $post = (int)$pdo->query("SELECT COUNT(*) FROM notificaciones_log WHERE $nlDateCol >= '2026-04-05'")->fetchColumn();
        $min = $pdo->query("SELECT MIN($nlDateCol) FROM notificaciones_log")->fetchColumn();
        echo "<span class='kpi gold'><span class='l'>Antes de 4/5</span><span class='n'>$pre</span></span>";
        echo "<span class='kpi green'><span class='l'>Después de 4/5</span><span class='n'>$post</span></span>";
        echo "<span class='kpi blue'><span class='l'>Más antiguo</span><span class='n' style='font-size:11px;'>" . htmlspecialchars($min) . "</span></span>";

        if ($pre > 0) {
            $samples = $pdo->query("SELECT * FROM notificaciones_log WHERE $nlDateCol < '2026-04-05' ORDER BY $nlDateCol LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
            echo "<h3 style='font-size:13px;margin-top:14px;'>📋 Muestra PRE-4/5:</h3>";
            echo "<div style='overflow-x:auto;max-height:500px;overflow-y:auto;'><table><tr>";
            foreach (array_keys($samples[0]) as $k) echo "<th>$k</th>";
            echo "</tr>";
            foreach ($samples as $r) {
                echo "<tr>";
                foreach ($r as $k => $v) {
                    $s = (string)$v;
                    $isGold = preg_match('/@[a-z0-9.-]+\.\w+|calle|avenida|[A-Z]{3,4}\d{6}|\d{5}\s+[A-Z]/i', $s);
                    $cls = $isGold ? 'hit' : '';
                    if (strlen($s) > 200) $s = substr($s, 0, 197) . '…';
                    echo "<td class='$cls'>" . htmlspecialchars($s) . "</td>";
                }
                echo "</tr>";
            }
            echo "</table></div>";
        }
    }
    echo "</div>";

    // ═══ Also check notificaciones_log for addresses in body content ══════════
    echo "<div class='box'><h2>🔍 Búsqueda de direcciones en cuerpo de notificaciones</h2>";
    $bodyCol = null;
    foreach (['body','mensaje','contenido','content','html'] as $c) if (in_array($c, $nlCols)) { $bodyCol = $c; break; }

    if ($bodyCol) {
        echo "<p>Columna de cuerpo detectada: <code>$bodyCol</code></p>";

        // Look for messages that might contain addresses
        $addressSearch = $pdo->query("
            SELECT id, $bodyCol
            FROM notificaciones_log
            WHERE $bodyCol LIKE '%Calle%' OR $bodyCol LIKE '%Av%' OR $bodyCol LIKE '%CP%' OR $bodyCol LIKE '%CDMX%' OR $bodyCol LIKE '%código postal%'
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

        if ($addressSearch) {
            echo "<p class='ok'>✓ Se encontraron " . count($addressSearch) . " notificaciones con direcciones (muestra):</p>";
            foreach ($addressSearch as $n) {
                echo "<div class='preview' style='margin:6px 0;'><strong>ID {$n['id']}:</strong><br>" . htmlspecialchars(mb_substr($n[$bodyCol], 0, 500)) . "…</div>";
            }
        } else {
            echo "<p class='warn'>No se encontraron direcciones en notificaciones.</p>";
        }
    }
    echo "</div>";

    // ═══ Summary ══════════════════════════════════════════════════════════════
    echo "<div class='alert'>";
    echo "<h2 style='color:#92400e;border:none;margin-bottom:8px;'>🏁 CONCLUSIÓN HONESTA</h2>";
    echo "<p>Si los logs arriba tampoco tienen datos pre-4/5, entonces:</p>";
    echo "<ul>";
    echo "<li><strong>La base de datos local NO tiene más información recuperable de antes del 5 de abril</strong> que los 15 registros ya restaurados.</li>";
    echo "<li>Las únicas fuentes externas que podrían ayudar:";
    echo "<ol style='margin-top:4px;'>";
    echo "<li><strong>Backups automáticos del hosting</strong> (preguntar al proveedor de hosting por backups de 2025 y 2026)</li>";
    echo "<li><strong>Buzón de emails enviados</strong> (<code>voltika@riactor.com</code>, <code>aliados@voltika.mx</code>, <code>dm@voltika.mx</code>)</li>";
    echo "<li><strong>Cuenta Stripe LIVE</strong> (<code>51QpalAD</code>) — si tiene registros antiguos</li>";
    echo "<li><strong>Dispositivos del equipo Voltika</strong> — si alguien guardó copias locales</li>";
    echo "</ol></li>";
    echo "</ul>";
    echo "</div>";

} catch (Throwable $e) {
    echo "<div class='box'><p class='err'>Error: " . htmlspecialchars($e->getMessage()) . "</p></div>";
}
?>
</body></html>
