<?php
/**
 * POST — Manually release (desasignar) a bike from a purchase order.
 * Body: { "transaccion_id": 123 }  or  { "moto_id": 456 }
 *
 * Customer brief 2026-05-06: Sales must be able to detach a unit from
 * an order without going through the full re-assignment flow. The
 * release MUST be reflected in CEDIS inventory — i.e. the moto becomes
 * available again for picking. We clear the customer/pedido fields on
 * inventario_motos so the dashboard JOIN in listar.php no longer ties
 * the unit to the order.
 */
require_once __DIR__ . '/../bootstrap.php';
$adminId = adminRequireAuth(['admin','cedis']);

$pdo  = getDB();
$body = adminJsonIn();

$transId = (int)($body['transaccion_id'] ?? 0);
$motoId  = (int)($body['moto_id'] ?? 0);

if (!$transId && !$motoId) {
    adminJsonOut(['error' => 'transaccion_id o moto_id es requerido'], 400);
}

// Resolve the moto to release. Prefer moto_id when supplied; otherwise
// look up by pedido_num so we can use the same JOIN logic as Ventas.
$moto = null;
if ($motoId) {
    $st = $pdo->prepare("SELECT * FROM inventario_motos WHERE id = ? AND activo = 1 LIMIT 1");
    $st->execute([$motoId]);
    $moto = $st->fetch(PDO::FETCH_ASSOC);
} elseif ($transId) {
    $stOrd = $pdo->prepare("SELECT pedido FROM transacciones WHERE id = ? LIMIT 1");
    $stOrd->execute([$transId]);
    $ord = $stOrd->fetch(PDO::FETCH_ASSOC);
    if (!$ord) {
        adminJsonOut(['error' => 'Orden no encontrada'], 404);
    }
    $pedidoNum = 'VK-' . $ord['pedido'];
    $st = $pdo->prepare("SELECT * FROM inventario_motos WHERE pedido_num = ? AND activo = 1 LIMIT 1");
    $st->execute([$pedidoNum]);
    $moto = $st->fetch(PDO::FETCH_ASSOC);
}

if (!$moto) {
    adminJsonOut(['error' => 'No se encontró ninguna moto asignada a esta orden.'], 404);
}

// Block desasignar if the moto is already entregada — at that point it
// has left inventory and the operation makes no sense.
//
// Customer brief 2026-05-09 (Óscar — "The system allows you to unassign
// a motorcycle that has already been delivered, and that's wrong"):
// the single estado='entregada' check missed three failure modes:
//   (a) cliente_acta_firmada=1 was set but estado lagged behind (race
//       between firmar-acta.php and finalizar.php)
//   (b) an entregas / acta row exists pointing at this moto even though
//       inventario_motos.estado was manually changed back
//   (c) the punto's finalizar-entrega flow already wrote estado='entregada'
//       earlier and a later manual edit re-set it to 'recibida'
// Belt-and-suspenders: refuse if ANY delivery evidence exists. The audit
// log records which signal blocked the release so support can debug.
$est = strtolower(trim($moto['estado'] ?? ''));
$actaFirmada = (int)($moto['cliente_acta_firmada'] ?? 0) === 1;
$entregaCompleta = false;
try {
    // entrega_punto / entrega rows have a `completada` or `estado='entregada'`
    // marker depending on schema age — check both forms.
    $envCols = $pdo->query("SHOW TABLES LIKE 'entregas'")->fetchColumn();
    if ($envCols) {
        $entCols = $pdo->query("SHOW COLUMNS FROM entregas")->fetchAll(PDO::FETCH_COLUMN);
        $estadoCol = in_array('estado', $entCols, true);
        $completaCol = in_array('completada', $entCols, true);
        $cond = [];
        if ($estadoCol)   $cond[] = "estado = 'entregada'";
        if ($completaCol) $cond[] = "completada = 1";
        if ($cond) {
            $sql = "SELECT 1 FROM entregas WHERE moto_id = ? AND (" . implode(' OR ', $cond) . ") LIMIT 1";
            $eStmt = $pdo->prepare($sql);
            $eStmt->execute([(int)$moto['id']]);
            $entregaCompleta = (bool)$eStmt->fetchColumn();
        }
    }
} catch (Throwable $e) { error_log('desasignar entregas check: ' . $e->getMessage()); }

if ($est === 'entregada' || $actaFirmada || $entregaCompleta) {
    $reasons = [];
    if ($est === 'entregada')   $reasons[] = "estado=entregada";
    if ($actaFirmada)           $reasons[] = "cliente_acta_firmada=1";
    if ($entregaCompleta)       $reasons[] = "entregas.completada=1";
    adminJsonOut([
        'error' => 'La moto ya fue entregada al cliente. No se puede desasignar.',
        'reasons' => $reasons,
        'moto_id' => (int)$moto['id'],
        'vin'     => $moto['vin_display'] ?? $moto['vin'] ?? '',
    ], 409);
}

