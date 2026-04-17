<?php
/**
 * POST — One-shot schema migration for Servicios adicionales (Phase C).
 *
 * Adds tracking columns to `transacciones` for:
 *  - Asesoría de placas:  placas_estado, placas_gestor_nombre, placas_gestor_telefono, placas_nota
 *  - Seguro Quálitas:     seguro_estado, seguro_cotizacion, seguro_poliza, seguro_nota
 *  - Common:              servicios_fmod, servicios_admin_uid (audit)
 *
 * Idempotent: reads INFORMATION_SCHEMA and skips columns that already exist.
 *
 * Body: { dry_run: bool }
 * Response: { ok, dry_run, added_columns, skipped_columns, errors }
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin']);

$body   = adminJsonIn();
$dryRun = !empty($body['dry_run']);

$pdo = getDB();

$newCols = [
    'placas_estado'          => "VARCHAR(20) NOT NULL DEFAULT 'pendiente'",
    'placas_gestor_nombre'   => "VARCHAR(200) NULL",
    'placas_gestor_telefono' => "VARCHAR(30)  NULL",
    'placas_nota'            => "TEXT         NULL",
    'seguro_estado'          => "VARCHAR(20) NOT NULL DEFAULT 'pendiente'",
    'seguro_cotizacion'      => "DECIMAL(10,2) NULL",
    'seguro_poliza'          => "VARCHAR(100) NULL",
    'seguro_nota'            => "TEXT         NULL",
    'servicios_fmod'         => "DATETIME NULL",
    'servicios_admin_uid'    => "INT NULL",
];

$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
$existing = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=? AND TABLE_NAME='transacciones'");
$existing->execute([$dbName]);
$existingSet = array_flip($existing->fetchAll(PDO::FETCH_COLUMN));

$added   = [];
$skipped = [];
$errors  = [];

foreach ($newCols as $col => $def) {
    if (isset($existingSet[$col])) { $skipped[] = $col; continue; }
    $sql = "ALTER TABLE `transacciones` ADD COLUMN `$col` $def";
    if ($dryRun) { $added[] = ['column' => $col, 'sql' => $sql, 'applied' => false]; continue; }
    try {
        $pdo->exec($sql);
        $added[] = ['column' => $col, 'sql' => $sql, 'applied' => true];
    } catch (Throwable $e) {
        $errors[] = ['column' => $col, 'sql' => $sql, 'error' => $e->getMessage()];
    }
}

if (!$dryRun) {
    adminLog('reparar_schema_servicios', [
        'added'   => count($added),
        'skipped' => count($skipped),
        'errors'  => count($errors),
    ]);
}

adminJsonOut([
    'ok'              => empty($errors),
    'dry_run'         => $dryRun,
    'added_columns'   => $added,
    'skipped_columns' => $skipped,
    'errors'          => $errors,
]);
