<?php
/**
 * Voltika Admin - Verificar estado de pago con Stripe
 *
 * GET  ?moto_id=N         → check payment status for a moto
 * POST { moto_id }        → force re-check with Stripe API
 * POST { moto_id, stripe_pi } → manually link a Stripe PaymentIntent to a moto
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-auth.php';

$dealer = requireDealerAuth();
$pdo = getDB();

// ── GET: return current payment status ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $motoId = intval($_GET['moto_id'] ?? 0);
    if (!$motoId) { echo json_encode(['ok' => false, 'error' => 'moto_id requerido']); exit; }

    try {
        // Try with all columns
        try {
            $stmt = $pdo->prepare("SELECT stripe_pi, stripe_payment_status, stripe_verified_at, pago_estado, transaccion_id, pedido_num FROM inventario_motos WHERE id = ?");
            $stmt->execute([$motoId]);
            $moto = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $stmt = $pdo->prepare("SELECT pago_estado, pedido_num FROM inventario_motos WHERE id = ?");
            $stmt->execute([$motoId]);
            $moto = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($moto) {
                $moto['stripe_pi'] = null;
                $moto['stripe_payment_status'] = 'unknown';
                $moto['stripe_verified_at'] = null;
                $moto['transaccion_id'] = null;
            }
        }

        if (!$moto) { echo json_encode(['ok' => false, 'error' => 'Moto no encontrada']); exit; }

        // If we have a stripe_pi but status is not confirmed, try Stripe API
        if (!empty($moto['stripe_pi']) && ($moto['stripe_payment_status'] ?? '') !== 'succeeded') {
            $result = verifyStripePayment($moto['stripe_pi']);
            if ($result) {
                try {
                    $pdo->prepare("UPDATE inventario_motos SET stripe_payment_status = ?, stripe_verified_at = NOW() WHERE id = ?")
                        ->execute([$result['status'], $motoId]);
                } catch (PDOException $e) { /* column may not exist */ }
                $moto['stripe_payment_status'] = $result['status'];

                if ($result['status'] === 'succeeded' && $moto['pago_estado'] !== 'pagada') {
                    $pdo->prepare("UPDATE inventario_motos SET pago_estado = 'pagada' WHERE id = ?")->execute([$motoId]);
                    $moto['pago_estado'] = 'pagada';
                }
            }
        }

        // If no stripe_pi, try to find from transacciones table
        if (empty($moto['stripe_pi']) && empty($moto['transaccion_id'])) {
            try {
                $pedido = preg_replace('/^VK-/', '', $moto['pedido_num'] ?? '');
                if ($pedido) {
                    $stmt2 = $pdo->prepare("SELECT id, stripe_pi, total FROM transacciones WHERE pedido = ? OR pedido = ? LIMIT 1");
                    $stmt2->execute([$pedido, 'VK-' . $pedido]);
                    $tx = $stmt2->fetch(PDO::FETCH_ASSOC);

                    if ($tx && !empty($tx['stripe_pi'])) {
                        try {
                            $pdo->prepare("UPDATE inventario_motos SET stripe_pi = ?, transaccion_id = ? WHERE id = ?")
                                ->execute([$tx['stripe_pi'], $tx['id'], $motoId]);
                        } catch (PDOException $e) { /* columns may not exist */ }
                        $moto['stripe_pi'] = $tx['stripe_pi'];
                        $moto['transaccion_id'] = $tx['id'];

                        $result = verifyStripePayment($tx['stripe_pi']);
                        if ($result) {
                            try {
                                $pdo->prepare("UPDATE inventario_motos SET stripe_payment_status = ?, stripe_verified_at = NOW() WHERE id = ?")
                                    ->execute([$result['status'], $motoId]);
                            } catch (PDOException $e) {}
                            $moto['stripe_payment_status'] = $result['status'];
                            if ($result['status'] === 'succeeded') {
                                $pdo->prepare("UPDATE inventario_motos SET pago_estado = 'pagada' WHERE id = ?")->execute([$motoId]);
                                $moto['pago_estado'] = 'pagada';
                            }
                        }
                    }
                }
            } catch (PDOException $e) { /* transacciones table may not exist */ }
        }

        echo json_encode([
            'ok' => true,
            'pago' => [
                'stripe_pi'             => $moto['stripe_pi'] ?? null,
                'stripe_payment_status' => $moto['stripe_payment_status'] ?? 'unknown',
                'stripe_verified_at'    => $moto['stripe_verified_at'] ?? null,
                'pago_estado'           => $moto['pago_estado'] ?? 'unknown',
                'transaccion_id'        => $moto['transaccion_id'] ?? null,
                'puede_entregar'        => (($moto['pago_estado'] ?? '') === 'pagada' || ($moto['stripe_payment_status'] ?? '') === 'succeeded'),
            ],
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// ── POST: force re-check or manual link ──────────────────────────────────────
$json   = json_decode(file_get_contents('php://input'), true) ?? [];
$motoId = intval($json['moto_id'] ?? 0);

if (!$motoId) {
    echo json_encode(['ok' => false, 'error' => 'moto_id requerido']);
    exit;
}

// Manual link: assign a stripe_pi to a moto
if (!empty($json['stripe_pi'])) {
    $stripePi = trim($json['stripe_pi']);
    $result = verifyStripePayment($stripePi);
    $status = $result ? $result['status'] : 'unknown';

    $pdo->prepare("UPDATE inventario_motos SET stripe_pi = ?, stripe_payment_status = ?, stripe_verified_at = NOW() WHERE id = ?")
        ->execute([$stripePi, $status, $motoId]);

    if ($status === 'succeeded') {
        $pdo->prepare("UPDATE inventario_motos SET pago_estado = 'pagada' WHERE id = ?")->execute([$motoId]);
    }

    echo json_encode([
        'ok' => true,
        'stripe_pi' => $stripePi,
        'status' => $status,
        'message' => $status === 'succeeded' ? 'Pago confirmado en Stripe' : 'Estado: ' . $status,
    ]);
    exit;
}

// Force re-check
$stmt = $pdo->prepare("SELECT stripe_pi FROM inventario_motos WHERE id = ?");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$moto || !$moto['stripe_pi']) {
    echo json_encode(['ok' => false, 'error' => 'No hay PaymentIntent vinculado a esta moto']);
    exit;
}

$result = verifyStripePayment($moto['stripe_pi']);
if ($result) {
    $pdo->prepare("UPDATE inventario_motos SET stripe_payment_status = ?, stripe_verified_at = NOW() WHERE id = ?")
        ->execute([$result['status'], $motoId]);

    if ($result['status'] === 'succeeded') {
        $pdo->prepare("UPDATE inventario_motos SET pago_estado = 'pagada' WHERE id = ?")->execute([$motoId]);
    }

    echo json_encode(['ok' => true, 'status' => $result['status'], 'amount' => $result['amount'] ?? null]);
} else {
    echo json_encode(['ok' => false, 'error' => 'No se pudo verificar con Stripe']);
}

// ═════════════════════════════════════════════════════════════════════════════
// Stripe API helper
// ═════════════════════════════════════════════════════════════════════════════
function verifyStripePayment($paymentIntentId) {
    if (!$paymentIntentId || !STRIPE_SECRET_KEY || STRIPE_SECRET_KEY === '') {
        return null;
    }

    $ch = curl_init('https://api.stripe.com/v1/payment_intents/' . urlencode($paymentIntentId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . STRIPE_SECRET_KEY],
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return null;

    $data = json_decode($response, true);
    if (!$data || !isset($data['status'])) return null;

    return [
        'status'   => $data['status'],  // succeeded, requires_payment_method, processing, etc.
        'amount'   => ($data['amount'] ?? 0) / 100,
        'currency' => $data['currency'] ?? 'mxn',
    ];
}
