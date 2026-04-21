<?php
/**
 * POST — Delete a Punto Voltika (soft or hard)
 *
 * Body: { punto_id, modo?: "auditar"|"soft"|"hard" }
 *
 *   "auditar"  (default): count related records and return them so the admin
 *                         can choose the right modo.
 *   "soft"              : activo=0 on the punto + deactivate its users.
 *                         Safe for puntos with inventory/sales history.
 *   "hard"              : DELETE FROM puntos_voltika. Only allowed when no
 *                         inventory, envíos, or sales reference the punto.
 *                         Related users and comisiones are removed too.
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$d = adminJsonIn();
$puntoId = (int)($d['punto_id'] ?? 0);
$modo    = $d['modo'] ?? 'auditar';

if (!$puntoId) adminJsonOut(['error' => 'punto_id requerido'], 400);

$pdo = getDB();

$p = $pdo->prepare("SELECT id, nombre, activo FROM puntos_voltika WHERE id=? LIMIT 1");
$p->execute([$puntoId]);
$punto = $p->fetch(PDO::FETCH_ASSOC);
if (!$punto) adminJsonOut(['error' => 'Punto no encontrado'], 404);

// Count related records. These tables may or may not exist depending on
// which modules have been initialised — wrap each in try/catch so a missing
// table does not break the whole audit.
function _safeCount(PDO $pdo, string $sql, array $params): int {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        return (int)$s->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

$counts = [
    'usuarios'          => _safeCount($pdo, "SELECT COUNT(*) FROM dealer_usuarios WHERE punto_id=?", [$puntoId]),
    'inventario'        => _safeCount($pdo, "SELECT COUNT(*) FROM inventario_motos WHERE punto_voltika_id=? AND (activo IS NULL OR activo=1)", [$puntoId]),
    'envios'            => _safeCount($pdo, "SELECT COUNT(*) FROM envios WHERE punto_destino_id=?", [$puntoId]),
    'comisiones'        => _safeCount($pdo, "SELECT COUNT(*) FROM punto_comisiones WHERE punto_id=?", [$puntoId]),
];

// Hard-delete blockers: anything that represents real business history.
$bloqueoHard = ($counts['inventario'] > 0 || $counts['envios'] > 0);

if ($modo === 'auditar') {
    adminJsonOut([
        'ok'            => true,
        'punto'         => $punto,
        'counts'        => $counts,
        'puede_hard'    => !$bloqueoHard,
    ]);
}

if ($modo === 'soft') {
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE puntos_voltika SET activo=0 WHERE id=?")->execute([$puntoId]);
        $pdo->prepare("UPDATE dealer_usuarios SET activo=0 WHERE punto_id=?")->execute([$puntoId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        adminJsonOut(['error' => 'Error al desactivar: ' . $e->getMessage()], 500);
    }
    adminLog('punto_soft_delete', ['punto_id' => $puntoId, 'nombre' => $punto['nombre']]);
    adminJsonOut(['ok' => true, 'modo' => 'soft', 'message' => 'Punto desactivado correctamente.']);
}

if ($modo === 'hard') {
    if ($bloqueoHard) {
        adminJsonOut([
            'error'  => 'No se puede eliminar permanentemente: el punto tiene inventario o envíos asociados. Usá modo="soft".',
            'counts' => $counts,
        ], 409);
    }
    $pdo->beginTransaction();
    try {
        // Clean up dependent rows that are safe to remove.
        try { $pdo->prepare("DELETE FROM punto_comisiones WHERE punto_id=?")->execute([$puntoId]); } catch (Throwable $e) {}
        try { $pdo->prepare("DELETE FROM dealer_usuarios  WHERE punto_id=?")->execute([$puntoId]); } catch (Throwable $e) {}
        $pdo->prepare("DELETE FROM puntos_voltika WHERE id=?")->execute([$puntoId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        adminJsonOut(['error' => 'Error al eliminar: ' . $e->getMessage()], 500);
    }
    adminLog('punto_hard_delete', ['punto_id' => $puntoId, 'nombre' => $punto['nombre']]);
    adminJsonOut(['ok' => true, 'modo' => 'hard', 'message' => 'Punto eliminado permanentemente.']);
}

adminJsonOut(['error' => 'modo inválido'], 400);
