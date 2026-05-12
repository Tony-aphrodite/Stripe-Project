<?php
/**
 * GET — Historial de motos recibidas en este punto.
 *
 * Customer brief 2026-05-12 (Óscar, 6th round — screenshot 2: "In this
 * section we need to have the information of all the received motos. View
 * the checklist, check who received the moto, etc."). The Recepción screen
 * previously only listed motos pending arrival. Once a moto was received,
 * its record vanished from punto's view — the operator had no way to
 * consult past checklists, photos, or seal numbers without bothering admin.
 *
 * This endpoint returns every recepcion_punto row belonging to the caller's
 * punto, JOINed with moto + envio + receiving user, plus all optional
 * checklist columns when present. The frontend renders these as expandable
 * cards in a "Historial" tab next to the existing "Pendientes" list.
 *
 * Query params:
 *   q       — optional free-text filter (matches VIN / modelo / pedido / cliente).
 *   limit   — optional cap (default 100, max 500).
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$pdo = getDB();

$q     = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 100);
if ($limit < 1)   $limit = 100;
if ($limit > 500) $limit = 500;

// Detect which optional columns exist (older installs may not have the
// 2026-05-08 checklist upgrade applied yet — degrade gracefully).
$optionalCols = [
    'vin_caja','vin_caja_coincide','vin_mismatch_confirmed',
    'sello_numero','sello_intacto',
    'foto_sello_url','foto_vin_label_url','foto_unidad_url',
    'observaciones',
];
$present = [];
try {
    $present = $pdo->query("SHOW COLUMNS FROM recepcion_punto")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    puntoJsonOut(['error' => 'recepcion_punto no disponible'], 500);
}

$select = ['rp.id', 'rp.envio_id', 'rp.moto_id', 'rp.recibido_por', 'rp.vin_escaneado',
           'rp.vin_coincide', 'rp.estado_fisico_ok', 'rp.sin_danos',
           'rp.componentes_completos', 'rp.bateria_ok', 'rp.fotos', 'rp.notas',
           'rp.completado', 'rp.freg AS fecha_recepcion'];
foreach ($optionalCols as $c) {
    if (in_array($c, $present, true)) $select[] = "rp.$c";
}

$selectSql = implode(",\n        ", $select);

$where = "WHERE rp.punto_id = ?";
$params = [$ctx['punto_id']];

if ($q !== '') {
    $where .= " AND (m.vin LIKE ? OR m.vin_display LIKE ? OR m.modelo LIKE ?
                     OR m.pedido_num LIKE ? OR m.cliente_nombre LIKE ?)";
    $needle = '%' . $q . '%';
    array_push($params, $needle, $needle, $needle, $needle, $needle);
}

$sql = "SELECT $selectSql,
               m.vin, m.vin_display, m.modelo, m.color,
               m.cliente_nombre, m.cliente_telefono, m.cliente_email, m.pedido_num,
               m.estado AS estado_inventario,
               e.tracking_number, e.carrier, e.fecha_envio,
               COALESCE(u.nombre, 'Usuario #' || rp.recibido_por) AS recibido_por_nombre,
               u.email AS recibido_por_email
          FROM recepcion_punto rp
          JOIN inventario_motos m ON m.id = rp.moto_id
          LEFT JOIN envios e ON e.id = rp.envio_id
          LEFT JOIN dealer_usuarios u ON u.id = rp.recibido_por
          $where
         ORDER BY rp.freg DESC
         LIMIT $limit";

// MySQL's CONCAT is different from || — fix the COALESCE expression.
$sql = str_replace("'Usuario #' || rp.recibido_por",
                   "CONCAT('Usuario #', rp.recibido_por)", $sql);

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('historial recepcion: ' . $e->getMessage());
    puntoJsonOut(['error' => 'Error al consultar historial', 'detail' => $e->getMessage()], 500);
}

// Decode the legacy `fotos` JSON column (extra free-form photos) so the
// frontend doesn't have to do it inline.
foreach ($rows as &$r) {
    if (isset($r['fotos']) && is_string($r['fotos']) && $r['fotos'] !== '') {
        $decoded = json_decode($r['fotos'], true);
        $r['fotos_extra'] = is_array($decoded) ? $decoded : [];
    } else {
        $r['fotos_extra'] = [];
    }
    unset($r['fotos']);
}
unset($r);

puntoJsonOut([
    'ok'           => true,
    'recepciones'  => $rows,
    'total'        => count($rows),
    'filter'       => $q,
]);
