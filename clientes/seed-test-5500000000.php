<?php
/**
 * One-shot seed — create a complete test data set tied to phone
 * 5500000000 so the customer can exercise every client-side panel
 * without going through real checkout / SMS / Truora.
 *
 * Customer brief 2026-05-06: "고객은 5500000000 번호를 사용해서
 * 테스트를 진행하는데" — they want to run all 31 feedback items
 * end-to-end via the customer-facing screens.
 *
 * What it creates (idempotent — re-run is safe):
 *   1. clientes row    — TEST cliente
 *   2. transaccion     — paid CONTADO order, ready for entrega
 *   3. subscripcion    — active credit subscription (mi-credito.html)
 *   4. preaprobacion   — PREAPROBADO record (Solicitudes flow)
 *   5. inventario_moto — assigned unit + delivery state
 *
 * URL: /clientes/seed-test-5500000000.php
 *      ?dry=1 to preview only (default safe mode)
 *      ?run=1 to actually insert
 *
 * ⚠ Test-only — delete in production once testing is complete.
 */
require_once __DIR__ . '/php/bootstrap.php';

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

header('Content-Type: text/html; charset=utf-8');

$TEST_TEL    = '5500000000';
$TEST_EMAIL  = 'diag-test@voltika.mx';
$TEST_NOMBRE = '[TEST] Voltika Diag';
$TEST_AP_PAT = 'Diag';
$TEST_AP_MAT = 'Test';
$run = !empty($_GET['run']);

$pdo = getDB();
$log = [];
$plan = [];

