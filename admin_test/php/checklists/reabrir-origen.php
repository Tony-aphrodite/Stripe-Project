<?php
/**
 * POST — Reopen a "force-completed" checklist_origen so CEDIS staff can
 * properly inspect the bike (tick 55 items + upload photos).
 *
 * Use case: bulk-complete-origen.php was used to flip completado=1 (so the
 * configurator would show the bike as available stock), but the 55 individual
 * items + 10 photo categories were never filled. Reopening sets completado=0
 * so the standard openOrigenForm UI becomes editable again.
 *
 * Body: { moto_id: N, motivo: "..." (optional) }
 *
 * Safety:
 *   - Only allows reopen when the checklist looks force-completed (a sample
 *     of items is 0). A genuinely-inspected 55/55 checklist is NOT reopenable
 *     to preserve the immutability guarantee for those records.
 *   - Audit log entry written.
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin']);

$d = adminJsonIn();
$motoId = (int)($d['moto_id'] ?? 0);
$motivo = trim((string)($d['motivo'] ?? 'Reapertura para inspección manual en CEDIS'));
if ($motoId <= 0) adminJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();

// Find current checklist
$stmt = $pdo->prepare("SELECT id, completado, frame_completo, validacion_final, hash_registro
    FROM checklist_origen WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$motoId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) adminJsonOut(['error' => 'No hay checklist para esta moto'], 404);
if ((int)$row['completado'] !== 1) adminJsonOut(['error' => 'El checklist no está completado, no necesita reapertura'], 400);

// Sanity check — if both these representative items are 1, the checklist
// looks genuinely completed (not force-completed). Block reopen to preserve
// the immutability of real inspection records.
$looksReal = ((int)$row['frame_completo'] === 1) && ((int)$row['validacion_final'] === 1);
$forceReopen = !empty($d['force']);
if ($looksReal && !$forceReopen) {
    adminJsonOut([
        'error' => 'Este checklist parece haber sido inspeccionado realmente (items principales marcados). Si aún quieres reabrirlo, vuelve a llamar con force=1.',
        'looks_real' => true,
    ], 403);
}

try {
    $pdo->prepare("UPDATE checklist_origen SET completado = 0, hash_registro = NULL WHERE id = ?")
        ->execute([$row['id']]);

    // Revert moto state if the original completion had advanced it
    $pdo->prepare("UPDATE inventario_motos
            SET log_estados = JSON_ARRAY_APPEND(COALESCE(log_estados,'[]'), '$',
                JSON_OBJECT('estado', estado, 'fecha', NOW(), 'usuario', ?, 'origen', 'checklist_origen_reabierto', 'motivo', ?))
            WHERE id = ?")
        ->execute([$uid, $motivo, $motoId]);

    adminLog('checklist_origen_reabierto', [
        'moto_id'      => $motoId,
        'checklist_id' => (int)$row['id'],
        'motivo'       => $motivo,
        'previo_hash'  => $row['hash_registro'],
        'force'        => $forceReopen ? 1 : 0,
    ]);

    adminJsonOut(['ok' => true, 'message' => 'Checklist reabierto. Ahora puedes editarlo normalmente.']);
} catch (Throwable $e) {
    adminJsonOut(['error' => $e->getMessage()], 500);
}
