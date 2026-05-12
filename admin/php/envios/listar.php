<?php
/**
 * GET — List all shipments with filters.
 *
 * Customer brief 2026-05-12 (Óscar, 9th round — screenshot: two ENVIOS
 * with the same VIN R4WPDTA18T8000048, one to S2R NEZA and another to
 * Voltika Center). One physical moto cannot be at two puntos at once.
 * crear.php already supersedes prior shipments to estado='completado_no_exitoso'
 * when a new envío is created for the same moto_id, but the listing
 * never filtered those out — so the panel showed BOTH the historic
 * (superseded) row and the active one as if they were duplicates.
 *
 * Defaults:
 *   - HIDE rows in estado='completado_no_exitoso' unless ?include_superseded=1
 *     is explicitly passed. This keeps the panel free of duplicates by
 *     default while preserving the audit trail for the diagnostic view.
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$pdo = getDB();
$where = []; $params = [];

// Default: skip superseded shipments. Pass include_superseded=1 to bypass.
$includeSuperseded = !empty($_GET['include_superseded']);
if (!$includeSuperseded) {
    $where[] = "(e.estado IS NULL OR e.estado <> 'completado_no_exitoso')";
}

if (!empty($_GET['estado']))   { $where[] = "e.estado=?";            $params[] = $_GET['estado']; }
if (!empty($_GET['punto_id'])) { $where[] = "e.punto_destino_id=?";  $params[] = $_GET['punto_id']; }

$sql = "SELECT e.*, m.vin, m.vin_display, m.modelo, m.color, m.cliente_nombre,
    m.pedido_num,
    pv.nombre AS punto_nombre, pv.ciudad AS punto_ciudad
    FROM envios e
    JOIN inventario_motos m ON m.id=e.moto_id
    LEFT JOIN puntos_voltika pv ON pv.id=e.punto_destino_id";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY e.freg DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$envios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Hardening — defense in depth: if two ACTIVE envíos exist for the same
// moto_id (which crear.php's supersede should prevent but legacy data may
// retain), keep only the most recent one in the response. The older one
// is silently dropped from the list to maintain "one active shipment per
// VIN" invariant; it remains in the DB for audit.
$seenMoto = [];
$dedup = [];
foreach ($envios as $row) {
    $mid = (int)$row['moto_id'];
    if ($mid && isset($seenMoto[$mid])) continue;   // older duplicate — skip
    $seenMoto[$mid] = true;
    $dedup[] = $row;
}

adminJsonOut([
    'envios'              => $dedup,
    'total_raw'           => count($envios),
    'total_deduplicated'  => count($dedup),
    'include_superseded'  => (bool)$includeSuperseded,
]);
