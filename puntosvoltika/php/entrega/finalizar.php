<?php
/**
 * POST — Finalizar entrega: verifica ACTA firmada por cliente y marca moto entregada
 * Body: { moto_id }
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$d = puntoJsonIn();
$motoId = (int)($d['moto_id'] ?? 0);
if (!$motoId) puntoJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();

// Verify ACTA signed
$stmt = $pdo->prepare("SELECT cliente_acta_firmada FROM inventario_motos WHERE id=?");
$stmt->execute([$motoId]);
$signed = (int)$stmt->fetchColumn();

if (!$signed) {
    puntoJsonOut(['error' => 'El cliente aún no ha firmado el ACTA DE ENTREGA'], 400);
}

// Verify OTP was confirmed
$e = $pdo->prepare("SELECT otp_verified FROM entregas WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$e->execute([$motoId]);
if (!(int)$e->fetchColumn()) {
    puntoJsonOut(['error' => 'OTP del cliente no verificado'], 400);
}

// Round 84 (2026-05-26) — Checklist completion gate. Customer brief (Óscar):
// the entrega checklist must be complete before estado='entregada' is set.
// Before Round 84 this endpoint only checked ACTA + OTP, so a moto could be
// marked entregada with the F1/F2/F3 checklist still incomplete. That's
// what produced Adrian's case (yellow inconsistency banner — estado matches
// but checklist_entrega_v2.fase{1,2,3}_completada=0).
//
// Now: require all three operator-side fases marked complete. Tolerant of
// legacy rows that only have the `completado` column (older single-flag schema).
try {
    $cl = $pdo->prepare("SELECT fase1_completada, fase2_completada, fase3_completada, completado
                           FROM checklist_entrega_v2
                          WHERE moto_id = ?
                          ORDER BY id DESC LIMIT 1");
    $cl->execute([$motoId]);
    $clRow = $cl->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $exc) {
    // Column may not exist on a legacy install — fall back to `completado` only
    try {
        $cl = $pdo->prepare("SELECT completado FROM checklist_entrega_v2 WHERE moto_id=? ORDER BY id DESC LIMIT 1");
        $cl->execute([$motoId]);
        $clRow = ['completado' => (int)$cl->fetchColumn()];
    } catch (Throwable $exc2) { $clRow = null; }
}

if (!$clRow) {
    puntoJsonOut([
        'error' => 'No hay checklist de entrega registrado para esta moto. '
                 . 'Abre el módulo de Entrega → Paso 4 (Checklist) y completa las 3 fases antes de finalizar.',
        'code'  => 'checklist_no_iniciado',
    ], 409);
}

// Accept EITHER the legacy `completado=1` flag OR all three per-fase flags.
$clFullyDone = ((int)($clRow['completado'] ?? 0) === 1)
            || ((int)($clRow['fase1_completada'] ?? 0) === 1
             && (int)($clRow['fase2_completada'] ?? 0) === 1
             && (int)($clRow['fase3_completada'] ?? 0) === 1);

if (!$clFullyDone) {
    $pendientes = [];
    if (!(int)($clRow['fase1_completada'] ?? 0)) $pendientes[] = 'F1 — Identidad';
    if (!(int)($clRow['fase2_completada'] ?? 0)) $pendientes[] = 'F2 — Pago';
    if (!(int)($clRow['fase3_completada'] ?? 0)) $pendientes[] = 'F3 — Unidad';
    puntoJsonOut([
        'error' => 'Checklist de entrega incompleto. Faltan: ' . implode(', ', $pendientes ?: ['todas las fases'])
                 . '. Vuelve al módulo de Entrega → Paso 4 y marca todos los items obligatorios.',
        'code'  => 'checklist_incompleto',
        'fases_pendientes' => $pendientes,
    ], 409);
}

// Update moto final status
$pdo->prepare("UPDATE inventario_motos SET estado='entregada', fecha_estado=NOW() WHERE id=?")
    ->execute([$motoId]);

// Start weekly payment countdown for linked credit subscriptions.
// Per client feedback: "first payment starts counting AFTER the motorcycle
// is delivered, not from purchase date". Set fecha_inicio now so cron
// generar-ciclos.php creates ciclos from today forward.
try {
    $subCols = $pdo->query("SHOW COLUMNS FROM subscripciones_credito")->fetchAll(PDO::FETCH_COLUMN);
    $sets = ['fecha_inicio = CURDATE()'];
    if (in_array('fecha_entrega', $subCols, true)) $sets[] = 'fecha_entrega = CURDATE()';

    $motoRowX = $pdo->prepare("SELECT cliente_id, cliente_telefono, cliente_email FROM inventario_motos WHERE id=?");
    $motoRowX->execute([$motoId]);
    $mX = $motoRowX->fetch(PDO::FETCH_ASSOC) ?: [];

    // Match by cliente_id (primary) or telefono / email (fallback for older records)
    if (!empty($mX['cliente_id'])) {
        $pdo->prepare("UPDATE subscripciones_credito SET " . implode(', ', $sets) . "
            WHERE cliente_id = ? AND (fecha_inicio IS NULL OR fecha_inicio = '0000-00-00')")
            ->execute([$mX['cliente_id']]);
    } elseif (!empty($mX['cliente_telefono'])) {
        $pdo->prepare("UPDATE subscripciones_credito SET " . implode(', ', $sets) . "
            WHERE telefono = ? AND (fecha_inicio IS NULL OR fecha_inicio = '0000-00-00')")
            ->execute([$mX['cliente_telefono']]);
    }
} catch (Throwable $e) {
    error_log('finalizar fecha_inicio update: ' . $e->getMessage());
}

// Log venta
$motoRow = $pdo->prepare("SELECT * FROM inventario_motos WHERE id=?");
$motoRow->execute([$motoId]);
$m = $motoRow->fetch(PDO::FETCH_ASSOC);

$pdo->prepare("INSERT INTO ventas_log (moto_id, tipo, dealer_id, cliente_nombre, cliente_email, cliente_telefono,
    pedido_num, modelo, color, vin, monto) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([
        $motoId, 'entrega_voltika', $ctx['user_id'],
        $m['cliente_nombre'], $m['cliente_email'], $m['cliente_telefono'],
        $m['pedido_num'], $m['modelo'], $m['color'], $m['vin'], $m['precio_venta']
    ]);

puntoLog('entrega_finalizada', ['moto_id' => $motoId]);

// ── Delivery commission for the punto (comision_entrega from puntos_voltika) ──
// Generates one row in comisiones_log per delivered moto. Idempotent: if the
// same moto already has a tipo='entrega' entry, we skip to avoid double pay.
try {
    $puntoId = (int)($m['punto_voltika_id'] ?? 0);
    if ($puntoId > 0) {
        $exists = $pdo->prepare("SELECT id FROM comisiones_log
            WHERE pedido_num = ? AND tipo = 'entrega' AND punto_id = ? LIMIT 1");
        $exists->execute([$m['pedido_num'] ?? '', $puntoId]);
        if (!$exists->fetch()) {
            $cStmt = $pdo->prepare("SELECT COALESCE(comision_entrega, 0) FROM puntos_voltika WHERE id = ?");
            $cStmt->execute([$puntoId]);
            $comEntrega = (float)$cStmt->fetchColumn();
            if ($comEntrega > 0) {
                $pdo->prepare("INSERT INTO comisiones_log
                    (punto_id, referido_id, pedido_num, modelo, monto_venta, comision_pct, comision_monto, tipo)
                    VALUES (?, NULL, ?, ?, ?, NULL, ?, 'entrega')")
                    ->execute([
                        $puntoId,
                        $m['pedido_num'] ?? '',
                        $m['modelo'] ?? '',
                        (float)($m['precio_venta'] ?? 0),
                        round($comEntrega, 2),
                    ]);
            }
        }
    }
} catch (Throwable $e) {
    error_log('finalizar comision_entrega: ' . $e->getMessage());
}

// Notify cliente — "¡Bienvenido a la familia Voltika!"
require_once __DIR__ . '/../../../configurador_prueba_test/php/voltika-notify.php';
try {
    $proximo = '';
    if (!empty($m['cliente_id'])) {
        $st = $pdo->prepare("SELECT fecha_vencimiento FROM ciclos_pago
            WHERE cliente_id = ? AND estado IN ('pending','overdue')
            ORDER BY fecha_vencimiento ASC LIMIT 1");
        $st->execute([$m['cliente_id']]);
        $proximo = (string)($st->fetchColumn() ?: '');
    }
    voltikaNotify('entrega_completada', [
        'cliente_id'   => $m['cliente_id'] ?? null,
        'nombre'       => $m['cliente_nombre'] ?? '',
        'modelo'       => $m['modelo'] ?? '',
        'telefono'     => $m['cliente_telefono'] ?? '',
        'email'        => $m['cliente_email'] ?? '',
        'proximo_pago' => $proximo,
    ]);
} catch (Throwable $e) { error_log('notify entrega_completada: ' . $e->getMessage()); }

puntoJsonOut(['ok' => true, 'mensaje' => '¡Entrega completada!']);
