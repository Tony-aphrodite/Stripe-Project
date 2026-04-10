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
               m.id AS moto_id, m.vin_display AS moto_vin, m.estado AS moto_estado,
               m.pago_estado
        FROM transacciones t
        LEFT JOIN inventario_motos m ON m.stripe_pi = t.stripe_pi AND m.stripe_pi <> ''
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
        ];
    }
} catch (Throwable $e) {
    error_log('ventas/listar transacciones: ' . $e->getMessage());
}

// ── Counts ───────────────────────────────────────────────────────────────
$total      = count($rows);
$asignadas  = count(array_filter($rows, fn($r) => $r['moto_id'] !== null));
$sinAsignar = $total - $asignadas;

adminJsonOut([
    'ok'    => true,
    'rows'  => $rows,
    'total' => $total,
    'asignadas'   => $asignadas,
    'sin_asignar' => $sinAsignar,
]);
