<?php
/**
 * Voltika Admin — Recover folio + score for backfilled rows from EXISTING
 * audit logs. NO CDC API CALLS, NO CHARGES.
 *
 * Customer brief 2026-05-30 URGENT: customer cannot afford to re-query CDC
 * but needs complete data for CDC compliance reporting (otherwise CDC may
 * fine them). Solution: extract the data from logs we ALREADY have:
 *
 *   1. cdc_query_log     — every CDC API call from consultar-buro.php is
 *                          logged here with full request body + response.
 *                          The folio+score live inside the `response` text.
 *   2. admin_log         — diagnostico-cdc-test action logs the response
 *                          snippet (resp_short) which contains folioConsulta.
 *   3. logs/circulo-credito.log — file log with full request/response JSON.
 *
 * Match logic (per cdc_query_log row):
 *   - Parse body_sent → extract primerNombre + apellidoPaterno + RFC
 *   - Parse response  → extract folioConsulta + scores[0].valor + cuentas
 *   - Find consultas_buro row by:
 *       a) RFC base (first 10 chars)
 *       b) AND name match (nombre + apellido_paterno)
 *       c) AND folio_consulta IS NULL or score IS NULL
 *   - UPDATE the row with the recovered data
 *
 * Idempotent (only fills NULL/empty fields). Two-stage flow.
 *
 * Auth: admin only.
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$commit = isset($_POST['commit']) && $_POST['commit'] === '1';

// Helper: parse a CDC response JSON to extract the fields we need
function extractCdcResponse(string $respJson): ?array {
    if ($respJson === '') return null;
    // Response may be truncated to 2000 chars — try to parse what we have
    $data = json_decode($respJson, true);
    if (!is_array($data)) {
        // Truncated JSON — try to extract just folio + score with regex
        $folio = null; $score = null;
        if (preg_match('/"folioConsulta"\s*:\s*"([^"]+)"/', $respJson, $m)) $folio = $m[1];
        if (preg_match('/"scores"\s*:\s*\[\s*\{[^}]*"valor"\s*:\s*"?(\d+)"?/', $respJson, $m)) $score = (int)$m[1];
        if ($folio || $score) return ['folio' => $folio, 'score' => $score, 'partial' => true];
        return null;
    }
    $folio = $data['folioConsulta'] ?? null;
    $score = null;
    if (!empty($data['scores'][0]['valor'])) $score = (int)$data['scores'][0]['valor'];

    $cuentas = $data['cuentas'] ?? [];
    $numC = is_array($cuentas) ? count($cuentas) : 0;
    $dpdMax = 0; $dpd90 = false; $pagoM = 0.0; $apro = 0.0; $venc = 0.0; $activas = 0; $hist90 = 0;
    if ($numC > 0) {
        foreach ($cuentas as $c) {
            $abierta = empty($c['fechaCierreCuenta']);
            if ($abierta) {
                $activas++;
                $pagoM += floatval($c['montoPagar'] ?? 0);
            }
            $apro += floatval($c['creditoMaximo']  ?? $c['montoCreditoMaximo'] ?? 0);
            $venc += floatval($c['saldoVencido']   ?? $c['montoVencido']      ?? 0);
            $atr = intval($c['peorAtraso'] ?? 0);
            if ($atr > $dpdMax) $dpdMax = $atr;
            if ($atr >= 90) { $dpd90 = true; $hist90++; }
        }
    }
    return [
        'folio'   => $folio,
        'score'   => $score,
        'cuentas' => $numC,
        'dpd90'   => $dpd90 ? 1 : 0,
        'dpdMax'  => $dpdMax,
        'pagoM'   => $pagoM,
        'apro'    => $apro,
        'venc'    => $venc,
        'activas' => $activas,
        'hist90'  => $hist90,
        'raw'     => substr($respJson, 0, 524288),
        'partial' => false,
    ];
}

// Helper: parse the request body to extract name + RFC for matching
function extractCdcRequest(string $bodyJson): ?array {
    $data = json_decode($bodyJson, true);
    if (!is_array($data)) {
        // Truncated — regex extract
        $name = ''; $apellido = ''; $rfc = '';
        if (preg_match('/"primerNombre"\s*:\s*"([^"]+)"/', $bodyJson, $m)) $name = $m[1];
        if (preg_match('/"apellidoPaterno"\s*:\s*"([^"]+)"/', $bodyJson, $m)) $apellido = $m[1];
        if (preg_match('/"RFC"\s*:\s*"([^"]+)"/', $bodyJson, $m)) $rfc = $m[1];
        return ($name && $apellido) ? ['nombre'=>$name, 'paterno'=>$apellido, 'rfc'=>$rfc] : null;
    }
    return [
        'nombre'  => (string)($data['primerNombre']    ?? ''),
        'paterno' => (string)($data['apellidoPaterno'] ?? ''),
        'rfc'     => (string)($data['RFC'] ?? ''),
    ];
}

// ── Build recovery plan from cdc_query_log ────────────────────────────────
$plan = [];
$logCount = 0; $logHttp200 = 0; $unmatched = 0;
try {
    $logRows = $pdo->query("SELECT id, http_code, body_sent, response, freg
        FROM cdc_query_log
        WHERE http_code = 200
        ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $logCount = count($logRows);
    $logHttp200 = $logCount;

    foreach ($logRows as $lg) {
        $req = extractCdcRequest((string)$lg['body_sent']);
        $resp = extractCdcResponse((string)$lg['response']);
        if (!$req || !$resp || (!$resp['folio'] && !isset($resp['score']))) continue;

        // Find matching consultas_buro row that needs enrichment
        $rfcBase = $req['rfc'] !== '' ? substr($req['rfc'], 0, 10) : '';
        $params = []; $where = [];
        if ($rfcBase !== '') {
            $where[] = "rfc LIKE ?";
            $params[] = $rfcBase . '%';
        }
        if ($req['nombre'] !== '' && $req['paterno'] !== '') {
            $where[] = "(LOWER(nombre) = LOWER(?) AND LOWER(apellido_paterno) = LOWER(?))";
            $params[] = $req['nombre'];
            $params[] = $req['paterno'];
        }
        if (empty($where)) { $unmatched++; continue; }
        $sql = "SELECT id, nombre, apellido_paterno, folio_consulta, score, freg
            FROM consultas_buro
            WHERE (" . implode(' OR ', $where) . ")
              AND (folio_consulta IS NULL OR folio_consulta = '' OR score IS NULL OR score = 0)
            ORDER BY ABS(TIMESTAMPDIFF(SECOND, freg, ?)) ASC LIMIT 1";
        $params[] = (string)$lg['freg'];
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $match = $st->fetch(PDO::FETCH_ASSOC);
        if (!$match) { $unmatched++; continue; }

        $plan[] = [
            'cb_id'    => (int)$match['id'],
            'cb_name'  => trim($match['nombre'] . ' ' . $match['apellido_paterno']),
            'cb_freg'  => (string)$match['freg'],
            'log_id'   => (int)$lg['id'],
            'log_freg' => (string)$lg['freg'],
            'recovered'=> $resp,
            'cur_folio'=> (string)($match['folio_consulta'] ?? ''),
            'cur_score'=> $match['score'],
        ];
    }
} catch (Throwable $e) {
    die('Error: ' . htmlspecialchars($e->getMessage()));
}

// Dedup by cb_id — keep best (most-complete) recovery per row
$bestByRow = [];
foreach ($plan as $p) {
    $rid = $p['cb_id'];
    if (!isset($bestByRow[$rid])) {
        $bestByRow[$rid] = $p;
        continue;
    }
    // Prefer the one with non-null folio + score
    $cur = $bestByRow[$rid]['recovered'];
    $new = $p['recovered'];
    $curScore = $cur['folio'] ? 2 : 0; $curScore += $cur['score'] !== null ? 1 : 0;
    $newScore = $new['folio'] ? 2 : 0; $newScore += $new['score'] !== null ? 1 : 0;
    if ($newScore > $curScore) $bestByRow[$rid] = $p;
}
$plan = array_values($bestByRow);

// ── COMMIT phase ──────────────────────────────────────────────────────────
$updateStats = null;
if ($commit && !empty($plan)) {
    $updated = 0; $errors = 0;
    foreach ($plan as $p) {
        $rec = $p['recovered'];
        try {
            $sets = []; $params = [];
            if (!empty($rec['folio'])) {
                $sets[] = "folio_consulta = COALESCE(NULLIF(folio_consulta, ''), ?)";
                $params[] = $rec['folio'];
            }
            if ($rec['score'] !== null) {
                $sets[] = "score = COALESCE(score, ?)";
                $params[] = $rec['score'];
            }
            if (!($rec['partial'] ?? false)) {
                $sets[] = "num_cuentas = COALESCE(num_cuentas, ?)";              $params[] = $rec['cuentas'];
                $sets[] = "dpd90_flag = COALESCE(dpd90_flag, ?)";               $params[] = $rec['dpd90'];
                $sets[] = "dpd_max = COALESCE(dpd_max, ?)";                     $params[] = $rec['dpdMax'];
                $sets[] = "pago_mensual = COALESCE(pago_mensual, ?)";           $params[] = $rec['pagoM'];
                $sets[] = "aprobado_total = COALESCE(aprobado_total, ?)";       $params[] = $rec['apro'];
                $sets[] = "vencido_total = COALESCE(vencido_total, ?)";         $params[] = $rec['venc'];
                $sets[] = "cuentas_activas = COALESCE(cuentas_activas, ?)";     $params[] = $rec['activas'];
                $sets[] = "cuentas_dpd90_historico = COALESCE(cuentas_dpd90_historico, ?)"; $params[] = $rec['hist90'];
                $sets[] = "raw_response = COALESCE(raw_response, ?)";           $params[] = $rec['raw'];
            }
            $sets[] = "status = COALESCE(NULLIF(status, ''), 'recovered_from_log')";
            if (empty($sets)) continue;
            $params[] = $p['cb_id'];
            $sql = "UPDATE consultas_buro SET " . implode(', ', $sets) . " WHERE id = ?";
            $up = $pdo->prepare($sql);
            $up->execute($params);
            if ($up->rowCount() > 0) $updated++;
        } catch (Throwable $e) {
            $errors++;
            error_log('recover-from-logs UPDATE id ' . $p['cb_id'] . ': ' . $e->getMessage());
        }
    }
    $updateStats = compact('updated','errors');
    if (function_exists('adminLog')) {
        adminLog('recover_from_cdc_logs', ['updated'=>$updated,'errors'=>$errors,'plan'=>count($plan)]);
    }
}

// ── UI ────────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Recover from CDC logs (FREE)</title>';
echo '<style>body{font-family:system-ui,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1200px;margin:0 auto;line-height:1.55;}
h1{font-size:20px;margin:0 0 6px;}
h2{font-size:14px;color:#475569;margin:18px 0 8px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;margin-bottom:12px;}
table{width:100%;border-collapse:collapse;font-size:11.5px;}
th{background:#f1f5f9;padding:5px 8px;text-align:left;}
td{padding:5px 8px;border-top:1px solid #f1f5f9;}
.btn{padding:8px 16px;background:#16a34a;color:#fff;border:0;border-radius:5px;cursor:pointer;font-weight:600;font-size:13px;text-decoration:none;display:inline-block;}
.btn.safe{background:#039fe1;}
.btn.ghost{background:#fff;color:#0c2340;border:1px solid #cbd5e1;}
.banner{padding:11px 14px;border-radius:8px;font-size:13.5px;margin-bottom:12px;font-weight:600;}
.banner-info{background:#dbeafe;border:1px solid #93c5fd;color:#1e3a8a;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.banner-warn{background:#fef3c7;border:1px solid #fcd34d;color:#92400e;}
.ok{color:#15803d;font-weight:700;}
.muted{color:#94a3b8;}
code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:11px;}
</style></head><body>';
echo '<h1>Recover folio + score from CDC logs — FREE (no API calls)</h1>';
echo '<p class="muted" style="font-size:12.5px;">Extracts already-paid-for CDC data from <code>cdc_query_log</code> and matches it to backfilled consultas_buro rows.</p>';

echo '<div class="banner banner-info">'
   . 'cdc_query_log entries scanned: <strong>' . $logCount . '</strong> &middot; '
   . 'HTTP 200 (usable): <strong>' . $logHttp200 . '</strong> &middot; '
   . 'Matched to consultas_buro: <strong>' . count($plan) . '</strong> &middot; '
   . 'Unmatched: <strong>' . $unmatched . '</strong>'
   . '</div>';

if ($updateStats) {
    echo '<div class="banner banner-ok">Recovered: <strong>' . $updateStats['updated'] . '</strong> rows &middot; Errors: <strong>' . $updateStats['errors'] . '</strong></div>';
    echo '<p><a class="btn" href="/admin/php/buro/exportar.php" target="_blank">Download CDC export CSV</a> ';
    echo '<a class="btn ghost" href="?">Re-scan</a></p>';
}

if (empty($plan)) {
    echo '<div class="card">';
    echo '<p>No recoverable data found in cdc_query_log for the backfilled rows.</p>';
    echo '<p class="muted">This means either:</p>';
    echo '<ul class="muted" style="font-size:13px;">';
    echo '<li>The cdc_query_log table is empty / was cleared</li>';
    echo '<li>The original CDC calls happened before the query log was added</li>';
    echo '<li>The matching algorithm couldn\'t link logs to consultas_buro rows by name+RFC</li>';
    echo '</ul>';
    echo '</div>';
} else {
    echo '<div class="card">';
    echo '<h2>Recovery plan (' . count($plan) . ' rows)</h2>';
    echo '<table><thead><tr><th>cb_id</th><th>Name</th><th>Log id</th><th>Recovered folio</th><th>Recovered score</th><th>Cuentas</th><th>Quality</th></tr></thead><tbody>';
    foreach ($plan as $p) {
        $r = $p['recovered'];
        $quality = '';
        if (!empty($r['partial'])) $quality = '<span class="muted">partial (regex)</span>';
        elseif ($r['folio'] && $r['score'] !== null) $quality = '<span class="ok">full</span>';
        elseif ($r['folio']) $quality = '<span class="muted">folio only</span>';
        elseif ($r['score'] !== null) $quality = '<span class="muted">score only</span>';
        echo '<tr>';
        echo '<td>' . $p['cb_id'] . '</td>';
        echo '<td>' . htmlspecialchars($p['cb_name']) . '</td>';
        echo '<td>' . $p['log_id'] . '</td>';
        echo '<td>' . htmlspecialchars((string)($r['folio'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars((string)($r['score'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars((string)($r['cuentas'] ?? '-')) . '</td>';
        echo '<td>' . $quality . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    if (!$commit) {
        echo '<form method="post" style="margin-top:14px;">';
        echo '<input type="hidden" name="commit" value="1">';
        echo '<button class="btn" type="submit" onclick="return confirm(\'Recover folio + score for ' . count($plan) . ' rows? FREE — no CDC charges.\');">Recover ' . count($plan) . ' rows (FREE)</button>';
        echo '</form>';
    }
    echo '</div>';
}

echo '</body></html>';
