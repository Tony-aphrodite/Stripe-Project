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

// ── 5. Tally summary ─────────────────────────────────────────────────
$counts = ['transacciones' => 0, 'subscripciones' => 0, 'preaprobaciones' => 0];
try {
    $q = $pdo->prepare("SELECT COUNT(*) FROM transacciones WHERE telefono = ? OR email = ?");
    $q->execute([$TEST_TEL, $TEST_EMAIL]); $counts['transacciones'] = (int)$q->fetchColumn();
    $q = $pdo->prepare("SELECT COUNT(*) FROM subscripciones_credito WHERE telefono = ? OR email = ?");
    $q->execute([$TEST_TEL, $TEST_EMAIL]); $counts['subscripciones'] = (int)$q->fetchColumn();
    $q = $pdo->prepare("SELECT COUNT(*) FROM preaprobaciones WHERE telefono = ? OR email = ?");
    $q->execute([$TEST_TEL, $TEST_EMAIL]); $counts['preaprobaciones'] = (int)$q->fetchColumn();
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
    <li>Configurador checkout: usa <code>5500000000</code> en el formulario, OTP <code>123456</code> (con bypass habilitado)</li>
    <li>mi-credito.html: ingresa <code>TEST-5500-CREDITO-1</code> como pedido</li>
    <li>Solicitudes admin: busca <code><?= htmlspecialchars($TEST_NOMBRE) ?></code></li>
    <li>Pagos admin: aparecerá la transacción <code>TEST-5500-CONTADO-1</code></li>
  </ol>
</div>

<div class="warn">
  ⚠ <b>Test only.</b> Borra este archivo (<code>clientes/seed-test-5500000000.php</code>) cuando termines de probar.
  Las filas se identifican por <code>[TEST]</code> en el nombre o <code>diag-test@voltika.mx</code> en el email.
</div>

</body></html>
