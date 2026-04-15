<?php
/**
 * POST — Toggle "venta al público" flag on a motorcycle
 * Body: { moto_id, venta_publico: 1|0 }
 * When enabled, this moto appears in the configurator inventory for its punto.
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$motoId = (int)($d['moto_id'] ?? 0);
$val    = (int)($d['venta_publico'] ?? 0);

if (!$motoId) adminJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();

// Ensure column exists (idempotent migration)
try {
    $cols = $pdo->query("SHOW COLUMNS FROM inventario_motos")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('venta_publico', $cols, true)) {
        $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN venta_publico TINYINT(1) NOT NULL DEFAULT 0 AFTER bloqueado_fecha");
    }
} catch (Throwable $e) { error_log('ensure venta_publico col: ' . $e->getMessage()); }

$stmt = $pdo->prepare("SELECT id, vin_display, vin, modelo, color FROM inventario_motos WHERE id=? AND activo=1");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) adminJsonOut(['error' => 'Moto no encontrada'], 404);

$pdo->prepare("UPDATE inventario_motos SET venta_publico=? WHERE id=?")->execute([$val, $motoId]);

adminLog($val ? 'moto_venta_publico_on' : 'moto_venta_publico_off', [
    'moto_id' => $motoId,
    'vin' => $moto['vin_display'] ?? $moto['vin'],
]);

adminJsonOut([
    'ok' => true,
    'venta_publico' => $val,
    'message' => $val ? 'Moto disponible para venta al público' : 'Moto retirada de venta al público',
]);
