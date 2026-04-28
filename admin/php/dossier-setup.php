<?php
/**
 * Voltika — Dossier de Defensa · Setup / Self-heal Script
 *
 * Browser-runnable installer that fixes the two server-side issues
 * surfaced by descargar-dossier.php diagnostics:
 *
 *   1. Creates the dossiers/ output directory with the right permissions
 *      (both prod and test) so PHP can write generated PDFs/ZIPs.
 *
 *   2. Locates FPDF on the server. If not found anywhere, downloads it
 *      from fpdf.org and installs to configurador_prueba/php/vendor/fpdf/.
 *
 * Designed for managed/shared hosting where shell access is unavailable.
 * Admin session required.
 *
 * Usage:
 *   GET https://voltika.mx/admin/php/dossier-setup.php
 *   GET https://voltika.mx/admin/php/dossier-setup.php?run=1   ← actually do it
 *
 * Without ?run=1 it only diagnoses (dry-run). With ?run=1 it makes changes.
 */

require_once __DIR__ . '/bootstrap.php';
adminRequireAuth(['admin']);

$run = !empty($_GET['run']);
header('Content-Type: text/html; charset=UTF-8');

$projectRoot = realpath(__DIR__ . '/../..');
if (!$projectRoot) $projectRoot = dirname(dirname(__DIR__));

$results = [];

// ─────────────────────────────────────────────────────────────────────────
// STEP 1: dossiers/ directories (prod + test) — w/ /tmp fallback
// ─────────────────────────────────────────────────────────────────────────
// Plesk-style hosting often denies the PHP runtime user write access to
// code-tree directories (the FTP user owns them). When the "ideal"
// location can't be made writable, we fall back to system temp — this is
// fully functional: the dossier system already supports it via
// `_dossierOutputDir()` and the dossiers metadata live in DB anyway.
$dirsToCreate = [
    [
        'preferred' => $projectRoot . '/configurador_prueba/dossiers',
        'fallback'  => sys_get_temp_dir() . '/voltika_dossiers_prod',
        'env'       => 'prod',
    ],
    [
        'preferred' => $projectRoot . '/configurador_prueba_test/dossiers',
        'fallback'  => sys_get_temp_dir() . '/voltika_dossiers_test',
        'env'       => 'test',
    ],
];
foreach ($dirsToCreate as $cfg) {
    $d = $cfg['preferred'];
    $entry = ['type' => 'dir', 'path' => $d, 'before' => null, 'after' => null, 'action' => null, 'ok' => false];
    $entry['before'] = is_dir($d) ? (is_writable($d) ? 'exists+writable' : 'exists+NOT_writable') : 'missing';

    if (!$run) {
        $entry['action'] = $entry['before'] === 'exists+writable' ? 'skip (already ok)' : 'will try mkdir + chmod, fallback a /tmp si falla';
        $entry['ok'] = true;
    } else {
        // Try preferred location first
        if (!is_dir($d)) @mkdir($d, 0775, true);
        @chmod($d, 0775);
        @file_put_contents($d . '/.gitkeep', '');

        if (is_dir($d) && is_writable($d)) {
            $entry['after']  = 'exists+writable';
            $entry['action'] = 'mkdir/chmod OK';
            $entry['ok']     = true;
        } else {
            // Fallback to system temp — this WORKS because /tmp is always
            // writable for the PHP user. Dossier-defensa.php's
            // `_dossierOutputDir()` already redirects there automatically
            // when the local dir isn't writable, so we just need to make
            // sure the fallback exists.
            $alt = $cfg['fallback'];
            @mkdir($alt, 0777, true);
            @chmod($alt, 0777);
            if (is_dir($alt) && is_writable($alt)) {
                $entry['after']  = $entry['before']; // unchanged
                $entry['action'] = 'preferida no escribible (Plesk perms) → fallback /tmp activo: ' . $alt;
                $entry['ok']     = true; // functional via fallback
                $entry['fallback_in_use'] = true;
            } else {
                $entry['after']  = $entry['before'];
                $entry['action'] = '⚠ Permission denied · sin /tmp tampoco · pedir al hosting permisos en ' . $d;
                $entry['ok']     = false;
            }
        }
    }
    $results[] = $entry;
}

