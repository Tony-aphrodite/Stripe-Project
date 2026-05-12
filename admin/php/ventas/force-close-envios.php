<?php
/**
 * POST — Force-close specific envíos by ID, bypassing WHERE filters.
 *
 * Customer brief 2026-05-09 (Óscar, 4th-round diagnostic): the earlier
 * limpiar-test-data.php cleanup reported "closed N envíos" but the DB
 * verification showed estado still empty. This endpoint provides a
 * direct, no-WHERE-conditions UPDATE so the admin can definitively
 * flip target envíos to completado_no_exitoso.
 *
 * Body:
 *   { ids: [9, 12, 13], motivo: "reason text" }
 *
 * Returns the row counts BEFORE and AFTER per ID so we can see exactly
 * what changed.
 *
 * Admin-only. Audit-logged.
 *
 * Usage from Console:
 *   ADApp.api('ventas/force-close-envios.php',
 *             { ids: [9, 12, 13], motivo: 'manual fix - cleanup didnt persist' })
 *     .done(r => console.table(r.results));
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin']);

$body = adminJsonIn();
$ids    = isset($body['ids']) && is_array($body['ids']) ? array_map('intval', $body['ids']) : [];
$motivo = trim((string)($body['motivo'] ?? ''));

if (!$ids) {
    adminJsonOut(['error' => 'ids[] requerido'], 400);
}
if ($motivo === '') {
    adminJsonOut(['error' => 'motivo requerido para audit'], 400);
}

$pdo = getDB();
$results = [];

foreach ($ids as $envioId) {
    if ($envioId <= 0) continue;
    $entry = ['envio_id' => $envioId];

    // 1. Read current state
    try {
        $sel = $pdo->prepare("SELECT id, moto_id, estado, freg, fmod, notas FROM envios WHERE id = ? LIMIT 1");
        $sel->execute([$envioId]);
        $before = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$before) {
            $entry['error'] = 'No existe envío con ese id';
            $results[] = $entry;
            continue;
        }
        $entry['estado_before']     = $before['estado'];
        $entry['estado_before_raw'] = var_export($before['estado'], true);
        $entry['estado_before_len'] = is_string($before['estado']) ? strlen($before['estado']) : 0;
    } catch (Throwable $e) {
        $entry['error'] = 'read failed: ' . $e->getMessage();
        $results[] = $entry;
        continue;
    }

    // 2. Force update — no WHERE filter on estado
    try {
        $upd = $pdo->prepare("UPDATE envios
                                 SET estado = 'completado_no_exitoso',
                                     notas  = CONCAT(COALESCE(notas,''), '\n[force-close] ', ?),
                                     fmod   = NOW()
                               WHERE id = ?");
        $upd->execute([$motivo, $envioId]);
        $entry['rows_affected'] = $upd->rowCount();
    } catch (Throwable $e) {
        $entry['error'] = 'update failed: ' . $e->getMessage();
        $results[] = $entry;
        continue;
    }

    // 3. Verify final state
    try {
        $sel->execute([$envioId]);
        $after = $sel->fetch(PDO::FETCH_ASSOC);
        $entry['estado_after']  = $after['estado'] ?? null;
        $entry['ok']            = ($after && $after['estado'] === 'completado_no_exitoso') ? '✓ CLOSED' : '✗ STILL BAD';
    } catch (Throwable $e) {
        $entry['error'] = 'verify failed: ' . $e->getMessage();
    }

    $results[] = $entry;
}

adminLog('force_close_envios', [
    'admin_id' => $uid,
    'motivo'   => $motivo,
    'ids'      => $ids,
    'results'  => $results,
]);

adminJsonOut([
    'ok'      => true,
    'count'   => count($results),
    'results' => $results,
    'motivo'  => $motivo,
]);
