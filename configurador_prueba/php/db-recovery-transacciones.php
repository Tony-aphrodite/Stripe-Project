<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 * VOLTIKA TRANSACCIONES RECOVERY RUNNER
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Purpose:
 *   Restores the 23 historic transacciones records (from 2025-08-30 to
 *   2026-04-06) into the current server DB, preserving the 9 current records.
 *
 * How to use:
 *   1. Upload this file + `recovery_transacciones_2026-04-24.sql` to server
 *   2. DRY RUN first (safe, no changes made):
 *        https://[your-domain]/configurador/php/db-recovery-transacciones.php?key=voltika-recovery-2026&dry=1
 *   3. If dry-run looks correct, APPLY the recovery:
 *        https://[your-domain]/configurador/php/db-recovery-transacciones.php?key=voltika-recovery-2026&apply=1
 *   4. Delete this file after successful recovery
 *
 * Safety guarantees:
 *   - Transactional (auto-rollback on error)
 *   - Uses INSERT IGNORE (no duplicate-key crashes)
 *   - Current records (id 1-9) are never touched
 *   - Dry-run mode shows exactly what would happen
 *   - Requires secret key
 * ═══════════════════════════════════════════════════════════════════════════
 */

header('Content-Type: text/html; charset=utf-8');

// ── Access control ──────────────────────────────────────────────────────────
$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika-recovery-2026') {
    http_response_code(403);
    exit('Forbidden — missing or invalid key');
}

require_once __DIR__ . '/config.php';

// ── Parameters ──────────────────────────────────────────────────────────────
$dryRun = isset($_GET['dry']) && $_GET['dry'] === '1';
$apply  = isset($_GET['apply']) && $_GET['apply'] === '1';

if (!$dryRun && !$apply) {
    exit('Missing mode: add &dry=1 (safe preview) or &apply=1 (real recovery)');
}

// ── Locate recovery SQL file ────────────────────────────────────────────────
$sqlFile = __DIR__ . '/../../recovery_transacciones_2026-04-24.sql';
if (!file_exists($sqlFile)) {
    // Try alternative paths
    $alternatives = [
        __DIR__ . '/../recovery_transacciones_2026-04-24.sql',
        __DIR__ . '/recovery_transacciones_2026-04-24.sql',
        dirname(__DIR__, 2) . '/recovery_transacciones_2026-04-24.sql',
    ];
    foreach ($alternatives as $alt) {
        if (file_exists($alt)) {
            $sqlFile = $alt;
            break;
        }
    }
}
if (!file_exists($sqlFile)) {
    exit("ERROR: recovery_transacciones_2026-04-24.sql not found. Upload it to project root.");
}

// ── Parse recovery SQL to extract the INSERT block ──────────────────────────
$sql = file_get_contents($sqlFile);

// Extract the INSERT IGNORE block (single statement, no transaction wrapper)
if (!preg_match('/INSERT IGNORE INTO `transacciones`.*?;/s', $sql, $m)) {
    exit("ERROR: could not find INSERT IGNORE block in recovery SQL");
}
$insertSql = $m[0];

// Count expected rows
preg_match_all('/^\(/m', $insertSql, $rowMatches);
$expectedRows = count($rowMatches[0]);

