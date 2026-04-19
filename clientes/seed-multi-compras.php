<?php
/**
 * One-shot seed — adds 5 extra purchases (mix of contado/MSI/oxxo/spei) plus
 * a credit subscription to the test client so the "Tienes N compras
 * vinculadas" banner appears in Inicio. Use this to test the [Ver todas]
 * button flow.
 *
 *   /clientes/seed-multi-compras.php
 *
 * Default target: contado@voltika.mx (5511112222) — created previously by
 * seed-test-contado.php. Override with ?email= and/or ?telefono=.
 *
 * ⚠ Delete after testing.
 */
require_once __DIR__ . '/php/bootstrap.php';

// Top-level error handler: if anything fatal happens, return a readable
// HTML error instead of a blank 500 so the user can see the cause.
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<pre style="font-family:monospace;background:#fee;color:#900;padding:20px;">'
           . htmlspecialchars('FATAL: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']) . '</pre>';
    }
});

header('Content-Type: text/html; charset=utf-8');

$TEST_TEL    = $_GET['telefono'] ?? '5511112222';
$TEST_EMAIL  = $_GET['email']    ?? 'contado@voltika.mx';
$TEST_NOMBRE = 'Cliente Multi';

$pdo = getDB();
$log = [];

// ── 1. Ensure clientes row ─────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("SELECT id, nombre FROM clientes WHERE telefono = ? OR email = ? LIMIT 1");
    $stmt->execute([$TEST_TEL, $TEST_EMAIL]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $cid = (int)$row['id'];
        $existingName = trim((string)($row['nombre'] ?? ''));
        if ($existingName && strtolower($existingName) !== 'cliente') $TEST_NOMBRE = $existingName;
        $log[] = "clientes: encontrado (id={$cid}, nombre={$TEST_NOMBRE})";
    } else {
        $pdo->prepare("INSERT INTO clientes (nombre, telefono, email) VALUES (?,?,?)")
           ->execute([$TEST_NOMBRE, $TEST_TEL, $TEST_EMAIL]);
        $cid = (int)$pdo->lastInsertId();
        $log[] = "clientes: creado (id={$cid})";
    }
} catch (Throwable $e) {
    $log[] = "clientes ERROR: " . $e->getMessage();
    $cid  = 0;
}

// ── Helper: ensure a transaccion (idempotent on pedido) ────────────────────
function ensureTx(PDO $pdo, string $pedido, array $data, array &$log): void {
    try {
        $stmt = $pdo->prepare("SELECT id FROM transacciones WHERE pedido = ? LIMIT 1");
        $stmt->execute([$pedido]);
        if ($stmt->fetchColumn()) { $log[] = "tx [{$pedido}]: ya existía"; return; }

        $cols = $pdo->query("SHOW COLUMNS FROM transacciones")->fetchAll(PDO::FETCH_COLUMN);
        $colSet = array_flip($cols);

        $fields = ['pedido','nombre','email','telefono','modelo','color','total','tpago','pago_estado'];
        $values = [$pedido, $data['nombre'], $data['email'], $data['telefono'],
                   $data['modelo'], $data['color'], $data['total'], $data['tpago'], $data['pago_estado']];
        if (isset($colSet['freg']))         { $fields[] = 'freg';         $values[] = $data['fecha'] ?? date('Y-m-d H:i:s'); }
        if (isset($colSet['ciudad']))       { $fields[] = 'ciudad';       $values[] = 'Ciudad de México'; }
        if (isset($colSet['estado']))       { $fields[] = 'estado';       $values[] = 'Distrito Federal'; }
        if (isset($colSet['cp']))           { $fields[] = 'cp';           $values[] = '11700'; }
        if (isset($colSet['punto_nombre']) && !empty($data['punto'])) {
            $fields[] = 'punto_nombre'; $values[] = $data['punto'];
        }
        if (isset($colSet['msi_meses']) && !empty($data['msi_meses'])) {
            $fields[] = 'msi_meses'; $values[] = $data['msi_meses'];
        }

        $placeholders = implode(',', array_fill(0, count($fields), '?'));
        $pdo->prepare("INSERT INTO transacciones (" . implode(',', $fields) . ") VALUES ($placeholders)")
           ->execute($values);
        $log[] = "tx [{$pedido}]: creado";
    } catch (Throwable $e) {
        $log[] = "tx [{$pedido}] ERROR: " . $e->getMessage();
    }
}

