<?php
/**
 * Voltika — Client Credit Portal API
 * No dealer auth required — uses pedido_num + OTP session for access
 *
 * POST { accion: 'buscar', pedido_num }           → Find credit info
 * POST { accion: 'historial', pedido_num }         → Payment history
 * POST { accion: 'pagar', pedido_num, semana_num } → Manual payment via Stripe
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';
session_start();

$pdo = getDB();

$json      = json_decode(file_get_contents('php://input'), true) ?? [];
$accion    = $json['accion'] ?? '';
$pedidoNum = trim($json['pedido_num'] ?? '');

if (!$pedidoNum) {
    echo json_encode(['ok' => false, 'error' => 'Número de pedido requerido']);
    exit;
}

// ── BUSCAR — get credit info ─────────────────────────────────────────────────
if ($accion === 'buscar') {
    // Find in pagos_credito
    $stmt = $pdo->prepare("SELECT * FROM pagos_credito WHERE pedido_num = ? OR pedido_num = ? LIMIT 1");
    $stmt->execute([$pedidoNum, 'VK-' . $pedidoNum]);
    $credito = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$credito) {
        // Try to build from transacciones + inventario_motos
        $stmt2 = $pdo->prepare("
            SELECT m.id AS moto_id, m.modelo, m.color, m.cliente_nombre, m.cliente_email,
                   m.cliente_telefono, m.pedido_num, m.precio_venta,
                   t.tpago, t.total AS enganche_pagado
            FROM inventario_motos m
            LEFT JOIN transacciones t ON t.pedido = REPLACE(m.pedido_num, 'VK-', '')
            WHERE (m.pedido_num = ? OR m.pedido_num = ?) AND m.activo = 1
            LIMIT 1
        ");
        $stmt2->execute([$pedidoNum, 'VK-' . $pedidoNum]);
        $moto = $stmt2->fetch(PDO::FETCH_ASSOC);

        if (!$moto) {
            echo json_encode(['ok' => false, 'error' => 'No se encontró un crédito con ese número de pedido']);
            exit;
        }

        // Only credit payments have a credit portal
        if ($moto['tpago'] !== 'enganche' && $moto['tpago'] !== 'credito') {
            echo json_encode(['ok' => false, 'error' => 'Este pedido no es un crédito. Solo clientes con crédito pueden acceder a este portal.']);
            exit;
        }

        // Auto-create credit record
        $precioTotal    = floatval($moto['precio_venta'] ?: 48260);
        $enganche       = floatval($moto['enganche_pagado'] ?: ($precioTotal * 0.30));
        $montoFinanciado = $precioTotal - $enganche;
        $plazoMeses     = 12;
        $semanas        = round($plazoMeses * 4.33);
        $pagoSemanal    = round($montoFinanciado / $semanas, 2);

        $pdo->prepare("
            INSERT INTO pagos_credito
                (moto_id, cliente_nombre, cliente_email, cliente_telefono, pedido_num,
                 modelo, color, precio_total, enganche, monto_financiado,
                 plazo_meses, pago_semanal, semanas_total, monto_restante, proximo_pago)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(CURDATE(), INTERVAL 7 DAY))
        ")->execute([
            $moto['moto_id'], $moto['cliente_nombre'], $moto['cliente_email'],
            $moto['cliente_telefono'], $moto['pedido_num'],
            $moto['modelo'], $moto['color'],
            $precioTotal, $enganche, $montoFinanciado,
            $plazoMeses, $pagoSemanal, $semanas, $montoFinanciado
        ]);

        $creditoId = $pdo->lastInsertId();

        // Generate weekly payment schedule
        for ($i = 1; $i <= $semanas; $i++) {
            $pdo->prepare("
                INSERT INTO pagos_credito_historial (credito_id, semana_num, monto, estado, fecha_programada)
                VALUES (?, ?, ?, 'pendiente', DATE_ADD(CURDATE(), INTERVAL ? DAY))
            ")->execute([$creditoId, $i, $pagoSemanal, $i * 7]);
        }

        // Re-fetch
        $stmt = $pdo->prepare("SELECT * FROM pagos_credito WHERE id = ?");
        $stmt->execute([$creditoId]);
        $credito = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'ok' => true,
        'credito' => [
            'id'               => (int)$credito['id'],
            'cliente_nombre'   => $credito['cliente_nombre'],
            'modelo'           => $credito['modelo'],
            'color'            => $credito['color'],
            'pedido_num'       => $credito['pedido_num'],
            'precio_total'     => floatval($credito['precio_total']),
            'enganche'         => floatval($credito['enganche']),
            'monto_financiado' => floatval($credito['monto_financiado']),
            'plazo_meses'      => (int)$credito['plazo_meses'],
            'pago_semanal'     => floatval($credito['pago_semanal']),
            'semanas_total'    => (int)$credito['semanas_total'],
            'semanas_pagadas'  => (int)$credito['semanas_pagadas'],
            'monto_pagado'     => floatval($credito['monto_pagado']),
            'monto_restante'   => floatval($credito['monto_restante']),
            'estado_credito'   => $credito['estado_credito'],
            'proximo_pago'     => $credito['proximo_pago'],
            'ultimo_pago'      => $credito['ultimo_pago'],
        ],
    ]);
    exit;
}

// ── HISTORIAL — payment history ──────────────────────────────────────────────
if ($accion === 'historial') {
    $stmt = $pdo->prepare("SELECT id FROM pagos_credito WHERE pedido_num = ? OR pedido_num = ? LIMIT 1");
    $stmt->execute([$pedidoNum, 'VK-' . $pedidoNum]);
    $credito = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$credito) {
        echo json_encode(['ok' => false, 'error' => 'Crédito no encontrado']);
        exit;
    }

    $stmt2 = $pdo->prepare("
        SELECT semana_num, monto, estado, metodo, fecha_programada, fecha_pago
        FROM pagos_credito_historial
        WHERE credito_id = ?
        ORDER BY semana_num ASC
    ");
    $stmt2->execute([$credito['id']]);

    echo json_encode(['ok' => true, 'historial' => $stmt2->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ── PAGAR — manual payment via Stripe ────────────────────────────────────────
if ($accion === 'pagar') {
    $stmt = $pdo->prepare("SELECT * FROM pagos_credito WHERE pedido_num = ? OR pedido_num = ? LIMIT 1");
    $stmt->execute([$pedidoNum, 'VK-' . $pedidoNum]);
    $credito = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$credito) {
        echo json_encode(['ok' => false, 'error' => 'Crédito no encontrado']);
        exit;
    }

    $monto = floatval($credito['pago_semanal']);
    if ($monto <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Monto de pago inválido']);
        exit;
    }

    // Create Stripe PaymentIntent for the weekly amount
    if (!STRIPE_SECRET_KEY) {
        echo json_encode(['ok' => false, 'error' => 'Stripe no configurado']);
        exit;
    }

    $stripePhpPath = __DIR__ . '/vendor/stripe/stripe-php/init.php';
    if (!file_exists($stripePhpPath)) {
        echo json_encode(['ok' => false, 'error' => 'Stripe SDK no encontrado']);
        exit;
    }
    require_once $stripePhpPath;
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

    try {
        $pi = \Stripe\PaymentIntent::create([
            'amount'   => intval($monto * 100),
            'currency' => 'mxn',
            'metadata' => [
                'credito_id' => $credito['id'],
                'pedido_num' => $credito['pedido_num'],
                'tipo'       => 'pago_semanal_manual',
            ],
        ]);

        echo json_encode([
            'ok' => true,
            'client_secret' => $pi->client_secret,
            'payment_intent_id' => $pi->id,
            'monto' => $monto,
        ]);
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => 'Error Stripe: ' . $e->getMessage()]);
    }
    exit;
}

// ── CONFIRMAR_PAGO — after Stripe payment confirmed ──────────────────────────
if ($accion === 'confirmar_pago') {
    $stripePi = trim($json['payment_intent_id'] ?? '');

    $stmt = $pdo->prepare("SELECT * FROM pagos_credito WHERE pedido_num = ? OR pedido_num = ? LIMIT 1");
    $stmt->execute([$pedidoNum, 'VK-' . $pedidoNum]);
    $credito = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$credito) {
        echo json_encode(['ok' => false, 'error' => 'Crédito no encontrado']);
        exit;
    }

    // Find next pending week
    $stmt2 = $pdo->prepare("
        SELECT id, semana_num FROM pagos_credito_historial
        WHERE credito_id = ? AND estado = 'pendiente'
        ORDER BY semana_num ASC LIMIT 1
    ");
    $stmt2->execute([$credito['id']]);
    $nextWeek = $stmt2->fetch(PDO::FETCH_ASSOC);

    if ($nextWeek) {
        $pdo->prepare("
            UPDATE pagos_credito_historial
            SET estado = 'pagado', metodo = 'tarjeta_manual', stripe_pi = ?, fecha_pago = NOW()
            WHERE id = ?
        ")->execute([$stripePi, $nextWeek['id']]);
    }

    // Update credit totals
    $pdo->prepare("
        UPDATE pagos_credito
        SET semanas_pagadas = semanas_pagadas + 1,
            monto_pagado = monto_pagado + pago_semanal,
            monto_restante = monto_restante - pago_semanal,
            ultimo_pago = NOW(),
            proximo_pago = DATE_ADD(proximo_pago, INTERVAL 7 DAY),
            estado_credito = IF(monto_restante - pago_semanal <= 0, 'completado', estado_credito)
        WHERE id = ?
    ")->execute([$credito['id']]);

    echo json_encode(['ok' => true, 'message' => 'Pago registrado exitosamente']);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Acción no reconocida']);
