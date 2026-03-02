<?php
/**
 * Voltika - Consultar Círculo de Crédito
 * Reporte de Crédito MX (Sandbox)
 * Docs: developer.circulodecredito.com.mx
 *
 * POST body (JSON):
 *   primerNombre       – Nombre(s)
 *   apellidoPaterno    – Apellido paterno
 *   apellidoMaterno    – Apellido materno
 *   fechaNacimiento    – YYYY-MM-DD
 *   CP                 – Código postal 5 dígitos
 *   RFC                – (opcional) RFC
 *   CURP               – (opcional) CURP
 *   direccion          – (opcional) Calle y número
 *   colonia            – (opcional) Colonia
 *   municipio          – (opcional) Municipio
 *   ciudad             – (opcional) Ciudad
 *   estado             – (opcional) Estado (2-3 letras)
 *
 * Devuelve: score, pago_mensual_buro, dpd90_flag, dpd_max
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Configuración Círculo de Crédito (Sandbox) ─────────────────────────────
define('CDC_API_KEY',  '5WdpF9Eqw7925TFAosGKifwkZ7nDuNUN');
define('CDC_BASE_URL', 'https://services.circulodecredito.com.mx/sandbox/v2/rcc/ficoscore');
define('CDC_FOLIO',    '0000080008');  // Folio otorgante de prueba

session_start();

// ── Request ─────────────────────────────────────────────────────────────────
$json = json_decode(file_get_contents('php://input'), true);
if (!$json) {
    http_response_code(400);
    echo json_encode(['error' => 'Request inválido']);
    exit;
}

$primerNombre    = strtoupper(trim($json['primerNombre'] ?? ''));
$apellidoPaterno = strtoupper(trim($json['apellidoPaterno'] ?? ''));
$apellidoMaterno = strtoupper(trim($json['apellidoMaterno'] ?? ''));
$fechaNacimiento = trim($json['fechaNacimiento'] ?? '');
$cp              = trim($json['CP'] ?? '');
$rfc             = strtoupper(trim($json['RFC'] ?? ''));
$curp            = strtoupper(trim($json['CURP'] ?? ''));
$direccion       = strtoupper(trim($json['direccion'] ?? ''));
$colonia         = strtoupper(trim($json['colonia'] ?? ''));
$municipio       = strtoupper(trim($json['municipio'] ?? ''));
$ciudad          = strtoupper(trim($json['ciudad'] ?? ''));
$estado          = strtoupper(trim($json['estado'] ?? ''));

if (!$primerNombre || !$apellidoPaterno) {
    http_response_code(400);
    echo json_encode(['error' => 'Nombre y apellido paterno son requeridos']);
    exit;
}

// ── Construir request body ──────────────────────────────────────────────────
$requestBody = [
    'folioOtorgante' => CDC_FOLIO,
    'persona' => [
        'primerNombre'    => $primerNombre,
        'segundoNombre'   => '',
        'apellidoPaterno' => $apellidoPaterno,
        'apellidoMaterno' => $apellidoMaterno,
        'fechaNacimiento' => $fechaNacimiento,
        'RFC'             => $rfc,
        'CURP'            => $curp,
        'nacionalidad'    => 'MX',
        'domicilio' => [
            'direccion'           => $direccion ?: 'NO DISPONIBLE',
            'coloniaPoblacion'    => $colonia ?: 'CENTRO',
            'delegacionMunicipio' => $municipio ?: $ciudad ?: 'NO DISPONIBLE',
            'ciudad'              => $ciudad ?: 'NO DISPONIBLE',
            'estado'              => $estado ?: 'MX',
            'CP'                  => $cp,
        ],
    ],
];

$jsonBody = json_encode($requestBody, JSON_UNESCAPED_UNICODE);

// ── Llamada a la API ────────────────────────────────────────────────────────
$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'x-api-key: ' . CDC_API_KEY,
];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => CDC_BASE_URL,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $jsonBody,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

// ── Logging ─────────────────────────────────────────────────────────────────
$logFile = __DIR__ . '/logs/circulo-credito.log';
if (!is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}
file_put_contents($logFile, json_encode([
    'timestamp' => date('c'),
    'nombre'    => $primerNombre . ' ' . $apellidoPaterno,
    'httpCode'  => $httpCode,
    'curlErr'   => $curlErr,
    'response'  => substr($response, 0, 500),
]) . "\n", FILE_APPEND | LOCK_EX);

// ── Evaluar respuesta ───────────────────────────────────────────────────────
if ($curlErr || $httpCode < 200 || $httpCode >= 300) {
    // API error → fallback: sin datos de Círculo
    $_SESSION['cdc_score']             = null;
    $_SESSION['cdc_pago_mensual_buro'] = 0;
    $_SESSION['cdc_dpd90_flag']        = null;
    $_SESSION['cdc_dpd_max']           = null;

    echo json_encode([
        'success'           => false,
        'fallback'          => true,
        'score'             => null,
        'pago_mensual_buro' => 0,
        'dpd90_flag'        => null,
        'dpd_max'           => null,
        'message'           => 'Sin datos de Círculo — evaluación estimada',
    ]);
    exit;
}

$data = json_decode($response, true);
if (!$data) {
    $_SESSION['cdc_score'] = null;
    echo json_encode([
        'success'  => false,
        'fallback' => true,
        'score'    => null,
        'message'  => 'No se pudo interpretar la respuesta de Círculo de Crédito',
    ]);
    exit;
}

// ── Extraer datos para preaprobación V3 ─────────────────────────────────────
$result = extractPreaprobacionData($data);

// Guardar en sesión para que preaprobacion-v3.php los use
$_SESSION['cdc_score']             = $result['score'];
$_SESSION['cdc_pago_mensual_buro'] = $result['pago_mensual_buro'];
$_SESSION['cdc_dpd90_flag']        = $result['dpd90_flag'];
$_SESSION['cdc_dpd_max']           = $result['dpd_max'];
$_SESSION['cdc_folio_consulta']    = $result['folioConsulta'];

echo json_encode($result);

// ── Funciones auxiliares ────────────────────────────────────────────────────

function extractPreaprobacionData(array $response): array {

    // 1. Score de crédito
    $score = null;
    if (!empty($response['scores'])) {
        foreach ($response['scores'] as $s) {
            $score = intval($s['valor'] ?? 0);
            break; // Tomar el primer (principal) score
        }
    }

    // 2. Pago mensual total en buró (suma de cuentas abiertas)
    $pagoMensualBuro = 0;
    $cuentas = $response['cuentas'] ?? [];
    foreach ($cuentas as $cuenta) {
        // Solo cuentas abiertas
        if (!empty($cuenta['fechaCierreCuenta'])) continue;
        $pagoMensualBuro += floatval($cuenta['montoPagar'] ?? 0);
    }

    // 3. DPD 90+ flag y Max DPD
    $dpd90Flag = false;
    $dpdMax    = 0;

    foreach ($cuentas as $cuenta) {
        // peorAtraso en días
        $peorAtraso = intval($cuenta['peorAtraso'] ?? 0);
        if ($peorAtraso > $dpdMax) {
            $dpdMax = $peorAtraso;
        }

        // Parsear historicoPagos (24 meses)
        // 1=al corriente, 2=30DPD, 3=60DPD, 4=90DPD, 5=120DPD, etc.
        $historico = $cuenta['historicoPagos'] ?? '';
        for ($i = 0; $i < strlen($historico); $i++) {
            $ch = $historico[$i];
            if (is_numeric($ch) && intval($ch) > 1) {
                $dpdDays = (intval($ch) - 1) * 30;
                if ($dpdDays > $dpdMax) {
                    $dpdMax = $dpdDays;
                }
            }
            // Códigos de mora severa
            if (in_array($ch, ['U', 'R', 'Y'])) {
                $dpd90Flag = true;
            }
        }

        // Contadores DPD directos
        if (isset($cuenta['DPD']) && ($cuenta['DPD']['dpd90'] ?? 0) > 0) {
            $dpd90Flag = true;
        }
    }

    if ($dpdMax >= 90) {
        $dpd90Flag = true;
    }

    return [
        'success'           => true,
        'score'             => $score,
        'pago_mensual_buro' => round($pagoMensualBuro, 2),
        'dpd90_flag'        => $dpd90Flag,
        'dpd_max'           => $dpdMax,
        'num_cuentas'       => count($cuentas),
        'folioConsulta'     => $response['folioConsulta'] ?? null,
    ];
}
