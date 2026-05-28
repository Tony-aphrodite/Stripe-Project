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
$sql = "
SELECT
  m.id AS moto_id,
  m.cliente_nombre,
  m.cliente_email,
  m.cliente_telefono,
  m.modelo,
  m.color,
  COALESCE(m.vin_display, m.vin) AS vin,
  m.estado AS moto_estado,
  m.freg AS moto_freg,
  m.transaccion_id,
  t.contrato_pdf_path,
  t.contrato_pdf_hash,
  t.cincel_timestamp_hash,
  t.tpago,
  t.fecha_estimada_entrega,
  sc.id AS sub_id,
  sc.status AS sub_status,
  sc.estado AS sub_estado,
  sc.monto_semanal,
  sc.plazo_semanas,
  sc.fecha_inicio,
  sc.fecha_entrega,
  sc.cobro_fallos_seguidos,
  sc.stripe_payment_method_id,
  ce.id AS checklist_id,
  ce.pagare_pdf_path,
  ce.pagare_status,
  ce.cincel_pagare_timestamp_hash,
  ce.completado AS checklist_completado
FROM inventario_motos m
LEFT JOIN transacciones t ON t.id = m.transaccion_id
LEFT JOIN subscripciones_credito sc ON sc.inventario_moto_id = m.id
LEFT JOIN checklist_entrega_v2 ce ON ce.moto_id = m.id
WHERE m.estado IN ('entregada','en_punto','asignada')
  AND (t.tpago = 'enganche' OR sc.id IS NOT NULL)
ORDER BY (m.estado = 'entregada') DESC, m.id DESC
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
$entregadas = $contratoOk = $pagareOk = $cincelOk = $subActiva = 0;
foreach ($rows as $r) {
    if ($r['moto_estado'] === 'entregada') $entregadas++;
    if (!empty($r['contrato_pdf_path']))   $contratoOk++;
    if (!empty($r['pagare_pdf_path']))     $pagareOk++;
    if (!empty($r['cincel_pagare_timestamp_hash'])) $cincelOk++;
    if (in_array($r['sub_estado'] ?? '', ['activa','active'], true)) $subActiva++;
}

echo '<div class="summary">';
echo '<div class="stat"><div class="stat-label">Total créditos</div><div class="stat-num">' . $total . '</div></div>';
echo '<div class="stat stat-ok"><div class="stat-label">Entregadas</div><div class="stat-num">' . $entregadas . '</div></div>';
echo '<div class="stat ' . ($contratoOk === $total ? 'stat-ok' : 'stat-warn') . '"><div class="stat-label">Contrato firmado</div><div class="stat-num">' . $contratoOk . '/' . $total . '</div></div>';
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
    $alerts = [];
    if (empty($r['contrato_pdf_path']))   $alerts[] = 'Contrato faltante';
    if (empty($r['pagare_pdf_path']) && $r['moto_estado'] === 'entregada') $alerts[] = 'PAGARÉ faltante';
    if (empty($r['cincel_pagare_timestamp_hash']) && !empty($r['pagare_pdf_path'])) $alerts[] = 'Sin sello Cincel';
    if (empty($r['sub_id'])) $alerts[] = 'Sin subscripción';
    elseif (!in_array($r['sub_estado'] ?? '', ['activa','active'], true)) $alerts[] = 'Sub no activa: ' . $r['sub_estado'];
    if ((int)($r['cobro_fallos_seguidos'] ?? 0) >= 3) $alerts[] = 'Stripe falló ' . (int)$r['cobro_fallos_seguidos'] . 'x';
    if (empty($r['stripe_payment_method_id'])) $alerts[] = 'Sin tarjeta activa';
    if ($stats['overdue'] > 0) $alerts[] = $stats['overdue'] . ' ciclos vencidos';
    if (!empty($r['sub_id']) && $stats['total'] === 0) $alerts[] = 'Ciclos no generados';

    echo '<tr>';
    // Cliente
    echo '<td><div class="client-name">' . htmlspecialchars((string)($r['cliente_nombre'] ?? '—')) . '</div>'
       . '<div class="client-contact">' . htmlspecialchars((string)($r['cliente_telefono'] ?? '')) . '</div>'
       . '<div class="client-contact">VIN: ' . htmlspecialchars(substr((string)($r['vin'] ?? '—'), 0, 17)) . '</div></td>';
    // Vehículo
    echo '<td>' . htmlspecialchars((string)($r['modelo'] ?? '—')) . '<br><span class="muted-cell">' . htmlspecialchars((string)($r['color'] ?? '')) . '</span></td>';
    // Estado moto
    $estClass = $r['moto_estado'] === 'entregada' ? 'b-ok' : ($r['moto_estado'] === 'en_punto' ? 'b-info' : 'b-muted');
    echo '<td><span class="badge ' . $estClass . '">' . htmlspecialchars((string)$r['moto_estado']) . '</span></td>';
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
