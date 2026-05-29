<?php
/**
 * Voltika Admin — Re-query CDC for backfilled consultas_buro rows that are
 * missing folio_consulta and score.
 *
 * Customer brief 2026-05-30: backfilled rows (created via
 * backfill-consultas.php from preaprobaciones) lack folio_consulta and
 * score because we never made a CDC call for them. Customer wants those
 * fields populated. The ONLY way to fill them is to call CDC again.
 *
 * !! EACH CDC CALL COSTS REAL MONEY !!
 *
 * Safety measures:
 *   - Dry-run preview by default (no API calls)
 *   - Explicit two-confirmation commit (button + browser confirm dialog)
 *   - Per-batch limit (default 5) so admin can stop after a small test
 *   - Per-call result logged so failures are visible
 *   - Rate limit: 1.5s sleep between calls
 *   - Skips rows that already have folio_consulta filled
 *
 * Auth: admin only.
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

// Load CDC config + constants from configurador
$cfgFile = __DIR__ . '/../../../configurador/php/config.php';
if (is_file($cfgFile)) require_once $cfgFile;
if (!defined('CDC_BASE_URL')) define('CDC_BASE_URL', getenv('CDC_BASE_URL') ?: 'https://services.circulodecredito.com.mx/v2/rccficoscore');
if (!defined('CDC_FOLIO'))    define('CDC_FOLIO',    getenv('CDC_FOLIO')    ?: '0000004694');
if (!defined('CDC_USER'))     define('CDC_USER',     getenv('CDC_USER')     ?: '');
if (!defined('CDC_PASS'))     define('CDC_PASS',     getenv('CDC_PASS')     ?: '');

$pdo = getDB();
$commit = isset($_POST['commit']) && $_POST['commit'] === '1';
$limit  = max(1, min(50, (int)($_POST['limit'] ?? $_GET['limit'] ?? 5)));

// ── Helpers ────────────────────────────────────────────────────────────────
function rqAscii(string $s): string {
    if ($s === '') return '';
    $s = strtoupper($s);
    $s = strtr($s, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
        'á'=>'A','é'=>'E','í'=>'I','ó'=>'O','ú'=>'U','ü'=>'U','ñ'=>'N']);
    return trim((string)preg_replace('/[^\x20-\x7E]/', '', $s));
}
function rqEstado(string $raw): string {
    $k = preg_replace('/\s+/', '', rqAscii($raw));
    $codes = ['CDMX','AGS','BC','BCS','CAMP','CHIS','CHIH','COAH','COL','DGO','GTO','GRO','HGO','JAL','MEX','MICH','MOR','NAY','NL','OAX','PUE','QRO','QROO','SLP','SIN','SON','TAB','TAMS','TLAX','VER','YUC','ZAC'];
    if (in_array($k, $codes, true)) return $k;
    $a = [
        'CIUDADDEMEXICO'=>'CDMX','DISTRITOFEDERAL'=>'CDMX','DF'=>'CDMX',
        'AGUASCALIENTES'=>'AGS','BAJACALIFORNIA'=>'BC','BAJACALIFORNIASUR'=>'BCS',
        'CAMPECHE'=>'CAMP','CHIAPAS'=>'CHIS','CHIHUAHUA'=>'CHIH','COAHUILA'=>'COAH',
        'COAHUILADEZARAGOZA'=>'COAH','COLIMA'=>'COL','DURANGO'=>'DGO',
        'GUANAJUATO'=>'GTO','GUERRERO'=>'GRO','HIDALGO'=>'HGO','JALISCO'=>'JAL',
        'ESTADODEMEXICO'=>'MEX','MEXICO'=>'MEX','MICHOACAN'=>'MICH',
        'MICHOACANDEOCAMPO'=>'MICH','MORELOS'=>'MOR','NAYARIT'=>'NAY',
        'NUEVOLEON'=>'NL','OAXACA'=>'OAX','PUEBLA'=>'PUE','QUERETARO'=>'QRO',
        'QUINTANAROO'=>'QROO','SANLUISPOTOSI'=>'SLP','SINALOA'=>'SIN','SONORA'=>'SON',
        'TABASCO'=>'TAB','TAMAULIPAS'=>'TAMS','TLAXCALA'=>'TLAX','VERACRUZ'=>'VER',
        'VERACRUZDEIGNACIODELALLAVE'=>'VER','VERACRUZD'=>'VER','YUCATAN'=>'YUC','ZACATECAS'=>'ZAC',
    ];
    return $a[$k] ?? 'CDMX';
}
function rqExtractScore(array $data): array {
    $score = null; $cuentas = 0; $dpd90 = false; $dpdMax = 0; $pagoMensual = 0.0;
    $aprobado = 0.0; $vencido = 0.0; $activas = 0; $dpd90Hist = 0;
    if (!empty($data['scores'])) {
        $score = intval($data['scores'][0]['valor'] ?? 0);
    }
    if (!empty($data['cuentas'])) {
        $cuentas = count($data['cuentas']);
        foreach ($data['cuentas'] as $c) {
            $abierta = empty($c['fechaCierreCuenta']);
            if ($abierta) {
                $activas++;
                $pagoMensual += floatval($c['montoPagar'] ?? 0);
            }
            $aprobado += floatval($c['creditoMaximo'] ?? $c['montoCreditoMaximo'] ?? 0);
            $vencido  += floatval($c['saldoVencido']  ?? $c['montoVencido']      ?? 0);
            $atr = intval($c['peorAtraso'] ?? 0);
            if ($atr > $dpdMax) $dpdMax = $atr;
            if ($atr >= 90) { $dpd90 = true; $dpd90Hist++; }
        }
        if ($dpdMax >= 90) $dpd90 = true;
    }
    return compact('score','cuentas','dpd90','dpdMax','pagoMensual','aprobado','vencido','activas','dpd90Hist');
}
function rqLoadKey(PDO $pdo): array {
    $key = null; $cert = null;
    try {
        $row = $pdo->query("SELECT private_key, certificate FROM cdc_certificates WHERE active = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) { $key = $row['private_key']; $cert = $row['certificate']; }
    } catch (Throwable $e) {}
    $kFile = __DIR__ . '/../../../configurador/php/certs/cdc_private.key';
    $cFile = __DIR__ . '/../../../configurador/php/certs/cdc_certificate.pem';
    if (!$key && file_exists($kFile)) $key = @file_get_contents($kFile);
    if (!$cert && file_exists($cFile)) $cert = @file_get_contents($cFile);
    return [$key, $cert];
}
function rqCallCdc(array $body, string $keyPem, ?string $certPem): array {
    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    $priv = openssl_pkey_get_private($keyPem);
    if (!$priv) return ['http' => 0, 'err' => 'bad private key', 'body' => '', 'parsed' => null];
    $sigBin = '';
    if (!openssl_sign($json, $sigBin, $priv, OPENSSL_ALGO_SHA256)) {
        return ['http' => 0, 'err' => 'sign failed: ' . openssl_error_string(), 'body' => '', 'parsed' => null];
    }
    $sig = bin2hex($sigBin);
    $headers = [
        'Content-Type: application/json', 'Accept: application/json',
        'x-api-key: ' . (defined('CDC_API_KEY') ? CDC_API_KEY : ''),
    ];
    if (CDC_USER) $headers[] = 'username: ' . CDC_USER;
    if (CDC_PASS) $headers[] = 'password: ' . CDC_PASS;
    $headers[] = 'x-signature: ' . $sig;
    $opts = [
        CURLOPT_URL => CDC_BASE_URL, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    ];
    $tmpCert = null; $tmpKey = null;
    if ($certPem && $keyPem) {
        $tmpCert = tempnam(sys_get_temp_dir(), 'cdc_cert_');
        $tmpKey  = tempnam(sys_get_temp_dir(), 'cdc_key_');
        file_put_contents($tmpCert, $certPem);
        file_put_contents($tmpKey,  $keyPem);
        $opts[CURLOPT_SSLCERT] = $tmpCert;
        $opts[CURLOPT_SSLKEY]  = $tmpKey;
    }
    $ch = curl_init();
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($tmpCert) @unlink($tmpCert);
    if ($tmpKey)  @unlink($tmpKey);
    $parsed = is_string($resp) ? json_decode($resp, true) : null;
    return ['http' => $http, 'err' => $err, 'body' => (string)$resp, 'parsed' => $parsed];
}

// ── Candidate detection ───────────────────────────────────────────────────
$candidates = $pdo->query("SELECT id, nombre, apellido_paterno, apellido_materno,
    fecha_nacimiento, rfc, curp, calle_numero, colonia, municipio, ciudad,
    estado, cp, origen, freg
    FROM consultas_buro
    WHERE (folio_consulta IS NULL OR folio_consulta = '')
      AND (score IS NULL OR score = 0)
      AND nombre IS NOT NULL AND nombre != ''
      AND apellido_paterno IS NOT NULL AND apellido_paterno != ''
      AND fecha_nacimiento IS NOT NULL AND fecha_nacimiento != ''
    ORDER BY freg DESC")->fetchAll(PDO::FETCH_ASSOC);

// ── COMMIT phase ──────────────────────────────────────────────────────────
$results = [];
if ($commit && !empty($candidates)) {
    [$keyPem, $certPem] = rqLoadKey($pdo);
    if (!$keyPem) {
        $results[] = ['id' => 0, 'name' => '(setup)', 'http' => 0, 'msg' => 'PRIVATE KEY MISSING — cannot sign CDC requests'];
    } else {
        $batch = array_slice($candidates, 0, $limit);
        foreach ($batch as $row) {
            $body = [
                'primerNombre'    => rqAscii((string)$row['nombre']),
                'apellidoPaterno' => rqAscii((string)$row['apellido_paterno']),
                'apellidoMaterno' => rqAscii((string)($row['apellido_materno'] ?: 'X')) ?: 'X',
                'fechaNacimiento' => (string)$row['fecha_nacimiento'],
                'nacionalidad'    => 'MX',
                'domicilio'       => [
                    'direccion'           => rqAscii((string)($row['calle_numero'] ?: 'NO DISPONIBLE')),
                    'coloniaPoblacion'    => rqAscii((string)($row['colonia']      ?: 'CENTRO')),
                    'delegacionMunicipio' => rqAscii((string)($row['municipio']    ?: $row['ciudad'] ?: 'NO DISPONIBLE')),
                    'ciudad'              => rqAscii((string)($row['ciudad']       ?: 'NO DISPONIBLE')),
                    'estado'              => rqEstado((string)($row['estado']      ?: 'CDMX')),
                    'CP'                  => (string)($row['cp'] ?: '00000'),
                ],
            ];
            if (!empty($row['rfc']))  $body['RFC']  = $row['rfc'];
            if (!empty($row['curp'])) $body['CURP'] = $row['curp'];

            $r = rqCallCdc($body, $keyPem, $certPem);
            $name = trim($row['nombre'] . ' ' . $row['apellido_paterno']);
            $folio = null; $score = null; $msg = '';
            if ($r['http'] === 200 && is_array($r['parsed'])) {
                $folio = $r['parsed']['folioConsulta'] ?? null;
                $extr  = rqExtractScore($r['parsed']);
                $score = $extr['score'];
                if ($folio || $score !== null) {
                    $up = $pdo->prepare("UPDATE consultas_buro
                        SET folio_consulta = COALESCE(NULLIF(folio_consulta, ''), ?),
                            score = COALESCE(score, ?),
                            num_cuentas = COALESCE(num_cuentas, ?),
                            dpd90_flag = COALESCE(dpd90_flag, ?),
                            dpd_max = COALESCE(dpd_max, ?),
                            pago_mensual = COALESCE(pago_mensual, ?),
                            aprobado_total = COALESCE(aprobado_total, ?),
                            vencido_total = COALESCE(vencido_total, ?),
                            cuentas_activas = COALESCE(cuentas_activas, ?),
                            cuentas_dpd90_historico = COALESCE(cuentas_dpd90_historico, ?),
                            status = 'cdc_requeried_' || ?,
                            raw_response = COALESCE(raw_response, ?)
                        WHERE id = ?");
                    // status concat: use plain text for portability
                    $up = $pdo->prepare("UPDATE consultas_buro SET
                        folio_consulta = COALESCE(NULLIF(folio_consulta, ''), :folio),
                        score = COALESCE(score, :score),
                        num_cuentas = COALESCE(num_cuentas, :cuentas),
                        dpd90_flag = COALESCE(dpd90_flag, :dpd90),
                        dpd_max = COALESCE(dpd_max, :dpdmax),
                        pago_mensual = COALESCE(pago_mensual, :pmens),
                        aprobado_total = COALESCE(aprobado_total, :apro),
                        vencido_total = COALESCE(vencido_total, :venc),
                        cuentas_activas = COALESCE(cuentas_activas, :acti),
                        cuentas_dpd90_historico = COALESCE(cuentas_dpd90_historico, :h90),
                        status = :status,
                        raw_response = COALESCE(raw_response, :raw)
                        WHERE id = :id");
                    $up->execute([
                        ':folio'  => $folio,
                        ':score'  => $score,
                        ':cuentas'=> $extr['cuentas'],
                        ':dpd90'  => $extr['dpd90'] ? 1 : 0,
                        ':dpdmax' => $extr['dpdMax'],
                        ':pmens'  => $extr['pagoMensual'],
                        ':apro'   => $extr['aprobado'],
                        ':venc'   => $extr['vencido'],
                        ':acti'   => $extr['activas'],
                        ':h90'    => $extr['dpd90Hist'],
                        ':status' => 'cdc_requeried',
                        ':raw'    => substr($r['body'], 0, 524288),
                        ':id'     => $row['id'],
                    ]);
                    $msg = 'OK — folio=' . ($folio ?: '?') . ' score=' . ($score ?? '?');
                } else {
                    $msg = 'CDC 200 but no folio/score in body';
                }
            } elseif ($r['http'] === 404 && isset($r['parsed']['errores'][0]['codigo']) && $r['parsed']['errores'][0]['codigo'] === '404.1') {
                $msg = '404.1 person not found — score stays NULL';
                $folio = $r['parsed']['folioConsulta'] ?? null;
                if ($folio) {
                    $pdo->prepare("UPDATE consultas_buro SET folio_consulta = ?, status = 'person_not_found' WHERE id = ?")
                        ->execute([$folio, $row['id']]);
                    $msg .= ' (saved folio ' . $folio . ')';
                }
            } else {
                $msg = 'HTTP ' . $r['http'] . ($r['err'] ? ' — ' . $r['err'] : '');
            }
            $results[] = ['id' => $row['id'], 'name' => $name, 'http' => $r['http'], 'folio' => $folio, 'score' => $score, 'msg' => $msg];
            usleep(1500000); // 1.5s pause between CDC calls
        }
    }
    if (function_exists('adminLog')) {
        adminLog('cdc_requery_backfilled', ['attempted' => count($results), 'limit' => $limit]);
    }
}

// ── UI ────────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Re-query CDC for missing folio/score</title>';
echo '<style>body{font-family:system-ui,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1200px;margin:0 auto;line-height:1.55;}
h1{font-size:20px;margin:0 0 6px;}
h2{font-size:14px;color:#475569;margin:18px 0 8px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;margin-bottom:12px;}
table{width:100%;border-collapse:collapse;font-size:11.5px;}
th{background:#f1f5f9;padding:5px 8px;text-align:left;}
td{padding:5px 8px;border-top:1px solid #f1f5f9;}
.btn{padding:8px 16px;background:#dc2626;color:#fff;border:0;border-radius:5px;cursor:pointer;font-weight:600;font-size:13px;text-decoration:none;display:inline-block;}
.btn.safe{background:#039fe1;}
.btn.success{background:#16a34a;}
.btn.ghost{background:#fff;color:#0c2340;border:1px solid #cbd5e1;}
input[type=number]{padding:6px 8px;border:1px solid #cbd5e1;border-radius:4px;width:80px;font-size:13px;}
.banner{padding:11px 14px;border-radius:8px;font-size:13.5px;margin-bottom:12px;font-weight:600;}
.banner-warn{background:#fef3c7;border:1px solid #fcd34d;color:#92400e;}
.banner-danger{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.ok{color:#15803d;font-weight:700;}
.warn{color:#a16207;}
.err{color:#b91c1c;font-weight:700;}
.muted{color:#94a3b8;}
code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:11px;}
</style></head><body>';
echo '<h1>Re-query CDC for missing folio &amp; score</h1>';

echo '<div class="banner banner-danger">';
echo '⚠ WARNING — EACH CDC CALL COSTS REAL MONEY. ';
echo 'CDC charges per query against Voltika\'s account. Use the batch limit to control spend.';
echo '</div>';

if (!empty($results)) {
    $ok = count(array_filter($results, fn($r) => stripos($r['msg'], 'OK') === 0));
    echo '<div class="banner banner-ok">Processed ' . count($results) . ' rows · ' . $ok . ' updated</div>';
    echo '<div class="card"><h2>CDC re-query results</h2>';
    echo '<table><thead><tr><th>id</th><th>Name</th><th>HTTP</th><th>Folio</th><th>Score</th><th>Result</th></tr></thead><tbody>';
    foreach ($results as $r) {
        $cls = stripos($r['msg'], 'OK') === 0 ? 'ok' : (stripos($r['msg'], '404') !== false ? 'warn' : 'err');
        echo '<tr><td>' . htmlspecialchars((string)$r['id']) . '</td>';
        echo '<td>' . htmlspecialchars($r['name']) . '</td>';
        echo '<td>' . (int)$r['http'] . '</td>';
        echo '<td>' . htmlspecialchars((string)($r['folio'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars((string)($r['score'] ?? '')) . '</td>';
        echo '<td class="' . $cls . '">' . htmlspecialchars($r['msg']) . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<p style="margin-top:14px;"><a class="btn success" href="/admin/php/buro/exportar.php" target="_blank">Download CDC export CSV</a> ';
    echo '<a class="btn ghost" href="?">Reload (process next batch)</a></p>';
    echo '</div>';
}

echo '<div class="card">';
echo '<h2>Candidates needing re-query: ' . count($candidates) . '</h2>';
if (empty($candidates)) {
    echo '<p class="ok">All consultas_buro rows already have folio_consulta and score. Nothing to do.</p>';
} else {
    echo '<form method="post" style="margin-bottom:14px;">';
    echo '<label style="font-size:13px;font-weight:600;">Batch size (max 50): </label>';
    echo '<input type="number" name="limit" value="' . $limit . '" min="1" max="50"> ';
    echo '<input type="hidden" name="commit" value="1">';
    echo '<button class="btn" type="submit" onclick="return confirm(\'This will make REAL CDC API calls and incur charges. Process ' . min($limit, count($candidates)) . ' rows now?\\n\\nClick OK to continue, Cancel to abort.\');">⚠ Re-query ' . min($limit, count($candidates)) . ' rows now (paid)</button>';
    echo '</form>';

    echo '<h2 class="muted">Preview (first ' . min(20, count($candidates)) . ' candidates)</h2>';
    echo '<table><thead><tr><th>id</th><th>Name</th><th>RFC</th><th>DOB</th><th>CP/Ciudad/Estado</th><th>Origen</th><th>Freg</th></tr></thead><tbody>';
    foreach (array_slice($candidates, 0, 20) as $r) {
        echo '<tr>';
        echo '<td>' . (int)$r['id'] . '</td>';
        echo '<td>' . htmlspecialchars(trim($r['nombre'] . ' ' . $r['apellido_paterno'] . ' ' . ($r['apellido_materno'] ?? ''))) . '</td>';
        echo '<td><code>' . htmlspecialchars((string)$r['rfc']) . '</code></td>';
        echo '<td>' . htmlspecialchars((string)$r['fecha_nacimiento']) . '</td>';
        echo '<td>' . htmlspecialchars(($r['cp'] ?? '') . ' ' . ($r['ciudad'] ?? '') . ' ' . ($r['estado'] ?? '')) . '</td>';
        echo '<td><code>' . htmlspecialchars((string)$r['origen']) . '</code></td>';
        echo '<td>' . htmlspecialchars((string)$r['freg']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    if (count($candidates) > 20) echo '<p class="muted">... and ' . (count($candidates) - 20) . ' more</p>';
}
echo '</div>';

echo '<div class="banner banner-warn">';
echo '<strong>How it works:</strong><br>';
echo '1. Tool calls CDC API with the same data we have for each row (name, DOB, RFC, address)<br>';
echo '2. CDC responds with a fresh folio + score (or 404 if person not found)<br>';
echo '3. Tool UPDATEs the row with the new folio + score (other fields untouched)<br>';
echo '4. Each call is rate-limited (1.5s) to avoid CDC throttling<br>';
echo '5. Recommended: start with batch size 1–3 to verify, then scale up<br>';
echo '</div>';

echo '</body></html>';
