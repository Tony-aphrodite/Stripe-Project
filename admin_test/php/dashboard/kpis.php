<?php
/**
 * GET — Real-time KPIs for dashboard top bar
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$pdo = getDB();
$today = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));

// Helper: run query safely, return default on error (missing table/column)
$safeScalar = function($sql, $params = [], $default = 0) use ($pdo) {
    try {
        if ($params) { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchColumn(); }
        return $pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) { return $default; }
};
$safeRow = function($sql, $default = []) use ($pdo) {
    try { return $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: $default; }
    catch (Throwable $e) { return $default; }
};

// Detect actual column name for subscripciones_credito status field
$subStatusCol = 'status';
try {
    $cols = $pdo->query("SHOW COLUMNS FROM subscripciones_credito")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('estado', $cols)) $subStatusCol = 'estado';
} catch (Throwable $e) {}

// Sales
$ventasHoy    = (int)$safeScalar("SELECT COUNT(*) FROM transacciones WHERE DATE(freg)=?", [$today]);
$ventasSemana = (int)$safeScalar("SELECT COUNT(*) FROM transacciones WHERE DATE(freg)>=?", [$weekStart]);

// Collections
$cobradoHoy = (float)$safeScalar("SELECT COALESCE(SUM(total),0) FROM transacciones WHERE DATE(freg)=?", [$today]);

// Portfolio
$carteraOk      = (int)$safeScalar("SELECT COUNT(*) FROM subscripciones_credito WHERE {$subStatusCol} IN ('activa','active')");
$carteraVencida = (int)$safeScalar("SELECT COUNT(DISTINCT cliente_id) FROM ciclos_pago WHERE estado='overdue'");

// Inventory
$inv = $safeRow("SELECT
    SUM(estado IN ('recibida','lista_para_entrega')) as disponible,
    SUM(cliente_nombre IS NOT NULL AND cliente_nombre<>'' AND estado NOT IN ('entregada','retenida','por_llegar')) as apartadas,
    SUM(estado = 'por_llegar') as en_transito,
    SUM(estado IN ('lista_para_entrega','por_validar_entrega')) as pendientes_entrega_clientes
    FROM inventario_motos WHERE activo = 1");

// Expected cash flow (pending cycles this week)
$flujo = (float)$safeScalar("SELECT COALESCE(SUM(monto),0) FROM ciclos_pago
    WHERE estado='pending' AND fecha_vencimiento BETWEEN ? AND DATE_ADD(?, INTERVAL 6 DAY)",
    [$today, $today]);

// Servicios adicionales — uses tracking columns if present, falls back to raw flag count
$hasTracking = (int)$safeScalar("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transacciones' AND COLUMN_NAME='placas_estado'") > 0;

if ($hasTracking) {
    $placasPendientes = (int)$safeScalar("SELECT COUNT(*) FROM transacciones
        WHERE asesoria_placas=1 AND COALESCE(placas_estado,'pendiente') IN ('pendiente','en_proceso')");
    $seguroPendientes = (int)$safeScalar("SELECT COUNT(*) FROM transacciones
        WHERE seguro_qualitas=1 AND COALESCE(seguro_estado,'pendiente') IN ('pendiente','cotizado')");
} else {
    $placasPendientes = (int)$safeScalar("SELECT COUNT(*) FROM transacciones WHERE asesoria_placas=1");
    $seguroPendientes = (int)$safeScalar("SELECT COUNT(*) FROM transacciones WHERE seguro_qualitas=1");
}

adminJsonOut([
    'app_env' => defined('APP_ENV') ? APP_ENV : 'test',
    'ventas_hoy' => $ventasHoy,
    'ventas_semana' => $ventasSemana,
    'cobrado_hoy' => $cobradoHoy,
    'flujo_esperado' => $flujo,
    'cartera_corriente' => $carteraOk,
    'cartera_vencida' => $carteraVencida,
    'inventario_disponible' => (int)($inv['disponible'] ?? 0),
    'unidades_apartadas' => (int)($inv['apartadas'] ?? 0),
    'en_transito' => (int)($inv['en_transito'] ?? 0),
    'pendientes_entrega_clientes' => (int)($inv['pendientes_entrega_clientes'] ?? 0),
    'placas_pendientes' => $placasPendientes,
    'seguro_pendientes' => $seguroPendientes,
]);