// ── Helper: column existence check (cached) ────────────────────────────
$colsCache = [];
function tableCols(PDO $pdo, string $table, array &$cache): array {
    if (isset($cache[$table])) return $cache[$table];
    try {
        $cache[$table] = array_flip($pdo->query("SHOW COLUMNS FROM $table")
                                       ->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { $cache[$table] = []; }
    return $cache[$table];
}

// ── 1. clientes row ──────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("SELECT id FROM clientes WHERE telefono = ? OR email = ? LIMIT 1");
    $stmt->execute([$TEST_TEL, $TEST_EMAIL]);
    $cid = (int)($stmt->fetchColumn() ?: 0);
    if ($cid) {
        $log[] = "clientes: existe (id={$cid})";
    } else {
        $plan[] = "clientes: insertar nueva fila";
        if ($run) {
            $pdo->prepare("INSERT INTO clientes (nombre, telefono, email) VALUES (?,?,?)")
               ->execute([$TEST_NOMBRE, $TEST_TEL, $TEST_EMAIL]);
            $cid = (int)$pdo->lastInsertId();
            $log[] = "clientes: creado (id={$cid})";
        }
    }
} catch (Throwable $e) { $log[] = "clientes ERROR: " . $e->getMessage(); $cid = 0; }

// ── 2. transaccion (CONTADO, paid, ready for entrega) ───────────────
try {
    $pedido = 'TEST-5500-CONTADO-1';
    $stmt = $pdo->prepare("SELECT id FROM transacciones WHERE pedido = ? LIMIT 1");
    $stmt->execute([$pedido]);
    $existingTxId = (int)($stmt->fetchColumn() ?: 0);
    if ($existingTxId) {
        $log[] = "transaccion {$pedido}: existe (id={$existingTxId})";
        $txId = $existingTxId;
    } else {
        $plan[] = "transaccion {$pedido}: CONTADO M03 negro \$9,975 pagada";
        if ($run) {
            $tCols = tableCols($pdo, 'transacciones', $colsCache);
            $f = ['pedido','nombre','email','telefono','modelo','color','total','tpago','pago_estado'];
            $v = [$pedido, $TEST_NOMBRE, $TEST_EMAIL, $TEST_TEL, 'M03', 'negro', 9975, 'contado', 'pagada'];
            if (isset($tCols['stripe_pi']))     { $f[]='stripe_pi';     $v[]='pi_TEST_5500_CONTADO_1'; }
            if (isset($tCols['ciudad']))        { $f[]='ciudad';        $v[]='Ciudad de México'; }
            if (isset($tCols['estado']))        { $f[]='estado';        $v[]='Distrito Federal'; }
            if (isset($tCols['cp']))            { $f[]='cp';            $v[]='11700'; }
            if (isset($tCols['punto_nombre']))  { $f[]='punto_nombre';  $v[]='Voltika Center'; }
            if (isset($tCols['pedido_corto']))  { $f[]='pedido_corto';  $v[]='VK-1826-TEST'; }
            if (isset($tCols['environment']))   { $f[]='environment';   $v[]=defined('APP_ENV') ? APP_ENV : 'test'; }
            if (isset($tCols['notas_admin']))   { $f[]='notas_admin';   $v[]='[TEST] Seed para 5500000000'; }
            $ph = implode(',', array_fill(0, count($f), '?'));
            $pdo->prepare("INSERT INTO transacciones (".implode(',', $f).") VALUES ($ph)")->execute($v);
            $txId = (int)$pdo->lastInsertId();
            $log[] = "transaccion {$pedido}: creada (id={$txId})";
        }
    }
} catch (Throwable $e) { $log[] = "transaccion ERROR: " . $e->getMessage(); $txId = 0; }

// ── 3. subscripcion_credito (active) ────────────────────────────────
try {
    $sub = $pdo->prepare("SELECT id FROM subscripciones_credito
                          WHERE telefono = ? OR email = ? LIMIT 1");
    $sub->execute([$TEST_TEL, $TEST_EMAIL]);
    $existingSubId = (int)($sub->fetchColumn() ?: 0);
    if ($existingSubId) {
        $log[] = "subscripcion: existe (id={$existingSubId})";
    } else {
        $plan[] = "subscripcion_credito: Pesgo Plus rojo, \$554/sem, 36 meses, activa";
        if ($run) {
            $sCols = tableCols($pdo, 'subscripciones_credito', $colsCache);
            $f = ['nombre','email','telefono'];
            $v = [$TEST_NOMBRE, $TEST_EMAIL, $TEST_TEL];
            if (isset($sCols['cliente_id']) && $cid)         { $f[]='cliente_id';      $v[]=$cid; }
            if (isset($sCols['modelo']))                     { $f[]='modelo';          $v[]='Pesgo plus'; }
            if (isset($sCols['color']))                      { $f[]='color';           $v[]='rojo'; }
            if (isset($sCols['monto_semanal']))              { $f[]='monto_semanal';   $v[]=554; }
            if (isset($sCols['precio_contado']))             { $f[]='precio_contado';  $v[]=48260; }
            if (isset($sCols['plazo_meses']))                { $f[]='plazo_meses';     $v[]=36; }
            if (isset($sCols['fecha_inicio']))               { $f[]='fecha_inicio';    $v[]=date('Y-m-d', strtotime('-15 days')); }
            if (isset($sCols['estado']))                     { $f[]='estado';          $v[]='activa'; }
            if (isset($sCols['status']))                     { $f[]='status';          $v[]='active'; }
            $ph = implode(',', array_fill(0, count($f), '?'));
            $pdo->prepare("INSERT INTO subscripciones_credito (".implode(',', $f).") VALUES ($ph)")
               ->execute($v);
            $log[] = "subscripcion: creada";
        }
    }
} catch (Throwable $e) { $log[] = "subscripcion ERROR: " . $e->getMessage(); }

// ── 2b. transaccion CRÉDITO (so the Documentos modal Crédito branch
//        has data to render the 7-row credit-specific document list) ─
// The CONTADO test row (#13) covers MSI/Contado modal cases, but
// nothing seeded so far has tpago='credito'. Without one, the Ventas
// Crédito filter shows 0 and the customer can't visually verify the
// new Crédito-specific Documentos rows (INE/PASSPORT, CURP+CDC,
// Capacidad, Resumen). Adding a paid CREDITO row keeps the modal
// reachable from a normal Ventas → Ver → Documentos click path.
try {
    $pcred = 'TEST-5500-CREDITO-2';
    $stmt = $pdo->prepare("SELECT id FROM transacciones WHERE pedido = ? LIMIT 1");
    $stmt->execute([$pcred]);
    $existingTxCredId = (int)($stmt->fetchColumn() ?: 0);
    if ($existingTxCredId) {
        $log[] = "transaccion {$pcred}: existe (id={$existingTxCredId})";
    } else {
        $plan[] = "transaccion {$pcred}: CREDITO Pesgo plus rojo \$48,260 enganche \$12,065 parcial";
        if ($run) {
            $tCols = tableCols($pdo, 'transacciones', $colsCache);
            $f = ['pedido','nombre','email','telefono','modelo','color','total','tpago','pago_estado'];
            // Credit orders carry pago_estado='parcial' (only enganche
            // captured so far) so the dashboard counts them in the
            // Crédito card without showing as fully paid.
            $v = [$pcred, $TEST_NOMBRE, $TEST_EMAIL, $TEST_TEL, 'Pesgo plus', 'rojo', 48260, 'credito', 'parcial'];
            if (isset($tCols['stripe_pi']))     { $f[]='stripe_pi';     $v[]='pi_TEST_5500_CREDITO_2'; }
            if (isset($tCols['ciudad']))        { $f[]='ciudad';        $v[]='Ciudad de México'; }
            if (isset($tCols['estado']))        { $f[]='estado';        $v[]='Distrito Federal'; }
            if (isset($tCols['cp']))            { $f[]='cp';            $v[]='11700'; }
            if (isset($tCols['punto_nombre']))  { $f[]='punto_nombre';  $v[]='Voltika Center'; }
            if (isset($tCols['pedido_corto']))  { $f[]='pedido_corto';  $v[]='VK-1826-CRTEST'; }
            if (isset($tCols['environment']))   { $f[]='environment';   $v[]=defined('APP_ENV') ? APP_ENV : 'test'; }
            if (isset($tCols['notas_admin']))   { $f[]='notas_admin';   $v[]='[TEST] Crédito seed para 5500000000'; }
            $ph = implode(',', array_fill(0, count($f), '?'));
            $pdo->prepare("INSERT INTO transacciones (".implode(',', $f).") VALUES ($ph)")->execute($v);
            $log[] = "transaccion {$pcred}: creada (id=" . $pdo->lastInsertId() . ")";
        }
    }
} catch (Throwable $e) { $log[] = "transaccion CREDITO ERROR: " . $e->getMessage(); }

// ── 3b. pagos_credito row (so mi-credito.html finds the credit) ─────
// cliente-credito.php searches pagos_credito.pedido_num — without a row
// here, mi-credito.html shows "No se encontró un crédito con ese
// número de pedido". We seed a complete amortization schedule (weekly
// rows in pagos_credito_historial) so the dashboard renders pagado vs
// restante and the next-payment card.
try {
    $pcPedido = 'TEST-5500-CREDITO-1';
    $stmt = $pdo->prepare("SELECT id FROM pagos_credito WHERE pedido_num = ? LIMIT 1");
    $stmt->execute([$pcPedido]);
    $existingPcId = (int)($stmt->fetchColumn() ?: 0);
    if ($existingPcId) {
        $log[] = "pagos_credito {$pcPedido}: existe (id={$existingPcId})";
    } else {
        $plan[] = "pagos_credito {$pcPedido}: Pesgo Plus, 36 semanas \$554/sem";
        if ($run) {
            // Tables may not exist on fresh installs — ensure them first.
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS pagos_credito (
                id INT AUTO_INCREMENT PRIMARY KEY,
                moto_id INT NULL, cliente_nombre VARCHAR(200), cliente_email VARCHAR(200),
                cliente_telefono VARCHAR(30), pedido_num VARCHAR(50),
                modelo VARCHAR(200), color VARCHAR(50),
                precio_total DECIMAL(12,2), enganche DECIMAL(12,2), monto_financiado DECIMAL(12,2),
                plazo_meses INT, pago_semanal DECIMAL(12,2), semanas_total INT,
                monto_pagado DECIMAL(12,2) DEFAULT 0, monto_restante DECIMAL(12,2),
                semanas_pagadas INT DEFAULT 0, proximo_pago DATE,
                estado VARCHAR(20) DEFAULT 'activo',
                freg DATETIME DEFAULT CURRENT_TIMESTAMP)"); } catch (Throwable $e) {}
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS pagos_credito_historial (
                id INT AUTO_INCREMENT PRIMARY KEY,
                credito_id INT, semana_num INT, monto DECIMAL(12,2),
                estado VARCHAR(20) DEFAULT 'pendiente', metodo VARCHAR(40),
                fecha_programada DATE, fecha_pago DATETIME NULL,
                stripe_payment_intent VARCHAR(60) NULL,
                freg DATETIME DEFAULT CURRENT_TIMESTAMP)"); } catch (Throwable $e) {}

            $precio       = 48260;
            $engPct       = 0.25;
            $enganche     = $precio * $engPct;
            $financiado   = $precio - $enganche;
            $plazoMeses   = 36;
            $semanas      = (int)round($plazoMeses * (52 / 12));
            $pagoSemanal  = 554;
            $proximoPago  = date('Y-m-d', strtotime('+7 days'));

            $pdo->prepare("INSERT INTO pagos_credito
                (cliente_nombre, cliente_email, cliente_telefono, pedido_num,
                 modelo, color, precio_total, enganche, monto_financiado,
                 plazo_meses, pago_semanal, semanas_total, monto_restante, proximo_pago)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([
                   $TEST_NOMBRE, $TEST_EMAIL, $TEST_TEL, $pcPedido,
                   'Pesgo plus', 'rojo', $precio, $enganche, $financiado,
                   $plazoMeses, $pagoSemanal, $semanas, $financiado, $proximoPago,
               ]);
            $pcId = (int)$pdo->lastInsertId();

            // Generate weekly schedule (pendiente by default).
            $ins = $pdo->prepare("INSERT INTO pagos_credito_historial
                (credito_id, semana_num, monto, estado, fecha_programada)
                VALUES (?, ?, ?, 'pendiente', DATE_ADD(CURDATE(), INTERVAL ? DAY))");
            for ($i = 1; $i <= $semanas; $i++) {
                $ins->execute([$pcId, $i, $pagoSemanal, $i * 7]);
            }
            $log[] = "pagos_credito {$pcPedido}: creado (id={$pcId}, {$semanas} semanas)";
        }
    }
} catch (Throwable $e) { $log[] = "pagos_credito ERROR: " . $e->getMessage(); }

// ── 4. preaprobacion (PREAPROBADO state for Solicitudes flow) ────────
try {
    $preap = $pdo->prepare("SELECT id FROM preaprobaciones
                            WHERE telefono = ? OR email = ? LIMIT 1");
    $preap->execute([$TEST_TEL, $TEST_EMAIL]);
    $existingPreapId = (int)($preap->fetchColumn() ?: 0);
    if ($existingPreapId) {
        $log[] = "preaprobacion: existe (id={$existingPreapId})";
    } else {
        $plan[] = "preaprobacion: PREAPROBADO score=580, PTI=20%, source=real";
        if ($run) {
            $pCols = tableCols($pdo, 'preaprobaciones', $colsCache);
            $f = ['nombre','email','telefono','status'];
            $v = [$TEST_NOMBRE, $TEST_EMAIL, $TEST_TEL, 'PREAPROBADO'];
            if (isset($pCols['apellido_paterno'])) { $f[]='apellido_paterno'; $v[]=$TEST_AP_PAT; }
            if (isset($pCols['apellido_materno'])) { $f[]='apellido_materno'; $v[]=$TEST_AP_MAT; }
            if (isset($pCols['fecha_nacimiento'])) { $f[]='fecha_nacimiento'; $v[]='1990-01-01'; }
            if (isset($pCols['cp']))               { $f[]='cp';               $v[]='11700'; }
            if (isset($pCols['ciudad']))           { $f[]='ciudad';           $v[]='Ciudad de México'; }
            if (isset($pCols['estado']))           { $f[]='estado';           $v[]='Distrito Federal'; }
            if (isset($pCols['modelo']))           { $f[]='modelo';           $v[]='M05'; }
            if (isset($pCols['precio_contado']))   { $f[]='precio_contado';   $v[]=48260; }
            if (isset($pCols['ingreso_mensual']))  { $f[]='ingreso_mensual';  $v[]=30000; }
            if (isset($pCols['pago_semanal']))     { $f[]='pago_semanal';     $v[]=554; }
            if (isset($pCols['pago_mensual']))     { $f[]='pago_mensual';     $v[]=2401; }
            if (isset($pCols['pti_total']))        { $f[]='pti_total';        $v[]=0.20; }
            if (isset($pCols['score']))            { $f[]='score';            $v[]=580; }
            if (isset($pCols['circulo_source']))   { $f[]='circulo_source';   $v[]='real'; }
            if (isset($pCols['enganche_pct']))     { $f[]='enganche_pct';     $v[]=25; }
            if (isset($pCols['plazo_meses']))      { $f[]='plazo_meses';      $v[]=36; }
            if (isset($pCols['enganche_requerido'])){ $f[]='enganche_requerido'; $v[]=0.25; }
            if (isset($pCols['plazo_max']))        { $f[]='plazo_max';        $v[]=36; }
            if (isset($pCols['truora_ok']))        { $f[]='truora_ok';        $v[]=1; }
            if (isset($pCols['seguimiento']))      { $f[]='seguimiento';      $v[]='nuevo'; }
            if (isset($pCols['notas_admin']))      { $f[]='notas_admin';      $v[]='[TEST] Seed 5500000000'; }
            $ph = implode(',', array_fill(0, count($f), '?'));
            $pdo->prepare("INSERT INTO preaprobaciones (".implode(',', $f).") VALUES ($ph)")
               ->execute($v);
            $log[] = "preaprobacion: creada";
        }
    }
} catch (Throwable $e) { $log[] = "preaprobacion ERROR: " . $e->getMessage(); }

// ── 5. inventario_motos (assigned moto for the test cliente) ────────
// Required by: Mi Voltika menu, Entrega menu, Documentos menu (acta de
// entrega lookup by moto_id), and the firmas_contratos JOIN.
$motoId = 0;
try {
    $stmt = $pdo->prepare("SELECT id FROM inventario_motos
                           WHERE cliente_telefono = ? OR cliente_email = ?
                           ORDER BY id DESC LIMIT 1");
    $stmt->execute([$TEST_TEL, $TEST_EMAIL]);
    $motoId = (int)($stmt->fetchColumn() ?: 0);
    if ($motoId) {
        $log[] = "inventario_motos: existe (id={$motoId})";
    } else {
        $plan[] = "inventario_motos: VIN R4WPATATEST500001, Pesgo plus rojo, entregada";
        if ($run) {
            $imCols = tableCols($pdo, 'inventario_motos', $colsCache);
            $f = ['vin','modelo','color','estado','activo'];
            $v = ['R4WPATATEST500001','Pesgo plus','rojo','entregada',1];
            if (isset($imCols['vin_display']))      { $f[]='vin_display';      $v[]='R4WPATATEST500001'; }
            if (isset($imCols['cliente_id']) && $cid){$f[]='cliente_id';       $v[]=$cid; }
            if (isset($imCols['cliente_nombre']))   { $f[]='cliente_nombre';   $v[]=$TEST_NOMBRE; }
            if (isset($imCols['cliente_email']))    { $f[]='cliente_email';    $v[]=$TEST_EMAIL; }
            if (isset($imCols['cliente_telefono'])) { $f[]='cliente_telefono'; $v[]=$TEST_TEL; }
            if (isset($imCols['pedido_num']))       { $f[]='pedido_num';       $v[]='VK-1826-TEST'; }
            if (isset($imCols['transaccion_id']) && isset($txId) && $txId) {
                                                      $f[]='transaccion_id';   $v[]=$txId; }
            if (isset($imCols['precio_venta']))     { $f[]='precio_venta';     $v[]=48260; }
            if (isset($imCols['enganche_pagado']))  { $f[]='enganche_pagado';  $v[]=12065; }
            if (isset($imCols['pago_estado']))      { $f[]='pago_estado';      $v[]='pagada'; }
            if (isset($imCols['fecha_estado']))     { $f[]='fecha_estado';     $v[]=date('Y-m-d H:i:s', strtotime('-3 days')); }
            $ph = implode(',', array_fill(0, count($f), '?'));
            $pdo->prepare("INSERT INTO inventario_motos (".implode(',', $f).") VALUES ($ph)")
               ->execute($v);
            $motoId = (int)$pdo->lastInsertId();
            $log[] = "inventario_motos: creado (id={$motoId})";
        }
    }
} catch (Throwable $e) { $log[] = "inventario_motos ERROR: " . $e->getMessage(); }

// ── 6. envios (shipment for the moto) ────────────────────────────────
try {
    if ($motoId) {
        $stmt = $pdo->prepare("SELECT id FROM envios WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$motoId]);
        if ($stmt->fetchColumn()) {
            $log[] = "envios: existe";
        } else {
            $plan[] = "envios: enviado, ETA hace 1 día";
            if ($run) {
                $eCols = tableCols($pdo, 'envios', $colsCache);
                $f = ['moto_id','estado'];
                $v = [$motoId,'enviado'];
                if (isset($eCols['carrier']))               { $f[]='carrier';               $v[]='Skydrop'; }
                if (isset($eCols['tracking_number']))       { $f[]='tracking_number';       $v[]='SKD-TEST-5500'; }
                if (isset($eCols['fecha_envio']))           { $f[]='fecha_envio';           $v[]=date('Y-m-d H:i:s', strtotime('-7 days')); }
                if (isset($eCols['fecha_estimada_llegada'])){ $f[]='fecha_estimada_llegada';$v[]=date('Y-m-d', strtotime('-1 days')); }
                if (isset($eCols['fecha_recepcion']))       { $f[]='fecha_recepcion';       $v[]=date('Y-m-d H:i:s', strtotime('-3 days')); }
                $ph = implode(',', array_fill(0, count($f), '?'));
                $pdo->prepare("INSERT INTO envios (".implode(',', $f).") VALUES ($ph)")->execute($v);
                $log[] = "envios: creado";
            }
        }
    }
} catch (Throwable $e) { $log[] = "envios ERROR: " . $e->getMessage(); }

// ── 7. entregas (delivery state) ─────────────────────────────────────
try {
    if ($motoId) {
        $stmt = $pdo->prepare("SELECT id FROM entregas WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$motoId]);
        if ($stmt->fetchColumn()) {
            $log[] = "entregas: existe";
        } else {
            $plan[] = "entregas: estado_entrega completada";
            if ($run) {
                try {
                    $entCols = tableCols($pdo, 'entregas', $colsCache);
                    $f = ['moto_id'];
                    $v = [$motoId];
                    if (isset($entCols['estado_entrega']))   { $f[]='estado_entrega';   $v[]='completada'; }
                    if (isset($entCols['estado']))           { $f[]='estado';           $v[]='completada'; }
                    if (isset($entCols['fecha_entrega']))    { $f[]='fecha_entrega';    $v[]=date('Y-m-d H:i:s', strtotime('-3 days')); }
                    if (isset($entCols['cliente_nombre']))   { $f[]='cliente_nombre';   $v[]=$TEST_NOMBRE; }
                    if (isset($entCols['cliente_telefono'])) { $f[]='cliente_telefono'; $v[]=$TEST_TEL; }
                    $ph = implode(',', array_fill(0, count($f), '?'));
                    $pdo->prepare("INSERT INTO entregas (".implode(',', $f).") VALUES ($ph)")->execute($v);
                    $log[] = "entregas: creado";
                } catch (Throwable $e) { $log[] = "entregas (table missing): " . $e->getMessage(); }
            }
        }
    }
} catch (Throwable $e) { $log[] = "entregas ERROR: " . $e->getMessage(); }

// ── 8. checklist_entrega_v2 (delivery checklist completed) ──────────
try {
    if ($motoId) {
        $stmt = $pdo->prepare("SELECT id FROM checklist_entrega_v2 WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$motoId]);
        if ($stmt->fetchColumn()) {
            $log[] = "checklist_entrega_v2: existe";
        } else {
            $plan[] = "checklist_entrega_v2: completado";
            if ($run) {
                try {
                    $cCols = tableCols($pdo, 'checklist_entrega_v2', $colsCache);
                    $f = ['moto_id'];
                    $v = [$motoId];
                    if (isset($cCols['completado']))   { $f[]='completado';   $v[]=1; }
                    if (isset($cCols['cliente_id']) && $cid) { $f[]='cliente_id'; $v[]=$cid; }
                    if (isset($cCols['fecha_completado'])) { $f[]='fecha_completado'; $v[]=date('Y-m-d H:i:s', strtotime('-3 days')); }
                    $ph = implode(',', array_fill(0, count($f), '?'));
                    $pdo->prepare("INSERT INTO checklist_entrega_v2 (".implode(',', $f).") VALUES ($ph)")->execute($v);
                    $log[] = "checklist_entrega_v2: creado";
                } catch (Throwable $e) { $log[] = "checklist_entrega_v2 (table missing): " . $e->getMessage(); }
            }
        }
    }
} catch (Throwable $e) { $log[] = "checklist_entrega_v2 ERROR: " . $e->getMessage(); }

// ── 9. firmas_contratos (signed contract for documentos) ────────────
try {
    $stmt = $pdo->prepare("SELECT id FROM firmas_contratos
                           WHERE telefono = ? OR email = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$TEST_TEL, $TEST_EMAIL]);
    if ($stmt->fetchColumn()) {
        $log[] = "firmas_contratos: existe";
    } else {
        $plan[] = "firmas_contratos: contrato firmado hace 5 días";
        if ($run) {
            try {
                $fcCols = tableCols($pdo, 'firmas_contratos', $colsCache);
                $f = ['nombre','email','telefono'];
                $v = [$TEST_NOMBRE, $TEST_EMAIL, $TEST_TEL];
                if (isset($fcCols['cliente_id']) && $cid) { $f[]='cliente_id'; $v[]=$cid; }
                if (isset($fcCols['firma_data_url'])) { $f[]='firma_data_url'; $v[]='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgYAAAAAMAASsJTYQAAAAASUVORK5CYII='; }
                if (isset($fcCols['ip']))            { $f[]='ip';            $v[]='127.0.0.1'; }
                if (isset($fcCols['user_agent']))    { $f[]='user_agent';    $v[]='Test/1.0 Seed'; }
                $ph = implode(',', array_fill(0, count($f), '?'));
                $pdo->prepare("INSERT INTO firmas_contratos (".implode(',', $f).") VALUES ($ph)")->execute($v);
                $log[] = "firmas_contratos: creado";
            } catch (Throwable $e) { $log[] = "firmas_contratos (table missing): " . $e->getMessage(); }
        }
    }
} catch (Throwable $e) { $log[] = "firmas_contratos ERROR: " . $e->getMessage(); }

// ── 10. actas_entrega (delivery acta for documentos) ────────────────
try {
    if ($cid) {
        // Ensure the table exists — older installs may not have it.
        // documentos/lista.php queries actas_entrega.cliente_id + freg, so
        // those are the minimum columns needed.
        if ($run) {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS actas_entrega (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    cliente_id INT NULL,
                    moto_id INT NULL,
                    transaccion_id INT NULL,
                    firma_data_url MEDIUMTEXT NULL,
                    ip VARCHAR(64) NULL,
                    user_agent VARCHAR(255) NULL,
                    freg DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_cliente (cliente_id),
                    INDEX idx_moto (moto_id)
                )");
            } catch (Throwable $e) {}
        }
        $stmt = $pdo->prepare("SELECT id FROM actas_entrega WHERE cliente_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$cid]);
        if ($stmt->fetchColumn()) {
            $log[] = "actas_entrega: existe";
        } else {
            $plan[] = "actas_entrega: acta firmada hace 3 días";
            if ($run) {
                try {
                    $aCols = tableCols($pdo, 'actas_entrega', $colsCache);
                    $f = ['cliente_id'];
                    $v = [$cid];
                    if (isset($aCols['moto_id']) && $motoId) { $f[]='moto_id'; $v[]=$motoId; }
                    if (isset($aCols['firma_data_url']))  { $f[]='firma_data_url';  $v[]='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgYAAAAAMAASsJTYQAAAAASUVORK5CYII='; }
                    if (isset($aCols['ip']))             { $f[]='ip';              $v[]='127.0.0.1'; }
                    $ph = implode(',', array_fill(0, count($f), '?'));
                    $pdo->prepare("INSERT INTO actas_entrega (".implode(',', $f).") VALUES ($ph)")->execute($v);
                    $log[] = "actas_entrega: creado";
                } catch (Throwable $e) { $log[] = "actas_entrega INSERT: " . $e->getMessage(); }
            }
        }
    }
} catch (Throwable $e) { $log[] = "actas_entrega ERROR: " . $e->getMessage(); }

// ── 11. ciclos_pago (weekly cycles for Pagos history) ───────────────
try {
    $sStmt = $pdo->prepare("SELECT id FROM subscripciones_credito
                            WHERE telefono = ? OR email = ? LIMIT 1");
    $sStmt->execute([$TEST_TEL, $TEST_EMAIL]);
    $subId = (int)($sStmt->fetchColumn() ?: 0);
    if ($subId) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ciclos_pago WHERE subscripcion_id = ?");
        $stmt->execute([$subId]);
        $cnt = (int)$stmt->fetchColumn();
        if ($cnt > 0) {
            $log[] = "ciclos_pago: existe ({$cnt} ciclos)";
        } else {
            $plan[] = "ciclos_pago: 156 ciclos semanales (3 ya pagados)";
            if ($run) {
                try {
                    $cCols = tableCols($pdo, 'ciclos_pago', $colsCache);
                    $start = strtotime('-21 days');
                    $ins = $pdo->prepare("INSERT INTO ciclos_pago
                        (subscripcion_id, semana_num, fecha_vencimiento, monto, estado".
                        (isset($cCols['cliente_id']) ? ', cliente_id' : '').
                        ") VALUES (?, ?, ?, ?, ?".(isset($cCols['cliente_id']) ? ', ?' : '').")");
                    for ($i = 1; $i <= 156; $i++) {
                        $estado = ($i <= 3) ? 'paid_manual' : 'pending';
                        $fecha = date('Y-m-d', $start + ($i-1) * 7 * 86400);
                        $params = [$subId, $i, $fecha, 554, $estado];
                        if (isset($cCols['cliente_id'])) $params[] = $cid;
                        $ins->execute($params);
                    }
                    $log[] = "ciclos_pago: creados (156 ciclos, 3 pagados)";
                } catch (Throwable $e) { $log[] = "ciclos_pago (table missing): " . $e->getMessage(); }
            }
        }
    }
} catch (Throwable $e) { $log[] = "ciclos_pago ERROR: " . $e->getMessage(); }

// ── 12. notificaciones_log (notifications panel) ────────────────────
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notificaciones_log
                           WHERE destino IN (?, ?)");
    $stmt->execute([$TEST_TEL, $TEST_EMAIL]);
    $nCnt = (int)$stmt->fetchColumn();
    if ($nCnt > 0) {
        $log[] = "notificaciones_log: existe ({$nCnt})";
    } else {
        $plan[] = "notificaciones_log: 3 notificaciones (recordatorio, pago OK, contrato)";
        if ($run) {
            try {
                $nCols = tableCols($pdo, 'notificaciones_log', $colsCache);
                $rows = [
                    ['recordatorio_pago', 'sms',  $TEST_TEL,   'Voltika: tu pago de $554 vence pronto.', 'sent'],
                    ['pago_confirmado',   'sms',  $TEST_TEL,   'Voltika: recibimos tu pago de $554. ¡Gracias!', 'sent'],
                    ['contrato_firmado',  'email',$TEST_EMAIL, 'Tu contrato Voltika está firmado y disponible.', 'sent'],
                ];
                foreach ($rows as $r) {
                    $f = ['tipo','canal','destino','mensaje','status'];
                    $v = $r;
                    if (isset($nCols['cliente_id']) && $cid) { $f[]='cliente_id'; $v[]=$cid; }
                    $ph = implode(',', array_fill(0, count($f), '?'));
                    $pdo->prepare("INSERT INTO notificaciones_log (".implode(',', $f).") VALUES ($ph)")->execute($v);
                }
                $log[] = "notificaciones_log: 3 filas creadas";
            } catch (Throwable $e) { $log[] = "notificaciones_log (table missing): " . $e->getMessage(); }
        }
    }
} catch (Throwable $e) { $log[] = "notificaciones_log ERROR: " . $e->getMessage(); }

// ── 13. Tally summary ────────────────────────────────────────────────
$counts = [
    'transacciones' => 0, 'subscripciones' => 0, 'preaprobaciones' => 0,
    'inventario_motos' => 0, 'envios' => 0, 'entregas' => 0,
    'firmas_contratos' => 0, 'actas_entrega' => 0, 'ciclos_pago' => 0,
    'notificaciones_log' => 0, 'pagos_credito' => 0,
];
try {
    $q = $pdo->prepare("SELECT COUNT(*) FROM transacciones WHERE telefono = ? OR email = ?");
    $q->execute([$TEST_TEL, $TEST_EMAIL]); $counts['transacciones'] = (int)$q->fetchColumn();
    $q = $pdo->prepare("SELECT COUNT(*) FROM subscripciones_credito WHERE telefono = ? OR email = ?");
    $q->execute([$TEST_TEL, $TEST_EMAIL]); $counts['subscripciones'] = (int)$q->fetchColumn();
    $q = $pdo->prepare("SELECT COUNT(*) FROM preaprobaciones WHERE telefono = ? OR email = ?");
    $q->execute([$TEST_TEL, $TEST_EMAIL]); $counts['preaprobaciones'] = (int)$q->fetchColumn();
    foreach ([
        'inventario_motos' => "SELECT COUNT(*) FROM inventario_motos WHERE cliente_telefono = ? OR cliente_email = ?",
        'envios' => "SELECT COUNT(*) FROM envios e JOIN inventario_motos m ON m.id=e.moto_id WHERE m.cliente_telefono=? OR m.cliente_email=?",
        'entregas' => "SELECT COUNT(*) FROM entregas e JOIN inventario_motos m ON m.id=e.moto_id WHERE m.cliente_telefono=? OR m.cliente_email=?",
        'firmas_contratos' => "SELECT COUNT(*) FROM firmas_contratos WHERE telefono=? OR email=?",
        'actas_entrega' => "SELECT COUNT(*) FROM actas_entrega a JOIN clientes c ON c.id=a.cliente_id WHERE c.telefono=? OR c.email=?",
        'ciclos_pago' => "SELECT COUNT(*) FROM ciclos_pago cp JOIN subscripciones_credito s ON s.id=cp.subscripcion_id WHERE s.telefono=? OR s.email=?",
        'notificaciones_log' => "SELECT COUNT(*) FROM notificaciones_log WHERE destino=? OR destino=?",
        'pagos_credito' => "SELECT COUNT(*) FROM pagos_credito WHERE cliente_telefono=? OR cliente_email=?",
    ] as $k => $sql) {
        try {
            $q = $pdo->prepare($sql);
            $q->execute([$TEST_TEL, $TEST_EMAIL]);
            $counts[$k] = (int)$q->fetchColumn();
        } catch (Throwable $e) { /* table may not exist */ }
    }
} catch (Throwable $e) { $log[] = "count ERROR: " . $e->getMessage(); }

?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Seed test 5500000000</title>
<style>
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#0b0f14;color:#eef2f7;padding:40px;max-width:760px;margin:0 auto}
.box{background:#11161d;border:1px solid #202a36;border-radius:12px;padding:24px;margin-bottom:16px}
h1{color:#22d37a;margin:0 0 16px;font-size:24px}
h3{color:#22d37a;margin:0 0 12px}
.row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #202a36;gap:12px}
.row:last-child{border-bottom:0}
.k{color:#9aa7b7}.v{font-weight:700;text-align:right}
.log{background:#0b0f14;border:1px solid #202a36;border-radius:8px;padding:12px;font-family:monospace;font-size:12px;color:#b7f2cf;margin-top:12px;line-height:1.7}
.plan{color:#facc15}
.err{color:#ff8c8c}
a.btn{display:inline-block;background:#22d37a;color:#04120a;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:700;margin:12px 8px 0 0}
a.btn.alt{background:#3b82f6;color:#fff}
a.btn.warn{background:#f59e0b;color:#1a1a1a}
.warn{background:rgba(245,179,1,.1);border:1px solid rgba(245,179,1,.35);color:#ffe19a;padding:12px;border-radius:8px;margin-top:16px;font-size:13px}
code{background:#202a36;padding:2px 6px;border-radius:4px}
</style></head><body>

<h1><?= $run ? '✅ Datos sembrados' : '🔍 Modo Preview (sin guardar)' ?></h1>

<div class="box">
  <h3>Cuenta de prueba</h3>
  <div class="row"><span class="k">Teléfono</span><span class="v"><?= htmlspecialchars($TEST_TEL) ?></span></div>
  <div class="row"><span class="k">Email</span><span class="v"><?= htmlspecialchars($TEST_EMAIL) ?></span></div>
  <div class="row"><span class="k">Nombre</span><span class="v"><?= htmlspecialchars($TEST_NOMBRE) ?></span></div>
  <div class="row"><span class="k">cliente_id</span><span class="v"><?= $cid ?: '—' ?></span></div>
</div>

<div class="box">
  <h3>Tablas</h3>
  <div class="row"><span class="k">transacciones</span><span class="v"><?= $counts['transacciones'] ?></span></div>
  <div class="row"><span class="k">subscripciones_credito</span><span class="v"><?= $counts['subscripciones'] ?></span></div>
  <div class="row"><span class="k">preaprobaciones</span><span class="v"><?= $counts['preaprobaciones'] ?></span></div>
  <div class="row"><span class="k">inventario_motos</span><span class="v"><?= $counts['inventario_motos'] ?></span></div>
  <div class="row"><span class="k">envios</span><span class="v"><?= $counts['envios'] ?></span></div>
  <div class="row"><span class="k">entregas</span><span class="v"><?= $counts['entregas'] ?></span></div>
  <div class="row"><span class="k">firmas_contratos</span><span class="v"><?= $counts['firmas_contratos'] ?></span></div>
  <div class="row"><span class="k">actas_entrega</span><span class="v"><?= $counts['actas_entrega'] ?></span></div>
  <div class="row"><span class="k">pagos_credito</span><span class="v"><?= $counts['pagos_credito'] ?></span></div>
  <div class="row"><span class="k">ciclos_pago</span><span class="v"><?= $counts['ciclos_pago'] ?></span></div>
  <div class="row"><span class="k">notificaciones_log</span><span class="v"><?= $counts['notificaciones_log'] ?></span></div>

  <?php if ($plan): ?>
  <div class="log">
    <strong style="color:#facc15">Plan (lo que se haría con ?run=1):</strong><br>
    <?php foreach ($plan as $p): ?>
      <span class="plan">→ <?= htmlspecialchars($p) ?></span><br>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="log">
    <strong>Log:</strong><br>
    <?php foreach ($log as $l): ?>
      <?php $isErr = stripos($l, 'error') !== false; ?>
      <span class="<?= $isErr?'err':'' ?>">→ <?= htmlspecialchars($l) ?></span><br>
    <?php endforeach; ?>
  </div>

  <?php if (!$run): ?>
    <a class="btn" href="?run=1">Ejecutar (insertar datos)</a>
    <a class="btn alt" href="/clientes/">Ir al portal</a>
  <?php else: ?>
    <a class="btn alt" href="/clientes/">Ir al portal cliente</a>
    <a class="btn alt" href="/configurador/mi-credito.html">mi-credito.html</a>
    <a class="btn warn" href="?run=1">Re-ejecutar (idempotente)</a>
  <?php endif; ?>
</div>

<div class="box">
  <h3>Cómo probar</h3>
  <ol style="line-height:1.8;padding-left:20px">
    <li><strong>Portal cliente <code>/clientes/</code></strong> — login con tel <code>5500000000</code> + OTP <code>123456</code>. Cada menú debe tener datos:
      <ul style="font-size:13px;color:#9aa7b7;margin-top:6px">
        <li><b>Inicio</b> — resumen de crédito + próximo pago</li>
        <li><b>Mis compras</b> — Pesgo plus rojo (crédito) + 1 transacción contado</li>
        <li><b>Pagos</b> — historial 156 ciclos (3 pagados, resto pendiente)</li>
        <li><b>Entrega</b> — moto entregada hace 3 días, checklist completo</li>
        <li><b>Documentos</b> — contrato firmado + acta de entrega</li>
        <li><b>Mi Voltika</b> — VIN R4WPATATEST500001, modelo Pesgo plus</li>
        <li><b>Cuenta</b> — perfil del cliente [TEST]</li>
        <li><b>Ayuda</b> — formulario de contacto (estático)</li>
      </ul>
    </li>
    <li>Configurador checkout: tel <code>5500000000</code>, OTP <code>123456</code></li>
    <li>mi-credito.html: pedido <code>TEST-5500-CREDITO-1</code></li>
    <li>Solicitudes admin: busca <code><?= htmlspecialchars($TEST_NOMBRE) ?></code></li>
    <li>Pagos admin: transacción <code>TEST-5500-CONTADO-1</code></li>
  </ol>
</div>

<div class="warn">
  ⚠ <b>Test only.</b> Borra este archivo (<code>clientes/seed-test-5500000000.php</code>) cuando termines de probar.
  Las filas se identifican por <code>[TEST]</code> en el nombre o <code>diag-test@voltika.mx</code> en el email.
</div>

</body></html>