// Round 61 (2026-05-20, Óscar): in addition to clearing customer + punto
// fields we ALSO reset estado='recibida' and supersede active envíos.
//
// Reason: previously desasignar cleared only the order-link columns but
// left estado='por_llegar'/'en_transito' from the first assignment and
// the corresponding envíos row in 'lista_para_enviar'/'en_transito'.
// Two visible consequences:
//   (a) motos-disponibles.php filters by estado IN ('recibida',
//       'lista_para_entrega') so a desasignada moto with estado=
//       'por_llegar' SILENTLY DISAPPEARED from the reassignment
//       dropdown. The admin couldn't find the moto and could not
//       reassign it to a different punto.
//   (b) The stale envío row pointed at the OLD punto, so the CEDIS
//       shipping panel kept showing a duplicate active envío for the
//       same VIN once a new assignment created another one.
//
// Resetting estado to 'recibida' (the canonical free-at-CEDIS state)
// and superseding the old envíos with 'completado_no_exitoso' lines
// the moto up correctly for the next asignar-punto.php / asignar-moto.php
// call regardless of which flow the admin uses next.
$adminNombre = '';
try {
    $du = $pdo->prepare("SELECT nombre FROM dealer_usuarios WHERE id = ? LIMIT 1");
    $du->execute([(int)$adminId]);
    $adminNombre = (string)($du->fetchColumn() ?: '');
} catch (Throwable $e) { /* non-fatal */ }

try {
    $pdo->beginTransaction();

    $rel = $pdo->prepare("
        UPDATE inventario_motos SET
            cliente_nombre   = NULL,
            cliente_email    = NULL,
            cliente_telefono = NULL,
            pedido_num       = NULL,
            stripe_pi        = NULL,
            pago_estado      = NULL,
            tipo_asignacion  = NULL,
            punto_voltika_id = NULL,
            estado           = 'recibida',
            fecha_estado     = NOW(),
            fmod             = NOW(),
            log_estados      = JSON_ARRAY_APPEND(
                COALESCE(log_estados, '[]'),
                '$',
                JSON_OBJECT(
                    'estado',         'recibida',
                    'accion',         'desasignar',
                    'fecha',          NOW(),
                    'usuario',        ?,
                    'usuario_nombre', ?,
                    'origen',         'admin_ventas_desasignar',
                    'pedido_num_old', ?
                )
            )
        WHERE id = ? AND activo = 1
    ");
    $rel->execute([
        (int)$adminId,
        $adminNombre,
        $moto['pedido_num'] ?? '',
        (int)$moto['id'],
    ]);

    // Try-with-log_estados can fail on legacy schemas without the column.
    // Fall back to a simpler UPDATE in that case so the operation still completes.
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('desasignar-moto with-log_estados update failed: ' . $e->getMessage()
              . ' — retrying without log_estados');
    $pdo->prepare("
        UPDATE inventario_motos SET
            cliente_nombre   = NULL,
            cliente_email    = NULL,
            cliente_telefono = NULL,
            pedido_num       = NULL,
            stripe_pi        = NULL,
            pago_estado      = NULL,
            tipo_asignacion  = NULL,
            punto_voltika_id = NULL,
            estado           = 'recibida',
            fecha_estado     = NOW(),
            fmod             = NOW()
        WHERE id = ? AND activo = 1
    ")->execute([(int)$moto['id']]);
}

// Supersede any active envíos for this moto so the CEDIS / Envíos panels
// don't keep a stale shipping pointer at the old punto. Mirrors the
// supersede block in asignar-punto.php (lines 157-182).
$supersededEnvios = [];
try {
    $chk = $pdo->prepare("SELECT id FROM envios WHERE moto_id = ?
                          AND estado IN ('lista_para_enviar','en_transito','enviado','enviada')");
    $chk->execute([(int)$moto['id']]);
    $supersededEnvios = array_map('intval', array_column($chk->fetchAll(PDO::FETCH_ASSOC), 'id'));
    if ($supersededEnvios) {
        $ph = implode(',', array_fill(0, count($supersededEnvios), '?'));
        try {
            $pdo->prepare("UPDATE envios SET estado='completado_no_exitoso', fmod=NOW()
                            WHERE id IN ($ph)")->execute($supersededEnvios);
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE envios SET estado='completado_no_exitoso'
                            WHERE id IN ($ph)")->execute($supersededEnvios);
        }
    }
} catch (Throwable $e) {
    error_log('desasignar-moto supersede envios: ' . $e->getMessage());
}

adminLog('desasignar_moto', [
    'moto_id'           => (int)$moto['id'],
    'vin'               => $moto['vin_display'] ?? $moto['vin'] ?? '',
    'pedido_num'        => $moto['pedido_num'] ?? '',
    'transaccion_id'    => $transId,
    'admin_id'          => $adminId,
    'estado_reset_to'   => 'recibida',
    'envios_superseded' => $supersededEnvios,
]);

adminJsonOut([
    'ok'         => true,
    'message'    => 'Moto ' . ($moto['vin_display'] ?? $moto['vin'] ?? '#'.$moto['id']) . ' liberada y devuelta al inventario.',
    'moto_id'    => (int)$moto['id'],
    'vin'        => $moto['vin_display'] ?? $moto['vin'] ?? '',
]);
