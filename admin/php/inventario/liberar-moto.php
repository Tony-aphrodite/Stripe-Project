<?php
/**
 * Voltika Admin — Round 28 v2 (2026-05-14, Óscar).
 *
 * POST { moto_id: int, notas?: string }
 *
 * Flips inventario_motos.estado from 'retenida' back to 'recibida' and
 * appends an audit entry to log_estados. Mirrors the 'liberar' action in
 * configurador/php/admin-moto-accion.php BUT uses the admin-panel session
 * (adminRequireAuth) instead of the dealer-panel session
 * (requireDealerAuth) so admins can use it directly from the inventario
 * detail modal without re-authenticating as a dealer.
 *
 * Does NOT touch bloqueado_venta — that's a separate sales-block flag
 * with its own bloquear-venta.php endpoint.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$json   = adminJsonIn();
$motoId = (int)($json['moto_id'] ?? 0);
$notas  = trim((string)($json['notas'] ?? ''));

if (!$motoId) {
    adminJsonOut(['error' => 'moto_id requerido'], 400);
}

$pdo = getDB();

$stmt = $pdo->prepare("SELECT id, estado FROM inventario_motos WHERE id = ? LIMIT 1");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) {
    adminJsonOut(['error' => 'Moto no encontrada'], 404);
}

if (strtolower((string)$moto['estado']) !== 'retenida') {
    adminJsonOut([
        'error'   => 'estado_no_retenida',
        'message' => 'La moto no está retenida actualmente (estado: ' . $moto['estado'] . '). Nada que liberar.',
        'estado'  => $moto['estado'],
    ], 409);
}

// Resolve display name for the audit trail. dealer_usuarios is the admin
// users table; the admin who clicks Liberar gets logged by id + name so
// future investigations can replay "who released what when".
$adminNombre = '';
try {
    $du = $pdo->prepare("SELECT nombre FROM dealer_usuarios WHERE id = ? LIMIT 1");
    $du->execute([(int)$uid]);
    $adminNombre = (string)($du->fetchColumn() ?: '');
} catch (Throwable $e) { /* non-fatal */ }

try {
    $pdo->beginTransaction();

    // Stamp the new state + append audit entry. JSON_ARRAY_APPEND with
    // COALESCE handles the case where log_estados is NULL on legacy rows.
    $pdo->prepare("UPDATE inventario_motos
            SET estado = 'recibida',
                fecha_estado = NOW(),
                fmod = NOW(),
                log_estados = JSON_ARRAY_APPEND(
                    COALESCE(log_estados, '[]'),
                    '$',
                    JSON_OBJECT(
                        'estado',  'recibida',
                        'accion',  'liberar',
                        'fecha',   NOW(),
                        'usuario', ?,
                        'usuario_nombre', ?,
                        'origen',  'admin_inventario_liberar',
                        'notas',   ?
                    )
                )
        WHERE id = ?")
        ->execute([(int)$uid, $adminNombre, $notas, $motoId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('liberar-moto error: ' . $e->getMessage());
    adminJsonOut(['error' => 'Error al liberar la moto: ' . $e->getMessage()], 500);
}

adminLog('inventario_liberar', [
    'moto_id'  => $motoId,
    'notas'    => $notas,
    'admin_id' => (int)$uid,
]);

adminJsonOut([
    'ok'         => true,
    'moto_id'    => $motoId,
    'estado_new' => 'recibida',
    'message'    => 'Moto liberada · estado: recibida',
]);
