<?php
/**
 * Portal Clientes — Estado de entrega
 * Returns pending/active delivery info for the authenticated client:
 *  - moto asignada (modelo, color, vin)
 *  - punto voltika (nombre, direccion, telefono)
 *  - estado actual de la entrega (otp_enviado, confirmado, rostro_ok, checklist_ok, firmada, entregada)
 *  - checklist avance
 *  - OTP recibido (si existe y no expiró)
 */
require_once __DIR__ . '/../bootstrap.php';

$cid = portalRequireAuth();
$pdo = getDB();

try {
    // Find active subscription / moto for this client
    $stmt = $pdo->prepare("SELECT im.*, pv.nombre AS punto_nombre, pv.direccion AS punto_direccion,
            pv.telefono AS punto_telefono, pv.ciudad AS punto_ciudad
        FROM inventario_motos im
        LEFT JOIN puntos_voltika pv ON pv.id = im.punto_voltika_id
        WHERE im.cliente_id = ?
          AND im.estado IN ('asignada','en_transito','recibida','retenida','en_entrega','entregada')
        ORDER BY im.id DESC LIMIT 1");
    $stmt->execute([$cid]);
    $moto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$moto) {
        portalJsonOut(['ok' => true, 'entrega' => null]);
    }

    // Find active entrega row
    $stmt = $pdo->prepare("SELECT * FROM entregas WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$moto['id']]);
    $entrega = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // Active OTP lives in entregas.otp_code
    $otp = null;
    if ($entrega && !empty($entrega['otp_code']) && empty($entrega['otp_verified'])) {
        $exp = strtotime($entrega['otp_expires'] ?? '');
        if ($exp && $exp > time()) $otp = $entrega['otp_code'];
    }

    // Checklist (may not exist yet — ignore errors)
    $checklist = null;
    try {
        $stmt = $pdo->prepare("SELECT * FROM checklist_entrega_v2 WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$moto['id']]);
        $checklist = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}

    // Skydrop shipment tracking — latest envio row carries the ETA
    $envio = null;
    try {
        $stmt = $pdo->prepare("SELECT estado, fecha_envio, fecha_estimada_llegada, fecha_recepcion,
                tracking_number, carrier
            FROM envios WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$moto['id']]);
        $envio = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}

    // Resumen de estado para UI
    $estadoUi = 'pendiente';
    if ($moto['estado'] === 'entregada') $estadoUi = 'entregada';
    elseif (!empty($moto['cliente_acta_firmada'])) $estadoUi = 'firmada';
    elseif ($checklist && !empty($checklist['vin_coincide'])) $estadoUi = 'checklist_ok';
    elseif ($checklist && (!empty($checklist['face_match_score']) || !empty($checklist['fase1_completada']))) $estadoUi = 'rostro_ok';
    elseif ($entrega && $entrega['estado'] === 'confirmado') $estadoUi = 'confirmado';
    elseif ($entrega && $entrega['estado'] === 'otp_enviado') $estadoUi = 'otp_enviado';

    portalJsonOut([
        'ok' => true,
        'entrega' => [
            'moto_id'       => (int)$moto['id'],
            'modelo'        => $moto['modelo'] ?? null,
            'color'         => $moto['color'] ?? null,
            'vin'           => $moto['vin'] ?? null,
            'vin_display'   => isset($moto['vin']) ? substr($moto['vin'], -6) : null,
            'estado_moto'   => $moto['estado'] ?? null,
            'estado_ui'     => $estadoUi,
            'acta_firmada'  => (int)($moto['cliente_acta_firmada'] ?? 0),
            'acta_fecha'    => $moto['cliente_acta_fecha'] ?? null,
            'recepcion_confirmada' => (int)($moto['cliente_recepcion_ok'] ?? 0),
            'punto' => [
                'nombre'    => $moto['punto_nombre'],
                'direccion' => $moto['punto_direccion'],
                'telefono'  => $moto['punto_telefono'],
                'ciudad'    => $moto['punto_ciudad'],
            ],
            'otp_activo'    => $otp,
            'checklist'     => $checklist,
            'fecha_recoleccion' => $moto['fecha_entrega_estimada'] ?? null,
            'envio'         => $envio ? [
                'estado'                 => $envio['estado'],
                'fecha_envio'            => $envio['fecha_envio'],
                'fecha_estimada_llegada' => $envio['fecha_estimada_llegada'],
                'fecha_recepcion'        => $envio['fecha_recepcion'],
                'tracking_number'        => $envio['tracking_number'],
                'carrier'                => $envio['carrier'],
            ] : null,
        ],
    ]);
} catch (Throwable $e) {
    error_log('entrega/estado: ' . $e->getMessage());
    // Return empty state instead of 500 — schema may not be fully initialized
    portalJsonOut(['ok' => true, 'entrega' => null]);
}
