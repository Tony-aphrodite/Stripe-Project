<?php
/**
 * GET — List orders/payments with status
 * Filters: ?tipo=contado|msi|credito&estado=&page=
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$pdo = getDB();
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// Combine transacciones + subscripciones for unified view
$sql = "SELECT 'orden' as fuente, t.id, t.pedido as pedido_num, t.nombre, t.email, t.telefono,
    t.modelo, t.color, t.tpago as tipo_pago, t.total as monto, t.stripe_pi,
    COALESCE(m.pago_estado,'pendiente') as pago_estado, t.freg
    FROM transacciones t
    LEFT JOIN inventario_motos m ON m.stripe_pi=t.stripe_pi";

$where = []; $params = [];
if (!empty($_GET['tipo'])) {
    if ($_GET['tipo'] === 'credito') {
        // Switch to subscriptions query
        $sql = "SELECT 'credito' as fuente, s.id, '' as pedido_num, s.nombre, s.email, s.telefono,
            s.modelo, s.color, 'credito' as tipo_pago, s.precio_contado as monto, s.stripe_setup_intent_id as stripe_pi,
            s.estado as pago_estado, s.freg
            FROM subscripciones_credito s";
    } else {
        $where[] = "t.tpago=?"; $params[] = $_GET['tipo'];
    }
}

if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY freg DESC LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary
$summary = $pdo->query("SELECT
    COUNT(*) as total_ordenes,
    SUM(total) as total_ingresos
    FROM transacciones")->fetch(PDO::FETCH_ASSOC);

$creditSummary = $pdo->query("SELECT
    COUNT(*) as total_creditos,
    SUM(precio_contado) as total_credito_monto
    FROM subscripciones_credito WHERE estado='activa'")->fetch(PDO::FETCH_ASSOC);

adminJsonOut([
    'pagos' => $rows,
    'resumen_ordenes' => $summary,
    'resumen_credito' => $creditSummary,
    'page' => $page
]);
