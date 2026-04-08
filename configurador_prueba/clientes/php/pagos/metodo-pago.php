<?php
require_once __DIR__ . '/../bootstrap.php';
$cid = portalRequireAuth();
$pdo = getDB();

$stmt = $pdo->prepare("SELECT stripe_customer_id, stripe_payment_method_id FROM subscripciones_credito
    WHERE cliente_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$cid]);
$sub = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sub || !$sub['stripe_payment_method_id']) portalJsonOut(['metodo' => null]);

$ch = curl_init('https://api.stripe.com/v1/payment_methods/' . urlencode($sub['stripe_payment_method_id']));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . STRIPE_SECRET_KEY],
    CURLOPT_TIMEOUT => 15,
]);
$resp = curl_exec($ch);
curl_close($ch);
$data = json_decode($resp, true) ?: [];
$card = $data['card'] ?? null;

portalJsonOut([
    'metodo' => $card ? [
        'brand' => $card['brand'] ?? 'Card',
        'last4' => $card['last4'] ?? '••••',
        'exp' => ($card['exp_month'] ?? '') . '/' . ($card['exp_year'] ?? ''),
    ] : null,
]);
