<?php
/**
 * Voltika — Truora end-to-end pipeline diagnostic.
 *
 * Shows in one page exactly where the identity-verification pipeline is
 * stuck (or working) so the admin can fix it without DB access.
 *
 * Sections:
 *   1. Recent verificaciones_identidad rows (the SPA's anchor)
 *   2. Recent truora_token_log (was the token issued?)
 *   3. Recent truora_webhook_log (did Truora call us back?)
 *   4. Recent truora_fetch_log (did our API fetch return CURP?)
 *   5. Recent truora_curp_audit (what was the comparison decision?)
 *   6. Health summary with the most likely failure point
 *
 * Auth: ?token=voltika_diag_2026 (or admin session).
 */
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_name('VOLTIKA_ADMIN');
    @session_start();
}
$expectedToken = getenv('VOLTIKA_DIAG_TOKEN') ?: (defined('VOLTIKA_DIAG_TOKEN') ? VOLTIKA_DIAG_TOKEN : 'voltika_diag_2026');
$adminOk  = !empty($_SESSION['admin_user_id']);
$tokenOk  = isset($_GET['token']) && hash_equals($expectedToken, $_GET['token']);
if (!$adminOk && !$tokenOk) {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><body style="font-family:system-ui;padding:30px;">';
    echo '<h2>Truora pipeline diag — acceso protegido</h2>';
    echo '<p>Use: <code>?token=' . htmlspecialchars($expectedToken) . '</code></p>';
    echo '<p><a href="?token=' . urlencode($expectedToken) . '">▶ Abrir</a></p>';
    exit;
}

$pdo = getDB();
function fetchLast(PDO $pdo, string $sql, array $params = [], int $n = 5): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? array_slice($rows, 0, $n) : [];
    } catch (Throwable $e) {
        return [['error' => $e->getMessage()]];
    }
}
function tableHtml(string $title, array $rows, array $cols, string $emptyHint = ''): string {
    $h = '<div class="sec"><h2>' . htmlspecialchars($title) . '</h2>';
    if (empty($rows) || (isset($rows[0]['error']))) {
        $err = $rows[0]['error'] ?? null;
        $h .= '<div class="empty">' . htmlspecialchars($err ?? $emptyHint ?: '(sin filas)') . '</div>';
    } else {
        $h .= '<table><thead><tr>';
        foreach ($cols as $c) $h .= '<th>' . htmlspecialchars($c) . '</th>';
        $h .= '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $h .= '<tr>';
            foreach ($cols as $c) {
                $v = $r[$c] ?? '';
                if (is_string($v) && strlen($v) > 200) $v = substr($v, 0, 200) . '…';
                $h .= '<td>' . htmlspecialchars((string)$v) . '</td>';
            }
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';
    }
    return $h . '</div>';
}

// Detect which optional columns exist before SELECT so the query never
// errors on legacy schemas. Build the column list dynamically.
$verCols = ['id', 'freg', 'telefono'];
foreach (['curp','expected_curp','verified_curp','curp_match',
          'truora_process_id','truora_status','truora_failure_status',
          'truora_declined_reason','truora_last_event','identity_status','approved'] as $c) {
    try {
        $r = $pdo->query("SHOW COLUMNS FROM verificaciones_identidad LIKE " . $pdo->quote($c))->fetch();
        if ($r) $verCols[] = $c;
    } catch (Throwable $e) {}
}
$verRows = fetchLast($pdo, "SELECT " . implode(',', $verCols) . " FROM verificaciones_identidad ORDER BY id DESC LIMIT 5");

