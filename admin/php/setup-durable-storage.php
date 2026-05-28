<?php
/**
 * Voltika Admin — Setup durable storage (Round 113, 2026-05-28).
 *
 * Problem: contracts and pagarés were saving to /tmp/ when the durable
 * directory wasn't writable. /tmp/ gets wiped by the OS, causing files
 * to vanish (Fernando Barush case: 2026-05-20 contract lost).
 *
 * This tool:
 *   1. Creates the durable storage directories if missing
 *   2. Verifies they're writable
 *   3. Lists any orphaned files still in /tmp/ (recovery candidates)
 *   4. Moves orphaned files from /tmp/ to durable storage (idempotent)
 *
 * Auth: admin only.
 */

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
adminRequireAuth(['admin']);

$action = (string)($_GET['action'] ?? '');

$dirs = [
    'contratos' => [
        'durable' => realpath(__DIR__ . '/../../configurador/php') . '/contratos',
        'tmp'     => sys_get_temp_dir() . '/voltika_contratos',
        'tmp_alt' => sys_get_temp_dir() . '/voltika_contratos_contado',
        'label'   => 'Contratos (configurador firma)',
    ],
    'pagares' => [
        'durable' => realpath(__DIR__) . '/checklists/pagares',
        'tmp'     => sys_get_temp_dir() . '/voltika_pagares',
        'tmp_alt' => null,
        'label'   => 'Pagarés (PAGARÉ checklist)',
    ],
];

// ── Helpers ─────────────────────────────────────────────────────────────
function _dirState(string $path): array {
    $exists   = is_dir($path);
    $writable = $exists && is_writable($path);
    $perms    = $exists ? substr(sprintf('%o', fileperms($path)), -4) : '—';
    $owner    = $exists && function_exists('posix_getpwuid')
                  ? (posix_getpwuid(fileowner($path))['name'] ?? fileowner($path))
                  : ($exists ? (string)fileowner($path) : '—');
    $count    = $exists ? count(glob($path . '/*.pdf') ?: []) : 0;
    return compact('exists', 'writable', 'perms', 'owner', 'count');
}

function _ensureDir(string $path): array {
    if (is_dir($path) && is_writable($path)) {
        return ['ok' => true, 'created' => false, 'msg' => 'Ya existía y es escribible.'];
    }
    if (!is_dir($path)) {
        if (!@mkdir($path, 0775, true)) {
            return ['ok' => false, 'created' => false,
                    'msg' => 'mkdir() falló — el padre no permite crear directorios desde PHP. ' .
                             'Revisa permisos del directorio padre.'];
        }
    }
    @chmod($path, 0775);
    if (!is_writable($path)) {
        return ['ok' => false, 'created' => true,
                'msg' => 'Directorio creado pero NO es escribible. PHP probablemente corre como un usuario ' .
                         'distinto del propietario. Necesitas chmod 775 + chown desde el panel del servidor.'];
    }
    return ['ok' => true, 'created' => true, 'msg' => 'Directorio creado y escribible.'];
}

function _movePdfs(string $from, string $to): array {
    if (!is_dir($from)) return ['moved' => 0, 'skipped' => 0, 'failed' => 0, 'files' => []];
    $moved = $skipped = $failed = 0;
    $list = [];
    foreach (glob($from . '/*.pdf') ?: [] as $src) {
        $dst = $to . '/' . basename($src);
        if (is_file($dst)) {
            $skipped++;
            $list[] = ['file' => basename($src), 'status' => 'skip', 'reason' => 'ya existe en destino'];
            continue;
        }
        if (@copy($src, $dst)) {
            @unlink($src);
            $moved++;
            $list[] = ['file' => basename($src), 'status' => 'move', 'reason' => 'OK'];
        } else {
            $failed++;
            $list[] = ['file' => basename($src), 'status' => 'fail', 'reason' => 'no se pudo copiar'];
        }
    }
    return compact('moved', 'skipped', 'failed', 'files');
}

// ── Actions ─────────────────────────────────────────────────────────────
$results = [];
if ($action === 'ensure' || $action === 'ensure_and_recover') {
    foreach ($dirs as $key => $d) {
        $results[$key]['ensure'] = _ensureDir($d['durable']);
    }
}
if ($action === 'ensure_and_recover') {
    foreach ($dirs as $key => $d) {
        if (empty($results[$key]['ensure']['ok'])) {
            $results[$key]['recover'] = ['skipped' => true, 'reason' => 'durable dir no escribible'];
            continue;
        }
        $recoverList = [];
        foreach (array_filter([$d['tmp'], $d['tmp_alt']]) as $tmp) {
            $r = _movePdfs($tmp, $d['durable']);
            $r['from'] = $tmp;
            $recoverList[] = $r;
        }
        $results[$key]['recover'] = $recoverList;
    }
}

