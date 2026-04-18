<?php
/**
 * GET — Inventario agrupado por punto con desglose por estado y aging.
 *
 * Parámetros:
 *   ?punto_id=N       → detalle de un solo punto
 *   (sin parámetros)  → resumen de todos los puntos (un registro por punto)
 *
 * Respuesta (con punto_id):
 * {
 *   punto: { id, nombre, ciudad },
 *   consignacion:       [ {vin, modelo, color, dias_en_punto, ...}, ... ],   // ventas directas en tienda
 *   en_transito:        [ ... ],   // motos en camino a este punto
 *   en_ensamble:        [ ... ],
 *   lista_para_entrega: [ ... ],   // con cliente asignado
 *   disponible_venta:   [ ... ],   // sin cliente, recibida (= consignación pool de venta directa)
 *   pagos_pendientes:   [ ... ],   // órdenes vinculadas al punto con pago_estado != pagada
 *   resumen: { consignacion, en_transito, en_ensamble, lista_para_entrega, disponible_venta, pagos_pendientes }
 * }
 *
 * Respuesta (sin punto_id — resumen global):
 * {
 *   puntos: [
 *     { id, nombre, ciudad, consignacion_count, en_transito_count, en_ensamble_count,
 *       lista_para_entrega_count, disponible_venta_count, pagos_pendientes_count,
 *       aging_max_dias },
 *     ...
 *   ]
 * }
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$pdo = getDB();
$puntoId = isset($_GET['punto_id']) ? (int)$_GET['punto_id'] : 0;

function motoRow(array $m): array {
    return [
        'id'               => (int)$m['id'],
        'vin'              => $m['vin'] ?? null,
        'vin_display'      => $m['vin_display'] ?? (isset($m['vin']) ? substr($m['vin'], -8) : null),
        'modelo'           => $m['modelo'] ?? null,
        'color'            => $m['color'] ?? null,
        'estado'           => $m['estado'] ?? null,
        'tipo_asignacion'  => $m['tipo_asignacion'] ?? null,
        'pedido_num'       => $m['pedido_num'] ?? null,
        'cliente_nombre'   => $m['cliente_nombre'] ?? null,
        'cliente_telefono' => $m['cliente_telefono'] ?? null,
        'pago_estado'      => $m['pago_estado'] ?? null,
        'fecha_estado'     => $m['fecha_estado'] ?? null,
        'dias_en_punto'    => isset($m['dias_en_punto']) ? (int)$m['dias_en_punto'] : null,
        'fecha_entrega_estimada' => $m['fecha_entrega_estimada'] ?? null,
    ];
}

// ── Modo detalle: un solo punto ─────────────────────────────────────────
if ($puntoId > 0) {
    $p = $pdo->prepare("SELECT id, nombre, ciudad, direccion FROM puntos_voltika WHERE id = ? LIMIT 1");
    $p->execute([$puntoId]);
    $punto = $p->fetch(PDO::FETCH_ASSOC);
    if (!$punto) adminJsonOut(['error' => 'Punto no encontrado'], 404);

    $sql = "SELECT m.*,
                CASE WHEN m.punto_voltika_id IS NOT NULL
                     THEN DATEDIFF(CURDATE(), COALESCE(m.fecha_estado, m.freg))
                     ELSE NULL END AS dias_en_punto
            FROM inventario_motos m
            WHERE m.activo = 1 AND m.punto_voltika_id = ?
            ORDER BY COALESCE(m.fecha_estado, m.freg) ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$puntoId]);

    $consignacion = [];
    $enTransito   = [];
    $enEnsamble   = [];
    $listaEntrega = [];
    $dispVenta    = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $row = motoRow($m);
        $estado = $m['estado'] ?? '';
        $tipo   = $m['tipo_asignacion'] ?? '';
        $hasCliente = !empty($m['cliente_nombre']) || !empty($m['pedido_num']);

        if ($estado === 'por_llegar') {
            $enTransito[] = $row;
            continue;
        }
        if (in_array($estado, ['en_ensamble', 'por_ensamblar'], true)) {
            $enEnsamble[] = $row;
            continue;
        }
        // Ready for delivery to a specific client
        if ($estado === 'lista_para_entrega' && $hasCliente) {
            $listaEntrega[] = $row;
            continue;
        }
        // Consigned showroom stock (no client yet) — "venta directa en tienda"
        if ($tipo === 'consignacion' && !$hasCliente) {
            $consignacion[] = $row;
            continue;
        }
        // Received at punto with no client yet → available for direct sale
        if (in_array($estado, ['recibida', 'lista_para_entrega'], true) && !$hasCliente) {
            $dispVenta[] = $row;
            continue;
        }
    }

    // Órdenes con pagos pendientes vinculadas a este punto (transacciones)
    $pagosPendientes = [];
    try {
        $pp = $pdo->prepare("SELECT t.id, t.pedido, t.nombre, t.email, t.telefono,
                t.modelo, t.color, t.total, t.tpago, t.stripe_pi, t.pago_estado, t.freg,
                t.last_reminder_at, t.reminders_sent_count
            FROM transacciones t
            WHERE (t.punto_nombre = ? OR t.referido_id = ? AND t.referido_tipo = 'punto')
              AND t.pago_estado IN ('pendiente','fallido')
              AND t.stripe_pi IS NOT NULL AND t.stripe_pi <> ''
            ORDER BY t.freg DESC");
        $pp->execute([$punto['nombre'], $puntoId]);
        foreach ($pp->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pagosPendientes[] = [
                'id' => (int)$r['id'], 'pedido' => $r['pedido'],
                'nombre' => $r['nombre'], 'email' => $r['email'], 'telefono' => $r['telefono'],
                'modelo' => $r['modelo'], 'color' => $r['color'], 'total' => (float)$r['total'],
                'tpago' => $r['tpago'], 'pago_estado' => $r['pago_estado'], 'freg' => $r['freg'],
                'last_reminder_at' => $r['last_reminder_at'],
                'reminders_sent_count' => (int)($r['reminders_sent_count'] ?? 0),
            ];
        }
    } catch (Throwable $e) { error_log('por-punto pagos: ' . $e->getMessage()); }

    adminJsonOut([
        'ok'    => true,
        'punto' => [
            'id'        => (int)$punto['id'],
            'nombre'    => $punto['nombre'],
            'ciudad'    => $punto['ciudad'] ?? null,
            'direccion' => $punto['direccion'] ?? null,
        ],
        'consignacion'       => $consignacion,
        'en_transito'        => $enTransito,
        'en_ensamble'        => $enEnsamble,
        'lista_para_entrega' => $listaEntrega,
        'disponible_venta'   => $dispVenta,
        'pagos_pendientes'   => $pagosPendientes,
        'resumen' => [
            'consignacion'       => count($consignacion),
            'en_transito'        => count($enTransito),
            'en_ensamble'        => count($enEnsamble),
            'lista_para_entrega' => count($listaEntrega),
            'disponible_venta'   => count($dispVenta),
            'pagos_pendientes'   => count($pagosPendientes),
        ],
    ]);
}

// ── Modo resumen global: un registro por punto ──────────────────────────
$sql = "SELECT p.id, p.nombre, p.ciudad,
            SUM(CASE WHEN m.estado = 'por_llegar' THEN 1 ELSE 0 END) AS en_transito_count,
            SUM(CASE WHEN m.estado IN ('en_ensamble','por_ensamblar') THEN 1 ELSE 0 END) AS en_ensamble_count,
            SUM(CASE WHEN m.estado = 'lista_para_entrega' AND (m.cliente_nombre <> '' OR m.pedido_num <> '') THEN 1 ELSE 0 END) AS lista_para_entrega_count,
            SUM(CASE WHEN m.tipo_asignacion = 'consignacion' AND m.cliente_nombre = '' AND m.pedido_num = '' THEN 1 ELSE 0 END) AS consignacion_count,
            SUM(CASE WHEN m.estado = 'recibida' AND (m.cliente_nombre = '' OR m.cliente_nombre IS NULL) AND (m.pedido_num = '' OR m.pedido_num IS NULL) THEN 1 ELSE 0 END) AS disponible_venta_count,
            MAX(CASE WHEN m.punto_voltika_id IS NOT NULL AND m.tipo_asignacion = 'consignacion'
                 THEN DATEDIFF(CURDATE(), COALESCE(m.fecha_estado, m.freg)) ELSE 0 END) AS aging_max_dias
        FROM puntos_voltika p
        LEFT JOIN inventario_motos m ON m.punto_voltika_id = p.id AND m.activo = 1
        WHERE p.activo = 1
        GROUP BY p.id, p.nombre, p.ciudad
        ORDER BY p.nombre";

$puntos = [];
try {
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        // Pagos pendientes per punto — separate query to avoid JOIN explosion
        $pp = $pdo->prepare("SELECT COUNT(*) FROM transacciones
            WHERE (punto_nombre = ? OR (referido_id = ? AND referido_tipo='punto'))
              AND pago_estado IN ('pendiente','fallido')
              AND stripe_pi IS NOT NULL AND stripe_pi <> ''");
        $pp->execute([$r['nombre'], (int)$r['id']]);
        $pendingCount = (int)$pp->fetchColumn();

        $puntos[] = [
            'id'                       => (int)$r['id'],
            'nombre'                   => $r['nombre'],
            'ciudad'                   => $r['ciudad'] ?? null,
            'consignacion_count'       => (int)$r['consignacion_count'],
            'en_transito_count'        => (int)$r['en_transito_count'],
            'en_ensamble_count'        => (int)$r['en_ensamble_count'],
            'lista_para_entrega_count' => (int)$r['lista_para_entrega_count'],
            'disponible_venta_count'   => (int)$r['disponible_venta_count'],
            'pagos_pendientes_count'   => $pendingCount,
            'aging_max_dias'           => (int)$r['aging_max_dias'],
        ];
    }
} catch (Throwable $e) {
    error_log('por-punto global: ' . $e->getMessage());
    adminJsonOut(['error' => $e->getMessage()], 500);
}

adminJsonOut(['ok' => true, 'puntos' => $puntos]);
