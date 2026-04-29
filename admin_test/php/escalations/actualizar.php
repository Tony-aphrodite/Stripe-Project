<?php
/**
 * POST — Update escalation (estado / asignado_a / notas).
 * Body: { id, estado?, asignado_a?, notas? }
 *
 * When estado transitions to 'resolved' or 'closed' on a dispute/chargeback
 * the related subscription is unfrozen back to 'activa' so auto-cobro can
 * resume — IF no other open dispute exists for the same client.
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$d = adminJsonIn();
$id = (int)($d['id'] ?? 0);
if (!$id) adminJsonOut(['error' => 'id requerido'], 400);

$pdo = getDB();

$stmt = $pdo->prepare("SELECT * FROM escalations WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) adminJsonOut(['error' => 'No encontrada'], 404);

$updates = [];
$params  = [];

if (isset($d['estado'])) {
    $newEstado = trim((string)$d['estado']);
    if (!in_array($newEstado, ['open','in_progress','resolved','closed'], true)) {
        adminJsonOut(['error' => 'estado inválido'], 400);
    }
    $updates[] = 'estado = ?';
    $params[]  = $newEstado;
    if (in_array($newEstado, ['resolved','closed'], true)) {
        $updates[] = 'resolved_at = NOW()';
    }
}
if (isset($d['asignado_a'])) {
    $updates[] = 'asignado_a = ?';
    $params[]  = substr((string)$d['asignado_a'], 0, 80);
}
if (isset($d['notas'])) {
    $updates[] = 'notas = ?';
    $params[]  = (string)$d['notas'];
}

if (!$updates) adminJsonOut(['ok' => true, 'no_change' => true]);

$params[] = $id;
$pdo->prepare("UPDATE escalations SET " . implode(', ', $updates) . " WHERE id = ?")
    ->execute($params);

// Unfreeze subscription if this was the last open dispute/chargeback for the client.
$newEstado = $d['estado'] ?? null;
if (in_array($newEstado, ['resolved','closed'], true)
    && in_array($row['kind'], ['chargeback','dispute','profeco'], true)
    && !empty($row['cliente_id'])) {
    try {
        $stillOpen = $pdo->prepare("SELECT COUNT(*) FROM escalations
            WHERE cliente_id = ?
              AND kind IN ('chargeback','dispute','profeco')
              AND estado IN ('open','in_progress')
              AND id <> ?");
        $stillOpen->execute([(int)$row['cliente_id'], $id]);
        if ((int)$stillOpen->fetchColumn() === 0) {
            $pdo->prepare("UPDATE subscripciones_credito
                           SET estado = 'activa'
                           WHERE cliente_id = ? AND estado = 'disputada'")
                ->execute([(int)$row['cliente_id']]);
        }
    } catch (Throwable $e) { error_log('escalations resume: ' . $e->getMessage()); }
}

adminLog('escalation_update', ['id' => $id, 'estado' => $newEstado, 'asignado_a' => $d['asignado_a'] ?? null]);

adminJsonOut(['ok' => true]);
