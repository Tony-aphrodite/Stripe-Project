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

// Optional scope by selected purchase
$reqTipo = isset($_GET['compra_tipo']) ? preg_replace('/[^a-z]/', '', strtolower($_GET['compra_tipo'])) : '';
$reqId   = isset($_GET['compra_id']) ? (int)$_GET['compra_id'] : 0;

try {
    // Load client contact so we can resolve motos for credit subs (which link by phone/email)
    $cStmt = $pdo->prepare("SELECT nombre, email, telefono FROM clientes WHERE id = ?");
    $cStmt->execute([$cid]);
    $cliente = $cStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $tel10 = preg_replace('/\D/', '', (string)($cliente['telefono'] ?? ''));
    if (strlen($tel10) > 10) $tel10 = substr($tel10, -10);
    $em = $cliente['email'] ?? null;

    $moto = null;
    $motoSelect = "SELECT im.*, pv.nombre AS punto_nombre, pv.direccion AS punto_direccion,
            pv.telefono AS punto_telefono, pv.ciudad AS punto_ciudad
        FROM inventario_motos im
        LEFT JOIN puntos_voltika pv ON pv.id = im.punto_voltika_id";

    if (($reqTipo === 'contado' || $reqTipo === 'msi') && $reqId > 0) {
        // Scoped contado/msi: find moto via transaccion_id or stripe_pi
        $tStmt = $pdo->prepare("SELECT id, stripe_pi, pedido FROM transacciones WHERE id = ? LIMIT 1");
        $tStmt->execute([$reqId]);
        $txn = $tStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($txn) {
            $q = $pdo->prepare("$motoSelect WHERE im.transaccion_id = ? ORDER BY im.id DESC LIMIT 1");
            $q->execute([(int)$txn['id']]);
            $moto = $q->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$moto && !empty($txn['stripe_pi'])) {
                $q = $pdo->prepare("$motoSelect WHERE im.stripe_pi = ? ORDER BY im.id DESC LIMIT 1");
                $q->execute([$txn['stripe_pi']]);
                $moto = $q->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$moto && !empty($txn['pedido'])) {
                $q = $pdo->prepare("$motoSelect WHERE im.pedido_num = CONCAT('VK-', ?) ORDER BY im.id DESC LIMIT 1");
                $q->execute([$txn['pedido']]);
                $moto = $q->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
    } elseif ($reqTipo === 'credito' && $reqId > 0) {
        // Scoped credit: find moto via subscription's contact (no direct FK)
        $sStmt = $pdo->prepare("SELECT telefono, email FROM subscripciones_credito WHERE id = ? LIMIT 1");
        $sStmt->execute([$reqId]);
        $sRow = $sStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $sTel = preg_replace('/\D/', '', (string)($sRow['telefono'] ?? ''));
        if (strlen($sTel) > 10) $sTel = substr($sTel, -10);
        $sEm = $sRow['email'] ?? null;
        $wh = []; $pv = [];
        if ($sTel) { $wh[] = "RIGHT(REPLACE(REPLACE(im.cliente_telefono,'+',''),' ',''),10) = ?"; $pv[] = $sTel; }
        if ($sEm)  { $wh[] = "im.cliente_email = ?"; $pv[] = $sEm; }
        if ($wh){
            $q = $pdo->prepare("$motoSelect WHERE (" . implode(' OR ', $wh) . ")
                AND im.estado IN ('recibida','lista_para_entrega','por_validar_entrega','en_ensamble','por_ensamblar','retenida','entregada')
                ORDER BY im.id DESC LIMIT 1");
            $q->execute($pv);
            $moto = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }

    // Default lookup by cliente_id when no scope given or scope didn't match
    if (!$moto) {
        $stmt = $pdo->prepare("$motoSelect
            WHERE im.cliente_id = ?
              AND im.estado IN ('recibida','lista_para_entrega','por_validar_entrega','en_ensamble','por_ensamblar','retenida','entregada')
            ORDER BY im.id DESC LIMIT 1");
        $stmt->execute([$cid]);
        $moto = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

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
