<?php
/**
 * Truora iframe integration diagnostic page.
 *
 * Checks every prerequisite for the iframe flow so operators can verify
 * the setup without running through the full credit application. Shows
 * which env vars are set, pings Truora for a test token, and lists the
 * most recent webhook events received.
 *
 * Access: /configurador_prueba/php/truora-iframe-diag.php?key=voltika_truora_2026
 */

$secret   = $_GET['key'] ?? '';
$expected = getenv('TRUORA_DIAG_KEY') ?: 'voltika_truora_2026';
if ($secret !== $expected) { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

$apiKey        = defined('TRUORA_API_KEY') ? TRUORA_API_KEY : '';
$webhookSecret = defined('TRUORA_WEBHOOK_SECRET') ? TRUORA_WEBHOOK_SECRET : '';
$flowId        = defined('TRUORA_FLOW_ID') ? TRUORA_FLOW_ID : '';
$identityUrl   = defined('TRUORA_IDENTITY_API_URL') ? TRUORA_IDENTITY_API_URL : '';
$voltikaBase   = defined('VOLTIKA_BASE_URL') ? VOLTIKA_BASE_URL : '';

$action = $_GET['action'] ?? '';
$pingResult = null;

if ($action === 'ping' && $apiKey && $flowId) {
    // Try to generate a throwaway API key to prove the flow is valid.
    $body = http_build_query([
        'key_type'     => 'web',
        'grant'        => 'digital-identity',
        'flow_id'      => $flowId,
        'country'      => 'MX',
        'account_id'   => 'voltika_diag_' . time(),
        'redirect_url' => rtrim($voltikaBase, '/') . '/configurador_prueba/',
    ]);
    $t0 = microtime(true);
    $ch = curl_init(rtrim($identityUrl, '/') . '/v1/api-keys');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Truora-API-Key: ' . $apiKey,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    $pingResult = [
        'http'    => $httpCode,
        'elapsed' => round((microtime(true) - $t0) * 1000),
        'body'    => (string)$resp,
        'curlErr' => $curlErr,
    ];
}

