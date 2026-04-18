<?php
/**
 * POST — One-shot schema migration for Checklist de Origen Phase 2.
 *
 * Adds 10 per-category photo JSON columns + 2 new binary fields to
 * `checklist_origen`. Backfills `foto_unidad_completa` from the legacy
 * `fotos` column so existing completed rows keep their photos visible.
 *
 * Safe to re-run (idempotent): uses INFORMATION_SCHEMA to skip columns
 * that already exist, and only backfills rows where the target column
 * is still NULL/empty.
 *
 * Body: { dry_run: bool }
 * Response: { ok, dry_run, added_columns, skipped_columns, backfilled, errors }
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin']);

$body   = adminJsonIn();
$dryRun = !empty($body['dry_run']);

$pdo = getDB();

$newJsonCols = [
    'foto_unidad_completa',
    'foto_vin',
    'foto_tablero_encendido',
    'foto_bateria',
    'foto_contenido_previo_cierre',
    'foto_caja_cerrada',
    'foto_sellos',
    'foto_detalle_calcomanias',
    'foto_empaque_accesorios',
    'foto_empaque_llaves',
];
$newBinaryCols = [
    'empaque_accesorios',
    'empaque_llaves',
];

$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
$existing = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=? AND TABLE_NAME='checklist_origen'");
$existing->execute([$dbName]);
$existingSet = array_flip($existing->fetchAll(PDO::FETCH_COLUMN));

$added   = [];
$skipped = [];
$errors  = [];

foreach ($newJsonCols as $col) {
    if (isset($existingSet[$col])) { $skipped[] = $col; continue; }
    $sql = "ALTER TABLE `checklist_origen` ADD COLUMN `$col` JSON NULL";
    if ($dryRun) { $added[] = ['column' => $col, 'sql' => $sql, 'applied' => false]; continue; }
    try {
        $pdo->exec($sql);
        $added[] = ['column' => $col, 'sql' => $sql, 'applied' => true];
    } catch (Throwable $e) {
        $errors[] = ['column' => $col, 'sql' => $sql, 'error' => $e->getMessage()];
    }
}

foreach ($newBinaryCols as $col) {
    if (isset($existingSet[$col])) { $skipped[] = $col; continue; }
    $sql = "ALTER TABLE `checklist_origen` ADD COLUMN `$col` TINYINT(1) NOT NULL DEFAULT 0";
    if ($dryRun) { $added[] = ['column' => $col, 'sql' => $sql, 'applied' => false]; continue; }
    try {
        $pdo->exec($sql);
        $added[] = ['column' => $col, 'sql' => $sql, 'applied' => true];
    } catch (Throwable $e) {
        $errors[] = ['column' => $col, 'sql' => $sql, 'error' => $e->getMessage()];
    }
}

// Backfill `foto_unidad_completa` from legacy `fotos` column for rows that
// have photos but no category data yet. Only touches rows where the target
// is empty — safe to re-run.
$backfilled = 0;
$backfillRows = [];

if (isset($existingSet['fotos']) || in_array('foto_unidad_completa', array_column($added, 'column'))) {
    // Re-read existing set in case we just added columns
    $existing2 = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA=? AND TABLE_NAME='checklist_origen'");
    $existing2->execute([$dbName]);
    $cols2 = array_flip($existing2->fetchAll(PDO::FETCH_COLUMN));

    if (isset($cols2['foto_unidad_completa']) && isset($cols2['fotos'])) {
        $rows = $pdo->query("
            SELECT id, fotos, foto_unidad_completa
            FROM checklist_origen
            WHERE fotos IS NOT NULL AND JSON_LENGTH(fotos) > 0
              AND (foto_unidad_completa IS NULL OR JSON_LENGTH(foto_unidad_completa) = 0)
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $backfillRows[] = ['id' => (int)$r['id']];
            if (!$dryRun) {
                try {
                    $pdo->prepare("UPDATE checklist_origen SET foto_unidad_completa=? WHERE id=?")
                        ->execute([$r['fotos'], $r['id']]);
                    $backfilled++;
                } catch (Throwable $e) {
                    $errors[] = ['row_id' => (int)$r['id'], 'error' => $e->getMessage()];
                }
            }
        }
    }
}

if (!$dryRun) {
    adminLog('reparar_schema_origen', [
        'added'      => count($added),
        'skipped'    => count($skipped),
        'backfilled' => $backfilled,
        'errors'     => count($errors),
    ]);
}

adminJsonOut([
    'ok'                => empty($errors),
    'dry_run'           => $dryRun,
    'added_columns'     => $added,
    'skipped_columns'   => $skipped,
    'backfill_previewed'=> count($backfillRows),
    'backfilled'        => $backfilled,
    'errors'            => $errors,
]);
