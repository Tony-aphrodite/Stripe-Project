<?php
/**
 * Voltika Portal - Start SPEI (bank transfer) payment
 * Creates a Stripe PaymentIntent with customer_balance + mx_bank_transfer and
 * returns the CLABE / reference so the frontend can render instructions.
 * Cycles are NOT marked paid here — the webhook does that on
 * payment_intent.succeeded using the metadata we attach.
 */
require_once __DIR__ . '/../bootstrap.php';
$cid = portalRequireAuth();

$in          = portalJsonIn();
$tipo        = $in['tipo'] ?? 'semanal'; // semanal | dos_semanas | adelanto
$montoCustom = (float)($in['monto'] ?? 0);

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM subscripciones_credito
    WHERE cliente_id = ? AND (estado IS NULL OR estado NOT IN ('cancelada','liquidada'))
    ORDER BY id DESC LIMIT 1");
$stmt->execute([$cid]);
$sub = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sub) portalJsonOut(['error' => 'No tienes una cuenta activa'], 404);

portalEnsureCiclos($sub);

$stmt = $pdo->prepare("SELECT * FROM ciclos_pago
    WHERE subscripcion_id = ? AND estado IN ('pending','overdue')
    ORDER BY semana_num ASC");
$stmt->execute([$sub['id']]);
$pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($pendientes)) portalJsonOut(['error' => 'No hay pagos pendientes'], 400);

$numCiclos = match ($tipo) {
    'dos_semanas' => 2,
    'adelanto'    => max(1, (int)($in['num_semanas'] ?? 4)),
    default       => 1,
};
$aPagar = ($tipo === 'adelanto')
    ? array_slice($pendientes, -$numCiclos)
    : array_slice($pendientes, 0, $numCiclos);

$monto = 0;
foreach ($aPagar as $c) $monto += (float)$c['monto'];
if ($montoCustom > 0) $monto = $montoCustom;

$amountCents = (int)round($monto * 100);
if ($amountCents <= 0) portalJsonOut(['error' => 'Monto inválido'], 400);

// ── Ensure Stripe customer exists (SPEI requires one) ───────────────────────
$customer = $sub['stripe_customer_id'] ?? '';
if (!$customer) {
    $clienteRow = $pdo->prepare("SELECT nombre, email, telefono FROM clientes WHERE id = ?");
    $clienteRow->execute([$cid]);
    $cli = $clienteRow->fetch(PDO::FETCH_ASSOC) ?: [];

    $ch = curl_init('https://api.stripe.com/v1/customers');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'name'  => $cli['nombre']   ?: 'Cliente Voltika',
            'email' => $cli['email']    ?: ('cliente+' . $cid . '@voltika.mx'),
            'phone' => $cli['telefono'] ?: '',
            'metadata[cliente_id]' => $cid,
        ]),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . STRIPE_SECRET_KEY,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch); curl_close($ch);
    $cust = json_decode($resp, true) ?: [];
    if (empty($cust['id'])) portalJsonOut(['error' => 'No se pudo registrar el cliente en Stripe'], 500);
    $customer = $cust['id'];
    try { $pdo->prepare("UPDATE subscripciones_credito SET stripe_customer_id = ? WHERE id = ?")
             ->execute([$customer, $sub['id']]); } catch (Throwable $e) {}
}

// ── Create PaymentIntent (SPEI / customer_balance) ──────────────────────────
$ciclosCsv = implode(',', array_column($aPagar, 'semana_num'));
$idempotencyKey = 'voltika_spei_' . $sub['id'] . '_' . $ciclosCsv . '_' . date('Ymd');

$postFields = http_build_query([
    'amount'   => $amountCents,
    'currency' => 'mxn',
    'customer' => $customer,
    'confirm'  => 'true',
    'payment_method_types[]' => 'customer_balance',
    'payment_method_data[type]' => 'customer_balance',
    'payment_method_options[customer_balance][funding_type]' => 'bank_transfer',
    'payment_method_options[customer_balance][bank_transfer][type]' => 'mx_bank_transfer',
    'description' => 'Voltika SPEI cliente #' . $cid,
    'metadata[cliente_id]'     => $cid,
    'metadata[subscripcion_id]' => $sub['id'],
    'metadata[tipo]'    => $tipo,
    'metadata[ciclos]'  => $ciclosCsv,
    'metadata[origen]'  => 'portal_spei',
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

$logFile = __DIR__ . '/../../../configurador_prueba_test/php/logs/portal-pagos.log';
@file_put_contents($logFile, json_encode([
    'ts' => date('c'), 'cliente' => $cid, 'sub' => $sub['id'], 'kind' => 'spei',
    'amount' => $amountCents, 'httpCode' => $code, 'id' => $data['id'] ?? null,
    'status' => $data['status'] ?? null, 'error' => $data['error']['message'] ?? null,
]) . "\n", FILE_APPEND | LOCK_EX);

if ($code < 200 || $code >= 300) {
    $err = $data['error']['message'] ?? 'No se pudo generar la referencia SPEI';
    portalLog('spei_fail', ['success' => 0, 'detalle' => $err]);
    portalJsonOut(['error' => $err, 'stripe' => $data['error'] ?? null], 402);
}

// ── Extract CLABE + reference ───────────────────────────────────────────────
$clabe = '';
$reference = '';
$next = $data['next_action'] ?? [];
$inst = $next['display_bank_transfer_instructions'] ?? null;
if ($inst) {
    $reference = $inst['reference'] ?? '';
    foreach (($inst['financial_addresses'] ?? []) as $addr) {
        if (!empty($addr['spei']['clabe']))       { $clabe = $addr['spei']['clabe']; break; }
        if (!empty($addr['spei_clabe']['clabe'])) { $clabe = $addr['spei_clabe']['clabe']; break; }
        if (!empty($addr['clabe']))               { $clabe = $addr['clabe']; break; }
    }
    // Fallback: recursively scan for an 18-digit numeric string
    if (!$clabe && !empty($inst['financial_addresses'])) {
        array_walk_recursive($inst['financial_addresses'], function($v) use (&$clabe) {
            if (!$clabe && is_string($v) && preg_match('/^\d{18}$/', $v)) $clabe = $v;
        });
    }
}

// ── Persist pending transaccion row (webhook will mark succeeded) ───────────
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
        VALUES (?, ?, ?, ?, 'pending', 'portal_spei')")
        ->execute([$cid, $sub['id'], $data['id'], $monto]);
} catch (Throwable $e) { error_log('spei transacciones: ' . $e->getMessage()); }

portalLog('spei_ok', ['success' => 1, 'detalle' => 'pi=' . ($data['id'] ?? '') . ' $' . $monto]);
portalJsonOut([
    'status'         => 'ok',
    'payment_intent' => $data['id'] ?? '',
    'monto'          => $monto,
    'clabe'          => $clabe,
    'banco'          => 'STP',
    'beneficiario'   => 'MTECH GEARS S.A. DE C.V.',
    'referencia'     => $reference,
    'expires_at'     => $inst['hosted_instructions_url'] ?? null,
    'ciclos'         => array_column($aPagar, 'semana_num'),
    'tipo'           => $tipo,
]);
