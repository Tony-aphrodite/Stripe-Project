<?php
/**
 * GET — Public endpoint: returns frontend configuration
 * Provides Stripe publishable key and environment mode to JavaScript
 * so keys are never hardcoded in frontend files.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache');

require_once __DIR__ . '/config.php';

echo json_encode([
    'ok' => true,
    'env' => APP_ENV,
    'stripe_publishable_key' => STRIPE_PUBLISHABLE_KEY,
], JSON_UNESCAPED_UNICODE);
