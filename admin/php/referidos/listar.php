<?php
/**
 * GET — List all referidos (influencers + punto codes) with operation stats
 *
 * Returns combined view of individual referidos AND punto referral codes,
 * each with their sales count, total revenue, and commission data.
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();

// Individual referidos (influencers)
$referidos = [];
try {
    $referidos = $pdo->query("
        SELECT r.id, r.nombre, r.email, r.telefono, r.codigo_referido AS codigo,
               'referido' AS fuente, r.ventas_count, r.comision_total, r.activo, r.freg,
               COUNT(t.id) AS operaciones,
               COALESCE(SUM(t.total), 0) AS total_ventas
        FROM referidos r
        LEFT JOIN transacciones t ON t.referido_id = r.id AND t.referido_tipo = 'referido'
        GROUP BY r.id
        ORDER BY r.ventas_count DESC, r.nombre ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // referidos table may not exist yet
}

// Punto codes (codigo_venta + codigo_electronico)
$puntos = [];
try {
    $puntos = $pdo->query("
        SELECT pv.id, pv.nombre, pv.ciudad, pv.estado, pv.tipo,
               pv.codigo_venta, pv.codigo_electronico,
               COALESCE(pv.ventas_count, 0) AS ventas_count, pv.activo,
               COUNT(t.id) AS operaciones,
               COALESCE(SUM(t.total), 0) AS total_ventas
        FROM puntos_voltika pv
        LEFT JOIN transacciones t ON t.referido_id = pv.id AND t.referido_tipo = 'punto'
        WHERE pv.activo = 1
        GROUP BY pv.id
        ORDER BY pv.ventas_count DESC, pv.nombre ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // puntos_voltika table may not exist yet
}

// Commission totals per punto
$comisionesPunto = [];
try {
    $rows = $pdo->query("
        SELECT punto_id, SUM(comision_monto) AS total_comision, COUNT(*) AS total_ops
        FROM comisiones_log
        WHERE punto_id IS NOT NULL
        GROUP BY punto_id
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $comisionesPunto[$r['punto_id']] = $r;
} catch (Throwable $e) {}

// Commission totals per referido
$comisionesRef = [];
try {
    $rows = $pdo->query("
        SELECT referido_id, SUM(comision_monto) AS total_comision, COUNT(*) AS total_ops
        FROM comisiones_log
        WHERE referido_id IS NOT NULL
        GROUP BY referido_id
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $comisionesRef[$r['referido_id']] = $r;
} catch (Throwable $e) {}

// Enrich with commission data
foreach ($referidos as &$ref) {
    $c = $comisionesRef[$ref['id']] ?? [];
    $ref['comision_calculada'] = floatval($c['total_comision'] ?? 0);
}
foreach ($puntos as &$pt) {
    $c = $comisionesPunto[$pt['id']] ?? [];
    $pt['comision_calculada'] = floatval($c['total_comision'] ?? 0);
}

adminJsonOut([
    'ok' => true,
    'referidos' => $referidos,
    'puntos' => $puntos
]);
