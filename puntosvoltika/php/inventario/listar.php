<?php
/**
 * GET — List inventory filtered BY the current point only
 * Dual inventory: entrega vs venta
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$pdo = getDB();
$pid = $ctx['punto_id'];

// All motos assigned to this point
$stmt = $pdo->prepare("SELECT m.*,
    (SELECT estado FROM envios WHERE moto_id=m.id ORDER BY freg DESC LIMIT 1) as envio_estado,
    (SELECT id FROM recepcion_punto WHERE moto_id=m.id ORDER BY freg DESC LIMIT 1) as recepcion_id
    FROM inventario_motos m
    WHERE m.punto_voltika_id=?
    ORDER BY m.fmod DESC");
$stmt->execute([$pid]);
$motos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Per dashboards_diagrams.pdf the Point Panel shows three buckets:
//   1) "Pending arrival" — motos still in transit (estado='por_llegar'), split by
//      showroom (tipo_asignacion='consignacion') vs for-delivery ('voltika_entrega')
//   2) "Para entrega"   — motos at the point with a cliente/pedido (delivery pipeline)
//   3) "Disponible para venta" — showroom stock at the point with no cliente yet
$porLlegarEntrega  = [];
$porLlegarShowroom = [];
$paraEntrega       = [];
$paraVenta         = [];

foreach ($motos as $m) {
    $estado = $m['estado'] ?? '';
    $tipo   = $m['tipo_asignacion'] ?? 'voltika_entrega';
    $vin    = $m['vin'] ?? '';
    $hasCliente = !empty($m['cliente_nombre']) || !empty($m['pedido_num']);

    // Skip CASE 3 placeholder rows (auto-created by confirmar-orden.php with a
    // synthetic VIN like "VK-MOD-0001"). These orders are already surfaced in
    // the `ventas_referido_pendientes` bucket built from transacciones below,
    // so showing the placeholder here would duplicate the same order in two
    // lists. Once CEDIS links a real moto (different row, real VIN), the real
    // moto enters the normal por_llegar/paraEntrega flow.
    if (strpos($vin, 'VK-') === 0) {
        continue;
    }

    if ($estado === 'por_llegar') {
        if ($tipo === 'consignacion') {
            $porLlegarShowroom[] = $m;
        } else {
            $porLlegarEntrega[] = $m;
        }
        continue;
    }

    if ($hasCliente) {
        $paraEntrega[] = $m;
    } else {
        $paraVenta[] = $m;
    }
}

// Per dashboards_diagrams.pdf CASE 3: orders placed online with a punto's
// CODIGO REFERIDO must appear in the point panel as "completed sale via
// referral, pending motorcycle assignment" until CEDIS links a real moto.
// We detect these by querying transacciones directly (referido_tipo='punto',
// this punto's id, caso=3) and excluding ones that already have a real moto
// linked (vin NOT LIKE 'VK-%' which is the placeholder pattern from
// confirmar-orden.php `$vinAuto`).
$ventasReferidoPendientes = [];
try {
    $rStmt = $pdo->prepare("
        SELECT t.id, t.pedido, t.nombre, t.email, t.telefono,
               t.modelo, t.color, t.total, t.tpago, t.freg
        FROM transacciones t
        WHERE t.referido_tipo = 'punto'
          AND t.referido_id   = ?
          AND NOT EXISTS (
              SELECT 1 FROM inventario_motos m
              WHERE m.pedido_num = CONCAT('VK-', t.pedido)
                AND m.vin NOT LIKE 'VK-%'
          )
        ORDER BY t.freg DESC
    ");
    $rStmt->execute([$pid]);
    $ventasReferidoPendientes = $rStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('listar ventas_referido_pendientes: ' . $e->getMessage());
}

puntoJsonOut([
    'punto_id'                       => $pid,
    'ventas_referido_pendientes'     => $ventasReferidoPendientes,
    'inventario_por_llegar_entrega'  => $porLlegarEntrega,
    'inventario_por_llegar_showroom' => $porLlegarShowroom,
    'inventario_entrega'             => $paraEntrega,
    'inventario_venta'               => $paraVenta,
    'total'                          => count($motos),
]);
