<?php
/**
 * POST — DELETE duplicate transacciones rows by `pedido`.
 * Keeps the oldest id for each pedido, deletes the rest. That's it.
 *
 * Body: { dry_run: bool }
 * Response: { ok, dry_run, groups:[{pedido,kept,deleted_ids}], rows_deleted, error }
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$body   = adminJsonIn();
$dryRun = !empty($body['dry_run']);

$pdo = getDB();

$groups = [];
try {
    $stmt = $pdo->query("
        SELECT pedido, COUNT(*) AS cnt, MIN(id) AS keep_id,
               GROUP_CONCAT(id ORDER BY id) AS all_ids
        FROM transacciones
        WHERE pedido IS NOT NULL AND pedido <> ''
        GROUP BY pedido
        HAVING cnt > 1
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $ids = array_map('intval', explode(',', $g['all_ids']));
        $keep = (int)$g['keep_id'];
        $deleted = array_values(array_filter($ids, fn($i) => $i !== $keep));
        $groups[] = [
            'pedido'      => $g['pedido'],
            'total'       => (int)$g['cnt'],
            'kept'        => $keep,
            'deleted_ids' => $deleted,
        ];
    }
} catch (Throwable $e) {
    adminJsonOut(['ok' => false, 'error' => 'SELECT failed: ' . $e->getMessage()], 500);
}

$rowsDeleted = 0;
if (!$dryRun && $groups) {
    $allIds = [];
    foreach ($groups as $g) foreach ($g['deleted_ids'] as $id) $allIds[] = (int)$id;
    if ($allIds) {
        try {
            $ph = implode(',', array_fill(0, count($allIds), '?'));
            $del = $pdo->prepare("DELETE FROM transacciones WHERE id IN ($ph)");
            $del->execute($allIds);
            $rowsDeleted = $del->rowCount();
            adminLog('reparar_duplicados', ['deleted' => $rowsDeleted, 'groups' => count($groups)]);
        } catch (Throwable $e) {
            adminJsonOut([
                'ok'     => false,
                'groups' => $groups,
                'error'  => 'DELETE failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}

adminJsonOut([
    'ok'           => true,
    'dry_run'      => $dryRun,
    'groups'       => $groups,
    'rows_deleted' => $rowsDeleted,
]);
