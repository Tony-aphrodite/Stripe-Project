<?php
/**
 * Seed — General Corrections (16 bugs) test data
 *
 * Crea TODO lo necesario para que el cliente pueda probar cada uno de los
 * 16 bugs corregidos del documento General_Corrections_EN.docx.
 *
 * URL:
 *   /tests/general-corrections/seed-test-data.php           (preview — no escribe)
 *   /tests/general-corrections/seed-test-data.php?run=1     (escribe a la DB)
 *   /tests/general-corrections/seed-test-data.php?reset=1   (borra y vuelve a sembrar)
 *
 * ⚠ Test-only — no ejecutar en producción real.
 * ⚠ Las inserciones son idempotentes: re-ejecutar es seguro.
 *
 * Datos de acceso después de sembrar (compartir con tester):
 *   • Cliente portal (móvil):
 *       URL:     https://voltika.mx/clientes/
 *       Login:   teléfono 5500000099  (OTP llega al test_code en logs)
 *
 *   • Punto / Dealer panel:
 *       URL:     https://voltika.mx/configurador/dealer-panel.html
 *       Email:   gc-punto@voltika.mx
 *       Pass:    GcTest1234
 *
 *   • Admin panel:  usar credenciales admin existentes
 */

require_once __DIR__ . '/../../clientes/php/bootstrap.php';

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

header('Content-Type: text/html; charset=utf-8');

$run   = !empty($_GET['run']);
$reset = !empty($_GET['reset']);

$TEL   = '5500000099';
$EMAIL = 'gc-test@voltika.mx';
$NOMBRE = '[GC-TEST] Cliente';

$PUNTO_NOMBRE  = '[GC-TEST] Punto General Corrections';
$PUNTO_CIUDAD  = 'Ciudad de México';
$PUNTO_ESTADO  = 'Distrito Federal';
$PUNTO_DIR     = 'Av. Test #100';
$PUNTO_CP      = '11700';

$DEALER_EMAIL = 'gc-punto@voltika.mx';
$DEALER_NAME  = '[GC-TEST] Operador Punto';
$DEALER_PASS  = 'GcTest1234';

$pdo = getDB();

// Reset support — limpia las filas creadas por este seed para empezar limpio.
function resetGcTest(PDO $pdo, string $tel, string $email): array {
    $msgs = [];
    try {
        // Find the client first.
        $cid = (int)$pdo->query("SELECT id FROM clientes WHERE telefono='$tel' OR email='$email' LIMIT 1")->fetchColumn();
        if ($cid) {
            // Find motos
            $motoIds = $pdo->query("SELECT id FROM inventario_motos WHERE cliente_id=$cid OR cliente_telefono='$tel'")->fetchAll(PDO::FETCH_COLUMN);
            $motoList = $motoIds ? implode(',', array_map('intval', $motoIds)) : '0';
            $msgs[] = "Borrando datos de cid=$cid, motos=($motoList)";
            $pdo->exec("DELETE FROM checklist_entrega_v2 WHERE moto_id IN ($motoList)");
            $pdo->exec("DELETE FROM checklist_ensamble  WHERE moto_id IN ($motoList)");
            $pdo->exec("DELETE FROM checklist_origen    WHERE moto_id IN ($motoList)");
            $pdo->exec("DELETE FROM recepcion_punto     WHERE moto_id IN ($motoList)");
            $pdo->exec("DELETE FROM envios              WHERE moto_id IN ($motoList)");
            $pdo->exec("DELETE FROM entregas            WHERE moto_id IN ($motoList)");
            $pdo->exec("DELETE FROM inventario_motos    WHERE id      IN ($motoList)");
            $pdo->exec("DELETE FROM transacciones       WHERE telefono='$tel' OR email='$email'");
            $pdo->exec("DELETE FROM clientes            WHERE id=$cid");
        }
        $pdo->exec("DELETE FROM dealer_usuarios WHERE email='gc-punto@voltika.mx'");
        $pdo->exec("DELETE FROM puntos_voltika  WHERE nombre LIKE '%GC-TEST%'");
        $msgs[] = "Reset completado";
    } catch (Throwable $e) { $msgs[] = "Reset error: " . $e->getMessage(); }
    return $msgs;
}

$out = [];
if ($reset) {
    $out[] = '<h3 style="color:#dc2626;">RESET</h3>';
    foreach (resetGcTest($pdo, $TEL, $EMAIL) as $m) {
        $out[] = '<div>' . htmlspecialchars($m) . '</div>';
    }
}

$plan = [];   // descripción de lo que se va a hacer (preview mode)
$log  = [];   // resultado real (run mode)

