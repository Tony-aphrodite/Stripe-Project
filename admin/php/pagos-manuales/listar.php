<?php
/**
 * Voltika Admin — Round 67 (2026-05-22).
 *
 * List manual payments. Two modes:
 *   ?transaccion_id=<id>  → manual payments for that single transaction
 *   (no param)            → last 200 manual payments across the org
 *
 * URL: GET /admin/php/pagos-manuales/listar.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
$adminId = adminRequireAuth(['admin']);

$pdo = getDB();

// Don't query a table that doesn't exist yet (first-time admin opening).
try {
    $tbl = $pdo->query("SHOW TABLES LIKE 'pagos_manuales'")->fetchColumn();
    if (!$tbl) {
        adminJsonOut(['ok' => true, 'pagos' => [], 'total' => 0]);
    }
} catch (Throwable $e) {
    adminJsonOut(['ok' => true, 'pagos' => [], 'total' => 0]);
}

$transId = (int)($_GET['transaccion_id'] ?? 0);

if ($transId > 0) {
    $sql = "SELECT pm.*, t.pedido, t.pedido_corto
              FROM pagos_manuales pm
              LEFT JOIN transacciones t ON t.id = pm.transaccion_id
             WHERE pm.transaccion_id = ?
             ORDER BY pm.id DESC";
    $st = $pdo->prepare($sql);
    $st->execute([$transId]);
} else {
    $sql = "SELECT pm.*, t.pedido, t.pedido_corto
              FROM pagos_manuales pm
              LEFT JOIN transacciones t ON t.id = pm.transaccion_id
             ORDER BY pm.id DESC
             LIMIT 200";
    $st = $pdo->prepare($sql);
    $st->execute();
}

$rows = $st->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$r) {
    $r['id']              = (int)$r['id'];
    $r['transaccion_id']  = (int)$r['transaccion_id'];
    $r['monto']           = (float)$r['monto'];
    $r['comprobante_url'] = !empty($r['comprobante_archivo'])
        ? '/admin/php/pagos-manuales/serve-comprobante.php?id=' . (int)$r['id']
        : null;
}
unset($r);

adminJsonOut([
    'ok'    => true,
    'pagos' => $rows,
    'total' => count($rows),
]);
