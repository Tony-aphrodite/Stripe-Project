<?php
/**
 * Voltika Admin — Round 46 diagnostic (2026-05-16).
 *
 * Standalone diagnostic for the "admin redirected to dealer-panel.html"
 * incident. Bypasses adminRequireAuth() because the admin user can't
 * log in (login.php rejects their session due to wrong rol value).
 *
 * Auth: shared-secret query string ?key=voltika_diag_2026
 *
 * URL:
 *   https://voltika.mx/admin/php/diagnostico-rol.php?key=voltika_diag_2026
 *   https://voltika.mx/admin/php/diagnostico-rol.php?key=voltika_diag_2026&email=admin@voltika.com.mx
 *
 * Reports:
 *   1. Current rol / permisos / activo for the queried user
 *   2. Recent rol_actualizado events for this user (who/when/from→to)
 *   3. Recent login + password events in chronological order
 *   4. Full activity log for the last 7 days
 *
 * Once the issue is resolved, this file can be deleted.
 */

declare(strict_types=1);

// Skip the usual admin bootstrap (no auth) — load config + DB directly.
require_once __DIR__ . '/bootstrap.php';

$expected = 'voltika_diag_2026';
$key = $_GET['key'] ?? '';
if (!hash_equals($expected, (string)$key)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Acceso denegado. Usa ?key=<secret>";
    exit;
}

$email = trim((string)($_GET['email'] ?? 'admin@voltika.com.mx'));

$pdo = getDB();

