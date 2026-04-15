<?php
/**
 * POST — Manually mark a payment cycle as paid
 * Body: { "ciclo_id": 123, "nota": "Pago en efectivo" }
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin']);

$d = adminJsonIn();
$cicloId = (int)($d['ciclo_id'] ?? 0);
$nota    = trim($d['nota'] ?? '');
if (!$cicloId) adminJsonOut(['error' => 'ciclo_id requerido'], 400);

$pdo = getDB();

$stmt = $pdo->prepare("SELECT * FROM ciclos_pago WHERE id=?");
$stmt->execute([$cicloId]);
$ciclo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ciclo) adminJsonOut(['error' => 'Ciclo no encontrado'], 404);
if (in_array($ciclo['estado'], ['paid_auto','paid_manual'])) {
    adminJsonOut(['error' => 'Este ciclo ya fue pagado'], 400);
}

$pdo->prepare("UPDATE ciclos_pago SET estado='paid_manual', fecha_pago=NOW() WHERE id=?")
    ->execute([$cicloId]);

adminLog('marcar_pagado_manual', [
    'ciclo_id'       => $cicloId,
    'monto'          => $ciclo['monto'],
    'subscripcion_id'=> $ciclo['subscripcion_id'],
    'nota'           => $nota,
    'admin_id'       => $uid,
]);

adminJsonOut(['ok' => true, 'ciclo_id' => $cicloId]);
