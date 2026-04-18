<?php
/**
 * Cron — Auto-cobro de ciclos pendientes/vencidos
 * Cobra automáticamente usando el método de pago guardado en Stripe.
 * Procesa máximo 50 ciclos por ejecución para evitar timeouts.
 */
require_once __DIR__ . '/../php/bootstrap.php';

// ── Auth: validar token cron ────────────────────────────────────────────────
$cronToken = defined('VOLTIKA_CRON_TOKEN') ? VOLTIKA_CRON_TOKEN : (getenv('VOLTIKA_CRON_TOKEN') ?: '');
if ($cronToken) {
    $provided = $_SERVER['HTTP_X_CRON_TOKEN'] ?? ($_GET['token'] ?? '');
    if ($provided !== $cronToken) {
        adminJsonOut(['error' => 'Token inválido'], 403);
    }
}

$stripeKey = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : (getenv('STRIPE_SECRET_KEY') ?: '');
if (!$stripeKey) {
    adminJsonOut(['error' => 'Stripe no configurado'], 500);
}

$pdo = getDB();

// Get pending/overdue cycles with a saved payment method.
// IMPORTANT: Skip cycles that already have a manual payment (paid_manual)
// or are in 'pending_manual' state (OXXO/SPEI payment awaiting acreditation).
// This prevents duplicate charges when a customer pays manually before the
// auto-charge cron runs. See: Voltika WhatsApp Notifications doc, Part 1.
$ciclos = $pdo->query("
    SELECT c.id, c.monto, c.semana_num,
           s.stripe_customer_id, s.stripe_payment_method_id,
           COALESCE(s.nombre, '') as nombre
    FROM ciclos_pago c
    JOIN subscripciones_credito s ON c.subscripcion_id = s.id
    WHERE c.estado IN ('pending','overdue')
      AND c.estado NOT IN ('paid_manual','paid_auto','pending_manual')
      AND s.stripe_customer_id IS NOT NULL AND s.stripe_customer_id != ''
      AND s.stripe_payment_method_id IS NOT NULL AND s.stripe_payment_method_id != ''
      AND s.estado = 'activa'
    ORDER BY c.fecha_vencimiento ASC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

$charged = 0;
$skipped = 0;
$failed  = 0;
$errors  = [];

foreach ($ciclos as $ciclo) {
    // Double-check: re-read the cycle status right before charging.
    // Between the SELECT above and now, a webhook could have updated
    // this cycle to paid_manual (OXXO/SPEI payment confirmed).
    $freshStatus = $pdo->prepare("SELECT estado FROM ciclos_pago WHERE id = ?");
    $freshStatus->execute([$ciclo['id']]);
    $currentEstado = $freshStatus->fetchColumn();
    if (in_array($currentEstado, ['paid_manual', 'paid_auto', 'pending_manual', 'skipped'], true)) {
        $skipped++;
        continue;
    }

    $amount = (int)(round($ciclo['monto'] * 100));

    $ch = curl_init('https://api.stripe.com/v1/payment_intents');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $stripeKey . ':',
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POSTFIELDS     => http_build_query([
            'amount'               => $amount,
            'currency'             => 'mxn',
            'customer'             => $ciclo['stripe_customer_id'],
            'payment_method'       => $ciclo['stripe_payment_method_id'],
            'off_session'          => 'true',
            'confirm'              => 'true',
            'description'          => 'Voltika auto-cobro ciclo #' . $ciclo['semana_num'] . ' - ' . $ciclo['nombre'],
            'metadata[ciclo_id]'   => $ciclo['id'],
            'metadata[tipo]'       => 'auto_cobro',
        ]),
    ]);
    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        $failed++;
        $errors[] = ['ciclo_id' => $ciclo['id'], 'error' => 'curl: ' . $curlErr];
        continue;
    }

    $resp = json_decode($raw, true);

    if ($httpCode >= 200 && $httpCode < 300 && ($resp['status'] ?? '') === 'succeeded') {
        $pdo->prepare("
            UPDATE ciclos_pago
            SET estado = 'paid_auto', stripe_payment_intent = ?, fecha_pago = NOW(), origen = 'cron_auto'
            WHERE id = ?
        ")->execute([$resp['id'], $ciclo['id']]);
        $charged++;
    } else {
        $errorMsg = $resp['error']['message']
            ?? ($resp['last_payment_error']['message'] ?? 'Error desconocido');
        $failed++;
        $errors[] = [
            'ciclo_id' => $ciclo['id'],
            'error'    => $errorMsg,
            'status'   => $resp['status'] ?? 'failed',
        ];
    }
}

adminLog('cron_auto_cobro', [
    'procesados' => count($ciclos),
    'cobrados'   => $charged,
    'omitidos'   => $skipped,
    'fallidos'   => $failed,
    'errores'    => $errors,
]);

adminJsonOut([
    'ok'         => true,
    'procesados' => count($ciclos),
    'cobrados'   => $charged,
    'omitidos'   => $skipped,
    'fallidos'   => $failed,
    'errores'    => $errors,
]);
