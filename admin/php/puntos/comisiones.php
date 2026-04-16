<?php
/**
 * GET/POST — Manage commission percentages per punto per modelo
 *
 * GET  ?punto_id=3        → list commissions for a punto
 * POST {punto_id, comisiones: [{modelo_id, comision_venta_pct, comision_entrega_pct}, ...]}
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();

// Ensure all required models exist (auto-insert missing ones)
$requiredModels = ['m03', 'Pesgo plus', 'mino B', 'MC10 Streetx'];
$chkModel = $pdo->prepare("SELECT id FROM modelos WHERE nombre = ? LIMIT 1");
$insModel = $pdo->prepare("INSERT INTO modelos (nombre, activo) VALUES (?, 1)");
foreach ($requiredModels as $mName) {
    $chkModel->execute([$mName]);
    if (!$chkModel->fetch()) {
        try { $insModel->execute([$mName]); } catch (Throwable $e) { /* ignore */ }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $puntoId = (int)($_GET['punto_id'] ?? 0);
    if (!$puntoId) adminJsonOut(['ok' => false, 'error' => 'punto_id requerido'], 400);

    $rows = $pdo->prepare("
        SELECT pc.modelo_id, m.nombre AS modelo_nombre,
               pc.comision_venta_pct, pc.comision_entrega_pct
        FROM punto_comisiones pc
        JOIN modelos m ON m.id = pc.modelo_id
        WHERE pc.punto_id = ?
        ORDER BY m.nombre
    ");
    $rows->execute([$puntoId]);

    // Also get all active modelos so the UI can show empty rows
    $modelos = $pdo->query("SELECT id, nombre, precio_contado FROM modelos WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

    adminJsonOut([
        'ok' => true,
        'comisiones' => $rows->fetchAll(PDO::FETCH_ASSOC),
        'modelos' => $modelos
    ]);
}

// POST — save commissions for a punto
$d = adminJsonIn();
$puntoId = (int)($d['punto_id'] ?? 0);
$comisiones = $d['comisiones'] ?? [];

if (!$puntoId) adminJsonOut(['ok' => false, 'error' => 'punto_id requerido'], 400);

$stmt = $pdo->prepare("
    INSERT INTO punto_comisiones (punto_id, modelo_id, comision_venta_pct, comision_entrega_pct)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        comision_venta_pct = VALUES(comision_venta_pct),
        comision_entrega_pct = VALUES(comision_entrega_pct)
");

foreach ($comisiones as $c) {
    $modeloId = (int)($c['modelo_id'] ?? 0);
    $ventaPct = floatval($c['comision_venta_pct'] ?? 0);
    $entregaPct = floatval($c['comision_entrega_pct'] ?? 0);
    if (!$modeloId) continue;
    $stmt->execute([$puntoId, $modeloId, $ventaPct, $entregaPct]);
}

adminLog('comisiones_actualizar', ['punto_id' => $puntoId, 'count' => count($comisiones)]);
adminJsonOut(['ok' => true]);