// ── 1. Current user state ────────────────────────────────────────────────
$user = null;
try {
    $stmt = $pdo->prepare("SELECT id, nombre, email, rol, permisos, activo,
                                  punto_nombre, punto_id, freg
                             FROM dealer_usuarios
                            WHERE email = ?
                            LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $userErr = $e->getMessage();
}

// ── 2. rol_actualizado events for this user ──────────────────────────────
$rolChanges = [];
if ($user) {
    try {
        // The admin_log row stores detalle as JSON; we filter by usuario_id field inside.
        $rcStmt = $pdo->prepare(
            "SELECT al.id, al.usuario_id AS changed_by_uid, al.accion, al.detalle, al.freg, al.ip,
                    du.email AS changed_by_email, du.nombre AS changed_by_nombre
               FROM admin_log al
               LEFT JOIN dealer_usuarios du ON du.id = al.usuario_id
              WHERE al.accion IN ('rol_actualizado','usuario_password_reset','password_change',
                                  'login_rechazado_rol_punto','login')
                AND (al.detalle LIKE ? OR al.detalle LIKE ?)
              ORDER BY al.freg DESC LIMIT 50"
        );
        $rcStmt->execute(['%"usuario_id":' . (int)$user['id'] . '%',
                          '%' . $email . '%']);
        $rolChanges = $rcStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rcErr = $e->getMessage();
    }
}

// ── 3. Recent activity broader (last 7 days, this user only) ────────────
$recentActivity = [];
if ($user) {
    try {
        $rqStmt = $pdo->prepare(
            "SELECT id, accion, detalle, freg, ip
               FROM admin_log
              WHERE usuario_id = ?
                AND freg >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              ORDER BY freg DESC LIMIT 100"
        );
        $rqStmt->execute([(int)$user['id']]);
        $recentActivity = $rqStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $raErr = $e->getMessage();
    }
}

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Voltika Diagnóstico — Rol del Admin</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fb;color:#0c2340;padding:24px;max-width:1080px;margin:0 auto;line-height:1.45;}
h1{font-size:22px;margin:0 0 4px;}
h2{font-size:14px;color:#475569;margin:24px 0 10px;text-transform:uppercase;letter-spacing:.4px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:14px;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th{text-align:left;padding:8px 6px;border-bottom:2px solid #cbd5e1;color:#475569;font-weight:700;font-size:12px;}
td{padding:8px 6px;border-bottom:1px solid #f1f5f9;vertical-align:top;}
code{background:#f1f5f9;color:#1e293b;padding:1px 5px;border-radius:3px;font-size:11.5px;font-family:ui-monospace,monospace;word-break:break-all;}
.ok{color:#16a34a;font-weight:700;}
.bad{color:#dc2626;font-weight:700;}
.warn{color:#d97706;font-weight:700;}
.muted{color:#94a3b8;font-size:11.5px;}
.banner{padding:12px 14px;border-radius:8px;font-size:13px;margin:12px 0;}
.banner-bad{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.banner-warn{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;}
.kv td:first-child{width:180px;color:#64748b;font-weight:700;font-size:12px;}
.filter{background:#fff;padding:14px;border-radius:10px;border:1px solid #e2e8f0;margin-bottom:14px;}
.filter input{padding:7px 11px;border:1px solid #cbd5e1;border-radius:6px;width:300px;font-size:13px;}
.filter button{padding:7px 15px;background:#039fe1;color:#fff;border:0;border-radius:6px;font-weight:600;cursor:pointer;}
pre{background:#0b1322;color:#e2e8f0;padding:10px;border-radius:6px;font-size:11.5px;overflow-x:auto;max-height:200px;}
</style></head><body>

<h1>🔍 Diagnóstico de rol — <code><?= htmlspecialchars($email) ?></code></h1>
<div class="muted">Generado: <?= date('Y-m-d H:i:s') ?> · servidor: <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '') ?></div>

<div class="filter">
  <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <input type="hidden" name="key" value="<?= htmlspecialchars($expected) ?>">
    <label>Email a inspeccionar: <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" placeholder="usuario@voltika.com.mx"></label>
    <button type="submit">Consultar</button>
  </form>
</div>

<?php if (!$user): ?>
  <div class="banner banner-bad">
    ✗ <strong>No se encontró ningún usuario con email <code><?= htmlspecialchars($email) ?></code> en dealer_usuarios.</strong>
    <?= isset($userErr) ? '<br>SQL error: ' . htmlspecialchars($userErr) : '' ?>
  </div>
<?php else: ?>

  <?php
    $rolActual = strtolower(trim((string)($user['rol'] ?? '')));
    $rolesPunto = ['dealer','punto','punto_voltika','punto-puntovoltika','pos'];
    $rolesAdmin = ['admin','cedis','operador','cobranza','documentos','logistica'];
    $isAdmin   = in_array($rolActual, $rolesAdmin, true);
    $isPunto   = in_array($rolActual, $rolesPunto, true);
  ?>

  <h2>1. Estado actual en la base de datos</h2>
  <div class="card">
    <table class="kv">
      <tr><td>ID</td><td><strong><?= (int)$user['id'] ?></strong></td></tr>
      <tr><td>Nombre</td><td><?= htmlspecialchars($user['nombre'] ?? '—') ?></td></tr>
      <tr><td>Email</td><td><code><?= htmlspecialchars($user['email']) ?></code></td></tr>
      <tr>
        <td>Rol</td>
        <td>
          <code><?= htmlspecialchars((string)$user['rol']) ?></code>
          <?php if ($isAdmin): ?>
            <span class="ok">✓ Rol de admin/back-office</span>
          <?php elseif ($isPunto): ?>
            <span class="bad">✗ Rol de punto → login.php redirige a /configurador/dealer-panel.html</span>
          <?php else: ?>
            <span class="warn">⚠ Rol no reconocido</span>
          <?php endif; ?>
        </td>
      </tr>
      <tr><td>Permisos</td><td><code><?= htmlspecialchars((string)($user['permisos'] ?? '(null)')) ?></code></td></tr>
      <tr><td>Activo</td><td><?= ((int)$user['activo'] === 1) ? '<span class="ok">✓ activo</span>' : '<span class="bad">✗ inactivo</span>' ?></td></tr>
      <tr><td>Punto asignado</td><td><?= htmlspecialchars((string)($user['punto_nombre'] ?? '—')) ?> <?= !empty($user['punto_id']) ? '<code>(id ' . htmlspecialchars((string)$user['punto_id']) . ')</code>' : '' ?></td></tr>
      <tr><td>Creado</td><td class="muted"><?= htmlspecialchars((string)$user['freg']) ?></td></tr>
    </table>

    <?php if ($isPunto): ?>
      <div class="banner banner-bad" style="margin-top:14px;">
        <strong>🎯 CAUSA RAÍZ CONFIRMADA</strong><br>
        El campo <code>rol</code> actualmente vale <code><?= htmlspecialchars((string)$user['rol']) ?></code>, que está en la lista de roles de Punto Voltika definida en
        <code>admin/php/auth/login.php:27</code>. Por eso el login redirige a <code>/configurador/dealer-panel.html</code> con HTTP 403.
        <br><br>
        <strong>Para restaurar el acceso admin:</strong><br>
        <code>UPDATE dealer_usuarios SET rol = 'admin' WHERE email = '<?= htmlspecialchars($email) ?>';</code><br>
        (O usa la pantalla de Roles desde otra cuenta admin activa).
      </div>
    <?php elseif ($isAdmin): ?>
      <div class="banner banner-ok" style="margin-top:14px;">
        ✓ El rol es <code><?= htmlspecialchars((string)$user['rol']) ?></code> — no debería ser redirigido por login.php.
        Si igual ves la redirección, revisa caché del navegador (Ctrl+Shift+R) y comprueba que <code>activo = 1</code> arriba.
      </div>
    <?php endif; ?>
  </div>

  <h2>2. Historial de cambios de rol y eventos de autenticación</h2>
  <div class="card">
    <?php if (empty($rolChanges)): ?>
      <div class="muted">Sin eventos relacionados encontrados en <code>admin_log</code>.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Fecha</th><th>Acción</th><th>Quién lo hizo</th><th>Detalle</th><th>IP</th></tr>
        </thead>
        <tbody>
          <?php foreach ($rolChanges as $r): ?>
            <tr>
              <td class="muted"><?= htmlspecialchars((string)$r['freg']) ?></td>
              <td>
                <?php $ac = (string)($r['accion'] ?? ''); ?>
                <strong style="color:<?= $ac === 'rol_actualizado' ? '#dc2626' : ($ac === 'login_rechazado_rol_punto' ? '#d97706' : '#0c2340') ?>">
                  <?= htmlspecialchars($ac) ?>
                </strong>
              </td>
              <td>
                <?php if (!empty($r['changed_by_email'])): ?>
                  <code><?= htmlspecialchars((string)$r['changed_by_email']) ?></code><br>
                  <span class="muted"><?= htmlspecialchars((string)($r['changed_by_nombre'] ?? '')) ?> (uid <?= (int)$r['changed_by_uid'] ?>)</span>
                <?php else: ?>
                  <span class="muted">uid <?= (int)($r['changed_by_uid'] ?? 0) ?></span>
                <?php endif; ?>
              </td>
              <td><pre><?= htmlspecialchars((string)$r['detalle']) ?></pre></td>
              <td class="muted"><?= htmlspecialchars((string)($r['ip'] ?? '—')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <h2>3. Toda la actividad del usuario (últimos 7 días)</h2>
  <div class="card">
    <?php if (empty($recentActivity)): ?>
      <div class="muted">Sin actividad reciente en admin_log para uid <?= (int)$user['id'] ?>.</div>
    <?php else: ?>
      <table>
        <thead><tr><th>Fecha</th><th>Acción</th><th>Detalle</th><th>IP</th></tr></thead>
        <tbody>
          <?php foreach ($recentActivity as $r): ?>
            <tr>
              <td class="muted" style="white-space:nowrap;"><?= htmlspecialchars((string)$r['freg']) ?></td>
              <td><strong><?= htmlspecialchars((string)$r['accion']) ?></strong></td>
              <td><code style="font-size:11px;"><?= htmlspecialchars(substr((string)$r['detalle'], 0, 300)) ?></code></td>
              <td class="muted"><?= htmlspecialchars((string)($r['ip'] ?? '—')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

<?php endif; ?>

<h2>Próximos pasos</h2>
<div class="card" style="font-size:13.5px;">
  <?php if ($user && in_array(strtolower(trim((string)$user['rol'])), $rolesPunto, true)): ?>
    <p>El diagnóstico confirma que <strong>el campo <code>rol</code> está incorrecto</strong>. El admin no puede entrar porque login.php aplica la regla de Round 7.</p>
    <p><strong>Reparación recomendada (cuando autorices):</strong></p>
    <ol>
      <li>Restaurar el rol original a <code>admin</code> (vía SQL o Roles screen).</li>
      <li>Revisar la sección 2 arriba para ver QUIÉN ejecutó <code>rol_actualizado</code> y cuándo — eso previene que vuelva a pasar.</li>
      <li>Verificar que <code>permisos</code> incluye los módulos esperados (ventas, inventario, etc.). Si está <code>NULL</code> o <code>[]</code>, el sidebar se vacía aunque el rol sea correcto.</li>
    </ol>
  <?php else: ?>
    <p>Si confirmas el problema de redirección con un <strong>email diferente</strong>, vuelve a ejecutar este diagnóstico con <code>?email=&lt;otro&gt;</code> en la URL.</p>
  <?php endif; ?>
</div>

</body></html>
