<?php
/**
 * One-shot fix-up: creates missing cotizaciones folders and chmods them to
 * 0777 so PHP can write uploads. Safe to run repeatedly; only reports what
 * was done. Admin-only.
 *
 *   /admin/php/fix-uploads-permisos.php
 */
require_once __DIR__ . '/bootstrap.php';
adminRequireAuth(['admin']);

$adminRoot = dirname(__DIR__);
$targets   = [
    $adminRoot . '/uploads',
    $adminRoot . '/uploads/cotizaciones',
    $adminRoot . '/uploads/cotizaciones/seguro',
    $adminRoot . '/uploads/cotizaciones/placas',
];

$report = [];

foreach ($targets as $dir) {
    $entry = ['path' => $dir, 'existed' => is_dir($dir), 'created' => false, 'chmod' => false, 'writable' => false, 'write_test' => null, 'notes' => []];

    if (!$entry['existed']) {
        $entry['created'] = @mkdir($dir, 0777, true);
        if (!$entry['created']) {
            $err = error_get_last();
            $entry['notes'][] = 'mkdir failed: ' . ($err['message'] ?? 'unknown');
        }
    }

    if (is_dir($dir)) {
        $entry['chmod'] = @chmod($dir, 0777);
        if (!$entry['chmod']) {
            $err = error_get_last();
            $entry['notes'][] = 'chmod failed: ' . ($err['message'] ?? 'unknown');
        }
        $entry['writable'] = is_writable($dir);

        // Real write test — chmod alone can lie on some filesystems (ACLs,
        // SELinux, etc.) so we actually try to create & delete a temp file.
        $probe = $dir . '/_writetest_' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($probe, 'ok') !== false) {
            $entry['write_test'] = 'ok';
            @unlink($probe);
        } else {
            $err = error_get_last();
            $entry['write_test'] = 'failed: ' . ($err['message'] ?? 'unknown');
        }

        // Resolve current mode + owner for visibility
        $stat = @stat($dir);
        if ($stat) {
            $entry['mode_octal'] = substr(sprintf('%o', $stat['mode']), -4);
            $entry['owner_uid']  = $stat['uid'];
            if (function_exists('posix_getpwuid')) {
                $pw = posix_getpwuid($stat['uid']);
                if ($pw) $entry['owner_name'] = $pw['name'];
            }
        }
    }

    $report[] = $entry;
}

// Process running as
$processUser = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? ('uid=' . posix_geteuid()))
    : get_current_user();

