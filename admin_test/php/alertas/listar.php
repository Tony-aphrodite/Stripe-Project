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
     WHERE estado='overdue' AND stripe_payment_intent IS NOT NULL AND stripe_payment_intent <> ''
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
    "SELECT id, vin_display, modelo, color, estado, DATEDIFF(?, COALESCE(fecha_estado, freg)) as dias_en_estado
     FROM inventario_motos
     WHERE estado NOT IN ('entregada','retenida')
     AND DATEDIFF(?, COALESCE(fecha_estado, freg)) > 14
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

// ── 7. Pickup retrasado — moto lista_para_entrega con cliente hace > 7 días ──
// Customer hasn't collected their unit despite the punto marking it ready.
$pickupRetrasado = $safeAll(
    "SELECT m.id, m.vin_display, m.modelo, m.color, m.cliente_nombre, m.cliente_telefono,
            pv.nombre AS punto_nombre,
            DATEDIFF(?, COALESCE(m.fecha_estado, m.freg)) as dias
     FROM inventario_motos m
     LEFT JOIN puntos_voltika pv ON pv.id = m.punto_voltika_id
     WHERE m.activo = 1
       AND m.estado = 'lista_para_entrega'
       AND m.cliente_nombre IS NOT NULL AND m.cliente_nombre <> ''
       AND DATEDIFF(?, COALESCE(m.fecha_estado, m.freg)) > 7
     ORDER BY dias DESC LIMIT 20",
    [$today, $today]
);
foreach ($pickupRetrasado as $pr) {
    $alerts[] = [
        'tipo'      => 'pickup_retrasado',
        'prioridad' => $pr['dias'] > 14 ? 'alta' : 'media',
        'titulo'    => 'Cliente no recoge su moto',
        'mensaje'   => ($pr['cliente_nombre'] ?: 'Cliente') . ' no ha recogido su ' . $pr['modelo']
                     . ' · ' . ($pr['vin_display'] ?: 's/VIN')
                     . ' en ' . ($pr['punto_nombre'] ?: 'punto') . '. '
                     . $pr['dias'] . ' días desde que se marcó lista para entrega.',
        'icono'     => 'pickup',
    ];
}

// ── 8. Ensamble retrasado — moto en ensamble > 7 días ──
// Punto may be stuck (missing parts, forgot the unit, etc.). Flag for CEDIS.
$ensambleRetrasado = $safeAll(
    "SELECT m.id, m.vin_display, m.modelo, m.color,
            pv.nombre AS punto_nombre,
            DATEDIFF(?, COALESCE(m.fecha_estado, m.freg)) as dias
     FROM inventario_motos m
     LEFT JOIN puntos_voltika pv ON pv.id = m.punto_voltika_id
     WHERE m.activo = 1
       AND m.estado IN ('en_ensamble', 'por_ensamblar')
       AND DATEDIFF(?, COALESCE(m.fecha_estado, m.freg)) > 7
     ORDER BY dias DESC LIMIT 20",
    [$today, $today]
);
foreach ($ensambleRetrasado as $er) {
    $alerts[] = [
        'tipo'      => 'ensamble_retrasado',
        'prioridad' => $er['dias'] > 14 ? 'alta' : 'media',
        'titulo'    => 'Ensamble retrasado',
        'mensaje'   => $er['modelo'] . ' · ' . ($er['vin_display'] ?: 's/VIN')
                     . ' lleva ' . $er['dias'] . ' días en ensamble en '
                     . ($er['punto_nombre'] ?: 'punto') . '. Verifica bloqueos o falta de piezas.',
        'icono'     => 'ensamble',
    ];
}

