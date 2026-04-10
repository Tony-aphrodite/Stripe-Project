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
    // Orders without a bike assigned (no matching inventario_motos by stripe_pi)
    $stmt = $pdo->query("
        SELECT t.id, t.pedido, t.nombre, t.email, t.telefono,
               t.modelo, t.color, t.tpago, t.total, t.stripe_pi, t.freg,
               t.punto_id, t.punto_nombre
        FROM transacciones t
        LEFT JOIN inventario_motos m ON m.stripe_pi = t.stripe_pi AND m.stripe_pi <> ''
        WHERE m.id IS NULL
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
            'fecha'        => $r['freg'],
            'punto_id'     => $r['punto_id'] ?: null,
            'punto_nombre' => $r['punto_nombre'] ?: null,
        ];
    }
} catch (Throwable $e) {
    error_log('ventas/sin-punto: ' . $e->getMessage());
}

$conPunto = count(array_filter($rows, fn($r) => $r['punto_id'] !== null));
$sinPunto = count($rows) - $conPunto;

adminJsonOut([
    'ok'        => true,
    'rows'      => $rows,
    'total'     => count($rows),
    'con_punto' => $conPunto,
    'sin_punto' => $sinPunto,
]);
