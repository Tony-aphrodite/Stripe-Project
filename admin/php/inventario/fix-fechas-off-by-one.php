<?php
/**
 * Voltika — One-shot fix for the "all dates 1 day delay" bug.
 *
 * Customer report 2026-05-04: every date column in the inventory list
 * (fecha_ingreso_pais, fecha_entrada_almacen, fecha_salida_almacen) was
 * showing one day earlier than the value in the source xlsx.
 *
 * Root cause: parseDateLoose() in reemplazar-completo.php was double-
 * subtracting the Excel "fake leap day" (1900-02-29). It decremented
 * the serial by 1 AND used 25569 as the 1970 reference — but 25569
 * already accounts for the fake day, so every Excel-serial date came
 * out 1 day too early. The function has been fixed; this script
 * back-fills the 122 rows that were already imported with the buggy
 * version.
 *
 * Idempotency: each row's log_estados gets a "fix_fechas_2026_05_04"
 * marker after the +1 day update. Rows that already carry the marker
 * are skipped, so the endpoint is safe to call multiple times.
 *
 * Usage:
 *   GET  /admin/php/inventario/fix-fechas-off-by-one.php?action=preview
 *        → shows how many rows would be fixed and a sample diff
 *   POST /admin/php/inventario/fix-fechas-off-by-one.php
 *        body: action=execute
 *        → actually applies +1 day to all eligible rows
 */

require_once __DIR__ . '/../bootstrap.php';
$adminId = adminRequireAuth(['admin']);

$pdo = getDB();

// Eligible rows: imported by reemplazar_completo (so we know they came
// through the buggy parser) AND not yet patched by this fix.
$selectEligibleSql = "SELECT id, vin, fecha_ingreso_pais, fecha_entrada_almacen, fecha_salida_almacen
                      FROM inventario_motos
                      WHERE log_estados LIKE '%reemplazo_completo%'
                        AND log_estados NOT LIKE '%fix_fechas_2026_05_04%'";

$action = strtolower(trim($_REQUEST['action'] ?? 'preview'));

if ($action === 'preview') {
    $rows = $pdo->query($selectEligibleSql)->fetchAll(PDO::FETCH_ASSOC);
    $samples = [];
    foreach (array_slice($rows, 0, 5) as $r) {
        $samples[] = [
            'vin'                       => $r['vin'],
            'fecha_ingreso_pais_actual' => $r['fecha_ingreso_pais'],
            'fecha_ingreso_pais_nueva'  => $r['fecha_ingreso_pais']
                ? date('Y-m-d', strtotime($r['fecha_ingreso_pais'] . ' +1 day'))
                : null,
            'fecha_entrada_almacen_actual' => $r['fecha_entrada_almacen'],
            'fecha_entrada_almacen_nueva'  => $r['fecha_entrada_almacen']
                ? date('Y-m-d', strtotime($r['fecha_entrada_almacen'] . ' +1 day'))
                : null,
        ];
    }
    adminJsonOut([
        'ok'              => true,
        'mode'            => 'preview',
        'filas_elegibles' => count($rows),
        'sample_diff'     => $samples,
        'next_step'       => 'POST con action=execute para aplicar +1 día',
    ]);
}

if ($action !== 'execute') {
    adminJsonOut(['ok' => false, 'error' => "action inválido (preview o execute)"], 400);
}

// ── Execute: +1 day on all three date columns, then mark the row ─────
$rows = $pdo->query($selectEligibleSql)->fetchAll(PDO::FETCH_ASSOC);
$updated = 0;
$pdo->beginTransaction();
try {
    $upd = $pdo->prepare("UPDATE inventario_motos
        SET fecha_ingreso_pais    = CASE WHEN fecha_ingreso_pais    IS NULL THEN NULL ELSE DATE_ADD(fecha_ingreso_pais,    INTERVAL 1 DAY) END,
            fecha_entrada_almacen = CASE WHEN fecha_entrada_almacen IS NULL THEN NULL ELSE DATE_ADD(fecha_entrada_almacen, INTERVAL 1 DAY) END,
            fecha_salida_almacen  = CASE WHEN fecha_salida_almacen  IS NULL THEN NULL ELSE DATE_ADD(fecha_salida_almacen,  INTERVAL 1 DAY) END,
            log_estados = JSON_ARRAY_APPEND(
                COALESCE(log_estados, JSON_ARRAY()),
                '$',
                JSON_OBJECT(
                    'accion',    'fix_fechas_2026_05_04',
                    'dealer',    'admin#" . (int)$adminId . "',
                    'timestamp', NOW()
                )
            )
        WHERE id = ?");
    foreach ($rows as $r) {
        try {
            $upd->execute([$r['id']]);
            $updated++;
        } catch (Throwable $e) {
            // JSON_ARRAY_APPEND fails when log_estados isn't valid JSON.
            // Fallback: append marker as plain text inside the existing
            // text payload so the idempotency check still picks it up
            // on the next run.
            $pdo->prepare("UPDATE inventario_motos
                SET fecha_ingreso_pais    = CASE WHEN fecha_ingreso_pais    IS NULL THEN NULL ELSE DATE_ADD(fecha_ingreso_pais,    INTERVAL 1 DAY) END,
                    fecha_entrada_almacen = CASE WHEN fecha_entrada_almacen IS NULL THEN NULL ELSE DATE_ADD(fecha_entrada_almacen, INTERVAL 1 DAY) END,
                    fecha_salida_almacen  = CASE WHEN fecha_salida_almacen  IS NULL THEN NULL ELSE DATE_ADD(fecha_salida_almacen,  INTERVAL 1 DAY) END,
                    log_estados = CONCAT(COALESCE(log_estados,''), ' [fix_fechas_2026_05_04]')
                WHERE id = ?")->execute([$r['id']]);
            $updated++;
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('fix-fechas fatal: ' . $e->getMessage());
    adminJsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
}

adminLog('inventario_fix_fechas_off_by_one', [
    'filas_actualizadas' => $updated,
]);

adminJsonOut([
    'ok'                 => true,
    'mode'               => 'execute',
    'filas_actualizadas' => $updated,
    'mensaje'            => "Aplicado +1 día a {$updated} filas. Refresca la pantalla CEDIS para ver las fechas corregidas.",
]);