// ─────────────────────────────────────────────────────────────────────────
// STEP 2: FPDF library
// ─────────────────────────────────────────────────────────────────────────
$fpdfCandidates = [
    $projectRoot . '/configurador_prueba/php/vendor/fpdf/fpdf.php',
    $projectRoot . '/configurador_prueba/php/vendor/setasign/fpdf/fpdf.php',
    $projectRoot . '/configurador_prueba_test/php/vendor/fpdf/fpdf.php',
    $projectRoot . '/admin/php/lib/fpdf.php',
    $projectRoot . '/admin_test/php/lib/fpdf.php',
];

$foundFpdf = '';
$fpdfStatus = [];
foreach ($fpdfCandidates as $f) {
    $exists = file_exists($f);
    $fpdfStatus[$f] = $exists ? 'EXISTE' : 'no';
    if ($exists && !$foundFpdf) $foundFpdf = $f;
}

$fpdfEntry = [
    'type'   => 'lib',
    'name'   => 'FPDF',
    'paths_checked' => $fpdfStatus,
    'found'  => $foundFpdf,
    'action' => null,
    'ok'     => false,
];

// Where we want it installed (so the loader in dossier-defensa.php finds it)
$fpdfTargetDir  = $projectRoot . '/configurador_prueba/php/vendor/fpdf';
$fpdfTargetFile = $fpdfTargetDir . '/fpdf.php';
$fpdfTargetTest = $projectRoot . '/configurador_prueba_test/php/vendor/fpdf';

if ($foundFpdf) {
    if (file_exists($fpdfTargetFile)) {
        $fpdfEntry['action'] = 'ya está en la ruta canónica — no acción';
        $fpdfEntry['ok'] = true;
    } else {
        // Try to copy to the canonical location for tidiness, but if the
        // copy fails (Plesk denies write to vendor/), the cross-env loader
        // in dossier-defensa.php will pick FPDF up directly from where it
        // already lives. So "found anywhere" = functional.
        $fpdfSrcDir = dirname($foundFpdf);
        if (!$run) {
            $fpdfEntry['action'] = "se intentará copiar a {$fpdfTargetDir}/ (si falla, el sistema lo cargará directamente desde {$fpdfSrcDir})";
            $fpdfEntry['ok'] = true;
        } else {
            $copied = _dossierSetupCopyDir($fpdfSrcDir, $fpdfTargetDir);
            if (!is_dir($fpdfTargetTest)) {
                _dossierSetupCopyDir($fpdfSrcDir, $fpdfTargetTest);
            }
            if ($copied) {
                $fpdfEntry['action'] = "copiado de {$fpdfSrcDir}";
                $fpdfEntry['ok'] = true;
            } else {
                // Copy failed (typical in Plesk). Verify FPDF actually loads
                // from the source path — that's what matters for runtime.
                if (!class_exists('FPDF') && file_exists($foundFpdf)) {
                    @require_once $foundFpdf;
                }
                $fpdfEntry['action'] = class_exists('FPDF')
                    ? "copia falló pero FPDF carga desde {$foundFpdf} ✓ (cross-env loader)"
                    : "copia falló y FPDF no carga — revisar permisos";
                $fpdfEntry['ok']     = class_exists('FPDF');
            }
        }
    }
} else {
    // Download FPDF from fpdf.org
    if (!$run) {
        $fpdfEntry['action'] = 'NOT FOUND — will download fpdf186 from fpdf.org and install to ' . $fpdfTargetDir;
        $fpdfEntry['ok'] = true;  // we plan to fix it
    } else {
        $r = _dossierSetupDownloadFpdf($fpdfTargetDir);
        $fpdfEntry['action'] = $r['msg'];
        $fpdfEntry['ok']     = $r['ok'];

        // Mirror to test
        if ($r['ok'] && !file_exists($fpdfTargetTest . '/fpdf.php')) {
            _dossierSetupCopyDir($fpdfTargetDir, $fpdfTargetTest);
        }
    }
}
$results[] = $fpdfEntry;

