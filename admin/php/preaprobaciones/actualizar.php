<?php
/**
 * POST — Update credit-application follow-up + decision fields.
 * Body: { id, seguimiento?, notas_admin?, status?,
 *         enganche_pct_aprobado?, plazo_meses_aprobado? }
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
 *
 * Customer brief 2026-05-09 (Óscar's report): when admin clicks "Aprobar
 * Plazos" the override slider values now ride along as
 * enganche_pct_aprobado (int 25-80) and plazo_meses_aprobado (int 12/18/
 * 24/36) so we can persist what the admin actually approved. Previously
 * the audit note shipped with "?" because the values were never sent.
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis','operador']);

$in    = adminJsonIn();
$id    = (int)($in['id'] ?? 0);
$seg   = trim((string)($in['seguimiento'] ?? ''));
$nota  = trim((string)($in['notas_admin'] ?? ''));
$status= trim((string)($in['status'] ?? ''));
// Optional override values from the manual-decision UI. We accept null/
// missing (older clients still work — they just won't update those
// columns) and validate ranges so a bad post can't smuggle bogus terms
// through to the credit-quote engine downstream.
$engAprob   = array_key_exists('enganche_pct_aprobado', $in) && $in['enganche_pct_aprobado'] !== null
              ? (int)$in['enganche_pct_aprobado'] : null;
$plazoAprob = array_key_exists('plazo_meses_aprobado', $in) && $in['plazo_meses_aprobado'] !== null
              ? (int)$in['plazo_meses_aprobado']  : null;
if ($engAprob   !== null && ($engAprob   < 0 || $engAprob   > 100)) $engAprob   = null;
if ($plazoAprob !== null && ($plazoAprob < 0 || $plazoAprob > 120)) $plazoAprob = null;

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

    // Lazy-create the override columns on first use. Idempotent — a
    // SHOW COLUMNS check guards each ADD so we don't error on second
    // call. This mirrors the pattern other admin endpoints use for
    // schema evolution against legacy DB instances.
    if ($engAprob !== null || $plazoAprob !== null) {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM preaprobaciones")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('enganche_pct_aprobado', $cols, true)) {
                $pdo->exec("ALTER TABLE preaprobaciones ADD COLUMN enganche_pct_aprobado TINYINT NULL");
            }
            if (!in_array('plazo_meses_aprobado', $cols, true)) {
                $pdo->exec("ALTER TABLE preaprobaciones ADD COLUMN plazo_meses_aprobado TINYINT NULL");
            }
        } catch (Throwable $e) { error_log('actualizar override columns: ' . $e->getMessage()); }
    }

    // Build the UPDATE dynamically so we only touch the columns the
    // caller actually asked us to change. notas_admin is always written
    // (the manual-review buttons append a timestamped audit line).
    $sets   = ['notas_admin = ?'];
    $params = [$nota];
    if ($seg !== '')         { $sets[] = 'seguimiento = ?';           $params[] = $seg; }
    if ($status !== '')      { $sets[] = 'status = ?';                $params[] = $status; }
    if ($engAprob !== null)  { $sets[] = 'enganche_pct_aprobado = ?'; $params[] = $engAprob; }
    if ($plazoAprob !== null){ $sets[] = 'plazo_meses_aprobado = ?';  $params[] = $plazoAprob; }
    $params[] = $id;
    $pdo->prepare("UPDATE preaprobaciones SET " . implode(', ', $sets) . " WHERE id = ?")
        ->execute($params);
    adminJsonOut(['ok' => true]);
} catch (Throwable $e) {
    adminJsonOut(['error' => $e->getMessage()], 500);
}