// Current state (always shown)
foreach ($dirs as $key => $d) {
    $results[$key]['state'] = [
        'durable' => _dirState($d['durable']),
        'tmp'     => _dirState($d['tmp']),
        'tmp_alt' => $d['tmp_alt'] ? _dirState($d['tmp_alt']) : null,
    ];
}

// ── Render ──────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Setup durable storage — Voltika</title>
<style>
body{font-family:system-ui,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1080px;margin:0 auto;line-height:1.55;}
h1{font-size:22px;margin:0 0 4px;}
h2{font-size:15px;color:#475569;margin:22px 0 8px;border-bottom:1px solid #cbd5e1;padding-bottom:4px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:14px;}
.muted{color:#94a3b8;font-size:12px;}
.ok{color:#15803d;font-weight:700;}
.err{color:#b91c1c;font-weight:700;}
.warn{color:#a16207;font-weight:700;}
table{width:100%;border-collapse:collapse;font-size:12.5px;}
th{background:#f1f5f9;padding:7px 9px;text-align:left;font-size:11.5px;}
td{padding:7px 9px;border-top:1px solid #f1f5f9;vertical-align:top;}
code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:11px;font-family:ui-monospace,monospace;}
.btn{display:inline-block;padding:9px 16px;background:#039fe1;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;font-size:13px;margin-right:6px;}
.btn.warn{background:#f59e0b;}
.btn.ghost{background:#fff;color:#0c2340;border:1px solid #cbd5e1;}
.banner{padding:11px 14px;border-radius:8px;font-size:13.5px;margin-bottom:14px;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.banner-err{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;}
.banner-info{background:#dbeafe;border:1px solid #93c5fd;color:#1e40af;}
</style></head><body>

<h1>📁 Setup durable storage</h1>
<div class="muted"><?= date('Y-m-d H:i:s') ?> · Repara la pérdida de archivos en /tmp ejecutando lo necesario desde PHP.</div>

<h2>1. Estado actual de los directorios</h2>
<div class="card">
  <table>
    <thead><tr>
      <th>Sección</th><th>Path</th><th>Existe</th><th>Escribible</th><th>Perms</th><th>Owner</th><th>PDFs</th>
    </tr></thead>
    <tbody>
    <?php foreach ($dirs as $key => $d):
        $sd = $results[$key]['state'];
    ?>
      <tr style="background:#fef3c7;">
        <td rowspan="<?= $d['tmp_alt'] ? 3 : 2 ?>"><strong><?= htmlspecialchars($d['label']) ?></strong></td>
        <td><code><?= htmlspecialchars($d['durable']) ?></code> <span class="muted">(durable)</span></td>
        <td class="<?= $sd['durable']['exists'] ? 'ok' : 'err' ?>"><?= $sd['durable']['exists'] ? 'SÍ' : 'NO' ?></td>
        <td class="<?= $sd['durable']['writable'] ? 'ok' : 'err' ?>"><?= $sd['durable']['writable'] ? 'SÍ' : 'NO' ?></td>
        <td><code><?= htmlspecialchars($sd['durable']['perms']) ?></code></td>
        <td><?= htmlspecialchars((string)$sd['durable']['owner']) ?></td>
        <td><?= $sd['durable']['count'] ?></td>
      </tr>
      <tr>
        <td><code><?= htmlspecialchars($d['tmp']) ?></code> <span class="muted">(efímero /tmp)</span></td>
        <td class="<?= $sd['tmp']['exists'] ? 'warn' : 'muted' ?>"><?= $sd['tmp']['exists'] ? 'SÍ' : 'no' ?></td>
        <td><?= $sd['tmp']['writable'] ? 'SÍ' : '—' ?></td>
        <td><code><?= htmlspecialchars($sd['tmp']['perms']) ?></code></td>
        <td><?= htmlspecialchars((string)$sd['tmp']['owner']) ?></td>
        <td class="<?= $sd['tmp']['count'] > 0 ? 'warn' : 'muted' ?>"><?= $sd['tmp']['count'] ?></td>
      </tr>
      <?php if ($d['tmp_alt']): ?>
      <tr>
        <td><code><?= htmlspecialchars($d['tmp_alt']) ?></code> <span class="muted">(/tmp alt)</span></td>
        <td class="<?= $sd['tmp_alt']['exists'] ? 'warn' : 'muted' ?>"><?= $sd['tmp_alt']['exists'] ? 'SÍ' : 'no' ?></td>
        <td><?= $sd['tmp_alt']['writable'] ? 'SÍ' : '—' ?></td>
        <td><code><?= htmlspecialchars($sd['tmp_alt']['perms']) ?></code></td>
        <td><?= htmlspecialchars((string)$sd['tmp_alt']['owner']) ?></td>
        <td class="<?= $sd['tmp_alt']['count'] > 0 ? 'warn' : 'muted' ?>"><?= $sd['tmp_alt']['count'] ?></td>
      </tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($action === 'ensure' || $action === 'ensure_and_recover'): ?>
  <h2>2. Resultado de creación de directorios</h2>
  <div class="card">
    <table>
      <thead><tr><th>Sección</th><th>Estado</th><th>Detalle</th></tr></thead>
      <tbody>
      <?php foreach ($dirs as $key => $d):
          $e = $results[$key]['ensure'];
      ?>
        <tr>
          <td><?= htmlspecialchars($d['label']) ?></td>
          <td class="<?= $e['ok'] ? 'ok' : 'err' ?>"><?= $e['ok'] ? '✓ OK' : '✗ FALLÓ' ?></td>
          <td><?= htmlspecialchars($e['msg']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php if ($action === 'ensure_and_recover'): ?>
  <h2>3. Recuperación de archivos /tmp → durable</h2>
  <div class="card">
    <?php foreach ($dirs as $key => $d):
        $rs = $results[$key]['recover'] ?? null;
    ?>
      <div style="margin-bottom:14px;">
        <strong><?= htmlspecialchars($d['label']) ?></strong>
        <?php if (!empty($rs['skipped'])): ?>
          <div class="muted">⚠ Recuperación omitida: <?= htmlspecialchars($rs['reason']) ?></div>
        <?php else: ?>
          <?php foreach ($rs as $r): ?>
            <div style="margin-top:6px;font-size:12.5px;">
              Desde <code><?= htmlspecialchars($r['from']) ?></code>:
              <span class="ok"><?= $r['moved'] ?> movidos</span> ·
              <span class="muted"><?= $r['skipped'] ?> ya existían</span> ·
              <span class="<?= $r['failed'] > 0 ? 'err' : 'muted' ?>"><?= $r['failed'] ?> fallaron</span>
            </div>
            <?php if (!empty($r['files'])): ?>
              <details style="margin-left:18px;">
                <summary class="muted" style="font-size:11.5px;cursor:pointer;">Detalle archivos</summary>
                <table style="margin-top:6px;">
                  <thead><tr><th>Archivo</th><th>Acción</th><th>Razón</th></tr></thead>
                  <tbody>
                  <?php foreach ($r['files'] as $f): ?>
                    <tr>
                      <td><code><?= htmlspecialchars($f['file']) ?></code></td>
                      <td class="<?= $f['status']==='move' ? 'ok' : ($f['status']==='fail' ? 'err' : 'muted') ?>">
                        <?= htmlspecialchars($f['status']) ?>
                      </td>
                      <td><?= htmlspecialchars($f['reason']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </details>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<h2><?= $action ? '4' : '2' ?>. Acciones disponibles</h2>
<div class="card">
  <p style="font-size:13px;margin-top:0;">
    <strong>Recomendado:</strong> ejecuta <em>Asegurar + Recuperar</em>. Esto crea los directorios durables
    (si no existen) y mueve cualquier PDF que aún quede en /tmp a la ubicación permanente
    antes de que el sistema operativo los borre.
  </p>
  <p>
    <a class="btn" href="?action=ensure_and_recover">▶ Asegurar directorios + Recuperar /tmp</a>
    <a class="btn ghost" href="?action=ensure">Solo asegurar directorios (sin mover archivos)</a>
    <a class="btn ghost" href="?">Refrescar estado</a>
  </p>
  <p class="muted" style="font-size:12px;margin:8px 0 0;">
    Idempotente — puedes ejecutarlo varias veces sin riesgo. Archivos ya movidos no se duplican.
  </p>
</div>

</body></html>
