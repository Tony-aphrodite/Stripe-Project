<?php
/**
 * GET — Real-time KPIs for dashboard top bar
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$pdo = getDB();
$today = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));

// Sales
$ventasHoy = $pdo->prepare("SELECT COUNT(*) FROM transacciones WHERE DATE(freg)=?");
$ventasHoy->execute([$today]);

$ventasSemana = $pdo->prepare("SELECT COUNT(*) FROM transacciones WHERE DATE(freg)>=?");
$ventasSemana->execute([$weekStart]);

// Collections
$cobradoHoy = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM transacciones WHERE DATE(freg)=?");
$cobradoHoy->execute([$today]);

// Portfolio
$carteraOk = $pdo->query("SELECT COUNT(*) FROM subscripciones_credito WHERE estado='activa'")->fetchColumn();
$carteraVencida = $pdo->query("SELECT COUNT(DISTINCT cliente_id) FROM ciclos_pago WHERE estado='overdue'")->fetchColumn();

// Inventory
$inv = $pdo->query("SELECT
    SUM(estado IN ('recibida','lista_para_entrega')) as disponible,
    SUM(cliente_nombre IS NOT NULL AND cliente_nombre<>'' AND estado NOT IN ('entregada')) as apartadas,
    SUM(estado='por_llegar') as en_transito,
    SUM(estado='por_validar_entrega') as entregas_pendientes
    FROM inventario_motos")->fetch(PDO::FETCH_ASSOC);

// Expected cash flow (pending cycles this week)
$flujo = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM ciclos_pago
    WHERE estado='pending' AND fecha_vencimiento BETWEEN ? AND DATE_ADD(?, INTERVAL 6 DAY)");
$flujo->execute([$today, $today]);

adminJsonOut([
    'ventas_hoy' => (int)$ventasHoy->fetchColumn(),
    'ventas_semana' => (int)$ventasSemana->fetchColumn(),
    'cobrado_hoy' => (float)$cobradoHoy->fetchColumn(),
    'flujo_esperado' => (float)$flujo->fetchColumn(),
    'cartera_corriente' => (int)$carteraOk,
    'cartera_vencida' => (int)$carteraVencida,
    'inventario_disponible' => (int)($inv['disponible'] ?? 0),
    'unidades_apartadas' => (int)($inv['apartadas'] ?? 0),
    'en_transito' => (int)($inv['en_transito'] ?? 0),
    'entregas_pendientes' => (int)($inv['entregas_pendientes'] ?? 0),
]);
