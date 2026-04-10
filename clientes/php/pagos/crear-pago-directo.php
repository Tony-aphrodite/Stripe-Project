<?php
/**
 * Voltika Portal - Direct weekly payment
 * Charges the saved card (off_session) for one or more pending cycles.
 * Uses Stripe PHP SDK via configurador/vendor if available; otherwise raw cURL.
 */
require_once __DIR__ . '/../bootstrap.php';
$cid = portalRequireAuth();

$in = portalJsonIn();
$tipo = $in['tipo'] ?? 'semanal'; // semanal | dos_semanas | adelanto | monto_custom
$montoCustom = (float)($in['monto'] ?? 0);

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM subscripciones_credito
    WHERE cliente_id = ? AND (estado IS NULL OR estado NOT IN ('cancelada','liquidada'))
    ORDER BY id DESC LIMIT 1");
$stmt->execute([$cid]);
$sub = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sub) portalJsonOut(['error' => 'No tienes una cuenta activa'], 404);

portalEnsureCiclos($sub);

// Lock cycles to prevent duplicate payments (race condition protection)
$pdo->beginTransaction();
$stmt = $pdo->prepare("SELECT * FROM ciclos_pago
    WHERE subscripcion_id = ? AND estado IN ('pending','overdue')
    ORDER BY semana_num ASC
    FOR UPDATE");
$stmt->execute([$sub['id']]);
$pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($pendientes)) {
    $pdo->rollBack();
    portalJsonOut(['error' => 'No hay pagos pendientes'], 400);
}

$numCiclos = match ($tipo) {
    'dos_semanas' => 2,
    'adelanto'    => max(1, (int)($in['num_semanas'] ?? 4)),
    default       => 1,
};

// Adelanto: discount from the LAST pending cycles (shortens the plan)
// Normal/dos_semanas: pay the NEXT pending cycles
if ($tipo === 'adelanto') {
    $aPagar = array_slice($pendientes, -$numCiclos);
} else {
    $aPagar = array_slice($pendientes, 0, $numCiclos);
}

$monto = 0;
foreach ($aPagar as $c) $monto += (float)$c['monto'];
if ($montoCustom > 0) $monto = $montoCustom;

$amountCents = (int)round($monto * 100);
if ($amountCents <= 0) {
    $pdo->rollBack();
    portalJsonOut(['error' => 'Monto inválido'], 400);
}

// ── Stripe PaymentIntent (off_session, confirm immediately) ─────────────────
$customer = $sub['stripe_customer_id'] ?? '';
$pm       = $sub['stripe_payment_method_id'] ?? '';
if (!$customer || !$pm) {
    $pdo->rollBack();
    portalJsonOut(['error' => 'Método de pago no configurado'], 400);
}

// Idempotency key prevents duplicate charges on double-click / retry
$idempotencyKey = 'voltika_' . $sub['id'] . '_' . implode('_', array_column($aPagar, 'semana_num')) . '_' . date('Ymd');

$postFields = http_build_query([
    'amount' => $amountCents,
    'currency' => 'mxn',
    'customer' => $customer,
    'payment_method' => $pm,
    'off_session' => 'true',
    'confirm' => 'true',
    'description' => 'Voltika pago semanal cliente #' . $cid,
    'metadata[cliente_id]' => $cid,
    'metadata[subscripcion_id]' => $sub['id'],
    'metadata[tipo]' => $tipo,
    'metadata[ciclos]' => implode(',', array_column($aPagar, 'semana_num')),
]);

$ch = curl_init('https://api.stripe.com/v1/payment_intents');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . STRIPE_SECRET_KEY,
        'Content-Type: application/x-www-form-urlencoded',
        'Idempotency-Key: ' . $idempotencyKey,
    ],
    CURLOPT_TIMEOUT => 30,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($resp, true) ?: [];
$logFile = __DIR__ . '/../../../configurador/php/logs/portal-pagos.log';
@file_put_contents($logFile, json_encode([
    'ts' => date('c'), 'cliente' => $cid, 'sub' => $sub['id'], 'tipo' => $tipo,
    'amount' => $amountCents, 'httpCode' => $code, 'id' => $data['id'] ?? null,
    'status' => $data['status'] ?? null, 'error' => $data['error']['message'] ?? null,
]) . "\n", FILE_APPEND | LOCK_EX);

if ($code >= 200 && $code < 300 && ($data['status'] ?? '') === 'succeeded') {
    // Mark cycles paid_manual
    $upd = $pdo->prepare("UPDATE ciclos_pago SET estado = 'paid_manual', stripe_payment_intent = ?, origen = 'portal_manual'
        WHERE id = ?");
    foreach ($aPagar as $c) $upd->execute([$data['id'], $c['id']]);
    $pdo->commit();

    // Insert transaccion row (best-effort, tolerant of schema differences)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS transacciones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cliente_id INT NULL,
            subscripcion_id INT NULL,
            stripe_payment_intent VARCHAR(100) NULL,
            monto DECIMAL(10,2) NULL,
            moneda VARCHAR(5) DEFAULT 'MXN',
            estado VARCHAR(30) NULL,
            origen VARCHAR(30) NULL,
            freg DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->prepare("INSERT INTO transacciones (cliente_id, subscripcion_id, stripe_payment_intent, monto, estado, origen)
            VALUES (?, ?, ?, ?, 'succeeded', 'portal_manual')")
            ->execute([$cid, $sub['id'], $data['id'], $monto]);
    } catch (Throwable $e) { error_log($e->getMessage()); }

    portalLog('payment_ok', ['success' => 1, 'detalle' => 'pi=' . ($data['id'] ?? '') . ' $' . $monto]);
    portalJsonOut([
        'status' => 'ok',
        'payment_intent' => $data['id'],
        'monto' => $monto,
        'ciclos_pagados' => array_column($aPagar, 'semana_num'),
    ]);
}

// Failure — release locked rows
$pdo->rollBack();
$err = $data['error']['message'] ?? 'No se pudo procesar el pago';
portalLog('payment_fail', ['success' => 0, 'detalle' => $err]);
portalJsonOut(['error' => $err, 'stripe' => $data['error'] ?? null], 402);
