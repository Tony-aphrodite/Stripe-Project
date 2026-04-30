<?php
/**
 * Voltika — Envia.com API Integration
 * Creates shipments and returns tracking numbers via envia.com
 *
 * Usage:
 *   require_once __DIR__ . '/envia-api.php';
 *   $result = enviaCrearEnvio($destino, $paquete, $pedidoNum);
 *
 * Requires in .env:
 *   ENVIA_API_KEY=your_api_key_here
 *   ENVIA_CARRIER=estafeta         (estafeta | dhl | fedex | redpack | etc.)
 *   ENVIA_SERVICE=standard
 *   CEDIS_NOMBRE=Voltika CEDIS
 *   CEDIS_CALLE=Av. Insurgentes
 *   CEDIS_NUMERO=1234
 *   CEDIS_CIUDAD=Ciudad de México
 *   CEDIS_ESTADO=CDMX
 *   CEDIS_CP=06600
 *   CEDIS_TELEFONO=5512345678
 *
 * Destination punto must have: nombre, calle, numero, ciudad, estado, cp, telefono
 */

/**
 * Create a shipment on envia.com and return tracking info.
 *
 * @param array  $destino    ['nombre','calle','numero','ciudad','estado','cp','telefono','email?','punto_nombre?']
 * @param array  $paquete    ['peso','largo','ancho','alto','valor?']  (kg and cm)
 * @param string $pedidoNum  Purchase order number for description
 * @return array ['ok'=>bool, 'tracking_number'=>string, 'tracking_url'=>string, 'label_url'=>string, 'error'=>string?]
 */
function enviaCrearEnvio(array $destino, array $paquete = [], string $pedidoNum = ''): array
{
    if (!ENVIA_API_KEY) {
        return ['ok' => false, 'error' => 'envia.com API key no configurado (ENVIA_API_KEY)'];
    }

    // Validate CEDIS origin address
    if (!CEDIS_CALLE || !CEDIS_CP) {
        return ['ok' => false, 'error' => 'Dirección CEDIS no configurada (CEDIS_CALLE, CEDIS_CP en .env)'];
    }

    $payload = [
        'carrier' => ENVIA_CARRIER,
        'service' => ENVIA_SERVICE,
        'shipment' => [
            'generate_document' => true,
            'items_type'        => 'box',
            'origin' => [
                'name'    => CEDIS_NOMBRE,
                'company' => 'Voltika México',
                'phone'   => CEDIS_TELEFONO,
                'email'   => SMTP_USER,
                'address' => [
                    'street1' => CEDIS_CALLE,
                    'number'  => CEDIS_NUMERO,
                    'city'    => CEDIS_CIUDAD,
                    'state'   => CEDIS_ESTADO,
                    'country' => 'MX',
                    'zipcode' => CEDIS_CP,
                ],
            ],
            'destination' => [
                'name'    => $destino['nombre']      ?? 'Punto Voltika',
                'company' => $destino['punto_nombre'] ?? '',
                'phone'   => $destino['telefono']    ?? CEDIS_TELEFONO,
                'email'   => $destino['email']       ?? '',
                'address' => [
                    'street1' => $destino['calle']   ?? '',
                    'number'  => $destino['numero']  ?? 'S/N',
                    'city'    => $destino['ciudad']  ?? '',
                    'state'   => $destino['estado']  ?? '',
                    'country' => 'MX',
                    'zipcode' => $destino['cp']      ?? '',
                ],
            ],
            'packages' => [[
                'weight'         => $paquete['peso']  ?? 120,   // kg (motorcycle ~120kg)
                'length'         => $paquete['largo'] ?? 180,   // cm
                'width'          => $paquete['ancho'] ?? 80,    // cm
                'height'         => $paquete['alto']  ?? 120,   // cm
                'declared_value' => $paquete['valor'] ?? 0,
                'description'    => 'Motocicleta eléctrica Voltika' . ($pedidoNum ? " — Pedido $pedidoNum" : ''),
                'content'        => 'motorcycle',
            ]],
        ],
    ];

    $ch = curl_init('https://api.envia.com/ship/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . ENVIA_API_KEY,
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log('Voltika envia.com cURL error: ' . $curlErr);
        return ['ok' => false, 'error' => 'Error de conexión con envia.com: ' . $curlErr];
    }

    $data = json_decode($response, true);

    // envia.com returns { "data": [...], "meta": {...} } on success
    if ($httpCode !== 200 || empty($data['data'])) {
        $msg = $data['message'] ?? ($data['errors'][0]['detail'] ?? 'Error desconocido');
        error_log('Voltika envia.com error [' . $httpCode . ']: ' . $msg . ' | Body: ' . $response);
        return ['ok' => false, 'error' => 'envia.com: ' . $msg];
    }

    $shipment       = $data['data'][0] ?? [];
    $trackingNumber = $shipment['trackingNumber']      ?? ($shipment['tracking_number']      ?? '');
    $trackingUrl    = $shipment['trackingUrl']         ?? ($shipment['tracking_url']         ?? '');
    $labelUrl       = $shipment['label']               ?? ($shipment['label_url']            ?? '');

    // Estimated delivery date — envia.com returns this in several possible fields
    $estimatedDate  = $shipment['estimatedDeliveryDate'] ?? ($shipment['estimated_delivery_date']
                   ?? ($shipment['deliveryDate']          ?? ($shipment['delivery_date']      ?? '')));

    // Normalize to Y-m-d if present (envia.com may return ISO 8601 or d/m/Y)
    if ($estimatedDate) {
        $ts = strtotime($estimatedDate);
        $estimatedDate = $ts ? date('Y-m-d', $ts) : '';
    }

    // Fallback tracking URL if not provided
    if (!$trackingUrl && $trackingNumber) {
        $trackingUrl = 'https://envia.com/rastreo/' . urlencode($trackingNumber);
    }

    error_log('Voltika envia.com shipment created: ' . $trackingNumber . ' ETA: ' . ($estimatedDate ?: '—') . ' pedido: ' . $pedidoNum);

    return [
        'ok'                     => true,
        'tracking_number'        => $trackingNumber,
        'tracking_url'           => $trackingUrl,
        'label_url'              => $labelUrl,
        'estimated_delivery_date'=> $estimatedDate,
        'carrier'                => $shipment['carrier'] ?? ENVIA_CARRIER,
        'raw'                    => $shipment,
    ];
}

/**
 * Get shipment status from envia.com by tracking number.
 *
 * @param string $trackingNumber
 * @return array ['ok'=>bool, 'status'=>string, 'events'=>array, 'error'=>string?]
 */
function enviaRastrear(string $trackingNumber): array
{
    if (!ENVIA_API_KEY || !$trackingNumber) {
        return ['ok' => false, 'error' => 'API key o tracking number no disponibles'];
    }

    $url = 'https://api.envia.com/ship/' . urlencode($trackingNumber) . '/track/';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . ENVIA_API_KEY],
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if ($httpCode !== 200 || empty($data['data'])) {
        return ['ok' => false, 'error' => 'No se pudo rastrear el envío'];
    }

    $track = $data['data'][0] ?? [];
    return [
        'ok'     => true,
        'status' => $track['status']       ?? '—',
        'events' => $track['checkpoints']  ?? [],
    ];
}