// ─────────────────────────────────────────────────────────────────────────
// STEP 3: confirm php-zip
// ─────────────────────────────────────────────────────────────────────────
$zipEntry = [
    'type'   => 'ext',
    'name'   => 'PHP ZipArchive',
    'before' => class_exists('ZipArchive') ? 'ok' : 'MISSING',
    'after'  => class_exists('ZipArchive') ? 'ok' : 'MISSING',
    'action' => class_exists('ZipArchive') ? 'no acción' : '⚠️ Pedir al hosting que habilite la extensión php-zip',
    'ok'     => class_exists('ZipArchive'),
];
$results[] = $zipEntry;

// ─────────────────────────────────────────────────────────────────────────
// STEP 4: trigger DB schema creation by loading dossier-defensa.php
// ─────────────────────────────────────────────────────────────────────────
$schemaEntry = ['type' => 'db', 'name' => 'tablas SQL', 'action' => null, 'ok' => false];
if ($run) {
    try {
        require_once $projectRoot . '/configurador_prueba/php/dossier-defensa.php';
        if (function_exists('dossierEnsureSchema')) {
            dossierEnsureSchema(getDB());
            $schemaEntry['action'] = 'dossiers_defensa table verified/created';
            $schemaEntry['ok']     = true;
        } else {
            $schemaEntry['action'] = 'dossier-defensa.php cargado pero función ausente';
        }
        // Also run archivo + escalations schemas if available
        if (file_exists($projectRoot . '/configurador_prueba/php/archivo-larga-duracion.php')) {
            require_once $projectRoot . '/configurador_prueba/php/archivo-larga-duracion.php';
            if (function_exists('archivoEnsureSchema')) archivoEnsureSchema(getDB());
        }
    } catch (Throwable $e) {
        $schemaEntry['action'] = 'ERROR: ' . $e->getMessage();
    }
} else {
    $schemaEntry['action'] = 'will call dossierEnsureSchema(getDB()) on run';
    $schemaEntry['ok']     = true;
}
$results[] = $schemaEntry;

