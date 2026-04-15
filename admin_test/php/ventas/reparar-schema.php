<?php
/**
 * POST — One-shot schema repair.
 *
 * Iterates `transacciones` and `clientes` (the two tables whose legacy
 * prod schemas have lots of `text NOT NULL DEFAULT NULL` columns) and
 * runs an `ALTER TABLE ... MODIFY COLUMN col <ORIGINAL_TYPE> NULL` per
 * offending column. The original type is read from INFORMATION_SCHEMA so
 * we never change `text → varchar` (which was silently failing before).
 *
 * Why necessary: confirmar-orden.php's `ensureTransaccionesColumns()` was
 * hardcoded to set specific columns to VARCHAR, which failed on the legacy
 * TEXT columns and was swallowed by try/catch. Result: 17 orphan orders.
 *
 * Safe to re-run (idempotent). Reports per-column success/failure so the
 * admin can see exactly what was changed.
 *
 * Body: { dry_run: bool } — optional preview mode.
 * Response: { ok, tables: [{ table, changes: [{ column, sql, ok, error }] }] }
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$body   = adminJsonIn();
$dryRun = !empty($body['dry_run']);

$pdo = getDB();

// Tables whose prod schema has the "text NOT NULL" time-bomb pattern.
// Add more tables here if the diagnostic reports problems in them later.
$tables = ['transacciones', 'clientes'];

$results = [];

foreach ($tables as $table) {
    $entry = ['table' => $table, 'changes' => [], 'skipped' => 0];

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $entry['error'] = 'SHOW COLUMNS falló: ' . $e->getMessage();
        $results[] = $entry;
        continue;
    }

    foreach ($cols as $c) {
        $name    = $c['Field'];
        $type    = $c['Type'];          // e.g. "text", "varchar(200)", "int(11)"
        $null    = $c['Null'];          // "YES" or "NO"
        $default = $c['Default'];       // raw default or null
        $extra   = $c['Extra'] ?? '';

        // Skip auto_increment and columns already nullable
        if (strpos($extra, 'auto_increment') !== false) { $entry['skipped']++; continue; }
        if ($null === 'YES') { $entry['skipped']++; continue; }

        // Skip columns that already have a default (those won't 1364 on INSERT omit)
        if ($default !== null) { $entry['skipped']++; continue; }

        // Build the MODIFY COLUMN preserving the original type. This avoids
        // the text→varchar conversion that was silently failing before.
        $sql = "ALTER TABLE `{$table}` MODIFY COLUMN `{$name}` {$type} NULL DEFAULT NULL";

        $change = [
            'column' => $name,
            'type'   => $type,
            'sql'    => $sql,
        ];

        if ($dryRun) {
            $change['ok']     = true;
            $change['dryrun'] = true;
        } else {
            try {
                $pdo->exec($sql);
                $change['ok'] = true;
            } catch (Throwable $e) {
                $change['ok']    = false;
                $change['error'] = $e->getMessage();
            }
        }
        $entry['changes'][] = $change;
    }
    $results[] = $entry;
}

// Summary counts
$totalChanges = 0;
$totalOk      = 0;
$totalFail    = 0;
foreach ($results as $r) {
    foreach ($r['changes'] ?? [] as $ch) {
        $totalChanges++;
        if (!empty($ch['ok'])) $totalOk++; else $totalFail++;
    }
}

adminLog('reparar_schema', [
    'dry_run' => $dryRun,
    'changes' => $totalChanges,
    'ok'      => $totalOk,
    'fail'    => $totalFail,
]);

adminJsonOut([
    'ok'             => $totalFail === 0,
    'dry_run'        => $dryRun,
    'total_changes'  => $totalChanges,
    'ok_count'       => $totalOk,
    'fail_count'     => $totalFail,
    'tables'         => $results,
    'siguiente_paso' => $totalFail === 0
        ? ($dryRun
            ? 'Dry-run completo. Ejecuta de nuevo sin dry_run para aplicar.'
            : 'Schema reparado. Ahora corre POST /admin/php/ventas/recuperar-lote.php para promover los huérfanos.')
        : 'Hubo fallos. Revisa `tables[*].changes[*].error` para detalles.',
]);
