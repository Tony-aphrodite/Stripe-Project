<?php
/**
 * POST — Cierra (no borra físicamente) un envío individual.
 *
 * Cliente brief 2026-05-09: cuando se reasigna una moto a otro punto, los
 * envíos viejos quedan visibles en el panel del admin sin posibilidad de
 * editarlos ni eliminarlos. Esta endpoint permite al admin marcar un envío
 * específico como `completado_no_exitoso` desde la UI.
 *
 * Body: { envio_id, motivo (optional, max 250 chars) }
 *
 * Por qué soft-close (estado='completado_no_exitoso') en lugar de DELETE:
 *   - Mantiene el audit trail (admin_audit + admin_log + envios row).
 *   - El historial de envíos por VIN sigue siendo auditable forensemente.
 *   - Mantiene foreign keys intactos (recepcion_punto.envio_id, etc.).
 *   - Reversible si fue cerrado por error: actualizar.php puede volver a
 *     abrir el estado correcto.
 *
 * Auth: admin only (cedis no debe poder cerrar envíos arbitrariamente).
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin']);

$d = adminJsonIn();
$envioId = (int)($d['envio_id'] ?? 0);
$motivo  = trim((string)($d['motivo'] ?? ''));
if (!$envioId) adminJsonOut(['error' => 'envio_id requerido'], 400);
if (strlen($motivo) > 250) $motivo = substr($motivo, 0, 250);

$pdo = getDB();

// Verify the envío exists and grab current state for the audit log.
$row = $pdo->prepare("SELECT id, moto_id, estado, punto_destino_id FROM envios WHERE id = ? LIMIT 1");
$row->execute([$envioId]);
$envio = $row->fetch(PDO::FETCH_ASSOC);
if (!$envio) adminJsonOut(['error' => 'Envío no encontrado'], 404);

// Reject if already in a closed state — re-closing is a no-op but we
// surface that explicitly so the operator doesn't think they did something.
$closedStates = ['completado','completado_no_exitoso','cancelado','recibida'];
if (in_array((string)$envio['estado'], $closedStates, true)) {
    adminJsonOut([
        'ok'             => true,
        'already_closed' => true,
        'estado_previo'  => $envio['estado'],
    ]);
}

// Soft-close.
try {
    $pdo->prepare("UPDATE envios SET estado='completado_no_exitoso', fmod=NOW() WHERE id=?")
        ->execute([$envioId]);
} catch (Throwable $e) {
    // Older schema fallback: no fmod column.
    $pdo->prepare("UPDATE envios SET estado='completado_no_exitoso' WHERE id=?")->execute([$envioId]);
}

adminLog('envio_eliminado', [
    'envio_id'      => $envioId,
    'moto_id'       => (int)$envio['moto_id'],
    'estado_previo' => $envio['estado'],
    'motivo'        => $motivo ?: null,
    'admin_id'      => $uid,
]);

adminJsonOut([
    'ok'            => true,
    'envio_id'      => $envioId,
    'estado_previo' => $envio['estado'],
    'estado_actual' => 'completado_no_exitoso',
]);
