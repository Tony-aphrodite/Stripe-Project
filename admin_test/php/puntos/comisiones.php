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

// Ensure comision_venta_monto column exists (customer template uses fixed MXN).
try {
    $pcCols = array_column($pdo->query("SHOW COLUMNS FROM punto_comisiones")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('comision_venta_monto', $pcCols, true)) {
        $pdo->exec("ALTER TABLE punto_comisiones ADD COLUMN comision_venta_monto DECIMAL(10,2) NULL");
    }
} catch (Throwable $e) { error_log('comisiones ensure col: ' . $e->getMessage()); }

// Ensure all required models exist (auto-insert missing ones).
// Customer Puntos template defines 6 sale-commission columns — all must exist.
// Uses a normalized-key comparison so `M03`, `m03`, `MC10 Streetx`, `MC10Streetx`
// etc. all resolve to the same row (prevents duplicates across imports).
$requiredModels = ['M03', 'M05', 'Pesgo plus', 'mino B', 'Ukko S+', 'MC10 Streetx'];
function _modNormKey(string $s): string {
    $n = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    return preg_replace('/[^a-z0-9]/', '', strtolower($n));
}
try {
    $existing = [];
    $q = $pdo->query("SELECT id, nombre FROM modelos");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $existing[_modNormKey($m['nombre'])] = true;
    }
    $insModel = $pdo->prepare("INSERT INTO modelos (nombre, activo) VALUES (?, 1)");
    foreach ($requiredModels as $mName) {
        if (!isset($existing[_modNormKey($mName)])) {
            try { $insModel->execute([$mName]); } catch (Throwable $e) {}
        }
    }
} catch (Throwable $e) { error_log('comisiones required models: ' . $e->getMessage()); }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $puntoId = (int)($_GET['punto_id'] ?? 0);
    if (!$puntoId) adminJsonOut(['ok' => false, 'error' => 'punto_id requerido'], 400);

    $rows = $pdo->prepare("
        SELECT pc.modelo_id, m.nombre AS modelo_nombre,
               pc.comision_venta_pct, pc.comision_venta_monto, pc.comision_entrega_pct
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
    INSERT INTO punto_comisiones (punto_id, modelo_id, comision_venta_pct, comision_venta_monto, comision_entrega_pct)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        comision_venta_pct   = VALUES(comision_venta_pct),
        comision_venta_monto = VALUES(comision_venta_monto),
        comision_entrega_pct = VALUES(comision_entrega_pct)
");

foreach ($comisiones as $c) {
    $modeloId   = (int)($c['modelo_id'] ?? 0);
    $ventaPct   = floatval($c['comision_venta_pct']   ?? 0);
    $ventaMonto = isset($c['comision_venta_monto']) ? floatval($c['comision_venta_monto']) : 0;
    $entregaPct = floatval($c['comision_entrega_pct'] ?? 0);
    if (!$modeloId) continue;
    $stmt->execute([$puntoId, $modeloId, $ventaPct, $ventaMonto ?: null, $entregaPct]);
}

adminLog('comisiones_actualizar', ['punto_id' => $puntoId, 'count' => count($comisiones)]);
adminJsonOut(['ok' => true]);
