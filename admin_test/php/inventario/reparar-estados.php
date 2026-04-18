<?php
/**
 * POST — One-shot repair for motos stuck in `por_llegar` despite a completed
 * Origen checklist. Before the fix in guardar-origen.php, completing the
 * checklist did NOT transition the moto to `recibida`, so early adopters
 * ended up with a pool of "stuck" units.
 *
 * Body: { dry_run: bool }
 * Response: { ok, scanned, fixed, motos: [ {id, vin, modelo, color, checklist_fecha} ] }
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin']);

$body   = adminJsonIn();
$dryRun = !empty($body['dry_run']);

$pdo = getDB();

$rows = $pdo->query("
    SELECT m.id, m.vin, m.vin_display, m.modelo, m.color, m.estado,
           co.freg AS checklist_fecha, co.id AS checklist_id
    FROM inventario_motos m
    JOIN checklist_origen co
         ON co.id = (
             SELECT co2.id FROM checklist_origen co2
             WHERE co2.moto_id = m.id AND co2.completado = 1
             ORDER BY co2.freg DESC LIMIT 1
         )
    WHERE m.activo = 1 AND m.estado = 'por_llegar'
    ORDER BY co.freg ASC
")->fetchAll(PDO::FETCH_ASSOC);

$motos = [];
foreach ($rows as $r) {
    $motos[] = [
        'id'              => (int)$r['id'],
        'vin'             => $r['vin_display'] ?: $r['vin'],
        'modelo'          => $r['modelo'],
        'color'           => $r['color'],
        'checklist_fecha' => $r['checklist_fecha'],
    ];
}

$fixed = 0;
if (!$dryRun && $motos) {
    $stmt = $pdo->prepare("UPDATE inventario_motos SET
            estado='recibida',
            fecha_estado=NOW(),
            fmod=NOW(),
            log_estados=JSON_ARRAY_APPEND(COALESCE(log_estados,'[]'), '$', JSON_OBJECT('estado','recibida','fecha',NOW(),'usuario',?,'origen','reparar_estados_retroactivo'))
        WHERE id=? AND estado='por_llegar'");
    foreach ($motos as $m) {
        $stmt->execute([$uid, $m['id']]);
        if ($stmt->rowCount() > 0) $fixed++;
    }
    adminLog('reparar_estados_retroactivo', ['scanned' => count($motos), 'fixed' => $fixed]);
}

adminJsonOut([
    'ok'      => true,
    'dry_run' => $dryRun,
    'scanned' => count($motos),
    'fixed'   => $fixed,
    'motos'   => $motos,
]);