// Helper — column existence cache.
$colsCache = [];
function cols(PDO $pdo, string $t, array &$c): array {
    if (isset($c[$t])) return $c[$t];
    try { $c[$t] = array_flip($pdo->query("SHOW COLUMNS FROM $t")->fetchAll(PDO::FETCH_COLUMN)); }
    catch (Throwable $e) { $c[$t] = []; }
    return $c[$t];
}

// ── 1. clientes ────────────────────────────────────────────────────────
$cid = 0;
try {
    $st = $pdo->prepare("SELECT id FROM clientes WHERE telefono=? OR email=? LIMIT 1");
    $st->execute([$TEL, $EMAIL]);
    $cid = (int)($st->fetchColumn() ?: 0);
    if ($cid) { $log[] = "✓ clientes existe (id=$cid)"; }
    else {
        $plan[] = "clientes: insertar";
        if ($run) {
            $pdo->prepare("INSERT INTO clientes (nombre, telefono, email) VALUES (?,?,?)")
               ->execute([$NOMBRE, $TEL, $EMAIL]);
            $cid = (int)$pdo->lastInsertId();
            $log[] = "✓ clientes creado (id=$cid)";
        }
    }
} catch (Throwable $e) { $log[] = "✗ clientes: " . $e->getMessage(); }

// ── 2. puntos_voltika ──────────────────────────────────────────────────
$puntoId = 0;
try {
    $st = $pdo->prepare("SELECT id FROM puntos_voltika WHERE nombre=? LIMIT 1");
    $st->execute([$PUNTO_NOMBRE]);
    $puntoId = (int)($st->fetchColumn() ?: 0);
    if ($puntoId) { $log[] = "✓ punto existe (id=$puntoId)"; }
    else {
        $plan[] = "puntos_voltika: insertar [GC-TEST] Punto";
        if ($run) {
            $pCols = cols($pdo, 'puntos_voltika', $colsCache);
            $f = ['nombre','activo'];
            $v = [$PUNTO_NOMBRE, 1];
            if (isset($pCols['ciudad']))      { $f[]='ciudad';      $v[]=$PUNTO_CIUDAD; }
            if (isset($pCols['estado']))      { $f[]='estado';      $v[]=$PUNTO_ESTADO; }
            if (isset($pCols['direccion']))   { $f[]='direccion';   $v[]=$PUNTO_DIR; }
            if (isset($pCols['cp']))          { $f[]='cp';          $v[]=$PUNTO_CP; }
            if (isset($pCols['lat']))         { $f[]='lat';         $v[]=19.4326; }
            if (isset($pCols['lng']))         { $f[]='lng';         $v[]=-99.1332; }
            $ph = implode(',', array_fill(0, count($f), '?'));
            $pdo->prepare("INSERT INTO puntos_voltika (".implode(',', $f).") VALUES ($ph)")
               ->execute($v);
            $puntoId = (int)$pdo->lastInsertId();
            $log[] = "✓ punto creado (id=$puntoId)";
        }
    }
} catch (Throwable $e) { $log[] = "✗ punto: " . $e->getMessage(); }

