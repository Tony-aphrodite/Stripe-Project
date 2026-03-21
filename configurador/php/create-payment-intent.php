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
$method           = trim($json['method'] ?? 'card');      // card, oxxo, spei
$installments     = !empty($json['installments']);
$msiMeses         = intval($json['msiMeses'] ?? 9);
$customer         = $json['customer'] ?? [];

if ($amount <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Monto invalido']);
    exit;
}

// ── Determinar payment_method_types segun metodo ─────────────────────────────
$paymentMethodTypes = ['card'];
if ($method === 'oxxo') {
    $paymentMethodTypes = ['oxxo'];
} elseif ($method === 'spei') {
    $paymentMethodTypes = ['customer_balance'];
}

// ── Crear PaymentIntent ───────────────────────────────────────────────────────
try {
    $intentData = [
        'amount'               => $amount,
        'currency'             => 'mxn',
        'payment_method_types' => $paymentMethodTypes,
        'description'          => 'Voltika - ' . ($customer['modelo'] ?? 'Moto electrica'),
        'metadata'             => [
            'modelo'   => $customer['modelo']   ?? '',
            'color'    => $customer['color']     ?? '',
            'ciudad'   => $customer['ciudad']    ?? '',
            'telefono' => $customer['telefono']  ?? '',
            'method'   => $method,
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

    // SPEI: handle server-side and return bank details directly
    if ($method === 'spei') {
        // SPEI requiere customer obligatorio
        if (empty($intentData['customer'])) {
            $stripeCustomer = \Stripe\Customer::create([
                'name'  => $customer['nombre'] ?? 'Cliente Voltika',
                'email' => $customer['email'] ?? 'cliente@voltika.mx',
            ]);
            $intentData['customer'] = $stripeCustomer->id;
        }
        $intentData['payment_method_types'] = ['customer_balance'];
        $intentData['payment_method_data'] = [
            'type' => 'customer_balance'
        ];
        $intentData['payment_method_options'] = [
            'customer_balance' => [
                'funding_type' => 'bank_transfer',
                'bank_transfer' => [
                    'type' => 'mx_bank_transfer'
                ]
            ]
        ];
        $intentData['confirm'] = true;

        $intent = \Stripe\PaymentIntent::create($intentData);

        $response = ['clientSecret' => $intent->client_secret];

        // Extract bank transfer details
        if ($intent->next_action && isset($intent->next_action->display_bank_transfer_instructions)) {
            $bankInfo = $intent->next_action->display_bank_transfer_instructions;
            $addresses = $bankInfo->financial_addresses ?? [];
            $clabe = '';
            foreach ($addresses as $addr) {
                if (isset($addr->clabe)) {
                    $clabe = $addr->clabe;
                    break;
                }
            }
            $response['speiData'] = [
                'clabe'        => $clabe,
                'banco'        => $bankInfo->hosted_instructions_url ? 'Stripe' : 'STP',
                'beneficiario' => 'MTECH GEARS S.A. DE C.V.',
                'referencia'   => $bankInfo->reference ?? '',
                'amount'       => $amount
            ];
        }

        echo json_encode($response);
        exit;
    }

    // Habilitar MSI si aplica (solo para card)
    if ($method === 'card' && $installments && $msiMeses > 0) {
        $intentData['payment_method_options'] = [
            'card' => [
                'installments' => ['enabled' => true]
            ]
        ];
    }

    // Para OXXO: dividir si supera $10,000 MXN (1,000,000 centavos)
    if ($method === 'oxxo') {
        $maxOxxoCents = 999900; // $9,999 MXN en centavos (margen seguro)
        $oxxoAmounts = [];
        if ($amount > $maxOxxoCents) {
            $remaining = $amount;
            while ($remaining > 0) {
                $chunk = min($remaining, $maxOxxoCents);
                $oxxoAmounts[] = $chunk;
                $remaining -= $chunk;
            }
        } else {
            $oxxoAmounts[] = $amount;
        }

        $oxxoRefs = [];
        $rawName      = !empty($customer['nombre']) ? trim($customer['nombre']) : '';
        $billingEmail = !empty($customer['email']) ? trim($customer['email']) : 'cliente@voltika.mx';
        // OXXO requires first + last name, each min 2 chars — always ensure valid
        $billingName = 'Cliente Voltika';
        if (strlen($rawName) >= 4 && strpos($rawName, ' ') !== false) {
            $parts = explode(' ', $rawName);
            $valid = true;
            foreach ($parts as $p) {
                if (strlen(trim($p)) < 2) { $valid = false; break; }
            }
            if ($valid) $billingName = $rawName;
        }

        // Asegurar que hay customer para OXXO
        if (empty($intentData['customer'])) {
            $stripeCustomer = \Stripe\Customer::create([
                'name'  => $billingName,
                'email' => $billingEmail,
            ]);
            $intentData['customer'] = $stripeCustomer->id;
        }

        foreach ($oxxoAmounts as $idx => $oxxoAmount) {
            $oxxoIntentData = [
                'amount'               => $oxxoAmount,
                'currency'             => 'mxn',
                'payment_method_types' => ['oxxo'],
                'customer'             => $intentData['customer'],
                'description'          => 'Voltika - OXXO ' . ($idx + 1) . '/' . count($oxxoAmounts),
                'metadata'             => $intentData['metadata'] ?? [],
            ];

            $intent = \Stripe\PaymentIntent::create($oxxoIntentData);

            // Crear PaymentMethod y confirmar
            $pm = \Stripe\PaymentMethod::create([
                'type' => 'oxxo',
                'billing_details' => [
                    'name'  => $billingName,
                    'email' => $billingEmail,
                ],
            ]);

            $intent = $intent->confirm(['payment_method' => $pm->id]);

            if ($intent->next_action && isset($intent->next_action->oxxo_display_details)) {
                $oxxo = $intent->next_action->oxxo_display_details;
                $oxxoRefs[] = [
                    'number'             => $oxxo->number ?? '',
                    'amount'             => $oxxoAmount,
                    'expires_after'      => $oxxo->expires_after ?? 0,
                    'hosted_voucher_url' => $oxxo->hosted_voucher_url ?? ''
                ];
            }
        }

        $response = [
            'oxxoData' => $oxxoRefs,
            'totalRefs' => count($oxxoRefs)
        ];

        echo json_encode($response);
        exit;
    }

    $intent = \Stripe\PaymentIntent::create($intentData);

    $response = ['clientSecret' => $intent->client_secret];

    echo json_encode($response);

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
