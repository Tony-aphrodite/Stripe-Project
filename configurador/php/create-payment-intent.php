<?php
/**
 * Voltika Configurador - Crear PaymentIntent en Stripe
 * Recibe JSON POST, crea PaymentIntent, devuelve clientSecret
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Central config ───────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';

// ── Stripe SDK ────────────────────────────────────────────────────────────────
$stripePhpPath = __DIR__ . '/vendor/autoload.php';
if (!file_exists($stripePhpPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Stripe SDK no encontrado. Ejecuta: cd php && composer install']);
    exit;
}
require_once $stripePhpPath;

if (!STRIPE_SECRET_KEY || STRIPE_SECRET_KEY === 'sk_test_PLACEHOLDER') {
    http_response_code(500);
    echo json_encode(['error' => 'STRIPE_SECRET_KEY no configurada. Edita el archivo .env']);
    exit;
}

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// ── Request ───────────────────────────────────────────────────────────────────
$json = json_decode(file_get_contents('php://input'), true);

if (!$json) {
    http_response_code(400);
    echo json_encode(['error' => 'Request invalido']);
    exit;
}

$amount           = intval($json['amount'] ?? 0);        // centavos MXN
$installments     = !empty($json['installments']);
$msiMeses         = intval($json['msiMeses'] ?? 9);
$customer         = $json['customer'] ?? [];

if ($amount <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Monto invalido']);
    exit;
}

// ── Crear PaymentIntent ───────────────────────────────────────────────────────
try {
    $intentData = [
        'amount'               => $amount,
        'currency'             => 'mxn',
        'payment_method_types' => ['card'],
        'description'          => 'Voltika - ' . ($customer['modelo'] ?? 'Moto electrica'),
        'metadata'             => [
            'modelo'   => $customer['modelo']   ?? '',
            'color'    => $customer['color']     ?? '',
            'ciudad'   => $customer['ciudad']    ?? '',
            'telefono' => $customer['telefono']  ?? '',
        ],
    ];

    // Agregar datos del cliente si estan disponibles
    if (!empty($customer['nombre']) && !empty($customer['email'])) {
        $stripeCustomer = \Stripe\Customer::create([
            'name'  => $customer['nombre'],
            'email' => $customer['email'],
            'phone' => '+52' . ($customer['telefono'] ?? ''),
        ]);
        $intentData['customer'] = $stripeCustomer->id;
        $intentData['receipt_email'] = $customer['email'];
    }

    // Habilitar MSI si aplica
    if ($installments && $msiMeses > 0) {
        $intentData['payment_method_options'] = [
            'card' => [
                'installments' => ['enabled' => true]
            ]
        ];
    }

    $intent = \Stripe\PaymentIntent::create($intentData);

    echo json_encode(['clientSecret' => $intent->client_secret]);

} catch (\Stripe\Exception\CardException $e) {
    http_response_code(402);
    echo json_encode(['error' => $e->getError()->message]);
} catch (\Stripe\Exception\ApiErrorException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error de Stripe: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error interno del servidor']);
}
