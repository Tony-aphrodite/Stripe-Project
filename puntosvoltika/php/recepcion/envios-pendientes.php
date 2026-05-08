<?php
/**
 * GET — List pending shipments arriving to this point
 *
 * Bug 3.1 + 3.2 (customer brief 2026-05-08): the reception page must show
 *   - explicit motorcycle status (in transit / not shipped / etc.)
 *   - tracking + carrier + shipment date
 *   - whether the Origin Checklist was completed (origen_certificado flag)
 * Bug 3.4 (same brief): also include motos that are PENDIENTE DE ASIGNACIÓN
 *   — i.e., already assigned to this punto (inventario_motos.punto_voltika_id)
 *   but for which CEDIS hasn't created the envio yet. Those rows are surfaced
 *   with a synthetic estado='pendiente_asignacion' so the UI can render them.
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$pdo = getDB();

// 1) Real envios for this punto.
$stmt = $pdo->prepare("SELECT e.id, e.moto_id, e.punto_destino_id, e.estado,
        e.tracking_number, e.carrier, e.fecha_envio, e.fecha_estimada_llegada,
        e.notas, e.freg,
        m.vin, m.vin_display, m.modelo, m.color,
        m.cliente_nombre, m.cliente_telefono, m.cliente_email, m.pedido_num,
        co.completado AS origen_certificado, co.freg AS origen_fecha
    FROM envios e
    JOIN inventario_motos m ON m.id=e.moto_id
    LEFT JOIN (
        SELECT moto_id, completado, freg
        FROM checklist_origen co1
        WHERE id = (SELECT MAX(id) FROM checklist_origen WHERE moto_id = co1.moto_id)
    ) co ON co.moto_id = m.id
    WHERE e.punto_destino_id=? AND e.estado IN ('lista_para_enviar','enviada')
    ORDER BY e.freg DESC");
$stmt->execute([$ctx['punto_id']]);
$envios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2) Bug 3.4 — motos asignadas al punto sin envío todavía.
//    Estado del inventario es 'asignada' (set by inventario/asignar-punto.php
//    or similar) y NO existe envío activo. Estos casos antes no aparecían en
//    Recepción y dejaban al operador sin visibilidad de su pipeline.
$pending = [];
try {
    $pq = $pdo->prepare("SELECT m.id AS moto_id, m.vin, m.vin_display, m.modelo, m.color,
            m.cliente_nombre, m.cliente_telefono, m.cliente_email, m.pedido_num,
            m.estado AS estado_inventario, m.fecha_estado AS fecha_asignacion,
            co.completado AS origen_certificado, co.freg AS origen_fecha
        FROM inventario_motos m
        LEFT JOIN (
            SELECT moto_id, completado, freg
            FROM checklist_origen co1
            WHERE id = (SELECT MAX(id) FROM checklist_origen WHERE moto_id = co1.moto_id)
        ) co ON co.moto_id = m.id
        WHERE m.activo = 1
          AND m.punto_voltika_id = ?
          AND m.estado IN ('asignada', 'pendiente_asignacion', 'por_llegar')
          AND NOT EXISTS (
                SELECT 1 FROM envios e2
                WHERE e2.moto_id = m.id
                  AND e2.estado IN ('lista_para_enviar','enviada','en_transito','recibida')
          )
        ORDER BY m.fecha_estado DESC");
    $pq->execute([$ctx['punto_id']]);
    foreach ($pq->fetchAll(PDO::FETCH_ASSOC) as $row) {
        // Synthetic envio shape so the UI can paint it the same way.
        $pending[] = [
            'id'                     => null,
            'moto_id'                => $row['moto_id'],
            'punto_destino_id'       => $ctx['punto_id'],
            'estado'                 => 'pendiente_asignacion',
            'tracking_number'        => null,
            'carrier'                => null,
            'fecha_envio'            => null,
            'fecha_estimada_llegada' => null,
            'notas'                  => null,
            'freg'                   => $row['fecha_asignacion'],
            'vin'                    => $row['vin'],
            'vin_display'            => $row['vin_display'],
            'modelo'                 => $row['modelo'],
            'color'                  => $row['color'],
            'cliente_nombre'         => $row['cliente_nombre'],
            'cliente_telefono'       => $row['cliente_telefono'],
            'cliente_email'          => $row['cliente_email'],
            'pedido_num'             => $row['pedido_num'],
            'origen_certificado'     => $row['origen_certificado'],
            'origen_fecha'           => $row['origen_fecha'],
        ];
    }
} catch (Throwable $e) {
    // Non-fatal — main envios list still served. Logged for debug.
    error_log('envios-pendientes pendiente_asignacion: ' . $e->getMessage());
}

puntoJsonOut(['envios' => array_merge($envios, $pending)]);