// ── HTML header ─────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Voltika — Recovery de transacciones</title>
<style>
  body { font-family: 'Inter', -apple-system, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; background: #f0f4f8; color: #0c2340; }
  .box { background: #fff; padding: 24px; border-radius: 16px; box-shadow: 0 4px 20px rgba(12,35,64,.08); margin-bottom: 16px; }
  h1 { color: #0c2340; font-size: 22px; margin-bottom: 12px; }
  h2 { color: #039fe1; font-size: 16px; margin: 16px 0 8px; }
  .ok { color: #16a34a; font-weight: 700; }
  .err { color: #dc2626; font-weight: 700; }
  .warn { color: #d97706; font-weight: 700; background: #fef3c7; padding: 2px 8px; border-radius: 6px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 10px; }
  th, td { padding: 8px 12px; border-bottom: 1px solid #e2e8f0; text-align: left; }
  th { background: #f5f7fa; font-size: 11px; text-transform: uppercase; color: #64748b; letter-spacing: 0.5px; }
  .dry { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 14px; border-radius: 8px; margin-bottom: 16px; }
  .applied { background: #dcfce7; border-left: 4px solid #16a34a; padding: 14px; border-radius: 8px; margin-bottom: 16px; }
  pre { background: #0c2340; color: #e0f4fd; padding: 14px; border-radius: 8px; overflow-x: auto; font-size: 12px; }
  .kpi { display: inline-block; background: linear-gradient(135deg,#039fe1,#027db0); color: #fff; padding: 10px 18px; border-radius: 10px; font-weight: 800; margin: 4px; }
  .kpi .n { font-size: 22px; display: block; }
  .kpi .l { font-size: 10px; text-transform: uppercase; opacity: 0.8; }
</style>
</head>
<body>

<div class="box">
  <h1>🔧 Voltika · Recovery de transacciones</h1>
  <p><strong>Archivo fuente:</strong> <code><?= htmlspecialchars(basename($sqlFile)) ?></code></p>
  <p><strong>Fecha de ejecución:</strong> <?= date('Y-m-d H:i:s') ?></p>
  <p><strong>Modo:</strong>
    <?php if ($dryRun): ?>
      <span class="warn">DRY RUN — no se aplicarán cambios</span>
    <?php else: ?>
      <span class="ok">APPLY — los cambios serán permanentes</span>
    <?php endif; ?>
  </p>
</div>

<?php

try {
    $pdo = getDB();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── Pre-check: verify transacciones table exists ────────────────────────
    $tableExists = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'transacciones'"
    )->fetchColumn();

    if (!$tableExists) {
        echo "<div class='box'><p class='err'>❌ ERROR: table `transacciones` does not exist in current DB.</p></div>";
        exit;
    }

    // ── Get current count and schema ────────────────────────────────────────
    $countBefore = (int)$pdo->query("SELECT COUNT(*) FROM `transacciones`")->fetchColumn();
    $maxIdBefore = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM `transacciones`")->fetchColumn();

    $schema = $pdo->query("SHOW COLUMNS FROM `transacciones`")->fetchAll(PDO::FETCH_ASSOC);
    $schemaColumns = array_column($schema, 'Field');

    echo "<div class='box'>";
    echo "<h2>📊 Estado actual de la base</h2>";
    echo "<div class='kpi'><span class='l'>Registros actuales</span><span class='n'>$countBefore</span></div>";
    echo "<div class='kpi'><span class='l'>Max ID actual</span><span class='n'>$maxIdBefore</span></div>";
    echo "<div class='kpi'><span class='l'>A restaurar</span><span class='n'>$expectedRows</span></div>";
    echo "<h2>Columnas actuales ({" . count($schemaColumns) . "})</h2>";
    echo "<p><code>" . implode(', ', array_map('htmlspecialchars', $schemaColumns)) . "</code></p>";
    echo "</div>";

    // ── Show preview of current records ─────────────────────────────────────
    echo "<div class='box'>";
    echo "<h2>🟢 Registros actuales (se preservarán)</h2>";
    $current = $pdo->query(
        "SELECT id, pedido, nombre, freg FROM `transacciones` ORDER BY id DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);
    if ($current) {
        echo "<table><tr><th>ID</th><th>Pedido</th><th>Nombre</th><th>Fecha</th></tr>";
        foreach ($current as $r) {
            echo "<tr><td>" . htmlspecialchars($r['id']) . "</td>";
            echo "<td>" . htmlspecialchars($r['pedido']) . "</td>";
            echo "<td>" . htmlspecialchars($r['nombre']) . "</td>";
            echo "<td>" . htmlspecialchars($r['freg']) . "</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p>⚠ No hay registros actuales.</p>";
    }
    echo "</div>";

    // ── DRY RUN: just show what would happen ────────────────────────────────
    if ($dryRun) {
        echo "<div class='dry'>";
        echo "<h2>🧪 Modo DRY RUN activado</h2>";
        echo "<p>Este modo <strong>no modifica la base</strong>. Solo muestra lo que se haría.</p>";
        echo "<p>Si se aplicara, se ejecutaría este INSERT:</p>";
        echo "<pre>" . htmlspecialchars(substr($insertSql, 0, 2000)) . "...\n(truncado — $expectedRows filas en total)</pre>";
        echo "<p><strong>Después de APPLY, habría:</strong></p>";
        echo "<ul>";
        echo "<li>Registros totales estimados: <strong>" . ($countBefore + $expectedRows) . "</strong> (los 9 actuales + 23 restaurados, menos duplicados detectados)</li>";
        echo "<li>Los registros actuales (id 1-9) permanecerán <strong>intactos</strong></li>";
        echo "<li>Los registros restaurados tendrán IDs nuevos (auto-increment, iniciando desde " . ($maxIdBefore + 1) . ")</li>";
        echo "<li>Las columnas nuevas (<code>referido_id</code>, <code>referido_tipo</code>, <code>caso</code>, <code>folio_contrato</code>) quedarán como <code>NULL</code> en los registros restaurados</li>";
        echo "</ul>";
        echo "<p>Para aplicar de verdad, cambia <code>&dry=1</code> por <code>&apply=1</code> en la URL.</p>";
        echo "</div>";
        exit;
    }

    // ── APPLY: execute the INSERT inside a transaction ──────────────────────
    $pdo->beginTransaction();

    $startTime = microtime(true);
    $affected = $pdo->exec($insertSql);
    $elapsed = round((microtime(true) - $startTime) * 1000, 2);

    $countAfter = (int)$pdo->query("SELECT COUNT(*) FROM `transacciones`")->fetchColumn();
    $added = $countAfter - $countBefore;
    $ignored = $expectedRows - $added;

    // ── Verify specific records were restored ───────────────────────────────
    $verify = $pdo->query(
        "SELECT id, pedido, nombre, freg, total, stripe_pi
         FROM `transacciones`
         WHERE pedido IN ('1756526853', '1775502429', '1775414052')
         ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (count($verify) >= 3) {
        $pdo->commit();
        echo "<div class='applied'>";
        echo "<h2>✅ Recovery aplicado con éxito</h2>";
        echo "<div class='kpi'><span class='l'>Filas insertadas</span><span class='n'>$added</span></div>";
        echo "<div class='kpi'><span class='l'>Duplicados ignorados</span><span class='n'>$ignored</span></div>";
        echo "<div class='kpi'><span class='l'>Tiempo</span><span class='n'>{$elapsed}ms</span></div>";
        echo "<div class='kpi'><span class='l'>Total ahora</span><span class='n'>$countAfter</span></div>";
        echo "</div>";

        echo "<div class='box'>";
        echo "<h2>🔍 Verificación de registros clave</h2>";
        echo "<table><tr><th>ID</th><th>Pedido</th><th>Nombre</th><th>Fecha</th><th>Total</th><th>Stripe PI</th></tr>";
        foreach ($verify as $r) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($r['id']) . "</td>";
            echo "<td>" . htmlspecialchars($r['pedido']) . "</td>";
            echo "<td>" . htmlspecialchars($r['nombre']) . "</td>";
            echo "<td>" . htmlspecialchars($r['freg']) . "</td>";
            echo "<td>$" . htmlspecialchars($r['total']) . "</td>";
            echo "<td><code>" . htmlspecialchars($r['stripe_pi'] ?? '—') . "</code></td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "</div>";

        echo "<div class='box'>";
        echo "<h2>📝 Próximos pasos recomendados</h2>";
        echo "<ol>";
        echo "<li>Abre phpMyAdmin y verifica visualmente que los 23 registros nuevos están presentes</li>";
        echo "<li>Revisa que los 9 registros originales (de abril 21-22) siguen intactos</li>";
        echo "<li><strong>Elimina este archivo</strong> (<code>db-recovery-transacciones.php</code>) del servidor por seguridad</li>";
        echo "<li>Considera ejecutar un backup NUEVO ahora con <code>db-backup.php</code> para tener el estado restaurado</li>";
        echo "<li>Para el hueco del 7-20 de abril, revisa el dashboard de Stripe</li>";
        echo "</ol>";
        echo "</div>";
    } else {
        $pdo->rollBack();
        echo "<div class='box'>";
        echo "<h2 class='err'>❌ Verificación fallida — ROLLBACK ejecutado</h2>";
        echo "<p>Se esperaban al menos 3 registros clave pero solo se encontraron " . count($verify) . ".</p>";
        echo "<p>La base de datos <strong>NO fue modificada</strong>.</p>";
        echo "</div>";
    }

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "<div class='box'>";
    echo "<h2 class='err'>❌ ERROR — cambios revertidos</h2>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    echo "<p>Si el transaction estaba activo, se hizo ROLLBACK automáticamente.</p>";
    echo "</div>";
}
?>

<div class="box" style="background:#fef3c7;border-left:4px solid #f59e0b;">
  <h2>⚠ Recordatorio de seguridad</h2>
  <p>Después de ejecutar con éxito, <strong>elimina este archivo del servidor</strong> (<code>db-recovery-transacciones.php</code>) para que no pueda ser ejecutado de nuevo por accidente.</p>
</div>

</body>
</html>
