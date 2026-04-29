<?php
/**
 * Voltika — MercadoPago payment adapter (scaffold).
 *
 * Tech Spec EN §5.2 marks MercadoPago as the PRIMARY processor going
 * forward and Stripe as legacy. This module provides the stable
 * interface so the configurador and admin can transition order-by-order
 * without rewriting business logic.
 *
 * Status: scaffold only. Real API calls activate when MP_ACCESS_TOKEN is
 * set in the environment / config. Until then, mpCreatePreference()
 * returns a queued response and orders continue to flow through Stripe.
 *
 * Configuration (config.php / env):
 *   MP_ENABLED         = '1' to enable (default '0' = use Stripe)
 *   MP_ACCESS_TOKEN    = MercadoPago access token (Marketplace o Vendedor)
 *   MP_PUBLIC_KEY      = MercadoPago public key (frontend SDK)
 *   MP_NOTIFICATION_URL= webhook endpoint
 *   MP_DESCRIPTOR      = "VOLTIKA MX" (statement descriptor)
 *
 * Public functions (stable interface — implementations vary):
 *   mpEnabled(): bool
 *   mpEnsureSchema(PDO): void
 *   mpCreatePreference(array $datos): array  - one-time card / OXXO / SPEI
 *   mpCreateSubscription(array $datos): array - recurring billing
 *   mpHandleWebhook(array $payload): array
 *
 * Migration path (Tech Spec EN §5.2 says "migrate gradually as Stripe
 * disputes resolve"):
 *   1. Configure MP_ACCESS_TOKEN + MP_PUBLIC_KEY in env (sandbox first).
 *   2. Set MP_ENABLED=1 to flip new orders to MP.
 *   3. Existing Stripe subscriptions keep using stripe-webhook.php; new
 *      ones use this module's webhook handler.
 *   4. Once all active Stripe disputes close, remove Stripe entirely.
 */

require_once __DIR__ . '/config.php';

function mpEnabled(): bool {
    $enabled = getenv('MP_ENABLED') ?: (defined('MP_ENABLED') ? MP_ENABLED : '0');
    $token   = getenv('MP_ACCESS_TOKEN') ?: (defined('MP_ACCESS_TOKEN') ? MP_ACCESS_TOKEN : '');
    return $enabled === '1' && $token !== '';
}

function mpEnsureSchema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS mp_preferences (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaccion_id INT NULL,
            mp_preference_id VARCHAR(80) NULL UNIQUE,
            mp_payment_id    VARCHAR(80) NULL,
            estado VARCHAR(30) NOT NULL DEFAULT 'pendiente',
              -- pendiente | aprobado | rechazado | cancelado | en_proceso | refunded
            monto DECIMAL(12,2) NOT NULL,
            metodo VARCHAR(40) NULL,
            init_point VARCHAR(500) NULL,
            sandbox_init_point VARCHAR(500) NULL,
            response_raw MEDIUMTEXT NULL,
            freg DATETIME DEFAULT CURRENT_TIMESTAMP,
            fmod DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_transaccion (transaccion_id),
            INDEX idx_estado (estado),
            INDEX idx_mp_preference (mp_preference_id),
            INDEX idx_mp_payment (mp_payment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('mpEnsureSchema: ' . $e->getMessage()); }
}

/**
 * Create a Checkout Pro preference for a one-shot payment.
 * In scaffold mode (MP_ACCESS_TOKEN unset) returns 'pending_config'.
 */
