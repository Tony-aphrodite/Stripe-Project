<?php
/**
 * Skydropx API helper — shipping quotations
 * Docs: https://docs.skydropx.com/
 *
 * Usage:
 *   require_once __DIR__ . '/skydropx.php';
 *   $result = skydropxCotizar($cpOrigen, $cpDestino);
 *   // $result = ['ok'=>true, 'dias'=>3, 'fecha_estimada'=>'2026-04-15', 'carrier'=>'Estafeta', ...]
 */

/**
 * Get shipping quote between two zip codes.
 * Returns the fastest option with estimated delivery days.
 */
function skydropxCotizar(string $cpOrigen, string $cpDestino): array {
    $apiKey = defined('SKYDROPX_API_KEY') ? SKYDROPX_API_KEY : '';
    if (!$apiKey) {
        return ['ok' => false, 'error' => 'SKYDROPX_API_KEY not configured'];
    }
    if (!$cpOrigen || !$cpDestino) {
        return ['ok' => false, 'error' => 'Códigos postales requeridos'];
    }

    $payload = [
        'zip_from' => $cpOrigen,
        'zip_to'   => $cpDestino,
        'parcel'   => [
            'weight' => defined('SKYDROPX_PARCEL_WEIGHT') ? SKYDROPX_PARCEL_WEIGHT : 150,
            'height' => defined('SKYDROPX_PARCEL_HEIGHT') ? SKYDROPX_PARCEL_HEIGHT : 120,
            'width'  => defined('SKYDROPX_PARCEL_WIDTH')  ? SKYDROPX_PARCEL_WIDTH  : 80,
            'length' => defined('SKYDROPX_PARCEL_LENGTH') ? SKYDROPX_PARCEL_LENGTH : 200,
        ],
    ];

    $ch = curl_init('https://api.skydropx.com/v1/quotations');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Token token=' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log('Skydropx curl error: ' . $err);
        return ['ok' => false, 'error' => 'Error de conexión con Skydropx'];
    }

    $data = json_decode($raw, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log('Skydropx HTTP ' . $httpCode . ': ' . $raw);
        return ['ok' => false, 'error' => 'Skydropx error HTTP ' . $httpCode];
    }

    // Parse rates — find the fastest option
    $rates = $data['included'] ?? [];
    $best = null;
    foreach ($rates as $item) {
        if (($item['type'] ?? '') !== 'rates') continue;
        $attrs = $item['attributes'] ?? [];
        $days  = (int)($attrs['days'] ?? $attrs['estimated_delivery'] ?? 99);
        if ($best === null || $days < $best['dias']) {
            $best = [
                'dias'       => $days,
                'carrier'    => $attrs['provider'] ?? $attrs['carrier'] ?? '?',
                'servicio'   => $attrs['service_level_name'] ?? $attrs['service'] ?? '',
                'precio'     => (float)($attrs['total_pricing'] ?? $attrs['amount'] ?? 0),
                'rate_id'    => $item['id'] ?? null,
            ];
        }
    }

    if (!$best) {
        // Fallback: check data.attributes.rates if different structure
        $topRates = $data['data']['attributes']['rates'] ?? $data['rates'] ?? [];
        foreach ($topRates as $r) {
            $days = (int)($r['days'] ?? $r['estimated_delivery'] ?? 99);
            if ($best === null || $days < $best['dias']) {
                $best = [
                    'dias'     => $days,
                    'carrier'  => $r['provider'] ?? $r['carrier'] ?? '?',
                    'servicio' => $r['service_level_name'] ?? $r['service'] ?? '',
                    'precio'   => (float)($r['total_pricing'] ?? $r['amount'] ?? 0),
                    'rate_id'  => $r['id'] ?? null,
                ];
            }
        }
    }

    if (!$best) {
        return ['ok' => false, 'error' => 'No se encontraron tarifas disponibles'];
    }

    // Calculate estimated date
    $fecha = date('Y-m-d', strtotime('+' . $best['dias'] . ' weekdays'));

    return [
        'ok'              => true,
        'dias'            => $best['dias'],
        'fecha_estimada'  => $fecha,
        'carrier'         => $best['carrier'],
        'servicio'        => $best['servicio'],
        'precio'          => $best['precio'],
        'rate_id'         => $best['rate_id'],
        'cp_origen'       => $cpOrigen,
        'cp_destino'      => $cpDestino,
    ];
}
