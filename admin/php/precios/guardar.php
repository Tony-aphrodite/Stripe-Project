<?php
/**
 * POST — Create or update pricing conditions for a model
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin']);

$d = adminJsonIn();
$modeloId = (int)($d['modelo_id'] ?? 0);
if (!$modeloId) adminJsonOut(['error' => 'modelo_id requerido'], 400);

$pdo = getDB();

$fields = [
    'modelo_id'           => $modeloId,
    'enganche_min'        => (float)($d['enganche_min'] ?? 0),
    'enganche_max'        => (float)($d['enganche_max'] ?? 0),
    'pago_semanal'        => (float)($d['pago_semanal'] ?? 0),
    'tasa_interna'        => (float)($d['tasa_interna'] ?? 0),
    'plazo_semanas'       => (int)($d['plazo_semanas'] ?? 52),
    'msi_opciones'        => json_encode($d['msi_opciones'] ?? [3,6,9,12]),
    'promocion_nombre'    => trim($d['promocion_nombre'] ?? ''),
    'promocion_activa'    => (int)($d['promocion_activa'] ?? 0),
    'promocion_descuento' => (float)($d['promocion_descuento'] ?? 0),
];

// Upsert
try {
    $cols = implode(', ', array_keys($fields));
    $placeholders = implode(', ', array_fill(0, count($fields), '?'));
    $updates = implode(', ', array_map(function($k){ return "$k=VALUES($k)"; }, array_keys($fields)));

    $pdo->prepare("INSERT INTO precios_condiciones ({$cols}) VALUES ({$placeholders})
        ON DUPLICATE KEY UPDATE {$updates}")
        ->execute(array_values($fields));

    adminLog('precio_actualizado', ['modelo_id' => $modeloId]);
    adminJsonOut(['ok' => true, 'modelo_id' => $modeloId]);
} catch (Throwable $e) {
    adminJsonOut(['error' => 'Error: ' . $e->getMessage()], 500);
}
