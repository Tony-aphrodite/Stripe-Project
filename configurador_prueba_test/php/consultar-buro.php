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

// CDC endpoint — /v2/rccficoscore (Reporte de Crédito Consolidado + FICO
// Score V2). This is the product the Voltika app has activated, confirmed
// by the customer's subscription screenshot and the v2 swagger at:
//   /sites/default/files/swagger/1730437066/reporte-de-credito-consolidado-fico-score-v2-1-swagger.2.1.2.yaml
// Body schema (FLAT, no wrapper):
//   primerNombre + apellidoPaterno + apellidoMaterno + fechaNacimiento +
//   RFC (uppercase) + nacionalidad + domicilio{direccion, coloniaPoblacion,
//   delegacionMunicipio, ciudad, estado (enum), CP}
// Override via the CDC_BASE_URL env var if the customer changes product.
define('CDC_BASE_URL', getenv('CDC_BASE_URL') ?: 'https://services.circulodecredito.com.mx/v2/rccficoscore');
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

// ── Normalize everything to ASCII-only uppercase ──────────────────────────
// CDC v2 is strict about the signed body — accents (ñ, á, é) or mixed case
// can cause 503 "signature mismatch" or 400 validation. Collapse everything
// to ASCII uppercase before signing.
$primerNombre    = cdcAscii($primerNombre);
$apellidoPaterno = cdcAscii($apellidoPaterno);
$apellidoMaterno = cdcAscii($apellidoMaterno);
$direccion       = cdcAscii($direccion);
$colonia         = cdcAscii($colonia);
$municipio       = cdcAscii($municipio);
$ciudad          = cdcAscii($ciudad);

// ── RFC: auto-compute if not provided ─────────────────────────────────────
// The credit-check step runs BEFORE the facturación step where the user
// enters their RFC. Compute the 10-char SAT RFC from their name+DOB so
// CDC has a valid-looking RFC. (Homoclave is added only if absent.)
if (!$rfc || strlen($rfc) < 10) {
    $rfc = cdcComputeRFC($primerNombre, $apellidoPaterno, $apellidoMaterno, $fechaNacimiento);
}
// Pad to 13 chars with a placeholder homoclave if still short — CDC v2
// sometimes rejects 10-char form. XXX is the conventional placeholder.
if (strlen($rfc) === 10) $rfc .= 'XXX';

// ── estado: normalize to CDC CatalogoEstados v2 enum ──────────────────────
$estadoNorm = cdcEstadoEnum($estado);

// ── Construir request body ──────────────────────────────────────────────────
// Body schema for /v2/rccficoscore (swagger v2.1.2):
//   - FLAT (no folio/persona wrapper)
//   - primerNombre singular + RFC UPPERCASE + nacionalidad required
//   - domicilio uses coloniaPoblacion / delegacionMunicipio / CP
$requestBody = [
    'primerNombre'    => $primerNombre,
    'apellidoPaterno' => $apellidoPaterno,
    'apellidoMaterno' => $apellidoMaterno ?: 'X',
    'fechaNacimiento' => $fechaNacimiento,
    'RFC'             => $rfc,
    'nacionalidad'    => 'MX',
    'domicilio' => [
        'direccion'           => $direccion ?: 'NO DISPONIBLE',
        'coloniaPoblacion'    => $colonia ?: 'CENTRO',
        'delegacionMunicipio' => $municipio ?: $ciudad ?: 'NO DISPONIBLE',
        'ciudad'              => $ciudad ?: 'NO DISPONIBLE',
        'estado'              => $estadoNorm,
        'CP'                  => $cp ?: '00000',
    ],
];
if ($curp) $requestBody['CURP'] = $curp;

$jsonBody = json_encode($requestBody, JSON_UNESCAPED_UNICODE);

// ── Load our private key (required for x-signature) ────────────────────────
// Resolution order: session → certs/cdc_private.key on disk. The key is
// generated by generar-certificado-cdc.php and must be present on the server
// BEFORE the credit flow runs (customers' sessions never have it).
$keyPem = $_SESSION['cdc_key_pem'] ?? null;
$keyFile = __DIR__ . '/certs/cdc_private.key';
if (!$keyPem && file_exists($keyFile)) $keyPem = @file_get_contents($keyFile);

$certPem = $_SESSION['cdc_cert_pem'] ?? null;
$certFile = __DIR__ . '/certs/cdc_certificate.pem';
if (!$certPem && file_exists($certFile)) $certPem = @file_get_contents($certFile);