$tokRows  = fetchLast($pdo, "SELECT id, freg, account_id, http_code, SUBSTRING(response, 1, 200) AS resp
    FROM truora_token_log ORDER BY id DESC LIMIT 5");

$hookRows = fetchLast($pdo, "SELECT id, received_at, signature_valid, store_error, event_count,
        SUBSTRING(decoded, 1, 250) AS decoded_preview
    FROM truora_webhook_log ORDER BY id DESC LIMIT 5");

$fetchRows = fetchLast($pdo, "SELECT id, fetched_at, process_id, url, http_code,
        SUBSTRING(response, 1, 250) AS resp_preview, curl_err
    FROM truora_fetch_log ORDER BY id DESC LIMIT 5");

$auditRows = fetchLast($pdo, "SELECT id, created_at, process_id, expected_curp, verified_curp, curp_source, decision
    FROM truora_curp_audit ORDER BY id DESC LIMIT 5");

// ── Health diagnosis ──────────────────────────────────────────────────
$health = [];
$lastTok  = $tokRows[0]['freg'] ?? null;
$lastHook = $hookRows[0]['received_at'] ?? null;
$lastVer  = $verRows[0]['freg'] ?? null;

if (!$lastTok) {
    $health[] = ['err', 'truora_token_log vacío. Nadie ha alcanzado el paso credito-identidad. ¿La SPA llega hasta el iframe?'];
} else {
    $health[] = ['ok', 'Tokens emitidos. Último: ' . $lastTok];
}

if (!$lastHook) {
    $health[] = ['err', 'truora_webhook_log vacío. Truora NO está llamando a nuestro webhook. Acción: en el dashboard de Truora, revisar Webhook → cambiar Subproduct de "Face validation" a "Identity Process" o equivalente que cubra el flow real, y verificar que la URL es exactamente https://voltika.mx/configurador_prueba/php/truora-webhook.php (sin www).'];
} else {
    $health[] = ['ok', 'Webhook recibe eventos. Último: ' . $lastHook];
    $invalidSig = false;
    foreach ($hookRows as $r) {
        if (isset($r['signature_valid']) && $r['signature_valid'] === '0') { $invalidSig = true; break; }
    }
    if ($invalidSig) $health[] = ['warn', 'Algunos webhooks llegaron con firma inválida — TRUORA_WEBHOOK_SECRET puede estar desincronizado.'];
}

$gotVerifiedCurp = false;
foreach ($auditRows as $r) {
    if (!empty($r['verified_curp'])) { $gotVerifiedCurp = true; break; }
}
if ($auditRows && !$gotVerifiedCurp) {
    $health[] = ['err', 'truora_curp_audit muestra que NO hemos podido extraer verified_curp en ningún caso. El payload del webhook no incluye CURP y nuestros 3 endpoints API candidatos también fallan. Acción: pedir a Truora support el endpoint exacto para obtener person_information de un proceso digital-identity completado.'];
}

$mismatchCount = 0;
foreach ($auditRows as $r) {
    if (($r['decision'] ?? '') === 'mismatch') $mismatchCount++;
}
if ($mismatchCount) $health[] = ['warn', "Detectados {$mismatchCount} CURP mismatch en los últimos 5 procesos — fraude bloqueado correctamente."];

if ($verRows) {
    $blocked = 0;
    foreach ($verRows as $r) {
        if (($r['approved'] ?? '0') === '0' && in_array($r['truora_declined_reason'] ?? '', ['verified_curp_unavailable', 'identity_curp_mismatch'])) {
            $blocked++;
        }
    }
    if ($blocked === count($verRows)) {
        $health[] = ['err', 'TODOS los últimos procesos fueron bloqueados por la verificación CURP. Esto indica que el sistema está rechazando incluso usuarios legítimos. Causa más probable: no logramos extraer verified_curp del webhook payload ni del API. Revisar truora_fetch_log abajo y compartir el response con Truora support.'];
    }
}
?>
<!doctype html>
<html lang="es"><head>
<meta charset="utf-8">
<title>Voltika · Truora Pipeline Diag</title>
<style>
body{font-family:system-ui,-apple-system,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1280px;margin:0 auto;line-height:1.5;}
h1{font-size:22px;margin:0 0 4px;}
h2{font-size:16px;color:#475569;margin:24px 0 8px;}
.sec{background:#fff;padding:14px 16px;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:14px;}
table{width:100%;border-collapse:collapse;font-size:11.5px;font-family:ui-monospace,Menlo,monospace;}
th{background:#f1f5f9;text-align:left;padding:6px 8px;font-size:11px;}
td{padding:6px 8px;border-top:1px solid #f1f5f9;vertical-align:top;word-break:break-all;}
.empty{color:#94a3b8;font-style:italic;font-size:13px;}
.health{padding:14px;background:#fef9c3;border:1px solid #facc15;border-radius:10px;margin-bottom:18px;}
.health li{margin:4px 0;list-style:none;padding-left:24px;position:relative;font-size:13px;}
.health .ok::before  {content:'✓';color:#22c55e;position:absolute;left:0;font-weight:700;}
.health .warn::before{content:'⚠';color:#f59e0b;position:absolute;left:0;font-weight:700;}
.health .err::before {content:'✗';color:#ef4444;position:absolute;left:0;font-weight:700;}
</style></head>
<body>

<h1>🔬 Truora Pipeline Diagnostic</h1>
<p style="color:#64748b;font-size:13px;margin-top:0;">Visita esta página después de cada test para ver dónde se detiene el pipeline.</p>

<div class="health"><strong>Health summary</strong><ul>
<?php foreach ($health as [$lvl, $msg]): ?>
  <li class="<?= htmlspecialchars($lvl) ?>"><?= htmlspecialchars($msg) ?></li>
<?php endforeach; ?>
</ul></div>

<?= tableHtml('1. verificaciones_identidad (último estado por proceso)', $verRows,
    $verCols,
    'Sin filas — la SPA no llega al paso de identidad.') ?>

<?= tableHtml('2. truora_token_log (token issued?)', $tokRows,
    ['id','freg','account_id','http_code','resp']) ?>

<?= tableHtml('3. truora_webhook_log (Truora called us back?)', $hookRows,
    ['id','received_at','signature_valid','store_error','event_count','decoded_preview'],
    '⚠ VACÍO. Truora no está llamando al webhook. Revisar dashboard.') ?>

<?= tableHtml('4. truora_fetch_log (API fetch para obtener CURP)', $fetchRows,
    ['id','fetched_at','process_id','url','http_code','resp_preview','curl_err'],
    'Sin filas — el webhook nunca llegó al punto de hacer la llamada API.') ?>

<?= tableHtml('5. truora_curp_audit (decisión de comparación)', $auditRows,
    ['id','created_at','process_id','expected_curp','verified_curp','curp_source','decision'],
    'Sin filas — el webhook nunca llegó al paso de comparación CURP.') ?>

<div class="sec" style="background:#eff6ff;border-color:#3b82f6;">
<h2 style="color:#1e40af;margin-top:0;">📋 Acciones según el resultado</h2>
<ul style="font-size:13px;line-height:1.7;margin:6px 0;padding-left:20px;">
<li><b>Sec 3 vacía</b> → Truora dashboard: Webhook subproduct = "Face validation" probablemente. Cambiar a "Identity Process" o el opción que cubra cualquier evento del flow.</li>
<li><b>Sec 3 con filas + Sec 4 con http_code 404/401</b> → endpoint API de Truora está mal. Pedir a Truora support: "endpoint to GET full identity process details including national_id_number".</li>
<li><b>Sec 4 con http_code 200 pero verified_curp NULL en Sec 5</b> → respuesta de Truora no incluye CURP en los campos que buscamos. Compartir el `resp_preview` para ajustar truoraExtractCurp.</li>
<li><b>Sec 5 con decision="match"</b> → ✓ Sistema funcionando. El usuario debería avanzar a credito-enganche.</li>
<li><b>Sec 5 con decision="mismatch"</b> → ✓ Fraude bloqueado correctamente.</li>
</ul>
</div>

</body></html>