// ── 2. Seed 5 transacciones with variety ──────────────────────────────────
$base = ['nombre' => $TEST_NOMBRE, 'email' => $TEST_EMAIL, 'telefono' => $TEST_TEL, 'pago_estado' => 'pagada'];

ensureTx($pdo, 'TEST-MULTI-CONTADO-1', array_merge($base, [
    'modelo' => 'M03', 'color' => 'negro',  'total' => 27999, 'tpago' => 'contado',
    'punto'  => 'Voltika QRO Centro', 'fecha' => date('Y-m-d H:i:s', strtotime('-30 days')),
]), $log);
ensureTx($pdo, 'TEST-MULTI-MSI-1', array_merge($base, [
    'modelo' => 'M05', 'color' => 'gris',   'total' => 29999, 'tpago' => 'msi', 'msi_meses' => 12,
    'punto'  => 'Voltika Center Santa Fe', 'fecha' => date('Y-m-d H:i:s', strtotime('-20 days')),
]), $log);
ensureTx($pdo, 'TEST-MULTI-OXXO-1', array_merge($base, [
    'modelo' => 'MC10', 'color' => 'negro', 'total' => 38500, 'tpago' => 'oxxo',
    'punto'  => 'Race Moto Taller',  'fecha' => date('Y-m-d H:i:s', strtotime('-12 days')),
]), $log);
ensureTx($pdo, 'TEST-MULTI-SPEI-1', array_merge($base, [
    'modelo' => 'Ukko S+', 'color' => 'plata','total' => 89900,'tpago' => 'spei',
    'punto'  => 'S2R Chalco', 'fecha' => date('Y-m-d H:i:s', strtotime('-5 days')),
]), $log);
ensureTx($pdo, 'TEST-MULTI-CONTADO-2', array_merge($base, [
    'modelo' => 'mino B', 'color' => 'azul', 'total' => 18999, 'tpago' => 'contado',
    'punto'  => 'El Samito', 'fecha' => date('Y-m-d H:i:s', strtotime('-1 days')),
]), $log);

