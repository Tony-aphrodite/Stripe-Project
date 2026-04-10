<?php
/**
 * POST — Save digital signature for delivery checklist
 * Body: { moto_id, firma_data (base64 PNG from canvas) }
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$motoId   = (int)($d['moto_id'] ?? 0);
$firmaB64 = $d['firma_data'] ?? '';

if (!$motoId || !$firmaB64) adminJsonOut(['error' => 'moto_id y firma_data requeridos'], 400);

// Validate base64 image
if (strpos($firmaB64, 'data:image/png;base64,') !== 0) {
    adminJsonOut(['error' => 'Formato de firma inválido'], 400);
}

$pdo = getDB();

$stmt = $pdo->prepare("SELECT id, completado FROM checklist_entrega_v2 WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$stmt->execute([$motoId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) adminJsonOut(['error' => 'Checklist de entrega no encontrado'], 404);
if ($row['completado']) adminJsonOut(['error' => 'Checklist ya completado'], 403);

// Save base64 data directly (small PNG from canvas)
$pdo->prepare("UPDATE checklist_entrega_v2 SET firma_data=?, firma_digital=1 WHERE id=?")
    ->execute([$firmaB64, $row['id']]);

adminLog('checklist_firma_guardada', ['moto_id' => $motoId]);
adminJsonOut(['ok' => true]);
