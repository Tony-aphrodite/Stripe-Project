<?php
/**
 * GET — Generate and return actionable alerts
 * Alerts are computed in real-time from current data state
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$pdo = getDB();
$today = date('Y-m-d');
$alerts = [];

$safeScalar = function($sql, $params = [], $default = 0) use ($pdo) {
    try {
        if ($params) { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchColumn(); }
        return $pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) { return $default; }
};
$safeAll = function($sql, $params = []) use ($pdo) {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
};

// ── 1. Low inventory per model ──
$lowStock = $safeAll(
    "SELECT modelo, COUNT(*) as disponibles FROM inventario_motos
     WHERE estado IN ('recibida','lista_para_entrega') AND modelo IS NOT NULL AND modelo <> ''
     GROUP BY modelo HAVING disponibles <= 3 ORDER BY disponibles ASC"
);
foreach ($lowStock as $ls) {
    $alerts[] = [
        'tipo'      => 'inventario_bajo',
        'prioridad' => $ls['disponibles'] <= 1 ? 'alta' : 'media',
        'titulo'    => 'Inventario bajo: ' . $ls['modelo'],
        'mensaje'   => 'Solo ' . $ls['disponibles'] . ' unidades disponibles de ' . $ls['modelo'],
        'icono'     => 'inventario',
    ];
}

// ── 2. High demand models (more sales than stock) ──
$highDemand = $safeAll(
    "SELECT t.modelo,
            COUNT(*) as ventas_semana,
            COALESCE((SELECT COUNT(*) FROM inventario_motos WHERE modelo=t.modelo AND estado IN ('recibida','lista_para_entrega')),0) as stock
     FROM transacciones t
     WHERE DATE(t.freg) >= DATE_SUB(?, INTERVAL 7 DAY) AND t.modelo IS NOT NULL
     GROUP BY t.modelo
     HAVING ventas_semana > stock",
    [$today]
);
foreach ($highDemand as $hd) {
    $alerts[] = [
        'tipo'      => 'alta_demanda',
        'prioridad' => 'alta',
        'titulo'    => 'Alta demanda: ' . $hd['modelo'],
        'mensaje'   => $hd['ventas_semana'] . ' ventas esta semana pero solo ' . $hd['stock'] . ' en inventario',
        'icono'     => 'demanda',
    ];
}

// ── 3. Increasing delinquency ──
$overdueCount = (int)$safeScalar("SELECT COUNT(*) FROM ciclos_pago WHERE estado='overdue'");
$overdueLastWeek = (int)$safeScalar(
    "SELECT COUNT(*) FROM ciclos_pago WHERE estado='overdue' AND fecha_vencimiento >= DATE_SUB(?, INTERVAL 7 DAY)",
    [$today]
);
if ($overdueLastWeek > 5) {
    $alerts[] = [
        'tipo'      => 'aumento_mora',
        'prioridad' => $overdueLastWeek > 15 ? 'alta' : 'media',
        'titulo'    => 'Aumento de mora',
        'mensaje'   => $overdueLastWeek . ' nuevos ciclos vencidos en los últimos 7 días. Total atrasados: ' . $overdueCount,
        'icono'     => 'mora',
    ];
}

// ── 4. Failed payments spike ──
$failedRecent = (int)$safeScalar(
    "SELECT COUNT(*) FROM ciclos_pago
     WHERE estado='overdue' AND stripe_pi IS NOT NULL AND stripe_pi <> ''
     AND fecha_vencimiento >= DATE_SUB(?, INTERVAL 7 DAY)",
    [$today]
);
if ($failedRecent > 3) {
    $alerts[] = [
        'tipo'      => 'pagos_rechazados',
        'prioridad' => 'alta',
        'titulo'    => 'Pagos rechazados en aumento',
        'mensaje'   => $failedRecent . ' pagos fallidos/rechazados en los últimos 7 días',
        'icono'     => 'pago_fallo',
    ];
}

// ── 5. Stuck units (in same state > 14 days) ──
$stuckUnits = $safeAll(
    "SELECT id, vin_display, modelo, color, estado, DATEDIFF(?, COALESCE(fecha_cambio_estado, freg)) as dias_en_estado
     FROM inventario_motos
     WHERE estado NOT IN ('entregada','retenida')
     AND DATEDIFF(?, COALESCE(fecha_cambio_estado, freg)) > 14
     ORDER BY dias_en_estado DESC LIMIT 10",
    [$today, $today]
);
foreach ($stuckUnits as $su) {
    $alerts[] = [
        'tipo'      => 'unidad_detenida',
        'prioridad' => $su['dias_en_estado'] > 30 ? 'alta' : 'media',
        'titulo'    => 'Unidad detenida: ' . ($su['vin_display'] ?: $su['modelo']),
        'mensaje'   => $su['dias_en_estado'] . ' días en estado "' . $su['estado'] . '" — ' . $su['modelo'] . ' ' . $su['color'],
        'icono'     => 'detenida',
    ];
}

// ── 6. High-performing models (top sellers this week) ──
$topSellers = $safeAll(
    "SELECT modelo, COUNT(*) as ventas FROM transacciones
     WHERE DATE(freg) >= DATE_SUB(?, INTERVAL 7 DAY) AND modelo IS NOT NULL
     GROUP BY modelo ORDER BY ventas DESC LIMIT 3",
    [$today]
);
foreach ($topSellers as $ts) {
    if ($ts['ventas'] >= 3) {
        $alerts[] = [
            'tipo'      => 'modelo_exitoso',
            'prioridad' => 'info',
            'titulo'    => 'Modelo exitoso: ' . $ts['modelo'],
            'mensaje'   => $ts['ventas'] . ' ventas esta semana',
            'icono'     => 'exito',
        ];
    }
}

// Sort: alta first, then media, then info
$prioOrder = ['alta' => 0, 'media' => 1, 'info' => 2];
usort($alerts, function($a, $b) use ($prioOrder) {
    return ($prioOrder[$a['prioridad']] ?? 9) - ($prioOrder[$b['prioridad']] ?? 9);
});

adminJsonOut([
    'ok'     => true,
    'total'  => count($alerts),
    'alertas'=> $alerts,
]);
