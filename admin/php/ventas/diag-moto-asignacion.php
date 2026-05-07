<?php
/**
 * Diagnóstico — verificar el bug "Sin moto asignada" en Ventas Ver
 * y la corrección de prefijos duplicados en inventario_motos.pedido_num.
 *
 * Customer brief 2026-05-07: this is the verification tool for the
 * three fixes we just shipped:
 *   1. voltikaNormalizePedidoNum() helper — single canonical "VK-X" form
 *   2. Ventas listar.php JOIN — accept legacy "VK-VK-X" + raw forms
 *   3. motos-disponibles.php — include the order's destination punto
 *
 * It runs four checks and reports them as a structured HTML report
 * (no external tools required, just open the URL):
 *
 *   A) Pedidos con prefijo duplicado   — ¿hay rows con "VK-VK-..."?
 *   B) Órdenes con join roto            — Ventas mostraría "Sin moto"
 *                                         pero existe el inventario row
 *   C) Helper sanity                    — el normalizador colapsa los
 *                                         distintos formatos de entrada
 *   D) Motos disponibles para una orden — la query del Asignar modal,
 *                                         con y sin punto_id, lo que
 *                                         devolvería el endpoint nuevo
 *
 * URL:
 *   /admin/php/ventas/diag-moto-asignacion.php             → preview only
 *   /admin/php/ventas/diag-moto-asignacion.php?run=1       → also fix the
 *                                                            duplicated
 *                                                            prefixes in
 *                                                            inventario
 *   /admin/php/ventas/diag-moto-asignacion.php?orden=VK-12 → focus check
 *                                                            D on this
 *                                                            order
 *
 * Auth: admin/cedis (same as the rest of /admin/).
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

if (!function_exists('voltikaNormalizePedidoNum')) {
    require_once __DIR__ . '/../../../configurador/php/master-bootstrap.php';
}

$pdo = getDB();
$run = !empty($_GET['run']);
$ordenFilter = trim((string)($_GET['orden'] ?? ''));

// ── Check A — duplicated VK-VK- prefixes + orphan "VK-" rows ─────────
// The orphan "VK-" rows are critical: they block every assignment
// because the duplicate-pedido_num guard returns "Esta moto ya está
// asignada a otra orden (pedido: VK-)". The clean for them is to
// blank out the field entirely (the moto goes back to free stock).
$dupRows = [];
$orphanRows = [];
try {
    $stmt = $pdo->query("SELECT id, vin_display, vin, pedido_num, cliente_nombre
                           FROM inventario_motos
                          WHERE pedido_num LIKE 'VK-VK-%'
                            AND activo = 1
                          ORDER BY id DESC LIMIT 50");
    $dupRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $dupErr = $e->getMessage(); }
try {
    $stmt = $pdo->query("SELECT id, vin_display, vin, pedido_num, cliente_nombre,
                                cliente_email, cliente_telefono
                           FROM inventario_motos
                          WHERE TRIM(pedido_num) IN ('VK-', 'VK-VK-', 'VK-VK')
                            AND activo = 1
                          ORDER BY id DESC LIMIT 50");
    $orphanRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $orphanErr = $e->getMessage(); }

$fixed = [];
$orphanFixed = [];
if ($run && $dupRows) {
    foreach ($dupRows as $r) {
        $clean = voltikaNormalizePedidoNum((string)$r['pedido_num']);
        if ($clean === '') continue; // handled by the orphan branch below
        try {
            $pdo->prepare("UPDATE inventario_motos SET pedido_num = ?, fmod = NOW() WHERE id = ?")
               ->execute([$clean, (int)$r['id']]);
            $fixed[] = ['id' => $r['id'], 'before' => $r['pedido_num'], 'after' => $clean];
            adminLog('moto_pedido_normalized', [
                'moto_id' => (int)$r['id'], 'before' => $r['pedido_num'], 'after' => $clean,
            ]);
        } catch (Throwable $e) { error_log('normalize: ' . $e->getMessage()); }
    }
}
if ($run && $orphanRows) {
    foreach ($orphanRows as $r) {
        // Wipe pedido_num + customer fields so the moto goes back to free
        // stock — the original linkage was broken (no real pedido), so the
        // moto can be assigned again to a real order.
        try {
            $pdo->prepare(
                "UPDATE inventario_motos SET
                    pedido_num       = NULL,
                    cliente_nombre   = NULL,
                    cliente_email    = NULL,
                    cliente_telefono = NULL,
                    stripe_pi        = NULL,
                    pago_estado      = NULL,
                    fmod             = NOW()
                 WHERE id = ?"
            )->execute([(int)$r['id']]);
            $orphanFixed[] = ['id' => $r['id'], 'vin' => $r['vin_display'] ?? $r['vin']];
            adminLog('moto_pedido_orphan_cleared', [
                'moto_id' => (int)$r['id'],
                'vin'     => $r['vin_display'] ?? $r['vin'],
            ]);
        } catch (Throwable $e) { error_log('orphan clear: ' . $e->getMessage()); }
    }
}

// ── Check B — orders that the dashboard would show as "Sin moto"
//             but where an inventario_motos row clearly belongs to them ─
$brokenJoins = [];
try {
    $stmt = $pdo->query("
        SELECT t.id           AS tx_id,
               t.pedido        AS tx_pedido,
               t.pedido_corto  AS tx_pedido_corto,
               t.nombre        AS tx_nombre,
               t.email         AS tx_email,
               m.id            AS m_id,
               m.vin_display   AS m_vin,
               m.pedido_num    AS m_pedido_num
          FROM transacciones t
          JOIN inventario_motos m
            ON m.activo = 1
           AND m.pedido_num IS NOT NULL
           AND m.pedido_num <> ''
           AND (
                 m.pedido_num = CONCAT('VK-', t.pedido)
              OR m.pedido_num = t.pedido_corto
              OR m.pedido_num = t.pedido
              OR m.pedido_num = CONCAT('VK-VK-', t.pedido)
           )
         WHERE NOT (
                 m.pedido_num = CONCAT('VK-', t.pedido)
              OR m.pedido_num = t.pedido_corto
           )
         ORDER BY t.id DESC LIMIT 50
    ");
    $brokenJoins = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $brokenErr = $e->getMessage(); }

// ── Check C — helper sanity ──────────────────────────────────────────
$helperCases = [
    '12345'             => 'expected VK-12345',
    'VK-12345'          => 'expected VK-12345',
    'VK-VK-12345'       => 'expected VK-12345',
    'vk-vk-vk-99'       => 'expected VK-99',
    '  VK-1826-CRTEST ' => 'expected VK-1826-CRTEST',
    'TEST-5500-CONTADO-1' => 'expected VK-TEST-5500-CONTADO-1',
];
$helperResults = [];
foreach ($helperCases as $in => $note) {
    $helperResults[] = ['in' => $in, 'out' => voltikaNormalizePedidoNum($in), 'note' => $note];
}

// ── Check D — motos disponibles for a given order ───────────────────
$dCheck = null;
if ($ordenFilter !== '') {
    try {
        $clean = preg_replace('/^VK-/i', '', $ordenFilter);
        $tStmt = $pdo->prepare("SELECT id, pedido, pedido_corto, modelo, color, punto_nombre
                                  FROM transacciones
                                 WHERE pedido = ? OR pedido_corto = ?
                                 ORDER BY id DESC LIMIT 1");
        $tStmt->execute([$clean, $ordenFilter]);
        $tx = $tStmt->fetch(PDO::FETCH_ASSOC);
        if ($tx) {
            $puntoId = 0;
            if (!empty($tx['punto_nombre'])) {
                $pStmt = $pdo->prepare("SELECT id FROM puntos_voltika WHERE nombre = ? AND activo = 1 LIMIT 1");
                $pStmt->execute([$tx['punto_nombre']]);
                $puntoId = (int)($pStmt->fetchColumn() ?: 0);
            }
            // Same logic as motos-disponibles.php
            $whereSinPunto = "m.activo = 1 AND (m.pedido_num IS NULL OR m.pedido_num = '')
                AND (m.cliente_email IS NULL OR m.cliente_email = '')
                AND m.estado IN ('recibida','lista_para_entrega')
                AND (m.punto_voltika_id IS NULL OR m.punto_voltika_id = 0)";
            $whereConPunto = $whereSinPunto . ($puntoId ? " OR (m.punto_voltika_id = $puntoId AND m.activo=1
                AND (m.pedido_num IS NULL OR m.pedido_num = '')
                AND m.estado IN ('recibida','lista_para_entrega'))" : '');
            $sinPunto = (int)$pdo->query("SELECT COUNT(*) FROM inventario_motos m WHERE $whereSinPunto")->fetchColumn();
            $conPunto = (int)$pdo->query("SELECT COUNT(*) FROM inventario_motos m WHERE $whereConPunto")->fetchColumn();
            $dCheck = [
                'tx'        => $tx,
                'punto_id'  => $puntoId,
                'sin_punto' => $sinPunto,
                'con_punto' => $conPunto,
                'delta'     => $conPunto - $sinPunto,
            ];
        }
    } catch (Throwable $e) { $dErr = $e->getMessage(); }
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8">
<title>Diagnóstico Asignación de Moto</title>
<style>
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#0b0f14;color:#eef2f7;padding:32px;max-width:960px;margin:0 auto;line-height:1.5}
h1{color:#22d37a;margin:0 0 8px;font-size:22px}
h2{color:#9aa7b7;font-size:13px;text-transform:uppercase;letter-spacing:1px;margin:26px 0 10px}
.box{background:#11161d;border:1px solid #202a36;border-radius:12px;padding:20px;margin-bottom:14px}
.row{display:grid;grid-template-columns:90px 1fr 1fr 1fr;gap:10px;padding:8px 0;border-bottom:1px solid #202a36;font-size:13px;align-items:center}
.row:last-child{border-bottom:0}
.k{color:#9aa7b7;font-family:monospace}
.tag{padding:3px 8px;border-radius:5px;font-size:11px;font-weight:700;text-align:center;font-family:monospace;display:inline-block}
.tag.ok{background:rgba(34,211,122,.15);color:#22d37a}
.tag.bad{background:rgba(255,140,140,.15);color:#ff8c8c}
.tag.warn{background:rgba(245,179,1,.15);color:#facc15}
.detail{color:#b7f2cf;font-family:monospace;font-size:12px;word-break:break-all}
.btn{background:#22d37a;color:#04120a;border:none;padding:9px 18px;border-radius:7px;font-weight:700;cursor:pointer;font-size:13px;margin-right:8px;text-decoration:none;display:inline-block}
.btn.alt{background:#3b82f6;color:#fff}
.btn.warn{background:#f59e0b;color:#1a1a1a}
code{background:#202a36;padding:2px 6px;border-radius:4px;font-size:12px}
table{width:100%;border-collapse:collapse;margin-top:6px}
th,td{padding:7px 10px;text-align:left;border-bottom:1px solid #202a36;font-size:12.5px}
th{color:#9aa7b7;font-weight:700;font-size:11px;letter-spacing:.5px;text-transform:uppercase}
.sum{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}
.summ-card{background:#11161d;border:1px solid #202a36;border-radius:8px;padding:14px;text-align:center}
.summ-card .n{font-size:26px;font-weight:900;color:#22d37a;margin-bottom:2px}
.summ-card.bad .n{color:#ff8c8c}
.summ-card.warn .n{color:#facc15}
.summ-card .l{font-size:11px;color:#9aa7b7;text-transform:uppercase;letter-spacing:.5px}
</style></head><body>

<h1>🔬 Diagnóstico — Asignación de moto a órdenes</h1>
<p style="color:#9aa7b7;margin:0 0 14px">Verifica los tres fixes 2026-05-07 (helper, JOIN tolerante, motos-disponibles con punto).</p>

<!-- Summary cards -->
<div class="sum">
  <div class="summ-card <?= count($orphanRows) > 0 ? 'bad' : '' ?>">
    <div class="n"><?= count($orphanRows) ?></div>
    <div class="l">⚠ Motos con "VK-" huérfano</div>
  </div>
  <div class="summ-card <?= count($dupRows) > 0 ? 'bad' : '' ?>">
    <div class="n"><?= count($dupRows) ?></div>
    <div class="l">Filas con VK-VK- duplicado</div>
  </div>
  <div class="summ-card <?= count($brokenJoins) > 0 ? 'warn' : '' ?>">
    <div class="n"><?= count($brokenJoins) ?></div>
    <div class="l">Órdenes con JOIN solo via fix</div>
  </div>
  <div class="summ-card">
    <div class="n"><?= $run ? (count($fixed) + count($orphanFixed)) : '—' ?></div>
    <div class="l">Filas reparadas (run=1)</div>
  </div>
</div>

<!-- Run buttons -->
<div class="box">
  <a class="btn" href="?">Re-cargar diagnóstico</a>
  <?php if ((count($dupRows) > 0 || count($orphanRows) > 0) && !$run):
    $totalToFix = count($dupRows) + count($orphanRows);
  ?>
    <a class="btn warn" href="?run=1" onclick="return confirm('Se repararán <?= $totalToFix ?> filas (<?= count($dupRows) ?> duplicadas + <?= count($orphanRows) ?> huérfanas). Las huérfanas vuelven al stock libre. ¿Continuar?')">Reparar todas (run=1)</a>
  <?php endif; ?>
  <?php if ($run): ?>
    <span class="tag ok">✓ Reparación ejecutada — <?= count($fixed) ?> normalizadas + <?= count($orphanFixed) ?> liberadas</span>
  <?php endif; ?>
</div>

<!-- A0) Orphan "VK-" rows — root cause of "ya está asignada" blocker -->
<h2>A0) ⚠ Motos con pedido_num huérfano ("VK-" sin cuerpo)</h2>
<div class="box">
  <?php if (count($orphanRows) === 0): ?>
    <span class="tag ok">✓ Sin motos con pedido_num huérfano — el bloqueador "Esta moto ya está asignada (pedido: VK-)" no debería disparar.</span>
  <?php else: ?>
    <p style="margin:0 0 10px;color:#ff8c8c"><strong>⚠ CRÍTICO:</strong> <?= count($orphanRows) ?> moto(s) con pedido_num="VK-" sin cuerpo. Estas filas son la causa del error "Esta moto ya está asignada a otra orden (pedido: VK-)" al intentar asignar OTRA moto a un pedido. Hay que limpiarlas (libera la moto al stock).</p>
    <table>
      <thead><tr><th>moto_id</th><th>VIN</th><th>pedido_num</th><th>cliente</th><th>email</th><th>tel</th></tr></thead>
      <tbody>
      <?php foreach ($orphanRows as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['id']) ?></td>
          <td><code><?= htmlspecialchars($r['vin_display'] ?? $r['vin']) ?></code></td>
          <td><span class="tag bad"><?= htmlspecialchars($r['pedido_num']) ?></span></td>
          <td><?= htmlspecialchars($r['cliente_nombre'] ?? '—') ?></td>
          <td style="font-size:11px"><?= htmlspecialchars($r['cliente_email'] ?? '—') ?></td>
          <td style="font-size:11px"><?= htmlspecialchars($r['cliente_telefono'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p style="margin:14px 0 0;color:#9aa7b7;font-size:12px">Acción de la limpieza: vaciar pedido_num + datos de cliente. La moto vuelve a quedar libre y disponible para asignación real.</p>
    <?php if ($run && $orphanFixed): ?>
      <p style="margin:14px 0 0;color:#22d37a">✓ Limpiadas <?= count($orphanFixed) ?> motos huérfanas en este run.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- A) Duplicated prefixes -->
<h2>A) Prefijo duplicado en inventario_motos.pedido_num</h2>
<div class="box">
  <?php if (count($dupRows) === 0): ?>
    <span class="tag ok">✓ Sin filas con VK-VK- — datos limpios</span>
  <?php else: ?>
    <p style="margin:0 0 10px;color:#ff8c8c">⚠ Encontradas <?= count($dupRows) ?> filas con prefijo duplicado. Estas órdenes mostrarán "Sin moto asignada" en Ventas hasta que se normalicen.</p>
    <table>
      <thead><tr><th>moto_id</th><th>VIN</th><th>pedido_num actual</th><th>cliente</th><th>tras normalizar</th></tr></thead>
      <tbody>
      <?php foreach ($dupRows as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['id']) ?></td>
          <td><code><?= htmlspecialchars($r['vin_display'] ?? $r['vin']) ?></code></td>
          <td><span class="tag bad"><?= htmlspecialchars($r['pedido_num']) ?></span></td>
          <td><?= htmlspecialchars($r['cliente_nombre'] ?? '—') ?></td>
          <td><span class="tag ok"><?= htmlspecialchars(voltikaNormalizePedidoNum($r['pedido_num'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($run && $fixed): ?>
      <p style="margin:14px 0 0;color:#22d37a">✓ Reparadas <?= count($fixed) ?> filas en este run.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- B) Broken JOINs that the new fix recovers -->
<h2>B) Órdenes que sólo aparecen vinculadas con el JOIN tolerante nuevo</h2>
<div class="box">
  <?php if (count($brokenJoins) === 0): ?>
    <span class="tag ok">✓ Todas las órdenes hacen match con la regla canónica — el JOIN tolerante no fue necesario para ninguna.</span>
  <?php else: ?>
    <p style="margin:0 0 10px;color:#facc15">⚠ <?= count($brokenJoins) ?> órdenes recuperadas por el JOIN extendido (las legacy con prefijo duplicado o formato no estándar).</p>
    <table>
      <thead><tr><th>tx_id</th><th>t.pedido</th><th>t.pedido_corto</th><th>m.pedido_num</th><th>cliente</th></tr></thead>
      <tbody>
      <?php foreach ($brokenJoins as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['tx_id']) ?></td>
          <td><code><?= htmlspecialchars($r['tx_pedido']) ?></code></td>
          <td><code><?= htmlspecialchars($r['tx_pedido_corto'] ?? '—') ?></code></td>
          <td><span class="tag warn"><?= htmlspecialchars($r['m_pedido_num']) ?></span></td>
          <td><?= htmlspecialchars($r['tx_nombre']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- C) Helper sanity -->
<h2>C) voltikaNormalizePedidoNum() — sanity check</h2>
<div class="box">
  <table>
    <thead><tr><th>Entrada</th><th>Salida</th><th>Esperado</th><th>Estado</th></tr></thead>
    <tbody>
    <?php foreach ($helperResults as $h):
      $expected = preg_replace('/^expected\s+/', '', $h['note']);
      $ok = $h['out'] === $expected;
    ?>
      <tr>
        <td><code><?= htmlspecialchars($h['in']) ?></code></td>
        <td><code><?= htmlspecialchars($h['out']) ?></code></td>
        <td><code><?= htmlspecialchars($expected) ?></code></td>
        <td><span class="tag <?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✓ PASS' : '✗ FAIL' ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- D) Motos disponibles, with and without punto_id -->
<h2>D) Motos disponibles para una orden — con y sin punto_id</h2>
<div class="box">
  <form method="get" style="margin-bottom:10px;">
    <label style="font-size:13px;color:#9aa7b7;">Probar con orden:
      <input type="text" name="orden" value="<?= htmlspecialchars($ordenFilter) ?>" placeholder="VK-12 o el pedido_corto" style="background:#0b0f14;border:1px solid #202a36;color:#eef2f7;padding:6px 10px;border-radius:5px;font-family:monospace;">
    </label>
    <button class="btn alt" type="submit">Verificar</button>
  </form>

  <?php if ($ordenFilter === ''): ?>
    <p style="color:#9aa7b7;margin:0">Ingresa un pedido para ver cuántas motos son seleccionables sin / con el filtro de punto.</p>
  <?php elseif (!$dCheck): ?>
    <p style="color:#ff8c8c;margin:0">No se encontró la orden <code><?= htmlspecialchars($ordenFilter) ?></code>.</p>
  <?php else: ?>
    <table>
      <tr><td>Orden</td><td><code><?= htmlspecialchars($dCheck['tx']['pedido_corto'] ?: 'VK-'.$dCheck['tx']['pedido']) ?></code></td></tr>
      <tr><td>Modelo / color</td><td><?= htmlspecialchars($dCheck['tx']['modelo']) ?> / <?= htmlspecialchars($dCheck['tx']['color']) ?></td></tr>
      <tr><td>Punto registrado</td><td><?= htmlspecialchars($dCheck['tx']['punto_nombre'] ?? '—') ?> (id=<?= $dCheck['punto_id'] ?>)</td></tr>
      <tr><td>Motos disponibles SIN punto_id</td><td><span class="tag warn"><?= $dCheck['sin_punto'] ?> moto(s)</span></td></tr>
      <tr><td>Motos disponibles CON punto_id</td><td><span class="tag ok"><?= $dCheck['con_punto'] ?> moto(s)</span></td></tr>
      <tr><td>Δ recuperadas por el fix</td><td><strong style="color:<?= $dCheck['delta'] > 0 ? '#22d37a' : '#9aa7b7' ?>;"><?= $dCheck['delta'] >= 0 ? '+' : '' ?><?= $dCheck['delta'] ?></strong></td></tr>
    </table>
    <p style="font-size:12px;color:#9aa7b7;margin:10px 0 0">Δ &gt; 0 significa que el fix expone motos que el modal anterior no podía mostrar.</p>
  <?php endif; ?>
</div>

<div class="box" style="background:rgba(245,179,1,.08);border-color:rgba(245,179,1,.3)">
  ⚠ Esta herramienta es de diagnóstico. Bórrala (<code>admin/php/ventas/diag-moto-asignacion.php</code>) tras finalizar las verificaciones.
</div>

</body></html>
