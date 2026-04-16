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

// ── Reminder email for OXXO/SPEI ─────────────────────────────────────────────
function _sendReminderEmail($email, $nombre, $customer, $monto, $metodo, $linkPago) {
    $pedidoNum = time();
    $n = htmlspecialchars($nombre);
    $m = htmlspecialchars($customer['modelo'] ?? '');
    $c = htmlspecialchars($customer['color'] ?? '');
    $montoFmt = '$' . number_format($monto, 0, '.', ',') . ' MXN';
    $whatsapp = '+52 55 1341 6370';

    $td = 'style="padding:10px 16px;border-bottom:1px solid #E5E7EB;font-size:14px;"';
    $tdl = 'style="padding:10px 16px;border-bottom:1px solid #E5E7EB;font-size:14px;color:#6B7280;"';
    $section = 'style="margin:0 0 8px;padding:12px 0 6px;font-size:16px;font-weight:800;color:#1a3a5c;border-bottom:2px solid #039fe1;"';

    $linkHtml = '';
    if (is_array($linkPago) && !empty($linkPago['clabe'])) {
        // SPEI bank transfer details
        $clabeValue = htmlspecialchars($linkPago['clabe']);
        $linkHtml .= '<div style="background:#E8F4FD;border-radius:10px;padding:16px;margin:12px 0;border:1px solid #B3D4FC;">';
        // Header with logo
        $linkHtml .= '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">';
        $linkHtml .= '<span style="font-size:14px;font-weight:700;color:#1a3a5c;">Datos para transferencia SPEI</span>';
        $linkHtml .= '<img src="https://www.voltika.mx/configurador_prueba/img/voltika_logo_h_white.svg" alt="Voltika" style="height:28px;width:auto;background:#1a3a5c;border-radius:6px;padding:4px 8px;">';
        $linkHtml .= '</div>';
        // CLABE with copy link
        $linkHtml .= '<div style="background:#fff;border-radius:8px;padding:14px;border:1px solid #eee;margin-bottom:10px;">';
        $linkHtml .= '<div style="font-size:12px;color:#888;margin-bottom:4px;">CLABE Interbancaria:</div>';
        $linkHtml .= '<div style="display:flex;align-items:center;justify-content:space-between;">';
        $linkHtml .= '<div style="font-size:16px;font-weight:900;color:#333;letter-spacing:0.5px;">' . $clabeValue . '</div>';
        $linkHtml .= '<a href="https://www.voltika.mx/configurador_prueba/voucher.html?clabe=' . $clabeValue . '" style="flex-shrink:0;padding:6px 12px;background:#039fe1;color:#fff;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;">Copiar</a>';
        $linkHtml .= '</div>';
        $linkHtml .= '</div>';
        if (!empty($linkPago['referencia'])) {
            $linkHtml .= '<div style="font-size:14px;color:#333;margin-bottom:4px;">Referencia: <strong>' . htmlspecialchars($linkPago['referencia']) . '</strong></div>';
        }
        if (!empty($linkPago['beneficiario'])) {
            $linkHtml .= '<div style="font-size:14px;color:#333;margin-bottom:4px;">Beneficiario: <strong>' . htmlspecialchars($linkPago['beneficiario']) . '</strong></div>';
        }
        if (!empty($linkPago['banco'])) {
            $linkHtml .= '<div style="font-size:14px;color:#333;">Banco: <strong>' . htmlspecialchars($linkPago['banco']) . '</strong></div>';
        }
        $linkHtml .= '</div>';
    } elseif (is_array($linkPago) && !empty($linkPago['oxxoRefs'])) {
        // OXXO references with full details
        $refs = $linkPago['oxxoRefs'];
        $totalRefs = count($refs);
        $voucherBase = 'https://www.voltika.mx/configurador_prueba/voucher.html?url=';
        if ($totalRefs > 1) {
            $linkHtml .= '<p style="font-size:13px;color:#555;text-align:center;margin:8px 0;">Se generaron <strong>' . $totalRefs . ' referencias</strong> de pago. Presenta cualquiera en OXXO:</p>';
        }
        foreach ($refs as $idx => $ref) {
            $refNum = $ref['number'] ?? '--';
            $refAmount = $ref['amount'] ? '$' . number_format($ref['amount'] / 100, 0, '.', ',') . ' MXN' : '';
            $refExpires = !empty($ref['expires_after']) ? date('d/m/Y', $ref['expires_after']) : '';
            $formatted = implode(' ', str_split($refNum, 4));

            $linkHtml .= '<div style="background:#FFF8E1;border-radius:10px;padding:14px;margin:10px 0;border:1px solid #FFE082;">';
            if ($totalRefs > 1) {
                $linkHtml .= '<div style="font-size:12px;color:#039fe1;font-weight:700;margin-bottom:6px;">Referencia ' . ($idx + 1) . ' de ' . $totalRefs . '</div>';
            }
            $linkHtml .= '<div style="font-size:12px;color:#888;margin-bottom:2px;">N&uacute;mero de referencia:</div>';
            $linkHtml .= '<div style="font-size:16px;font-weight:900;color:#333;letter-spacing:0.5px;font-family:monospace;margin-bottom:8px;">' . htmlspecialchars($formatted) . '</div>';
            $linkHtml .= '<div style="font-size:13px;color:#333;">Monto: <strong>' . $refAmount . '</strong></div>';
            if ($refExpires) {
                $linkHtml .= '<div style="font-size:12px;color:#888;margin-top:2px;">Vence: <strong>' . $refExpires . '</strong></div>';
            }
            if (!empty($ref['hosted_voucher_url'])) {
                $wrappedUrl = $voucherBase . urlencode($ref['hosted_voucher_url']);
                $linkHtml .= '<a href="' . htmlspecialchars($wrappedUrl) . '" style="display:block;text-align:center;margin-top:10px;padding:10px;background:#E53935;color:#fff;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;">Ver voucher con c&oacute;digo de barras</a>';
            }
            $linkHtml .= '</div>';
        }
        $linkHtml .= '<p style="font-size:12px;color:#888;text-align:center;margin:10px 0 0;">Presenta en cualquier tienda OXXO. Confirmaci&oacute;n autom&aacute;tica al pagar.</p>';
    } elseif (is_array($linkPago) && count($linkPago) > 0) {
        // Fallback: array of URLs
        $voucherBase = 'https://www.voltika.mx/configurador_prueba/voucher.html?url=';
        $totalRefs = count($linkPago);
        foreach ($linkPago as $idx => $url) {
            $wrappedUrl = $voucherBase . urlencode($url);
            $label = $totalRefs > 1 ? 'REFERENCIA ' . ($idx + 1) . ' DE ' . $totalRefs . ' &rarr;' : 'COMPLETAR MI PAGO &rarr;';
            $linkHtml .= '<a href="' . htmlspecialchars($wrappedUrl) . '" style="display:block;text-align:center;padding:14px;background:#039fe1;color:#fff;border-radius:10px;font-size:14px;font-weight:800;text-decoration:none;margin:8px 0;">' . $label . '</a>';
        }
    } elseif (is_string($linkPago) && filter_var($linkPago, FILTER_VALIDATE_URL)) {
        $linkHtml = '<a href="' . htmlspecialchars($linkPago) . '" style="display:block;text-align:center;padding:16px;background:#039fe1;color:#fff;border-radius:10px;font-size:16px;font-weight:800;text-decoration:none;margin:12px 0;">COMPLETAR MI PAGO &rarr;</a>';
    } elseif (is_string($linkPago) && !empty($linkPago)) {
        $linkHtml = '<div style="background:#E8F4FD;border-radius:8px;padding:14px;text-align:center;margin:12px 0;font-size:14px;font-weight:700;color:#1a3a5c;">' . htmlspecialchars($linkPago) . '</div>';
    }

    $body = '<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Completa tu pago Voltika</title></head>
<body style="margin:0;padding:0;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;">
<tr><td align="center" style="padding:24px 12px;">
<table width="620" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;max-width:620px;width:100%;box-shadow:0 2px 12px rgba(0,0,0,0.08);">

<tr><td style="background:linear-gradient(135deg,#1a3a5c,#039fe1);padding:28px;text-align:center;">
<img src="https://www.voltika.mx/configurador_prueba/img/voltika_logo_h_white.svg" alt="Voltika" style="height:44px;width:auto;display:block;margin:0 auto;">
<p style="margin:6px 0 0;font-size:13px;color:rgba(255,255,255,0.8);">Movilidad el&eacute;ctrica inteligente</p>
</td></tr>

<tr><td style="padding:28px;">

<h2 style="margin:0 0 8px;font-size:20px;color:#1a3a5c;">Hola ' . $n . ', tu Voltika te est&aacute; esperando.</h2>
<p style="margin:0 0 12px;font-size:14px;color:#555;line-height:1.7;">Ya elegiste tu modelo, tu color y tu forma de pago.<br>Tu moto est&aacute; lista para ti.</p>
<p style="margin:0 0 24px;font-size:15px;color:#E53935;font-weight:700;">Solo falta completar tu pago para asegurarla.</p>

<div ' . $section . '>TU VOLTIKA</div>
<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
<tr><td ' . $tdl . '>Cliente</td><td ' . $td . '><strong>' . $n . '</strong></td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Orden</td><td ' . $td . '><strong>#' . $pedidoNum . '</strong></td></tr>
<tr><td ' . $tdl . '>Modelo</td><td ' . $td . '>' . $m . '</td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Color</td><td ' . $td . '>' . $c . '</td></tr>
<tr style="background:#F9FAFB;"><td ' . $tdl . '>Monto pendiente</td><td ' . $td . '><strong style="color:#E53935;">' . $montoFmt . '</strong></td></tr>
<tr><td ' . $tdl . '>M&eacute;todo de pago</td><td ' . $td . '>' . htmlspecialchars($metodo) . '</td></tr>
</table>

<div ' . $section . '>TERMINA TU COMPRA AHORA</div>
' . (strpos($metodo, 'SPEI') !== false || strpos($metodo, 'Transferencia') !== false
    ? '<p style="font-size:14px;color:#555;margin:12px 0 8px;">Se gener&oacute; tu referencia para transferencia bancaria. Realiza tu transferencia desde cualquier banco a:</p>'
    : '<p style="font-size:14px;color:#555;margin:12px 0 8px;">Tu referencia de pago ya est&aacute; generada.</p>') . '
