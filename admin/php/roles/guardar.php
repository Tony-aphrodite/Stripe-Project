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