// ── 3. dealer_usuarios (PoS login) ─────────────────────────────────────
$dealerId = 0;
try {
    $st = $pdo->prepare("SELECT id, rol FROM dealer_usuarios WHERE email=? LIMIT 1");
    $st->execute([$DEALER_EMAIL]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $dealerId = $row ? (int)$row['id'] : 0;
    if ($dealerId) {
        // Si fue sembrado con rol='punto' (rechazado por
        // puntosvoltika/php/auth/login.php), corregimos a 'dealer'.
        if (($row['rol'] ?? '') !== 'dealer' && ($row['rol'] ?? '') !== 'admin') {
            if ($run) {
                $pdo->prepare("UPDATE dealer_usuarios SET rol='dealer' WHERE id=?")->execute([$dealerId]);
                $log[] = "✓ dealer existe (id=$dealerId) — rol corregido a 'dealer'";
            } else {
                $log[] = "→ dealer (id=$dealerId) requiere corregir rol a 'dealer'";
            }
        } else {
            $log[] = "✓ dealer existe (id=$dealerId, rol=" . ($row['rol'] ?? '?') . ")";
        }
    } else {
        $plan[] = "dealer_usuarios: $DEALER_EMAIL / $DEALER_PASS (rol=dealer)";
        if ($run) {
            $pwHash = password_hash($DEALER_PASS, PASSWORD_DEFAULT);
            $duCols = cols($pdo, 'dealer_usuarios', $colsCache);
            $f = ['nombre','email','password_hash','rol','activo'];
            // 'dealer' es el rol que puntosvoltika/php/auth/login.php
            // acepta (rol IN ('dealer','admin')). 'punto' es rechazado.
            $v = [$DEALER_NAME, $DEALER_EMAIL, $pwHash, 'dealer', 1];
            if (isset($duCols['punto_id']))   { $f[]='punto_id';   $v[]=$puntoId; }
            if (isset($duCols['punto_nombre'])) { $f[]='punto_nombre'; $v[]=$PUNTO_NOMBRE; }
            $ph = implode(',', array_fill(0, count($f), '?'));
            $pdo->prepare("INSERT INTO dealer_usuarios (".implode(',', $f).") VALUES ($ph)")
               ->execute($v);
            $dealerId = (int)$pdo->lastInsertId();
            $log[] = "✓ dealer creado (id=$dealerId, rol=dealer)";
        }
    }
} catch (Throwable $e) { $log[] = "✗ dealer: " . $e->getMessage(); }

// ── Helpers ────────────────────────────────────────────────────────────
// Crea (o reusa) una transacción contado para una moto.
function ensureTx(PDO $pdo, array $colsCache, string $pedido, string $nombre, string $email, string $tel, string $modelo, string $color, int $total, string $puntoNombre, bool $run, array &$log): int {
    $st = $pdo->prepare("SELECT id FROM transacciones WHERE pedido=? LIMIT 1");
    $st->execute([$pedido]);
    $id = (int)($st->fetchColumn() ?: 0);
    if ($id) { $log[] = "✓ tx $pedido existe ($id)"; return $id; }
    if (!$run) { $log[] = "→ tx $pedido se creará"; return 0; }
    $tCols = cols($pdo, 'transacciones', $colsCache);
    $f = ['pedido','nombre','email','telefono','modelo','color','total','tpago','pago_estado'];
    $v = [$pedido, $nombre, $email, $tel, $modelo, $color, $total, 'contado', 'pagada'];
    if (isset($tCols['stripe_pi']))    { $f[]='stripe_pi';    $v[]='pi_'.$pedido; }
    if (isset($tCols['ciudad']))       { $f[]='ciudad';       $v[]='Ciudad de México'; }
    if (isset($tCols['estado']))       { $f[]='estado';       $v[]='Distrito Federal'; }
    if (isset($tCols['cp']))           { $f[]='cp';           $v[]='11700'; }
    if (isset($tCols['punto_nombre'])) { $f[]='punto_nombre'; $v[]=$puntoNombre; }
    if (isset($tCols['environment']))  { $f[]='environment';  $v[]='test'; }
    if (isset($tCols['notas_admin']))  { $f[]='notas_admin';  $v[]='[GC-TEST] seed'; }
    $ph = implode(',', array_fill(0, count($f), '?'));
    $pdo->prepare("INSERT INTO transacciones (".implode(',', $f).") VALUES ($ph)")->execute($v);
    $newId = (int)$pdo->lastInsertId();
    $log[] = "✓ tx $pedido creado ($newId)";
    return $newId;
}

// Crea (o reusa) una moto. Si ya existe pero su estado actual está vacío
// (caso típico cuando un seed previo intentó usar un valor ENUM inválido
// como 'checklist_ok' o 'asignada'), corrige al estado deseado mediante
// UPDATE — siempre que el nuevo valor SÍ sea aceptado por el ENUM.
function ensureMoto(PDO $pdo, array $colsCache, string $vin, string $modelo, string $color, string $estado, ?int $puntoId, ?int $cid, string $pedidoNum, ?string $clienteNombre, ?string $clienteEmail, ?string $clienteTel, string $numMotor, bool $run, array &$log): int {
    $st = $pdo->prepare("SELECT id, estado FROM inventario_motos WHERE vin=? LIMIT 1");
    $st->execute([$vin]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $id = $row ? (int)$row['id'] : 0;
    if ($id) {
        $currentEstado = (string)($row['estado'] ?? '');
        // Re-apply estado if it's empty (failed prior insert) AND we're in run mode.
        if ($run && $currentEstado === '' && $estado !== '') {
            try {
                $upd = $pdo->prepare("UPDATE inventario_motos SET estado=? WHERE id=?");
                $upd->execute([$estado, $id]);
                // Re-read to confirm the new value stuck.
                $newEstado = (string)$pdo->query("SELECT estado FROM inventario_motos WHERE id=$id")->fetchColumn();
                $log[] = "✓ moto $vin existe ($id) — estado vacío corregido a '$newEstado'";
            } catch (Throwable $e) {
                $log[] = "✗ moto $vin estado update: " . $e->getMessage();
            }
        } else {
            $log[] = "✓ moto $vin existe ($id, estado=$currentEstado)";
        }
        return $id;
    }
    if (!$run) { $log[] = "→ moto $vin se creará (estado=$estado)"; return 0; }
    $imCols = cols($pdo, 'inventario_motos', $colsCache);
    $f = ['vin','modelo','color','estado','activo','num_motor'];
    $v = [$vin, $modelo, $color, $estado, 1, $numMotor];
    if (isset($imCols['vin_display']))      { $f[]='vin_display';      $v[]=$vin; }
    if (isset($imCols['anio_modelo']))      { $f[]='anio_modelo';      $v[]='2026'; }
    if ($puntoId && isset($imCols['punto_voltika_id'])) { $f[]='punto_voltika_id'; $v[]=$puntoId; }
    if ($cid && isset($imCols['cliente_id']))            { $f[]='cliente_id';      $v[]=$cid; }
    if ($clienteNombre && isset($imCols['cliente_nombre'])) { $f[]='cliente_nombre'; $v[]=$clienteNombre; }
    if ($clienteEmail  && isset($imCols['cliente_email']))  { $f[]='cliente_email';  $v[]=$clienteEmail; }
    if ($clienteTel    && isset($imCols['cliente_telefono'])){$f[]='cliente_telefono';$v[]=$clienteTel; }
    if ($pedidoNum     && isset($imCols['pedido_num']))     { $f[]='pedido_num';     $v[]=$pedidoNum; }
    if (isset($imCols['pago_estado']))      { $f[]='pago_estado';      $v[]='pagada'; }
    if (isset($imCols['precio_venta']))     { $f[]='precio_venta';     $v[]=9975; }
    if (isset($imCols['fecha_estado']))     { $f[]='fecha_estado';     $v[]=date('Y-m-d H:i:s'); }
    $ph = implode(',', array_fill(0, count($f), '?'));
    $pdo->prepare("INSERT INTO inventario_motos (".implode(',', $f).") VALUES ($ph)")->execute($v);
    $newId = (int)$pdo->lastInsertId();
    $log[] = "✓ moto $vin creado ($newId, estado=$estado)";
    return $newId;
}

// ── 4. Las 7 motos de prueba ───────────────────────────────────────────

// Moto 1: ENTREGADA — para Bug 5.8 (botón Confirmar recepción debe estar oculto)
$tx1     = ensureTx($pdo, $colsCache, 'GCTEST-1', $NOMBRE, $EMAIL, $TEL, 'M03', 'negro', 9975, $PUNTO_NOMBRE, $run, $log);
$moto1   = ensureMoto($pdo, $colsCache, 'GCTESTVIN0000001', 'M03', 'negro', 'entregada', $puntoId, $cid, 'VK-GCTEST-1', $NOMBRE, $EMAIL, $TEL, 'GCMOTOR0000001', $run, $log);

// Moto 2: para Bug 5.7 (Cincel ACTA)
//   `inventario_motos.estado` es un ENUM que NO acepta 'checklist_ok'
//   (MySQL silenciosamente lo convierte a ''). Usamos 'lista_para_entrega'
//   (valor válido) y dejamos que `checklist_entrega_v2.vin_coincide=1`
//   sembrado más abajo dispare estado_ui='checklist_ok' en estado.php.
$tx2     = ensureTx($pdo, $colsCache, 'GCTEST-2', $NOMBRE, $EMAIL, $TEL, 'M05', 'gris', 14500, $PUNTO_NOMBRE, $run, $log);
$moto2   = ensureMoto($pdo, $colsCache, 'GCTESTVIN0000002', 'M05', 'gris', 'lista_para_entrega', $puntoId, $cid, 'VK-GCTEST-2', $NOMBRE, $EMAIL, $TEL, 'GCMOTOR0000002', $run, $log);

// Moto 3: LISTA_PARA_ENTREGA — para Bugs 5.1, 5.2, 5.3, 5.4, 5.6 (PoS empieza la entrega)
$tx3     = ensureTx($pdo, $colsCache, 'GCTEST-3', $NOMBRE, $EMAIL, $TEL, 'Pesgo plus', 'rojo', 48260, $PUNTO_NOMBRE, $run, $log);
$moto3   = ensureMoto($pdo, $colsCache, 'GCTESTVIN0000003', 'Pesgo plus', 'rojo', 'lista_para_entrega', $puntoId, $cid, 'VK-GCTEST-3', $NOMBRE, $EMAIL, $TEL, 'GCMOTOR0000003', $run, $log);

// Moto 4: RECIBIDA — para Bug 4.1 (Ensamble photos sync admin↔PoS)
$moto4   = ensureMoto($pdo, $colsCache, 'GCTESTVIN0000004', 'Ukko-S', 'verde', 'recibida', $puntoId, null, '', null, null, null, 'GCMOTOR0000004', $run, $log);

// Moto 5: ENVIADA + envio activo — para Bugs 3.1, 3.2, 3.3 (Recepción)
$moto5   = ensureMoto($pdo, $colsCache, 'GCTESTVIN0000005', 'M05', 'azul', 'por_llegar', $puntoId, null, '', null, null, null, 'GCMOTOR0000005', $run, $log);

// Moto 6: SIN ENVIO — para Bug 3.4 (PENDIENTE DE ASIGNACIÓN). Igual que
// moto 2, 'asignada' no es un ENUM válido. Usamos 'por_llegar' (válido) y
// confiamos en el filtro de envios-pendientes.php (estado='por_llegar' AND
// NO active envio) para identificar motos pendientes.
$moto6   = ensureMoto($pdo, $colsCache, 'GCTESTVIN0000006', 'M03', 'plata', 'por_llegar', $puntoId, null, '', null, null, null, 'GCMOTOR0000006', $run, $log);

// Moto 7: POR_LLEGAR + en envío "lista_para_enviar" — para Bugs 1.1, 1.2, 2.1, 2.2
//         (el admin abrirá el Checklist de Origen y el modal "Marcar enviada")
$moto7   = ensureMoto($pdo, $colsCache, 'GCTESTVIN0000007', 'MC10', 'naranja', 'por_llegar', $puntoId, null, '', null, null, null, 'GCMOTOR0000007', $run, $log);

// ── 5. envios para motos 5 y 7 ─────────────────────────────────────────
function ensureEnvio(PDO $pdo, array $colsCache, int $motoId, int $puntoId, string $estado, ?string $tracking, ?string $carrier, ?string $fechaEnvio, ?string $eta, bool $run, array &$log) {
    if (!$motoId || !$puntoId) return;
    $st = $pdo->prepare("SELECT id FROM envios WHERE moto_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([$motoId]);
    if ($st->fetchColumn()) { $log[] = "✓ envio moto=$motoId existe"; return; }
    if (!$run) { $log[] = "→ envio moto=$motoId se creará (estado=$estado)"; return; }
    $eCols = cols($pdo, 'envios', $colsCache);
    $f = ['moto_id','punto_destino_id','estado'];
    $v = [$motoId, $puntoId, $estado];
    if ($tracking && isset($eCols['tracking_number']))     { $f[]='tracking_number';     $v[]=$tracking; }
    if ($carrier  && isset($eCols['carrier']))             { $f[]='carrier';             $v[]=$carrier; }
    if ($fechaEnvio && isset($eCols['fecha_envio']))       { $f[]='fecha_envio';         $v[]=$fechaEnvio; }
    if ($eta && isset($eCols['fecha_estimada_llegada']))   { $f[]='fecha_estimada_llegada'; $v[]=$eta; }
    if (isset($eCols['notas']))                            { $f[]='notas';               $v[]='[GC-TEST] seed envio'; }
    $ph = implode(',', array_fill(0, count($f), '?'));
    $pdo->prepare("INSERT INTO envios (".implode(',', $f).") VALUES ($ph)")->execute($v);
    $log[] = "✓ envio moto=$motoId creado (estado=$estado)";
}

// Moto 5 — En tránsito (enviada). Con tracking + carrier para Bug 3.2 (info visible).
ensureEnvio($pdo, $colsCache, $moto5, $puntoId, 'enviada',
    'GCTRK-5-MOTO', 'Estafeta', date('Y-m-d', strtotime('-2 days')), date('Y-m-d', strtotime('+1 day')), $run, $log);

// Moto 7 — Lista para enviar (Bug 2.1, 2.2 — admin la marcará como enviada en el modal).
ensureEnvio($pdo, $colsCache, $moto7, $puntoId, 'lista_para_enviar',
    null, null, null, date('Y-m-d', strtotime('+5 days')), $run, $log);

// ── 5b. checklist_entrega_v2 para moto 2 — necesario para que el portal
//        cliente reporte estado_ui='checklist_ok' y aparezca el botón de
//        firma con Cincel (Bug 5.7).
//        Lógica de estado.php:
//          if (checklist.vin_coincide) → estado_ui = 'checklist_ok'
//        Por eso sembramos vin_coincide=1 + las 4 fases de "verificar".
if ($moto2 && $run) {
    try {
        $st = $pdo->prepare("SELECT id FROM checklist_entrega_v2 WHERE moto_id=? LIMIT 1");
        $st->execute([$moto2]);
        if (!$st->fetchColumn()) {
            $ceCols = cols($pdo, 'checklist_entrega_v2', $colsCache);
            $f = ['moto_id'];
            $v = [$moto2];
            // Campo crítico para que estado_ui salga 'checklist_ok'
            if (isset($ceCols['vin_coincide']))    { $f[]='vin_coincide';    $v[]=1; }
            if (isset($ceCols['estado_fisico_ok'])){ $f[]='estado_fisico_ok'; $v[]=1; }
            if (isset($ceCols['sin_danos']))       { $f[]='sin_danos';       $v[]=1; }
            if (isset($ceCols['unidad_completa'])) { $f[]='unidad_completa'; $v[]=1; }
            if (isset($ceCols['fase1_completada'])){ $f[]='fase1_completada'; $v[]=1; }
            if (isset($ceCols['fase3_completada'])){ $f[]='fase3_completada'; $v[]=1; }
            $ph = implode(',', array_fill(0, count($f), '?'));
            $pdo->prepare("INSERT INTO checklist_entrega_v2 (".implode(',', $f).") VALUES ($ph)")->execute($v);
            $log[] = "✓ checklist_entrega_v2 moto=$moto2 sembrado (vin_coincide=1)";
        } else {
            $log[] = "✓ checklist_entrega_v2 moto=$moto2 ya existe";
        }
    } catch (Throwable $e) { $log[] = "✗ checklist_entrega_v2 moto=$moto2: " . $e->getMessage(); }
}

// ── 5c. entregas para moto 2 — cubre el path de OTP/face que estado.php
//        también consulta. Sin esta fila, la respuesta del endpoint no incluye
//        los timestamps de los pasos previos (es informativo, no bloquea
//        estado_ui='checklist_ok').
if ($moto2 && $run) {
    try {
        $st = $pdo->prepare("SELECT id FROM entregas WHERE moto_id=? LIMIT 1");
        $st->execute([$moto2]);
        if (!$st->fetchColumn()) {
            $entCols = cols($pdo, 'entregas', $colsCache);
            $f = ['moto_id', 'estado'];
            $v = [$moto2, 'confirmado']; // OTP ya verificado
            if (isset($entCols['otp_verified']))   { $f[]='otp_verified';   $v[]=1; }
            if (isset($entCols['cliente_nombre'])) { $f[]='cliente_nombre'; $v[]=$NOMBRE; }
            if (isset($entCols['cliente_telefono'])) { $f[]='cliente_telefono'; $v[]=$TEL; }
            if (isset($entCols['cliente_email']))   { $f[]='cliente_email';  $v[]=$EMAIL; }
            if (isset($entCols['pedido_num']))     { $f[]='pedido_num';     $v[]='VK-GCTEST-2'; }
            $ph = implode(',', array_fill(0, count($f), '?'));
            $pdo->prepare("INSERT INTO entregas (".implode(',', $f).") VALUES ($ph)")->execute($v);
            $log[] = "✓ entregas moto=$moto2 sembrado (estado=confirmado, otp_verified=1)";
        } else {
            $log[] = "✓ entregas moto=$moto2 ya existe";
        }
    } catch (Throwable $e) { $log[] = "✗ entregas moto=$moto2: " . $e->getMessage(); }
}

// ── 5d. recepcion_punto para motos 2 y 3 — necesario para que aparezcan en
//        "Entregar al cliente" (puntosvoltika/php/inventario/listar.php
//        agrupa en `inventario_entrega` solo motos con recepción + cliente).
foreach ([
    // moto_id, vin
    [$moto2, 'GCTESTVIN0000002'],
    [$moto3, 'GCTESTVIN0000003'],
] as $pair) {
    [$mid, $vin] = $pair;
    if (!$mid || !$run) continue;
    try {
        $st = $pdo->prepare("SELECT id FROM recepcion_punto WHERE moto_id=? LIMIT 1");
        $st->execute([$mid]);
        if ($st->fetchColumn()) {
            $log[] = "✓ recepcion_punto moto=$mid ya existe";
            continue;
        }
        $rpCols = cols($pdo, 'recepcion_punto', $colsCache);
        $f = ['moto_id', 'punto_id', 'vin_escaneado', 'vin_coincide'];
        $v = [$mid, $puntoId, $vin, 1];
        if (isset($rpCols['recibido_por']))          { $f[]='recibido_por';          $v[]=$dealerId ?: 1; }
        if (isset($rpCols['estado_fisico_ok']))      { $f[]='estado_fisico_ok';      $v[]=1; }
        if (isset($rpCols['sin_danos']))             { $f[]='sin_danos';             $v[]=1; }
        if (isset($rpCols['componentes_completos']))  { $f[]='componentes_completos'; $v[]=1; }
        if (isset($rpCols['bateria_ok']))            { $f[]='bateria_ok';            $v[]=1; }
        if (isset($rpCols['fotos']))                 { $f[]='fotos';                 $v[]='[]'; }
        if (isset($rpCols['notas']))                 { $f[]='notas';                 $v[]='[GC-TEST] seed reception'; }
        if (isset($rpCols['completado']))            { $f[]='completado';            $v[]=1; }
        $ph = implode(',', array_fill(0, count($f), '?'));
        $pdo->prepare("INSERT INTO recepcion_punto (".implode(',', $f).") VALUES ($ph)")->execute($v);
        $log[] = "✓ recepcion_punto moto=$mid sembrado (vin=$vin)";
    } catch (Throwable $e) { $log[] = "✗ recepcion_punto moto=$mid: " . $e->getMessage(); }
}

// ── 6. checklist_origen para moto 5 (origen completado en CEDIS — Bug 3.2 verifier) ──
if ($moto5 && $run) {
    try {
        $st = $pdo->prepare("SELECT id FROM checklist_origen WHERE moto_id=? LIMIT 1");
        $st->execute([$moto5]);
        if (!$st->fetchColumn()) {
            $coCols = cols($pdo, 'checklist_origen', $colsCache);
            $f = ['moto_id','dealer_id','vin','num_motor','modelo','color','completado'];
            $v = [$moto5, $dealerId ?: 1, 'GCTESTVIN0000005', 'GCMOTOR0000005', 'M05', 'azul', 1];
            if (isset($coCols['fecha_inicio']))      { $f[]='fecha_inicio';      $v[]=date('Y-m-d H:i:s', strtotime('-3 days')); }
            if (isset($coCols['fecha_completado'])) { $f[]='fecha_completado'; $v[]=date('Y-m-d H:i:s', strtotime('-2 days')); }
            if (isset($coCols['bloqueado']))         { $f[]='bloqueado';         $v[]=1; }
            if (isset($coCols['hash_registro']))     { $f[]='hash_registro';     $v[]=hash('sha256', 'GCTEST5'); }
            $ph = implode(',', array_fill(0, count($f), '?'));
            $pdo->prepare("INSERT INTO checklist_origen (".implode(',', $f).") VALUES ($ph)")->execute($v);
            $log[] = "✓ checklist_origen moto=$moto5 sembrado (completado=1)";
        }
    } catch (Throwable $e) { $log[] = "✗ checklist_origen moto=$moto5: " . $e->getMessage(); }
}

// ── Output ─────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>GC Test Seed</title>
<style>
  body { font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif; max-width:920px; margin:24px auto; padding:0 16px; color:#111; background:#fff; }
  h1 { color:#1a3a5c; }
  h3 { color:#039fe1; margin-top:28px; padding-bottom:6px; border-bottom:1px solid #e2e8f0; }
  .ok   { color:#16a34a; }
  .err  { color:#dc2626; font-weight:700; }
  .plan { color:#92400e; }
  .card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px; margin-bottom:12px; }
  pre { background:#0f172a; color:#94a3b8; border-radius:6px; padding:10px 12px; font-size:12px; overflow-x:auto; }
  code { background:#f1f5f9; padding:2px 6px; border-radius:3px; font-size:12.5px; }
  table { border-collapse:collapse; width:100%; margin:8px 0; font-size:13px; }
  th, td { border:1px solid #e2e8f0; padding:8px 10px; text-align:left; vertical-align:top; }
  th { background:#f1f5f9; font-weight:700; }
  a.btn { display:inline-block; background:#039fe1; color:#fff; padding:9px 16px; border-radius:6px; text-decoration:none; font-weight:600; margin:4px 6px 4px 0; }
  a.btn.warn { background:#dc2626; }
  a.btn.ghost { background:#fff; color:#039fe1; border:1.5px solid #039fe1; }
</style>
</head><body>

<h1>🧪 GC Test Seed — General Corrections (16 bugs)</h1>

<div class="card">
  <strong>Modo:</strong> <?= $run ? '<span class="ok">RUN (escribe a la DB)</span>' : '<span class="plan">PREVIEW (no escribe)</span>' ?>
  <?= $reset ? ' · <span class="err">+RESET ejecutado</span>' : '' ?>
  <div style="margin-top:10px;">
    <a class="btn ghost" href="?">Preview (no escribe)</a>
    <a class="btn"      href="?run=1">▶ Ejecutar Run</a>
    <a class="btn warn" href="?reset=1&run=1" onclick="return confirm('Borrar y volver a sembrar todos los datos GC-TEST?')">↺ Reset y volver a sembrar</a>
  </div>
</div>

<h3>Salida</h3>
<?php if ($plan): ?>
<div class="card"><strong>Plan (preview):</strong>
<ul><?php foreach ($plan as $p) echo '<li>' . htmlspecialchars($p) . '</li>'; ?></ul></div>
<?php endif; ?>

<?php if ($log): ?>
<div class="card"><strong>Log:</strong>
<ul><?php foreach ($log as $l) {
    $cls = strpos($l, '✗') === 0 ? 'err' : (strpos($l, '✓') === 0 ? 'ok' : '');
    echo '<li class="' . $cls . '">' . htmlspecialchars($l) . '</li>';
} ?></ul></div>
<?php endif; ?>

<?php if ($out): ?>
<div class="card"><?= implode('', $out) ?></div>
<?php endif; ?>

<h3>📋 Datos para tester</h3>
<table>
  <tr><th>Acceso</th><th>URL</th><th>Credenciales</th></tr>
  <tr>
    <td><strong>Cliente Portal</strong> (móvil)</td>
    <td><code>https://voltika.mx/clientes/</code></td>
    <td>Teléfono: <code><?= $TEL ?></code><br>OTP: ver logs del servidor o usar test-code</td>
  </tr>
  <tr>
    <td><strong>Punto Voltika</strong> (PoS)</td>
    <td><code>https://voltika.mx/configurador/dealer-panel.html</code></td>
    <td>Email: <code><?= $DEALER_EMAIL ?></code><br>Pass: <code><?= $DEALER_PASS ?></code></td>
  </tr>
  <tr>
    <td><strong>Admin</strong></td>
    <td><code>https://voltika.mx/admin/</code></td>
    <td>(usar credenciales admin existentes)</td>
  </tr>
</table>

<h3>🐛 Mapeo Bug → Moto a usar</h3>
<table>
  <tr><th>Bug</th><th>Donde testear</th><th>Moto / VIN</th><th>Estado</th></tr>
  <tr><td>5.8 — Botón Confirmar recepción</td><td>Cliente portal</td><td>GCTESTVIN0000001 (M03 negro)</td><td>entregada</td></tr>
  <tr><td><strong>5.7 — Cincel ACTA</strong> (CRITICAL)</td><td>Cliente portal</td><td>GCTESTVIN0000002 (M05 gris)</td><td>checklist_ok</td></tr>
  <tr><td>5.1 — Auto-save + 6h + No exitosa</td><td>PoS — Entregar</td><td>GCTESTVIN0000003 (Pesgo Plus rojo)</td><td>lista_para_entrega</td></tr>
  <tr><td>5.2 — OTP por SMS</td><td>PoS — Entregar paso 1</td><td>GCTESTVIN0000003</td><td>lista_para_entrega</td></tr>
  <tr><td>5.3 — INE cámara + archivo</td><td>PoS — Entregar paso 3</td><td>GCTESTVIN0000003</td><td>lista_para_entrega</td></tr>
  <tr><td>5.4 — INE Reverso</td><td>PoS — Entregar paso 3</td><td>GCTESTVIN0000003</td><td>lista_para_entrega</td></tr>
  <tr><td>5.6 — Full delivery checklist</td><td>PoS — Entregar paso 4</td><td>GCTESTVIN0000003</td><td>lista_para_entrega</td></tr>
  <tr><td>4.1 — Assembly photos</td><td>PoS — Inventario → Ensamble</td><td>GCTESTVIN0000004 (Ukko-S verde)</td><td>recibida</td></tr>
  <tr><td>3.1 — Estado moto visible</td><td>PoS — Recepción</td><td>GCTESTVIN0000005 (M05 azul)</td><td>por_llegar + envio enviada</td></tr>
  <tr><td>3.2 — Tracking/Carrier/Origen badge</td><td>PoS — Recepción</td><td>GCTESTVIN0000005</td><td>por_llegar + envio enviada</td></tr>
  <tr><td>3.3 — Reception checklist detallado</td><td>PoS — Recepción → Recibir</td><td>GCTESTVIN0000005</td><td>por_llegar + envio enviada</td></tr>
  <tr><td>3.4 — PENDIENTE DE ASIGNACIÓN</td><td>PoS — Recepción</td><td>GCTESTVIN0000006 (M03 plata)</td><td>asignada (sin envio)</td></tr>
  <tr><td>1.1 — Engine validation</td><td>Admin — Checklist Origen</td><td>GCTESTVIN0000007 (MC10 naranja) — num_motor oficial: <code>GCMOTOR0000007</code></td><td>por_llegar</td></tr>
  <tr><td>1.2 — PDF firma + autosave 30s</td><td>Admin — Checklist Origen</td><td>GCTESTVIN0000007</td><td>por_llegar</td></tr>
  <tr><td>2.1 — Fecha llegada ≥ envío</td><td>Admin — Envíos → Marcar enviada</td><td>GCTESTVIN0000007</td><td>envio lista_para_enviar</td></tr>
  <tr><td>2.2 — Tracking + Carrier</td><td>Admin — Envíos → Marcar enviada</td><td>GCTESTVIN0000007</td><td>envio lista_para_enviar</td></tr>
</table>

<h3>📝 Pasos siguientes</h3>
<ol>
  <li>Si todo se ve bien arriba, presiona <strong>Ejecutar Run</strong> para escribir los datos.</li>
  <li>Comparte los datos de acceso con el tester.</li>
  <li>Al terminar las pruebas, elimina con <strong>Reset</strong> o borrando el archivo en producción.</li>
</ol>

</body></html>