' . $linkHtml . '

<div ' . $section . '>PAGO AUTOM&Aacute;TICO (IMPORTANTE)</div>
<p style="font-size:14px;color:#555;margin:12px 0 8px;">No necesitas enviar comprobantes.</p>
<div style="font-size:13px;color:#555;line-height:1.8;margin-bottom:24px;">
&#10004; Tu pago se acredita autom&aacute;ticamente<br>
&#10004; Tu orden se activa en cuanto se confirma<br>
&#10004; Recibir&aacute;s la confirmaci&oacute;n por correo y WhatsApp
</div>

<div ' . $section . '>LO QUE PASA AL PAGAR</div>
<div style="font-size:13px;color:#555;line-height:1.8;margin-bottom:24px;">
&#10004; Aseguras tu Voltika<br>
&#10004; Activamos tu proceso de entrega<br>
&#10004; Te asignamos punto autorizado en menos de 48 horas
</div>

<div style="background:#FFF8E1;border-radius:8px;padding:16px;margin-bottom:24px;border-left:4px solid #FFB300;">
<p style="margin:0 0 6px;font-size:14px;font-weight:700;color:#E65100;">&#9888; IMPORTANTE</p>
<p style="margin:0 0 6px;font-size:13px;color:#555;">Debido a la demanda, las unidades se asignan conforme se completan los pagos.</p>
<p style="margin:0;font-size:13px;color:#E53935;font-weight:700;">Tu reserva puede liberarse si no se confirma el pago.</p>
</div>

