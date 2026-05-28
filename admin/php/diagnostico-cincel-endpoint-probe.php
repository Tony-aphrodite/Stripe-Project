<?php
/**
 * Voltika Admin — Cincel endpoint probe (Round 113, 2026-05-28).
 *
 * Problem: POST /v3/timestamps returns HTTP 404. /v3/tokens/jwt works.
 * Cincel changed something — endpoint moved, renamed, or migrated.
 *
 * This probe iterates common timestamp endpoint variations and reports
 * HTTP code + response body for each. Any non-404 indicates a hit.
 *
 * URL: /admin/php/diagnostico-cincel-endpoint-probe.php?key=voltika_diag_2026
 */

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
adminRequireAuth(['admin']);

$expected = 'voltika_diag_2026';
if (!hash_equals($expected, (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "Acceso denegado. Usa ?key=voltika_diag_2026";
    exit;
}

@require_once __DIR__ . '/../../configurador/php/config.php';
@require_once __DIR__ . '/../../configurador/php/cincel-timestamp.php';

$jwt = cincelGetJWT();
if (!$jwt) {
    header('Content-Type: text/plain');
    echo "❌ No se pudo obtener JWT — abortando.\n";
    exit;
}

// Use a known sha256 (from any signed PDF) for the probe payloads.
$probeHash = '59c92520ba6d287ff2b93543330e8007e5028ed8d073a2f40bab9d067145bed4';
$rootHost  = 'https://api.cincel.digital';

// ── Probe definitions ───────────────────────────────────────────────────
// Each probe: method, path, body (optional), description.
$probes = [
    // POST timestamp creation variations
    ['POST', '/v3/timestamps',           ['hash' => $probeHash], 'Original endpoint (was working before)'],
    ['POST', '/v3/timestamp',            ['hash' => $probeHash], 'Singular'],
    ['POST', '/v3/timestamps/create',    ['hash' => $probeHash], 'Explicit /create suffix'],
    ['POST', '/v3/nom151',               ['hash' => $probeHash], 'NOM-151 service name'],
    ['POST', '/v3/nom-151',              ['hash' => $probeHash], 'NOM-151 with dash'],
    ['POST', '/v3/seals',                ['hash' => $probeHash], 'Seals plural'],
    ['POST', '/v3/seal',                 ['hash' => $probeHash], 'Seal singular'],
    ['POST', '/v3/stamps',               ['hash' => $probeHash], 'Stamps plural'],
    ['POST', '/v3/stamp',                ['hash' => $probeHash], 'Stamp singular'],
    ['POST', '/v3/documents/timestamp',  ['hash' => $probeHash], 'Under documents resource'],
    ['POST', '/v3/document/timestamp',   ['hash' => $probeHash], 'Document singular'],
    ['POST', '/v3/c-doc/timestamps',     ['hash' => $probeHash], 'Under c-doc resource'],
    ['POST', '/v3/cdoc/timestamps',      ['hash' => $probeHash], 'Under cdoc (no dash)'],
    ['POST', '/v3/cdoc',                 ['hash' => $probeHash], 'Just /cdoc'],

    // v4 variations
    ['POST', '/v4/timestamps',           ['hash' => $probeHash], 'v4 migration'],
    ['POST', '/v4/timestamp',            ['hash' => $probeHash], 'v4 singular'],
    ['POST', '/v4/nom151',               ['hash' => $probeHash], 'v4 NOM-151'],
    ['POST', '/v4/seals',                ['hash' => $probeHash], 'v4 seals'],

    // GET endpoints to discover what's available (no body)
    ['GET',  '/v3/timestamps/' . $probeHash, null, 'GET timestamp by hash (existing endpoint)'],
    ['GET',  '/v3/credits',              null, 'Account credits info'],
    ['GET',  '/v3/me',                   null, 'User profile'],
    ['GET',  '/v3/user',                 null, 'User resource'],
    ['GET',  '/v3/account',              null, 'Account resource'],
    ['GET',  '/v3/services',             null, 'Available services'],
    ['GET',  '/v3/products',             null, 'Available products'],
];

function _probeCincel(string $method, string $url, ?array $body, string $jwt): array {
    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $jwt];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_CUSTOMREQUEST  => $method,
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $start = microtime(true);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    $ms   = (int)((microtime(true) - $start) * 1000);
    curl_close($ch);

    return [
        'http'    => $code,
        'body'    => $raw,
        'curl_err'=> $err ?: null,
        'ms'      => $ms,
    ];
}

$results = [];
foreach ($probes as $p) {
    [$method, $path, $body, $desc] = $p;
    $url = $rootHost . $path;
    $r = _probeCincel($method, $url, $body, $jwt);
    $r['method'] = $method;
    $r['path']   = $path;
    $r['desc']   = $desc;
    $results[] = $r;
}

// ── Render ──────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Cincel endpoint probe</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fb;color:#0c2340;padding:24px;max-width:1180px;margin:0 auto;line-height:1.5;}
h1{font-size:22px;margin:0 0 4px;}
.muted{color:#94a3b8;font-size:12px;margin-bottom:16px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:14px;}
table{border-collapse:collapse;width:100%;font-size:12px;}
th,td{border-bottom:1px solid #e2e8f0;padding:7px 8px;text-align:left;vertical-align:top;}
th{background:#f1f5f9;color:#475569;font-size:11px;text-transform:uppercase;letter-spacing:.4px;}
.method{font-family:ui-monospace,monospace;font-size:10.5px;background:#0c2340;color:#fff;padding:1px 5px;border-radius:3px;}
.path{font-family:ui-monospace,monospace;font-size:11.5px;}
.h-200,.h-201{background:#dcfce7;color:#166534;font-weight:700;}
.h-401,.h-403{background:#fef3c7;color:#92400e;font-weight:700;}
.h-404{background:#fee2e2;color:#991b1b;}
.h-405{background:#dbeafe;color:#1e40af;font-weight:700;}
.h-422,.h-400{background:#fed7aa;color:#9a3412;font-weight:700;}
.h-500{background:#fecaca;color:#7f1d1d;font-weight:700;}
.body{font-family:ui-monospace,monospace;font-size:10.5px;color:#475569;background:#f8fafc;padding:4px 6px;border-radius:3px;max-width:500px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.hit{background:#fef9c3 !important;}
.banner{padding:12px 14px;border-radius:8px;font-size:14px;margin-bottom:14px;}
.banner-warn{background:#fef3c7;border:1px solid #fcd34d;color:#78350f;font-weight:600;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;font-weight:600;}
</style></head><body>

<h1>🔍 Cincel endpoint probe</h1>
<div class="muted"><?= date('Y-m-d H:i:s') ?> · Hash probe: <code><?= htmlspecialchars($probeHash) ?></code></div>

<?php
$hits = array_filter($results, fn($r) => $r['http'] !== 0 && $r['http'] !== 404);
?>
<?php if ($hits): ?>
<div class="banner banner-ok">
    ✅ Encontrados <?= count($hits) ?> endpoint(s) que NO devuelven 404. Revísalos abajo (resaltados en amarillo).
</div>
<?php else: ?>
<div class="banner banner-warn">
    ⚠️ TODOS los endpoints probados devolvieron 404 o error. La API de Cincel cambió radicalmente —
    es necesario contactar a soporte para conocer la nueva ruta.
</div>
<?php endif; ?>

<div class="card">
  <table>
    <thead><tr>
      <th>#</th><th>Method</th><th>Path</th><th>HTTP</th><th>Body (preview)</th><th>ms</th><th>Descripción</th>
    </tr></thead>
    <tbody>
    <?php foreach ($results as $i => $r):
        $bodyPreview = is_string($r['body']) ? substr($r['body'], 0, 200) : '';
        $isHit = $r['http'] !== 0 && $r['http'] !== 404;
        $rowClass = $isHit ? 'hit' : '';
    ?>
      <tr class="<?= $rowClass ?>">
        <td><?= $i + 1 ?></td>
        <td><span class="method"><?= htmlspecialchars($r['method']) ?></span></td>
        <td class="path"><?= htmlspecialchars($r['path']) ?></td>
        <td><span class="h-<?= $r['http'] ?>"><?= $r['http'] ?: 'ERR' ?></span></td>
        <td class="body"><?= htmlspecialchars($bodyPreview) ?></td>
        <td><?= $r['ms'] ?></td>
        <td><?= htmlspecialchars($r['desc']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<details>
  <summary style="cursor:pointer;color:#475569;font-size:12px;">Respuesta completa (JSON)</summary>
  <pre style="font-size:10.5px;background:#f8fafc;padding:10px;border:1px solid #e2e8f0;border-radius:6px;max-height:400px;overflow:auto;"><?= htmlspecialchars(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
</details>

</body></html>