// ─────────────────────────────────────────────────────────────────────────
// Render
// ─────────────────────────────────────────────────────────────────────────
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Voltika · Dossier Setup</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f0f4f8;color:#0c2340;padding:24px;max-width:920px;margin:0 auto;}
h1{font-size:22px;margin:0 0 6px;}
h2{font-size:13px;color:#64748b;margin:0 0 24px;text-transform:uppercase;letter-spacing:.5px;font-weight:500;}
.btn{display:inline-block;padding:10px 18px;background:#039fe1;color:#fff;border-radius:8px;font-weight:700;text-decoration:none;font-size:14px;}
.btn.go{background:#22c55e;}
.btn.danger{background:#ef4444;}
.banner{padding:14px 18px;border-radius:10px;margin:14px 0;font-size:14px;line-height:1.5;}
.banner.warn{background:#fef3c7;color:#78350f;border:1px solid #f59e0b;}
.banner.ok{background:#dcfce7;color:#14532d;border:1px solid #22c55e;}
.banner.run{background:#dbeafe;color:#1e40af;border:1px solid #60a5fa;}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);margin:12px 0;}
th{background:#1a3a5c;color:#fff;padding:10px 12px;font-size:12px;text-align:left;text-transform:uppercase;letter-spacing:.5px;}
td{padding:10px 12px;border-bottom:1px solid #f1f5f9;font-size:13px;}
tr:last-child td{border-bottom:0;}
.ok{color:#16a34a;font-weight:600;}
.bad{color:#dc2626;font-weight:600;}
code{background:#1e293b;color:#e2e8f0;padding:2px 7px;border-radius:4px;font-size:12px;font-family:ui-monospace,Menlo,monospace;}
.summary{background:#fff;padding:18px;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.06);margin-bottom:18px;}
details{background:#f8fafc;padding:8px 12px;border-radius:6px;margin-top:6px;font-size:12px;}
details ul{margin:6px 0 0 18px;padding:0;}
.path{font-family:ui-monospace,Menlo,monospace;font-size:11.5px;color:#475569;}
</style>
</head>
<body>

<h1>📦 Voltika · Dossier Setup</h1>
<h2>Installer / self-heal para el sistema de Dossier de Defensa</h2>

<?php
$allOk = true;
foreach ($results as $r) if (!$r['ok']) { $allOk = false; break; }
?>

<?php if (!$run): ?>
  <div class="banner run">
    <strong>Modo diagnóstico (dry-run)</strong> — solo muestra lo que se haría.
    Para ejecutar realmente los cambios, presiona el botón verde abajo.
  </div>
<?php else: ?>
  <?php if ($allOk): ?>
    <div class="banner ok">
      <strong>✓ Setup completado correctamente.</strong>
      Vuelve a la lista de Ventas y haz clic en 📦 sobre cualquier orden con moto asignada — el dossier se generará automáticamente.
    </div>
  <?php else: ?>
    <div class="banner warn">
      <strong>⚠ Setup parcial.</strong> Algunos pasos fallaron. Revisa la columna "Acción" abajo y comparte la captura con soporte si necesitas ayuda.
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="summary">
  <strong>Project root detectado:</strong>
  <code><?= htmlspecialchars($projectRoot) ?></code>
</div>

<table>
<thead><tr><th>Paso</th><th>Detalle</th><th>Estado</th><th>Acción</th></tr></thead>
<tbody>
<?php foreach ($results as $r): ?>
  <tr>
    <td><strong><?= htmlspecialchars($r['type'] ?? '') ?></strong></td>
    <td>
      <?php if (isset($r['path'])): ?>
        <span class="path"><?= htmlspecialchars($r['path']) ?></span>
        <?php if (isset($r['before'])): ?>
          <br><small>antes: <?= htmlspecialchars($r['before']) ?>
          <?= isset($r['after']) ? ' · después: ' . htmlspecialchars($r['after']) : '' ?></small>
        <?php endif; ?>
      <?php elseif (isset($r['name'])): ?>
        <strong><?= htmlspecialchars($r['name']) ?></strong>
        <?php if (!empty($r['found'])): ?>
          <br><small>encontrado: <span class="path"><?= htmlspecialchars($r['found']) ?></span></small>
        <?php elseif (!empty($r['paths_checked'])): ?>
          <details><summary>Rutas verificadas (<?= count($r['paths_checked']) ?>)</summary>
            <ul>
              <?php foreach ($r['paths_checked'] as $p => $st): ?>
                <li><span class="path"><?= htmlspecialchars($p) ?></span> — <?= $st === 'EXISTE' ? '<span class="ok">EXISTE</span>' : 'no' ?></li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php endif; ?>
        <?php if (isset($r['before'])): ?>
          <br><small>estado: <?= htmlspecialchars($r['before']) ?></small>
        <?php endif; ?>
      <?php endif; ?>
    </td>
    <td class="<?= $r['ok'] ? 'ok' : 'bad' ?>">
      <?= $r['ok'] ? '✓' : '✗' ?>
    </td>
    <td><?= htmlspecialchars($r['action'] ?? '') ?></td>
  </tr>
<?php endforeach; ?>
</tbody>
</table>

<div style="text-align:center;margin-top:24px;">
<?php if (!$run): ?>
  <a class="btn go" href="?run=1">▶ Ejecutar setup ahora</a>
<?php else: ?>
  <a class="btn" href="?">↻ Volver a diagnóstico (dry-run)</a>
  <a class="btn go" href="?run=1">▶ Re-ejecutar</a>
  <a class="btn" href="../#ventas">→ Ir a Ventas</a>
<?php endif; ?>
</div>

<div style="font-size:11px;color:#94a3b8;text-align:center;margin-top:24px;">
Voltika · Setup interno · admin: <?= htmlspecialchars((string)($_SESSION['admin_user_nombre'] ?? '?')) ?>
</div>

</body></html>

<?php
// ─────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────

function _dossierSetupCopyDir(string $src, string $dst): bool {
    if (!is_dir($src)) return false;
    if (!is_dir($dst)) @mkdir($dst, 0775, true);
    $ok = true;
    foreach (scandir($src) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $sp = $src . '/' . $entry;
        $dp = $dst . '/' . $entry;
        if (is_dir($sp)) {
            if (!_dossierSetupCopyDir($sp, $dp)) $ok = false;
        } else {
            if (!@copy($sp, $dp)) $ok = false;
        }
    }
    return $ok;
}

function _dossierSetupDownloadFpdf(string $targetDir): array {
    if (!is_dir($targetDir)) {
        if (!@mkdir($targetDir, 0775, true)) {
            return ['ok' => false, 'msg' => 'No pude crear ' . $targetDir];
        }
    }
    if (!is_writable($targetDir)) {
        return ['ok' => false, 'msg' => $targetDir . ' no es escribible'];
    }

    $url = 'http://www.fpdf.org/en/download/fpdf186.zip';
    $tmpZip = tempnam(sys_get_temp_dir(), 'fpdf') . '.zip';

    // Try cURL first, fall back to file_get_contents
    $data = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) $data = false;
    }
    if ($data === false) {
        $ctx = stream_context_create(['http' => ['timeout' => 60]]);
        $data = @file_get_contents($url, false, $ctx);
    }
    if ($data === false) {
        return ['ok' => false, 'msg' => 'No pude descargar FPDF de ' . $url . ' (red bloqueada?)'];
    }

    file_put_contents($tmpZip, $data);

    if (!class_exists('ZipArchive')) {
        @unlink($tmpZip);
        return ['ok' => false, 'msg' => 'php-zip no disponible — pídelo al hosting'];
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpZip) !== true) {
        @unlink($tmpZip);
        return ['ok' => false, 'msg' => 'ZIP descargado pero corrupto'];
    }

    $extractTo = sys_get_temp_dir() . '/voltika_fpdf_extract_' . bin2hex(random_bytes(3));
    @mkdir($extractTo, 0775, true);
    $zip->extractTo($extractTo);
    $zip->close();
    @unlink($tmpZip);

    // The zip extracts to fpdf186/ by default; copy its contents into target
    $extractedSub = null;
    foreach (scandir($extractTo) as $e) {
        if ($e === '.' || $e === '..') continue;
        if (is_dir($extractTo . '/' . $e)) { $extractedSub = $extractTo . '/' . $e; break; }
    }
    $sourceDir = $extractedSub ?: $extractTo;
    $copied = _dossierSetupCopyDir($sourceDir, $targetDir);

    // Cleanup
    _dossierSetupRmTree($extractTo);

    if (!file_exists($targetDir . '/fpdf.php')) {
        return ['ok' => false, 'msg' => 'Extraído pero fpdf.php no apareció en ' . $targetDir];
    }
    return ['ok' => true, 'msg' => 'descargado e instalado desde fpdf.org/fpdf186.zip'];
}

function _dossierSetupRmTree(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') continue;
        $p = $dir . '/' . $e;
        if (is_dir($p)) _dossierSetupRmTree($p);
        else @unlink($p);
    }
    @rmdir($dir);
}
