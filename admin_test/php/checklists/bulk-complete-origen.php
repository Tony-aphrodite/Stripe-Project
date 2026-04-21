<?php
/**
 * POST — Bulk-mark multiple motos' checklist_origen as completed.
 * Used by admins/CEDIS to quickly process many recently-imported bikes
 * after a physical inspection batch.
 *
 * Body: { moto_ids: [12, 14, 18, ...], notas?: "Inspección lote A" }
 * Returns: { ok, completados: N, ya_completos: N, errores: N }
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin', 'cedis']);

$in   = adminJsonIn();
$ids  = $in['moto_ids'] ?? [];
$nota = trim((string)($in['notas'] ?? ''));

if (!is_array($ids) || empty($ids)) {
    adminJsonOut(['error' => 'Debes seleccionar al menos una moto'], 400);
}
$ids = array_values(array_unique(array_map('intval', $ids)));
$ids = array_filter($ids, function($v){ return $v > 0; });
if (empty($ids)) adminJsonOut(['error' => 'IDs inválidos'], 400);

$pdo = getDB();

// Check each moto exists + fetch metadata for the checklist row
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT id, vin, modelo, color FROM inventario_motos WHERE id IN ($placeholders)");
$stmt->execute($ids);
$motos = $stmt->fetchAll(PDO::FETCH_ASSOC);
$found = array_column($motos, null, 'id');

$completados = 0;
$yaCompletos = 0;
$errores     = 0;
$detalle     = [];

$insStmt = $pdo->prepare("INSERT INTO checklist_origen
    (moto_id, dealer_id, vin, modelo, color, completado, bloqueado, hash_registro, notas)
    VALUES (?, ?, ?, ?, ?, 1, 1, ?, ?)");
$updStmt = $pdo->prepare("UPDATE checklist_origen
    SET completado = 1, bloqueado = 1, fcompletado = NOW()
    WHERE moto_id = ? AND completado = 0");
$chkStmt = $pdo->prepare("SELECT id, completado FROM checklist_origen WHERE moto_id = ? LIMIT 1");

foreach ($ids as $motoId) {
    $m = $found[$motoId] ?? null;
    if (!$m) { $errores++; $detalle[] = "Moto $motoId no existe"; continue; }

    try {
        $chkStmt->execute([$motoId]);
        $existing = $chkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing && (int)$existing['completado'] === 1) {
            $yaCompletos++;
            continue;
        }
        if ($existing) {
            $updStmt->execute([$motoId]);
            $completados++;
        } else {
            $hash = hash('sha256', "bulk-$motoId-" . date('c'));
            $insStmt->execute([$motoId, $uid, $m['vin'], $m['modelo'], $m['color'], $hash, $nota ?: 'Inspección masiva']);
            $completados++;
        }
    } catch (Throwable $e) {
        $errores++;
        $detalle[] = "Moto $motoId: " . $e->getMessage();
    }
}

adminLog('checklist_origen_bulk', [
    'usuario_id' => $uid,
    'completados' => $completados,
    'ya_completos' => $yaCompletos,
    'errores' => $errores,
    'total_seleccionados' => count($ids),
]);

adminJsonOut([
    'ok'           => true,
    'completados'  => $completados,
    'ya_completos' => $yaCompletos,
    'errores'      => $errores,
    'detalle'      => array_slice($detalle, 0, 20),
]);