function mpCreatePreference(array $datos): array {
    if (!mpEnabled()) {
        return ['ok' => false, 'estado' => 'pending_config',
                'error' => 'MercadoPago no habilitado (set MP_ENABLED=1 + MP_ACCESS_TOKEN).'];
    }

    $token = getenv('MP_ACCESS_TOKEN') ?: MP_ACCESS_TOKEN;
    $descriptor = getenv('MP_DESCRIPTOR') ?: (defined('MP_DESCRIPTOR') ? MP_DESCRIPTOR : 'VOLTIKA MX');
    $notifyUrl  = getenv('MP_NOTIFICATION_URL') ?: (defined('MP_NOTIFICATION_URL') ? MP_NOTIFICATION_URL : '');

    $pdo = getDB();
    mpEnsureSchema($pdo);

    $body = [
        'items' => [[
            'title'       => $datos['descripcion'] ?? 'Voltika',
            'quantity'    => 1,
            'unit_price'  => floatval($datos['monto']),
            'currency_id' => 'MXN',
        ]],
        'payer' => [
            'email' => $datos['email'] ?? '',
            'name'  => $datos['nombre'] ?? '',
        ],
        'statement_descriptor' => $descriptor,
        'external_reference'   => (string)($datos['transaccion_id'] ?? ''),
        'notification_url'     => $notifyUrl,
        'back_urls' => [
            'success' => $datos['return_url'] ?? '',
            'failure' => $datos['return_url'] ?? '',
            'pending' => $datos['return_url'] ?? '',
        ],
        'auto_return' => 'approved',
    ];

    $ch = curl_init('https://api.mercadopago.com/checkout/preferences');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) return ['ok' => false, 'error' => 'curl: ' . $curlErr];
    $resp = json_decode($raw, true) ?: [];
    if ($httpCode < 200 || $httpCode >= 300) {
        return ['ok' => false, 'error' => $resp['message'] ?? ('HTTP ' . $httpCode), 'raw' => $resp];
    }

    $pdo->prepare("INSERT INTO mp_preferences
            (transaccion_id, mp_preference_id, monto, init_point, sandbox_init_point, response_raw)
        VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([
            $datos['transaccion_id'] ?? null,
            $resp['id'] ?? null,
            floatval($datos['monto']),
            $resp['init_point'] ?? null,
            $resp['sandbox_init_point'] ?? null,
            substr($raw, 0, 5000),
        ]);

    return [
        'ok' => true,
        'preference_id' => $resp['id'] ?? null,
        'init_point'    => $resp['init_point'] ?? null,
        'sandbox_init_point' => $resp['sandbox_init_point'] ?? null,
    ];
}

/**
 * Subscriptions API stub (recurring weekly billing). Real implementation
 * uses MP's preapproval / preapproval_plan endpoints once MP_ENABLED=1.
 */
function mpCreateSubscription(array $datos): array {
    if (!mpEnabled()) {
        return ['ok' => false, 'estado' => 'pending_config',
                'error' => 'MercadoPago subscriptions no habilitadas.'];
    }
    return ['ok' => false, 'error' => 'mpCreateSubscription: implementación pendiente — ver Tech Spec §5.2'];
}

/**
 * Webhook handler — invoked by configurador_prueba/php/mp-webhook.php.
 * Updates mp_preferences and synchronizes back to transacciones.
 */
function mpHandleWebhook(array $payload): array {
    if (!mpEnabled()) return ['ok' => false, 'error' => 'MP no habilitado'];

    $pdo = getDB();
    mpEnsureSchema($pdo);

    $type = $payload['type'] ?? ($payload['topic'] ?? '');
    $dataId = $payload['data']['id'] ?? ($payload['resource'] ?? '');

    if ($type === 'payment' && $dataId) {
        // Fetch full payment details from MP
        $token = getenv('MP_ACCESS_TOKEN') ?: MP_ACCESS_TOKEN;
        $ch = curl_init('https://api.mercadopago.com/v1/payments/' . urlencode((string)$dataId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode < 200 || $httpCode >= 300) {
            return ['ok' => false, 'error' => 'MP payment fetch HTTP ' . $httpCode];
        }
        $payment = json_decode($raw, true) ?: [];

        $estado = $payment['status'] ?? 'unknown';
        $prefId = $payment['preference_id'] ?? null;
        $extRef = $payment['external_reference'] ?? null;
        $monto  = floatval($payment['transaction_amount'] ?? 0);
        $metodo = $payment['payment_method_id'] ?? '';

        // Persist
        $pdo->prepare("UPDATE mp_preferences
                       SET estado = ?, mp_payment_id = ?, metodo = ?, response_raw = ?
                       WHERE mp_preference_id = ?")
            ->execute([_mpMapEstado($estado), (string)$dataId, $metodo, substr($raw, 0, 5000), $prefId]);

        // Mirror onto transacciones if external_reference matches a row.
        if ($extRef && in_array($estado, ['approved','accredited'], true)) {
            try {
                $pdo->prepare("UPDATE transacciones SET pago_estado = 'pagada' WHERE id = ?")
                    ->execute([(int)$extRef]);
            } catch (Throwable $e) {}
        }
        return ['ok' => true, 'mapped_estado' => _mpMapEstado($estado)];
    }
    return ['ok' => true, 'note' => 'unhandled type'];
}

function _mpMapEstado(string $mp): string {
    return match ($mp) {
        'approved', 'accredited' => 'aprobado',
        'pending', 'in_process'  => 'en_proceso',
        'rejected'               => 'rechazado',
        'refunded', 'charged_back' => 'refunded',
        'cancelled'              => 'cancelado',
        default                  => $mp,
    };
}
