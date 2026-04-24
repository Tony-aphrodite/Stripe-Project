<?php
/**
 * VOLTIKA · DEEP FORENSICS — LAST RESORT FOR PRE-4/5 DATA
 *
 * Investigates ALL remaining sources that might contain historical customer
 * + shipping data that was wiped from transacciones. Checks:
 *
 *   1. transacciones_errores.payload — captured form JSON from failed inserts
 *   2. notificaciones_log — email bodies sent to customers (may contain shipping)
 *   3. admin_log — administrative actions with full payload
 *   4. MySQL binary log status (gold mine if enabled)
 *   5. Cincel contract PDFs on disk
 *   6. stripe_webhook_phantom payload JSON
 *   7. firmas_contratos extended data
 *   8. verificaciones_identidad webhook_payload JSON
 *
 * Usage: ?key=voltika-forensics-2026
 */

set_time_limit(600);
header('Content-Type: text/html; charset=utf-8');
$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika-forensics-2026') { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/config.php';

?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<title>Voltika · Deep Forensics</title>
<style>
  body { font-family: 'Inter', sans-serif; max-width: 1400px; margin: 20px auto; padding: 20px; background: #f0f4f8; color: #0c2340; }
  .box { background: #fff; padding: 18px 22px; border-radius: 14px; box-shadow: 0 4px 20px rgba(12,35,64,.07); margin-bottom: 14px; }
  h1 { color: #0c2340; font-size: 22px; }
  h2 { color: #039fe1; font-size: 15px; margin: 0 0 10px; padding-bottom: 6px; border-bottom: 1px solid #e2e8f0; }
  h3 { color: #0c2340; font-size: 13px; margin: 14px 0 8px; }
  table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
  th, td { padding: 6px 10px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
  th { background: #f5f7fa; font-size: 10.5px; text-transform: uppercase; color: #64748b; }
  .ok { color: #16a34a; font-weight: 700; }
  .warn { color: #d97706; font-weight: 700; }
  .err { color: #dc2626; font-weight: 700; }
  .gold { background: #fef3c7; padding: 2px 4px; border-radius: 3px; font-weight: 700; color: #92400e; }
  .kpi { display: inline-block; padding: 10px 16px; border-radius: 10px; margin: 4px; color: #fff; }
  .kpi.blue { background: linear-gradient(135deg,#039fe1,#027db0); }
  .kpi.gold { background: linear-gradient(135deg,#f59e0b,#d97706); }
  .kpi.green { background: linear-gradient(135deg,#22c55e,#16a34a); }
  .kpi.red { background: linear-gradient(135deg,#ef4444,#dc2626); }
  .kpi.navy { background: linear-gradient(135deg,#0c2340,#1e3a5f); }
  .kpi .n { font-size: 22px; font-weight: 800; display: block; }
  .kpi .l { font-size: 10px; text-transform: uppercase; opacity: .85; }
  code { background: #f1f5f9; padding: 1px 6px; border-radius: 4px; font-size: 10.5px; }
  .payload { background: #0c2340; color: #e0f4fd; padding: 12px; border-radius: 6px; overflow-x: auto; font-size: 10.5px; max-height: 300px; overflow-y: auto; white-space: pre-wrap; font-family: monospace; margin: 6px 0; }
  .extract { background: #dcfce7; padding: 8px 12px; border-radius: 6px; margin-top: 4px; font-size: 11px; color: #166534; }
  .treasure { background: linear-gradient(135deg,#fef3c7,#fde68a); border-left: 4px solid #f59e0b; padding: 14px 18px; border-radius: 8px; margin-bottom: 10px; color: #92400e; }
</style></head><body>

<div class="box"><h1>🔬 Voltika · Forensics profundo — Última búsqueda de datos pre-4/5</h1></div>

<?php
try {
    $pdo = getDB();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ═══ 1. transacciones_errores — the MOST LIKELY gold mine ══════════════
    echo "<div class='box'><h2>⭐ 1. <code>transacciones_errores</code> — Payloads guardados de intentos fallidos (55 registros)</h2>";
    $teCount = (int)$pdo->query("SELECT COUNT(*) FROM transacciones_errores")->fetchColumn();
    $teWithPayload = (int)$pdo->query("SELECT COUNT(*) FROM transacciones_errores WHERE payload IS NOT NULL AND payload <> ''")->fetchColumn();
    $tePre = (int)$pdo->query("SELECT COUNT(*) FROM transacciones_errores WHERE freg < '2026-04-05'")->fetchColumn();

    echo "<span class='kpi navy'><span class='l'>Total</span><span class='n'>$teCount</span></span>";
    echo "<span class='kpi gold'><span class='l'>Con payload</span><span class='n'>$teWithPayload</span></span>";
    echo "<span class='kpi blue'><span class='l'>Antes 4/5</span><span class='n'>$tePre</span></span>";

    // Show oldest records with payload
    $teRows = $pdo->query("
        SELECT id, nombre, email, telefono, modelo, color, total, stripe_pi, payload, error_msg, freg
        FROM transacciones_errores
        WHERE payload IS NOT NULL AND payload <> ''
        ORDER BY freg ASC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    if ($teRows) {
        echo "<h3>🔍 Primeras 10 con payload (ordenadas por fecha):</h3>";
        foreach ($teRows as $r) {
            echo "<div style='border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin:10px 0;'>";
            echo "<strong>ID {$r['id']}</strong> · " . htmlspecialchars($r['freg']) . " · " . htmlspecialchars($r['nombre'] ?: '(sin nombre)') . " · " . htmlspecialchars($r['email']) . " · \$" . htmlspecialchars($r['total']);
            echo "<br><small>Error: " . htmlspecialchars(mb_substr($r['error_msg'] ?? '', 0, 100)) . "</small>";

            // Try to decode payload
            $payload = $r['payload'];
            $decoded = json_decode($payload, true);
            if ($decoded) {
                // Extract customer info fields
                $c = $decoded['customer'] ?? $decoded;
                $found = [];
                foreach (['nombre','apellidos','email','telefono','rfc','razon','direccion','ciudad','estado','cp','e_nombre','e_telefono','e_direccion','e_ciudad','e_estado','e_cp','modelo','color','tpago','tenvio','punto_nombre'] as $f) {
                    if (isset($c[$f]) && !empty($c[$f])) {
                        $found[$f] = $c[$f];
                    }
                }
                if ($found) {
                    echo "<div class='extract'><strong>📋 Datos extraíbles:</strong><br>";
                    foreach ($found as $k => $v) {
                        echo "<code>$k</code>: <span class='gold'>" . htmlspecialchars(mb_substr((string)$v, 0, 60)) . "</span><br>";
                    }
                    echo "</div>";
                }
            }
            echo "<details><summary style='cursor:pointer;font-size:11px;color:#64748b;'>Ver payload completo</summary>";
            echo "<div class='payload'>" . htmlspecialchars(mb_substr($payload, 0, 2000)) . (strlen($payload) > 2000 ? '…' : '') . "</div>";
            echo "</details>";
            echo "</div>";
        }
    }
    echo "</div>";

    // ═══ 2. notificaciones_log — email bodies ═════════════════════════════════
    echo "<div class='box'><h2>📧 2. <code>notificaciones_log</code> — Cuerpos de emails enviados (483 registros)</h2>";
    $nlCols = $pdo->query("SHOW COLUMNS FROM notificaciones_log")->fetchAll(PDO::FETCH_COLUMN);
    echo "<p><strong>Columnas:</strong> " . implode(', ', array_map(fn($c)=>"<code>$c</code>", $nlCols)) . "</p>";

    // Find date column and body column
    $dateCol = null;
    foreach (['freg','fecha','created_at','fecha_envio'] as $d) if (in_array($d,$nlCols)) { $dateCol = $d; break; }
    $bodyCol = null;
    foreach (['body','mensaje','contenido','content','html_body','cuerpo'] as $c) if (in_array($c,$nlCols)) { $bodyCol = $c; break; }

    if ($dateCol && $bodyCol) {
        $nlPre = (int)$pdo->query("SELECT COUNT(*) FROM notificaciones_log WHERE $dateCol < '2026-04-05'")->fetchColumn();
        echo "<span class='kpi blue'><span class='l'>Antes 4/5</span><span class='n'>$nlPre</span></span>";

        // Find emails with shipping address patterns
        $stmt = $pdo->prepare("
            SELECT * FROM notificaciones_log
            WHERE $dateCol < '2026-04-05'
            AND ($bodyCol LIKE '%Envío a%' OR $bodyCol LIKE '%Direcci%' OR $bodyCol LIKE '%Calle%' OR $bodyCol LIKE '%Recoger en%' OR $bodyCol LIKE '%e_direccion%')
            ORDER BY $dateCol ASC LIMIT 10
        ");
        $stmt->execute();
        $nlRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($nlRows) {
            echo "<div class='treasure'>💎 <strong>" . count($nlRows) . " emails con posible dirección</strong></div>";
            foreach ($nlRows as $n) {
                echo "<div style='border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin:10px 0;'>";
                echo "<strong>ID {$n['id']}</strong> · " . htmlspecialchars($n[$dateCol]);
                if (isset($n['email'])) echo " · " . htmlspecialchars($n['email']);
                if (isset($n['telefono'])) echo " · " . htmlspecialchars($n['telefono']);
                if (isset($n['asunto']) || isset($n['subject'])) echo " · " . htmlspecialchars($n['asunto'] ?? $n['subject']);

                $body = $n[$bodyCol];

                // Try to extract address info
                preg_match_all('/(?:Calle|Av|Avenida|Eje|Bosque|Paseo)[^<>\n]{5,100}/i', $body, $matches);
                if ($matches[0]) {
                    echo "<div class='extract'><strong>📍 Direcciones detectadas:</strong><br>";
                    foreach (array_slice($matches[0], 0, 5) as $m) {
                        echo "• <span class='gold'>" . htmlspecialchars(trim(strip_tags($m))) . "</span><br>";
                    }
                    echo "</div>";
                }

                // Find email patterns
                preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $body, $emails);
                if ($emails[0]) {
                    echo "<div class='extract' style='background:#dbeafe;color:#1e40af;'><strong>📧 Emails detectados:</strong> " . htmlspecialchars(implode(', ', array_unique(array_slice($emails[0], 0, 5)))) . "</div>";
                }

                echo "<details><summary style='cursor:pointer;font-size:11px;color:#64748b;'>Ver cuerpo completo</summary>";
                echo "<div class='payload' style='max-height:400px;'>" . htmlspecialchars(mb_substr(strip_tags($body), 0, 3000)) . "</div>";
                echo "</details>";
                echo "</div>";
            }
        } else {
            echo "<p class='warn'>⚠ No se encontraron emails con patrones de dirección antes del 4/5.</p>";
        }
    }
    echo "</div>";

    // ═══ 3. admin_log ═════════════════════════════════════════════════════════
    echo "<div class='box'><h2>📝 3. <code>admin_log</code> — Log administrativo (1,705 registros)</h2>";
    $alCols = $pdo->query("SHOW COLUMNS FROM admin_log")->fetchAll(PDO::FETCH_COLUMN);
    echo "<p><strong>Columnas:</strong> " . implode(', ', array_map(fn($c)=>"<code>$c</code>", $alCols)) . "</p>";

    $alDateCol = null;
    foreach (['freg','fecha','created_at'] as $d) if (in_array($d,$alCols)) { $alDateCol = $d; break; }

    if ($alDateCol) {
        $alPre = (int)$pdo->query("SELECT COUNT(*) FROM admin_log WHERE $alDateCol < '2026-04-05'")->fetchColumn();
        echo "<span class='kpi blue'><span class='l'>Antes 4/5</span><span class='n'>$alPre</span></span>";

        if ($alPre > 0) {
            // Look for order/sale actions
            $actionCol = in_array('accion', $alCols) ? 'accion' : (in_array('action', $alCols) ? 'action' : null);
            $detailsCol = in_array('detalles', $alCols) ? 'detalles' : (in_array('details', $alCols) ? 'details' : (in_array('data', $alCols) ? 'data' : null));

            $sampleSql = "SELECT * FROM admin_log WHERE $alDateCol < '2026-04-05'";
            if ($actionCol && $detailsCol) {
                $sampleSql .= " AND ($actionCol LIKE '%orden%' OR $actionCol LIKE '%pedido%' OR $actionCol LIKE '%transac%' OR $actionCol LIKE '%venta%' OR $detailsCol LIKE '%direccion%' OR $detailsCol LIKE '%envio%')";
            }
            $sampleSql .= " ORDER BY $alDateCol ASC LIMIT 10";

            $alSamples = $pdo->query($sampleSql)->fetchAll(PDO::FETCH_ASSOC);

            if ($alSamples) {
                echo "<h3>🔍 Muestra con keywords relevantes:</h3>";
                echo "<table><tr>";
                foreach (array_keys($alSamples[0]) as $k) echo "<th>$k</th>";
                echo "</tr>";
                foreach ($alSamples as $r) {
                    echo "<tr>";
                    foreach ($r as $v) {
                        $s = (string)$v;
                        if (strlen($s) > 150) $s = substr($s, 0, 147) . '…';
                        echo "<td>" . htmlspecialchars($s) . "</td>";
                    }
                    echo "</tr>";
                }
                echo "</table>";
            }
        }
    }
    echo "</div>";

    // ═══ 4. MySQL binary log check ═════════════════════════════════════════════
    echo "<div class='box'><h2>💾 4. MySQL Binary Log (log binario)</h2>";
    try {
        $binlogStatus = $pdo->query("SHOW VARIABLES LIKE 'log_bin'")->fetch(PDO::FETCH_ASSOC);
        $binlogExpire = $pdo->query("SHOW VARIABLES LIKE 'expire_logs_days'")->fetch(PDO::FETCH_ASSOC);
        $binlogEnabled = ($binlogStatus['Value'] ?? '') === 'ON';

        if ($binlogEnabled) {
            echo "<div class='treasure'>🎯 <strong>BINARY LOG ESTÁ ACTIVO</strong> — esto es <u>oro puro</u>. Cada INSERT/UPDATE está registrado.</div>";
            echo "<p>Días de retención: <code>" . htmlspecialchars($binlogExpire['Value'] ?? 'desconocido') . "</code></p>";

            try {
                $binlogs = $pdo->query("SHOW BINARY LOGS")->fetchAll(PDO::FETCH_ASSOC);
                if ($binlogs) {
                    echo "<h3>📜 Archivos binlog disponibles:</h3>";
                    echo "<table><tr><th>Archivo</th><th>Tamaño</th></tr>";
                    foreach ($binlogs as $b) {
                        echo "<tr><td><code>" . htmlspecialchars($b['Log_name'] ?? '') . "</code></td>";
                        echo "<td>" . number_format($b['File_size'] ?? 0) . " bytes</td></tr>";
                    }
                    echo "</table>";
                    echo "<div class='extract'>⭐ <strong>ACCIÓN:</strong> Con SSH acceder al servidor y ejecutar <code>mysqlbinlog /var/lib/mysql/LOG_NAME | grep transacciones</code> para recuperar TODOS los INSERTs históricos.</div>";
                }
            } catch (Exception $e) {
                echo "<p class='warn'>No se pudo listar los binlogs (requiere permisos SUPER/REPLICATION CLIENT). Contactar al hosting provider.</p>";
            }
        } else {
            echo "<p class='warn'>⚠ Binary log <strong>NO está activo</strong> (<code>log_bin=" . htmlspecialchars($binlogStatus['Value'] ?? 'OFF') . "</code>). Esta opción no está disponible.</p>";
        }
    } catch (Exception $e) {
        echo "<p class='err'>Error checking binlog: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    echo "</div>";

    // ═══ 5. Cincel contract PDFs ═══════════════════════════════════════════════
    echo "<div class='box'><h2>📄 5. Archivos PDF de contratos Cincel en disco</h2>";

    $searchPaths = [
        __DIR__ . '/../uploads/',
        __DIR__ . '/../../uploads/',
        __DIR__ . '/uploads/',
        __DIR__ . '/../../httpdocs/uploads/',
        '/var/www/vhosts/voltika.mx/httpdocs/uploads/',
        '/var/www/vhosts/voltika.mx/httpdocs/configurador_prueba/uploads/',
        dirname(__DIR__) . '/uploads/contratos/',
        dirname(__DIR__) . '/uploads/documentos/',
    ];

    $pdfFiles = [];
    foreach ($searchPaths as $path) {
        if (is_dir($path)) {
            $files = glob($path . '**/*.pdf', GLOB_BRACE);
            $files = array_merge($files, glob($path . '*.pdf'));
            foreach ($files as $f) {
                $pdfFiles[$f] = ['size' => filesize($f), 'mtime' => filemtime($f)];
            }
        }
    }

    // Also check firmas_contratos for PDF filenames
    try {
        $pdfRows = $pdo->query("SELECT id, nombre, email, pdf_file, freg FROM firmas_contratos WHERE pdf_file IS NOT NULL AND pdf_file <> '' ORDER BY freg ASC")->fetchAll(PDO::FETCH_ASSOC);
        if ($pdfRows) {
            echo "<div class='treasure'>💎 <strong>" . count($pdfRows) . " PDFs de contratos referenciados en firmas_contratos</strong> — cada PDF contiene nombre, RFC, dirección completa del cliente.</div>";
            echo "<table><tr><th>ID</th><th>Fecha</th><th>Nombre</th><th>Email</th><th>PDF File</th></tr>";
            foreach (array_slice($pdfRows, 0, 15) as $p) {
                echo "<tr><td>{$p['id']}</td><td>" . htmlspecialchars($p['freg']) . "</td>";
                echo "<td>" . htmlspecialchars($p['nombre']) . "</td>";
                echo "<td>" . htmlspecialchars($p['email']) . "</td>";
                echo "<td><code>" . htmlspecialchars($p['pdf_file']) . "</code></td></tr>";
            }
            echo "</table>";
            echo "<div class='extract'>⭐ <strong>ACCIÓN:</strong> Cada PDF contiene los datos completos del cliente firmados. Se pueden extraer con <code>pdftotext</code> o parseo manual.</div>";
        }
    } catch (Exception $e) {}

    if (count($pdfFiles) > 0) {
        echo "<h3>📂 PDFs encontrados en disco (" . count($pdfFiles) . "):</h3>";
        $oldPdfs = array_filter($pdfFiles, fn($f) => $f['mtime'] < strtotime('2026-04-05'));
        echo "<p>PDFs anteriores a 4/5: <strong>" . count($oldPdfs) . "</strong></p>";
    } else {
        echo "<p class='warn'>No se localizaron PDFs en rutas comunes desde este script.</p>";
    }
    echo "</div>";

    // ═══ 6. stripe_webhook_phantom payload ════════════════════════════════════
    echo "<div class='box'><h2>👻 6. <code>stripe_webhook_phantom</code> payload JSON</h2>";
    $swCount = (int)$pdo->query("SELECT COUNT(*) FROM stripe_webhook_phantom")->fetchColumn();
    $sw = $pdo->query("SELECT * FROM stripe_webhook_phantom WHERE metadata LIKE '%direccion%' OR metadata LIKE '%e_direccion%' OR metadata LIKE '%nombre%' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    if ($sw) {
        foreach ($sw as $w) {
            echo "<div style='border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin:10px 0;'>";
            echo "<strong>ID {$w['id']}</strong> · " . htmlspecialchars($w['freg']) . " · " . htmlspecialchars($w['email'] ?? '') . " · \$" . htmlspecialchars($w['amount'] ?? '');
            echo "<details><summary style='cursor:pointer;font-size:11px;'>Ver metadata</summary>";
            echo "<div class='payload'>" . htmlspecialchars(mb_substr($w['metadata'] ?? '', 0, 2000)) . "</div>";
            echo "</details>";
            echo "</div>";
        }
    } else {
        echo "<p class='warn'>No se encontraron webhooks con datos de dirección/cliente en metadata.</p>";
    }
    echo "</div>";

    // ═══ 7. verificaciones_identidad webhook_payload ══════════════════════════
    echo "<div class='box'><h2>🔐 7. <code>verificaciones_identidad.webhook_payload</code></h2>";
    try {
        $vi = $pdo->query("SELECT id, nombre, apellidos, email, telefono, webhook_payload, freg FROM verificaciones_identidad WHERE webhook_payload IS NOT NULL LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($vi as $v) {
            echo "<div style='border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin:10px 0;'>";
            echo "<strong>ID {$v['id']}</strong> · " . htmlspecialchars($v['nombre'] . ' ' . $v['apellidos']) . " · " . htmlspecialchars($v['email']);
            echo "<details><summary style='cursor:pointer;font-size:11px;'>Ver payload de webhook Truora</summary>";
            echo "<div class='payload'>" . htmlspecialchars(mb_substr($v['webhook_payload'] ?? '', 0, 2000)) . "</div>";
            echo "</details>";
            echo "</div>";
        }
    } catch (Exception $e) {}
    echo "</div>";

    // ═══ 8. Final Summary ═════════════════════════════════════════════════════
    echo "<div class='treasure'>";
    echo "<h2 style='border:none;margin:0 0 10px;'>🏁 Resumen forense</h2>";
    echo "<p><strong>Fuentes investigadas aquí:</strong></p>";
    echo "<ol>";
    echo "<li><code>transacciones_errores</code> — $teWithPayload payloads guardados ($tePre pre-4/5)</li>";
    echo "<li><code>notificaciones_log</code> — emails enviados con direcciones</li>";
    echo "<li><code>admin_log</code> — acciones administrativas</li>";
    echo "<li>MySQL <strong>binary log</strong> — " . ($binlogEnabled ? 'ACTIVO ⭐' : 'inactivo') . "</li>";
    echo "<li><strong>Cincel PDFs</strong> — contratos firmados con datos completos</li>";
    echo "<li><code>stripe_webhook_phantom</code> payloads</li>";
    echo "<li><code>verificaciones_identidad</code> webhook_payload</li>";
    echo "</ol>";
    echo "<p><strong>Si aún no hay datos pre-4/5 suficientes, las fuentes externas restantes son:</strong></p>";
    echo "<ol>";
    echo "<li><strong>Hosting backups automáticos</strong> — pedir al proveedor backup de fechas específicas</li>";
    echo "<li><strong>Email SMTP archives</strong> — buzón Sent de voltika@riactor.com, aliados@voltika.mx</li>";
    echo "<li><strong>Botmaker WhatsApp dashboard</strong> — mensajes enviados con direcciones</li>";
    echo "<li><strong>Cincel dashboard directo</strong> — descargar todos los contratos firmados</li>";
    echo "</ol>";
    echo "</div>";

} catch (Throwable $e) {
    echo "<div class='box'><p class='err'>Error: " . htmlspecialchars($e->getMessage()) . "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre></p></div>";
}
?>
</body></html>
