<?php
/**
 * One-shot seed — creates a test CONTADO client (single-payment) so the new
 * Inicio wireframe can be tested.
 *
 *   https://voltika.mx/clientes/seed-test-contado.php
 *
 * Creates:
 *   1. clientes row (or reuses an existing one matching the test phone)
 *   2. transacciones row with tpago='contado', pago_estado='pagada'
 *   3. inventario_motos row linked to the transaction (for delivery info)
 *
 * Does NOT create subscripciones_credito → portal will detect tipoPortal=
 * 'contado' and render the new home.
 *
 * ⚠ Delete after testing.
 */
require_once __DIR__ . '/php/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');

// ── Tweak these if you want a different test identity ──────────────────────
$TEST_TEL    = '5511112222';
$TEST_NOMBRE = 'Cliente Contado';
$TEST_EMAIL  = 'contado@voltika.mx';
$TEST_MODELO = 'M05';
$TEST_COLOR  = 'negro';
$TEST_VIN    = 'VKTEST00000000847';
$TEST_TOTAL  = 29999.00;

$pdo = getDB();
$log = [];

// ── 1. Ensure clientes row ─────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT id FROM clientes WHERE telefono = ? OR email = ? LIMIT 1");
$stmt->execute([$TEST_TEL, $TEST_EMAIL]);
$cid = (int)($stmt->fetchColumn() ?: 0);

if ($cid) {
    $log[] = "clientes: ya existe (id={$cid})";
    $pdo->prepare("UPDATE clientes SET nombre=?, telefono=?, email=? WHERE id=?")
       ->execute([$TEST_NOMBRE, $TEST_TEL, $TEST_EMAIL, $cid]);
} else {
    $pdo->prepare("INSERT INTO clientes (nombre, telefono, email) VALUES (?,?,?)")
       ->execute([$TEST_NOMBRE, $TEST_TEL, $TEST_EMAIL]);
    $cid = (int)$pdo->lastInsertId();
    $log[] = "clientes: creado (id={$cid})";
}

