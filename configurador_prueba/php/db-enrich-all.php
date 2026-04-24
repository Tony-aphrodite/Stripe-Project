<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 * VOLTIKA · TRANSACCIONES COMPREHENSIVE ENRICHMENT
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Fills empty fields in every transacciones row by cross-referencing:
 *   1. `facturacion` table  → rfc, razon, direccion, ciudad, estado, cp
 *   2. `pedidos` table      → shipping fields (e_*), direccion, tenvio, punto
 *   3. Stripe metadata      → nombre, telefono, modelo, color, msi, punto
 *   4. Stripe Customer obj  → email, phone backup
 *
 * Safety:
 *   - ONLY fills EMPTY fields (never overwrites existing data)
 *   - Uses COALESCE(NULLIF(field, ''), new_value) logic
 *   - Transactional with rollback on error
 *   - Dry run mode to preview changes
 *   - Reports before/after per record
 *
 * Usage:
 *   ?key=voltika-enrich-2026&mode=dry       → preview (no changes)
 *   ?key=voltika-enrich-2026&mode=apply     → execute enrichment
 * ═══════════════════════════════════════════════════════════════════════════
 */

set_time_limit(900);
header('Content-Type: text/html; charset=utf-8');

$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika-enrich-2026') {
    http_response_code(403);
    exit('Forbidden');
}

