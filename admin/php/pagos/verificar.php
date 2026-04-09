<?php
/**
 * POST — Verify payment status from Stripe and update local records
 * Used for MSI/contado full payment check and credito enganche check
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$motoId = (int)($d['moto_id'] ?? 0);
if (!$motoId) adminJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE id=?");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) adminJsonOut(['error' => 'Moto no encontrada'], 404);

$stripeKey = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : (getenv('STRIPE_SECRET_KEY') ?: '');
$result = ['moto_id' => $motoId, 'verificado' => false];

// Check Stripe PaymentIntent if exists
if ($moto['stripe_pi'] && $stripeKey) {
    $ch = curl_init('https://api.stripe.com/v1/payment_intents/' . $moto['stripe_pi']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $stripeKey . ':',
    ]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (($resp['status'] ?? '') === 'succeeded') {
        $pdo->prepare("UPDATE inventario_motos SET pago_estado='pagada', stripe_payment_status='succeeded',
            stripe_verified_at=NOW() WHERE id=?")->execute([$motoId]);
        $result['verificado'] = true;
        $result['stripe_status'] = 'succeeded';
        $result['monto'] = ($resp['amount'] ?? 0) / 100;
    } else {
        $result['stripe_status'] = $resp['status'] ?? 'unknown';
    }
}

// Check credit enganche if subscripcion linked
$sub = $pdo->prepare("SELECT * FROM subscripciones_credito WHERE inventario_moto_id=? LIMIT 1");
$sub->execute([$motoId]);
$subRow = $sub->fetch(PDO::FETCH_ASSOC);
if ($subRow) {
    $result['credito'] = [
        'estado' => $subRow['estado'],
        'setup_intent' => $subRow['stripe_setup_intent_id'],
        'tiene_metodo_pago' => !empty($subRow['stripe_payment_method_id']),
    ];
    // Check if enganche was paid via pagos_credito
    $eng = $pdo->prepare("SELECT enganche, monto_pagado FROM pagos_credito WHERE moto_id=? LIMIT 1");
    $eng->execute([$motoId]);
    $engRow = $eng->fetch(PDO::FETCH_ASSOC);
    if ($engRow) {
        $result['credito']['enganche'] = (float)$engRow['enganche'];
        $result['credito']['enganche_pagado'] = (float)$engRow['monto_pagado'] >= (float)$engRow['enganche'];
    }
}

adminLog('verificar_pago', $result);
adminJsonOut($result);
