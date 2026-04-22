<?php
/**
 * POST — Point transitions a motorcycle between lifecycle states.
 *
 * Input: { moto_id, nuevo_estado, fecha_entrega_estimada?, notas? }
 *
 * Allowed transitions (per dashboards_diagrams.pdf):
 *   recibida           → en_ensamble         (start assembly)
 *   en_ensamble        → lista_para_entrega  (assembly done, requires fecha_entrega_estimada)
 *   recibida           → lista_para_entrega  (skip assembly, still requires date)
 *
 * NOTE: either path to `lista_para_entrega` now REQUIRES a completed
 * checklist_entrega (5-phase inspection). Customer feedback 2026-04-23
 * reported a punto was marking motos ready-to-deliver without inspection.
 * Without this gate the customer never gets the OTP and the legal act
 * (phase 5) is skipped — this is a safety + compliance bug.
 *
 * On lista_para_entrega: fires `lista_para_recoger` notification including the pickup date.
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$d = puntoJsonIn();
$motoId        = (int)($d['moto_id'] ?? 0);
$nuevoEstado   = trim($d['nuevo_estado'] ?? '');
$fechaEntrega  = trim($d['fecha_entrega_estimada'] ?? '');
$notas         = trim($d['notas'] ?? '');

if (!$motoId || !$nuevoEstado) {
    puntoJsonOut(['error' => 'moto_id y nuevo_estado son obligatorios'], 400);
}

$allowed = ['en_ensamble', 'lista_para_entrega'];
if (!in_array($nuevoEstado, $allowed, true)) {
    puntoJsonOut(['error' => 'Estado no permitido: ' . $nuevoEstado], 400);
}

$pdo = getDB();

// Load the moto and verify it belongs to this point
$stmt = $pdo->prepare("SELECT * FROM inventario_motos WHERE id=? AND punto_voltika_id=?");
$stmt->execute([$motoId, $ctx['punto_id']]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) puntoJsonOut(['error' => 'Moto no encontrada en tu inventario'], 404);

// Physical-presence gate: block any lifecycle transition until the moto has a
// recepcion_punto record. Without this the punto could mark "lista para entrega"
// on a moto still at CEDIS (reported by customer 2026-04-18).
$recStmt = $pdo->prepare("SELECT id FROM recepcion_punto WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$recStmt->execute([$motoId]);
$hasRecepcion = (bool)$recStmt->fetchColumn();
if (!$hasRecepcion) {
    puntoJsonOut([
        'error' => 'Esta moto aún no ha sido recibida físicamente en tu punto. '
                 . 'Escanea el VIN en el módulo de Recepción antes de cambiar su estado.'
    ], 409);
}

if (!empty($moto['bloqueado_venta'])) {
    puntoJsonOut(['error' => 'Esta moto está bloqueada. Motivo: ' . ($moto['bloqueado_motivo'] ?? 'Sin motivo') . '. Contacta a CEDIS para desbloquearla.'], 403);
}

$estadoActual = $moto['estado'] ?? '';

// Transition rules
$validTransitions = [
    'recibida'    => ['en_ensamble', 'lista_para_entrega'],
    'en_ensamble' => ['lista_para_entrega'],
];
if (!isset($validTransitions[$estadoActual]) || !in_array($nuevoEstado, $validTransitions[$estadoActual], true)) {
    puntoJsonOut(['error' => 'Transición no permitida: ' . $estadoActual . ' → ' . $nuevoEstado], 400);
}

// lista_para_entrega requires a valid future pickup date
// Per dashboards_diagrams.pdf (diagram 5): showroom motos with no cliente assigned
// must NOT transition to lista_para_entrega — they should remain in showroom
// inventory and wait for a CASE 4 direct sale or a CEDIS assignment.
if ($nuevoEstado === 'lista_para_entrega') {
    if (empty($moto['cliente_nombre']) && empty($moto['pedido_num'])) {
        puntoJsonOut([
            'error' => 'Esta moto está en showroom y no tiene cliente asignado. '
                     . 'No se puede marcar "lista para entrega" sin un pedido vinculado.'
        ], 400);
    }
    if (!$fechaEntrega || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaEntrega)) {
        puntoJsonOut(['error' => 'Fecha de entrega estimada requerida (YYYY-MM-DD)'], 400);
    }
    $ts = strtotime($fechaEntrega);
    if (!$ts || $ts < strtotime('today')) {
        puntoJsonOut(['error' => 'La fecha debe ser hoy o posterior'], 400);
    }

    // ── Checklist de entrega gate ──────────────────────────────────────────
    // The 5-phase entrega checklist (identity / pago / unidad / OTP / acta)
    // must be completed before the moto is ready-to-pickup. Without this the
    // point could skip inspection + legal act, which is what the customer
    // flagged. Tolerate both v2 (newer) and legacy tables so upgrades don't
    // block operations.
    $clComplete = false;
    foreach (['checklist_entrega_v2', 'checklist_entrega'] as $tbl) {
        try {
            $q = $pdo->prepare("SELECT completado FROM {$tbl}
                                WHERE moto_id = ?
                                ORDER BY id DESC LIMIT 1");
            $q->execute([$motoId]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                if ((int)($row['completado'] ?? 0) === 1) { $clComplete = true; }
                break; // found a row (complete or not) — don't check legacy fallback
            }
        } catch (Throwable $e) {
            // Table may not exist in this deployment — try the next one
            continue;
        }
    }
    if (!$clComplete) {
        puntoJsonOut([
            'error' => 'No puedes marcar "lista para entrega" sin completar el checklist de entrega. '
                     . 'Abre el módulo de Checklist, completa las 5 fases (identidad, pago, unidad, OTP y acta de entrega) y vuelve a intentarlo.',
            'code'  => 'checklist_entrega_pendiente',
        ], 409);
    }
}

// Ensure fecha_entrega_estimada column exists on inventario_motos
try {
    $cols = $pdo->query("SHOW COLUMNS FROM inventario_motos")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('fecha_entrega_estimada', $cols, true)) {
        $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN fecha_entrega_estimada DATE NULL");
    }
} catch (Throwable $e) { error_log('cambiar-estado ensure column: ' . $e->getMessage()); }

// Apply the transition
if ($nuevoEstado === 'lista_para_entrega') {
    $pdo->prepare("UPDATE inventario_motos
        SET estado=?, fecha_entrega_estimada=?, fmod=NOW()
        WHERE id=?")->execute([$nuevoEstado, $fechaEntrega, $motoId]);
} else {
    $pdo->prepare("UPDATE inventario_motos SET estado=?, fmod=NOW() WHERE id=?")
        ->execute([$nuevoEstado, $motoId]);
}

puntoLog('cambiar_estado', [
    'moto_id'                => $motoId,
    'estado_anterior'        => $estadoActual,
    'estado_nuevo'           => $nuevoEstado,
    'fecha_entrega_estimada' => $fechaEntrega ?: null,
    'notas'                  => $notas ?: null,
]);

// On lista_para_entrega → notify the client with the pickup date
if ($nuevoEstado === 'lista_para_entrega' && !empty($moto['cliente_telefono'])) {
    require_once __DIR__ . '/../../../configurador_prueba_test/php/voltika-notify.php';
    try {
        $pq = $pdo->prepare("SELECT nombre, direccion, horarios FROM puntos_voltika WHERE id=?");
        $pq->execute([$ctx['punto_id']]);
        $punto = $pq->fetch(PDO::FETCH_ASSOC) ?: [];

        // Format the pickup date for humans: "15 de abril de 2026"
        $meses = ['enero','febrero','marzo','abril','mayo','junio',
                  'julio','agosto','septiembre','octubre','noviembre','diciembre'];
        $dt = new DateTime($fechaEntrega);
        $fechaHuman = $dt->format('j') . ' de ' . $meses[(int)$dt->format('n') - 1] . ' de ' . $dt->format('Y');

        voltikaNotify('lista_para_recoger', [
            'cliente_id'    => $moto['cliente_id'] ?? null,
            'nombre'        => $moto['cliente_nombre'] ?? '',
            'modelo'        => $moto['modelo'] ?? '',
            'punto'         => $punto['nombre'] ?? '',
            'direccion'     => $punto['direccion'] ?? '',
            'horario'       => $punto['horarios'] ?? '',
            'fecha_entrega' => $fechaHuman,
            'telefono'      => $moto['cliente_telefono'],
            'email'         => $moto['cliente_email'] ?? '',
        ]);
    } catch (Throwable $e) { error_log('notify lista_para_recoger: ' . $e->getMessage()); }
}

puntoJsonOut([
    'ok'                     => true,
    'moto_id'                => $motoId,
    'estado'                 => $nuevoEstado,
    'fecha_entrega_estimada' => $fechaEntrega ?: null,
]);
