<?php
require_once __DIR__ . '/../bootstrap.php';
$cid = portalRequireAuth();
$pdo = getDB();

$subId = 0;
try {
    $stmt = $pdo->prepare("SELECT id FROM subscripciones_credito WHERE cliente_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$cid]);
    $subId = (int)$stmt->fetchColumn();
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
foreach ($ciclos as $c) {
    if (in_array($c['estado'], ['paid_manual','paid_auto'])) $pagado += (float)$c['monto'];
}

portalJsonOut([
    'ciclos' => $ciclos,
    'pagado_a_la_fecha' => $pagado,
    'total_ciclos' => count($ciclos),
]);
