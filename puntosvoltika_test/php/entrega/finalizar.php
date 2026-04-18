<?php
/**
 * POST — Finalizar entrega: verifica ACTA firmada por cliente y marca moto entregada
 * Body: { moto_id }
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$d = puntoJsonIn();
$motoId = (int)($d['moto_id'] ?? 0);
if (!$motoId) puntoJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();

// Verify ACTA signed
$stmt = $pdo->prepare("SELECT cliente_acta_firmada FROM inventario_motos WHERE id=?");
$stmt->execute([$motoId]);
$signed = (int)$stmt->fetchColumn();

if (!$signed) {
    puntoJsonOut(['error' => 'El cliente aún no ha firmado el ACTA DE ENTREGA'], 400);
}

// Verify OTP was confirmed
$e = $pdo->prepare("SELECT otp_verified FROM entregas WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$e->execute([$motoId]);
if (!(int)$e->fetchColumn()) {
    puntoJsonOut(['error' => 'OTP del cliente no verificado'], 400);
}

// Update moto final status
$pdo->prepare("UPDATE inventario_motos SET estado='entregada', fecha_estado=NOW() WHERE id=?")
    ->execute([$motoId]);

// Start weekly payment countdown for linked credit subscriptions.
// Per client feedback: "first payment starts counting AFTER the motorcycle
// is delivered, not from purchase date". Set fecha_inicio now so cron
// generar-ciclos.php creates ciclos from today forward.
try {
    $subCols = $pdo->query("SHOW COLUMNS FROM subscripciones_credito")->fetchAll(PDO::FETCH_COLUMN);
    $sets = ['fecha_inicio = CURDATE()'];
    if (in_array('fecha_entrega', $subCols, true)) $sets[] = 'fecha_entrega = CURDATE()';

    $motoRowX = $pdo->prepare("SELECT cliente_id, cliente_telefono, cliente_email FROM inventario_motos WHERE id=?");
    $motoRowX->execute([$motoId]);
    $mX = $motoRowX->fetch(PDO::FETCH_ASSOC) ?: [];

    // Match by cliente_id (primary) or telefono / email (fallback for older records)
    if (!empty($mX['cliente_id'])) {
        $pdo->prepare("UPDATE subscripciones_credito SET " . implode(', ', $sets) . "
            WHERE cliente_id = ? AND (fecha_inicio IS NULL OR fecha_inicio = '0000-00-00')")
            ->execute([$mX['cliente_id']]);
    } elseif (!empty($mX['cliente_telefono'])) {
        $pdo->prepare("UPDATE subscripciones_credito SET " . implode(', ', $sets) . "
            WHERE telefono = ? AND (fecha_inicio IS NULL OR fecha_inicio = '0000-00-00')")
            ->execute([$mX['cliente_telefono']]);
    }
} catch (Throwable $e) {
    error_log('finalizar fecha_inicio update: ' . $e->getMessage());
}

// Log venta
$motoRow = $pdo->prepare("SELECT * FROM inventario_motos WHERE id=?");
$motoRow->execute([$motoId]);
$m = $motoRow->fetch(PDO::FETCH_ASSOC);

$pdo->prepare("INSERT INTO ventas_log (moto_id, tipo, dealer_id, cliente_nombre, cliente_email, cliente_telefono,
    pedido_num, modelo, color, vin, monto) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([
        $motoId, 'entrega_voltika', $ctx['user_id'],
        $m['cliente_nombre'], $m['cliente_email'], $m['cliente_telefono'],
        $m['pedido_num'], $m['modelo'], $m['color'], $m['vin'], $m['precio_venta']
    ]);

puntoLog('entrega_finalizada', ['moto_id' => $motoId]);

// Notify cliente — "¡Bienvenido a la familia Voltika!"
require_once __DIR__ . '/../../../configurador_prueba_test/php/voltika-notify.php';
try {
    $proximo = '';
    if (!empty($m['cliente_id'])) {
        $st = $pdo->prepare("SELECT fecha_vencimiento FROM ciclos_pago
            WHERE cliente_id = ? AND estado IN ('pending','overdue')
            ORDER BY fecha_vencimiento ASC LIMIT 1");
        $st->execute([$m['cliente_id']]);
        $proximo = (string)($st->fetchColumn() ?: '');
    }
    voltikaNotify('entrega_completada', [
        'cliente_id'   => $m['cliente_id'] ?? null,
        'nombre'       => $m['cliente_nombre'] ?? '',
        'modelo'       => $m['modelo'] ?? '',
        'telefono'     => $m['cliente_telefono'] ?? '',
        'email'        => $m['cliente_email'] ?? '',
        'proximo_pago' => $proximo,
    ]);
} catch (Throwable $e) { error_log('notify entrega_completada: ' . $e->getMessage()); }

puntoJsonOut(['ok' => true, 'mensaje' => '¡Entrega completada!']);