<div ' . $section . '>ACREDITACI&Oacute;N</div>
<div style="font-size:13px;color:#555;line-height:1.8;margin-bottom:24px;">
&bull; SPEI: hasta 24 horas<br>
&bull; OXXO: hasta 24 horas
</div>

<div ' . $section . '>ENTREGA SEGURA</div>
<p style="font-size:14px;color:#333;font-weight:700;margin:12px 0 8px;">&#128274; Tu n&uacute;mero celular es tu llave de entrega.</p>
<div style="font-size:13px;color:#555;line-height:1.8;margin-bottom:24px;">
Se te pedir&aacute;:<br>
&bull; C&oacute;digo de seguridad (OTP)<br>
&bull; Identificaci&oacute;n oficial<br>
&bull; Confirmaci&oacute;n de datos de tu compra
</div>

<div ' . $section . '>&iquest;TIENES DUDAS?</div>
<p style="font-size:14px;color:#555;margin:12px 0 8px;">Te ayudamos en este momento.</p>
<p style="font-size:14px;margin:0 0 4px;">&#128241; WhatsApp: <a href="https://wa.me/5213416370" style="color:#039fe1;font-weight:700;">' . $whatsapp . '</a></p>
<p style="font-size:14px;margin:0 0 24px;">&#128231; Correo: <a href="mailto:redes@voltika.mx" style="color:#039fe1;font-weight:700;">redes@voltika.mx</a></p>

