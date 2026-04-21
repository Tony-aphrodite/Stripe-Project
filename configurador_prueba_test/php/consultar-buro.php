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

// ── Central config ───────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';

// CDC endpoint — production /v2/ficoscore (standalone FICO Score product).
// Discovered empirically: this is the endpoint where our Voltika Consumer
// Key has a working subscription. The body must use top-level "folio" +
// "persona" (NOT the older "folioOtorgante" name).
// Override via the CDC_BASE_URL env var if the customer moves to a different
// product (e.g. Reporte de Crédito Consolidado + FICO Score).
define('CDC_BASE_URL', getenv('CDC_BASE_URL') ?: 'https://services.circulodecredito.com.mx/v2/ficoscore');
// Folio otorgante — 10-digit id assigned by CDC (0000004694 for Voltika).
define('CDC_FOLIO', getenv('CDC_FOLIO') ?: '0000080008');
// CDC production auth model (confirmed via the official PHP client source):
//   - username and password go as CUSTOM headers, NOT HTTP Basic Auth
//   - x-api-key carries the Consumer Key from the CDC developer portal
//   - x-signature carries the SHA256 hash of the request body, HEX encoded
if (!defined('CDC_USER')) define('CDC_USER', getenv('CDC_USER') ?: '');
if (!defined('CDC_PASS')) define('CDC_PASS', getenv('CDC_PASS') ?: '');

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

// NIP-CIEC extras (Phase A)
$tipoConsulta             = strtoupper(trim($json['tipo_consulta'] ?? 'PF'));
$fechaAprobacionConsulta  = trim($json['fecha_aprobacion_consulta'] ?? '');
$horaAprobacionConsulta   = trim($json['hora_aprobacion_consulta']  ?? '');
if (!$fechaAprobacionConsulta) $fechaAprobacionConsulta = date('Y-m-d');
if (!$horaAprobacionConsulta)  $horaAprobacionConsulta  = date('H:i:s');

// NIP-CIEC Phase B: consent flags + query timestamps
$ingresoNipCiec    = strtoupper(trim($json['ingreso_nip_ciec'] ?? 'SI'));
$respuestaLeyenda  = strtoupper(trim($json['respuesta_leyenda'] ?? 'SI'));
$aceptacionTyc     = strtoupper(trim($json['aceptacion_tyc'] ?? 'SI'));
$fechaConsulta     = date('Y-m-d');   // actual API call date
$horaConsulta      = date('H:i:s');   // actual API call time

if (!$primerNombre || !$apellidoPaterno) {
    http_response_code(400);
    echo json_encode(['error' => 'Nombre y apellido paterno son requeridos']);
    exit;
}

// ── Construir request body ──────────────────────────────────────────────────
// The /v2/ficoscore endpoint expects a top-level "folio" field (not the older
// "folioOtorgante") plus a nested "persona" object.
$requestBody = [
    'folio'   => CDC_FOLIO,
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

// ── Sign the body with our private key (x-signature header) ────────────────
// CDC production requires the body to be SHA256-signed with our private key
// and the signature passed as a HEX-encoded x-signature header. The matching
// public certificate must already be uploaded to the CDC developer portal.
$signatureHex = '';
$keyPem = $_SESSION['cdc_key_pem'] ?? null;
if (!$keyPem) {
    $keyFile = __DIR__ . '/certs/cdc_private.key';
    if (file_exists($keyFile)) $keyPem = @file_get_contents($keyFile);
}
if ($keyPem) {
    $priv = openssl_pkey_get_private($keyPem);
    if ($priv) {
        $sig = '';
        if (openssl_sign($jsonBody, $sig, $priv, OPENSSL_ALGO_SHA256)) {
            $signatureHex = bin2hex($sig);
        }
    }
}

// ── Llamada a la API ────────────────────────────────────────────────────────
// Auth per CDC production spec: x-api-key + username/password as custom
// headers (NOT HTTP Basic Auth) + x-signature hex.
$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'x-api-key: ' . CDC_API_KEY,
];
if (CDC_USER)      $headers[] = 'username: ' . CDC_USER;
if (CDC_PASS)      $headers[] = 'password: ' . CDC_PASS;
if ($signatureHex) $headers[] = 'x-signature: ' . $signatureHex;

