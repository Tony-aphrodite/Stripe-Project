<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 * VOLTIKA · STRIPE FULL RECOVERY (shipping + customer + billing)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Purpose:
 *   Extracts EVERY piece of information Stripe has about successful payments:
 *
 *     ✓ Customer name, email, phone
 *     ✓ Billing address (direccion, ciudad, estado, cp)
 *     ✓ Shipping address (e_direccion, e_ciudad, e_estado, e_cp) ⭐
 *     ✓ Shipping contact name + phone (e_nombre, e_telefono) ⭐
 *     ✓ Model, color, payment method (from metadata)
 *     ✓ MSI months, installment plan
 *     ✓ Delivery point (punto_id, punto_nombre)
 *     ✓ Stripe PaymentIntent ID
 *     ✓ Exact amount and date
 *     ✓ Succeeded status only (filter out failed)
 *
 *   Then cross-references each record against the current transacciones table
 *   and optionally inserts missing records with COMPLETE information.
 *
 * Usage:
 *   Step 1 — Scan (read-only):
 *     ?key=voltika-stripe-full-2026&mode=scan
 *
 *   Step 2 — Export CSV (for manual review):
 *     ?key=voltika-stripe-full-2026&mode=csv
 *
 *   Step 3 — Apply missing records:
 *     ?key=voltika-stripe-full-2026&mode=apply
 *
 *   Step 4 — Enrich existing records (fills empty fields from Stripe):
 *     ?key=voltika-stripe-full-2026&mode=enrich
 *
 * Safety:
 *   - All modes except "apply" and "enrich" are read-only
 *   - Transactional with auto-rollback
 *   - Uses stripe_pi as unique identifier (no duplicates)
 *   - Only inserts status=succeeded PaymentIntents
 * ═══════════════════════════════════════════════════════════════════════════
 */

set_time_limit(600);
header('Content-Type: text/html; charset=utf-8');

$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika-stripe-full-2026') {
    http_response_code(403);
    exit('Forbidden');
}

