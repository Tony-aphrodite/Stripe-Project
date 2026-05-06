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

    // Customer brief 2026-05-06 (Carlos Ricardo Sanchez case): Truora
    // rejection is a hard prerequisite for credit approval. Block the
    // promotion paths (aprobado / enviado_a_ventas / status=PREAPROBADO)
    // when the linked Truora row is rejected. Admin can still rechazar /
    // ofrecer_contado / ofrecer_msi (those don't grant credit).
    $isApprovalAction = in_array($seg, ['aprobado', 'enviado_a_ventas'], true)
                     || $status === 'PREAPROBADO';
    if ($isApprovalAction) {
        try {
            $tStmt = $pdo->prepare(
                "SELECT vi.truora_status, vi.approved
                   FROM preaprobaciones p
                   LEFT JOIN verificaciones_identidad vi ON vi.id = (
                       SELECT vi2.id FROM verificaciones_identidad vi2
                        WHERE (vi2.telefono <> '' AND vi2.telefono = p.telefono)
                           OR (vi2.email    <> '' AND vi2.email    = p.email)
                        ORDER BY vi2.id DESC LIMIT 1
                   )
                  WHERE p.id = ? LIMIT 1"
            );
            $tStmt->execute([$id]);
            $tRow = $tStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $tStat = strtolower((string)($tRow['truora_status'] ?? ''));
            if (in_array($tStat, ['failure', 'rejected', 'denied'], true)) {
                adminJsonOut([
                    'ok' => false,
                    'error' => 'truora_rechazado',
                    'message' => 'No se puede aprobar el crédito: la verificación de identidad (Truora) está rechazada.',
                    'truora_status' => $tStat,
                ], 409);
            }
        } catch (Throwable $e) {
            error_log('actualizar truora gate: ' . $e->getMessage());
        }
    }

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
