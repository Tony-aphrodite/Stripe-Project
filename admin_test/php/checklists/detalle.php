<?php
/**
 * GET ?moto_id= — Get full checklist data for a moto
 * Returns all 3 checklists (latest record each)
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$motoId = (int)($_GET['moto_id'] ?? 0);
if (!$motoId) adminJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();

// Moto info
$stmt = $pdo->prepare("SELECT id, vin, vin_display, modelo, color, anio_modelo, estado,
    cliente_nombre, pedido_num FROM inventario_motos WHERE id=?");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) adminJsonOut(['error' => 'Moto no encontrada'], 404);

// Checklist origen
$co = $pdo->prepare("SELECT * FROM checklist_origen WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$co->execute([$motoId]);

// Checklist ensamble
$ce = $pdo->prepare("SELECT * FROM checklist_ensamble WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$ce->execute([$motoId]);

// Checklist entrega
$cv = $pdo->prepare("SELECT * FROM checklist_entrega_v2 WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$cv->execute([$motoId]);

adminJsonOut([
    'ok'    => true,
    'moto'  => $moto,
    'origen'   => $co->fetch(PDO::FETCH_ASSOC) ?: null,
    'ensamble' => $ce->fetch(PDO::FETCH_ASSOC) ?: null,
    'entrega'  => $cv->fetch(PDO::FETCH_ASSOC) ?: null,
]);