$mode = $_GET['mode'] ?? '';
if (!in_array($mode, ['scan','csv','apply','enrich'])) {
    exit('Add &mode=scan (preview), &mode=csv (export), &mode=apply (insert missing), or &mode=enrich (fill empty fields in existing)');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

$from = $_GET['from'] ?? '2025-01-01';
$to   = $_GET['to']   ?? date('Y-m-d');
$fromTs = strtotime($from . ' 00:00:00');
$toTs   = strtotime($to . ' 23:59:59');

if ($mode === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="voltika-stripe-full-' . date('Y-m-d') . '.csv"');
} else {
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Voltika · Stripe Full Recovery</title>
<style>
  body { font-family: 'Inter', sans-serif; max-width: 1400px; margin: 20px auto; padding: 20px; background: #f0f4f8; }
  .box { background: #fff; padding: 18px 22px; border-radius: 14px; box-shadow: 0 4px 20px rgba(12,35,64,.07); margin-bottom: 14px; }
  h1 { color: #0c2340; font-size: 22px; }
  h2 { color: #039fe1; font-size: 15px; margin: 0 0 10px; padding-bottom: 6px; border-bottom: 1px solid #e2e8f0; }
  .kpi-row { display: grid; grid-template-columns: repeat(auto-fill,minmax(160px,1fr)); gap: 8px; margin: 6px 0; }
  .kpi { padding: 12px; border-radius: 10px; color: #fff; }
  .kpi.blue { background: linear-gradient(135deg,#039fe1,#027db0); }
  .kpi.navy { background: linear-gradient(135deg,#0c2340,#1e3a5f); }
  .kpi.green { background: linear-gradient(135deg,#22c55e,#16a34a); }
  .kpi.warn { background: linear-gradient(135deg,#f59e0b,#d97706); }
  .kpi .n { font-size: 20px; font-weight: 800; display: block; }
  .kpi .l { font-size: 10px; text-transform: uppercase; opacity: .85; letter-spacing: .5px; }
  table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
  th, td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
  th { background: #f5f7fa; font-size: 10px; text-transform: uppercase; color: #64748b; letter-spacing: .5px; }
  code { background: #f1f5f9; padding: 1px 5px; border-radius: 3px; font-size: 10.5px; font-family: 'SF Mono',Consolas,monospace; }
  .ok { color: #16a34a; font-weight: 700; }
  .warn { color: #d97706; font-weight: 700; }
  .err { color: #dc2626; font-weight: 700; }
  .pill-ok { background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 50px; font-size: 10px; font-weight: 700; }
  .pill-missing { background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 50px; font-size: 10px; font-weight: 700; }
  .alert-ok { background: #dcfce7; border-left: 4px solid #16a34a; padding: 10px 14px; border-radius: 8px; color: #166534; margin-bottom: 10px; }
  .alert-warn { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 10px 14px; border-radius: 8px; color: #92400e; margin-bottom: 10px; }
  .alert-err { background: #fee2e2; border-left: 4px solid #dc2626; padding: 10px 14px; border-radius: 8px; color: #991b1b; margin-bottom: 10px; }
  .mode-apply { background: linear-gradient(135deg,#22c55e,#16a34a); color: #fff; padding: 4px 12px; border-radius: 50px; font-size: 11px; font-weight: 700; }
  .mode-scan { background: linear-gradient(135deg,#039fe1,#027db0); color: #fff; padding: 4px 12px; border-radius: 50px; font-size: 11px; font-weight: 700; }
  .mode-enrich { background: linear-gradient(135deg,#a855f7,#9333ea); color: #fff; padding: 4px 12px; border-radius: 50px; font-size: 11px; font-weight: 700; }
</style>
</head>
<body>
<div class="box">
  <h1>💎 Voltika · Stripe Full Recovery · <span class="mode-<?= $mode === 'enrich' ? 'enrich' : ($mode === 'apply' ? 'apply' : 'scan') ?>"><?= strtoupper($mode) ?></span></h1>
  <p style="font-size:12px;color:#64748b;">Rango: <strong><?= htmlspecialchars($from) ?> → <?= htmlspecialchars($to) ?></strong> · Status: <code>succeeded</code></p>
</div>
    <?php
}

try {
    if (!defined('STRIPE_SECRET_KEY') || !STRIPE_SECRET_KEY) {
        throw new Exception('STRIPE_SECRET_KEY no está configurada');
    }
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

    // ═══════════════════════════════════════════════════════════════════════
    // FETCH Stripe PaymentIntents with ALL related objects expanded
    // ═══════════════════════════════════════════════════════════════════════
    if ($mode !== 'csv') {
        echo "<div class='box'><h2>📥 Descargando de Stripe (con shipping, customer, charges expandidos)…</h2>";
        flush();
    }

    $allData = [];
    $startingAfter = null;
    $page = 0;

    do {
        $page++;
        $params = [
            'limit' => 100,
            'created' => ['gte' => $fromTs, 'lte' => $toTs],
            'expand' => [
                'data.customer',
                'data.latest_charge',
                'data.latest_charge.payment_method_details',
                'data.latest_charge.billing_details',
            ],
        ];
        if ($startingAfter) $params['starting_after'] = $startingAfter;

        $response = \Stripe\PaymentIntent::all($params);

        foreach ($response->data as $pi) {
            if ($pi->status !== 'succeeded') continue;

            // Extract ALL possible fields
            $charge = $pi->latest_charge ?? null;
            $customer = $pi->customer ?? null;
            $metadata = $pi->metadata ? $pi->metadata->toArray() : [];

            // Billing details (from card payment form)
            $billing = $charge ? ($charge->billing_details ?? null) : null;
            $billAddr = $billing ? ($billing->address ?? null) : null;

            // Shipping details (if set on PaymentIntent)
            $shipping = $pi->shipping ?? null;
            $shipAddr = $shipping ? ($shipping->address ?? null) : null;

            // Payment method details (card type, msi, etc)
            $pmDetails = $charge ? ($charge->payment_method_details ?? null) : null;
            $cardDetails = $pmDetails ? ($pmDetails->card ?? null) : null;
            $msi = $cardDetails && isset($cardDetails->installments) ? $cardDetails->installments : null;

            $record = [
                'stripe_pi'    => $pi->id,
                'freg'         => date('Y-m-d H:i', $pi->created),
                'status'       => $pi->status,
                'amount'       => ($pi->amount_received ?: $pi->amount) / 100,
                'currency'     => strtoupper($pi->currency),

                // Customer/metadata-based fields
                'pedido'       => $metadata['pedido'] ?? ('STRIPE-' . substr($pi->id, 3, 12)),
                'nombre'       => trim(($metadata['nombre'] ?? '') . ' ' . ($metadata['apellidos'] ?? '')),
                'email'        => $metadata['email'] ?? ($billing->email ?? ($customer ? $customer->email : '')),
                'telefono'     => $metadata['telefono'] ?? ($billing->phone ?? ($customer ? $customer->phone : '')),
                'modelo'       => $metadata['modelo'] ?? '',
                'color'        => $metadata['color'] ?? '',
                'tpago'        => $metadata['tpago'] ?? $metadata['method'] ?? 'Tarjeta de débito o crédito',
                'msi_meses'    => $metadata['msi_meses'] ?? ($msi && $msi->plan ? $msi->plan->count : '0'),
                'punto_id'     => $metadata['punto_id'] ?? '',
                'punto_nombre' => $metadata['punto_nombre'] ?? '',

                // Billing address (direccion)
                'direccion'    => $billAddr ? trim(($billAddr->line1 ?? '') . ' ' . ($billAddr->line2 ?? '')) : '',
                'ciudad'       => ($billAddr->city ?? '') ?: ($metadata['ciudad'] ?? ''),
                'estado'       => ($billAddr->state ?? '') ?: ($metadata['estado'] ?? ''),
                'cp'           => ($billAddr->postal_code ?? '') ?: ($metadata['cp'] ?? ''),

                // Shipping address (e_*)
                'e_nombre'     => $shipping ? ($shipping->name ?? '') : '',
                'e_telefono'   => $shipping ? ($shipping->phone ?? '') : '',
                'e_direccion'  => $shipAddr ? trim(($shipAddr->line1 ?? '') . ' ' . ($shipAddr->line2 ?? '')) : '',
                'e_ciudad'     => $shipAddr ? ($shipAddr->city ?? '') : '',
                'e_estado'     => $shipAddr ? ($shipAddr->state ?? '') : '',
                'e_cp'         => $shipAddr ? ($shipAddr->postal_code ?? '') : '',

                'tenvio'       => $shipping ? 'Envío a domicilio' : ($metadata['tenvio'] ?? ''),

                // Card info (masked)
                'card_brand'   => $cardDetails ? ($cardDetails->brand ?? '') : '',
                'card_last4'   => $cardDetails ? ($cardDetails->last4 ?? '') : '',

                // Customer object info
                'customer_id'  => $customer ? $customer->id : '',
                'receipt_url'  => $charge ? ($charge->receipt_url ?? '') : '',
            ];

            $allData[] = $record;
        }

        $startingAfter = ($response->has_more && count($response->data) > 0)
            ? end($response->data)->id
            : null;
    } while ($startingAfter && $page < 100);

    // ═══════════════════════════════════════════════════════════════════════
    // CSV EXPORT MODE
    // ═══════════════════════════════════════════════════════════════════════
    if ($mode === 'csv') {
        $out = fopen('php://output', 'w');
        fputcsv($out, array_keys($allData[0] ?? [
            'stripe_pi','freg','status','amount','currency','pedido','nombre','email','telefono',
            'modelo','color','tpago','msi_meses','punto_id','punto_nombre',
            'direccion','ciudad','estado','cp',
            'e_nombre','e_telefono','e_direccion','e_ciudad','e_estado','e_cp',
            'tenvio','card_brand','card_last4','customer_id','receipt_url'
        ]));
        foreach ($allData as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Compare with DB
    // ═══════════════════════════════════════════════════════════════════════
    $pdo = getDB();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $dbStripePIs = $pdo->query("SELECT stripe_pi FROM transacciones WHERE stripe_pi IS NOT NULL AND stripe_pi <> ''")->fetchAll(PDO::FETCH_COLUMN);
    $dbSet = array_flip($dbStripePIs);

    $missing = [];
    $present = [];
    foreach ($allData as $r) {
        if (isset($dbSet[$r['stripe_pi']])) {
            $present[] = $r;
        } else {
            $missing[] = $r;
        }
    }

    echo "<div class='box'>";
    echo "<h2>🔑 Conexión Stripe</h2>";
    echo "<p>Modo API: <strong>" . (defined('APP_ENV') ? strtoupper(APP_ENV) : 'UNKNOWN') . "</strong> · Key: <code>" . substr(STRIPE_SECRET_KEY, 0, 12) . "...</code></p>";
    echo "</div>";

    echo "<div class='box'>";
    echo "<h2>📊 Resultado del análisis</h2>";
    echo "<div class='kpi-row'>";
    echo "<div class='kpi navy'><span class='l'>Exitosos en Stripe</span><span class='n'>" . count($allData) . "</span></div>";
    echo "<div class='kpi green'><span class='l'>Ya en DB</span><span class='n'>" . count($present) . "</span></div>";
    echo "<div class='kpi " . (count($missing) > 0 ? 'warn' : 'green') . "'><span class='l'>Faltantes</span><span class='n'>" . count($missing) . "</span></div>";
    echo "<div class='kpi blue'><span class='l'>Con shipping</span><span class='n'>" . count(array_filter($allData, fn($r) => !empty($r['e_direccion']))) . "</span></div>";
    echo "<div class='kpi blue'><span class='l'>Con billing</span><span class='n'>" . count(array_filter($allData, fn($r) => !empty($r['direccion']))) . "</span></div>";
    echo "</div>";
    echo "<p style='margin-top:10px;'><a href='?key=voltika-stripe-full-2026&mode=csv&from=$from&to=$to' class='mode-scan' style='text-decoration:none;padding:8px 14px;'>📥 Descargar CSV completo</a></p>";
    echo "</div>";

    // ── Show detailed table of missing records with FULL shipping info ────
    if (count($missing) > 0) {
        echo "<div class='box'>";
        echo "<h2>⚠ Faltantes en DB (" . count($missing) . ") — información completa extraída de Stripe</h2>";
        echo "<div style='overflow-x:auto;'><table>";
        echo "<tr>
            <th>Fecha</th><th>Stripe PI</th><th>Pedido</th>
            <th>Nombre</th><th>Email</th><th>Teléfono</th>
            <th>Dirección (billing)</th>
            <th>Env. Nombre</th><th>Env. Teléfono</th>
            <th>Env. Dirección ⭐</th>
            <th>Modelo</th><th>Color</th><th>Total</th>
        </tr>";
        foreach ($missing as $r) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($r['freg']) . "</td>";
            echo "<td><code>" . htmlspecialchars(substr($r['stripe_pi'], 0, 20)) . "…</code></td>";
            echo "<td>" . htmlspecialchars($r['pedido']) . "</td>";
            echo "<td>" . htmlspecialchars($r['nombre']) . "</td>";
            echo "<td>" . htmlspecialchars($r['email']) . "</td>";
            echo "<td>" . htmlspecialchars($r['telefono']) . "</td>";
            echo "<td>" . htmlspecialchars(trim($r['direccion'] . ', ' . $r['ciudad'] . ', ' . $r['estado'] . ' ' . $r['cp'], ', ')) . "</td>";
            echo "<td>" . htmlspecialchars($r['e_nombre']) . "</td>";
            echo "<td>" . htmlspecialchars($r['e_telefono']) . "</td>";
            echo "<td>" . htmlspecialchars(trim($r['e_direccion'] . ', ' . $r['e_ciudad'] . ', ' . $r['e_estado'] . ' ' . $r['e_cp'], ', ')) . "</td>";
            echo "<td>" . htmlspecialchars($r['modelo']) . "</td>";
            echo "<td>" . htmlspecialchars($r['color']) . "</td>";
            echo "<td>\$" . number_format($r['amount']) . "</td>";
            echo "</tr>";
        }
        echo "</table></div>";
        echo "</div>";
    }

    // ═══════════════════════════════════════════════════════════════════════
    // APPLY MODE: insert missing records
    // ═══════════════════════════════════════════════════════════════════════
    if ($mode === 'apply') {
        if (count($missing) === 0) {
            echo "<div class='alert-ok'>✓ No hay nada que insertar — todos los PaymentIntents ya están en DB.</div>";
        } else {
            $pdo->beginTransaction();
            $inserted = 0;

            foreach ($missing as $r) {
                $sql = "INSERT INTO transacciones
                    (pedido, referido, nombre, telefono, email, razon, rfc, direccion, ciudad, estado, cp,
                     e_nombre, e_telefono, e_direccion, e_ciudad, e_estado, e_cp,
                     modelo, color, tpago, tenvio, precio, penvio, total, freg, stripe_pi,
                     punto_id, punto_nombre, msi_meses)
                    VALUES
                    (:pedido, '', :nombre, :telefono, :email, '', '', :direccion, :ciudad, :estado, :cp,
                     :e_nombre, :e_telefono, :e_direccion, :e_ciudad, :e_estado, :e_cp,
                     :modelo, :color, :tpago, :tenvio, :precio, '0', :total, :freg, :stripe_pi,
                     :punto_id, :punto_nombre, :msi_meses)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':pedido'       => $r['pedido'],
                    ':nombre'       => $r['nombre'],
                    ':telefono'     => $r['telefono'],
                    ':email'        => $r['email'],
                    ':direccion'    => $r['direccion'],
                    ':ciudad'       => $r['ciudad'],
                    ':estado'       => $r['estado'],
                    ':cp'           => $r['cp'],
                    ':e_nombre'     => $r['e_nombre'],
                    ':e_telefono'   => $r['e_telefono'],
                    ':e_direccion'  => $r['e_direccion'],
                    ':e_ciudad'     => $r['e_ciudad'],
                    ':e_estado'     => $r['e_estado'],
                    ':e_cp'         => $r['e_cp'],
                    ':modelo'       => $r['modelo'],
                    ':color'        => $r['color'],
                    ':tpago'        => $r['tpago'],
                    ':tenvio'       => $r['tenvio'],
                    ':precio'       => (string)$r['amount'],
                    ':total'        => (string)$r['amount'],
                    ':freg'         => $r['freg'],
                    ':stripe_pi'    => $r['stripe_pi'],
                    ':punto_id'     => $r['punto_id'],
                    ':punto_nombre' => $r['punto_nombre'],
                    ':msi_meses'    => $r['msi_meses'],
                ]);
                $inserted++;
            }

            $pdo->commit();
            echo "<div class='alert-ok'><strong>✅ Insertados $inserted registros con información completa (shipping + billing + customer).</strong></div>";
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ENRICH MODE: fill empty fields in EXISTING records from Stripe
    // ═══════════════════════════════════════════════════════════════════════
    if ($mode === 'enrich') {
        $enriched = 0;
        $pdo->beginTransaction();

        foreach ($present as $r) {
            $existing = $pdo->prepare("SELECT id FROM transacciones WHERE stripe_pi = ?");
            $existing->execute([$r['stripe_pi']]);
            $id = $existing->fetchColumn();
            if (!$id) continue;

            // Only update fields if they are empty in DB, using Stripe data as fallback
            $sql = "UPDATE transacciones SET
                nombre = COALESCE(NULLIF(nombre, ''), :nombre),
                telefono = COALESCE(NULLIF(telefono, ''), :telefono),
                direccion = COALESCE(NULLIF(direccion, ''), :direccion),
                ciudad = COALESCE(NULLIF(ciudad, ''), :ciudad),
                estado = COALESCE(NULLIF(estado, ''), :estado),
                cp = COALESCE(NULLIF(cp, ''), :cp),
                e_nombre = COALESCE(NULLIF(e_nombre, ''), :e_nombre),
                e_telefono = COALESCE(NULLIF(e_telefono, ''), :e_telefono),
                e_direccion = COALESCE(NULLIF(e_direccion, ''), :e_direccion),
                e_ciudad = COALESCE(NULLIF(e_ciudad, ''), :e_ciudad),
                e_estado = COALESCE(NULLIF(e_estado, ''), :e_estado),
                e_cp = COALESCE(NULLIF(e_cp, ''), :e_cp),
                modelo = COALESCE(NULLIF(modelo, ''), :modelo),
                color = COALESCE(NULLIF(color, ''), :color),
                tenvio = COALESCE(NULLIF(tenvio, ''), :tenvio),
                punto_id = COALESCE(NULLIF(punto_id, ''), :punto_id),
                punto_nombre = COALESCE(NULLIF(punto_nombre, ''), :punto_nombre)
                WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':nombre'       => $r['nombre'],
                ':telefono'     => $r['telefono'],
                ':direccion'    => $r['direccion'],
                ':ciudad'       => $r['ciudad'],
                ':estado'       => $r['estado'],
                ':cp'           => $r['cp'],
                ':e_nombre'     => $r['e_nombre'],
                ':e_telefono'   => $r['e_telefono'],
                ':e_direccion'  => $r['e_direccion'],
                ':e_ciudad'     => $r['e_ciudad'],
                ':e_estado'     => $r['e_estado'],
                ':e_cp'         => $r['e_cp'],
                ':modelo'       => $r['modelo'],
                ':color'        => $r['color'],
                ':tenvio'       => $r['tenvio'],
                ':punto_id'     => $r['punto_id'],
                ':punto_nombre' => $r['punto_nombre'],
                ':id'           => $id,
            ]);
            $enriched += $stmt->rowCount();
        }
        $pdo->commit();
        echo "<div class='alert-ok'><strong>✅ Enriquecidos $enriched registros existentes con datos adicionales de Stripe (shipping, billing, cliente).</strong></div>";
    }

    if ($mode === 'scan') {
        echo "<div class='alert-warn'>";
        echo "<strong>🧪 Modo SCAN:</strong> No se ha modificado nada. Acciones disponibles:";
        echo "<ul>";
        echo "<li><code>?mode=csv</code> — Descarga todos los datos como CSV (para revisar en Excel)</li>";
        echo "<li><code>?mode=apply</code> — Inserta los " . count($missing) . " registros faltantes con información completa</li>";
        echo "<li><code>?mode=enrich</code> — Enriquece los " . count($present) . " registros existentes (rellena campos vacíos con datos de Stripe)</li>";
        echo "</ul>";
        echo "</div>";
    }

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo "<div class='alert-err'><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
}

if ($mode !== 'csv') echo "</body></html>";
