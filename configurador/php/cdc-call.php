<?php
/**
 * Voltika — Reusable CDC (Círculo de Crédito) query helper (Round 72, 2026-05-23).
 *
 * Carved out from consultar-buro.php so admin-side tools (e.g. the new
 * "Reintentar CDC" button on preaprobaciones) can re-run a bureau query
 * for an existing applicant without re-implementing the signing /
 * mTLS / retry plumbing.
 *
 * Why a separate file: consultar-buro.php is a script with session writes,
 * header echoes and `exit` calls — not includable as a library. Here we
 * expose a single function `cdcQueryPersona($persona): array` that any
 * server-side caller can use to get a parsed CDC result for one persona.
 *
 * The shared parsing/normalization helpers (`cdcAscii`, `cdcComputeRFC`,
 * `cdcEstadoEnum`, `extractPreaprobacionData`) are mirrored here with
 * `function_exists()` guards so the file is safe to include alongside
 * consultar-buro.php in any order without "Cannot redeclare" fatals.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// CDC_BASE_URL historically lived inside consultar-buro.php (not config.php).
// When this helper is loaded standalone — e.g. from /admin/php/preaprobaciones/
// reconsultar-cdc.php — consultar-buro.php is NOT included, so the constant
// stayed undefined and PHP fatal'd with HTTP 500. Define it here with the
// same default so both call paths work.
if (!defined('CDC_BASE_URL')) {
    define('CDC_BASE_URL',
        getenv('CDC_BASE_URL') ?: 'https://services.circulodecredito.com.mx/v2/rccficoscore');
}

// ─────────────────────────────────────────────────────────────────────────
// Shared helpers (mirrored from consultar-buro.php with guards)
// ─────────────────────────────────────────────────────────────────────────

if (!function_exists('cdcAscii')) {
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
        $s = preg_replace('/[^\x20-\x7E]/', '', $s);
        return trim($s);
    }
}

if (!function_exists('cdcComputeRFC')) {
    function cdcComputeRFC(string $nombre, string $paterno, string $materno, string $fechaNac): string {
        $nombre = cdcAscii($nombre);
        $paterno = cdcAscii($paterno);
        $materno = cdcAscii($materno);
        if ($paterno === '' || strlen($paterno) < 1) return '';
        // First letter of paterno + first vowel
        $first = $paterno[0] ?? '';
        $vowel = '';
        for ($i = 1; $i < strlen($paterno); $i++) {
            if (strpos('AEIOU', $paterno[$i]) !== false) { $vowel = $paterno[$i]; break; }
        }
        $mat = ($materno !== '') ? ($materno[0] ?? 'X') : 'X';
        $nom = ($nombre  !== '') ? ($nombre[0]  ?? 'X') : 'X';
        $letters = $first . $vowel . $mat . $nom;
        $letters = str_pad($letters, 4, 'X');
        // Date YYMMDD from fechaNac (YYYY-MM-DD)
        $digits = '';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fechaNac, $m)) {
            $digits = substr($m[1], 2) . $m[2] . $m[3];
        }
        return $letters . $digits;
    }
}

if (!function_exists('cdcEstadoEnum')) {
    /**
     * Normalize free-text "estado" into CDC's CatalogoEstados v2 enum.
     * MUST match the codes used by consultar-buro.php — earlier versions
     * of this helper had short codes (COA, CAM, DF, …) which CDC v2 rejects
     * with "El estado COA no es válido en direccion".
     */
    function cdcEstadoEnum(string $raw): string {
        $k = cdcAscii($raw);
        $k = preg_replace('/\s+/', '', $k);
        $codes = ['CDMX','AGS','BC','BCS','CAMP','CHIS','CHIH','COAH','COL','DGO',
                  'GTO','GRO','HGO','JAL','MEX','MICH','MOR','NAY','NL','OAX','PUE',
                  'QRO','QROO','SLP','SIN','SON','TAB','TAMS','TLAX','VER','YUC','ZAC'];
        if (in_array($k, $codes, true)) return $k;
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
        if (isset($aliases[$k])) return $aliases[$k];
        return ''; // empty signals "unknown — try CP-derived fallback"
    }
}