// Hard-fail if key is missing — sending an empty x-signature is guaranteed
// to return 403/503 from CDC and previously looked like a transient error.
if (!$keyPem) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'CDC private key no está presente en el servidor',
        'hint'    => 'Abre generar-certificado-cdc.php?key=voltika_cdc_cert_2026 y confirma que certs/cdc_private.key existe con permisos 600.',
    ]);
    exit;
}

// ── Sign the body ──────────────────────────────────────────────────────────
$signatureHex = '';
$priv = openssl_pkey_get_private($keyPem);
if (!$priv) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'No se pudo parsear la llave privada',
        'openssl' => openssl_error_string(),
    ]);
    exit;
}
$sig = '';
if (openssl_sign($jsonBody, $sig, $priv, OPENSSL_ALGO_SHA256)) {
    $signatureHex = bin2hex($sig);
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

// Mutual TLS — many CDC v2 products require the client certificate at the
// TLS layer in addition to the x-signature header. Attaching both is
// harmless (Apigee ignores mTLS when not enforced). Without this, some
// CDC products return 503 with an empty body.
$tmpCert = null; $tmpKey = null;
if ($certPem && $keyPem) {
    $tmpCert = tempnam(sys_get_temp_dir(), 'cdc_cert_');
    $tmpKey  = tempnam(sys_get_temp_dir(), 'cdc_key_');
    file_put_contents($tmpCert, $certPem);
    file_put_contents($tmpKey,  $keyPem);
    $curlOpts[CURLOPT_SSLCERT] = $tmpCert;
    $curlOpts[CURLOPT_SSLKEY]  = $tmpKey;
}
curl_setopt_array($ch, $curlOpts);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);
if ($tmpCert) @unlink($tmpCert);
if ($tmpKey)  @unlink($tmpKey);

// ── Logging ─────────────────────────────────────────────────────────────────
$logFile = __DIR__ . '/logs/circulo-credito.log';
if (!is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}
file_put_contents($logFile, json_encode([
    'timestamp' => date('c'),
    'nombre'    => $primerNombre . ' ' . $apellidoPaterno,
    'rfc_used'  => $rfc,
    'estado'    => $estadoNorm,
    'cp'        => $cp,
    'has_sig'   => $signatureHex !== '',
    'sig_len'   => strlen($signatureHex),
    'body_sent' => substr($jsonBody, 0, 800),
    'httpCode'  => $httpCode,
    'curlErr'   => $curlErr,
    'response'  => substr((string)$response, 0, 800),
]) . "\n", FILE_APPEND | LOCK_EX);

// ── Evaluar respuesta ───────────────────────────────────────────────────────
// NO SILENT FALLBACK: if CDC fails, surface the real error so we can see
// why. The old "fallback approved" masked 100% failures — customer saw
// "evaluación estimada" and thought it worked, but no query was ever
// registered in CDC.
if ($curlErr || $httpCode < 200 || $httpCode >= 300) {
    $_SESSION['cdc_score'] = null;
    http_response_code(502);
    echo json_encode([
        'success'  => false,
        'error'    => 'CDC API falló',
        'http'     => $httpCode,
        'curl_err' => $curlErr ?: null,
        'body'     => substr((string)$response, 0, 600),
        'message'  => 'No pudimos consultar tu historial crediticio. Intenta de nuevo o contacta soporte.',
    ]);
    exit;
}

