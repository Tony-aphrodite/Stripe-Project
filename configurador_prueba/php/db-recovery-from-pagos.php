<?php
/**
 * VOLTIKA · RECOVERY FROM PAGOS + ENVIOS TABLES
 *
 * Strategy:
 *   1. Read all pagos records (payment ledger with 1,139 rows)
 *   2. Cross-reference with transacciones by stripe_payment_intent_id
 *   3. Identify pagos WITHOUT matching transaccion (= lost records)
 *   4. Look up each lost record in Stripe to get customer/shipping/billing data
 *   5. Look up matching envios entries for shipping info
 *   6. Generate or execute INSERTs to restore transacciones
 *
 * Modes:
 *   ?key=voltika-pagos-2026&mode=analyze   → report only
 *   ?key=voltika-pagos-2026&mode=dry       → generate SQL preview
 *   ?key=voltika-pagos-2026&mode=apply     → insert missing records
 */

set_time_limit(900);
header('Content-Type: text/html; charset=utf-8');

$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika-pagos-2026') {
    http_response_code(403); exit('Forbidden');
}

$mode = $_GET['mode'] ?? 'analyze';
if (!in_array($mode, ['analyze','dry','apply'])) {
    exit('Add &mode=analyze | dry | apply');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

$useStripe = isset($_GET['stripe']) && $_GET['stripe'] === '1';

?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<title>Voltika · Recovery desde pagos</title>
<style>
  body { font-family: 'Inter', sans-serif; max-width: 1400px; margin: 20px auto; padding: 20px; background: #f0f4f8; color: #0c2340; }
  .box { background: #fff; padding: 18px 22px; border-radius: 14px; box-shadow: 0 4px 20px rgba(12,35,64,.07); margin-bottom: 14px; }
  h1 { color: #0c2340; font-size: 22px; }
  h2 { color: #039fe1; font-size: 15px; margin: 0 0 10px; padding-bottom: 6px; border-bottom: 1px solid #e2e8f0; }
  table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
  th, td { padding: 6px 10px; border-bottom: 1px solid #e2e8f0; text-align: left; }
  th { background: #f5f7fa; font-size: 10.5px; text-transform: uppercase; color: #64748b; }
  .ok { color: #16a34a; font-weight: 700; }
  .warn { color: #d97706; font-weight: 700; }
  .err { color: #dc2626; font-weight: 700; }
  .kpi { display: inline-block; background: linear-gradient(135deg,#039fe1,#027db0); color: #fff; padding: 10px 16px; border-radius: 10px; margin: 4px; }
  .kpi.green { background: linear-gradient(135deg,#22c55e,#16a34a); }
  .kpi.warn { background: linear-gradient(135deg,#f59e0b,#d97706); }
  .kpi.red { background: linear-gradient(135deg,#ef4444,#dc2626); }
  .kpi.navy { background: linear-gradient(135deg,#0c2340,#1e3a5f); }
  .kpi .n { font-size: 22px; font-weight: 800; display: block; }
  .kpi .l { font-size: 10px; text-transform: uppercase; opacity: .85; }
  code { background: #f1f5f9; padding: 1px 6px; border-radius: 4px; font-size: 10.5px; }
  .alert-ok { background: #dcfce7; border-left: 4px solid #16a34a; padding: 10px 14px; border-radius: 8px; color: #166534; margin-bottom: 10px; }
  .alert-warn { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 10px 14px; border-radius: 8px; color: #92400e; margin-bottom: 10px; }
  .alert-err { background: #fee2e2; border-left: 4px solid #dc2626; padding: 10px 14px; border-radius: 8px; color: #991b1b; margin-bottom: 10px; }
</style></head><body>

<div class="box"><h1>💰 Recovery desde <code>pagos</code> + <code>envios</code> · <strong><?= strtoupper($mode) ?></strong></h1></div>

<?php
try {
    $pdo = getDB();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── 1. Analyze pagos table structure ───────────────────────────────────
    $pagosCols = $pdo->query("SHOW COLUMNS FROM pagos")->fetchAll(PDO::FETCH_ASSOC);
    $pagosColNames = array_column($pagosCols, 'Field');

    echo "<div class='box'><h2>📋 Estructura de <code>pagos</code></h2>";
    echo "<p><strong>Columnas:</strong> " . implode(', ', array_map(fn($c)=>"<code>$c</code>", $pagosColNames)) . "</p>";

    $pagosCount = (int)$pdo->query("SELECT COUNT(*) FROM pagos")->fetchColumn();
    $pagosWithPI = (int)$pdo->query("SELECT COUNT(*) FROM pagos WHERE stripe_payment_intent_id IS NOT NULL AND stripe_payment_intent_id <> ''")->fetchColumn();
    $pagosWithEmail = (int)$pdo->query("SELECT COUNT(*) FROM pagos WHERE email IS NOT NULL AND email <> ''")->fetchColumn();

    echo "<div>";
    echo "<span class='kpi navy'><span class='l'>Total pagos</span><span class='n'>" . number_format($pagosCount) . "</span></span>";
    echo "<span class='kpi'><span class='l'>Con Stripe PI</span><span class='n'>" . number_format($pagosWithPI) . "</span></span>";
    echo "<span class='kpi'><span class='l'>Con email</span><span class='n'>" . number_format($pagosWithEmail) . "</span></span>";
    echo "</div>";
    echo "</div>";

    // ── 2. Find pagos WITHOUT matching transaccion ─────────────────────────
    $missingPagos = $pdo->query("
        SELECT p.* FROM pagos p
        WHERE p.stripe_payment_intent_id IS NOT NULL
          AND p.stripe_payment_intent_id <> ''
          AND NOT EXISTS (
            SELECT 1 FROM transacciones t
            WHERE t.stripe_pi = p.stripe_payment_intent_id
          )
        ORDER BY p.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "<div class='box'><h2>🔍 Pagos SIN transacciones correspondiente</h2>";
    echo "<div>";
    echo "<span class='kpi red'><span class='l'>Pagos huérfanos</span><span class='n'>" . count($missingPagos) . "</span></span>";
    echo "<span class='kpi green'><span class='l'>Pagos ya en transacciones</span><span class='n'>" . ($pagosWithPI - count($missingPagos)) . "</span></span>";
    echo "</div>";

    // Sample preview
    if (count($missingPagos) > 0) {
        echo "<h3 style='margin-top:14px;font-size:13px;'>📋 Muestra (primeros 30 pagos huérfanos):</h3>";
        echo "<table><tr><th>Pago ID</th><th>Email</th><th>Monto</th><th>Método</th><th>Stripe PI</th><th>Estado</th></tr>";
        foreach (array_slice($missingPagos, 0, 30) as $p) {
            echo "<tr>";
            echo "<td><strong>" . htmlspecialchars($p['id']) . "</strong></td>";
            echo "<td>" . htmlspecialchars($p['email'] ?? '—') . "</td>";
            echo "<td>\$" . htmlspecialchars($p['monto'] ?? 0) . "</td>";
            echo "<td>" . htmlspecialchars($p['metodo_pago'] ?? '—') . "</td>";
            echo "<td><code>" . htmlspecialchars(substr($p['stripe_payment_intent_id'] ?? '', 0, 25)) . "</code></td>";
            echo "<td>" . htmlspecialchars($p['estado'] ?? '—') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    echo "</div>";

    // ── 3. Analyze envios table ────────────────────────────────────────────
    $enviosExists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'envios'")->fetchColumn();
    if ($enviosExists) {
        $enviosCols = $pdo->query("SHOW COLUMNS FROM envios")->fetchAll(PDO::FETCH_COLUMN);
        $enviosCount = (int)$pdo->query("SELECT COUNT(*) FROM envios")->fetchColumn();

        echo "<div class='box'><h2>📦 Tabla <code>envios</code></h2>";
        echo "<p><strong>Columnas:</strong> " . implode(', ', array_map(fn($c)=>"<code>$c</code>", $enviosCols)) . "</p>";
        echo "<span class='kpi navy'><span class='l'>Total envios</span><span class='n'>$enviosCount</span></span>";

        if ($enviosCount > 0) {
            $sample = $pdo->query("SELECT * FROM envios ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            echo "<h3 style='margin-top:14px;font-size:13px;'>📋 Muestra (últimos 5):</h3>";
            echo "<table><tr>";
            foreach (array_keys($sample[0]) as $k) echo "<th>$k</th>";
            echo "</tr>";
            foreach ($sample as $r) {
                echo "<tr>";
                foreach ($r as $v) echo "<td>" . htmlspecialchars(mb_substr((string)$v, 0, 50)) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        echo "</div>";
    }

    // ── 4. Check other key tables ──────────────────────────────────────────
    echo "<div class='box'><h2>🔍 Tablas adicionales con datos de cliente</h2>";

    $otherTables = [
        'transacciones_pv' => 'Transacciones en Puntos Voltika',
        'subscripciones_credito' => 'Créditos y suscripciones',
        'pagos_credito' => 'Pagos de créditos',
        'pagos_credito_historial' => 'Historial de pagos créditos',
        'firmas_contratos' => 'Contratos firmados',
        'checklist_origen' => 'Checklist origen',
        'recepcion_punto' => 'Recepción en puntos',
        'stripe_webhook_phantom' => 'Webhooks fantasma',
    ];

    echo "<table><tr><th>Tabla</th><th>Descripción</th><th>Registros</th><th>Columnas relevantes</th></tr>";
    foreach ($otherTables as $t => $desc) {
        try {
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            $cols = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN);
            $relevant = array_filter($cols, fn($c) => preg_match('/nombre|email|telefono|direccion|cliente|pedido|stripe|monto|rfc/i', $c));
            echo "<tr>";
            echo "<td><code>$t</code></td>";
            echo "<td>$desc</td>";
            echo "<td><strong>" . number_format($cnt) . "</strong></td>";
            echo "<td style='font-size:10.5px;'>" . implode(', ', array_map(fn($c)=>"<code>$c</code>", array_slice($relevant,0,10))) . "</td>";
            echo "</tr>";
        } catch (Exception $e) {
            echo "<tr><td><code>$t</code></td><td>$desc</td><td colspan='2' class='warn'>no existe</td></tr>";
        }
    }
    echo "</table></div>";

    if ($mode === 'analyze') {
        echo "<div class='alert-warn'>";
        echo "<strong>Modo ANÁLISIS:</strong> Información diagnóstica mostrada.<br>";
        echo "Para generar SQL de recovery, cambia <code>&mode=analyze</code> por <code>&mode=dry</code>.<br>";
        echo "Agrega <code>&stripe=1</code> para enriquecer con datos de Stripe (lento pero completo).";
        echo "</div>";
        exit;
    }

    // ── 5. Generate recovery INSERTs ───────────────────────────────────────
    if (empty($missingPagos)) {
        echo "<div class='alert-ok'>✓ Todos los pagos ya tienen transacciones correspondiente. Nada que recuperar.</div>";
        exit;
    }

    // Optionally fetch Stripe data for each missing payment
    $stripeData = [];
    if ($useStripe && defined('STRIPE_SECRET_KEY') && STRIPE_SECRET_KEY) {
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
        echo "<div class='box'><h2>🔄 Consultando Stripe para " . count($missingPagos) . " pagos…</h2><p>Por favor espera…</p></div>";
        flush();

        foreach ($missingPagos as $p) {
            $piId = $p['stripe_payment_intent_id'];
            try {
                $pi = \Stripe\PaymentIntent::retrieve([
                    'id' => $piId,
                    'expand' => ['customer','latest_charge.billing_details']
                ]);
                $ch = $pi->latest_charge ?? null;
                $cu = $pi->customer ?? null;
                $md = $pi->metadata ? $pi->metadata->toArray() : [];
                $bill = $ch ? $ch->billing_details : null;
                $billAddr = $bill ? $bill->address : null;
                $ship = $pi->shipping ?? null;
                $shipAddr = $ship ? $ship->address : null;

                $stripeData[$piId] = [
                    'nombre' => trim(($md['nombre'] ?? '') . ' ' . ($md['apellidos'] ?? '')) ?: ($bill->name ?? ($cu->name ?? '')),
                    'email' => $md['email'] ?? ($bill->email ?? ($cu->email ?? $p['email'] ?? '')),
                    'telefono' => $md['telefono'] ?? ($bill->phone ?? ($cu->phone ?? '')),
                    'modelo' => $md['modelo'] ?? '',
                    'color' => $md['color'] ?? '',
                    'tpago' => $md['tpago'] ?? $md['method'] ?? $p['metodo_pago'] ?? '',
                    'msi_meses' => $md['msi_meses'] ?? ($p['msi_activado'] ? '9' : '0'),
                    'punto_id' => $md['punto_id'] ?? '',
                    'punto_nombre' => $md['punto_nombre'] ?? '',
                    'ciudad' => ($billAddr->city ?? '') ?: ($md['ciudad'] ?? ''),
                    'estado' => ($billAddr->state ?? '') ?: ($md['estado'] ?? ''),
                    'cp' => ($billAddr->postal_code ?? '') ?: ($md['cp'] ?? ''),
                    'direccion' => $billAddr ? trim(($billAddr->line1 ?? '') . ' ' . ($billAddr->line2 ?? '')) : '',
                    'e_nombre' => $ship->name ?? '',
                    'e_telefono' => $ship->phone ?? '',
                    'e_direccion' => $shipAddr ? trim(($shipAddr->line1 ?? '') . ' ' . ($shipAddr->line2 ?? '')) : '',
                    'e_ciudad' => $shipAddr->city ?? '',
                    'e_estado' => $shipAddr->state ?? '',
                    'e_cp' => $shipAddr->postal_code ?? '',
                    'freg' => date('Y-m-d H:i', $pi->created),
                    'total' => ($pi->amount_received ?: $pi->amount) / 100,
                ];
            } catch (Exception $e) {
                // PI not found in current Stripe account (might be from other env)
            }
        }
        echo "<div class='alert-ok'>✓ Datos de Stripe obtenidos para " . count($stripeData) . " de " . count($missingPagos) . " pagos</div>";
    }

    // ── 6. Build INSERTs ───────────────────────────────────────────────────
    $rows = [];
    foreach ($missingPagos as $p) {
        $piId = $p['stripe_payment_intent_id'];
        $s = $stripeData[$piId] ?? [];

        $rows[] = [
            'pedido'      => 'PAGOS-' . $p['id'],
            'nombre'      => $s['nombre'] ?? '',
            'telefono'    => $s['telefono'] ?? '',
            'email'       => $s['email'] ?? ($p['email'] ?? ''),
            'direccion'   => $s['direccion'] ?? '',
            'ciudad'      => $s['ciudad'] ?? '',
            'estado'      => $s['estado'] ?? '',
            'cp'          => $s['cp'] ?? '',
            'e_nombre'    => $s['e_nombre'] ?? '',
            'e_telefono'  => $s['e_telefono'] ?? '',
            'e_direccion' => $s['e_direccion'] ?? '',
            'e_ciudad'    => $s['e_ciudad'] ?? '',
            'e_estado'    => $s['e_estado'] ?? '',
            'e_cp'        => $s['e_cp'] ?? '',
            'modelo'      => $s['modelo'] ?? '',
            'color'       => $s['color'] ?? '',
            'tpago'       => $s['tpago'] ?? ($p['metodo_pago'] ?? ''),
            'msi_meses'   => $s['msi_meses'] ?? ($p['msi_activado'] ? '9' : '0'),
            'punto_id'    => $s['punto_id'] ?? '',
            'punto_nombre'=> $s['punto_nombre'] ?? '',
            'precio'      => $s['total'] ?? ($p['monto'] ?? 0),
            'total'       => $s['total'] ?? ($p['monto'] ?? 0),
            'freg'        => $s['freg'] ?? date('Y-m-d H:i'),
            'stripe_pi'   => $piId,
            'pago_estado' => $p['estado'] ?? '',
        ];
    }

    echo "<div class='box'><h2>📝 Registros a INSERTAR en transacciones</h2>";
    echo "<div><span class='kpi red'><span class='l'>Se insertarán</span><span class='n'>" . count($rows) . "</span></span></div>";

    // Preview table
    echo "<h3 style='font-size:13px;margin-top:14px;'>Preview (primeros 15):</h3>";
    echo "<table><tr><th>pedido</th><th>nombre</th><th>email</th><th>modelo</th><th>total</th><th>Stripe PI</th></tr>";
    foreach (array_slice($rows, 0, 15) as $r) {
        echo "<tr>";
        echo "<td><code>" . htmlspecialchars($r['pedido']) . "</code></td>";
        echo "<td>" . htmlspecialchars($r['nombre']) . "</td>";
        echo "<td>" . htmlspecialchars($r['email']) . "</td>";
        echo "<td>" . htmlspecialchars($r['modelo']) . "</td>";
        echo "<td>\$" . htmlspecialchars($r['total']) . "</td>";
        echo "<td><code>" . htmlspecialchars(substr($r['stripe_pi'], 0, 22)) . "</code></td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "</div>";

    if ($mode === 'dry') {
        echo "<div class='alert-warn'>";
        echo "<strong>Modo DRY:</strong> No se ha insertado nada.<br>";
        echo "Para aplicar, cambia <code>&mode=dry</code> por <code>&mode=apply</code>.";
        echo "</div>";
        exit;
    }

    // ── 7. APPLY ───────────────────────────────────────────────────────────
    $pdo->beginTransaction();
    $inserted = 0;
    try {
        foreach ($rows as $r) {
            $cols = array_keys($r);
            $placeholders = array_map(fn($c) => ":$c", $cols);
            $sql = "INSERT IGNORE INTO transacciones (referido, " . implode(',', array_map(fn($c)=>"`$c`", $cols)) . ") VALUES ('', " . implode(',', $placeholders) . ")";
            $stmt = $pdo->prepare($sql);
            $params = [];
            foreach ($r as $k => $v) $params[":$k"] = $v;
            $stmt->execute($params);
            $inserted++;
        }
        $pdo->commit();

        echo "<div class='alert-ok'>";
        echo "<h2 style='color:#166534;'>✅ Recovery aplicado</h2>";
        echo "<div>";
        echo "<span class='kpi green'><span class='l'>Filas insertadas</span><span class='n'>$inserted</span></span>";
        echo "</div>";
        echo "<p>Revisa transacciones en phpMyAdmin. Los nuevos registros tienen <code>pedido</code> con prefijo <code>PAGOS-</code>.</p>";
        echo "</div>";
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "<div class='alert-err'>❌ Error — ROLLBACK: " . htmlspecialchars($e->getMessage()) . "</div>";
    }

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo "<div class='alert-err'><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
}
?>
</body></html>