if (!function_exists('cdcEstadoFromCP')) {
    /**
     * Mexican CPs are state-prefixed per SAT/SEPOMEX. The first 2 digits
     * map to one Estado. Used as fallback when the applicant's stored
     * estado field is empty/unrecognizable. Returns the CDC v2 enum
     * (CDMX, COAH, …) — NOT 2-3 letter codes.
     */
    function cdcEstadoFromCP(string $cp): string {
        $cp = preg_replace('/\D/', '', $cp);
        if (strlen($cp) < 5) return '';
        $p = (int)substr($cp, 0, 2);
        if ($p >= 1  && $p <= 16) return 'CDMX';
        if ($p === 20)            return 'AGS';
        if ($p >= 21 && $p <= 22) return 'BC';
        if ($p === 23)            return 'BCS';
        if ($p === 24)            return 'CAMP';
        if ($p >= 25 && $p <= 27) return 'COAH';
        if ($p === 28)            return 'COL';
        if ($p >= 29 && $p <= 30) return 'CHIS';
        if ($p >= 31 && $p <= 33) return 'CHIH';
        if ($p >= 34 && $p <= 35) return 'DGO';
        if ($p >= 36 && $p <= 38) return 'GTO';
        if ($p >= 39 && $p <= 41) return 'GRO';
        if ($p >= 42 && $p <= 43) return 'HGO';
        if ($p >= 44 && $p <= 49) return 'JAL';
        if ($p >= 50 && $p <= 57) return 'MEX';
        if ($p >= 58 && $p <= 61) return 'MICH';
        if ($p === 62)            return 'MOR';
        if ($p === 63)            return 'NAY';
        if ($p >= 64 && $p <= 67) return 'NL';
        if ($p >= 68 && $p <= 71) return 'OAX';
        if ($p >= 72 && $p <= 75) return 'PUE';
        if ($p === 76)            return 'QRO';
        if ($p === 77)            return 'QROO';
        if ($p >= 78 && $p <= 79) return 'SLP';
        if ($p >= 80 && $p <= 82) return 'SIN';
        if ($p >= 83 && $p <= 85) return 'SON';
        if ($p >= 86 && $p <= 87) return 'TAB';
        if ($p >= 88 && $p <= 89) return 'TAMS';
        if ($p === 90)            return 'TLAX';
        if ($p >= 91 && $p <= 96) return 'VER';
        if ($p >= 97 && $p <= 98) return 'YUC';
        if ($p === 99)            return 'ZAC';
        return '';
    }
}

