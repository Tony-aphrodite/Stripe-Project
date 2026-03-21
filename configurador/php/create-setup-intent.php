<?php
/**
 * Voltika Configurador - Crear SetupIntent en Stripe
 * For recurring payment card registration (no charge now)
 * Receives customer info, creates SetupIntent, returns clientSecret
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
    echo json_encode(['error' => 'Stripe secret key no configurada en .env']);
    exit;
}

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// ── Read request ──────────────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);
$nombre   = isset($input['nombre'])   ? trim($input['nombre'])   : '';
$email    = isset($input['email'])    ? trim($input['email'])    : '';
$telefono = isset($input['telefono']) ? trim($input['telefono']) : '';

try {
    // Create or find Stripe Customer
    $customerParams = [
        'name'  => $nombre,
        'phone' => $telefono ? '+52' . $telefono : null,
        'metadata' => [
            'source' => 'voltika_configurador',
            'tipo'   => 'autopago_semanal'
        ]
    ];
    if ($email) {
        $customerParams['email'] = $email;
    }

    $customer = \Stripe\Customer::create($customerParams);

    // Create SetupIntent (saves card for future charges, no charge now)
    $setupIntent = \Stripe\SetupIntent::create([
        'customer'             => $customer->id,
        'payment_method_types' => ['card'],
        'usage'                => 'off_session',  // For recurring charges
        'metadata'             => [
            'nombre'   => $nombre,
            'email'    => $email,
            'telefono' => $telefono,
            'tipo'     => 'autopago_credito_voltika'
        ]
    ]);

    echo json_encode([
        'ok'           => true,
        'clientSecret' => $setupIntent->client_secret,
        'customerId'   => $customer->id,
        'setupIntentId'=> $setupIntent->id
    ]);

} catch (\Stripe\Exception\ApiErrorException $e) {
    http_response_code(400);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Error interno: ' . $e->getMessage()
    ]);
}
