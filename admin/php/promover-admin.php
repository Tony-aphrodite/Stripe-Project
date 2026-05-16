<?php
/**
 * Voltika Admin — Round 46B emergency promotion tool (2026-05-16).
 *
 * Promotes a user account to full admin role after verifying the owner's
 * email + current password. Built specifically for the incident where
 * admin@voltika.com.mx had its rol downgraded to "dealer" — locking the
 * legitimate owner out of /admin/.
 *
 * Why password verification (not just admin auth):
 *   - The admin can't log in (login.php rejects rol='dealer' with a
 *     redirect to dealer-panel.html), so adminRequireAuth() is impossible.
 *   - Password match against bcrypt hash proves ownership. Same security
 *     model the change-password.php endpoint already uses.
 *   - The secret URL key is just to keep random visitors out of the page.
 *
 * URLs:
 *   GET  /admin/php/promover-admin.php?key=voltika_diag_2026
 *        → form to enter email + current password
 *   POST same URL with JSON body { email, password }
 *        → if password matches, sets rol='admin', clears punto_id /
 *          punto_nombre / permisos. Returns the updated row.
 *
 * Audit:
 *   - admin_log: 'promovido_a_admin_emergencia' with old → new diff
 *   - This file should be removed (or have the key changed) once the
 *     legitimate admin can log in again.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$expected = 'voltika_diag_2026';
$key = $_GET['key'] ?? '';
if (!hash_equals($expected, (string)$key)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Acceso denegado. Usa ?key=<secret>";
    exit;
}

$pdo = getDB();

// ─────────────────────────────────────────────────────────────────────────
// POST: verify password + promote
// ─────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $body = adminJsonIn();
    $email    = trim((string)($body['email']    ?? ''));
    $password = (string)($body['password'] ?? '');
    // Round 46B v2: "override" mode skips password verification but lets the
    // admin set a NEW password atomically. Used when the password hash in DB
    // no longer matches anything anyone remembers (e.g., change-password.php
    // crashed mid-flight, manual DB edit, etc.). The secret URL key is the
    // single auth gate — every override is heavy-audited.
    $override    = !empty($body['override']);
    $newPassword = (string)($body['newPassword'] ?? '');

    if ($email === '') {
        echo json_encode(['ok' => false, 'error' => 'Email requerido']);
        exit;
    }
    if (!$override && $password === '') {
        echo json_encode(['ok' => false, 'error' => 'Contraseña requerida (o activa el modo override + nueva contraseña)']);
        exit;
    }
    if ($override && strlen($newPassword) < 6) {
        echo json_encode(['ok' => false, 'error' => 'En modo override, la nueva contraseña debe tener al menos 6 caracteres']);
        exit;
    }

    // Look up the user.
    $u = $pdo->prepare(
        "SELECT id, nombre, email, rol, permisos, punto_id, punto_nombre,
                password_hash, activo
           FROM dealer_usuarios
          WHERE email = ?
          LIMIT 1"
    );
    $u->execute([$email]);
    $user = $u->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        echo json_encode(['ok' => false, 'error' => 'No existe usuario con ese email']);
        exit;
    }
    if (!$override && !password_verify($password, (string)$user['password_hash'])) {
        // Audit failed attempts so we can see brute-force attempts.
        try {
            $pdo->prepare(
                "INSERT INTO admin_log (usuario_id, accion, detalle, ip)
                 VALUES (?, 'promover_admin_fail', ?, ?)"
            )->execute([(int)$user['id'],
                        json_encode(['email' => $email, 'reason' => 'wrong_password']),
                        $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (Throwable $e) {}
        echo json_encode(['ok' => false, 'error' => 'Contraseña incorrecta — usa el modo Override si quieres forzar la restauración con una nueva contraseña.']);
        exit;
    }
    if ((int)$user['activo'] !== 1) {
        echo json_encode(['ok' => false, 'error' => 'Usuario inactivo (activo=0). Reactívalo desde la pantalla de Roles primero.']);
        exit;
    }

    // Snapshot the "before" values for audit trail.
    $before = [
        'rol'           => $user['rol'],
        'permisos'      => $user['permisos'],
        'punto_id'      => $user['punto_id'],
        'punto_nombre'  => $user['punto_nombre'],
    ];

    // Promote: set rol=admin, clear permisos (NULL = full access in sidebar
    // semantics), unbind from any specific punto. Active flag stays as is.
    // In override mode, also reset the password_hash to the supplied new value.
    try {
        if ($override) {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $pdo->prepare(
                "UPDATE dealer_usuarios
                    SET rol = 'admin',
                        permisos = NULL,
                        punto_id = NULL,
                        punto_nombre = NULL,
                        password_hash = ?
                  WHERE id = ?"
            )->execute([$newHash, (int)$user['id']]);
        } else {
            $pdo->prepare(
                "UPDATE dealer_usuarios
                    SET rol = 'admin',
                        permisos = NULL,
                        punto_id = NULL,
                        punto_nombre = NULL
                  WHERE id = ?"
            )->execute([(int)$user['id']]);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Error de DB: ' . $e->getMessage()]);
        exit;
    }

    // Re-read so the response reflects DB truth.
    $u2 = $pdo->prepare(
        "SELECT id, nombre, email, rol, permisos, punto_id, punto_nombre, activo
           FROM dealer_usuarios WHERE id = ?"
    );
    $u2->execute([(int)$user['id']]);
    $after = $u2->fetch(PDO::FETCH_ASSOC);

    try {
        $accion = $override ? 'promovido_a_admin_override' : 'promovido_a_admin_emergencia';
        $pdo->prepare(
            "INSERT INTO admin_log (usuario_id, accion, detalle, ip)
             VALUES (?, ?, ?, ?)"
        )->execute([
            (int)$user['id'],
            $accion,
            json_encode([
                'email'         => $email,
                'override'      => $override,
                'before'        => $before,
                'after'         => $after,
                'password_reset'=> $override ? 'yes' : 'no',
            ], JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {}

    echo json_encode([
        'ok'              => true,
        'message'         => $override
            ? 'Cuenta restaurada como admin Y nueva contraseña guardada. Inicia sesión con la nueva contraseña.'
            : 'Cuenta restaurada como admin. Ya puedes iniciar sesión en /admin/.',
        'override_used'   => $override,
        'before'          => $before,
        'after'           => $after,
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// GET: form
// ─────────────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Voltika — Restaurar acceso admin</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fb;color:#0c2340;padding:24px;max-width:680px;margin:0 auto;line-height:1.5;}
h1{font-size:22px;margin:0 0 4px;}
.muted{color:#94a3b8;font-size:12px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px;margin-bottom:14px;}
label{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px;}
input{width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;margin-bottom:12px;font-family:inherit;}
button{background:#039fe1;color:#fff;border:0;padding:12px 22px;border-radius:6px;font-weight:700;cursor:pointer;font-size:14px;}
button:disabled{background:#94a3b8;cursor:not-allowed;}
.banner{padding:12px 14px;border-radius:8px;font-size:13px;margin:12px 0;}
.banner-warn{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.banner-bad{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
pre{background:#0b1322;color:#e2e8f0;padding:12px;border-radius:6px;font-size:11.5px;overflow-x:auto;}
code{background:#f1f5f9;color:#1e293b;padding:1px 6px;border-radius:3px;font-size:11.5px;}
</style></head><body>

<h1>🔐 Restaurar acceso admin</h1>
<div class="muted">Round 46B · diagnóstico de emergencia · servidor <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '') ?></div>

<div class="banner banner-warn">
  Esta herramienta cambia <code>rol = 'admin'</code>, <code>permisos = NULL</code>,
  <code>punto_id = NULL</code>, <code>punto_nombre = NULL</code> para la cuenta
  cuyo email y contraseña actual coincidan. Es la salida cuando un admin perdió acceso
  por un cambio de rol accidental.
</div>

<div class="card">
  <label>Email de la cuenta</label>
  <input id="f_email" type="email" value="admin@voltika.com.mx" autocomplete="username">

  <label>Contraseña actual</label>
  <input id="f_pass" type="password" placeholder="La que escribiste al cambiarla recientemente" autocomplete="current-password">

  <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:12px;margin:12px 0;">
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;text-transform:none;letter-spacing:0;color:#9a3412;font-weight:600;margin:0;">
      <input id="f_override" type="checkbox" style="width:auto;margin:0;">
      ⚠ Modo override — el password actual no funciona / no lo recuerdo
    </label>
    <div id="f_override_panel" style="display:none;margin-top:10px;">
      <label style="color:#9a3412;">Nueva contraseña (mín. 6 caracteres)</label>
      <input id="f_newpass" type="password" placeholder="La nueva contraseña que vas a usar para iniciar sesión" autocomplete="new-password" style="margin-bottom:0;">
      <div style="font-size:11.5px;color:#9a3412;margin-top:8px;line-height:1.5;">
        En este modo NO se verifica la contraseña actual. Se sustituye <code>password_hash</code> por la nueva contraseña Y se ajusta el rol a admin. El cambio queda registrado en <code>admin_log.promovido_a_admin_override</code> con IP + email + antes/después. Úsalo solo cuando el password actual está corrupto / olvidado.
      </div>
    </div>
  </div>

  <button id="f_btn">✓ Promover a admin</button>
  <div id="f_status" style="margin-top:14px;font-size:13px;"></div>
  <div id="f_result"></div>
</div>

<div class="muted">
  La contraseña debe coincidir con el hash bcrypt en <code>dealer_usuarios.password_hash</code>.
  Intentos fallidos quedan registrados en <code>admin_log.promover_admin_fail</code>.
  Borra este archivo del servidor una vez restaurado el acceso.
</div>

<script>
document.getElementById('f_override').addEventListener('change', function(){
  document.getElementById('f_override_panel').style.display = this.checked ? 'block' : 'none';
  document.getElementById('f_pass').disabled = this.checked;
  document.getElementById('f_btn').textContent = this.checked ? '⚠ Override: forzar admin + nueva contraseña' : '✓ Promover a admin';
});
document.getElementById('f_btn').addEventListener('click', function(){
  var btn = this;
  var email    = document.getElementById('f_email').value.trim();
  var pass     = document.getElementById('f_pass').value;
  var override = document.getElementById('f_override').checked;
  var newpass  = document.getElementById('f_newpass').value;
  var status = document.getElementById('f_status');
  var result = document.getElementById('f_result');
  result.innerHTML = '';
  if (!email) { status.innerHTML = '<span style="color:#b91c1c">Email requerido.</span>'; return; }
  if (override) {
    if (!newpass || newpass.length < 6) { status.innerHTML = '<span style="color:#b91c1c">Nueva contraseña requerida (mín. 6 caracteres).</span>'; return; }
    if (!confirm('Vas a sobrescribir el password de ' + email + ' sin verificar el actual. ¿Continuar?')) return;
  } else {
    if (!pass) { status.innerHTML = '<span style="color:#b91c1c">Contraseña actual requerida (o activa Override).</span>'; return; }
  }
  btn.disabled = true;
  status.innerHTML = '<span style="color:#1e40af">⏳ ' + (override ? 'Sobrescribiendo password + restaurando rol…' : 'Verificando contraseña + actualizando rol…') + '</span>';
  fetch(location.pathname + location.search, {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: email, password: pass, override: override, newPassword: newpass })
  })
  .then(function(r){ return r.json(); })
  .then(function(j){
    if (j.ok) {
      status.innerHTML = '<span class="banner banner-ok" style="display:block">✓ ' + j.message + '</span>';
      result.innerHTML =
        '<div style="margin-top:12px"><strong>Antes:</strong><pre>' + JSON.stringify(j.before, null, 2) + '</pre></div>' +
        '<div><strong>Después:</strong><pre>' + JSON.stringify(j.after, null, 2)  + '</pre></div>' +
        '<div style="margin-top:14px"><a href="/admin/" style="color:#039fe1;font-weight:700;">→ Iniciar sesión en /admin/</a></div>';
    } else {
      status.innerHTML = '<span class="banner banner-bad" style="display:block">✗ ' + (j.error || 'falló') + '</span>';
      btn.disabled = false;
    }
  })
  .catch(function(e){
    status.innerHTML = '<span class="banner banner-bad" style="display:block">✗ ' + e.message + '</span>';
    btn.disabled = false;
  });
});
document.getElementById('f_pass').addEventListener('keypress', function(e){ if (e.key === 'Enter') document.getElementById('f_btn').click(); });
</script>

</body></html>
