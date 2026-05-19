<?php
/**
 * Voltika Admin — Round 58 verification + Acta de Entrega backfill tool.
 *
 * Verifies the complete Cincel signing pipeline end-to-end:
 *   1. Cincel credentials in env + auth test against Cincel API
 *   2. Recent Acta signing history per moto (last 30)
 *   3. Per-acta: is the signed PDF actually on disk? Or only DB metadata?
 *   4. Backfill button — for any acta with cliente_acta_firmada=1 but
 *      cincel_acta_signed_pdf_path NULL/missing, fetches the signed PDF
 *      from Cincel /v3/documents/{id} and saves it locally so descargar.php
 *      can serve it. Lets you fix historical signings in 1 click.
 *
 * URL:
 *   https://voltika.mx/admin/php/diagnostico-acta.php?key=voltika_diag_2026
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

foreach ([__DIR__ . '/../../configurador/php/config.php',
          __DIR__ . '/../../configurador_prueba_test/php/config.php'] as $cfg) {
    if (is_file($cfg)) { @require_once $cfg; break; }
}

$expected = 'voltika_diag_2026';
$key = $_GET['key'] ?? $_POST['key'] ?? '';
if (!hash_equals($expected, (string)$key)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Acceso denegado. Usa ?key=<secret>";
    exit;
}

$pdo = getDB();

// ── Helper: Cincel auth (mirrors cincel-firma-acta.php logic) ───────────
// Round 58 v2: try multiple URL variations because Cincel has both prod
// (api.cincel.digital) and sandbox (sandbox.api.cincel.digital), with and
// without /v3 prefix. Test credentials only work on sandbox.
function cincelAuth(): array {
    $cincelApiEnv = defined('CINCEL_API_URL') ? rtrim(CINCEL_API_URL, '/')
                  : (getenv('CINCEL_API_URL') ?: 'https://api.cincel.digital/v3');
    $email    = defined('CINCEL_EMAIL')    ? CINCEL_EMAIL    : (getenv('CINCEL_EMAIL')    ?: '');
    $password = defined('CINCEL_PASSWORD') ? CINCEL_PASSWORD : (getenv('CINCEL_PASSWORD') ?: '');

    if (!$email || !$password) {
        return ['ok' => false, 'error' => 'CINCEL_EMAIL / CINCEL_PASSWORD vacíos', 'token' => null,
                'env_url' => $cincelApiEnv, 'tried' => []];
    }

    // Build all reasonable URL candidates. The env-defined URL is tried
    // first; if it fails we automatically try sandbox + variations.
    $baseCandidates = [$cincelApiEnv];
    $variants = [
        'https://api.cincel.digital/v3',
        'https://api.cincel.digital',
        'https://sandbox.api.cincel.digital/v3',
        'https://sandbox.api.cincel.digital',
    ];
    foreach ($variants as $v) {
        if (!in_array($v, $baseCandidates, true)) $baseCandidates[] = $v;
    }

    // Comprehensive auth endpoint probe. Cincel has changed their API
    // path more than once. The Express app on /v3 responds with 404 JSON
    // for unknown routes (so we get a CLEAR signal which path exists)
    // while nginx returns HTML 404 when nothing is mounted at the prefix.
    $authPaths = [
        '/auth/tokens',
        '/auth/login',
        '/sessions',
        '/oauth/token',
        '/users/login',
        '/users/sign_in',
        '/login',
        '/token',
        '/tokens',
        '/api/auth/tokens',
        '/api/auth/login',
        '/api/v3/auth/tokens',
        '/api/v3/auth/login',
    ];
    $attempts = [];
    foreach ($baseCandidates as $base) {
        foreach ($authPaths as $authPath) {
            $url = $base . $authPath;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'password' => $password]),
                CURLOPT_TIMEOUT => 8,
            ]);
            $raw = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            // Detect nginx HTML 404 vs JSON 404 vs real responses. We only
            // record JSON 404s and non-404 responses to keep the table
            // readable (nginx 404s mean wrong base path, not interesting).
            $isJsonResp = is_string($raw) && (strpos($raw, '<html') === false);
            if ($code !== 404 || $isJsonResp) {
                $attempts[] = ['url' => $url, 'http' => $code, 'body' => substr((string)$raw, 0, 200)];
            }
            if ($code >= 200 && $code < 300) {
                $auth = json_decode((string)$raw, true);
                $token = $auth['access_token'] ?? $auth['token']
                       ?? ($auth['data']['access_token'] ?? null)
                       ?? ($auth['data']['token'] ?? null);
                if ($token) {
                    return [
                        'ok' => true, 'token' => $token, 'api' => $base,
                        'env_url' => $cincelApiEnv, 'working_url' => $base . $authPath,
                        'attempts' => $attempts,
                    ];
                }
            }
            // Also: even 401/422 on a real endpoint is more informative
            // than 404 — it means the endpoint exists, just credentials
            // are wrong. Flag it specifically.
            if ($code === 401 || $code === 403 || $code === 422) {
                return [
                    'ok' => false,
                    'error' => 'Endpoint encontrado en ' . $url . ' pero credenciales rechazadas (HTTP ' . $code . ')',
                    'endpoint_exists' => $url,
                    'env_url' => $cincelApiEnv,
                    'attempts' => $attempts,
                    'token' => null,
                ];
            }
        }
    }
    return ['ok' => false, 'error' => 'Auth fallida — ningún endpoint conocido responde con éxito',
            'env_url' => $cincelApiEnv, 'attempts' => $attempts, 'token' => null];
}

function fmtJsonShort($v, int $max = 80): string {
    if ($v === null) return '—';
    if (is_string($v)) return strlen($v) > $max ? substr($v, 0, $max) . '...' : $v;
    return substr(json_encode($v, JSON_UNESCAPED_UNICODE), 0, $max);
}

// ── POST: backfill signed PDF for one moto ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'backfill_one')) {
    header('Content-Type: application/json; charset=utf-8');
    $motoId = (int)($_POST['moto_id'] ?? 0);
    if (!$motoId) { echo json_encode(['ok' => false, 'error' => 'moto_id requerido']); exit; }

    $q = $pdo->prepare("SELECT id, cincel_acta_document_id, cliente_acta_firmada, cincel_nom151_data
                          FROM inventario_motos WHERE id = ?");
    $q->execute([$motoId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['ok' => false, 'error' => 'Moto no encontrada']); exit; }
    if (empty($row['cincel_acta_document_id'])) {
        echo json_encode(['ok' => false, 'error' => 'Esta moto no tiene cincel_acta_document_id (no se inició flujo Cincel)']);
        exit;
    }

    $auth = cincelAuth();
    if (!$auth['ok']) { echo json_encode(['ok' => false, 'error' => 'Cincel auth: ' . $auth['error'], 'attempts' => $auth['attempts'] ?? []]); exit; }

    $signedUrl = null;
    // Try the nom151_data first
    if (!empty($row['cincel_nom151_data'])) {
        $nom = @json_decode((string)$row['cincel_nom151_data'], true);
        if (is_array($nom)) {
            $signedUrl = $nom['signed_pdf_url'] ?? ($nom['raw_event']['signed_pdf_url'] ?? null)
                       ?? ($nom['raw_event']['file_url'] ?? null);
        }
    }
    $docMeta = null;
    if (!$signedUrl) {
        $ch = curl_init($auth['api'] . '/documents/' . rawurlencode($row['cincel_acta_document_id']));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $auth['token']],
            CURLOPT_TIMEOUT => 15,
        ]);
        $rawDoc = curl_exec($ch);
        $docCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $docMeta = is_string($rawDoc) ? json_decode($rawDoc, true) : null;
        if ($docCode >= 200 && $docCode < 300 && is_array($docMeta)) {
            $signedUrl = $docMeta['signed_pdf_url']
                      ?? $docMeta['file_url']
                      ?? ($docMeta['document']['signed_pdf_url'] ?? null)
                      ?? ($docMeta['document']['file_url'] ?? null);
        } else {
            echo json_encode([
                'ok' => false, 'error' => 'Cincel /documents devolvió HTTP ' . $docCode,
                'body' => substr((string)$rawDoc, 0, 600),
            ]);
            exit;
        }
    }
    if (!$signedUrl) {
        echo json_encode(['ok' => false, 'error' => 'No se encontró signed_pdf_url en Cincel response',
                          'doc_meta' => $docMeta]);
        exit;
    }

    // Download.
    $downloadDir = realpath(__DIR__ . '/../../configurador/php/uploads/actas') ?: (__DIR__ . '/../../configurador/php/uploads/actas');
    if (!is_dir($downloadDir)) @mkdir($downloadDir, 0775, true);
    $signedName = 'acta_signed_' . $motoId . '_' . date('Ymd_His') . '.pdf';
    $signedPath = $downloadDir . '/' . $signedName;

    $ch = curl_init($signedUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $pdfBin = curl_exec($ch);
    $pdfCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // If unauthorized, retry with Cincel token in case the URL needs it.
    if ($pdfCode >= 400 || !is_string($pdfBin) || strlen($pdfBin) < 1000) {
        $ch = curl_init($signedUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $auth['token']],
        ]);
        $pdfBin = curl_exec($ch);
        $pdfCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }

    if ($pdfCode < 200 || $pdfCode >= 300 || !is_string($pdfBin) || strlen($pdfBin) < 1000 || substr($pdfBin, 0, 4) !== '%PDF') {
        echo json_encode(['ok' => false, 'error' => 'Descarga falló', 'http' => $pdfCode,
                          'bytes' => is_string($pdfBin) ? strlen($pdfBin) : 0,
                          'is_pdf' => is_string($pdfBin) && substr($pdfBin, 0, 4) === '%PDF',
                          'signed_url' => $signedUrl]);
        exit;
    }

    $written = @file_put_contents($signedPath, $pdfBin);
    if (!$written) { echo json_encode(['ok' => false, 'error' => 'No se pudo escribir en ' . $signedPath]); exit; }

    try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cincel_acta_signed_pdf_path VARCHAR(255) NULL"); } catch (Throwable $e) {}
    $pdo->prepare("UPDATE inventario_motos SET cincel_acta_signed_pdf_path = ? WHERE id = ?")
        ->execute([$signedName, $motoId]);

    adminLog('acta_signed_pdf_backfilled', ['moto_id' => $motoId, 'filename' => $signedName, 'bytes' => $written]);

    echo json_encode([
        'ok' => true, 'moto_id' => $motoId, 'filename' => $signedName, 'bytes' => $written,
        'public_url' => '/configurador/php/uploads/actas/' . $signedName,
    ]);
    exit;
}

// ── GET: dashboard ─────────────────────────────────────────────────────
$auth = cincelAuth();

$motos = [];
try {
    $st = $pdo->query("SELECT id, vin, vin_display, cliente_nombre, cliente_acta_firmada,
                              cliente_acta_fecha, cliente_acta_firma,
                              cincel_acta_document_id, cincel_acta_status,
                              cincel_acta_signed_pdf_path, cincel_nom151_data
                         FROM inventario_motos
                        WHERE cincel_acta_document_id IS NOT NULL
                           OR cliente_acta_firmada = 1
                        ORDER BY id DESC LIMIT 30");
    $motos = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $motosErr = $e->getMessage(); }

$uploadDirCandidates = [
    realpath(__DIR__ . '/../../configurador/php/uploads/actas'),
    realpath(__DIR__ . '/../../configurador_prueba_test/php/uploads/actas'),
];
$uploadDirs = array_filter($uploadDirCandidates);

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Voltika — Acta de Entrega diagnostic</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fb;color:#0c2340;padding:24px;max-width:1180px;margin:0 auto;line-height:1.5;}
h1{font-size:22px;margin:0 0 4px;} h2{font-size:14px;color:#475569;margin:24px 0 10px;text-transform:uppercase;letter-spacing:.4px;}
.muted{color:#94a3b8;font-size:11.5px;} .ok{color:#16a34a;font-weight:700;} .bad{color:#dc2626;font-weight:700;} .warn{color:#d97706;font-weight:700;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:14px;}
table{width:100%;border-collapse:collapse;font-size:12px;}
th{text-align:left;padding:7px 5px;border-bottom:2px solid #cbd5e1;color:#475569;font-weight:700;font-size:11px;}
td{padding:6px 5px;border-bottom:1px solid #f1f5f9;vertical-align:top;}
code{background:#f1f5f9;color:#1e293b;padding:1px 5px;border-radius:3px;font-size:11px;font-family:ui-monospace,monospace;word-break:break-all;}
.banner{padding:12px;border-radius:8px;font-size:13px;margin:10px 0;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.banner-bad{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
.banner-warn{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;}
button{background:#039fe1;color:#fff;border:0;padding:6px 12px;border-radius:5px;font-size:12px;cursor:pointer;font-weight:600;}
button:disabled{background:#94a3b8;cursor:not-allowed;}
pre{background:#0b1322;color:#e2e8f0;padding:8px;border-radius:5px;font-size:10.5px;overflow-x:auto;max-height:140px;}
</style></head><body>

<h1>📄 Diagnóstico Acta de Entrega + Cincel</h1>
<div class="muted">Round 58 verification · <?= date('Y-m-d H:i:s') ?> · <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '') ?></div>

<h2>1. Cincel — credenciales + autenticación</h2>
<div class="card">
  <table>
    <tr><td><strong>API URL</strong></td><td><code><?= htmlspecialchars($auth['api'] ?? '?') ?></code></td></tr>
    <tr><td><strong>CINCEL_EMAIL</strong></td><td><?= (defined('CINCEL_EMAIL') && CINCEL_EMAIL) ? '<span class="ok">✓ definido</span> · <code>' . htmlspecialchars(substr(CINCEL_EMAIL, 0, 12) . '…') . '</code>' : '<span class="bad">✗ vacío</span>' ?></td></tr>
    <tr><td><strong>CINCEL_PASSWORD</strong></td><td><?= (defined('CINCEL_PASSWORD') && CINCEL_PASSWORD) ? '<span class="ok">✓ definido</span> · ' . strlen(CINCEL_PASSWORD) . ' chars' : '<span class="bad">✗ vacío</span>' ?></td></tr>
    <tr><td><strong>Auth test</strong></td><td>
      <?php if ($auth['ok']): ?>
        <span class="ok">✓ Token obtenido</span> · longitud <?= strlen($auth['token']) ?>
        <br>URL que funcionó: <code><?= htmlspecialchars($auth['working_url'] ?? '?') ?></code>
        <?php if (!empty($auth['api']) && $auth['api'] !== ($auth['env_url'] ?? '')): ?>
          <div class="banner banner-warn" style="margin-top:8px;">
            ⚠ Auth funcionó con <code><?= htmlspecialchars($auth['api']) ?></code> pero tu env tiene
            <code><?= htmlspecialchars((string)($auth['env_url'] ?? '')) ?></code>. <strong>Actualiza
            tu .env con CINCEL_API_URL=<?= htmlspecialchars($auth['api']) ?></strong> para que el
            código de producción use la URL correcta.
          </div>
        <?php endif; ?>
      <?php else: ?>
        <span class="bad">✗ FALLÓ</span> — <?= htmlspecialchars($auth['error'] ?? '?') ?>
        <br>Env URL configurada: <code><?= htmlspecialchars((string)($auth['env_url'] ?? '?')) ?></code>
        <pre><?= htmlspecialchars(json_encode($auth['attempts'] ?? [], JSON_PRETTY_PRINT)) ?></pre>
      <?php endif; ?>
    </td></tr>
  </table>
</div>

<h2>2. Directorios locales</h2>
<div class="card">
  <?php foreach ($uploadDirCandidates as $c): ?>
    <div>
      <code><?= htmlspecialchars($c ?: '(no existe)') ?></code>
      <?php if ($c && is_dir($c)):
        $count = count(glob($c . '/acta_signed_*.pdf') ?: []);
        $tplCount = count(glob($c . '/acta_cliente_*.pdf') ?: []);
        echo ' <span class="ok">✓</span> · ' . $count . ' signed · ' . $tplCount . ' templates';
      else: echo ' <span class="warn">no existe</span>'; endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<h2>3. Últimas 30 actas — estado por moto</h2>
<div class="card">
  <?php if (empty($motos)): ?>
    <div class="muted">Sin registros de actas en inventario_motos (cincel_acta_document_id IS NULL && cliente_acta_firmada = 0).</div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Moto ID</th><th>Cliente</th><th>VIN</th>
          <th>cliente_acta_firmada</th><th>cincel_status</th><th>Cincel doc_id</th>
          <th>Signed PDF en disco?</th><th>Acción</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($motos as $m):
        $motoId = (int)$m['id'];
        $signedName = $m['cincel_acta_signed_pdf_path'] ?? null;
        $existsOnDisk = false;
        if ($signedName) {
            foreach ($uploadDirs as $ud) {
                if ($ud && file_exists($ud . '/' . $signedName) && filesize($ud . '/' . $signedName) > 1000) { $existsOnDisk = true; break; }
            }
        } else {
            // Fallback: glob for any acta_signed_<motoId>_*
            foreach ($uploadDirs as $ud) {
                if ($ud && count(glob($ud . '/acta_signed_' . $motoId . '_*.pdf') ?: []) > 0) { $existsOnDisk = true; break; }
            }
        }
        $needsBackfill = !empty($m['cincel_acta_document_id']) && !$existsOnDisk;
      ?>
        <tr>
          <td><strong><?= $motoId ?></strong></td>
          <td><?= htmlspecialchars((string)($m['cliente_nombre'] ?? '—')) ?></td>
          <td><code><?= htmlspecialchars((string)($m['vin_display'] ?? $m['vin'] ?? '—')) ?></code></td>
          <td><?= ((int)$m['cliente_acta_firmada'] === 1) ? '<span class="ok">✓ 1</span>' : '<span class="muted">0</span>' ?></td>
          <td><?= htmlspecialchars((string)($m['cincel_acta_status'] ?? '—')) ?></td>
          <td><code style="font-size:10px;"><?= htmlspecialchars(fmtJsonShort($m['cincel_acta_document_id'], 28)) ?></code></td>
          <td>
            <?php if ($existsOnDisk): ?>
              <span class="ok">✓ presente</span>
              <?php if ($signedName): ?><br><code style="font-size:9.5px;"><?= htmlspecialchars($signedName) ?></code><?php endif; ?>
            <?php elseif (!empty($m['cincel_acta_document_id'])): ?>
              <span class="bad">✗ falta</span>
            <?php else: ?>
              <span class="muted">no aplica</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($needsBackfill): ?>
              <button class="bf-btn" data-id="<?= $motoId ?>">Descargar de Cincel</button>
              <span class="bf-status" data-id="<?= $motoId ?>" style="font-size:11px;margin-left:4px;"></span>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<h2>Próximos pasos</h2>
<div class="card" style="font-size:13.5px;">
  <ol>
    <li>Para cada fila con <strong>✗ falta</strong> en "Signed PDF en disco" — haz click en <strong>"Descargar de Cincel"</strong>. La herramienta autentica con Cincel, obtiene el signed_pdf_url, lo descarga y lo guarda en <code>/configurador/php/uploads/actas/</code>. Una vez descargado, el cliente verá la firma en su PDF.</li>
    <li>Las filas con <strong>✓ presente</strong> ya están correctas — el cliente puede descargar su acta firmada desde su portal y verá la autógrafa + sello NOM-151 de Cincel.</li>
    <li>Para nuevas firmas a partir de hoy (Round 58), este flujo es automático: cuando Cincel notifica al webhook, el PDF firmado se descarga en el momento.</li>
  </ol>
</div>

<script>
document.querySelectorAll('.bf-btn').forEach(function(btn){
  btn.addEventListener('click', function(){
    var id = btn.getAttribute('data-id');
    var status = document.querySelector('.bf-status[data-id="' + id + '"]');
    btn.disabled = true;
    status.textContent = '⏳ Descargando...';
    status.style.color = '#1e40af';
    var fd = new FormData();
    fd.append('key', <?= json_encode($expected) ?>);
    fd.append('action', 'backfill_one');
    fd.append('moto_id', id);
    fetch(location.pathname, { method: 'POST', credentials: 'include', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (j.ok) {
          status.textContent = '✓ OK · ' + j.bytes + ' bytes';
          status.style.color = '#15803d';
          setTimeout(function(){ location.reload(); }, 1500);
        } else {
          status.textContent = '✗ ' + (j.error || 'falló');
          status.style.color = '#b91c1c';
          btn.disabled = false;
          console.warn('Backfill failed:', j);
        }
      })
      .catch(function(e){
        status.textContent = '✗ ' + e.message;
        status.style.color = '#b91c1c';
        btn.disabled = false;
      });
  });
});
</script>

</body></html>
