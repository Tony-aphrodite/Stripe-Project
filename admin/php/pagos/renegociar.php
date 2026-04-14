<?php
/**
 * POST — Renegotiate a subscription (pause, resume, extend, reduce)
 * Body: { "subscripcion_id": 123, "accion": "pausar"|"reanudar"|"extender"|"reducir", "parametros": {...} }
 *
 * Actions:
 *   pausar   — parametros: { semanas: N }  — pause subscription, skip next N pending cycles
 *   reanudar — no parametros needed        — resume paused subscription
 *   extender — parametros: { semanas: N }  — add N weeks to plazo, create new cycles
 *   reducir  — parametros: { nuevo_monto_semanal: X } — lower weekly amount, extend plazo to compensate
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cobranza']);

$d = adminJsonIn();
$subId  = (int)($d['subscripcion_id'] ?? 0);
$accion = $d['accion'] ?? '';
$params = $d['parametros'] ?? [];

if (!$subId) adminJsonOut(['error' => 'subscripcion_id requerido'], 400);
if (!in_array($accion, ['pausar','reanudar','extender','reducir'])) {
    adminJsonOut(['error' => 'accion invalida (pausar|reanudar|extender|reducir)'], 400);
}

$pdo = getDB();

$stmt = $pdo->prepare("SELECT * FROM subscripciones_credito WHERE id = ?");
$stmt->execute([$subId]);
$sub = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sub) adminJsonOut(['error' => 'Subscripcion no encontrada'], 404);

switch ($accion) {

    // ── Pausar ──────────────────────────────────────────────────────────────
    case 'pausar':
        $semanas = (int)($params['semanas'] ?? 0);
        if ($semanas < 1 || $semanas > 52) {
            adminJsonOut(['error' => 'semanas debe ser entre 1 y 52'], 400);
        }
        if ($sub['estado'] === 'pausada') {
            adminJsonOut(['error' => 'La subscripcion ya esta pausada'], 400);
        }

        // Mark subscription as paused
        $pdo->prepare("UPDATE subscripciones_credito SET estado='pausada' WHERE id=?")
            ->execute([$subId]);

        // Skip next N pending cycles
        $stmt = $pdo->prepare("
            UPDATE ciclos_pago
            SET estado='skipped'
            WHERE subscripcion_id = ? AND estado = 'pending'
            ORDER BY semana_num ASC
            LIMIT ?
        ");
        $stmt->execute([$subId, $semanas]);
        $skipped = $stmt->rowCount();

        adminLog('renegociar_pausar', [
            'subscripcion_id' => $subId,
            'semanas'         => $semanas,
            'ciclos_skipped'  => $skipped,
        ]);

        adminJsonOut(['ok' => true, 'message' => "Subscripcion pausada, $skipped ciclos omitidos"]);
        break;

    // ── Reanudar ────────────────────────────────────────────────────────────
    case 'reanudar':
        if ($sub['estado'] !== 'pausada') {
            adminJsonOut(['error' => 'La subscripcion no esta pausada'], 400);
        }

        $pdo->prepare("UPDATE subscripciones_credito SET estado='activa' WHERE id=?")
            ->execute([$subId]);

        adminLog('renegociar_reanudar', ['subscripcion_id' => $subId]);

        adminJsonOut(['ok' => true, 'message' => 'Subscripcion reanudada']);
        break;

    // ── Extender ────────────────────────────────────────────────────────────
    case 'extender':
        $semanas = (int)($params['semanas'] ?? 0);
        if ($semanas < 1 || $semanas > 104) {
            adminJsonOut(['error' => 'semanas debe ser entre 1 y 104'], 400);
        }

        $oldPlazo = (int)$sub['plazo_semanas'];
        $newPlazo = $oldPlazo + $semanas;

        // Update plazo
        $pdo->prepare("UPDATE subscripciones_credito SET plazo_semanas=? WHERE id=?")
            ->execute([$newPlazo, $subId]);

        // Find the last existing cycle to continue numbering
        $stmt = $pdo->prepare("SELECT MAX(semana_num) as max_sem, MAX(fecha_vencimiento) as max_fecha FROM ciclos_pago WHERE subscripcion_id = ?");
        $stmt->execute([$subId]);
        $last = $stmt->fetch(PDO::FETCH_ASSOC);
        $lastSem   = (int)($last['max_sem'] ?? 0);
        $lastFecha = $last['max_fecha'] ? new DateTime($last['max_fecha']) : new DateTime();

        // Create new cycles
        $insertStmt = $pdo->prepare("
            INSERT INTO ciclos_pago (subscripcion_id, cliente_id, semana_num, fecha_vencimiento, monto, estado)
            VALUES (?, ?, ?, ?, ?, 'pending')
        ");
        $monto = $sub['monto_semanal'];
        for ($i = 1; $i <= $semanas; $i++) {
            $lastFecha->modify('+7 days');
            $insertStmt->execute([
                $subId,
                $sub['cliente_id'],
                $lastSem + $i,
                $lastFecha->format('Y-m-d'),
                $monto,
            ]);
        }

        adminLog('renegociar_extender', [
            'subscripcion_id' => $subId,
            'semanas_added'   => $semanas,
            'old_plazo'       => $oldPlazo,
            'new_plazo'       => $newPlazo,
        ]);

        adminJsonOut(['ok' => true, 'message' => "Plazo extendido de $oldPlazo a $newPlazo semanas ($semanas ciclos creados)"]);
        break;

    // ── Reducir ─────────────────────────────────────────────────────────────
    case 'reducir':
        $nuevoMonto = floatval($params['nuevo_monto_semanal'] ?? 0);
        if ($nuevoMonto <= 0) {
            adminJsonOut(['error' => 'nuevo_monto_semanal debe ser mayor a 0'], 400);
        }
        $oldMonto = (float)$sub['monto_semanal'];
        if ($nuevoMonto >= $oldMonto) {
            adminJsonOut(['error' => 'El nuevo monto debe ser menor al actual ($' . number_format($oldMonto, 2) . ')'], 400);
        }

        // Calculate remaining debt from pending cycles
        $stmt = $pdo->prepare("SELECT SUM(monto) as total_pendiente, COUNT(*) as ciclos_pendientes FROM ciclos_pago WHERE subscripcion_id = ? AND estado = 'pending'");
        $stmt->execute([$subId]);
        $pending = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalPendiente    = (float)($pending['total_pendiente'] ?? 0);
        $ciclosPendientes  = (int)($pending['ciclos_pendientes'] ?? 0);

        if ($totalPendiente <= 0) {
            adminJsonOut(['error' => 'No hay saldo pendiente para renegociar'], 400);
        }

        // New number of weeks needed
        $newSemanas = (int)ceil($totalPendiente / $nuevoMonto);
        $semanasExtra = $newSemanas - $ciclosPendientes;

        // Update monto_semanal on subscription
        $newPlazo = (int)$sub['plazo_semanas'] + max(0, $semanasExtra);
        $pdo->prepare("UPDATE subscripciones_credito SET monto_semanal=?, plazo_semanas=? WHERE id=?")
            ->execute([$nuevoMonto, $newPlazo, $subId]);

        // Update existing pending cycles with new amount
        $pdo->prepare("UPDATE ciclos_pago SET monto=? WHERE subscripcion_id=? AND estado='pending'")
            ->execute([$nuevoMonto, $subId]);

        // If extra weeks needed, create them
        if ($semanasExtra > 0) {
            $stmt = $pdo->prepare("SELECT MAX(semana_num) as max_sem, MAX(fecha_vencimiento) as max_fecha FROM ciclos_pago WHERE subscripcion_id = ?");
            $stmt->execute([$subId]);
            $last = $stmt->fetch(PDO::FETCH_ASSOC);
            $lastSem   = (int)($last['max_sem'] ?? 0);
            $lastFecha = $last['max_fecha'] ? new DateTime($last['max_fecha']) : new DateTime();

            $insertStmt = $pdo->prepare("
                INSERT INTO ciclos_pago (subscripcion_id, cliente_id, semana_num, fecha_vencimiento, monto, estado)
                VALUES (?, ?, ?, ?, ?, 'pending')
            ");
            for ($i = 1; $i <= $semanasExtra; $i++) {
                $lastFecha->modify('+7 days');
                $insertStmt->execute([
                    $subId,
                    $sub['cliente_id'],
                    $lastSem + $i,
                    $lastFecha->format('Y-m-d'),
                    $nuevoMonto,
                ]);
            }
        }

        adminLog('renegociar_reducir', [
            'subscripcion_id'    => $subId,
            'old_monto'          => $oldMonto,
            'new_monto'          => $nuevoMonto,
            'total_pendiente'    => $totalPendiente,
            'semanas_extra'      => max(0, $semanasExtra),
            'new_plazo'          => $newPlazo,
        ]);

        adminJsonOut(['ok' => true, 'message' => "Monto reducido de \$$oldMonto a \$$nuevoMonto/semana. Nuevo plazo: $newPlazo semanas (+$semanasExtra)"]);
        break;
}
