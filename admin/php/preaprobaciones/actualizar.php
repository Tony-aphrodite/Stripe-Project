<?php
/**
 * POST — Update follow-up status / notes for a credit application
 * Body: { id, seguimiento, notas_admin }
 *   seguimiento: nuevo | contactado | vendido | descartado
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis','operador']);

$in = adminJsonIn();
$id   = (int)($in['id'] ?? 0);
$seg  = trim($in['seguimiento'] ?? '');
$nota = trim((string)($in['notas_admin'] ?? ''));

if ($id <= 0) adminJsonOut(['error' => 'ID inválido'], 400);
$validSeg = ['nuevo','contactado','vendido','descartado'];
if ($seg !== '' && !in_array($seg, $validSeg, true)) {
    adminJsonOut(['error' => 'seguimiento inválido. Valores: ' . implode(',', $validSeg)], 400);
}

try {
    $pdo = getDB();
    if ($seg !== '') {
        $pdo->prepare("UPDATE preaprobaciones SET seguimiento=?, notas_admin=? WHERE id=?")
            ->execute([$seg, $nota, $id]);
    } else {
        $pdo->prepare("UPDATE preaprobaciones SET notas_admin=? WHERE id=?")
            ->execute([$nota, $id]);
    }
    adminJsonOut(['ok' => true]);
} catch (Throwable $e) {
    adminJsonOut(['error' => $e->getMessage()], 500);
}
