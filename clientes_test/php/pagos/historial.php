<?php
require_once __DIR__ . '/../bootstrap.php';
$cid = portalRequireAuth();
$pdo = getDB();

// Optional scope: pick a specific subscription (for multi-purchase clients).
// Accepts ?subscripcion_id=N  OR  ?compra_tipo=credito&compra_id=N
$reqSubId = 0;
if (isset($_GET['subscripcion_id'])) $reqSubId = (int)$_GET['subscripcion_id'];
if (!$reqSubId && isset($_GET['compra_tipo']) && strtolower($_GET['compra_tipo']) === 'credito' && isset($_GET['compra_id'])) {
    $reqSubId = (int)$_GET['compra_id'];
}

$subId = 0;
try {
    if ($reqSubId > 0) {
        // Verify ownership (cliente_id OR email OR telefono match)
        $oStmt = $pdo->prepare("SELECT s.id FROM subscripciones_credito s
            LEFT JOIN clientes c ON c.id = ?
            WHERE s.id = ?
              AND (s.cliente_id = ? OR s.email = c.email OR s.telefono = c.telefono)
            LIMIT 1");
        $oStmt->execute([$cid, $reqSubId, $cid]);
        $subId = (int)$oStmt->fetchColumn();
    }
    if (!$subId) {
        $stmt = $pdo->prepare("SELECT id FROM subscripciones_credito WHERE cliente_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$cid]);
        $subId = (int)$stmt->fetchColumn();
    }
} catch (Throwable $e) { error_log('historial sub: ' . $e->getMessage()); }
if (!$subId) portalJsonOut(['ciclos' => [], 'total_ciclos' => 0, 'pagado_a_la_fecha' => 0]);

$ciclos = [];
try {
    $stmt = $pdo->prepare("SELECT id, semana_num, fecha_vencimiento, monto, estado, stripe_payment_intent
        FROM ciclos_pago WHERE subscripcion_id = ? ORDER BY semana_num ASC");
    $stmt->execute([$subId]);
    $ciclos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { error_log('historial ciclos: ' . $e->getMessage()); }

$pagado = 0;
$pagosRealizados = 0;
$pagosRestantes = 0;
foreach ($ciclos as $c) {
    if (in_array($c['estado'], ['paid_manual','paid_auto'])) {
        $pagado += (float)$c['monto'];
        $pagosRealizados++;
    } else {
        $pagosRestantes++;
    }
}

portalJsonOut([
    'ciclos' => $ciclos,
    'pagado_a_la_fecha' => $pagado,
    'total_ciclos' => count($ciclos),
    'pagos_realizados' => $pagosRealizados,
    'pagos_restantes' => $pagosRestantes,
]);
