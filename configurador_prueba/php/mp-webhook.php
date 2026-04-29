<?php
/**
 * MercadoPago webhook endpoint.
 * Routes to mpHandleWebhook(); pairs with stripe-webhook.php during the
 * dual-processor migration window.
 */
require_once __DIR__ . '/mercadopago-adapter.php';

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
// MP also sends some events as query string.
foreach ($_GET as $k => $v) if (!isset($payload[$k])) $payload[$k] = $v;

$r = mpHandleWebhook($payload);
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['received' => true, 'result' => $r]);