<div style="background:#F5F5F5;border-radius:8px;padding:16px;">
<p style="font-size:12px;margin:0 0 4px;"><a href="https://voltika.mx/docs/tyc_2026.pdf" style="color:#039fe1;">T&eacute;rminos y Condiciones</a></p>
<p style="font-size:12px;margin:0 0 8px;"><a href="https://voltika.mx/docs/privacidad_2026.pdf" style="color:#039fe1;">Aviso de Privacidad</a></p>
<p style="font-size:11px;color:#aaa;margin:0;">Al iniciar tu compra aceptaste estas condiciones.</p>
</div>

</td></tr>

<tr><td style="background:#1a3a5c;padding:20px 28px;text-align:center;">
<img src="https://www.voltika.mx/configurador_prueba/img/goelectric.svg" alt="GO electric" style="height:28px;width:auto;margin-bottom:8px;">
<p style="margin:0 0 4px;font-size:14px;font-weight:700;color:#fff;">Voltika M&eacute;xico</p>
<p style="margin:0;font-size:12px;color:rgba(255,255,255,0.6);">Mu&eacute;vete a el&eacute;ctrico &middot; Mtech Gears, S.A. de C.V.</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>';

    $asunto = 'Tu Voltika te está esperando! Completa tu pago Orden #' . $pedidoNum;
    try {
        sendMail($email, $nombre, $asunto, $body);
    } catch (Exception $e) {
        error_log('Voltika reminder email error: ' . $e->getMessage());
    }
}

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
            'nombre'   => $customer['nombre']    ?? '',
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

        $response = ['clientSecret' => $intent->client_secret, 'paymentIntentId' => $intent->id];

        // Extract bank transfer details
        if ($intent->next_action && isset($intent->next_action->display_bank_transfer_instructions)) {
            $bankInfo = $intent->next_action->display_bank_transfer_instructions;
            $addresses = $bankInfo->financial_addresses ?? [];
            $clabe = '';
            foreach ($addresses as $addr) {
                // Try different CLABE property paths
                if (isset($addr->spei_clabe->clabe)) {
                    $clabe = $addr->spei_clabe->clabe;
                    break;
                } elseif (isset($addr->clabe)) {
                    $clabe = $addr->clabe;
                    break;
                } elseif (isset($addr->spei->clabe)) {
                    $clabe = $addr->spei->clabe;
                    break;
                }
            }
            // If still no CLABE, try to get from the full object
            if (empty($clabe) && !empty($addresses)) {
                $firstAddr = json_decode(json_encode($addresses[0]), true);
                error_log('SPEI address structure: ' . json_encode($firstAddr));
                // Search recursively for any 18-digit number (CLABE format)
                array_walk_recursive($firstAddr, function($value) use (&$clabe) {
                    if (is_string($value) && preg_match('/^\d{18}$/', $value)) {
                        $clabe = $value;
                    }
                });
            }
            $response['speiData'] = [
                'clabe'        => $clabe,
                'banco'        => !empty($bankInfo->hosted_instructions_url) ? 'Stripe' : 'STP',
                'beneficiario' => 'MTECH GEARS S.A. DE C.V.',
                'referencia'   => $bankInfo->reference ?? '',
                'amount'       => $amount
            ];
        }

        // Send SPEI reminder email
        $custEmail = $customer['email'] ?? '';
        $custNombre = trim(($customer['nombre'] ?? '') . ' ' . ($customer['apellidos'] ?? ''));
        if ($custEmail) {
            $speiInfo = [
                'clabe'        => $clabe ?: '',
                'referencia'   => $bankInfo->reference ?? '',
                'beneficiario' => 'MTECH GEARS S.A. DE C.V.',
                'banco'        => !empty($bankInfo->hosted_instructions_url) ? 'Stripe' : 'STP'
            ];
            _sendReminderEmail($custEmail, $custNombre, $customer, $amount / 100, 'Transferencia SPEI', $speiInfo);
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
                    'hosted_voucher_url' => $oxxo->hosted_voucher_url ?? '',
                    'paymentIntentId'    => $intent->id
                ];
            }
        }

        $response = [
            'oxxoData' => $oxxoRefs,
            'totalRefs' => count($oxxoRefs),
            'paymentIntentId' => !empty($oxxoRefs) ? $oxxoRefs[0]['paymentIntentId'] : ''
        ];

        // Send OXXO reminder email with full reference data
        $custEmail = $customer['email'] ?? '';
        $custNombre = trim(($customer['nombre'] ?? '') . ' ' . ($customer['apellidos'] ?? ''));
        if ($custEmail) {
            _sendReminderEmail($custEmail, $custNombre, $customer, $amount / 100, 'Pago en OXXO', ['oxxoRefs' => $oxxoRefs]);
        }

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
