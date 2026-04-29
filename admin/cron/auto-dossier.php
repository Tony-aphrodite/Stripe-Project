<?php
/**
 * Cron — Auto-build Dossier de Defensa after delivery.
 * ─────────────────────────────────────────────────────────────────────────
 * Runs every hour. For each motorcycle that has just been delivered
 * (checklist_entrega_v2.fase5_completada = 1) and does NOT yet have a
 * dossier, build one automatically.
 *
 * Skips motos that already have a dossier (idempotent).
 * Limits to 30 builds per run to stay within HTTP timeouts (each build
 * runs Cincel timestamp + S3 upload).
 */
require_once __DIR__ . '/../php/bootstrap.php';

$configuradorPhp = realpath(__DIR__ . '/../../configurador_prueba/php')
                ?: realpath(__DIR__ . '/../../configurador_prueba_test/php');
if (!$configuradorPhp) adminJsonOut(['error' => 'configurador_prueba/php not found'], 500);
require_once $configuradorPhp . '/dossier-defensa.php';

$cronToken = defined('VOLTIKA_CRON_TOKEN') ? VOLTIKA_CRON_TOKEN : (getenv('VOLTIKA_CRON_TOKEN') ?: '');
if ($cronToken) {
    $provided = $_SERVER['HTTP_X_CRON_TOKEN'] ?? ($_GET['token'] ?? '');
    if ($provided !== $cronToken) adminJsonOut(['error' => 'Token inválido'], 403);
}

$pdo = getDB();
dossierEnsureSchema($pdo);

// Detect "fase5_completada" column (delivery finalized). Lazy: not all
// schemas have it — fall back to fase5_fecha IS NOT NULL.
$useFlag = false;
try {
    $useFlag = (bool)$pdo->query("SHOW COLUMNS FROM checklist_entrega_v2 LIKE 'fase5_completada'")->fetch();
} catch (Throwable $e) {}

$where = $useFlag ? 'c.fase5_completada = 1' : 'c.fase5_fecha IS NOT NULL';

$candidates = [];
try {
    $candidates = $pdo->query("
        SELECT c.moto_id, c.fase5_fecha, m.vin, m.transaccion_id
        FROM checklist_entrega_v2 c
        JOIN inventario_motos m ON m.id = c.moto_id
        WHERE $where
          AND m.vin IS NOT NULL AND m.vin <> ''
          AND NOT EXISTS (
              SELECT 1 FROM dossiers_defensa d WHERE d.moto_id = c.moto_id
          )
        ORDER BY c.fase5_fecha DESC
        LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('auto-dossier candidates: ' . $e->getMessage());
}

$built = 0; $errors = 0; $errs = [];
foreach ($candidates as $cand) {
    try {
        $r = dossierBuild((int)$cand['moto_id'], ['motivo' => 'auto_post_delivery']);
        if ($r['ok']) $built++;
        else { $errors++; $errs[] = ['moto_id' => $cand['moto_id'], 'err' => $r['error']]; }
    } catch (Throwable $e) {
        $errors++;
        $errs[] = ['moto_id' => $cand['moto_id'], 'err' => $e->getMessage()];
    }
}

adminLog('cron_auto_dossier', ['built' => $built, 'errors' => $errors, 'errs' => $errs]);
adminJsonOut(['ok' => true, 'built' => $built, 'errors' => $errors, 'errs' => $errs]);
