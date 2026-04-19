<?php
/**
 * POST — Re-sync a single transaccion's pago_estado with Stripe's real status.
 *
 * Body: { transaccion_id: int }
 *
 * Reads the authoritative status from Stripe (payment_intent.status) and
 * rewrites transacciones.pago_estado if different. Also propagates to
 * inventario_motos where stripe_pi matches.
 *
 * Returns: { ok, changed, before, after, stripe_status }
 *
 * Used by:
 *   - Ventas list "🔄 Sincronizar" per-row button
 *   - Ventas detail modal auto-verify on open (when pago_estado != 'pagada')
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

// Load Stripe secret
$cfgCandidates = [
    __DIR__ . '/../../../configurador_prueba/php/config.php',
    __DIR__ . '/../../../configurador_prueba_test/php/config.php',
];
foreach ($cfgCandidates as $p) { if (file_exists($p)) { require_once $p; break; } }
if (!defined('STRIPE_SECRET_KEY') || !STRIPE_SECRET_KEY) {
    adminJsonOut(['error' => 'STRIPE_SECRET_KEY no configurada'], 500);
}

$d   = adminJsonIn();
$tid = (int)($d['transaccion_id'] ?? 0);
if (!$tid) adminJsonOut(['error' => 'transaccion_id requerido'], 400);

$pdo = getDB();

$stmt = $pdo->prepare("SELECT id, stripe_pi, pago_estado, tpago FROM transacciones WHERE id = ? LIMIT 1");
$stmt->execute([$tid]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) adminJsonOut(['error' => 'Orden no encontrada'], 404);

$pi = trim($order['stripe_pi'] ?? '');
if (!$pi) {
    adminJsonOut([
        'ok' => true,
        'changed' => false,
        'before' => $order['pago_estado'],
        'after'  => $order['pago_estado'],
        'stripe_status' => null,
        'note' => 'Sin stripe_pi — no hay con qué verificar.',
    ]);
}

// Retrieve real Stripe status
$ch = curl_init('https://api.stripe.com/v1/payment_intents/' . urlencode($pi));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . STRIPE_SECRET_KEY],
    CURLOPT_TIMEOUT        => 12,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($code !== 200) {
    adminJsonOut(['error' => 'Stripe respondió HTTP ' . $code], 502);
}
$data = json_decode($resp, true);
$stripeStatus = $data['status'] ?? null;

if (!$stripeStatus) {
    adminJsonOut(['error' => 'Respuesta inesperada de Stripe'], 502);
}

// Map Stripe status → pago_estado
$tpagoLc = strtolower(trim($order['tpago'] ?? ''));
$isCreditFam = in_array($tpagoLc, ['credito','enganche','parcial'], true);

$expected = 'pendiente';
if ($stripeStatus === 'succeeded') {
    // Credit-family orders: enganche captured means 'parcial', not full 'pagada'
    $expected = $isCreditFam ? 'parcial' : 'pagada';
} elseif ($stripeStatus === 'canceled') {
    $expected = 'fallido';
} else {
    $expected = 'pendiente';
}

$before = strtolower($order['pago_estado'] ?? '');
$changed = ($before !== $expected);

if ($changed) {
    try {
        $pdo->prepare("UPDATE transacciones SET pago_estado = ? WHERE id = ?")->execute([$expected, $tid]);
        // Propagate to inventario_motos linked by stripe_pi
        $pdo->prepare("UPDATE inventario_motos SET pago_estado = ? WHERE stripe_pi = ?")->execute([$expected, $pi]);
    } catch (Throwable $e) {
        error_log('verificar-stripe-uno update: ' . $e->getMessage());
        adminJsonOut(['error' => 'No se pudo actualizar la DB: ' . $e->getMessage()], 500);
    }

    adminLog('verificar_stripe_uno', [
        'transaccion_id' => $tid,
        'stripe_pi'      => $pi,
        'before'         => $before,
        'after'          => $expected,
        'stripe_status'  => $stripeStatus,
    ]);
}

adminJsonOut([
    'ok'            => true,
    'changed'       => $changed,
    'before'        => $before,
    'after'         => $expected,
    'stripe_status' => $stripeStatus,
]);
