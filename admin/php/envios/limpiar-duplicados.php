<?php
/**
 * One-time cleanup tool — fix legacy duplicate envíos.
 *
 * Customer brief 2026-05-06 H20-22: same VIN appearing on multiple
 * active rows (`lista_para_enviar` / `en_transito` / `enviado`) at the
 * same time. The crear.php fix prevents NEW duplicates going forward
 * but legacy data still has them. This tool finds duplicate groups
 * (same moto_id with >1 active envío) and closes all but the newest
 * row by setting estado='completado_no_exitoso'.
 *
 * GET  limpiar-duplicados.php?dry=1   → preview (default; never mutates)
 * GET  limpiar-duplicados.php?run=1   → actually close older duplicates
 *
 * Idempotent: re-running after success finds no duplicates and
 * returns count=0. Each closed row gets an audit row in admin_audit
 * via adminLog so the operation is traceable.
 *
 * Only admins can run this. Cedis users can preview but not commit
 * (the policy is conservative — bulk state changes are admin-only).
 */
require_once __DIR__ . '/../bootstrap.php';

$pdo = getDB();
$dry = !isset($_GET['run']);

// Auth: preview is open to admin/cedis (read-only); commit is admin-only.
if ($dry) {
    $uid = adminRequireAuth(['admin', 'cedis']);
} else {
    $uid = adminRequireAuth(['admin']);
}

// Find all duplicate groups: moto_id values with more than one row in
// an active state. We prefer fmod for tiebreaking (the newest row is
// the one most recently touched), falling back to freg if fmod is null.
try {
    $rows = $pdo->query("
        SELECT moto_id,
               COUNT(*) AS cnt,
               GROUP_CONCAT(id ORDER BY COALESCE(fmod, freg) DESC) AS envio_ids,
               GROUP_CONCAT(estado ORDER BY COALESCE(fmod, freg) DESC) AS estados
          FROM envios
         WHERE moto_id IS NOT NULL
           AND moto_id > 0
           AND estado IN ('lista_para_enviar','en_transito','enviado')
         GROUP BY moto_id
        HAVING cnt > 1
         ORDER BY moto_id
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    adminJsonOut(['ok' => false, 'error' => 'query_failed', 'detail' => $e->getMessage()], 500);
}

// Enrich each group with the moto's VIN so the operator can sanity-check
// before committing. Failure to resolve the VIN is non-fatal — we just
// leave it null and continue.
foreach ($rows as &$row) {
    try {
        $st = $pdo->prepare("SELECT vin_display, vin FROM inventario_motos WHERE id = ? LIMIT 1");
        $st->execute([(int)$row['moto_id']]);
        $m = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $row['vin'] = $m['vin_display'] ?? $m['vin'] ?? null;
    } catch (Throwable $e) {
        $row['vin'] = null;
    }

    $ids = array_map('intval', explode(',', $row['envio_ids']));
    $row['keep_envio_id']  = $ids[0] ?? null;          // newest (DESC order)
    $row['close_envio_ids']= array_slice($ids, 1);     // older — to be closed
}
unset($row);

if ($dry) {
    adminJsonOut([
        'ok'         => true,
        'mode'       => 'preview',
        'hint'       => 'Para aplicar los cambios, agrega ?run=1',
        'duplicates' => $rows,
        'count'      => count($rows),
    ]);
}

// Commit mode — close older rows for each duplicate group inside a
// single transaction so a partial failure leaves no half-applied state.
$closed = 0;
$details = [];
try {
    $pdo->beginTransaction();
    foreach ($rows as $row) {
        $closeIds = $row['close_envio_ids'];
        if (!$closeIds) continue;

        $placeholders = implode(',', array_fill(0, count($closeIds), '?'));
        $upd = $pdo->prepare(
            "UPDATE envios
                SET estado = 'completado_no_exitoso',
                    fmod   = NOW()
              WHERE id IN ($placeholders)
                AND estado IN ('lista_para_enviar','en_transito','enviado')"
        );
        $upd->execute($closeIds);
        $closed += $upd->rowCount();

        $details[] = [
            'moto_id'        => (int)$row['moto_id'],
            'vin'            => $row['vin'],
            'kept_envio_id'  => $row['keep_envio_id'],
            'closed_envio_ids' => $closeIds,
        ];

        adminLog('envio_cleanup_legacy_duplicates', [
            'moto_id'          => (int)$row['moto_id'],
            'vin'              => $row['vin'],
            'kept_envio_id'    => $row['keep_envio_id'],
            'closed_envio_ids' => $closeIds,
            'admin_id'         => $uid,
        ]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    adminJsonOut(['ok' => false, 'error' => 'commit_failed', 'detail' => $e->getMessage()], 500);
}

adminJsonOut([
    'ok'      => true,
    'mode'    => 'committed',
    'count'   => $closed,
    'groups'  => count($rows),
    'details' => $details,
]);
