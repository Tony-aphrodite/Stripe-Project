<?php
/**
 * GET — List unassigned orders (no bike assigned yet) for "Venta" assignment flow
 * Returns orders split by: with punto (user chose delivery point) / without punto
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$pdo = getDB();

$rows = [];

try {
    // Only eligible orders for shipment assignment (customer brief 2026-04-19):
    //   (a) Payment confirmed — pago_estado IN ('pagada','aprobada',...) OR
    //       credit-family orders with the enganche captured ('parcial').
    //   (b) Has a punto assigned (not 'centro-cercano').
    //   (c) No physical moto linked yet — excludes the synthetic placeholder
    //       VINs created by confirmar-orden.php ("VK-MOD-xxx") so real-moto
    //       assignments are correctly detected via both stripe_pi and pedido_num.
    //   (d) Order by freg DESC — newest first.
    $stmt = $pdo->query("
        SELECT t.id, t.pedido, t.nombre, t.email, t.telefono,
               t.modelo, t.color, t.tpago, t.total, t.stripe_pi, t.freg,
               t.punto_id, t.punto_nombre, t.pago_estado
        FROM transacciones t
        WHERE t.punto_nombre IS NOT NULL
          AND t.punto_nombre <> ''
          AND (t.punto_id IS NULL OR t.punto_id <> 'centro-cercano')
          AND (
                LOWER(t.pago_estado) IN ('pagada','aprobada','approved','paid')
             OR (LOWER(t.tpago) IN ('credito','enganche','parcial') AND LOWER(t.pago_estado) = 'parcial')
              )
          AND NOT EXISTS (
                SELECT 1 FROM inventario_motos m
                WHERE m.activo = 1
                  AND m.vin NOT REGEXP '^VK-[A-Z0-9]+-[0-9]+-[a-f0-9]+'
                  AND (
                        (m.stripe_pi = t.stripe_pi AND m.stripe_pi <> '')
                     OR  m.pedido_num = CONCAT('VK-', t.pedido)
                  )
              )
        ORDER BY t.freg DESC
        LIMIT 100
    ");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'id'           => (int)$r['id'],
            'pedido'       => $r['pedido'],
            'nombre'       => $r['nombre'],
            'email'        => $r['email'],
            'telefono'     => $r['telefono'],
            'modelo'       => $r['modelo'],
            'color'        => $r['color'],
            'tipo'         => $r['tpago'],
            'monto'        => (float)$r['total'],
            'stripe_pi'    => $r['stripe_pi'],
            'pago_estado'  => $r['pago_estado'],
            'fecha'        => $r['freg'],
            'punto_id'     => $r['punto_id'] ?: null,
            'punto_nombre' => $r['punto_nombre'] ?: null,
        ];
    }
} catch (Throwable $e) {
    error_log('ventas/sin-punto: ' . $e->getMessage());
}

adminJsonOut([
    'ok'        => true,
    'rows'      => $rows,
    'total'     => count($rows),
    'con_punto' => count($rows),
    'sin_punto' => 0,
]);
