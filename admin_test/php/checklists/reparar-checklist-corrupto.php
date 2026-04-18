<?php
/**
 * POST — Detect and fix corrupt checklist_origen rows.
 *
 * A row is "corrupt" when completado=1 but the sum of all binary fields is 0
 * (i.e. locked as complete yet visibly empty in the UI). This happens when a
 * new binary column is added after a row was locked and the new field default
 * is 0, tripping the UI counter. Or when data was directly manipulated.
 *
 * Modes:
 *   - action=unlock (default): set completado=0 so user can re-fill
 *   - action=delete:            remove the row entirely
 *
 * Body: { dry_run: bool, action: 'unlock'|'delete' }
 * Response: { ok, dry_run, action, corruptos:[{id,moto_id,vin,modelo,freg}], fixed, errors }
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin']);

$body   = adminJsonIn();
$dryRun = !empty($body['dry_run']);
$action = ($body['action'] ?? 'unlock') === 'delete' ? 'delete' : 'unlock';

$pdo = getDB();

// Binary fields to sum. Auto-detect so we pick up any schema evolution.
$binaryCols = [];
try {
    $cols = $pdo->query("SHOW COLUMNS FROM checklist_origen")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        if (stripos($c['Type'], 'tinyint') === 0 && !in_array($c['Field'], ['completado','activo'], true)) {
            $binaryCols[] = $c['Field'];
        }
    }
} catch (Throwable $e) {
    adminJsonOut(['ok' => false, 'error' => 'SHOW COLUMNS failed: ' . $e->getMessage()], 500);
}

if (!$binaryCols) {
    adminJsonOut(['ok' => false, 'error' => 'No binary columns detected in checklist_origen'], 500);
}

// Build sum expression
$sumParts = [];
foreach ($binaryCols as $col) $sumParts[] = "COALESCE(`$col`,0)";
$sumExpr = '(' . implode(' + ', $sumParts) . ')';

$corruptos = [];
try {
    $sql = "SELECT co.id, co.moto_id, co.freg, co.hash_registro,
                   m.vin, m.vin_display, m.modelo, m.color, m.estado,
                   $sumExpr AS suma_campos
            FROM checklist_origen co
            LEFT JOIN inventario_motos m ON m.id = co.moto_id
            WHERE co.completado = 1
              AND $sumExpr = 0
            ORDER BY co.freg DESC";
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $corruptos[] = [
            'id'         => (int)$r['id'],
            'moto_id'    => (int)$r['moto_id'],
            'vin'        => $r['vin_display'] ?: $r['vin'] ?: '—',
            'modelo'     => $r['modelo'],
            'color'      => $r['color'],
            'estado'     => $r['estado'],
            'freg'       => $r['freg'],
            'hash_short' => substr($r['hash_registro'] ?? '', 0, 10),
        ];
    }
} catch (Throwable $e) {
    adminJsonOut(['ok' => false, 'error' => 'SELECT failed: ' . $e->getMessage()], 500);
}

$fixed  = 0;
$errors = [];

if (!$dryRun && $corruptos) {
    $ids = array_column($corruptos, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    try {
        if ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM checklist_origen WHERE id IN ($ph)");
            $stmt->execute($ids);
            $fixed = $stmt->rowCount();
        } else { // unlock
            $stmt = $pdo->prepare("UPDATE checklist_origen
                SET completado=0, hash_registro=NULL WHERE id IN ($ph)");
            $stmt->execute($ids);
            $fixed = $stmt->rowCount();
            // Also roll back moto state if it was moved to recibida by this
            // corrupt checklist (only if the moto has no other completed origen)
            foreach ($corruptos as $c) {
                $moto_id = $c['moto_id'];
                if (!$moto_id) continue;
                try {
                    $hasOther = $pdo->prepare("SELECT COUNT(*) FROM checklist_origen
                        WHERE moto_id=? AND completado=1 AND id<>?");
                    $hasOther->execute([$moto_id, $c['id']]);
                    if ((int)$hasOther->fetchColumn() === 0) {
                        $pdo->prepare("UPDATE inventario_motos
                            SET estado='por_llegar' WHERE id=? AND estado='recibida'")
                            ->execute([$moto_id]);
                    }
                } catch (Throwable $e) {
                    $errors[] = ['moto_id' => $moto_id, 'error' => $e->getMessage()];
                }
            }
        }
        adminLog('reparar_checklist_corrupto', [
            'action' => $action, 'fixed' => $fixed, 'ids' => $ids,
        ]);
    } catch (Throwable $e) {
        $errors[] = ['step' => $action, 'error' => $e->getMessage()];
    }
}

adminJsonOut([
    'ok'         => empty($errors),
    'dry_run'    => $dryRun,
    'action'     => $action,
    'corruptos'  => $corruptos,
    'fixed'      => $fixed,
    'errors'     => $errors,
]);
