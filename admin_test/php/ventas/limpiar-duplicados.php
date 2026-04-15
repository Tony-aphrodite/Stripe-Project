<?php
/**
 * Fix duplicate moto assignments — where one order has multiple bikes.
 *
 * GET  limpiar-duplicados.php          → preview duplicates
 * GET  limpiar-duplicados.php?run=1    → keep newest assignment, release older ones
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$run = !empty($_GET['run']);

// Find pedido_num values with more than 1 active moto assigned
$dupes = $pdo->query("
    SELECT pedido_num, COUNT(*) AS cnt, GROUP_CONCAT(id ORDER BY fmod DESC) AS moto_ids,
           GROUP_CONCAT(vin_display ORDER BY fmod DESC) AS vins
    FROM inventario_motos
    WHERE pedido_num IS NOT NULL AND pedido_num <> ''
      AND activo = 1
      AND vin NOT REGEXP '^VK-[A-Z0-9]+-[0-9]+-[a-f0-9]+'
    GROUP BY pedido_num
    HAVING cnt > 1
    ORDER BY pedido_num
")->fetchAll(PDO::FETCH_ASSOC);

if (!$run) {
    adminJsonOut([
        'ok'   => true,
        'mode' => 'preview',
        'hint' => 'Add ?run=1 to fix — keeps newest assignment, releases older ones',
        'duplicates' => $dupes,
    ]);
}

// Fix: for each duplicate group, keep the first (newest by fmod), release the rest
$fixed = [];
foreach ($dupes as $d) {
    $ids = array_map('intval', explode(',', $d['moto_ids']));
    $keep = array_shift($ids); // newest (first in DESC order)
    foreach ($ids as $releaseId) {
        $pdo->prepare("
            UPDATE inventario_motos SET
                cliente_nombre = NULL, cliente_email = NULL, cliente_telefono = NULL,
                pedido_num = NULL, stripe_pi = NULL, pago_estado = NULL,
                punto_voltika_id = NULL, tipo_asignacion = NULL,
                fecha_estado = NOW(), fmod = NOW()
            WHERE id = ?
        ")->execute([$releaseId]);
    }
    $fixed[] = [
        'pedido' => $d['pedido_num'],
        'kept_moto_id' => $keep,
        'released_moto_ids' => $ids,
    ];
}

adminJsonOut([
    'ok'    => true,
    'mode'  => 'fixed',
    'count' => count($fixed),
    'fixed' => $fixed,
]);
