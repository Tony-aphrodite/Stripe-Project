<?php
/**
 * GET — List orders ready to ship: moto already assigned in Ventas + punto
 * set + payment confirmed + no shipment yet. Customer brief 2026-04-19:
 * moto assignment happens in the Ventas screen (via asignar-moto.php); this
 * endpoint feeds the Envíos flow, which only adds the shipping info.
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$pdo = getDB();

$rows = [];

try {
    // Eligibility criteria:
    //   (a) Payment confirmed — pago_estado IN ('pagada','aprobada',...) OR
    //       credit-family orders with the enganche captured ('parcial').
    //   (b) Punto assigned (not 'centro-cercano').
    //   (c) A REAL moto linked (placeholder VK-MOD-xxx is excluded via the
    //       NOT REGEXP filter) — matched by stripe_pi or pedido_num.
    //   (d) No shipment exists yet for that moto (en envios table).
    //   (e) Newest first.
    $stmt = $pdo->query("
        SELECT t.id, t.pedido, t.nombre, t.email, t.telefono,
               t.modelo, t.color, t.tpago, t.total, t.stripe_pi, t.freg,
               t.punto_id, t.punto_nombre, t.pago_estado,
               m.id         AS moto_id,
               m.vin        AS moto_vin,
               m.vin_display AS moto_vin_display,
               m.modelo     AS moto_modelo,
               m.color      AS moto_color,
               m.punto_voltika_id AS moto_punto_id
        FROM transacciones t
        INNER JOIN inventario_motos m
          ON m.activo = 1
         AND m.vin NOT REGEXP '^VK-[A-Z0-9]+-[0-9]+-[a-f0-9]+'
         AND (
               (m.stripe_pi = t.stripe_pi AND m.stripe_pi <> '')
            OR  m.pedido_num = CONCAT('VK-', t.pedido)
         )
        WHERE t.punto_nombre IS NOT NULL
          AND t.punto_nombre <> ''
          AND (t.punto_id IS NULL OR t.punto_id <> 'centro-cercano')
          AND (
                LOWER(t.pago_estado) IN ('pagada','aprobada','approved','paid')
             OR (LOWER(t.tpago) IN ('credito','enganche','parcial') AND LOWER(t.pago_estado) = 'parcial')
              )
          AND NOT EXISTS (
                SELECT 1 FROM envios e WHERE e.moto_id = m.id
              )
        ORDER BY t.freg DESC
        LIMIT 100
    ");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'id'            => (int)$r['id'],
            'pedido'        => $r['pedido'],
            'nombre'        => $r['nombre'],
            'email'         => $r['email'],
            'telefono'      => $r['telefono'],
            'modelo'        => $r['modelo'],
            'color'         => $r['color'],
            'tipo'          => $r['tpago'],
            'monto'         => (float)$r['total'],
            'stripe_pi'     => $r['stripe_pi'],
            'pago_estado'   => $r['pago_estado'],
            'fecha'         => $r['freg'],
            'punto_id'      => $r['punto_id'] ?: null,
            'punto_nombre'  => $r['punto_nombre'] ?: null,
            'moto_id'       => (int)$r['moto_id'],
            'moto_vin'      => $r['moto_vin_display'] ?: $r['moto_vin'],
            'moto_modelo'   => $r['moto_modelo'],
            'moto_color'    => $r['moto_color'],
            'moto_punto_id' => $r['moto_punto_id'] ? (int)$r['moto_punto_id'] : null,
        ];
    }
} catch (Throwable $e) {
    error_log('ventas/sin-punto: ' . $e->getMessage());
}

adminJsonOut([
    'ok'    => true,
    'rows'  => $rows,
    'total' => count($rows),
]);
