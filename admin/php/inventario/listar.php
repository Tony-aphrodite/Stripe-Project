<?php
/**
 * GET — List inventory with filters
 * Params: ?modelo=&color=&estado=&punto_id=&vin=&page=&limit=
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$pdo = getDB();
$where = ["m.activo = 1"]; $params = [];

if (!empty($_GET['modelo']))   { $where[] = "m.modelo=?";   $params[] = $_GET['modelo']; }
if (!empty($_GET['color']))    { $where[] = "m.color=?";    $params[] = $_GET['color']; }
if (!empty($_GET['estado']))   { $where[] = "m.estado=?";   $params[] = $_GET['estado']; }
if (!empty($_GET['punto_id'])) { $where[] = "m.punto_voltika_id=?"; $params[] = $_GET['punto_id']; }
if (!empty($_GET['vin']))      { $where[] = "(m.vin LIKE ? OR m.vin_display LIKE ?)"; $params[] = '%'.$_GET['vin'].'%'; $params[] = '%'.$_GET['vin'].'%'; }
if (!empty($_GET['checklist'])) {
    $ck = $_GET['checklist'];
    if ($ck === 'sin')       $where[] = "NOT EXISTS (SELECT 1 FROM checklists_origen co WHERE co.moto_id = m.id)";
    elseif ($ck === 'origen') $where[] = "EXISTS (SELECT 1 FROM checklists_origen co WHERE co.moto_id = m.id AND co.completado = 1)";
    elseif ($ck === 'ensamble') $where[] = "EXISTS (SELECT 1 FROM checklists_ensamble ce WHERE ce.moto_id = m.id AND ce.completado = 1)";
    elseif ($ck === 'completo') $where[] = "EXISTS (SELECT 1 FROM checklists_entrega ct WHERE ct.moto_id = m.id AND ct.completado = 1)";
}

$sql = "SELECT m.*, pv.nombre AS punto_voltika_nombre FROM inventario_motos m
    LEFT JOIN puntos_voltika pv ON pv.id = m.punto_voltika_id
    WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY m.fmod DESC";

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

// Count
$cntSql = "SELECT COUNT(*) FROM inventario_motos m WHERE " . implode(' AND ', $where);
$cntStmt = $pdo->prepare($cntSql);
$cntStmt->execute($params);
$total = (int)$cntStmt->fetchColumn();

$sql .= " LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary counts
$summary = $pdo->query("SELECT
    COUNT(*) as total,
    SUM(estado='recibida' OR estado='lista_para_entrega') as disponible,
    SUM(estado='por_validar_entrega' OR (estado<>'entregada' AND cliente_nombre IS NOT NULL AND cliente_nombre<>'')) as reservado,
    SUM(estado='entregada') as entregado,
    SUM(estado IN ('por_llegar','lista_para_enviar','enviada')) as en_transito,
    SUM(estado='en_ensamble' OR estado='por_ensamblar') as en_ensamble,
    SUM(estado='retenida') as bloqueado,
    SUM(punto_voltika_id IS NOT NULL AND estado NOT IN ('entregada','por_llegar','retenida')) as en_puntos
    FROM inventario_motos WHERE activo = 1")->fetch(PDO::FETCH_ASSOC);

// Model counts
$porModelo = $pdo->query("SELECT modelo, COUNT(*) as cnt FROM inventario_motos WHERE activo = 1 GROUP BY modelo ORDER BY modelo")->fetchAll(PDO::FETCH_ASSOC);

// Distinct modelos for filter
$modelos = array_column($porModelo, 'modelo');

adminJsonOut([
    'motos' => $rows,
    'total' => $total,
    'page' => $page,
    'pages' => ceil($total / $limit),
    'resumen' => $summary,
    'por_modelo' => $porModelo,
    'modelos' => $modelos,
]);
