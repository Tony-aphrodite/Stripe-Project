<?php
/**
 * GET — Fetch current ensamble checklist state for a moto owned by the
 * punto operator's assigned punto.
 *
 *   ?moto_id=123
 */
require_once __DIR__ . '/../bootstrap.php';
$auth = puntoRequireAuth();

$motoId = (int)($_GET['moto_id'] ?? 0);
if (!$motoId) puntoJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();

// Verify the moto is currently at this operator's punto — dealers can only
// view their own checklists.
$motoStmt = $pdo->prepare("SELECT id, vin, vin_display, modelo, color, estado, punto_voltika_id
                            FROM inventario_motos WHERE id=?");
$motoStmt->execute([$motoId]);
$moto = $motoStmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) puntoJsonOut(['error' => 'Moto no encontrada'], 404);

if ((int)$moto['punto_voltika_id'] !== (int)$auth['punto_id']) {
    puntoJsonOut(['error' => 'Moto no pertenece a este punto'], 403);
}

// Fetch current ensamble checklist (single record per moto, latest).
$chkStmt = $pdo->prepare("SELECT * FROM checklist_ensamble WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$chkStmt->execute([$motoId]);
$checklist = $chkStmt->fetch(PDO::FETCH_ASSOC) ?: null;

puntoJsonOut([
    'ok'        => true,
    'moto'      => $moto,
    'checklist' => $checklist,
]);
