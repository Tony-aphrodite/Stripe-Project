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

// Ensure per-model commission table exists. Each influencer is paid a
// fixed MXN amount per model sale (set in the Editar form). The auto-calc
// in confirmar-orden.php uses this table to populate comisiones_log.
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS referido_comisiones (
            referido_id    INT NOT NULL,
            modelo_slug    VARCHAR(50) NOT NULL,
            comision_monto DECIMAL(10,2) NOT NULL DEFAULT 0,
            PRIMARY KEY (referido_id, modelo_slug)
        )
    ");
} catch (Throwable $e) {}

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

// Attach per-model commissions so the Editar form can pre-fill them. Shape:
// $ref['comisiones'] = { 'm05': 500, 'pesgo-plus': 800, ... }
try {
    $commRows = $pdo->query("SELECT referido_id, modelo_slug, comision_monto FROM referido_comisiones")
        ->fetchAll(PDO::FETCH_ASSOC);
    $byRef = [];
    foreach ($commRows as $c) {
        $byRef[(int)$c['referido_id']][$c['modelo_slug']] = (float)$c['comision_monto'];
    }
    foreach ($referidos as &$ref) {
        $ref['comisiones'] = $byRef[(int)$ref['id']] ?? (object)[];
    }
    unset($ref);
} catch (Throwable $e) {}

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

// Per-model configured commissions, keyed by referido_id then modelo_slug.
// The admin edit form uses this map to pre-fill inputs so a re-save doesn't
// wipe values that weren't shown.
$comisionesPorModelo = [];
try {
    $rows = $pdo->query("SELECT referido_id, modelo_slug, comision_monto
                         FROM referido_comisiones")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $rid = (int)$r['referido_id'];
        if (!isset($comisionesPorModelo[$rid])) $comisionesPorModelo[$rid] = [];
        $comisionesPorModelo[$rid][$r['modelo_slug']] = (float)$r['comision_monto'];
    }
} catch (Throwable $e) {}

// Enrich with commission data
foreach ($referidos as &$ref) {
    $c = $comisionesRef[$ref['id']] ?? [];
    $ref['comision_calculada'] = floatval($c['total_comision'] ?? 0);
    $ref['comisiones'] = $comisionesPorModelo[(int)$ref['id']] ?? new stdClass();
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