// Create .htaccess if missing
$ht = $adminRoot . '/uploads/cotizaciones/.htaccess';
$htExists = is_file($ht);
if (!$htExists) {
    @file_put_contents($ht, "Order deny,allow\nDeny from all\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n");
    $htExists = is_file($ht);
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Fix permisos — Voltika admin</title>
<style>
  body{font-family:-apple-system,Segoe UI,Arial,sans-serif;background:#f5f7fa;color:#1a3a5c;margin:0;padding:24px;}
  h1{margin:0 0 6px;font-size:22px;}
  p.lead{color:#555;margin:0 0 18px;font-size:13px;}
  .card{background:#fff;border:1px solid #e1e8ee;border-radius:10px;padding:20px;max-width:960px;margin:0 auto 18px;box-shadow:0 1px 4px rgba(12,35,64,.04);}
  table{width:100%;border-collapse:collapse;font-size:12px;}
  th,td{text-align:left;padding:8px 10px;border-bottom:1px solid #eef2f5;vertical-align:top;}
  th{background:#f5f7fa;font-weight:700;font-size:11px;color:#334;text-transform:uppercase;}
  .ok{color:#0e8f55;font-weight:700;}
  .err{color:#c62828;font-weight:700;}
  .warn{color:#b45309;font-weight:700;}
  code{background:#f7fafc;padding:1px 5px;border-radius:3px;font-size:11px;}
  pre{background:#f7fafc;padding:10px;border-radius:5px;font-size:11px;overflow:auto;}
  .banner{padding:12px 14px;border-radius:8px;font-size:13px;margin-bottom:14px;}
  .banner.ok{background:#ecfdf5;border-left:4px solid #0e8f55;color:#065f46;}
  .banner.bad{background:#fef2f2;border-left:4px solid #dc2626;color:#7a0e1f;}
</style>
</head>
<body>

<?php
$allOk = true;
foreach ($report as $r) {
    if ($r['write_test'] !== 'ok') { $allOk = false; break; }
}
?>

<div class="card">
  <h1>🔧 Fix permisos — <code>admin/uploads/cotizaciones/</code></h1>
  <p class="lead">Crea las carpetas necesarias y aplica <code>chmod 0777</code> para que PHP pueda guardar las cotizaciones. El <code>.htaccess</code> ya bloquea el acceso HTTP directo, así que <code>777</code> no compromete la seguridad.</p>

  <?php if ($allOk): ?>
    <div class="banner ok">✅ Todo listo — las 4 carpetas son escribibles por PHP. Puedes cerrar esta página e ir a probar la subida de cotización.</div>
  <?php else: ?>
    <div class="banner bad">⚠️ Al menos una carpeta no es escribible. Revisa la tabla abajo — si <code>chmod</code> falló, tu hosting no permite que PHP cambie permisos. Tienes que aplicarlos desde Plesk File Manager o FTP (777).</div>
  <?php endif; ?>

  <p class="lead">Proceso PHP corriendo como: <code><?= htmlspecialchars($processUser) ?></code> · <code>.htaccess</code>: <?= $htExists ? '<span class="ok">presente</span>' : '<span class="err">falta</span>' ?></p>

  <table>
    <thead>
      <tr><th>Carpeta</th><th>Existía</th><th>Creada ahora</th><th>chmod 0777</th><th>Modo actual</th><th>Dueño</th><th>is_writable</th><th>Write test</th><th>Notas</th></tr>
    </thead>
    <tbody>
      <?php foreach ($report as $r): ?>
        <tr>
          <td><code><?= htmlspecialchars(str_replace($adminRoot, 'admin', $r['path'])) ?></code></td>
          <td><?= $r['existed'] ? 'sí' : 'no' ?></td>
          <td><?= !$r['existed'] ? ($r['created'] ? '<span class="ok">sí</span>' : '<span class="err">falló</span>') : '—' ?></td>
          <td>
            <?php if (is_dir($r['path'])): ?>
              <?= $r['chmod'] ? '<span class="ok">aplicado</span>' : '<span class="err">falló</span>' ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td><code><?= htmlspecialchars($r['mode_octal'] ?? '—') ?></code></td>
          <td><?= htmlspecialchars($r['owner_name'] ?? ($r['owner_uid'] ?? '—')) ?></td>
          <td><?= $r['writable'] ? '<span class="ok">sí</span>' : '<span class="err">no</span>' ?></td>
          <td><?= $r['write_test'] === 'ok' ? '<span class="ok">ok</span>' : '<span class="err">' . htmlspecialchars($r['write_test'] ?? '—') . '</span>' ?></td>
          <td>
            <?php foreach ($r['notes'] as $n): ?>
              <div style="font-size:11px;color:#888;"><?= htmlspecialchars($n) ?></div>
            <?php endforeach; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if (!$allOk): ?>
<div class="card">
  <h1>📋 Plan B manual</h1>
  <p class="lead">Si el script no pudo aplicar <code>chmod</code> (típico en hostings con restricciones), aplícalo tú:</p>

  <h3 style="font-size:14px;margin:10px 0 6px;">Plesk File Manager</h3>
  <ol style="font-size:13px;line-height:1.8;">
    <li>Plesk → <strong>Websites & Domains → voltika.mx → File Manager</strong></li>
    <li>Ir a <code>httpdocs/admin/uploads/cotizaciones/</code></li>
    <li>Clic derecho en <code>placas</code> → <strong>Change Permissions</strong></li>
    <li>Marca <strong>"All subdirectories and files"</strong></li>
    <li>Owner <code>rwx</code> + Group <code>rwx</code> + Others <code>rwx</code> (= 777)</li>
    <li>OK. Repite para <code>seguro</code>.</li>
  </ol>

  <h3 style="font-size:14px;margin:14px 0 6px;">SSH</h3>
  <pre>cd /var/www/vhosts/voltika.mx/httpdocs/admin/uploads/cotizaciones
chmod 777 placas seguro</pre>

  <h3 style="font-size:14px;margin:14px 0 6px;">FTP</h3>
  <p style="font-size:13px;">WinSCP/FileZilla → clic derecho carpeta → <strong>File Permissions</strong> → <code>777</code> → recurse into subdirectories.</p>
</div>
<?php endif; ?>

</body>
</html>