$data = json_decode($response, true);
if (!$data) {
    $_SESSION['cdc_score'] = null;
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'error'   => 'Respuesta de CDC no es JSON válido',
        'body'    => substr((string)$response, 0, 600),
        'message' => 'Respuesta inesperada del buró. Contacta soporte.',
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

/**
 * Strip accents + non-ASCII, uppercase. CDC v2 rejects bodies containing
 * ñ/á/é etc. (signature mismatch or 400 validation).
 */
function cdcAscii(string $s): string {
    if ($s === '') return '';
    $s = strtoupper($s);
    $map = [
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
        'á'=>'A','é'=>'E','í'=>'I','ó'=>'O','ú'=>'U','ü'=>'U','ñ'=>'N',
        'À'=>'A','È'=>'E','Ì'=>'I','Ò'=>'O','Ù'=>'U',
        'Â'=>'A','Ê'=>'E','Î'=>'I','Ô'=>'O','Û'=>'U',
    ];
    $s = strtr($s, $map);
    // Drop any remaining non-ASCII
    $s = preg_replace('/[^\x20-\x7E]/', '', $s);
    return trim($s);
}

/**
 * Compute a 10-character SAT-format RFC (persona física) from name + DOB.
 * Omits homoclave (adds "XXX" placeholder upstream). Good enough for CDC
 * bureau queries which only need the 10-char identifying prefix to match.
 *
 * Letters:
 *   1. First letter of apellido paterno
 *   2. First vowel AFTER letter 1 of apellido paterno
 *   3. First letter of apellido materno (or "X" if none)
 *   4. First letter of primer nombre
 * Digits:
 *   YYMMDD of fecha nacimiento (YYYY-MM-DD)
 */
function cdcComputeRFC(string $nombre, string $paterno, string $materno, string $fechaNac): string {
    $nombre  = cdcAscii($nombre);
    $paterno = cdcAscii($paterno);
    $materno = cdcAscii($materno);

    if ($paterno === '' || $nombre === '') return 'XAXX010101000';

    // Letter 1 & 2 — from apellido paterno
    $l1 = substr($paterno, 0, 1);
    $l2 = 'X';
    for ($i = 1; $i < strlen($paterno); $i++) {
        $c = $paterno[$i];
        if (in_array($c, ['A','E','I','O','U'], true)) { $l2 = $c; break; }
    }

    // Letter 3 — first letter of apellido materno (X if absent)
    $l3 = $materno !== '' ? substr($materno, 0, 1) : 'X';

    // Letter 4 — first letter of nombre (skip common preamble like JOSE/MARIA)
    $nombreParts = preg_split('/\s+/', $nombre);
    $firstName = $nombreParts[0];
    if (in_array($firstName, ['JOSE', 'MARIA', 'MA', 'J'], true) && isset($nombreParts[1])) {
        $firstName = $nombreParts[1];
    }
    $l4 = substr($firstName, 0, 1);

    // Digits YYMMDD
    $digits = '';
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fechaNac, $m)) {
        $digits = substr($m[1], 2, 2) . $m[2] . $m[3];
    } else {
        $digits = '000000';
    }

    return $l1 . $l2 . $l3 . $l4 . $digits;
}

/**
 * Normalize free-text "estado" into CDC's CatalogoEstados v2 enum.
 * Accepts common variations ("Ciudad de México" / "CDMX" / "DF" / "cdmx").
 */
function cdcEstadoEnum(string $raw): string {
    $k = cdcAscii($raw);
    $k = preg_replace('/\s+/', '', $k);
    // Direct codes
    $codes = ['CDMX','AGS','BC','BCS','CAMP','CHIS','CHIH','COAH','COL','DGO',
              'GTO','GRO','HGO','JAL','MEX','MICH','MOR','NAY','NL','OAX','PUE',
              'QRO','QROO','SLP','SIN','SON','TAB','TAMS','TLAX','VER','YUC','ZAC'];
    if (in_array($k, $codes, true)) return $k;
    // Aliases by full name
    $aliases = [
        'CIUDADDEMEXICO' => 'CDMX', 'DISTRITOFEDERAL' => 'CDMX', 'DF' => 'CDMX',
        'AGUASCALIENTES' => 'AGS',
        'BAJACALIFORNIA' => 'BC', 'BAJACALIFORNIASUR' => 'BCS',
        'CAMPECHE' => 'CAMP',
        'CHIAPAS' => 'CHIS', 'CHIHUAHUA' => 'CHIH',
        'COAHUILA' => 'COAH', 'COLIMA' => 'COL',
        'DURANGO' => 'DGO',
        'GUANAJUATO' => 'GTO', 'GUERRERO' => 'GRO',
        'HIDALGO' => 'HGO',
        'JALISCO' => 'JAL',
        'ESTADODEMEXICO' => 'MEX', 'MEXICO' => 'MEX',
        'MICHOACAN' => 'MICH', 'MORELOS' => 'MOR',
        'NAYARIT' => 'NAY', 'NUEVOLEON' => 'NL',
        'OAXACA' => 'OAX',
        'PUEBLA' => 'PUE',
        'QUERETARO' => 'QRO', 'QUINTANAROO' => 'QROO',
        'SANLUISPOTOSI' => 'SLP', 'SINALOA' => 'SIN', 'SONORA' => 'SON',
        'TABASCO' => 'TAB', 'TAMAULIPAS' => 'TAMS', 'TLAXCALA' => 'TLAX',
        'VERACRUZ' => 'VER',
        'YUCATAN' => 'YUC',
        'ZACATECAS' => 'ZAC',
    ];
    return $aliases[$k] ?? 'CDMX';
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
