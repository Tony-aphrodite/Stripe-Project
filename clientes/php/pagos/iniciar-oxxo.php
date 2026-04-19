<?php
/**
 * Voltika Portal - Start OXXO (cash voucher) payment
 * Creates a Stripe PaymentIntent with payment_method_types=oxxo, returns the
 * voucher URL + reference number + expiration. Cycles are NOT marked paid
 * here — the webhook does that on payment_intent.succeeded.
 */
require_once __DIR__ . '/../bootstrap.php';
$cid = portalRequireAuth();

$in          = portalJsonIn();
$tipo        = $in['tipo'] ?? 'semanal';
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
if ($amountCents <= 0)   portalJsonOut(['error' => 'Monto inválido'], 400);
// OXXO hard cap ~$10,000 MXN per reference; portal weekly amounts are tiny,
// but guard anyway — prompt user to split into multiple weeks.
if ($amountCents > 999900) portalJsonOut(['error' => 'OXXO no admite un solo pago mayor a $9,999. Divídelo en varias semanas.'], 400);

// ── Resolve cliente name/email for OXXO billing_details (required) ──────────
// Probe columns first — some deployments lack apellido_paterno/materno, and a
// raw SELECT on a missing column would throw PDOException → HTTP 500.
$colSet = [];
try {
    $cols = $pdo->query("SHOW COLUMNS FROM clientes")->fetchAll(PDO::FETCH_COLUMN);
    $colSet = array_flip($cols);
} catch (Throwable $e) {}
$select = ['nombre', 'email', 'telefono'];
if (isset($colSet['apellido_paterno'])) $select[] = 'apellido_paterno';
if (isset($colSet['apellido_materno'])) $select[] = 'apellido_materno';

$cliRow = $pdo->prepare("SELECT " . implode(',', $select) . " FROM clientes WHERE id = ?");
$cliRow->execute([$cid]);
$cli = $cliRow->fetch(PDO::FETCH_ASSOC) ?: [];

$fullName = trim(implode(' ', array_filter([
    $cli['nombre']           ?? '',
    $cli['apellido_paterno'] ?? '',
    $cli['apellido_materno'] ?? '',
], 'strlen')));
// OXXO requires ≥ 4 chars and a space (first + last). Always provide a valid fallback.
if (strlen($fullName) < 4 || strpos($fullName, ' ') === false) {
    $fullName = 'Cliente Voltika';
}
$billingEmail = $cli['email'] ?: ('cliente+' . $cid . '@voltika.mx');

// ── Ensure Stripe customer exists ───────────────────────────────────────────
$customer = $sub['stripe_customer_id'] ?? '';
if (!$customer) {
    $ch = curl_init('https://api.stripe.com/v1/customers');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'name'  => $fullName,
            'email' => $billingEmail,
            'phone' => $cli['telefono'] ?? '',
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

// ── Create PaymentIntent (OXXO, confirm inline with payment_method_data) ────
$ciclosCsv = implode(',', array_column($aPagar, 'semana_num'));
$idempotencyKey = 'voltika_oxxo_' . $sub['id'] . '_' . $ciclosCsv . '_' . date('Ymd');

$postFields = http_build_query([
    'amount'   => $amountCents,
    'currency' => 'mxn',
    'customer' => $customer,
    'confirm'  => 'true',
    'payment_method_types[]' => 'oxxo',
    'payment_method_data[type]' => 'oxxo',
    'payment_method_data[billing_details][name]'  => $fullName,
    'payment_method_data[billing_details][email]' => $billingEmail,
    'description' => 'Voltika OXXO cliente #' . $cid,
    'metadata[cliente_id]'      => $cid,
    'metadata[subscripcion_id]' => $sub['id'],
    'metadata[tipo]'    => $tipo,
    'metadata[ciclos]'  => $ciclosCsv,
    'metadata[origen]'  => 'portal_oxxo',
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
    'ts' => date('c'), 'cliente' => $cid, 'sub' => $sub['id'], 'kind' => 'oxxo',
    'amount' => $amountCents, 'httpCode' => $code, 'id' => $data['id'] ?? null,
    'status' => $data['status'] ?? null, 'error' => $data['error']['message'] ?? null,
]) . "\n", FILE_APPEND | LOCK_EX);

if ($code < 200 || $code >= 300) {
    $err = $data['error']['message'] ?? 'No se pudo generar la referencia OXXO';
    portalLog('oxxo_fail', ['success' => 0, 'detalle' => $err]);
    portalJsonOut(['error' => $err, 'stripe' => $data['error'] ?? null], 402);
}

// ── Extract voucher details ─────────────────────────────────────────────────
$voucherUrl = '';
$reference  = '';
$expiresAt  = 0;
$next = $data['next_action'] ?? [];
$oxxo = $next['oxxo_display_details'] ?? null;
if ($oxxo) {
    $reference  = $oxxo['number'] ?? '';
    $expiresAt  = (int)($oxxo['expires_after'] ?? 0);
    $voucherUrl = $oxxo['hosted_voucher_url'] ?? '';
}

// ── Persist pending transaccion row (webhook marks succeeded) ───────────────
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
        VALUES (?, ?, ?, ?, 'pending', 'portal_oxxo')")
        ->execute([$cid, $sub['id'], $data['id'], $monto]);
} catch (Throwable $e) { error_log('oxxo transacciones: ' . $e->getMessage()); }

portalLog('oxxo_ok', ['success' => 1, 'detalle' => 'pi=' . ($data['id'] ?? '') . ' $' . $monto]);
portalJsonOut([
    'status'         => 'ok',
    'payment_intent' => $data['id'] ?? '',
    'monto'          => $monto,
    'referencia'     => $reference,
    'voucher_url'    => $voucherUrl,
    'expires_at'     => $expiresAt,
    'ciclos'         => array_column($aPagar, 'semana_num'),
    'tipo'           => $tipo,
]);
