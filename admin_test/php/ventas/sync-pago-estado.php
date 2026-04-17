<?php
/**
 * POST — Reconcile transacciones.pago_estado against Stripe PaymentIntent status.
 *
 * Finds rows where pago_estado is 'pendiente'/'parcial'/NULL but their stripe_pi
 * points to a PaymentIntent that is already `succeeded` in Stripe. Updates the
 * row to pago_estado='pagada'. Complements verificar-stripe.php (which handles
 * ORPHAN PIs missing from transacciones entirely).
 *
 * Why needed: the `payment_intent.succeeded` webhook may not fire or land
 * (missing endpoint config, signature failure, network retry). This script is
 * a safety net the admin can run any time to bring DB state in line with Stripe.
 *
 * Body: { dry_run: bool, hours: int (default 168 = 7d) }
 * Response: {
 *   ok, dry_run, scanned, updated, already_paid, stripe_pending, errors,
 *   details: [{ id, pedido, stripe_pi, previous, new_status }]
 * }
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin']);

$body    = adminJsonIn();
$dryRun  = !empty($body['dry_run']);
$hours   = max(1, min(8760, (int)($body['hours'] ?? 168)));

$stripeKey = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : (getenv('STRIPE_SECRET_KEY') ?: '');
if (!$stripeKey) {
    adminJsonOut(['ok' => false, 'error' => 'STRIPE_SECRET_KEY no configurada'], 500);
}

$pdo = getDB();

// Stripe PI fetch helper
$fetchPi = function(string $piId) use ($stripeKey): array {
    $url = 'https://api.stripe.com/v1/payment_intents/' . urlencode($piId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $stripeKey . ':',
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err || $code >= 400) {
        return ['error' => $err ?: ('HTTP ' . $code)];
    }
    return json_decode($raw, true) ?: ['error' => 'json_decode failed'];
};

// Find candidate rows: have stripe_pi, pago_estado is pendiente/parcial/empty
try {
    $stmt = $pdo->prepare("
        SELECT id, pedido, stripe_pi, pago_estado, freg, total, nombre, email
        FROM transacciones
        WHERE stripe_pi IS NOT NULL AND stripe_pi <> ''
          AND (pago_estado IS NULL OR pago_estado IN ('pendiente','parcial',''))
          AND freg >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ORDER BY freg DESC
    ");
    $stmt->execute([$hours]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    adminJsonOut(['ok' => false, 'error' => 'SELECT failed: ' . $e->getMessage()], 500);
}

$scanned       = 0;
$updated       = 0;
$alreadyPaid   = 0;
$stripePending = 0;
$errors        = [];
$details       = [];

foreach ($candidates as $row) {
    $scanned++;
    $piId = $row['stripe_pi'];

    $pi = $fetchPi($piId);
    if (isset($pi['error'])) {
        $errors[] = ['id' => (int)$row['id'], 'pi' => $piId, 'error' => $pi['error']];
        continue;
    }

    $status = $pi['status'] ?? 'unknown';

    if ($status === 'succeeded') {
        $details[] = [
            'id'         => (int)$row['id'],
            'pedido'     => $row['pedido'],
            'stripe_pi'  => $piId,
            'previous'   => $row['pago_estado'],
            'new_status' => 'pagada',
            'nombre'     => $row['nombre'],
            'total'      => (float)$row['total'],
        ];
        if (!$dryRun) {
            try {
                $pdo->prepare("UPDATE transacciones SET pago_estado='pagada' WHERE id=?")
                    ->execute([(int)$row['id']]);
                // Mirror to inventario_motos if linked by stripe_pi
                $pdo->prepare("UPDATE inventario_motos SET pago_estado='pagada' WHERE stripe_pi=? AND (pago_estado IS NULL OR pago_estado IN ('pendiente',''))")
                    ->execute([$piId]);
                $updated++;
            } catch (Throwable $e) {
                $errors[] = ['id' => (int)$row['id'], 'pi' => $piId, 'error' => 'UPDATE failed: ' . $e->getMessage()];
            }
        } else {
            $updated++; // projected
        }
    } elseif (in_array($status, ['requires_payment_method','requires_confirmation','requires_action','processing','requires_capture'], true)) {
        $stripePending++;
    } elseif ($status === 'canceled') {
        // Mark as canceled in DB
        $details[] = [
            'id'         => (int)$row['id'],
            'pedido'     => $row['pedido'],
            'stripe_pi'  => $piId,
            'previous'   => $row['pago_estado'],
            'new_status' => 'cancelado',
        ];
        if (!$dryRun) {
            try {
                $pdo->prepare("UPDATE transacciones SET pago_estado='cancelado' WHERE id=?")
                    ->execute([(int)$row['id']]);
                $updated++;
            } catch (Throwable $e) {
                $errors[] = ['id' => (int)$row['id'], 'pi' => $piId, 'error' => $e->getMessage()];
            }
        }
    } else {
        $errors[] = ['id' => (int)$row['id'], 'pi' => $piId, 'error' => 'unknown status: ' . $status];
    }
}

if (!$dryRun) {
    adminLog('sync_pago_estado', [
        'scanned'       => $scanned,
        'updated'       => $updated,
        'stripe_pending'=> $stripePending,
        'errors'        => count($errors),
    ]);
}

adminJsonOut([
    'ok'             => true,
    'dry_run'        => $dryRun,
    'hours'          => $hours,
    'scanned'        => $scanned,
    'updated'        => $updated,
    'stripe_pending' => $stripePending,
    'errors'         => $errors,
    'details'        => $details,
]);