// ── 9. Consignación estancada — sin vender > 30 días ──
// Aging stock: reconsider relocation, price adjustment or recall.
$consigEstancada = $safeAll(
    "SELECT m.id, m.vin_display, m.modelo, m.color,
            pv.nombre AS punto_nombre,
            DATEDIFF(?, COALESCE(m.fecha_estado, m.freg)) as dias
     FROM inventario_motos m
     LEFT JOIN puntos_voltika pv ON pv.id = m.punto_voltika_id
     WHERE m.activo = 1
       AND m.tipo_asignacion = 'consignacion'
       AND (m.cliente_nombre IS NULL OR m.cliente_nombre = '')
       AND (m.pedido_num IS NULL OR m.pedido_num = '')
       AND m.estado NOT IN ('entregada','retenida','por_llegar')
       AND DATEDIFF(?, COALESCE(m.fecha_estado, m.freg)) > 30
     ORDER BY dias DESC LIMIT 20",
    [$today, $today]
);
foreach ($consigEstancada as $ce) {
    $alerts[] = [
        'tipo'      => 'consignacion_estancada',
        'prioridad' => $ce['dias'] > 60 ? 'alta' : 'media',
        'titulo'    => 'Consignación sin venderse',
        'mensaje'   => $ce['modelo'] . ' · ' . ($ce['vin_display'] ?: 's/VIN')
                     . ' lleva ' . $ce['dias'] . ' días en consignación en '
                     . ($ce['punto_nombre'] ?: 'punto') . ' sin venderse.'
                     . ($ce['dias'] > 60 ? ' Evalúa reubicación o ajuste de precio.' : ''),
        'icono'     => 'consignacion',
    ];
}

// ── Tech Spec EN §7 — operational triggers (CRITICAL) ───────────────────
// Lazy-create escalations table so chargeback / PROFECO / dispute events
// land somewhere queryable. Stripe webhook + admin UI both write to it.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS escalations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kind        VARCHAR(40)  NOT NULL,    -- chargeback | profeco | dispute | card_fail | identity_mismatch
        severity    VARCHAR(20)  NOT NULL DEFAULT 'critical',
        cliente_id  INT NULL,
        transaccion_id INT NULL,
        moto_id     INT NULL,
        ref_externa VARCHAR(120) NULL,        -- Stripe dispute id, PROFECO folio, etc.
        titulo      VARCHAR(200) NOT NULL,
        detalle     TEXT NULL,
        estado      VARCHAR(20)  NOT NULL DEFAULT 'open',  -- open | in_progress | resolved | closed
        asignado_a  VARCHAR(80)  NULL,        -- legal | finance | ops | admin user id
        notas       MEDIUMTEXT   NULL,
        freg        DATETIME DEFAULT CURRENT_TIMESTAMP,
        fmod        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        resolved_at DATETIME NULL,
        INDEX idx_estado_kind (estado, kind),
        INDEX idx_cliente (cliente_id),
        INDEX idx_transaccion (transaccion_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) { error_log('escalations table: ' . $e->getMessage()); }

// Surface every OPEN escalation as an alert. Sort by severity later.
$openEscalations = $safeAll(
    "SELECT id, kind, severity, titulo, detalle, ref_externa, freg
     FROM escalations
     WHERE estado IN ('open','in_progress')
     ORDER BY freg DESC LIMIT 100"
);
$kindIcon = [
    'chargeback'        => 'pago_fallo',
    'profeco'           => 'detenida',
    'dispute'           => 'pago_fallo',
    'card_fail'         => 'pago_fallo',
    'identity_mismatch' => 'detenida',
];
$kindLabel = [
    'chargeback'        => 'Contracargo (chargeback)',
    'profeco'           => 'PROFECO',
    'dispute'           => 'Disputa abierta',
    'card_fail'         => 'Tarjeta fallida (3+ intentos)',
    'identity_mismatch' => 'Identidad NO coincide',
];
foreach ($openEscalations as $esc) {
    $alerts[] = [
        'tipo'      => 'escalation_' . $esc['kind'],
        'prioridad' => $esc['severity'] === 'critical' ? 'alta' : ($esc['severity'] === 'high' ? 'alta' : 'media'),
        'titulo'    => ($kindLabel[$esc['kind']] ?? $esc['kind']) . ': ' . $esc['titulo'],
        'mensaje'   => ($esc['detalle'] ? $esc['detalle'] . ' · ' : '')
                     . ($esc['ref_externa'] ? 'Ref ' . $esc['ref_externa'] . ' · ' : '')
                     . 'Abierta el ' . date('d/m/Y', strtotime($esc['freg'])),
        'icono'     => $kindIcon[$esc['kind']] ?? 'detenida',
        'escalation_id' => (int)$esc['id'],
    ];
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
