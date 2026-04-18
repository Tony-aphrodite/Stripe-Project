<?php
/**
 * GET — List ALL purchases (credito subscripciones + contado/msi transacciones)
 * linked to the authenticated client via cliente_id, telefono or email.
 *
 * Each purchase includes enough context for the "Mis compras" card list AND
 * for a detail screen: model/color, fecha, estado, montos, linked moto (if
 * any), linked punto (if any), delivery progress, payment summary.
 *
 * Response:
 * {
 *   ok: true,
 *   compras: [
 *     { id, tipo:'credito'|'contado'|'msi', modelo, color, fecha_compra,
 *       monto_total|pago_semanal, plazo_meses, estado, fecha_inicio, fecha_entrega,
 *       moto:{ vin, estado }, punto:{ nombre, direccion, ciudad },
 *       entrega:{ paso, etiqueta, estado_db },
 *       pagos:{ pagados, total, atrasados },
 *       pago_estado  // contado/msi
 *     },
 *     ...
 *   ]
 * }
 */
require_once __DIR__ . '/../bootstrap.php';
$cid = portalRequireAuth();

$pdo = getDB();

// Load client contact info for matching
$cStmt = $pdo->prepare("SELECT nombre, email, telefono FROM clientes WHERE id = ?");
$cStmt->execute([$cid]);
$cliente = $cStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$tel10 = preg_replace('/\D/', '', (string)($cliente['telefono'] ?? ''));
if (strlen($tel10) > 10) $tel10 = substr($tel10, -10);
$email = $cliente['email'] ?? null;

$compras = [];