// ── 3. Optional credit subscription ────────────────────────────────────────
try {
    $sub = $pdo->prepare("SELECT id FROM subscripciones_credito
                           WHERE cliente_id = ? OR email = ? OR telefono = ? LIMIT 1");
    $sub->execute([$cid, $TEST_EMAIL, $TEST_TEL]);
    if (!$sub->fetchColumn()) {
        $sCols = $pdo->query("SHOW COLUMNS FROM subscripciones_credito")->fetchAll(PDO::FETCH_COLUMN);
        $sSet = array_flip($sCols);

        $sf = ['nombre','email','telefono'];
        $sv = [$TEST_NOMBRE, $TEST_EMAIL, $TEST_TEL];
        if (isset($sSet['cliente_id']))     { $sf[] = 'cliente_id';     $sv[] = $cid; }
        if (isset($sSet['modelo']))         { $sf[] = 'modelo';         $sv[] = 'Pesgo plus'; }
        if (isset($sSet['color']))          { $sf[] = 'color';          $sv[] = 'rojo'; }
        if (isset($sSet['monto_semanal']))  { $sf[] = 'monto_semanal';  $sv[] = 850; }
        if (isset($sSet['precio_contado'])) { $sf[] = 'precio_contado'; $sv[] = 45000; }
        if (isset($sSet['plazo_meses']))    { $sf[] = 'plazo_meses';    $sv[] = 12; }
        if (isset($sSet['fecha_inicio']))   { $sf[] = 'fecha_inicio';   $sv[] = date('Y-m-d', strtotime('-15 days')); }
        if (isset($sSet['estado']))         { $sf[] = 'estado';         $sv[] = 'activa'; }

        $ph = implode(',', array_fill(0, count($sf), '?'));
        $pdo->prepare("INSERT INTO subscripciones_credito (".implode(',', $sf).") VALUES ($ph)")
           ->execute($sv);
        $log[] = "subscripciones_credito: creado (Pesgo plus rojo)";
    } else {
        $log[] = "subscripciones_credito: ya tiene una";
    }
} catch (Throwable $e) {
    $log[] = "subscripciones_credito error: " . $e->getMessage();
}

// ── 4. Tally totals (defensive) ────────────────────────────────────────────
$totalTx = 0; $totalSub = 0;
try {
    $q = $pdo->prepare("SELECT COUNT(*) FROM transacciones WHERE email = ? OR telefono = ?");
    $q->execute([$TEST_EMAIL, $TEST_TEL]);
    $totalTx = (int)$q->fetchColumn();
} catch (Throwable $e) { $log[] = "count tx error: " . $e->getMessage(); }
try {
    $q = $pdo->prepare("SELECT COUNT(*) FROM subscripciones_credito
                         WHERE cliente_id = ? OR email = ? OR telefono = ?");
    $q->execute([$cid, $TEST_EMAIL, $TEST_TEL]);
    $totalSub = (int)$q->fetchColumn();
} catch (Throwable $e) { $log[] = "count sub error: " . $e->getMessage(); }

?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Seed multi-compras</title>
<style>
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#0b0f14;color:#eef2f7;padding:40px;max-width:680px;margin:0 auto}
.box{background:#11161d;border:1px solid #202a36;border-radius:12px;padding:24px;margin-bottom:16px}
h1{color:#22d37a;margin:0 0 16px}
.row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #202a36;gap:12px}
.row:last-child{border-bottom:0}
.k{color:#9aa7b7}.v{font-weight:700;text-align:right}
.log{background:#0b0f14;border:1px solid #202a36;border-radius:8px;padding:12px;font-family:monospace;font-size:12px;color:#b7f2cf;margin-top:12px;line-height:1.7}
.log .err{color:#ff8c8c}
a.btn{display:inline-block;background:#22d37a;color:#04120a;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:700;margin-top:12px;margin-right:8px}
a.btn.alt{background:#3b82f6;color:#fff}
.warn{background:rgba(245,179,1,.1);border:1px solid rgba(245,179,1,.35);color:#ffe19a;padding:12px;border-radius:8px;margin-top:16px;font-size:13px}
</style></head><body>

<h1>✅ Multi-compras listas</h1>

<div class="box">
  <div class="row"><span class="k">cliente_id</span><span class="v"><?= $cid ?></span></div>
  <div class="row"><span class="k">Email (login)</span><span class="v"><?= htmlspecialchars($TEST_EMAIL) ?></span></div>
  <div class="row"><span class="k">Teléfono (login)</span><span class="v"><?= htmlspecialchars($TEST_TEL) ?></span></div>
  <div class="row"><span class="k">Nombre</span><span class="v"><?= htmlspecialchars($TEST_NOMBRE) ?></span></div>
  <div class="row"><span class="k">Total transacciones</span><span class="v"><?= $totalTx ?></span></div>
  <div class="row"><span class="k">Total subscripciones</span><span class="v"><?= $totalSub ?></span></div>
  <div class="row"><span class="k">Total compras visibles</span><span class="v" style="color:#22d37a"><?= $totalTx + $totalSub ?></span></div>

  <div class="log">
    <?php foreach ($log as $l): ?>
      <?php $isErr = stripos($l, 'error') !== false || stripos($l, 'ERROR') !== false; ?>
      <span class="<?= $isErr?'err':'' ?>">→ <?= htmlspecialchars($l) ?></span><br>
    <?php endforeach; ?>
  </div>

  <a class="btn" href="/clientes/">Ir al portal →</a>
  <a class="btn alt" href="?email=<?= urlencode($TEST_EMAIL) ?>&telefono=<?= urlencode($TEST_TEL) ?>">Re-ejecutar</a>
</div>

<div class="box">
  <h3 style="margin:0 0 12px;color:#22d37a">Cómo probar</h3>
  <ol style="line-height:1.8;padding-left:20px">
    <li>Abre <a href="/clientes/" style="color:#22d37a">voltika.mx/clientes/</a> en una <b>ventana de incógnito</b></li>
    <li>Login con teléfono <b><?= htmlspecialchars($TEST_TEL) ?></b> o email <b><?= htmlspecialchars($TEST_EMAIL) ?></b></li>
    <li>Después del login deberías ver el banner azul "<i>Tienes N compras vinculadas</i>" arriba</li>
    <li>Click en <b>Ver todas</b> → debe ir a Mis compras y mostrar todas las unidades</li>
  </ol>
</div>

<div class="warn">
  ⚠ <b>Borra este archivo después de probar</b> (<code>clientes/seed-multi-compras.php</code>).
</div>

</body></html>
