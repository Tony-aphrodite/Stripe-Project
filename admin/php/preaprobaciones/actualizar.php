<?php
/**
 * POST — Update credit-application follow-up + decision fields.
 * Body: { id, seguimiento?, notas_admin?, status? }
 *
 * Accepted seguimiento (extended 2026-05-04 for the manual-review screen
 * redesign — old values still valid, new values track the four explicit
 * decision buttons in the modal):
 *   - nuevo, contactado, vendido, descartado, archivado, enviado_a_ventas
 *     (legacy seguimiento states, still in use by the listing filter)
 *   - aprobado          → admin clicked "Aprobar Plazos"
 *   - ofrecer_contado   → admin clicked "$ Ofrecer Contado"
 *   - ofrecer_msi       → admin clicked "9 MSI Sin Intereses"
 *   - rechazado         → admin clicked "✗ Rechazar"
 *
 * Accepted status (optional — only set when admin explicitly rejects, so
 * the listing filter and KPIs reflect the override):
 *   PREAPROBADO | CONDICIONAL | NO_VIABLE
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis','operador']);

$in    = adminJsonIn();
$id    = (int)($in['id'] ?? 0);
$seg   = trim((string)($in['seguimiento'] ?? ''));
$nota  = trim((string)($in['notas_admin'] ?? ''));
$status= trim((string)($in['status'] ?? ''));

if ($id <= 0) adminJsonOut(['error' => 'ID inválido'], 400);

$validSeg = [
    'nuevo','contactado','vendido','descartado','archivado','enviado_a_ventas',
    'truora_enviado','aprobado','ofrecer_contado','ofrecer_msi','rechazado',
];
if ($seg !== '' && !in_array($seg, $validSeg, true)) {
    adminJsonOut(['error' => 'seguimiento inválido. Valores: ' . implode(',', $validSeg)], 400);
}

$validStatus = ['PREAPROBADO', 'CONDICIONAL', 'NO_VIABLE'];
if ($status !== '' && !in_array($status, $validStatus, true)) {
    adminJsonOut(['error' => 'status inválido. Valores: ' . implode(',', $validStatus)], 400);
}

try {
    $pdo = getDB();
    // Build the UPDATE dynamically so we only touch the columns the
    // caller actually asked us to change. notas_admin is always written
    // (the manual-review buttons append a timestamped audit line).
    $sets   = ['notas_admin = ?'];
    $params = [$nota];
    if ($seg !== '')    { $sets[] = 'seguimiento = ?'; $params[] = $seg; }
    if ($status !== '') { $sets[] = 'status = ?';      $params[] = $status; }
    $params[] = $id;
    $pdo->prepare("UPDATE preaprobaciones SET " . implode(', ', $sets) . " WHERE id = ?")
        ->execute($params);
    adminJsonOut(['ok' => true]);
} catch (Throwable $e) {
    adminJsonOut(['error' => $e->getMessage()], 500);
}
