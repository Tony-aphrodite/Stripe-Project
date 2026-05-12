<?php
/**
 * POST — Soft-delete a motorcycle from this punto's inventory.
 * Body: { moto_id, motivo: "reason text" }
 *
 * Customer brief 2026-05-09: punto users had no way to remove a moto
 * from their inventory view, even when the row was clearly a duplicate /
 * test entry / mistake. They had to escalate to admin for every cleanup.
 * This endpoint lets the punto soft-delete an inventory item under
 * strict safety guards:
 *
 *   ✓ The moto must belong to THIS punto (no cross-punto deletion).
 *   ✓ The moto must NOT be linked to a real customer order
 *       (cliente_nombre / cliente_email / pedido_num all empty).
 *   ✓ The moto must NOT have an active envío row in flight.
 *   ✓ A non-empty motivo string is required (audit trail).
 *
 * Soft-delete (activo=0) — preserves the row for admin/audit. Admin can
 * un-delete by flipping activo=1 if it turns out to be a mistake.
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

// Customer brief 2026-05-12 (Óscar, 6th round): "Punto cannot delete a
// moto." The frontend no longer surfaces the Eliminar button, and this
// endpoint now hard-rejects so a forged request (older cached page,
// curl, etc.) cannot bypass the UI restriction. Audit logged for any
// attempts.
puntoLog('intento_eliminar_bloqueado', [
    'moto_id' => (int)($_POST['moto_id'] ?? json_decode(file_get_contents('php://input'), true)['moto_id'] ?? 0),
    'punto_id' => $ctx['punto_id'],
    'user_id'  => $ctx['user_id'],
]);
puntoJsonOut([
    'error' => 'La eliminación de motos está restringida. Solicita a CEDIS / admin que la procese.',
    'hint'  => 'Esta acción ya no está disponible para puntos. Contacta al equipo central para retirar el registro.',
], 403);

// ── Legacy code path kept below (now unreachable) ────────────────────
$d = puntoJsonIn();
$motoId = (int)($d['moto_id'] ?? 0);
$motivo = trim((string)($d['motivo'] ?? ''));

if (!$motoId) puntoJsonOut(['error' => 'moto_id requerido'], 400);
if ($motivo === '') puntoJsonOut(['error' => 'Motivo de eliminación requerido'], 400);
if (mb_strlen($motivo) > 250) puntoJsonOut(['error' => 'Motivo muy largo (máx 250 caracteres)'], 400);

$pdo = getDB();

// ── 1. Verify moto belongs to this punto AND is in a deletable state ──
$stmt = $pdo->prepare("SELECT id, vin_display, vin, modelo, color, punto_voltika_id,
                              cliente_nombre, cliente_email, pedido_num, activo
                         FROM inventario_motos
                        WHERE id = ?");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) puntoJsonOut(['error' => 'Moto no encontrada'], 404);

if (!(int)$moto['activo']) {
    puntoJsonOut(['error' => 'Esta moto ya está eliminada'], 410);
}

if ((int)$moto['punto_voltika_id'] !== $ctx['punto_id']) {
    puntoJsonOut(['error' => 'Esta moto no pertenece a tu punto'], 403);
}

// ── 2. Block if a real customer is linked ──
$hasCustomer = !empty($moto['cliente_nombre']) || !empty($moto['cliente_email']) || !empty($moto['pedido_num']);
if ($hasCustomer) {
    puntoJsonOut([
        'error'    => 'No puedes eliminar una moto con cliente o pedido asignado.',
        'cliente'  => $moto['cliente_nombre'] ?? null,
        'pedido'   => $moto['pedido_num']     ?? null,
        'hint'     => 'Para casos con cliente, contacta a CEDIS para reasignar la moto antes de eliminarla.',
    ], 409);
}

// ── 3. Block if there's an envío row in an active state ──
try {
    $envStmt = $pdo->prepare("SELECT id, estado FROM envios
                               WHERE moto_id = ?
                                 AND estado IN ('lista_para_enviar','enviada','en_transito','enviado')
                               ORDER BY freg DESC LIMIT 1");
    $envStmt->execute([$motoId]);
    $envActivo = $envStmt->fetch(PDO::FETCH_ASSOC);
    if ($envActivo) {
        puntoJsonOut([
            'error' => 'Esta moto tiene un envío activo (estado=' . $envActivo['estado'] . '). Pide a admin cerrar el envío antes de eliminar.',
            'envio_id' => (int)$envActivo['id'],
        ], 409);
    }
} catch (Throwable $e) {
    error_log('eliminar envios check: ' . $e->getMessage());
}

// ── 4. Soft-delete: activo=0 + audit columns (lazy-created) ──
try {
    $cols = $pdo->query("SHOW COLUMNS FROM inventario_motos")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('eliminado_por',  $cols, true)) $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN eliminado_por INT NULL");
    if (!in_array('eliminado_motivo', $cols, true)) $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN eliminado_motivo VARCHAR(250) NULL");
    if (!in_array('eliminado_fecha', $cols, true)) $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN eliminado_fecha DATETIME NULL");
} catch (Throwable $e) { error_log('eliminar audit cols: ' . $e->getMessage()); }

$pdo->prepare("UPDATE inventario_motos
                  SET activo = 0,
                      eliminado_por = ?,
                      eliminado_motivo = ?,
                      eliminado_fecha = NOW()
                WHERE id = ? AND activo = 1")
    ->execute([$ctx['user_id'], $motivo, $motoId]);

puntoLog('moto_eliminada_punto', [
    'moto_id' => $motoId,
    'vin'     => $moto['vin_display'] ?? $moto['vin'],
    'modelo'  => $moto['modelo'],
    'color'   => $moto['color'],
    'motivo'  => $motivo,
]);

puntoJsonOut([
    'ok'      => true,
    'moto_id' => $motoId,
    'vin'     => $moto['vin_display'] ?? $moto['vin'],
]);
