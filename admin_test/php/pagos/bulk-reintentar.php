<?php
/**
 * POST — Bulk retry failed charges for multiple payment cycles
 * Body: { "ciclo_ids": [1, 2, 3, ...] }
 * Processes each cycle: creates PaymentIntent via Stripe, updates on success.
 * Max 100 cycles per request.
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cobranza']);

$d = adminJsonIn();
$cicloIds = $d['ciclo_ids'] ?? [];

if (!is_array($cicloIds) || empty($cicloIds)) {
    adminJsonOut(['error' => 'ciclo_ids requerido (array)'], 400);
}
if (count($cicloIds) > 100) {
    adminJsonOut(['error' => 'Maximo 100 ciclos por solicitud'], 400);
}

// Sanitize IDs
$cicloIds = array_map('intval', $cicloIds);
$cicloIds = array_filter($cicloIds, fn($id) => $id > 0);
if (empty($cicloIds)) {
    adminJsonOut(['error' => 'No se proporcionaron ciclo_ids validos'], 400);
}

$stripeKey = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : (getenv('STRIPE_SECRET_KEY') ?: '');
if (!$stripeKey) adminJsonOut(['error' => 'Stripe no configurado'], 500);

$pdo = getDB();

// Fetch all requested cycles with subscription data
$placeholders = implode(',', array_fill(0, count($cicloIds), '?'));
$stmt = $pdo->prepare("
    SELECT c.*, s.stripe_customer_id, s.stripe_payment_method_id,
           COALESCE(s.nombre, cl.nombre, '') as nombre
    FROM ciclos_pago c
    LEFT JOIN subscripciones_credito s ON c.subscripcion_id = s.id
    LEFT JOIN clientes cl ON c.cliente_id = cl.id
    WHERE c.id IN ($placeholders)
");
$stmt->execute($cicloIds);
$ciclos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$succeeded = 0;
$failed    = 0;
$details   = [];

foreach ($ciclos as $ciclo) {
    $cicloId = (int)$ciclo['id'];

    // Skip already paid
    if (in_array($ciclo['estado'], ['paid_auto', 'paid_manual'])) {
        $details[] = ['ciclo_id' => $cicloId, 'status' => 'skipped', 'reason' => 'Ya pagado'];
        continue;
    }

    // Skip if no payment method
    if (empty($ciclo['stripe_customer_id']) || empty($ciclo['stripe_payment_method_id'])) {
        $details[] = ['ciclo_id' => $cicloId, 'status' => 'skipped', 'reason' => 'Sin metodo de pago'];
        $failed++;
        continue;
    }

    // Create PaymentIntent
    $amount = (int)(round($ciclo['monto'] * 100));
    $ch = curl_init('https://api.stripe.com/v1/payment_intents');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $stripeKey . ':',
        CURLOPT_POSTFIELDS     => http_build_query([
            'amount'               => $amount,
            'currency'             => 'mxn',
            'customer'             => $ciclo['stripe_customer_id'],
            'payment_method'       => $ciclo['stripe_payment_method_id'],
            'off_session'          => 'true',
            'confirm'              => 'true',
            'description'          => 'Voltika bulk reintento ciclo #' . $ciclo['semana_num'] . ' - ' . ($ciclo['nombre'] ?? ''),
            'metadata[ciclo_id]'   => $cicloId,
            'metadata[tipo]'       => 'bulk_reintento_admin',
        ]),
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $result = json_decode($raw, true);

    if (($result['status'] ?? '') === 'succeeded') {
        $pdo->prepare("UPDATE ciclos_pago SET estado='paid_auto', stripe_payment_intent=?, fecha_pago=NOW() WHERE id=?")
            ->execute([$result['id'], $cicloId]);

        adminLog('bulk_reintentar_pago', [
            'ciclo_id'  => $cicloId,
            'stripe_pi' => $result['id'],
            'monto'     => $ciclo['monto'],
        ]);

        $succeeded++;
        $details[] = ['ciclo_id' => $cicloId, 'status' => 'succeeded', 'stripe_pi' => $result['id']];
    } else {
        $errorMsg = $result['error']['message'] ?? ($result['last_payment_error']['message'] ?? 'Error desconocido');
        adminLog('bulk_reintentar_pago_error', [
            'ciclo_id' => $cicloId,
            'error'    => $errorMsg,
        ]);

        $failed++;
        $details[] = ['ciclo_id' => $cicloId, 'status' => 'failed', 'error' => $errorMsg];
    }
}

adminLog('bulk_reintentar_resumen', [
    'total'     => count($cicloIds),
    'succeeded' => $succeeded,
    'failed'    => $failed,
]);

adminJsonOut([
    'ok'        => true,
    'total'     => count($cicloIds),
    'succeeded' => $succeeded,
    'failed'    => $failed,
    'details'   => $details,
]);
