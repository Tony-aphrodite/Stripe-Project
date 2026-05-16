<?php
/**
 * POST — Update a user's role and permissions
 * Body: { "usuario_id": 1, "rol": "cobranza", "permisos": ["dashboard","pagos","cobranza"] }
 *
 * Customer brief 2026-05-04: investigation showed every row in
 * dealer_usuarios had permisos=NULL despite admins clicking Guardar.
 * Hardened in this rev: explicit array coercion, JSON encoding even
 * for empty list ("[]"), rowCount verification, and the new permisos
 * value echoed back in the response so the dashboard can confirm.
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin']);

$d = adminJsonIn();
$usuarioId = (int)($d['usuario_id'] ?? 0);
$rol = trim((string)($d['rol'] ?? ''));
$permisosRaw = $d['permisos'] ?? [];

if (!$usuarioId) adminJsonOut(['error' => 'usuario_id requerido'], 400);
if (!$rol)       adminJsonOut(['error' => 'rol requerido'], 400);

$validRoles = ['admin','dealer','cedis','operador','cobranza','documentos','logistica'];
if (!in_array($rol, $validRoles, true)) adminJsonOut(['error' => 'Rol no válido'], 400);

// ── Round 48 (2026-05-16, Óscar) — Self-demotion guard ─────────────────────
// Customer incident on 2026-05-16 03:41:24: admin@voltika.com.mx (uid 1)
// opened the Roles screen and accidentally clicked "dealer" in the rol
// dropdown for their OWN row, then hit Save. The endpoint accepted the
// update → login.php started redirecting them to /configurador/dealer-panel.html
// because dealer/punto users belong to the punto panel — admin lost
// access to /admin/. Recovery required a secret-key emergency script
// (promover-admin.php) and a full audit-log forensic to confirm what
// happened. To prevent recurrence, block any caller from changing their
// OWN role to a non-admin value. They can still call this endpoint to
// edit OTHER users' roles, and another admin can change their role for
// them if relinquishing privileges is intentional.
if ($usuarioId === (int)$uid && $rol !== 'admin') {
    adminLog('rol_actualizado_bloqueado_self', [
        'usuario_id'   => $usuarioId,
        'rol_solicitado' => $rol,
        'motivo'       => 'self_demotion_blocked',
    ]);
    adminJsonOut([
        'error' => 'No puedes cambiar tu propio rol a "' . $rol . '". ' .
                   'Para evitar bloqueos accidentales (login.php redirige los roles distintos a admin a otro panel), ' .
                   'esta operación requiere que otro admin la haga en tu nombre.',
        'reason' => 'self_demotion_blocked',
    ], 403);
}

// Defensive coercion — accept array, JSON-string, or comma-separated.
// The SPA sends an array but earlier reports showed permisos arriving
// as null in the DB, so be tolerant of upstream encoding mishaps.
if (is_string($permisosRaw)) {
    $tryDecode = json_decode($permisosRaw, true);
    if (is_array($tryDecode))      $permisosRaw = $tryDecode;
    elseif ($permisosRaw === '')    $permisosRaw = [];
    else                            $permisosRaw = array_map('trim', explode(',', $permisosRaw));
}
if (!is_array($permisosRaw)) $permisosRaw = [];
$permisos = array_values(array_filter(array_map('strval', $permisosRaw), fn($v) => $v !== ''));
$permisosJson = json_encode($permisos, JSON_UNESCAPED_UNICODE);
if (!is_string($permisosJson)) $permisosJson = '[]';

$pdo = getDB();
$stmt = $pdo->prepare("UPDATE dealer_usuarios SET rol = ?, permisos = ? WHERE id = ?");
$stmt->execute([$rol, $permisosJson, $usuarioId]);
$rowsUpdated = $stmt->rowCount();

// Verify by re-reading the row — if the UPDATE silently no-op'd
// (e.g., row didn't exist) this exposes it instead of returning ok.
$check = $pdo->prepare("SELECT id, rol, permisos FROM dealer_usuarios WHERE id = ? LIMIT 1");
$check->execute([$usuarioId]);
$verifyRow = $check->fetch(PDO::FETCH_ASSOC);

adminLog('rol_actualizado', [
    'usuario_id'    => $usuarioId,
    'rol'           => $rol,
    'permisos'      => $permisos,
    'rows_updated'  => $rowsUpdated,
]);

adminJsonOut([
    'ok'           => true,
    'usuario_id'   => $usuarioId,
    'rol'          => $rol,
    'permisos'     => $permisos,           // echo back what we saved
    'rows_updated' => $rowsUpdated,
    'db_check'     => $verifyRow,
]);
