<?php
/**
 * Truora diagnostic tool — one page, no auth required when accessed with
 * ?key=<TRUORA_DIAG_KEY> query param. Shows:
 *   1) Current config (API key presence, endpoints, flags)
 *   2) Last 20 entries from truora_query_log
 *   3) Last 10 rows of verificaciones_identidad
 *   4) Ping test to api.checks.truora.com (creates a synthetic person check)
 *
 * Purpose: when "Truora not working" is reported, this page exposes the real
 * HTTP responses and timing so the root cause is identifiable in seconds
 * instead of hunting through logs.
 */
require_once __DIR__ . '/config.php';

// Simple guard: ?key=xxx must match TRUORA_DIAG_KEY env var (set in .env).
// If env var is empty, ALLOW the page (dev-mode) but display a warning.
$expectedKey = getenv('TRUORA_DIAG_KEY') ?: '';
$providedKey = $_GET['key'] ?? '';
if ($expectedKey !== '' && $providedKey !== $expectedKey) {
    http_response_code(403);
    echo 'Forbidden. Append ?key=... with the diagnostic key from .env.';
    exit;
}

$pdo = getDB();
$action = $_GET['action'] ?? 'dashboard';
header('Content-Type: text/html; charset=utf-8');

$truoraKey = defined('TRUORA_API_KEY') ? TRUORA_API_KEY : '';

