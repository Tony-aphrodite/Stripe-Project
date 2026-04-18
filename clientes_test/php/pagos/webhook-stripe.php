<?php
/**
 * Voltika Portal - Stripe webhook listener (DEPRECATED)
 *
 * Payment webhook handling has been consolidated into the main webhook:
 *   configurador_prueba_test/php/stripe-webhook.php
 *
 * This file remains for backward compatibility — if Stripe still sends
 * events to this URL, it will process them locally as before.
 * Once the Stripe Dashboard webhook URL is updated, this file can be removed.
 */
require_once __DIR__ . '/../bootstrap.php';

$payload = file_get_contents('php://input');
$sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Minimal signature verification
$verified = false;
if (STRIPE_WEBHOOK_SECRET && $sig) {
    foreach (explode(',', $sig) as $pair) {
        [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
        if ($k === 't') $ts = $v;
        if ($k === 'v1') $v1 = $v;
    }
    if (!empty($ts) && !empty($v1)) {
        $signed = hash_hmac('sha256', $ts . '.' . $payload, STRIPE_WEBHOOK_SECRET);
        $verified = hash_equals($signed, $v1);
    }
}

$event = json_decode($payload, true);
$logDir = __DIR__ . '/../../../configurador_prueba_test/php/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
$logFile = $logDir . '/portal-webhook.log';
@file_put_contents($logFile, json_encode([
    'ts' => date('c'), 'verified' => $verified,
    'type' => $event['type'] ?? null, 'id' => $event['id'] ?? null,
]) . "\n", FILE_APPEND | LOCK_EX);

if (!$verified) { http_response_code(400); exit('sig'); }

if (($event['type'] ?? '') === 'payment_intent.succeeded') {
    $pi = $event['data']['object'] ?? [];
    $piId = $pi['id'] ?? '';
    $meta = $pi['metadata'] ?? [];
    $subId = (int)($meta['subscripcion_id'] ?? 0);
    $ciclos = $meta['ciclos'] ?? '';
    if ($subId && $ciclos) {
        $pdo = getDB();
        $nums = array_map('intval', explode(',', $ciclos));
        $ph = implode(',', array_fill(0, count($nums), '?'));
        $stmt = $pdo->prepare("UPDATE ciclos_pago SET estado = 'paid_auto', stripe_payment_intent = ?
            WHERE subscripcion_id = ? AND semana_num IN ($ph) AND estado NOT IN ('paid_manual','paid_auto')");
        $stmt->execute([$piId, $subId, ...$nums]);
    }
}

http_response_code(200);
echo 'ok';