$ch = curl_init();
$curlOpts = [
    CURLOPT_URL            => CDC_BASE_URL,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $jsonBody,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
];
curl_setopt_array($ch, $curlOpts);

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

// ── Guardar en BD ─────────────────────────────────────────────────────────────
try {
    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS consultas_buro (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        nombre           VARCHAR(200),
        apellido_paterno VARCHAR(100),
        apellido_materno VARCHAR(100),
        fecha_nacimiento VARCHAR(20),
        cp               VARCHAR(10),
        score            INT,
        pago_mensual     DECIMAL(12,2),
        dpd90_flag       TINYINT(1),
        dpd_max          INT,
        num_cuentas      INT,
        folio_consulta   VARCHAR(100),
        freg             DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Idempotent column additions for NIP-CIEC compliance (Phase A)
    ensureConsultasBuroColumns($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO consultas_buro
            (nombre, apellido_paterno, apellido_materno, fecha_nacimiento, cp,
             score, pago_mensual, dpd90_flag, dpd_max, num_cuentas, folio_consulta,
             rfc, curp, calle_numero, colonia, municipio, ciudad, estado,
             tipo_consulta, fecha_aprobacion_consulta, hora_aprobacion_consulta,
             fecha_consulta, hora_consulta, usuario_api,
             ingreso_nip_ciec, respuesta_leyenda, aceptacion_tyc)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $primerNombre, $apellidoPaterno, $apellidoMaterno, $fechaNacimiento, $cp,
        $result['score'], $result['pago_mensual_buro'],
        $result['dpd90_flag'] ? 1 : 0,
        $result['dpd_max'], $result['num_cuentas'],
        $result['folioConsulta'],
        $rfc, $curp, $direccion, $colonia, $municipio, $ciudad, $estado,
        $tipoConsulta, $fechaAprobacionConsulta, $horaAprobacionConsulta,
        $fechaConsulta, $horaConsulta, CDC_FOLIO,
        $ingresoNipCiec, $respuestaLeyenda, $aceptacionTyc,
    ]);
} catch (PDOException $e) {
    error_log('Voltika consultas_buro DB error: ' . $e->getMessage());
}

echo json_encode($result);

// ── Funciones auxiliares ────────────────────────────────────────────────────

/**
 * Idempotently add NIP-CIEC compliance columns to consultas_buro.
 * Safe to call on every request — each ALTER wrapped in try/catch.
 */
function ensureConsultasBuroColumns(PDO $pdo): void {
    $cols = [
        'rfc'                       => "VARCHAR(20) NULL",
        'curp'                      => "VARCHAR(20) NULL",
        'calle_numero'              => "VARCHAR(200) NULL",
        'colonia'                   => "VARCHAR(150) NULL",
        'municipio'                 => "VARCHAR(150) NULL",
        'ciudad'                    => "VARCHAR(100) NULL",
        'estado'                    => "VARCHAR(10) NULL",
        'tipo_consulta'             => "VARCHAR(5) NOT NULL DEFAULT 'PF'",
        'fecha_aprobacion_consulta' => "DATE NULL",
        'hora_aprobacion_consulta'  => "TIME NULL",
        'fecha_consulta'            => "DATE NULL",
        'hora_consulta'             => "TIME NULL",
        'usuario_api'               => "VARCHAR(100) NULL",
        'ingreso_nip_ciec'          => "VARCHAR(5) DEFAULT 'SI'",
        'respuesta_leyenda'         => "VARCHAR(5) DEFAULT 'SI'",
        'aceptacion_tyc'            => "VARCHAR(5) DEFAULT 'SI'",
    ];
    try {
        $existing = [];
        $rs = $pdo->query("SHOW COLUMNS FROM consultas_buro");
        foreach ($rs as $row) { $existing[strtolower($row['Field'])] = true; }
        foreach ($cols as $name => $def) {
            if (!isset($existing[$name])) {
                try { $pdo->exec("ALTER TABLE consultas_buro ADD COLUMN `$name` $def"); }
                catch (PDOException $e) { error_log("ensureConsultasBuroColumns $name: " . $e->getMessage()); }
            }
        }
    } catch (PDOException $e) {
        error_log('ensureConsultasBuroColumns: ' . $e->getMessage());
    }
}

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
