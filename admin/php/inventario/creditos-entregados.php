<?php
/**
 * Voltika Admin — Vista unificada de créditos entregados.
 *
 * Customer brief 2026-05-28: "until today we can't see the subscription of
 * the credit we already deliver". Cobranza solo muestra ciclos overdue,
 * Ventas solo muestra el momento de venta — falta una vista holística que
 * cruce inventario_motos × subscripciones_credito × checklist × ciclos_pago.
 *
 * Esta página lista TODOS los clientes de crédito con motos entregadas y
 * muestra de un vistazo:
 *   - Datos del cliente (nombre, teléfono, email)
 *   - Vehículo (modelo, VIN, fecha de entrega)
 *   - Estado del contrato (PDF firmado, NOM-151)
 *   - Estado del PAGARÉ (PDF generado, NOM-151)
 *   - Estado de la subscripción (activa/pendiente, monto semanal, plazo)
 *   - Progreso de pagos (pagados/totales)
 *   - Próximo vencimiento
 *   - Indicadores de problemas
 *
 * Auth: admin only.
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Créditos entregados</title>';
echo '<style>
body{font-family:system-ui,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1480px;margin:0 auto;line-height:1.5;}
h1{font-size:22px;margin:0 0 4px;}
.muted{color:#94a3b8;font-size:12px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin-bottom:14px;}
table{width:100%;border-collapse:collapse;font-size:12px;}
th{background:#f1f5f9;padding:8px 9px;text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:#475569;}
td{padding:8px 9px;border-top:1px solid #f1f5f9;vertical-align:top;}
tr:hover td{background:#f8fafc;}
.ok{color:#16a34a;font-weight:700;}
.warn{color:#d97706;font-weight:700;}
.err{color:#dc2626;font-weight:700;}
.muted-cell{color:#94a3b8;}
.badge{display:inline-block;padding:2px 7px;border-radius:10px;font-size:10.5px;font-weight:600;}
.b-ok{background:#dcfce7;color:#15803d;}
.b-warn{background:#fef3c7;color:#92400e;}
.b-err{background:#fee2e2;color:#991b1b;}
.b-info{background:#dbeafe;color:#1e40af;}
.b-muted{background:#f1f5f9;color:#64748b;}
.client-name{font-weight:600;color:#0c2340;}
.client-contact{font-size:10.5px;color:#64748b;}
.amt{font-family:ui-monospace,monospace;font-weight:600;}
.summary{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:14px;}
.stat{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;}
.stat-label{font-size:10.5px;color:#64748b;text-transform:uppercase;letter-spacing:.4px;}
.stat-num{font-size:22px;font-weight:700;color:#0c2340;margin-top:3px;}
.stat-ok .stat-num{color:#16a34a;}
.stat-warn .stat-num{color:#d97706;}
.stat-err .stat-num{color:#dc2626;}
</style></head><body>';

echo '<h1>💳 Créditos entregados — Vista unificada</h1>';
echo '<p class="muted">Cliente · Vehículo · Contrato · PAGARÉ · Subscripción · Pagos · Próximo vencimiento</p>';

// ── Query: all credit deliveries with full join ────────────────────────
// Start from subscripciones_credito (one row per credit customer).
// This guarantees we see EVERY credit customer, regardless of moto state.
// Then LEFT JOIN moto/transaccion/checklist for additional context.
$sql = "
SELECT
  sc.id AS sub_id,
  sc.cliente_id,
  sc.nombre AS sub_nombre,
  sc.email AS sub_email,
  sc.telefono AS sub_telefono,
  sc.status AS sub_status,
  sc.estado AS sub_estado,
  sc.monto_semanal,
  sc.plazo_semanas,
  sc.fecha_inicio,
  sc.fecha_entrega,
  sc.cobro_fallos_seguidos,
  sc.stripe_payment_method_id,
  sc.inventario_moto_id,
  sc.modelo AS sub_modelo,
  sc.color AS sub_color,
  sc.freg AS sub_freg,
  m.cliente_nombre,
  m.cliente_email,
  m.cliente_telefono,
  m.modelo,
  m.color,
  COALESCE(m.vin_display, m.vin) AS vin,
  m.estado AS moto_estado,
  m.transaccion_id,
  t.contrato_pdf_path,
  t.contrato_pdf_hash,
  t.cincel_timestamp_hash,
  t.tpago,
  t.pago_estado,
  t.fecha_estimada_entrega,
  ce.id AS checklist_id,
  ce.pagare_pdf_path,
  ce.pagare_status,
  ce.cincel_pagare_timestamp_hash,
  ce.completado AS checklist_completado
FROM subscripciones_credito sc
LEFT JOIN inventario_motos m ON m.id = sc.inventario_moto_id
LEFT JOIN transacciones t ON t.id = m.transaccion_id
LEFT JOIN checklist_entrega_v2 ce
       ON ce.id = (SELECT MAX(id) FROM checklist_entrega_v2 WHERE moto_id = m.id)
WHERE sc.id = (SELECT MAX(id) FROM subscripciones_credito sc2
               WHERE sc2.cliente_id = sc.cliente_id
                  OR (sc2.email = sc.email AND sc2.telefono = sc.telefono))
ORDER BY (m.estado = 'entregada') DESC, sc.id DESC
LIMIT 200
";

try {
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo '<div class="card" style="background:#fee2e2;color:#991b1b;">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '</body></html>'; exit;
}

if (!$rows) {
    echo '<div class="card">No se encontraron créditos.</div></body></html>'; exit;
}

// ── Summary stats ──────────────────────────────────────────────────────
$total = count($rows);
$entregadas = $contratoOk = $pagareOk = $cincelOk = $subActiva = $sinMoto = 0;
foreach ($rows as $r) {
    if (($r['moto_estado'] ?? '') === 'entregada') $entregadas++;
    if (empty($r['inventario_moto_id'])) $sinMoto++;
    if (!empty($r['contrato_pdf_path']))   $contratoOk++;
    if (!empty($r['pagare_pdf_path']))     $pagareOk++;
    if (!empty($r['cincel_pagare_timestamp_hash'])) $cincelOk++;
    if (in_array($r['sub_estado'] ?? '', ['activa','active'], true)) $subActiva++;
}

echo '<div class="summary">';
echo '<div class="stat"><div class="stat-label">Total subs crédito</div><div class="stat-num">' . $total . '</div></div>';
echo '<div class="stat stat-ok"><div class="stat-label">Entregadas</div><div class="stat-num">' . $entregadas . '/' . $total . '</div></div>';
echo '<div class="stat ' . ($sinMoto === 0 ? 'stat-ok' : 'stat-err') . '"><div class="stat-label">Sin moto asignada</div><div class="stat-num">' . $sinMoto . '</div></div>';
echo '<div class="stat ' . ($pagareOk === $entregadas ? 'stat-ok' : 'stat-warn') . '"><div class="stat-label">PAGARÉ generado</div><div class="stat-num">' . $pagareOk . '/' . $entregadas . '</div></div>';
echo '<div class="stat ' . ($cincelOk === $pagareOk ? 'stat-ok' : 'stat-warn') . '"><div class="stat-label">NOM-151 sellado</div><div class="stat-num">' . $cincelOk . '/' . $pagareOk . '</div></div>';
echo '</div>';

// ── Helper: compute payment progress per subscription ──────────────────
function getCiclosStats(PDO $pdo, ?int $subId): array {
    if (!$subId) return ['total'=>0,'pagados'=>0,'pending'=>0,'overdue'=>0,'next_due'=>null];
    try {
        $q = $pdo->prepare("SELECT estado, COUNT(*) n, MIN(CASE WHEN estado='pending' AND fecha_vencimiento >= CURDATE() THEN fecha_vencimiento END) AS next_due
                            FROM ciclos_pago WHERE subscripcion_id=? GROUP BY estado");
        $q->execute([$subId]);
        $stats = ['total'=>0,'pagados'=>0,'pending'=>0,'overdue'=>0,'next_due'=>null];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $stats['total'] += (int)$r['n'];
            if (in_array($r['estado'], ['paid_auto','paid_manual'], true)) $stats['pagados'] += (int)$r['n'];
            elseif ($r['estado'] === 'pending') {
                $stats['pending'] += (int)$r['n'];
                if (!empty($r['next_due'])) $stats['next_due'] = $r['next_due'];
            } elseif ($r['estado'] === 'overdue') $stats['overdue'] += (int)$r['n'];
        }
        // get next_due explicitly
        $q2 = $pdo->prepare("SELECT MIN(fecha_vencimiento) FROM ciclos_pago
                             WHERE subscripcion_id=? AND estado='pending' AND fecha_vencimiento >= CURDATE()");
        $q2->execute([$subId]);
        $stats['next_due'] = $q2->fetchColumn() ?: null;
        return $stats;
    } catch (Throwable $e) { return ['total'=>0,'pagados'=>0,'pending'=>0,'overdue'=>0,'next_due'=>null]; }
}

// ── Main table ─────────────────────────────────────────────────────────
echo '<div class="card"><table>';
echo '<thead><tr>'
   . '<th>Cliente / VIN</th>'
   . '<th>Vehículo</th>'
   . '<th>Estado moto</th>'
   . '<th>Contrato</th>'
   . '<th>PAGARÉ</th>'
   . '<th>Subscripción</th>'
   . '<th>Pagos</th>'
   . '<th>Próx. vencimiento</th>'
   . '<th>Alertas</th>'
   . '</tr></thead><tbody>';

foreach ($rows as $r) {
    $stats = getCiclosStats($pdo, $r['sub_id'] ? (int)$r['sub_id'] : null);
    // Use moto data when available, fall back to sub data
    $clientName = $r['cliente_nombre'] ?: $r['sub_nombre'];
    $clientTel  = $r['cliente_telefono'] ?: $r['sub_telefono'];
    $clientEmail = $r['cliente_email'] ?: $r['sub_email'];
    $modelo = $r['modelo'] ?: $r['sub_modelo'];
    $color  = $r['color']  ?: $r['sub_color'];
    $motoEstado = $r['moto_estado'] ?: 'sin moto';

    $alerts = [];
    if (empty($r['inventario_moto_id'])) $alerts[] = 'Sin moto asignada';
    elseif (empty($r['contrato_pdf_path'])) $alerts[] = 'Contrato faltante';
    if (empty($r['pagare_pdf_path']) && $motoEstado === 'entregada') $alerts[] = 'PAGARÉ faltante';
    if (empty($r['cincel_pagare_timestamp_hash']) && !empty($r['pagare_pdf_path'])) $alerts[] = 'Sin sello Cincel';
    if (!in_array($r['sub_estado'] ?? '', ['activa','active'], true)) $alerts[] = 'Sub estado: ' . ($r['sub_estado'] ?: 'null');
    if ((int)($r['cobro_fallos_seguidos'] ?? 0) >= 3) $alerts[] = 'Stripe falló ' . (int)$r['cobro_fallos_seguidos'] . 'x';
    if (empty($r['stripe_payment_method_id'])) $alerts[] = 'Sin tarjeta activa';
    if ($stats['overdue'] > 0) $alerts[] = $stats['overdue'] . ' ciclos vencidos';
    if (empty($r['fecha_inicio'])) $alerts[] = 'Sin fecha_inicio (no entregada)';
    elseif ($stats['total'] === 0) $alerts[] = 'Ciclos no generados';

    echo '<tr>';
    // Cliente
    echo '<td><div class="client-name">' . htmlspecialchars((string)$clientName) . '</div>'
       . '<div class="client-contact">' . htmlspecialchars((string)$clientTel) . '</div>'
       . '<div class="client-contact">' . htmlspecialchars((string)$clientEmail) . '</div>'
       . (!empty($r['vin']) ? '<div class="client-contact">VIN: ' . htmlspecialchars(substr((string)$r['vin'], 0, 17)) . '</div>' : '')
       . '<div class="client-contact" style="font-size:10px;">sub_id=' . (int)$r['sub_id'] . '</div></td>';
    // Vehículo
    echo '<td>' . htmlspecialchars((string)($modelo ?: '—')) . '<br><span class="muted-cell">' . htmlspecialchars((string)$color) . '</span></td>';
    // Estado moto
    $estClass = $motoEstado === 'entregada' ? 'b-ok' : ($motoEstado === 'en_punto' ? 'b-info' : ($motoEstado === 'sin moto' ? 'b-err' : 'b-muted'));
    echo '<td><span class="badge ' . $estClass . '">' . htmlspecialchars($motoEstado) . '</span></td>';
    // Contrato
    if (!empty($r['contrato_pdf_path'])) {
        echo '<td><span class="badge b-ok">✓ Firmado</span>'
           . (!empty($r['cincel_timestamp_hash']) ? '<br><span class="muted-cell" style="font-size:10px;">NOM-151 ✓</span>' : '<br><span class="muted-cell" style="font-size:10px;">sin sello</span>')
           . '</td>';
    } else {
        echo '<td><span class="badge b-err">✗ Faltante</span></td>';
    }
    // PAGARÉ
    if (!empty($r['pagare_pdf_path'])) {
        echo '<td><span class="badge b-ok">✓ Generado</span>'
           . (!empty($r['cincel_pagare_timestamp_hash']) ? '<br><span class="muted-cell" style="font-size:10px;">NOM-151 ✓</span>' : '<br><span class="muted-cell" style="font-size:10px;">sin sello</span>')
           . '</td>';
    } else {
        echo '<td><span class="badge b-warn">— Pendiente</span></td>';
    }
    // Subscripción
    if (!empty($r['sub_id'])) {
        $subClass = in_array($r['sub_estado'] ?? '', ['activa','active'], true) ? 'b-ok' : 'b-warn';
        echo '<td><span class="badge ' . $subClass . '">' . htmlspecialchars((string)$r['sub_estado']) . '</span><br>'
           . '<span class="amt">$' . number_format((float)$r['monto_semanal'], 0) . '</span>/sem · '
           . (int)$r['plazo_semanas'] . ' sem<br>'
           . '<span class="muted-cell" style="font-size:10px;">desde ' . htmlspecialchars((string)($r['fecha_inicio'] ?? '?')) . '</span></td>';
    } else {
        echo '<td><span class="badge b-err">✗ Sin sub</span></td>';
    }
    // Pagos
    if ($stats['total'] > 0) {
        $pct = $stats['total'] > 0 ? round(($stats['pagados'] / $stats['total']) * 100) : 0;
        echo '<td><strong>' . $stats['pagados'] . '/' . $stats['total'] . '</strong> <span class="muted-cell">(' . $pct . '%)</span>'
           . ($stats['overdue'] > 0 ? '<br><span class="err">⚠ ' . $stats['overdue'] . ' vencidos</span>' : '')
           . '</td>';
    } else {
        echo '<td><span class="muted-cell">—</span></td>';
    }
    // Próximo vencimiento
    if (!empty($stats['next_due'])) {
        $days = (int)((strtotime($stats['next_due']) - strtotime(date('Y-m-d'))) / 86400);
        $colour = $days < 0 ? 'err' : ($days <= 7 ? 'warn' : 'ok');
        echo '<td><span class="' . $colour . '">' . htmlspecialchars($stats['next_due']) . '</span><br>'
           . '<span class="muted-cell" style="font-size:10px;">' . ($days >= 0 ? "en $days días" : abs($days) . " días tarde") . '</span></td>';
    } else {
        echo '<td><span class="muted-cell">—</span></td>';
    }
    // Alertas
    if ($alerts) {
        echo '<td>';
        foreach ($alerts as $a) echo '<span class="badge b-warn" style="margin:1px;">' . htmlspecialchars($a) . '</span> ';
        echo '</td>';
    } else {
        echo '<td><span class="badge b-ok">✓ Sin alertas</span></td>';
    }
    echo '</tr>';
}
echo '</tbody></table></div>';

echo '<p class="muted">Mostrando los primeros ' . $total . ' créditos. Para detalle de un cliente: <code>cliente-data-dump.php?moto_id=N</code> o <code>ciclos-check.php?sub_id=N</code></p>';
echo '</body></html>';
