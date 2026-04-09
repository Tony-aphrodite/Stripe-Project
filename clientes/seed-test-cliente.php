<?php
/**
 * One-shot seed script — creates a test client for portal testing.
 *
 * Usage: browse to https://voltika.mx/clientes/seed-test-cliente.php
 * Then login at https://voltika.mx/clientes/ with the phone shown.
 *
 * ⚠ DELETE THIS FILE after testing — it exposes account creation.
 */
require_once __DIR__ . '/php/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');

// ── Edit these if you want a different test identity ────────────────────────
$TEST_TEL    = '5500000000';
$TEST_NOMBRE = 'Cliente Prueba';
$TEST_EMAIL  = 'prueba@voltika.mx';
$TEST_MODELO = 'Voltika S1';
$TEST_COLOR  = 'Negro';

$pdo = getDB();
$log = [];

// ── 1. Ensure clientes row ─────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT id FROM clientes WHERE telefono=? LIMIT 1");
$stmt->execute([$TEST_TEL]);
$cid = (int)($stmt->fetchColumn() ?: 0);

if ($cid) {
    $log[] = "clientes: ya existe (id={$cid})";
} else {
    $stmt = $pdo->prepare("INSERT INTO clientes (nombre, telefono, email) VALUES (?,?,?)");
    $stmt->execute([$TEST_NOMBRE, $TEST_TEL, $TEST_EMAIL]);
    $cid = (int)$pdo->lastInsertId();
    $log[] = "clientes: creado (id={$cid})";
}

// ── 2. Ensure an active credit subscription so portal has data to show ─────
$subCols = [];
try { $subCols = $pdo->query("SHOW COLUMNS FROM subscripciones_credito")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}

$stmt = $pdo->prepare("SELECT id FROM subscripciones_credito WHERE telefono=? ORDER BY id DESC LIMIT 1");
$stmt->execute([$TEST_TEL]);
$sid = (int)($stmt->fetchColumn() ?: 0);

if (!$sid) {
    // Build insert dynamically based on which columns exist
    $fields = ['nombre', 'telefono', 'email'];
    $values = [$TEST_NOMBRE, $TEST_TEL, $TEST_EMAIL];

    if (in_array('cliente_id', $subCols))       { $fields[] = 'cliente_id';       $values[] = $cid; }
    if (in_array('modelo', $subCols))           { $fields[] = 'modelo';           $values[] = $TEST_MODELO; }
    if (in_array('color', $subCols))            { $fields[] = 'color';            $values[] = $TEST_COLOR; }
    if (in_array('monto_semanal', $subCols))    { $fields[] = 'monto_semanal';    $values[] = 850.00; }
    if (in_array('precio_contado', $subCols))   { $fields[] = 'precio_contado';   $values[] = 45000.00; }
    if (in_array('plazo_meses', $subCols))      { $fields[] = 'plazo_meses';      $values[] = 12; }
    if (in_array('plazo_semanas', $subCols))    { $fields[] = 'plazo_semanas';    $values[] = 52; }
    if (in_array('fecha_inicio', $subCols))     { $fields[] = 'fecha_inicio';     $values[] = date('Y-m-d'); }
    if (in_array('estado', $subCols))           { $fields[] = 'estado';           $values[] = 'activa'; }
    if (in_array('status', $subCols))           { $fields[] = 'status';           $values[] = 'activa'; }

    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $sql = "INSERT INTO subscripciones_credito (" . implode(',', $fields) . ") VALUES ($placeholders)";
    $pdo->prepare($sql)->execute($values);
    $sid = (int)$pdo->lastInsertId();
    $log[] = "subscripciones_credito: creado (id={$sid})";
} else {
    // Ensure cliente_id link
    if (in_array('cliente_id', $subCols)) {
        $pdo->prepare("UPDATE subscripciones_credito SET cliente_id=? WHERE id=? AND (cliente_id IS NULL OR cliente_id=0)")
            ->execute([$cid, $sid]);
    }
    $log[] = "subscripciones_credito: ya existe (id={$sid})";
}

?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Seed test client</title>
<style>
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#0b0f14;color:#eef2f7;padding:40px;max-width:600px;margin:0 auto}
.box{background:#11161d;border:1px solid #202a36;border-radius:12px;padding:24px;margin-bottom:16px}
h1{color:#22d37a;margin:0 0 16px}
.row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #202a36}
.row:last-child{border-bottom:0}
.k{color:#9aa7b7}.v{font-weight:700}
.log{background:#0b0f14;border:1px solid #202a36;border-radius:8px;padding:12px;font-family:monospace;font-size:12px;color:#b7f2cf;margin-top:12px}
a.btn{display:inline-block;background:#22d37a;color:#04120a;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:700;margin-top:12px}
.warn{background:rgba(245,179,1,.1);border:1px solid rgba(245,179,1,.35);color:#ffe19a;padding:12px;border-radius:8px;margin-top:16px;font-size:13px}
</style></head><body>

<h1>✅ Test client listo</h1>

<div class="box">
  <div class="row"><span class="k">Teléfono (login)</span><span class="v"><?= htmlspecialchars($TEST_TEL) ?></span></div>
  <div class="row"><span class="k">Nombre</span><span class="v"><?= htmlspecialchars($TEST_NOMBRE) ?></span></div>
  <div class="row"><span class="k">Email</span><span class="v"><?= htmlspecialchars($TEST_EMAIL) ?></span></div>
  <div class="row"><span class="k">cliente_id</span><span class="v"><?= $cid ?></span></div>
  <div class="row"><span class="k">subscripcion_id</span><span class="v"><?= $sid ?></span></div>

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
    <li>Abre <a href="/clientes/" style="color:#22d37a">voltika.mx/clientes/</a></li>
    <li>Ingresa el teléfono: <b><?= htmlspecialchars($TEST_TEL) ?></b></li>
    <li>Presiona <b>Recibir código por SMS</b></li>
    <li>Si el SMS falla (número ficticio), aparecerá un banner amarillo
        "<i>Código de prueba: XXXXXX</i>" — úsalo para entrar</li>
  </ol>
</div>

<div class="warn">
  ⚠ <b>Importante:</b> Borra este archivo después de probar
  (<code>clientes/seed-test-cliente.php</code>). Permite crear cuentas sin autenticación.
</div>

</body></html>