if (!function_exists('extractPreaprobacionData')) {
    /**
     * Minimal mirror of consultar-buro.php's extractor. Only fields that the
     * "Reintentar CDC" path actually persists are computed here — keeps the
     * helper small. If you need the full extractor (aprobado_total, vencido,
     * etc.) use the version in consultar-buro.php directly.
     */
    function extractPreaprobacionData(array $r): array {
        $score = null;
        if (!empty($r['scores']) && is_array($r['scores'])) {
            $first = $r['scores'][0] ?? null;
            if (is_array($first)) {
                $val = $first['valor'] ?? null;
                if ($val !== null) $score = (int)$val;
            }
        }
        $cuentas = is_array($r['cuentas'] ?? null) ? $r['cuentas'] : [];
        $pagoMensual = 0.0; $dpdMax = 0; $dpd90 = false;
        $cuentasActivas = 0;
        foreach ($cuentas as $c) {
            if (empty($c['fechaCierreCuenta'])) {
                $cuentasActivas++;
                $pagoMensual += (float)($c['montoPagar'] ?? 0);
            }
            $peor = (int)($c['peorAtraso'] ?? 0);
            if ($peor > $dpdMax) $dpdMax = $peor;
            $hist = (string)($c['historicoPagos'] ?? '');
            for ($i = 0; $i < strlen($hist); $i++) {
                $ch = $hist[$i];
                if (is_numeric($ch) && (int)$ch > 1) {
                    $days = ((int)$ch - 1) * 30;
                    if ($days > $dpdMax) $dpdMax = $days;
                    if ($days >= 90)     $dpd90  = true;
                }
            }
        }
        return [
            'score'             => $score,
            'pago_mensual_buro' => round($pagoMensual, 2),
            'dpd90_flag'        => $dpd90,
            'dpd_max'           => $dpdMax,
            'num_cuentas'       => $cuentasActivas,
            'folioConsulta'     => (string)($r['folioConsulta'] ?? ''),
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Main entry point — query CDC for one persona
// ─────────────────────────────────────────────────────────────────────────

/**
 * Run a CDC FICO query for one persona.
 *
 * Input keys (all strings unless noted):
 *   primerNombre, apellidoPaterno, apellidoMaterno (optional),
 *   fechaNacimiento (YYYY-MM-DD), rfc (optional, auto-computed if blank),
 *   curp (optional), direccion, colonia, municipio, ciudad, estado, cp.
 *
 * Returns:
 *   [
 *     'ok' => bool,
 *     'http' => int,
 *     'person_found' => true|false|null,
 *         (true=CDC returned persona, false=404.1 not found, null=transport error)
 *     'score' => int|null,
 *     'pago_mensual_buro' => float,
 *     'dpd90_flag' => bool,
 *     'dpd_max' => int,
 *     'num_cuentas' => int,
 *     'folioConsulta' => string,
 *     'raw' => array (decoded body),
 *     'curl_err' => string,
 *     'diag' => [ ... ]   (request/response trace for logging)
 *   ]
 */
function cdcQueryPersona(array $persona): array {
    // ── Normalize input ───────────────────────────────────────────────
    $primerNombre    = cdcAscii((string)($persona['primerNombre']    ?? ''));
    $apellidoPaterno = cdcAscii((string)($persona['apellidoPaterno'] ?? ''));
    $apellidoMaterno = cdcAscii((string)($persona['apellidoMaterno'] ?? ''));
    $fechaNacimiento =          (string)($persona['fechaNacimiento'] ?? '');
    $rfc             =          (string)($persona['rfc']             ?? '');
    $curp            =          (string)($persona['curp']            ?? '');
    $direccion       = cdcAscii((string)($persona['direccion']       ?? ''));
    $colonia         = cdcAscii((string)($persona['colonia']         ?? ''));
    $municipio       = cdcAscii((string)($persona['municipio']       ?? ''));
    $ciudad          = cdcAscii((string)($persona['ciudad']          ?? ''));
    $estado          =          (string)($persona['estado']          ?? '');
    $cp              =          (string)($persona['cp']              ?? '');

    if ($primerNombre === '' || $apellidoPaterno === '' || $fechaNacimiento === '') {
        return [
            'ok' => false, 'http' => 0,
            'error' => 'Datos insuficientes: se requiere primerNombre, apellidoPaterno y fechaNacimiento.',
        ];
    }
    if (!$rfc || strlen($rfc) < 10) {
        $rfc = cdcComputeRFC($primerNombre, $apellidoPaterno, $apellidoMaterno, $fechaNacimiento);
    }
    if (strlen($rfc) === 10) $rfc .= 'XXX';
    // Estado: try the explicit field first; if empty/unrecognizable, derive
    // from CP. CDC rejects when CP and Estado don't match (e.g. CP=76000
    // in Querétaro but Estado='DF' triggers "El código postal no pertenece
    // al Estado DF"), so the fallback must produce the CORRECT state, not
    // a placeholder.
    $estadoNorm = cdcEstadoEnum($estado);
    if ($estadoNorm === '' && $cp !== '') {
        $estadoNorm = cdcEstadoFromCP($cp);
    }
    if ($estadoNorm === '') $estadoNorm = 'CDMX'; // last-resort (CDC v2 enum)


    // ── Build request body ────────────────────────────────────────────
    $requestBody = [
        'primerNombre'    => $primerNombre,
        'apellidoPaterno' => $apellidoPaterno,
        'apellidoMaterno' => $apellidoMaterno !== '' ? $apellidoMaterno : 'X',
        'fechaNacimiento' => $fechaNacimiento,
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
    if ($rfc)  $requestBody['RFC']  = $rfc;
    if ($curp) $requestBody['CURP'] = $curp;
    $jsonBody = json_encode($requestBody, JSON_UNESCAPED_UNICODE);

    // ── Load key+cert (DB preferred, disk fallback) ──────────────────
    $keyPem = null; $certPem = null;
    try {
        $pdoTmp = getDB();
        $row = $pdoTmp->query("SELECT private_key, certificate FROM cdc_certificates WHERE active = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $keyPem  = $row['private_key'];
            $certPem = $row['certificate'];
        }
    } catch (Throwable $e) { /* fall through */ }
    $keyFile  = __DIR__ . '/certs/cdc_private.key';
    $certFile = __DIR__ . '/certs/cdc_certificate.pem';
    if (!$keyPem  && file_exists($keyFile))  $keyPem  = @file_get_contents($keyFile);
    if (!$certPem && file_exists($certFile)) $certPem = @file_get_contents($certFile);
    if (!$keyPem) {
        return ['ok' => false, 'http' => 0,
                'error' => 'CDC private key no está en la base de datos ni en disco.'];
    }

    // ── Sign body ────────────────────────────────────────────────────
    $priv = openssl_pkey_get_private($keyPem);
    if (!$priv) {
        return ['ok' => false, 'http' => 0, 'error' => 'No se pudo parsear la llave privada de CDC.'];
    }
    $sig = ''; $signatureHex = '';
    if (openssl_sign($jsonBody, $sig, $priv, OPENSSL_ALGO_SHA256)) {
        $signatureHex = bin2hex($sig);
    }
    if ($signatureHex === '') {
        return ['ok' => false, 'http' => 0, 'error' => 'No se pudo firmar el cuerpo para CDC.'];
    }

    // ── Headers ──────────────────────────────────────────────────────
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'x-api-key: ' . CDC_API_KEY,
    ];
    if (CDC_USER) $headers[] = 'username: ' . CDC_USER;
    if (CDC_PASS) $headers[] = 'password: ' . CDC_PASS;
    $headers[] = 'x-signature: ' . $signatureHex;

    // ── cURL with mTLS + retry ───────────────────────────────────────
    $tmpCert = null; $tmpKey = null;
    if ($certPem && $keyPem) {
        $tmpCert = tempnam(sys_get_temp_dir(), 'cdc_cert_');
        $tmpKey  = tempnam(sys_get_temp_dir(), 'cdc_key_');
        file_put_contents($tmpCert, $certPem);
        file_put_contents($tmpKey,  $keyPem);
    }
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
    if ($tmpCert && $tmpKey) {
        $curlOpts[CURLOPT_SSLCERT] = $tmpCert;
        $curlOpts[CURLOPT_SSLKEY]  = $tmpKey;
    }

    $retryable = [0, 502, 503, 504];
    $maxAttempts = 2;
    $attempts = [];
    $response = ''; $httpCode = 0; $curlErr = '';
    for ($try = 1; $try <= $maxAttempts; $try++) {
        $ch = curl_init();
        curl_setopt_array($ch, $curlOpts);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);
        $attempts[] = ['try' => $try, 'http' => $httpCode, 'err' => $curlErr, 'len' => strlen((string)$response)];
        $transient = ($curlErr !== '') || in_array($httpCode, $retryable, true);
        if (!$transient || $try >= $maxAttempts) break;
        sleep(2);
    }
    if ($tmpCert) @unlink($tmpCert);
    if ($tmpKey)  @unlink($tmpKey);

    $parsed = json_decode((string)$response, true);
    $diag = [
        'attempts'   => $attempts,
        'body_sent'  => substr($jsonBody, 0, 2000),
        'body_resp'  => substr((string)$response, 0, 2000),
        'final_http' => $httpCode,
        'curl_err'   => $curlErr,
    ];

    // ── 404.1 — persona explicitly not found ─────────────────────────
    $isPersonNotFound = $httpCode === 404
        && is_array($parsed)
        && (($parsed['errores'][0]['codigo'] ?? '') === '404.1');
    if ($isPersonNotFound) {
        return [
            'ok'                => true,
            'http'              => $httpCode,
            'person_found'      => false,
            'score'             => null,
            'pago_mensual_buro' => 0.0,
            'dpd90_flag'        => false,
            'dpd_max'           => 0,
            'num_cuentas'       => 0,
            'folioConsulta'     => '',
            'raw'               => $parsed,
            'curl_err'          => $curlErr,
            'diag'              => $diag,
        ];
    }

    // ── Transport / auth failure ─────────────────────────────────────
    if ($curlErr !== '' || $httpCode < 200 || $httpCode >= 300) {
        return [
            'ok'                => false,
            'http'              => $httpCode,
            'person_found'      => null,
            'score'             => null,
            'pago_mensual_buro' => 0.0,
            'dpd90_flag'        => false,
            'dpd_max'           => 0,
            'num_cuentas'       => 0,
            'error'             => 'CDC respondió HTTP ' . $httpCode . ' o transport error.',
            'raw'               => $parsed,
            'curl_err'          => $curlErr,
            'diag'              => $diag,
        ];
    }

    // ── Success — parse fields we care about ─────────────────────────
    if (!is_array($parsed)) {
        return [
            'ok'    => false,
            'http'  => $httpCode,
            'error' => 'Respuesta de CDC no es JSON válido.',
            'raw'   => $response,
            'diag'  => $diag,
        ];
    }
    $extracted = extractPreaprobacionData($parsed);
    return [
        'ok'                => true,
        'http'              => $httpCode,
        'person_found'      => true,
        'score'             => $extracted['score'],
        'pago_mensual_buro' => $extracted['pago_mensual_buro'],
        'dpd90_flag'        => $extracted['dpd90_flag'],
        'dpd_max'           => $extracted['dpd_max'],
        'num_cuentas'       => $extracted['num_cuentas'],
        'folioConsulta'     => $extracted['folioConsulta'],
        'raw'               => $parsed,
        'curl_err'          => $curlErr,
        'diag'              => $diag,
    ];
}