$recentHooks = [];
try {
    $pdo = getDB();
    $pdo->query("SELECT 1 FROM truora_webhook_log LIMIT 1");
    $recentHooks = $pdo->query("SELECT received_at, signature_valid, store_error, event_count,
            LEFT(decoded, 1200) AS decoded
        FROM truora_webhook_log ORDER BY id DESC LIMIT 10")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* table may not exist yet */ }

$recentTokens = [];
try {
    $pdo = getDB();
    $pdo->query("SELECT 1 FROM truora_token_log LIMIT 1");
    $recentTokens = $pdo->query("SELECT freg, account_id, http_code, LEFT(response,400) AS resp
        FROM truora_token_log ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

function mask(string $s, int $keep = 3): string {
    $l = strlen($s);
    if ($l === 0) return '(vacío)';
    if ($l <= $keep * 2) return str_repeat('*', $l);
    return substr($s, 0, $keep) . str_repeat('*', max(1, $l - $keep * 2)) . substr($s, -$keep);
}

?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Truora iframe diagnostic</title>
<style>
body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background:#0f172a; color:#e2e8f0; margin:0; padding:24px; }
.container { max-width:1050px; margin:0 auto; }
h1 { color:#fbbf24; font-size:22px; margin:0 0 14px; }
h2 { color:#60a5fa; font-size:16px; border-bottom:1px solid #334155; padding-bottom:6px; margin:22px 0 12px; }
.card { background:#1e293b; border:1px solid #334155; border-radius:10px; padding:14px; margin:10px 0; }
.kv { display:grid; grid-template-columns:220px 1fr; gap:6px 14px; font-size:13px; font-family:Consolas,monospace; }
.kv > div:nth-child(odd) { color:#94a3b8; }
.ok { color:#10b981; font-weight:700; }
.bad { color:#ef4444; font-weight:700; }
.warn { color:#f59e0b; font-weight:700; }
pre { background:#0b1220; border:1px solid #1e293b; border-radius:6px; padding:10px; font-size:11px; max-height:300px; overflow:auto; color:#cbd5e1; }
a.btn { background:#3b82f6; color:#fff; padding:8px 14px; border-radius:6px; text-decoration:none; font-size:13px; font-weight:600; display:inline-block; }
table { width:100%; border-collapse:collapse; font-size:12px; }
th, td { text-align:left; padding:6px 8px; border-bottom:1px solid #334155; vertical-align:top; }
th { color:#94a3b8; }
</style>
</head>
<body>
<div class="container">
<h1>🧩 Truora iframe Integration Diagnostic</h1>

<h2>1. Configuración</h2>
<div class="card">
<div class="kv">
    <div>TRUORA_API_KEY</div>
    <div><?= $apiKey ? '<span class="ok">configurada</span> ' . mask($apiKey) . ' <em>(' . strlen($apiKey) . ' chars)</em>' : '<span class="bad">VACÍA</span>' ?></div>

    <div>TRUORA_FLOW_ID</div>
    <div><?= $flowId
        ? '<span class="ok">' . htmlspecialchars($flowId) . '</span>'
        : '<span class="bad">VACÍO — configurar en env cuando Truora dé el flow_id</span>' ?></div>

    <div>TRUORA_WEBHOOK_SECRET</div>
    <div><?= $webhookSecret
        ? '<span class="ok">configurado</span> ' . mask($webhookSecret)
        : '<span class="warn">vacío — webhooks llegan pero no se verifican firmas</span>' ?></div>

    <div>TRUORA_IDENTITY_API_URL</div>
    <div><?= htmlspecialchars($identityUrl ?: '(default)') ?></div>

    <div>VOLTIKA_BASE_URL</div>
    <div><?= htmlspecialchars($voltikaBase) ?></div>

    <div>Webhook URL registrado</div>
    <div><?= htmlspecialchars(rtrim($voltikaBase, '/') . '/configurador_prueba/php/truora-webhook.php') ?></div>

    <div>Endpoint token</div>
    <div><?= htmlspecialchars(rtrim($voltikaBase, '/') . '/configurador_prueba/php/truora-token.php') ?></div>
</div>
</div>

<h2>2. Ping — generar token de prueba</h2>
<div class="card">
<?php if (!$apiKey || !$flowId): ?>
<div class="warn">⚠ No se puede hacer ping: falta TRUORA_API_KEY o TRUORA_FLOW_ID.</div>
<?php else: ?>
<a class="btn" href="?key=<?= urlencode($secret) ?>&amp;action=ping">Ejecutar ping</a>
<?php if ($pingResult): ?>
<div style="margin-top:14px;">
    <div>HTTP: <strong class="<?= $pingResult['http']>=200 && $pingResult['http']<300 ? 'ok':'bad' ?>"><?= $pingResult['http'] ?></strong>
    · <?= $pingResult['elapsed'] ?> ms
    <?php if ($pingResult['curlErr']): ?> · <span class="bad">curl: <?= htmlspecialchars($pingResult['curlErr']) ?></span><?php endif; ?>
    </div>
    <pre><?php
        $decoded = json_decode($pingResult['body'], true);
        echo htmlspecialchars(is_array($decoded)
            ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            : $pingResult['body']);
    ?></pre>
    <?php if ($pingResult['http'] >= 200 && $pingResult['http'] < 300): ?>
        <div class="ok">✅ Token generado — el flow_id es válido y la API key tiene permiso digital-identity.</div>
    <?php elseif ($pingResult['http'] === 401): ?>
        <div class="bad">❌ 401 — API key inválida o sin grant digital-identity. Crear nueva key desde el dashboard.</div>
    <?php elseif ($pingResult['http'] === 404): ?>
        <div class="bad">❌ 404 — flow_id no encontrado. Verificar IPFxxxxx en el dashboard.</div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
</div>

<h2>3. Webhooks recientes (<?= count($recentHooks) ?>)</h2>
<?php if (!$recentHooks): ?>
<div class="card warn">Sin webhooks todavía. Truora enviará POST a <code>truora-webhook.php</code> cuando ocurra un evento.</div>
<?php else: ?>
<div class="card" style="padding:0;overflow:auto;">
<table>
<thead><tr><th>Recibido</th><th>Firma</th><th>Eventos</th><th>Error</th><th>Payload</th></tr></thead>
<tbody>
<?php foreach ($recentHooks as $h): ?>
<tr>
    <td><?= htmlspecialchars($h['received_at']) ?></td>
    <td><?= $h['signature_valid'] === null ? '<span class="warn">n/a</span>'
             : ($h['signature_valid'] ? '<span class="ok">válida</span>' : '<span class="bad">inválida</span>') ?></td>
    <td><?= (int)$h['event_count'] ?></td>
    <td><?= htmlspecialchars($h['store_error'] ?? '—') ?></td>
    <td><pre style="max-height:140px;"><?= htmlspecialchars((string)$h['decoded']) ?></pre></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<h2>4. Tokens generados recientes (<?= count($recentTokens) ?>)</h2>
<?php if (!$recentTokens): ?>
<div class="card warn">Sin tokens todavía. Cada visita a truora-token.php desde el configurador crea una entrada aquí.</div>
<?php else: ?>
<div class="card" style="padding:0;overflow:auto;">
<table>
<thead><tr><th>Fecha</th><th>account_id</th><th>HTTP</th><th>Respuesta</th></tr></thead>
<tbody>
<?php foreach ($recentTokens as $t): ?>
<tr>
    <td><?= htmlspecialchars($t['freg']) ?></td>
    <td><?= htmlspecialchars($t['account_id'] ?? '') ?></td>
    <td class="<?= ($t['http_code']>=200 && $t['http_code']<300)?'ok':'bad' ?>"><?= (int)$t['http_code'] ?></td>
    <td><pre style="max-height:120px;"><?= htmlspecialchars((string)$t['resp']) ?></pre></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<h2>5. Checklist de pasos pendientes</h2>
<div class="card" style="font-size:13px;line-height:1.7;">
<div><?= $flowId ? '<span class="ok">✅</span>' : '<span class="bad">⬜</span>' ?> Flow ID obtenido de Truora (IPF...)</div>
<div><?= $apiKey ? '<span class="ok">✅</span>' : '<span class="bad">⬜</span>' ?> API key permanente en env (TRUORA_API_KEY)</div>
<div><?= $webhookSecret ? '<span class="ok">✅</span>' : '<span class="warn">🟡</span>' ?> Webhook signing secret configurado (recomendado)</div>
<div><?= !empty($recentHooks) ? '<span class="ok">✅</span>' : '<span class="warn">🟡</span>' ?> Webhook ya recibió al menos un evento de Truora</div>
<div><?= !empty($recentTokens) ? '<span class="ok">✅</span>' : '<span class="warn">🟡</span>' ?> Token endpoint ya fue usado (por lo menos 1 vez)</div>
<div>⬜ Dominio voltika.mx registrado en whitelist de Truora (pedir a soporte)</div>
</div>

<div style="text-align:center;color:#64748b;margin-top:40px;font-size:11px;">
    Voltika Truora iframe diagnostic · <?= date('Y-m-d H:i:s') ?>
</div>
</div>
</body>
</html>