// ── 2. Make sure no subscripciones_credito interferes (this client must be
//      detected as contado, not credito) ────────────────────────────────────
try {
    $del = $pdo->prepare("DELETE FROM subscripciones_credito
                           WHERE cliente_id = ? OR telefono = ? OR email = ?");
    $del->execute([$cid, $TEST_TEL, $TEST_EMAIL]);
    if ($del->rowCount() > 0) $log[] = "subscripciones_credito: limpiado " . $del->rowCount() . " fila(s) previa(s)";
} catch (Throwable $e) { $log[] = "subscripciones_credito limpieza: " . $e->getMessage(); }

// ── 3. Ensure transacciones row (contado) ─────────────────────────────────
$stmt = $pdo->prepare("SELECT id FROM transacciones
                        WHERE email = ? AND tpago = 'contado'
                        ORDER BY id DESC LIMIT 1");
$stmt->execute([$TEST_EMAIL]);
$txId = (int)($stmt->fetchColumn() ?: 0);

$pedidoNum = 'TEST-CONTADO-' . date('Ymd');
if ($txId) {
    $log[] = "transacciones: ya existe (id={$txId})";
    $pdo->prepare("UPDATE transacciones SET
        pago_estado='pagada', total=?, modelo=?, color=?, nombre=?, telefono=?, freg=NOW()
        WHERE id=?")
       ->execute([$TEST_TOTAL, $TEST_MODELO, $TEST_COLOR, $TEST_NOMBRE, $TEST_TEL, $txId]);
} else {
    // Build INSERT dynamically (some columns may not exist on older schemas)
    $cols = $pdo->query("SHOW COLUMNS FROM transacciones")->fetchAll(PDO::FETCH_COLUMN);
    $colSet = array_flip($cols);

    $fields = ['pedido', 'nombre', 'email', 'telefono', 'modelo', 'color', 'total', 'tpago', 'pago_estado'];
    $values = [$pedidoNum, $TEST_NOMBRE, $TEST_EMAIL, $TEST_TEL, $TEST_MODELO, $TEST_COLOR, $TEST_TOTAL, 'contado', 'pagada'];
    if (isset($colSet['freg']))         { $fields[] = 'freg';         $values[] = date('Y-m-d H:i:s'); }
    if (isset($colSet['stripe_pi']))    { $fields[] = 'stripe_pi';    $values[] = ''; }
    if (isset($colSet['ciudad']))       { $fields[] = 'ciudad';       $values[] = 'Ciudad de México'; }
    if (isset($colSet['estado']))       { $fields[] = 'estado';       $values[] = 'Distrito Federal'; }
    if (isset($colSet['cp']))           { $fields[] = 'cp';           $values[] = '11700'; }
    if (isset($colSet['punto_nombre'])) { $fields[] = 'punto_nombre'; $values[] = 'Voltika Center'; }

    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $pdo->prepare("INSERT INTO transacciones (" . implode(',', $fields) . ") VALUES ($placeholders)")
       ->execute($values);
    $txId = (int)$pdo->lastInsertId();
    $log[] = "transacciones: creado (id={$txId}, pedido={$pedidoNum})";
}

// Resolve / generate pedido_corto (so the home shows VK-YYMM-NNNN)
$pedidoCorto = '';
try {
    $notifyPath = null;
    foreach ([
        __DIR__ . '/../configurador_prueba_test/php/voltika-notify.php',
        __DIR__ . '/../configurador_prueba/php/voltika-notify.php',
    ] as $p) { if (is_file($p)) { $notifyPath = $p; break; } }
    if ($notifyPath) require_once $notifyPath;
    if (function_exists('voltikaResolvePedidoCorto')) {
        $pedidoCorto = voltikaResolvePedidoCorto($pdo, $txId);
        if ($pedidoCorto) $log[] = "pedido_corto: {$pedidoCorto}";
    }
} catch (Throwable $e) { $log[] = "pedido_corto: " . $e->getMessage(); }

// ── 4. Ensure inventario_motos row (delivered moto) ───────────────────────
$stmt = $pdo->prepare("SELECT id FROM inventario_motos
                        WHERE cliente_telefono = ? OR cliente_email = ?
                        ORDER BY id DESC LIMIT 1");
$stmt->execute([$TEST_TEL, $TEST_EMAIL]);
$motoId = (int)($stmt->fetchColumn() ?: 0);

if ($motoId) {
    $log[] = "inventario_motos: ya existe (id={$motoId})";
    $pdo->prepare("UPDATE inventario_motos SET
        modelo=?, color=?, vin_display=?, vin=?,
        cliente_nombre=?, cliente_email=?, cliente_telefono=?, cliente_id=?,
        pedido_num=?, estado='entregada', activo=1, fecha_estado=NOW()
        WHERE id=?")
       ->execute([
           $TEST_MODELO, $TEST_COLOR, $TEST_VIN, $TEST_VIN,
           $TEST_NOMBRE, $TEST_EMAIL, $TEST_TEL, $cid,
           'VK-' . $pedidoNum, $motoId,
       ]);
} else {
    $mCols = $pdo->query("SHOW COLUMNS FROM inventario_motos")->fetchAll(PDO::FETCH_COLUMN);
    $mSet  = array_flip($mCols);

    $f = ['modelo','color','vin','vin_display','cliente_nombre','cliente_email','cliente_telefono','pedido_num','estado','activo'];
    $v = [$TEST_MODELO, $TEST_COLOR, $TEST_VIN, $TEST_VIN, $TEST_NOMBRE, $TEST_EMAIL, $TEST_TEL, 'VK-' . $pedidoNum, 'entregada', 1];
    if (isset($mSet['cliente_id']))     { $f[] = 'cliente_id';     $v[] = $cid; }
    if (isset($mSet['transaccion_id'])) { $f[] = 'transaccion_id'; $v[] = $txId; }
    if (isset($mSet['fecha_estado']))   { $f[] = 'fecha_estado';   $v[] = date('Y-m-d H:i:s'); }
    if (isset($mSet['freg']))           { $f[] = 'freg';           $v[] = date('Y-m-d H:i:s'); }
    if (isset($mSet['pago_estado']))    { $f[] = 'pago_estado';    $v[] = 'pagada'; }

    $placeholders = implode(',', array_fill(0, count($f), '?'));
    $pdo->prepare("INSERT INTO inventario_motos (" . implode(',', $f) . ") VALUES ($placeholders)")
       ->execute($v);
    $motoId = (int)$pdo->lastInsertId();
    $log[] = "inventario_motos: creado (id={$motoId})";
}

?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Seed test client — CONTADO</title>
<style>
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#0b0f14;color:#eef2f7;padding:40px;max-width:600px;margin:0 auto}
.box{background:#11161d;border:1px solid #202a36;border-radius:12px;padding:24px;margin-bottom:16px}
h1{color:#22d37a;margin:0 0 16px}
.row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #202a36;gap:12px}
.row:last-child{border-bottom:0}
.k{color:#9aa7b7}.v{font-weight:700;text-align:right;word-break:break-all}
.log{background:#0b0f14;border:1px solid #202a36;border-radius:8px;padding:12px;font-family:monospace;font-size:12px;color:#b7f2cf;margin-top:12px;line-height:1.7}
a.btn{display:inline-block;background:#22d37a;color:#04120a;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:700;margin-top:12px}
.warn{background:rgba(245,179,1,.1);border:1px solid rgba(245,179,1,.35);color:#ffe19a;padding:12px;border-radius:8px;margin-top:16px;font-size:13px}
.tag{display:inline-block;background:#039fe1;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;letter-spacing:.5px}
</style></head><body>

<h1>✅ Test client CONTADO listo <span class="tag">CONTADO</span></h1>

<div class="box">
  <div class="row"><span class="k">Teléfono (login)</span><span class="v"><?= htmlspecialchars($TEST_TEL) ?></span></div>
  <div class="row"><span class="k">Email</span><span class="v"><?= htmlspecialchars($TEST_EMAIL) ?></span></div>
  <div class="row"><span class="k">Nombre</span><span class="v"><?= htmlspecialchars($TEST_NOMBRE) ?></span></div>
  <div class="row"><span class="k">cliente_id</span><span class="v"><?= $cid ?></span></div>
  <div class="row"><span class="k">transaccion_id</span><span class="v"><?= $txId ?></span></div>
  <div class="row"><span class="k">moto_id</span><span class="v"><?= $motoId ?></span></div>
  <div class="row"><span class="k">Pedido</span><span class="v"><?= htmlspecialchars($pedidoCorto ?: ('VK-'.$pedidoNum)) ?></span></div>
  <div class="row"><span class="k">Modelo / Color</span><span class="v"><?= htmlspecialchars($TEST_MODELO . ' · ' . $TEST_COLOR) ?></span></div>
  <div class="row"><span class="k">Total pagado</span><span class="v">$<?= number_format($TEST_TOTAL, 2) ?> MXN</span></div>
  <div class="row"><span class="k">Estado moto</span><span class="v" style="color:#22d37a">entregada</span></div>

  <div class="log">
    <?php foreach ($log as $l): ?>
      → <?= htmlspecialchars($l) ?><br>
    <?php endforeach; ?>
  </div>

  <a class="btn" href="/clientes/">Ir al portal →</a>
</div>

<div class="box">
  <h3 style="margin:0 0 12px;color:#22d37a">Cómo probar</h3>
  <ol style="line-height:1.8;padding-left:20px">
    <li>Abre <a href="/clientes/" style="color:#22d37a">voltika.mx/clientes/</a> en una <b>ventana de incógnito</b> (para no chocar con la sesión actual)</li>
    <li>Ingresa el teléfono <b><?= htmlspecialchars($TEST_TEL) ?></b> o email <b><?= htmlspecialchars($TEST_EMAIL) ?></b></li>
    <li>Si el SMS falla (número ficticio), aparecerá un banner amarillo "<i>Código de prueba: XXXXXX</i>" — úsalo para entrar</li>
    <li>Verifica:
      <ul style="margin-top:6px;line-height:1.6;color:#b7f2cf">
        <li>Sidebar <b>SIN "Pagos"</b></li>
        <li>Inicio = nuevo wireframe (foto moto 4:3 + specs + RESUMEN DE PAGO)</li>
      </ul>
    </li>
  </ol>
</div>

<div class="warn">
  ⚠ <b>Borra este archivo después de probar</b> (<code>clientes/seed-test-contado.php</code>).
  Permite crear cuentas sin autenticación.
</div>

</body></html>