// ── Action: ping ──────────────────────────────────────────────────────────
// Creates a tiny person check to Truora so we can see the real response.
// Uses obviously synthetic data so it doesn't consume quota on a real query.
if ($action === 'ping') {
    $testFields = [
        'country'         => 'MX',
        'type'            => 'person',
        'user_authorized' => 'true',
        'first_name'      => 'DIAG',
        'last_name'       => 'TEST',
        'gender'          => 'M',
        'state_id'        => 'CDMX',
        'date_of_birth'   => '1990-01-01',
    ];
    $body = http_build_query($testFields);
    $t0 = microtime(true);
    $ch = curl_init('https://api.checks.truora.com/v1/checks');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Truora-API-Key: ' . $truoraKey,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    $elapsed  = round((microtime(true) - $t0) * 1000);
    curl_close($ch);
    ?>
    <!DOCTYPE html><html><head><meta charset="utf-8"><title>Truora Ping</title>
    <style>body{font-family:ui-monospace,Menlo,Consolas,monospace;max-width:900px;margin:20px auto;padding:0 14px;}
    pre{background:#f3f4f6;padding:12px;border-radius:6px;font-size:12px;overflow-x:auto;}
    .ok{color:#059669;} .err{color:#b91c1c;} .box{border:1px solid #e5e7eb;border-radius:8px;padding:14px;margin:12px 0;}</style></head><body>
    <h2>Truora Ping Result</h2>
    <div class="box">
      <div>HTTP: <strong class="<?= ($httpCode>=200 && $httpCode<300) ? 'ok' : 'err' ?>"><?= $httpCode ?></strong> · <?= $elapsed ?> ms</div>
      <?php if ($curlErr): ?><div class="err">curl error: <?= htmlspecialchars($curlErr) ?></div><?php endif; ?>
      <?php if (empty($truoraKey)): ?><div class="err">⚠ TRUORA_API_KEY vacía — la llamada falló por falta de credencial.</div><?php endif; ?>
    </div>
    <h3>Request body</h3>
    <pre><?= htmlspecialchars($body) ?></pre>
    <h3>Response body</h3>
    <pre><?= htmlspecialchars((string)$resp) ?></pre>
    <p><a href="?<?= $expectedKey !== '' ? 'key=' . urlencode($providedKey) : '' ?>">← Back</a></p>
    </body></html>
    <?php
    exit;
}

// ── Default: dashboard ────────────────────────────────────────────────────
// Optional filters — narrow down to one customer's attempts when "Truora
// not working" is reported by a specific person.
$filterName  = trim((string)($_GET['nombre']   ?? ''));
$filterPhone = trim((string)($_GET['telefono'] ?? ''));
$filterPhoneDigits = preg_replace('/\D/', '', $filterPhone);
if (strlen($filterPhoneDigits) > 10) $filterPhoneDigits = substr($filterPhoneDigits, -10);

$where = []; $params = [];
if ($filterName !== '') {
    $where[] = "(nombre LIKE ? OR apellidos LIKE ?)";
    $params[] = '%' . $filterName . '%';
    $params[] = '%' . $filterName . '%';
}
if ($filterPhoneDigits !== '') {
    $where[] = "body_sent LIKE ?";
    $params[] = '%' . $filterPhoneDigits . '%';
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$recent = [];
try {
    $sql = "SELECT id, action, nombre, apellidos, email, http_code,
                   body_sent, response, curl_err, freg
            FROM truora_query_log" . $whereSql . " ORDER BY id DESC LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$verifs = [];
try {
    $vWhere = []; $vParams = [];
    if ($filterName !== '') {
        $vWhere[] = "(nombre LIKE ? OR apellidos LIKE ?)";
        $vParams[] = '%' . $filterName . '%';
        $vParams[] = '%' . $filterName . '%';
    }
    if ($filterPhoneDigits !== '') {
        $vWhere[] = "telefono LIKE ?";
        $vParams[] = '%' . $filterPhoneDigits . '%';
    }
    $vSql = "SELECT id, nombre, apellidos, telefono, fecha_nacimiento, truora_check_id, truora_score, identity_status,
                    approved, face_check_id, face_score, face_match,
                    doc_check_id, doc_status, webhook_received_at, freg
             FROM verificaciones_identidad"
            . ($vWhere ? ' WHERE ' . implode(' AND ', $vWhere) : '')
            . " ORDER BY id DESC LIMIT 20";
    $stmt = $pdo->prepare($vSql);
    $stmt->execute($vParams);
    $verifs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

function trDecodeResp(?string $resp): string {
    if ($resp === null || $resp === '') return '(vacío)';
    $decoded = json_decode($resp, true);
    if (is_array($decoded)) {
        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return $resp;
}

function trDecodeBody(?string $body): string {
    if ($body === null || $body === '') return '(vacío)';
    // body_sent is application/x-www-form-urlencoded — split & decode for readability
    $pairs = explode('&', $body);
    $out = [];
    foreach ($pairs as $p) {
        if ($p === '') continue;
        $eq = strpos($p, '=');
        if ($eq === false) { $out[] = urldecode($p); continue; }
        $k = urldecode(substr($p, 0, $eq));
        $v = urldecode(substr($p, $eq + 1));
        $out[] = str_pad($k, 18) . ' = ' . $v;
    }
    return implode("\n", $out);
}

?>
<!DOCTYPE html>
<html><head><meta charset="utf-8">
<title>Truora Diagnostic</title>
<style>
body{font-family:ui-monospace,Menlo,Consolas,monospace;max-width:1100px;margin:20px auto;padding:0 14px;color:#111;}
h2,h3{margin:18px 0 8px;}
.box{border:1px solid #e5e7eb;border-radius:8px;padding:12px 14px;margin:10px 0;background:#fff;}
.grid{display:grid;grid-template-columns:auto 1fr;gap:6px 14px;font-size:13px;}
.ok{color:#059669;} .err{color:#b91c1c;} .warn{color:#b45309;}
table{width:100%;border-collapse:collapse;font-size:12px;margin-top:8px;}
th,td{text-align:left;padding:6px 8px;border-bottom:1px solid #e5e7eb;vertical-align:top;}
th{background:#f9fafb;font-weight:600;}
pre{background:#f3f4f6;padding:8px;border-radius:4px;font-size:11px;max-height:120px;overflow:auto;margin:0;}
.btn{display:inline-block;background:#039fe1;color:#fff;padding:8px 14px;border-radius:6px;text-decoration:none;font-weight:700;font-size:13px;}
.btn:hover{opacity:.9;}
</style></head><body>
<h2>Truora Diagnostic Dashboard</h2>

<div class="box">
<h3 style="margin:0 0 8px;">Configuración</h3>
<div class="grid">
<div>TRUORA_API_URL</div><div><?= defined('TRUORA_API_URL') ? TRUORA_API_URL : '<span class="err">undefined</span>' ?></div>
<div>TRUORA_FACE_URL</div><div><?= defined('TRUORA_FACE_URL') ? TRUORA_FACE_URL : '<span class="err">undefined</span>' ?></div>
<div>TRUORA_API_KEY</div><div><?= $truoraKey ? '<span class="ok">configurada (' . strlen($truoraKey) . ' chars)</span>' : '<span class="err">VACÍA — verificar-identidad.php fallará</span>' ?></div>
<div>TRUORA_FACE_MATCH_ENABLED</div><div><?= defined('TRUORA_FACE_MATCH_ENABLED') && TRUORA_FACE_MATCH_ENABLED ? '<span class="ok">ON</span>' : '<span class="warn">OFF — face match desactivado</span>' ?></div>
<div>TRUORA_DOC_VALIDATION_ENABLED</div><div><?= defined('TRUORA_DOC_VALIDATION_ENABLED') && TRUORA_DOC_VALIDATION_ENABLED ? '<span class="ok">ON</span>' : '<span class="warn">OFF — document validation desactivado</span>' ?></div>
<div>TRUORA_WEBHOOK_SECRET</div><div><?= defined('TRUORA_WEBHOOK_SECRET') && TRUORA_WEBHOOK_SECRET !== '' ? '<span class="ok">configurado</span>' : '<span class="warn">VACÍO — webhooks no firmados, checks pueden quedar sin cerrar</span>' ?></div>
</div>
<div style="margin-top:10px;"><a class="btn" href="?action=ping<?= $expectedKey !== '' ? '&key=' . urlencode($providedKey) : '' ?>">Ejecutar Ping Test</a></div>
</div>

<div class="box">
<h3 style="margin:0 0 8px;">Filtrar registros</h3>
<form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <?php if ($expectedKey !== ''): ?>
        <input type="hidden" name="key" value="<?= htmlspecialchars($providedKey) ?>">
    <?php endif; ?>
    <input type="text" name="nombre"   placeholder="Nombre o apellido" value="<?= htmlspecialchars($filterName) ?>"  style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;flex:1;min-width:160px;">
    <input type="text" name="telefono" placeholder="Teléfono"          value="<?= htmlspecialchars($filterPhone) ?>" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;flex:1;min-width:160px;">
    <button type="submit" class="btn" style="border:none;cursor:pointer;">Filtrar</button>
    <?php if ($filterName !== '' || $filterPhone !== ''): ?>
        <a href="?<?= $expectedKey !== '' ? 'key=' . urlencode($providedKey) : '' ?>" style="color:#6b7280;text-decoration:underline;font-size:12px;">limpiar</a>
    <?php endif; ?>
</form>
<div style="margin-top:6px;color:#6b7280;font-size:12px;">Ejemplo — cliente del screenshot: <code>?telefono=525514516605</code>. Filtra tanto truora_query_log como verificaciones_identidad.</div>
</div>

<h3>Últimas llamadas a Truora (<?= count($recent) ?>)<?= ($filterName !== '' || $filterPhone !== '') ? ' — filtrado' : '' ?></h3>
<?php if (!$recent): ?>
<div class="box warn">Sin registros que coincidan. <?= ($filterName !== '' || $filterPhone !== '') ? 'Prueba limpiar el filtro o verifica el teléfono.' : 'truora_query_log vacía o tabla no existe.' ?></div>
<?php else: ?>
<?php foreach ($recent as $r): ?>
<div class="box">
    <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px;">
        <span><strong><?= htmlspecialchars($r['freg']) ?></strong> · <?= htmlspecialchars($r['action']) ?> · <?= htmlspecialchars(($r['nombre'] ?? '') . ' ' . ($r['apellidos'] ?? '')) ?></span>
        <span>HTTP: <strong class="<?= ($r['http_code']>=200 && $r['http_code']<300) ? 'ok' : 'err' ?>"><?= $r['http_code'] ?></strong></span>
    </div>
    <?php if (!empty($r['curl_err'])): ?>
        <div class="err" style="font-size:12px;margin-bottom:6px;">curl: <?= htmlspecialchars($r['curl_err']) ?></div>
    <?php endif; ?>
    <details <?= ($filterName !== '' || $filterPhone !== '') ? 'open' : '' ?>>
        <summary style="cursor:pointer;font-size:12px;color:#374151;">📤 Body enviado a Truora</summary>
        <pre style="max-height:none;"><?= htmlspecialchars(trDecodeBody($r['body_sent'] ?? '')) ?></pre>
    </details>
    <details <?= ($filterName !== '' || $filterPhone !== '') ? 'open' : '' ?>>
        <summary style="cursor:pointer;font-size:12px;color:#374151;">📥 Respuesta de Truora</summary>
        <pre style="max-height:none;"><?= htmlspecialchars(trDecodeResp($r['response'] ?? '')) ?></pre>
    </details>
</div>
<?php endforeach; ?>
<?php endif; ?>

<h3>Verificaciones de identidad (<?= count($verifs) ?>)<?= ($filterName !== '' || $filterPhone !== '') ? ' — filtrado' : '' ?></h3>
<?php if (!$verifs): ?>
<div class="box warn">Sin verificaciones que coincidan.</div>
<?php else: ?>
<table>
<tr>
<th>ID</th><th>Cliente</th><th>Teléfono</th><th>Fecha Nac.</th><th>check_id</th><th>Score</th><th>Status</th>
<th>Approved</th><th>Face match</th><th>Doc status</th><th>Webhook</th><th>Fecha</th>
</tr>
<?php foreach ($verifs as $v): ?>
<tr>
<td><?= $v['id'] ?></td>
<td><?= htmlspecialchars(($v['nombre'] ?? '') . ' ' . ($v['apellidos'] ?? '')) ?></td>
<td><?= htmlspecialchars($v['telefono'] ?? '') ?></td>
<td><?= htmlspecialchars($v['fecha_nacimiento'] ?? '') ?></td>
<td style="font-size:10px;"><code><?= htmlspecialchars($v['truora_check_id'] ?? '') ?></code></td>
<td><?= $v['truora_score'] !== null ? $v['truora_score'] : '—' ?></td>
<td><?= htmlspecialchars($v['identity_status'] ?? '') ?></td>
<td class="<?= $v['approved'] ? 'ok' : 'err' ?>"><?= $v['approved'] ? '✓' : '✗' ?></td>
<td style="font-size:10px;"><?= $v['face_score'] !== null ? 'score ' . $v['face_score'] : '—' ?> <?= $v['face_match'] === null ? '' : ($v['face_match'] ? '<span class="ok">match</span>' : '<span class="err">mismatch</span>') ?></td>
<td style="font-size:10px;"><?= htmlspecialchars($v['doc_status'] ?? '—') ?></td>
<td><?= $v['webhook_received_at'] ? '<span class="ok">sí</span>' : '<span class="warn">no</span>' ?></td>
<td><?= htmlspecialchars($v['freg']) ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<h3>Archivos de log</h3>
<div class="box">
<div style="font-size:12px;color:#555;margin-bottom:6px;">Tail de <code>php/logs/truora.log</code>:</div>
<pre><?php
$log = __DIR__ . '/logs/truora.log';
if (file_exists($log)) {
    $size = filesize($log);
    $offset = max(0, $size - 5000);
    $fh = fopen($log, 'rb');
    if ($fh) { fseek($fh, $offset); echo htmlspecialchars(fread($fh, 5000)); fclose($fh); }
} else {
    echo '(log no existe)';
}
?></pre>
</div>

</body></html>
