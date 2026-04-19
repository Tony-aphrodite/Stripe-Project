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
    cliente_nombre, cliente_telefono, cliente_email, pedido_num, fecha_entrega_estimada
    FROM inventario_motos WHERE id=?");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) adminJsonOut(['error' => 'Moto no encontrada'], 404);

// Linked transacción — we need tpago to decide which signatures to show at
// delivery (pagaré only applies to credit-family orders).
try {
    $moto['tpago'] = null;
    if (!empty($moto['pedido_num'])) {
        $pedido = preg_replace('/^VK-/', '', $moto['pedido_num']);
        $ts = $pdo->prepare("SELECT tpago FROM transacciones WHERE pedido = ? ORDER BY id DESC LIMIT 1");
        $ts->execute([$pedido]);
        $moto['tpago'] = $ts->fetchColumn() ?: null;
    }
} catch (Throwable $e) { error_log('detalle.php tpago lookup: ' . $e->getMessage()); }

// Credit plan info — enrich moto with plan data so the signing screen can
// render the customer-friendly summary card (modelo / pago semanal / plazo /
// primer pago) without needing extra round trips. Match the subscription by
// client telefono / email since inventario_motos has no FK to subscripciones.
try {
    $sub = null;
    if (!empty($moto['cliente_telefono']) || !empty($moto['cliente_email'])) {
        $q = "SELECT id, modelo, color, monto_semanal, plazo_semanas, plazo_meses,
                     fecha_inicio, fecha_entrega, nombre
              FROM subscripciones_credito
              WHERE (telefono = ? OR email = ?)
              ORDER BY id DESC LIMIT 1";
        $ss = $pdo->prepare($q);
        $ss->execute([$moto['cliente_telefono'] ?? '', $moto['cliente_email'] ?? '']);
        $sub = $ss->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $moto['plan'] = $sub ? [
        'pago_semanal'  => (float)($sub['monto_semanal'] ?? 0),
        'plazo_semanas' => (int)($sub['plazo_semanas'] ?? 0),
        'plazo_meses'   => (int)($sub['plazo_meses'] ?? 0),
        'fecha_entrega' => $sub['fecha_entrega'] ?? ($moto['fecha_entrega_estimada'] ?? null),
        'nombre'        => $sub['nombre'] ?? $moto['cliente_nombre'],
    ] : null;
} catch (Throwable $e) {
    error_log('detalle.php plan lookup: ' . $e->getMessage());
    $moto['plan'] = null;
}

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