// ── 1) Credit subscriptions ────────────────────────────────────────────────
try {
    $sql = "SELECT id, modelo, color, monto_semanal, plazo_meses, plazo_semanas,
                fecha_inicio, fecha_entrega, freg, estado,
                stripe_customer_id, stripe_payment_method_id,
                telefono AS sub_telefono, email AS sub_email
            FROM subscripciones_credito
            WHERE cliente_id = ?";
    $params = [$cid];
    if ($tel10) { $sql .= " OR RIGHT(REPLACE(REPLACE(telefono,'+',''),' ',''),10) = ?"; $params[] = $tel10; }
    if ($email) { $sql .= " OR email = ?"; $params[] = $email; }
    $sql .= " ORDER BY id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        // Delivery status from inventario_motos (best match by telefono/email/subscripcion id)
        $moto = null;
        try {
            $mStmt = $pdo->prepare("SELECT vin, vin_display, estado, modelo, color
                FROM inventario_motos
                WHERE (cliente_telefono = ? OR cliente_email = ?)
                ORDER BY id DESC LIMIT 1");
            $mStmt->execute([$s['sub_telefono'] ?: $cliente['telefono'] ?? '', $s['sub_email'] ?: $email]);
            $moto = $mStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {}

        // Payment summary
        $pagoSum = ['pagados' => 0, 'total' => 0, 'atrasados' => 0];
        try {
            $ps = $pdo->prepare("SELECT
                    SUM(CASE WHEN estado IN ('paid_auto','paid_manual') THEN 1 ELSE 0 END) AS pagados,
                    COUNT(*) AS total,
                    SUM(CASE WHEN estado = 'overdue' THEN 1 ELSE 0 END) AS atrasados
                FROM ciclos_pago WHERE subscripcion_id = ?");
            $ps->execute([$s['id']]);
            $row = $ps->fetch(PDO::FETCH_ASSOC) ?: [];
            $pagoSum = [
                'pagados'   => (int)($row['pagados']   ?? 0),
                'total'     => (int)($row['total']     ?? 0),
                'atrasados' => (int)($row['atrasados'] ?? 0),
            ];
        } catch (Throwable $e) {}

        $compras[] = [
            'tipo'          => 'credito',
            'id'            => (int)$s['id'],
            'modelo'        => $s['modelo'] ?? ($moto['modelo'] ?? null),
            'color'         => $s['color']  ?? ($moto['color']  ?? null),
            'pago_semanal'  => (float)($s['monto_semanal'] ?? 0),
            'plazo_meses'   => (int)($s['plazo_meses'] ?? 0),
            'plazo_semanas' => (int)($s['plazo_semanas'] ?? 0),
            'fecha_compra'  => $s['freg'],
            'fecha_inicio'  => $s['fecha_inicio'],
            'fecha_entrega' => $s['fecha_entrega'] ?? null,
            'estado'        => $s['estado'] ?? 'activa',
            'tiene_tarjeta' => !empty($s['stripe_payment_method_id']),
            'moto'          => $moto ? [
                'vin'    => $moto['vin_display'] ?: $moto['vin'],
                'estado' => $moto['estado'],
            ] : null,
            'pagos'         => $pagoSum,
        ];
    }
} catch (Throwable $e) {
    error_log('compras subs: ' . $e->getMessage());
}

// ── 2) Contado / MSI transactions ───────────────────────────────────────────
try {
    $where = [];
    $params = [];
    if ($tel10) { $where[] = "RIGHT(REPLACE(REPLACE(telefono,'+',''),' ',''),10) = ?"; $params[] = $tel10; }
    if ($email) { $where[] = "email = ?"; $params[] = $email; }
    if (!$where) $where[] = '0';

    $sql = "SELECT id, pedido, nombre, modelo, color, total, tpago, msi_meses, freg,
                   pago_estado, stripe_pi, punto_nombre, estado AS estado_mx, ciudad, cp
            FROM transacciones
            WHERE (" . implode(' OR ', $where) . ")
              AND tpago IN ('contado','msi','spei','oxxo')
            ORDER BY id DESC";
    $tStmt = $pdo->prepare($sql);
    $tStmt->execute($params);
    foreach ($tStmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        // Linked moto via stripe_pi or pedido_num
        $moto = null;
        try {
            $mStmt = null;
            if (!empty($t['stripe_pi'])) {
                $mStmt = $pdo->prepare("SELECT vin, vin_display, estado, modelo, color, punto_nombre
                    FROM inventario_motos WHERE stripe_pi = ? ORDER BY id DESC LIMIT 1");
                $mStmt->execute([$t['stripe_pi']]);
                $moto = $mStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$moto && !empty($t['pedido'])) {
                $mStmt = $pdo->prepare("SELECT vin, vin_display, estado, modelo, color, punto_nombre
                    FROM inventario_motos WHERE pedido_num = CONCAT('VK-', ?) ORDER BY id DESC LIMIT 1");
                $mStmt->execute([$t['pedido']]);
                $moto = $mStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        } catch (Throwable $e) {}

        // Map state to 4-step delivery progress (same as cliente/estado.php)
        $estadoMoto = $moto['estado'] ?? null;
        $paso = 1; $etiqueta = 'preparacion';
        switch ($estadoMoto) {
            case 'lista_para_entrega':  $paso = 2; $etiqueta = 'asignacion'; break;
            case 'por_validar_entrega': $paso = 3; $etiqueta = 'en_transito'; break;
            case 'entregada':           $paso = 4; $etiqueta = 'listo'; break;
        }

        $compras[] = [
            'tipo'         => ($t['tpago'] === 'msi' ? 'msi' : 'contado'),
            'id'           => (int)$t['id'],
            'pedido'       => $t['pedido'],
            'modelo'       => $t['modelo'] ?? ($moto['modelo'] ?? null),
            'color'        => $t['color']  ?? ($moto['color']  ?? null),
            'total'        => (float)($t['total'] ?? 0),
            'msi_meses'    => $t['msi_meses'] ? (int)$t['msi_meses'] : null,
            'fecha_compra' => $t['freg'],
            'pago_estado'  => $t['pago_estado'] ?: 'pendiente',
            'metodo'       => $t['tpago'],
            'estado'       => $estadoMoto ?: 'sin_asignar',
            'moto'         => $moto ? [
                'vin'    => $moto['vin_display'] ?: $moto['vin'],
                'estado' => $moto['estado'],
            ] : null,
            'entrega'      => [
                'paso'      => $paso,
                'etiqueta'  => $etiqueta,
                'estado_db' => $estadoMoto,
                'punto_nombre' => $moto['punto_nombre'] ?? $t['punto_nombre'] ?? null,
            ],
        ];
    }
} catch (Throwable $e) {
    error_log('compras txns: ' . $e->getMessage());
}

// Sort all compras by fecha_compra desc (so most recent first across both types)
usort($compras, function($a, $b){
    return strcmp((string)($b['fecha_compra'] ?? ''), (string)($a['fecha_compra'] ?? ''));
});

portalJsonOut([
    'ok'      => true,
    'total'   => count($compras),
    'compras' => $compras,
    'cliente' => [
        'id' => $cid,
        'nombre'   => trim(($cliente['nombre'] ?? '')),
        'telefono' => $cliente['telefono'] ?? null,
        'email'    => $cliente['email']    ?? null,
    ],
]);
