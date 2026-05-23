<?php
/**
 * Voltika Admin — Diagnóstico Cincel Timestamp CREATE (Round 71, 2026-05-23).
 *
 * El probe anterior (diagnostico-cincel-timestamp.php) solo prueba el GET
 * público — confirma que el endpoint vive y responde, pero no consume
 * créditos ni valida el JWT. Esto es un test READ-ONLY.
 *
 * Este diagnóstico ejecuta el flujo COMPLETO de producción:
 *
 *   1. Pide JWT a Cincel con HTTP Basic + email/password configurados.
 *   2. Selecciona un PDF firmado de la carpeta de contratos.
 *   3. Calcula su sha256.
 *   4. Llama cincelGetOrCreateTimestamp() del módulo cincel-timestamp.php
 *      → si Cincel ya tiene un sello para ese hash, lo recupera sin gastar
 *        créditos (idempotente).
 *      → si no, POSTea para crearlo (consume 1 c.Doc).
 *   5. Persiste el resultado en cincel_timestamps (si la conexión a DB está
 *      disponible) y muestra las URLs de los certificados.
 *
 * Esta página es la verificación previa al hookeo en confirmar-orden.php.
 * Una vez que aquí veamos un timestamp creado y descargable, sabemos que el
 * módulo está listo para usarse en producción de forma automática.
 *
 * URL: /admin/php/diagnostico-cincel-timestamp-create.php?key=voltika_diag_2026
 *
 * Por seguridad la creación NO se dispara con un simple GET — el admin
 * debe confirmar con ?confirmar=1 para evitar consumo accidental de créditos.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$adminId = adminRequireAuth(['admin']);

$expected = 'voltika_diag_2026';
$key = $_GET['key'] ?? '';
if (!hash_equals($expected, (string)$key)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Acceso denegado. Usa ?key=voltika_diag_2026";
    exit;
}

@require_once __DIR__ . '/../../configurador/php/config.php';
@require_once __DIR__ . '/../../configurador/php/cincel-timestamp.php';

// ── Locate available signed contract PDFs ────────────────────────────────
function _scanContractPdfs(): array {
    $dirs = [
        __DIR__ . '/../../configurador/contratos/contado',
        __DIR__ . '/../../configurador/contratos/credito',
        __DIR__ . '/../../configurador/contratos/msi',
        __DIR__ . '/../../configurador/contratos',
        sys_get_temp_dir() . '/voltika_contratos_contado',
        sys_get_temp_dir() . '/voltika_contratos_credito',
        sys_get_temp_dir() . '/voltika_contratos_msi',
    ];
    $found = [];
    foreach ($dirs as $d) {
        if (!is_dir($d)) continue;
        foreach (scandir($d) ?: [] as $f) {
            if (!preg_match('/\.pdf$/i', $f)) continue;
            $p = $d . '/' . $f;
            if (!is_file($p) || filesize($p) < 1024) continue;
            $found[] = [
                'path' => $p,
                'name' => $f,
                'dir'  => $d,
                'size' => filesize($p),
                'mtime' => filemtime($p) ?: 0,
            ];
        }
    }
    usort($found, function($a,$b){ return $b['mtime'] <=> $a['mtime']; });
    return $found;
}

$pdfs = _scanContractPdfs();

// ── Determine the JWT state without yet hitting the create endpoint ─────
$jwtMasked = null;
$jwtError  = null;
$jwt = cincelGetJWT();
if ($jwt) {
    // Show only first/last 6 chars; the JWT itself is a credential.
    $jwtMasked = substr($jwt, 0, 8) . '…' . substr($jwt, -6) . ' (' . strlen($jwt) . ' chars)';
} else {
    $jwtError = 'No se pudo obtener JWT. Verifica CINCEL_EMAIL / CINCEL_PASSWORD en local-secrets.php.';
}

// ── Resolve the selected PDF (if any) ────────────────────────────────────
$selectedPath = isset($_GET['pdf']) ? (string)$_GET['pdf'] : '';
$selectedInfo = null;
if ($selectedPath !== '') {
    // Whitelist: only allow paths returned by _scanContractPdfs()
    $allow = array_column($pdfs, 'path');
    if (in_array($selectedPath, $allow, true) && is_file($selectedPath)) {
        $selectedInfo = [
            'path'   => $selectedPath,
            'name'   => basename($selectedPath),
            'size'   => filesize($selectedPath),
            'sha256' => hash_file('sha256', $selectedPath),
        ];
    }
}

// ── Run actions ──────────────────────────────────────────────────────────
$action  = isset($_GET['action']) ? (string)$_GET['action'] : '';
$confirm = !empty($_GET['confirmar']);
$result  = null;
$saved   = null;

if ($selectedInfo && $action === 'check') {
    // Cheap, no auth, no credits. Just GET /v3/timestamps/{hash}
    $existing = cincelTimestampExists($selectedInfo['sha256']);
    $result = [
        'mode'     => 'check',
        'ok'       => $existing !== null,
        'exists'   => $existing !== null,
        'hash'     => $selectedInfo['sha256'],
        'timestamp'=> $existing,
    ];
}

if ($selectedInfo && $action === 'create' && $confirm) {
    // Full create path — consumes 1 credit IF Cincel doesn't already have the hash.
    $result = cincelGetOrCreateTimestamp($selectedInfo['path']);
    // Persist if DB available.
    try {
        if (!empty($result['ok']) && function_exists('getPDO')) {
            $pdo = getPDO();
            $saved = cincelSaveTimestamp($pdo, $result, null, $selectedInfo['path']);
        }
    } catch (Throwable $e) {
        $saved = ['error' => $e->getMessage()];
    }
}

// ── Render ───────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
$pdfShort = function($p){
    $r = str_replace(__DIR__ . '/../../', '', $p);
    return $r;
};
?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Voltika — Diagnóstico Cincel Timestamp CREATE</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fb;color:#0c2340;padding:24px;max-width:1080px;margin:0 auto;line-height:1.55;}
h1{font-size:22px;margin:0 0 4px;}
h2{font-size:14px;color:#475569;margin:24px 0 8px;text-transform:uppercase;letter-spacing:.4px;}
.muted{color:#94a3b8;font-size:12px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:14px;}
.banner{padding:10px 12px;border-radius:8px;font-size:13px;margin-bottom:10px;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;font-weight:600;}
.banner-bad{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;font-weight:600;}
.banner-warn{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-weight:600;}
.banner-info{background:#dbeafe;border:1px solid #93c5fd;color:#1e40af;}
table{border-collapse:collapse;width:100%;font-size:12.5px;}
th,td{border-bottom:1px solid #e2e8f0;padding:6px 8px;text-align:left;}
th{background:#f1f5f9;color:#475569;font-weight:600;font-size:11.5px;text-transform:uppercase;letter-spacing:.4px;}
tr:hover td{background:#f8fafc;}
a.btn{display:inline-block;padding:5px 10px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;margin-right:4px;}
.btn-check{background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;}
.btn-create{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
.btn-create-go{background:#16a34a;color:#fff;border:1px solid #15803d;}
.btn-back{background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;}
code{background:#1e293b;color:#e2e8f0;padding:2px 6px;border-radius:3px;font-size:11px;font-family:ui-monospace,monospace;word-break:break-all;}
pre{background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px;font-size:11px;white-space:pre-wrap;word-break:break-all;max-height:280px;overflow:auto;margin:6px 0;}
.k{color:#475569;font-weight:600;}
.warning-box{background:#fef3c7;border:1px solid #fcd34d;color:#78350f;padding:10px 12px;border-radius:8px;font-size:12.5px;margin-bottom:10px;}
</style></head><body>

<h1>🕐 Diagnóstico Cincel Timestamp — Create flow</h1>
<div class="muted"><?= date('Y-m-d H:i:s') ?> · Servidor: <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '') ?></div>

<h2>1. Estado del JWT</h2>
<div class="card">
  <?php if ($jwtMasked): ?>
    <div class="banner banner-ok">✅ JWT obtenido correctamente.</div>
    <div class="muted">Token (enmascarado): <code><?= htmlspecialchars($jwtMasked) ?></code></div>
    <div class="muted" style="margin-top:4px;">
      Cache: <code><?= htmlspecialchars(sys_get_temp_dir() . '/voltika-cincel-jwt.json') ?></code> · TTL:
      <?= defined('CINCEL_JWT_TTL') ? (int)CINCEL_JWT_TTL : (4 * 3600) ?> s
    </div>
  <?php else: ?>
    <div class="banner banner-bad">❌ <?= htmlspecialchars($jwtError ?? 'JWT no disponible') ?></div>
    <div class="muted">Revisa <code>error_log</code> para el detalle del fallo de autenticación.</div>
  <?php endif; ?>
</div>

<h2>2. PDFs de contrato disponibles</h2>
<div class="card">
  <?php if (!$pdfs): ?>
    <div class="banner banner-warn">No se encontraron contratos PDF en las carpetas conocidas. Necesitas al menos uno para probar.</div>
    <ul class="muted" style="font-size:12px;">
      <li><code>/configurador/contratos/contado/</code></li>
      <li><code>/configurador/contratos/credito/</code></li>
      <li><code>/configurador/contratos/msi/</code></li>
      <li><code><?= htmlspecialchars(sys_get_temp_dir()) ?>/voltika_contratos_*</code></li>
    </ul>
  <?php else: ?>
    <div class="muted" style="margin-bottom:8px;">Mostrando <?= count($pdfs) ?> PDF(s) ordenados por mtime descendente. Selecciona uno para correr el test.</div>
    <table>
      <thead><tr>
        <th>Archivo</th><th>Carpeta</th><th>Tamaño</th><th>Modificado</th><th>Acciones</th>
      </tr></thead>
      <tbody>
      <?php foreach (array_slice($pdfs, 0, 30) as $p):
        $checkUrl  = '?key=' . urlencode($expected) . '&pdf=' . urlencode($p['path']) . '&action=check';
        $createUrl = '?key=' . urlencode($expected) . '&pdf=' . urlencode($p['path']) . '&action=create';
      ?>
        <tr>
          <td><code><?= htmlspecialchars($p['name']) ?></code></td>
          <td class="muted" style="font-size:11px;"><?= htmlspecialchars($pdfShort($p['dir'])) ?></td>
          <td><?= number_format($p['size']) ?> B</td>
          <td><?= htmlspecialchars(date('Y-m-d H:i', $p['mtime'])) ?></td>
          <td>
            <a class="btn btn-check" href="<?= htmlspecialchars($checkUrl) ?>">Check (GET)</a>
            <a class="btn btn-create" href="<?= htmlspecialchars($createUrl) ?>">Create (POST) →</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php if ($selectedInfo): ?>
  <h2>3. PDF seleccionado</h2>
  <div class="card">
    <div><span class="k">Archivo:</span> <code><?= htmlspecialchars($selectedInfo['path']) ?></code></div>
    <div><span class="k">Tamaño:</span> <?= number_format($selectedInfo['size']) ?> bytes</div>
    <div style="margin-top:6px;"><span class="k">SHA-256:</span> <code><?= htmlspecialchars($selectedInfo['sha256']) ?></code></div>
  </div>
<?php endif; ?>

<?php if ($selectedInfo && $action === 'create' && !$confirm): ?>
  <h2>4. Confirmar creación de timestamp</h2>
  <div class="card">
    <div class="warning-box">
      ⚠️ <strong>Atención — costo posible:</strong> POST /v3/timestamps consume 1 crédito c.Doc
      <em>si Cincel no tiene aún un sello para este hash</em>. Si ya existe (porque corriste el test
      previamente o porque otro proceso lo creó), no se cobrará.
    </div>
    <p style="font-size:13px;">
      Vamos a calcular sha256 de <code><?= htmlspecialchars($selectedInfo['name']) ?></code>,
      preguntar primero a Cincel si ya existe timestamp para ese hash (sin gasto) y, solo si no existe,
      crear uno nuevo (1 crédito).
    </p>
    <p>
      <a class="btn btn-create-go"
         href="?key=<?= urlencode($expected) ?>&pdf=<?= urlencode($selectedInfo['path']) ?>&action=create&confirmar=1">
        Sí, crear timestamp ahora
      </a>
      <a class="btn btn-back" href="?key=<?= urlencode($expected) ?>">Cancelar</a>
    </p>
  </div>
<?php endif; ?>

<?php if ($result): ?>
  <h2>5. Resultado</h2>
  <div class="card">
    <?php if (!empty($result['ok'])): ?>
      <?php if (!empty($result['exists']) && ($result['mode'] ?? '') === 'check'): ?>
        <div class="banner banner-ok">✅ Cincel YA tiene un timestamp NOM-151 para este hash. No se necesita crear (sin gasto de crédito).</div>
      <?php elseif (!empty($result['already'])): ?>
        <div class="banner banner-info">ℹ️ Cincel ya tenía un timestamp para este hash — recuperado sin consumir crédito.</div>
      <?php elseif (!empty($result['created'])): ?>
        <div class="banner banner-ok">✅ Timestamp NOM-151 creado correctamente (1 crédito c.Doc consumido).</div>
      <?php else: ?>
        <div class="banner banner-ok">✅ OK.</div>
      <?php endif; ?>
    <?php else: ?>
      <?php if (($result['mode'] ?? '') === 'check' && empty($result['exists'])): ?>
        <div class="banner banner-info">ℹ️ Cincel NO tiene timestamp para este hash todavía. Usa <strong>Create</strong> para generarlo.</div>
      <?php else: ?>
        <div class="banner banner-bad">❌ Falló la operación.</div>
      <?php endif; ?>
    <?php endif; ?>

    <div style="margin-top:8px;"><span class="k">Hash:</span> <code><?= htmlspecialchars($result['hash'] ?? '—') ?></code></div>

    <?php if (!empty($result['timestamp']) && is_array($result['timestamp'])):
      $ts = $result['timestamp'];
      $apiRoot = defined('CINCEL_API_URL') ? rtrim(CINCEL_API_URL, '/') : 'https://api.cincel.digital/v3';
      $rootHost = preg_replace('#/v\d+$#', '', $apiRoot) ?: 'https://api.cincel.digital';
    ?>
      <div style="margin-top:10px;">
        <div class="k" style="margin-bottom:4px;">Certificados (descarga directa):</div>
        <ul style="margin:0;padding-left:18px;line-height:1.7;font-size:12.5px;">
          <?php foreach (['nom151'=>'NOM-151 (.p7m, evidencia legal)', 'timestamp'=>'Timestamp (.tsr)', 'bitcoin'=>'Bitcoin proof'] as $field => $label):
            if (empty($ts[$field])) continue;
            $file = (string)$ts[$field];
            // Cincel returns either full URLs or filenames; build downloadable link.
            $href = (strpos($file, 'http') === 0) ? $file : $rootHost . '/v3/timestamps/' . urlencode($result['hash']) . '/' . urlencode($file);
          ?>
            <li>
              <strong><?= htmlspecialchars($label) ?>:</strong>
              <a href="<?= htmlspecialchars($href) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($file) ?></a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if (isset($saved)): ?>
      <div style="margin-top:10px;font-size:12.5px;">
        <span class="k">DB:</span>
        <?php if (is_int($saved)): ?>
          ✅ Guardado en <code>cincel_timestamps</code> (id <?= (int)$saved ?>).
        <?php elseif (is_array($saved) && !empty($saved['error'])): ?>
          ⚠️ No se pudo guardar: <?= htmlspecialchars($saved['error']) ?>
        <?php else: ?>
          —
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <details style="margin-top:10px;">
      <summary class="muted" style="font-size:11.5px;cursor:pointer;">Respuesta cruda</summary>
      <pre><?= htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
    </details>
  </div>
<?php endif; ?>

<h2>¿Qué probamos aquí?</h2>
<div class="card" style="font-size:13px;">
  <ul style="margin:0;padding-left:18px;line-height:1.7;">
    <li><strong>Check (GET)</strong> consulta el endpoint público sin auth ni gasto. Útil para verificar idempotencia (¿ya existe el sello?).</li>
    <li><strong>Create (POST)</strong> ejecuta el flujo completo: JWT → POST /v3/timestamps → persistencia en DB. Consume 1 crédito si el hash es nuevo.</li>
    <li>Una vez que veas un sello creado correctamente aquí, podemos engancharlo en <code>confirmar-orden.php</code> para que se aplique automáticamente a cada contrato firmado.</li>
  </ul>
</div>

<p class="muted" style="margin-top:24px;">
  Módulo: <code>/configurador/php/cincel-timestamp.php</code> ·
  Tabla audit: <code>cincel_timestamps</code> ·
  Columna referencia: <code>transacciones.cincel_timestamp_hash</code>
</p>

</body></html>
