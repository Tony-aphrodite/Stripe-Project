<?php
/**
 * GET — List purchases with bike assignment status
 * Returns orders from transacciones + subscripciones_credito
 * with info about whether a bike is assigned or not.
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$pdo = getDB();

$rows = [];

// ── Orders from transacciones ───────────────────────────────────────────
try {
    $stmt = $pdo->query("
        SELECT t.id, t.pedido, t.nombre, t.email, t.telefono,
               t.modelo, t.color, t.tpago, t.total, t.stripe_pi, t.freg,
               t.punto_id, t.punto_nombre, t.folio_contrato,
               t.fecha_estimada_entrega,
               m.id AS moto_id, m.vin_display AS moto_vin, m.estado AS moto_estado,
               m.pago_estado
        FROM transacciones t
        LEFT JOIN inventario_motos m
               ON m.pedido_num = CONCAT('VK-', t.pedido)
              AND m.activo = 1
              AND m.vin NOT REGEXP '^VK-[A-Z0-9]+-[0-9]+-[a-f0-9]+'
        ORDER BY t.freg DESC
        LIMIT 200
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'id'          => (int)$r['id'],
            'pedido'      => $r['pedido'],
            'nombre'      => $r['nombre'],
            'email'       => $r['email'],
            'telefono'    => $r['telefono'],
            'modelo'      => $r['modelo'],
            'color'       => $r['color'],
            'tipo'        => $r['tpago'],
            'monto'       => (float)$r['total'],
            'stripe_pi'   => $r['stripe_pi'],
            'fecha'       => $r['freg'],
            'moto_id'     => $r['moto_id'] ? (int)$r['moto_id'] : null,
            'moto_vin'    => $r['moto_vin'],
            'moto_estado' => $r['moto_estado'],
            'pago_estado' => $r['pago_estado'] ?: 'pendiente',
            'punto_id'    => $r['punto_id'] ?? null,
            'punto_nombre'=> $r['punto_nombre'] ?? null,
            'folio_contrato' => $r['folio_contrato'] ?? null,
            'fecha_estimada_entrega' => $r['fecha_estimada_entrega'] ?? null,
        ];
    }
} catch (Throwable $e) {
    error_log('ventas/listar transacciones: ' . $e->getMessage());
}

// ── Credit subscriptions that have NO matching transacciones row ────────
// These are orphans: customer reached the autopago step (SetupIntent saved to
// subscripciones_credito) but either (a) the transacciones INSERT in
// confirmar-orden.php silently failed, or (b) they never paid the enganche
// through Stripe PaymentIntent. Either way the admin must see them so no
// sale falls through the cracks. Match by telefono (most reliable — both
// tables have it, and enganche+contract+autopago share the phone).
try {
    // Orphan detection: a subscripciones_credito row is an orphan when NO
    // transacciones row exists for the same customer. We match by telefono
    // OR email (whichever is available) to tolerate NULL/empty fields.
    // The previous version required modelo to also match, but modelo is
    // often NULL/"" on legacy rows, and SQL's `NULL = NULL` is false, so
    // recovered rows were still appearing as orphans.
    $stmt = $pdo->query("
        SELECT s.id, s.cliente_id, s.telefono, s.email, s.modelo, s.color,
               s.precio_contado, s.monto_semanal, s.plazo_semanas,
               s.stripe_customer_id, s.freg, s.estado,
               c.nombre AS cliente_nombre
        FROM subscripciones_credito s
        LEFT JOIN clientes c ON c.id = s.cliente_id
        WHERE NOT EXISTS (
            SELECT 1 FROM transacciones t
            WHERE (t.telefono <> '' AND t.telefono = s.telefono)
               OR (t.email    <> '' AND t.email    = s.email)
               OR (t.pedido   =  CONCAT('SC-', s.id))
        )
        ORDER BY s.freg DESC
        LIMIT 100
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'id'          => (int)$r['id'],
            'pedido'      => 'SC-' . $r['id'],
            'nombre'      => $r['cliente_nombre'] ?: ('Cliente #' . ($r['cliente_id'] ?: 's/n')),
            'email'       => $r['email'],
            'telefono'    => $r['telefono'],
            'modelo'      => $r['modelo'],
            'color'       => $r['color'],
            'tipo'        => 'credito-orfano',
            'monto'       => (float)($r['precio_contado'] ?? 0),
            'stripe_pi'   => $r['stripe_customer_id'] ?? '',
            'fecha'       => $r['freg'],
            'moto_id'     => null,
            'moto_vin'    => null,
            'moto_estado' => null,
            'pago_estado' => 'orfano',
            'punto_id'    => null,
            'punto_nombre'=> null,
            'source'      => 'subscripciones_credito',
            'alerta'      => 'Suscripción de crédito sin transacción de enganche — revisar',
        ];
    }
} catch (Throwable $e) {
    error_log('ventas/listar subscripciones_credito: ' . $e->getMessage());
}

// ── Orders captured in transacciones_errores (Plan B recovery table) ────
// confirmar-orden.php writes here when the main INSERT into transacciones
// fails. The admin needs to see these so the sale can be recovered manually.
try {
    // Only show errors that HAVE NOT been recovered yet. After recuperar-lote
    // or recuperar-orden promotes an error into transacciones, recuperado_tx_id
    // is set (to the new tx id, or -1 if skipped as duplicate). Both cases
    // should disappear from the dashboard.
    $stmt = $pdo->query("
        SELECT id, nombre, email, telefono, modelo, color, total,
               stripe_pi, error_msg, freg
        FROM transacciones_errores
        WHERE recuperado_tx_id IS NULL
        ORDER BY freg DESC
        LIMIT 100
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'id'          => (int)$r['id'],
            'pedido'      => 'ERR-' . $r['id'],
            'nombre'      => $r['nombre'],
            'email'       => $r['email'],
            'telefono'    => $r['telefono'],
            'modelo'      => $r['modelo'],
            'color'       => $r['color'],
            'tipo'        => 'error-captura',
            'monto'       => (float)$r['total'],
            'stripe_pi'   => $r['stripe_pi'],
            'fecha'       => $r['freg'],
            'moto_id'     => null,
            'moto_vin'    => null,
            'moto_estado' => null,
            'pago_estado' => 'error',
            'punto_id'    => null,
            'punto_nombre'=> null,
            'source'      => 'transacciones_errores',
            'alerta'      => 'Error al guardar la orden: ' . ($r['error_msg'] ?? 'desconocido'),
        ];
    }
} catch (Throwable $e) {
    // Table may not exist yet — fine, Plan B creates it on first error.
}

// Sort combined rows by fecha desc
usort($rows, fn($a, $b) => strcmp((string)($b['fecha'] ?? ''), (string)($a['fecha'] ?? '')));

// ── Inventory availability per modelo+color ──────────────────────────────
// Used by the dashboard to show "X disponibles" or "Sin inventario — 2 meses"
$disponibles = [];
try {
    $inv = $pdo->query("
        SELECT modelo, color, COUNT(*) AS cnt
        FROM inventario_motos
        WHERE activo = 1
          AND (pedido_num IS NULL OR pedido_num = '')
          AND (cliente_email IS NULL OR cliente_email = '')
          AND vin NOT REGEXP '^VK-[A-Z0-9]+-[0-9]+-[a-f0-9]+'
          AND estado NOT IN ('entregada','retenida')
        GROUP BY modelo, color
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($inv as $i) {
        $key = strtolower(trim($i['modelo'])) . '|' . strtolower(trim($i['color']));
        $disponibles[$key] = (int)$i['cnt'];
    }
} catch (Throwable $e) {}

$twoMonths = date('Y-m-d', strtotime('+2 months'));
foreach ($rows as &$row) {
    $key = strtolower(trim($row['modelo'] ?? '')) . '|' . strtolower(trim($row['color'] ?? ''));
    $stock = $disponibles[$key] ?? 0;
    $row['inventario_disponible'] = $stock;
    if (!$row['moto_id'] && $stock === 0) {
        $row['fecha_estimada_entrega'] = $row['fecha_estimada_entrega'] ?? $twoMonths;
    }
}
unset($row);

// ── Counts ───────────────────────────────────────────────────────────────
$total      = count($rows);
$asignadas  = count(array_filter($rows, fn($r) => $r['moto_id'] !== null));
$sinAsignar = $total - $asignadas;
$orfanos    = count(array_filter($rows, fn($r) => ($r['source'] ?? '') !== ''));

adminJsonOut([
    'ok'    => true,
    'rows'  => $rows,
    'total' => $total,
    'asignadas'   => $asignadas,
    'sin_asignar' => $sinAsignar,
    'orfanos'     => $orfanos,
    'generated_at'=> date('c'),
]);