$mode = $_GET['mode'] ?? '';
if (!in_array($mode, ['dry', 'apply'])) {
    exit('Add &mode=dry (preview) or &mode=apply (execute)');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

$useStripe = isset($_GET['stripe']) && $_GET['stripe'] === '1';

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Voltika · Enrichment Total</title>
<style>
  body { font-family: 'Inter', sans-serif; max-width: 1400px; margin: 20px auto; padding: 20px; background: #f0f4f8; color: #0c2340; }
  .box { background: #fff; padding: 18px 22px; border-radius: 14px; box-shadow: 0 4px 20px rgba(12,35,64,.07); margin-bottom: 14px; }
  h1 { color: #0c2340; font-size: 22px; }
  h2 { color: #039fe1; font-size: 15px; margin: 0 0 10px; padding-bottom: 6px; border-bottom: 1px solid #e2e8f0; }
  .kpi-row { display: grid; grid-template-columns: repeat(auto-fill,minmax(170px,1fr)); gap: 10px; margin: 6px 0; }
  .kpi { padding: 14px; border-radius: 10px; color: #fff; }
  .kpi.blue { background: linear-gradient(135deg,#039fe1,#027db0); }
  .kpi.navy { background: linear-gradient(135deg,#0c2340,#1e3a5f); }
  .kpi.green { background: linear-gradient(135deg,#22c55e,#16a34a); }
  .kpi.warn { background: linear-gradient(135deg,#f59e0b,#d97706); }
  .kpi.purple { background: linear-gradient(135deg,#a855f7,#9333ea); }
  .kpi .n { font-size: 22px; font-weight: 800; display: block; }
  .kpi .l { font-size: 10px; text-transform: uppercase; opacity: .85; letter-spacing: .5px; }
  table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
  th, td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
  th { background: #f5f7fa; font-size: 10px; text-transform: uppercase; color: #64748b; letter-spacing: .5px; }
  .ok { color: #16a34a; font-weight: 700; }
  .warn { color: #d97706; font-weight: 700; }
  .err { color: #dc2626; font-weight: 700; }
  .dim { color: #94a3b8; font-style: italic; }
  code { background: #f1f5f9; padding: 1px 6px; border-radius: 4px; font-size: 10.5px; font-family: 'SF Mono',Consolas,monospace; }
  .filled { background: #dcfce7; }
  .alert-ok { background: #dcfce7; border-left: 4px solid #16a34a; padding: 10px 14px; border-radius: 8px; color: #166534; margin-bottom: 10px; }
  .alert-warn { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 10px 14px; border-radius: 8px; color: #92400e; margin-bottom: 10px; }
  .alert-err { background: #fee2e2; border-left: 4px solid #dc2626; padding: 10px 14px; border-radius: 8px; color: #991b1b; margin-bottom: 10px; }
  .mode-dry { background: linear-gradient(135deg,#f59e0b,#d97706); color: #fff; padding: 4px 12px; border-radius: 50px; font-size: 11px; font-weight: 700; }
  .mode-apply { background: linear-gradient(135deg,#22c55e,#16a34a); color: #fff; padding: 4px 12px; border-radius: 50px; font-size: 11px; font-weight: 700; }
  .src { display: inline-block; padding: 1px 7px; border-radius: 50px; font-size: 9px; font-weight: 700; margin: 1px; }
  .src.fact { background: #fef3c7; color: #92400e; }
  .src.ped { background: #dbeafe; color: #1e40af; }
  .src.stripe { background: #ede9fe; color: #6d28d9; }
</style>
</head>
<body>

<div class="box">
  <h1>✨ Voltika · Enrichment Total · <span class="mode-<?= $mode ?>"><?= strtoupper($mode) ?></span></h1>
  <p style="font-size:12px;color:#64748b;">
    Rellena campos vacíos desde facturacion + pedidos<?= $useStripe ? ' + Stripe' : '' ?>.
    <?= !$useStripe ? '<br><small>Agrega <code>&stripe=1</code> para incluir datos de Stripe (más lento).</small>' : '' ?>
  </p>
</div>

<?php
try {
    $pdo = getDB();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ═══════════════════════════════════════════════════════════════════════
    // 1. Load all transacciones
    // ═══════════════════════════════════════════════════════════════════════
    $transacciones = $pdo->query("
        SELECT id, pedido, nombre, telefono, email, razon, rfc,
               direccion, ciudad, estado, cp,
               e_nombre, e_telefono, e_direccion, e_ciudad, e_estado, e_cp,
               modelo, color, tpago, tenvio,
               precio, penvio, total, freg, stripe_pi,
               punto_id, punto_nombre, msi_meses
        FROM transacciones
        ORDER BY id
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "<div class='box'>";
    echo "<h2>📋 Fuentes de enriquecimiento</h2>";
    echo "<div class='kpi-row'>";
    echo "<div class='kpi navy'><span class='l'>Registros a procesar</span><span class='n'>" . count($transacciones) . "</span></div>";

    // 2. Load facturacion grouped by email — dynamically detect available columns
    $factCols = $pdo->query("SHOW COLUMNS FROM facturacion")->fetchAll(PDO::FETCH_COLUMN);
    $factAvailable = array_flip($factCols);

    // Select only columns that exist
    $wantedFactCols = ['email','rfc','razon','calle','cp','ciudad','estado','nombre','telefono','direccion'];
    $selectFactCols = array_values(array_intersect($wantedFactCols, $factCols));
    if (in_array('freg', $factCols)) $orderBy = 'ORDER BY freg DESC';
    elseif (in_array('id', $factCols)) $orderBy = 'ORDER BY id DESC';
    else $orderBy = '';

    $facturacionRaw = $pdo->query("
        SELECT " . implode(',', array_map(fn($c) => "`$c`", $selectFactCols)) . "
        FROM facturacion
        WHERE email IS NOT NULL AND email <> ''
        $orderBy
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Build index: email → best non-empty data
    $facturacionByEmail = [];
    foreach ($facturacionRaw as $f) {
        $email = strtolower(trim($f['email'] ?? ''));
        if (!$email) continue;
        if (!isset($facturacionByEmail[$email])) {
            $facturacionByEmail[$email] = [
                'rfc' => '', 'razon' => '', 'direccion' => '', 'ciudad' => '',
                'estado' => '', 'cp' => '', 'nombre' => '', 'telefono' => ''
            ];
        }
        foreach (['rfc','razon','ciudad','estado','cp','nombre','telefono'] as $k) {
            if (isset($f[$k]) && empty($facturacionByEmail[$email][$k]) && !empty(trim($f[$k]))) {
                $facturacionByEmail[$email][$k] = trim($f[$k]);
            }
        }
        // 'calle' → 'direccion' mapping
        if (isset($f['calle']) && empty($facturacionByEmail[$email]['direccion']) && !empty(trim($f['calle']))) {
            $facturacionByEmail[$email]['direccion'] = trim($f['calle']);
        }
        // Also accept direct 'direccion' column if it exists
        if (isset($f['direccion']) && empty($facturacionByEmail[$email]['direccion']) && !empty(trim($f['direccion']))) {
            $facturacionByEmail[$email]['direccion'] = trim($f['direccion']);
        }
    }
    echo "<div class='kpi blue'><span class='l'>Facturacion por email</span><span class='n'>" . count($facturacionByEmail) . "</span></div>";

    // 3. Load pedidos (try multiple possible schemas)
    $pedidosByPedido = [];
    $pedidosByEmail = [];
    try {
        $pedidosRaw = $pdo->query("SELECT * FROM pedidos ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($pedidosRaw as $p) {
            $pid = $p['pedido'] ?? $p['id_pedido'] ?? null;
            $em  = isset($p['email']) ? strtolower(trim($p['email'])) : null;
            if ($pid) $pedidosByPedido[$pid] = $p;
            if ($em)  $pedidosByEmail[$em] = $p;
        }
        echo "<div class='kpi blue'><span class='l'>Pedidos indexados</span><span class='n'>" . count($pedidosRaw) . "</span></div>";
    } catch (Exception $e) {
        echo "<div class='kpi warn'><span class='l'>Pedidos</span><span class='n'>N/A</span></div>";
    }

    // 4. Optionally load Stripe data
    $stripeByPi = [];
    if ($useStripe && defined('STRIPE_SECRET_KEY') && STRIPE_SECRET_KEY) {
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

        $stripePIs = array_values(array_filter(array_column($transacciones, 'stripe_pi')));
        $stripePIs = array_unique($stripePIs);

        foreach ($stripePIs as $piId) {
            try {
                $pi = \Stripe\PaymentIntent::retrieve([
                    'id' => $piId,
                    'expand' => ['customer', 'latest_charge.billing_details', 'latest_charge.payment_method_details']
                ]);
                $charge = $pi->latest_charge ?? null;
                $customer = $pi->customer ?? null;
                $meta = $pi->metadata ? $pi->metadata->toArray() : [];
                $billing = $charge ? ($charge->billing_details ?? null) : null;
                $billAddr = $billing ? ($billing->address ?? null) : null;
                $shipping = $pi->shipping ?? null;
                $shipAddr = $shipping ? ($shipping->address ?? null) : null;
                $pmd = $charge ? ($charge->payment_method_details ?? null) : null;
                $cardDet = $pmd ? ($pmd->card ?? null) : null;

                $stripeByPi[$piId] = [
                    'nombre'       => trim(($meta['nombre'] ?? '') . ' ' . ($meta['apellidos'] ?? '')) ?: ($billing->name ?? ($customer ? $customer->name : '')),
                    'email'        => $meta['email'] ?? ($billing->email ?? ($customer ? $customer->email : '')),
                    'telefono'     => $meta['telefono'] ?? ($billing->phone ?? ($customer ? $customer->phone : '')),
                    'modelo'       => $meta['modelo'] ?? '',
                    'color'        => $meta['color'] ?? '',
                    'tpago'        => $meta['tpago'] ?? $meta['method'] ?? '',
                    'msi_meses'    => $meta['msi_meses'] ?? '',
                    'punto_id'     => $meta['punto_id'] ?? '',
                    'punto_nombre' => $meta['punto_nombre'] ?? '',
                    'ciudad'       => ($billAddr ? ($billAddr->city ?? '') : '') ?: ($meta['ciudad'] ?? ''),
                    'estado'       => ($billAddr ? ($billAddr->state ?? '') : '') ?: ($meta['estado'] ?? ''),
                    'cp'           => ($billAddr ? ($billAddr->postal_code ?? '') : '') ?: ($meta['cp'] ?? ''),
                    'direccion'    => $billAddr ? trim(($billAddr->line1 ?? '') . ' ' . ($billAddr->line2 ?? '')) : '',
                    'e_nombre'     => $shipping ? ($shipping->name ?? '') : '',
                    'e_telefono'   => $shipping ? ($shipping->phone ?? '') : '',
                    'e_direccion'  => $shipAddr ? trim(($shipAddr->line1 ?? '') . ' ' . ($shipAddr->line2 ?? '')) : '',
                    'e_ciudad'     => $shipAddr ? ($shipAddr->city ?? '') : '',
                    'e_estado'     => $shipAddr ? ($shipAddr->state ?? '') : '',
                    'e_cp'         => $shipAddr ? ($shipAddr->postal_code ?? '') : '',
                ];
            } catch (Exception $e) {
                // Skip errors for individual PIs
            }
        }
        echo "<div class='kpi purple'><span class='l'>Stripe PIs consultados</span><span class='n'>" . count($stripeByPi) . "</span></div>";
    }

    echo "</div>";
    echo "</div>";

    // ═══════════════════════════════════════════════════════════════════════
    // 5. Enrichment loop
    // ═══════════════════════════════════════════════════════════════════════
    $updateFields = [
        'nombre','telefono','rfc','razon','direccion','ciudad','estado','cp',
        'e_nombre','e_telefono','e_direccion','e_ciudad','e_estado','e_cp',
        'modelo','color','tpago','tenvio','msi_meses','punto_id','punto_nombre'
    ];

    $changes = [];
    $fieldStats = array_fill_keys($updateFields, ['fact' => 0, 'ped' => 0, 'stripe' => 0]);

    foreach ($transacciones as $t) {
        $email = strtolower(trim($t['email'] ?? ''));
        $pedido = trim($t['pedido'] ?? '');
        $piId = trim($t['stripe_pi'] ?? '');

        $fact = $facturacionByEmail[$email] ?? null;
        $ped = $pedidosByPedido[$pedido] ?? $pedidosByEmail[$email] ?? null;
        $stripe = $stripeByPi[$piId] ?? null;

        $newValues = [];
        $sources = [];

        foreach ($updateFields as $f) {
            $cur = trim($t[$f] ?? '');
            if ($cur !== '') continue; // never overwrite

            // Try facturacion first (fiscal fields priority)
            if ($fact && isset($fact[$f]) && !empty(trim($fact[$f]))) {
                $newValues[$f] = trim($fact[$f]);
                $sources[$f] = 'fact';
                $fieldStats[$f]['fact']++;
                continue;
            }

            // Try pedidos (shipping fields priority)
            if ($ped) {
                // Map pedidos columns flexibly
                $pedMap = [
                    'nombre' => 'nombre', 'telefono' => 'telefono',
                    'direccion' => 'direccion', 'ciudad' => 'ciudad',
                    'estado' => 'estado', 'cp' => 'cp',
                    'e_nombre' => 'e_nombre', 'e_telefono' => 'e_telefono',
                    'e_direccion' => 'e_direccion', 'e_ciudad' => 'e_ciudad',
                    'e_estado' => 'e_estado', 'e_cp' => 'e_cp',
                    'modelo' => 'modelo', 'color' => 'color',
                    'tpago' => 'tpago', 'tenvio' => 'tenvio',
                    'punto_id' => 'punto_id', 'punto_nombre' => 'punto_nombre',
                ];
                $pcol = $pedMap[$f] ?? null;
                if ($pcol && isset($ped[$pcol]) && !empty(trim($ped[$pcol]))) {
                    $newValues[$f] = trim($ped[$pcol]);
                    $sources[$f] = 'ped';
                    $fieldStats[$f]['ped']++;
                    continue;
                }
            }

            // Try Stripe last (metadata fallback)
            if ($stripe && isset($stripe[$f]) && !empty(trim($stripe[$f]))) {
                $newValues[$f] = trim($stripe[$f]);
                $sources[$f] = 'stripe';
                $fieldStats[$f]['stripe']++;
            }
        }

        if (!empty($newValues)) {
            $changes[] = [
                'id' => $t['id'],
                'pedido' => $t['pedido'],
                'nombre' => $t['nombre'] ?: '(vacío)',
                'email' => $t['email'],
                'changes' => $newValues,
                'sources' => $sources,
            ];
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 6. Show stats
    // ═══════════════════════════════════════════════════════════════════════
    echo "<div class='box'>";
    echo "<h2>📊 Resumen de enriquecimiento</h2>";
    echo "<div class='kpi-row'>";
    echo "<div class='kpi navy'><span class='l'>Registros que se modificarían</span><span class='n'>" . count($changes) . "</span></div>";
    $totalFields = array_sum(array_map(fn($c) => count($c['changes']), $changes));
    echo "<div class='kpi green'><span class='l'>Campos totales a llenar</span><span class='n'>$totalFields</span></div>";
    $fromFact = array_sum(array_column($fieldStats, 'fact'));
    $fromPed = array_sum(array_column($fieldStats, 'ped'));
    $fromStripe = array_sum(array_column($fieldStats, 'stripe'));
    echo "<div class='kpi warn'><span class='l'>Desde facturacion</span><span class='n'>$fromFact</span></div>";
    echo "<div class='kpi blue'><span class='l'>Desde pedidos</span><span class='n'>$fromPed</span></div>";
    echo "<div class='kpi purple'><span class='l'>Desde Stripe</span><span class='n'>$fromStripe</span></div>";
    echo "</div>";

    // Field-level breakdown
    echo "<h3 style='margin-top:16px;font-size:13px;'>Desglose por campo</h3>";
    echo "<table><tr><th>Campo</th><th>facturacion</th><th>pedidos</th><th>Stripe</th><th>Total</th></tr>";
    foreach ($fieldStats as $f => $s) {
        $total = $s['fact'] + $s['ped'] + $s['stripe'];
        if ($total === 0) continue;
        echo "<tr>";
        echo "<td><code>$f</code></td>";
        echo "<td>" . ($s['fact'] > 0 ? "<span class='src fact'>{$s['fact']}</span>" : '<span class="dim">0</span>') . "</td>";
        echo "<td>" . ($s['ped'] > 0 ? "<span class='src ped'>{$s['ped']}</span>" : '<span class="dim">0</span>') . "</td>";
        echo "<td>" . ($s['stripe'] > 0 ? "<span class='src stripe'>{$s['stripe']}</span>" : '<span class="dim">0</span>') . "</td>";
        echo "<td><strong>$total</strong></td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "</div>";

    // ═══════════════════════════════════════════════════════════════════════
    // 7. Show per-record changes
    // ═══════════════════════════════════════════════════════════════════════
    if (count($changes) > 0) {
        echo "<div class='box'>";
        echo "<h2>📝 Cambios por registro (" . count($changes) . " filas afectadas)</h2>";
        echo "<div style='overflow-x:auto;max-height:600px;overflow-y:auto;'><table>";
        echo "<tr><th>ID</th><th>Pedido</th><th>Nombre</th><th>Email</th><th>Campos a llenar</th></tr>";
        foreach ($changes as $c) {
            echo "<tr>";
            echo "<td><strong>{$c['id']}</strong></td>";
            echo "<td>" . htmlspecialchars($c['pedido']) . "</td>";
            echo "<td>" . htmlspecialchars($c['nombre']) . "</td>";
            echo "<td>" . htmlspecialchars($c['email']) . "</td>";
            echo "<td style='font-size:10.5px;'>";
            foreach ($c['changes'] as $f => $v) {
                $src = $c['sources'][$f] ?? '';
                $vDisplay = mb_strlen($v) > 30 ? mb_substr($v, 0, 27) . '…' : $v;
                echo "<span class='src $src'>$f</span> = <code>" . htmlspecialchars($vDisplay) . "</code><br>";
            }
            echo "</td>";
            echo "</tr>";
        }
        echo "</table></div>";
        echo "</div>";
    } else {
        echo "<div class='alert-ok'>✓ No hay campos vacíos que se puedan llenar con las fuentes disponibles.</div>";
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 8. DRY vs APPLY
    // ═══════════════════════════════════════════════════════════════════════
    if ($mode === 'dry') {
        echo "<div class='alert-warn'>";
        echo "<strong>🧪 Modo DRY:</strong> No se ha modificado nada.<br>";
        echo "Para aplicar los cambios, cambia <code>&mode=dry</code> por <code>&mode=apply</code> en la URL.";
        if (!$useStripe) {
            echo "<br>Para incluir datos de Stripe también, agrega <code>&stripe=1</code> a la URL.";
        }
        echo "</div>";
    } else {
        // APPLY
        if (empty($changes)) {
            echo "<div class='alert-ok'>✓ Sin cambios que aplicar.</div>";
        } else {
            $pdo->beginTransaction();
            $applied = 0;
            $startTime = microtime(true);
            try {
                foreach ($changes as $c) {
                    $setParts = [];
                    $params = [':id' => $c['id']];
                    foreach ($c['changes'] as $f => $v) {
                        $setParts[] = "`$f` = :$f";
                        $params[":$f"] = $v;
                    }
                    $sql = "UPDATE transacciones SET " . implode(', ', $setParts) . " WHERE id = :id";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $applied++;
                }
                $pdo->commit();
                $elapsed = round((microtime(true) - $startTime) * 1000, 2);
                echo "<div class='alert-ok'>";
                echo "<h2 style='color:#166534;'>✅ Enrichment aplicado con éxito</h2>";
                echo "<div class='kpi-row'>";
                echo "<div class='kpi green'><span class='l'>Registros actualizados</span><span class='n'>$applied</span></div>";
                echo "<div class='kpi blue'><span class='l'>Campos llenados</span><span class='n'>$totalFields</span></div>";
                echo "<div class='kpi navy'><span class='l'>Tiempo</span><span class='n'>{$elapsed}ms</span></div>";
                echo "</div>";
                echo "<h3 style='margin-top:16px;'>Siguientes pasos:</h3>";
                echo "<ol>";
                echo "<li>Ejecuta un backup nuevo: <code>/configurador_prueba/php/db-backup.php?key=voltika-backup-2026</code></li>";
                echo "<li>Verifica en phpMyAdmin — los campos vacíos deberían estar rellenos</li>";
                echo "<li>Si quedan campos aún vacíos (como <code>e_direccion</code>) son los que no están en ninguna fuente</li>";
                echo "<li>Elimina este archivo del servidor</li>";
                echo "</ol>";
                echo "</div>";
            } catch (Throwable $e) {
                $pdo->rollBack();
                echo "<div class='alert-err'>❌ Error — ROLLBACK ejecutado: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo "<div class='alert-err'><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre></div>";
}
?>

</body>
</html>
