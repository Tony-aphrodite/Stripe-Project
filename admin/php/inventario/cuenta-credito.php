<?php
/**
 * Voltika Admin — Detalle de cuenta de crédito por cliente.
 *
 * Customer brief 2026-05-29: "necesitamos saber cuántos pagos han sido pagados,
 * cuántos faltan, cuál es el total, etc". This is the per-customer credit
 * account view — payment progress, history, next payment, totals.
 *
 * Usage:
 *   ?sub_id=N  → show that subscription's account
 *   No params  → list all active credit accounts with totals
 *
 * Auth: admin only.
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin', 'cedis']);

$pdo = getDB();
$subId = (int)($_GET['sub_id'] ?? 0);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Cuenta de crédito</title>';
echo '<style>
body{font-family:system-ui,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1180px;margin:0 auto;line-height:1.5;}
h1{font-size:22px;margin:0 0 4px;}
h2{font-size:15px;color:#475569;margin:20px 0 8px;border-bottom:1px solid #cbd5e1;padding-bottom:4px;}
.muted{color:#94a3b8;font-size:12px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin-bottom:14px;}
table{width:100%;border-collapse:collapse;font-size:12.5px;}
th{background:#f1f5f9;padding:7px 9px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#475569;}
td{padding:7px 9px;border-top:1px solid #f1f5f9;}
tr:hover td{background:#f8fafc;}
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:12px 0;}
.kpi{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:11px 14px;}
.kpi-label{font-size:10.5px;color:#64748b;text-transform:uppercase;letter-spacing:.4px;}
.kpi-num{font-size:22px;font-weight:700;color:#0c2340;margin-top:3px;font-family:ui-monospace,monospace;}
.kpi.green .kpi-num{color:#16a34a;}
.kpi.amber .kpi-num{color:#d97706;}
.kpi.red .kpi-num{color:#dc2626;}
.kpi.blue .kpi-num{color:#0ea5e9;}
.progress{height:10px;background:#e2e8f0;border-radius:5px;overflow:hidden;margin-top:6px;}
.progress-fill{height:100%;background:#16a34a;transition:width .2s;}
.badge{display:inline-block;padding:2px 7px;border-radius:10px;font-size:10.5px;font-weight:600;}
.b-paid{background:#dcfce7;color:#15803d;}
.b-overdue{background:#fee2e2;color:#991b1b;}
.b-pending{background:#fef3c7;color:#92400e;}
.b-pending-today{background:#dbeafe;color:#1e40af;}
a.row-link{color:#0c2340;text-decoration:none;}
a.row-link:hover{text-decoration:underline;}
.amt{font-family:ui-monospace,monospace;font-weight:600;}
input{padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;}
button{padding:6px 14px;background:#039fe1;color:#fff;border:0;border-radius:4px;cursor:pointer;font-weight:600;}
</style></head><body>';

if ($subId > 0) {
    // ════════════ DETAIL VIEW for one subscription ════════════
    $st = $pdo->prepare("SELECT sc.*, c.nombre AS cliente_nombre
        FROM subscripciones_credito sc
        LEFT JOIN clientes c ON c.id = sc.cliente_id WHERE sc.id = ?");
    $st->execute([$subId]);
    $sub = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sub) { echo '<div class="card">Subscripción no encontrada</div></body></html>'; exit; }

    $monto = (float)$sub['monto_semanal'];
    $plazoSem = (int)$sub['plazo_semanas'];
    $totalAPlazo = $monto * $plazoSem;

    $stats = ['pagados'=>0,'monto_pagado'=>0,'pending'=>0,'overdue'=>0,'monto_pending'=>0,'monto_overdue'=>0,'total'=>0];
    $cq = $pdo->prepare("SELECT estado, COUNT(*) n, COALESCE(SUM(monto),0) m FROM ciclos_pago WHERE subscripcion_id=? GROUP BY estado");
    $cq->execute([$subId]);
    foreach ($cq->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $stats['total'] += (int)$r['n'];
        if (in_array($r['estado'], ['paid_auto','paid_manual'], true)) {
            $stats['pagados'] += (int)$r['n'];
            $stats['monto_pagado'] += (float)$r['m'];
        } elseif ($r['estado'] === 'pending') {
            $stats['pending'] += (int)$r['n'];
            $stats['monto_pending'] += (float)$r['m'];
        } elseif ($r['estado'] === 'overdue') {
            $stats['overdue'] += (int)$r['n'];
            $stats['monto_overdue'] += (float)$r['m'];
        }
    }

    $next = $pdo->prepare("SELECT semana_num, fecha_vencimiento, monto FROM ciclos_pago
        WHERE subscripcion_id=? AND estado='pending' AND fecha_vencimiento >= CURDATE()
        ORDER BY fecha_vencimiento ASC LIMIT 1");
    $next->execute([$subId]);
    $nextRow = $next->fetch(PDO::FETCH_ASSOC) ?: null;

    $progressPct = $stats['total'] > 0 ? round(($stats['pagados'] / $stats['total']) * 100, 1) : 0;
    $amtProgress = $totalAPlazo > 0 ? round(($stats['monto_pagado'] / $totalAPlazo) * 100, 1) : 0;
    $saldoInsoluto = max(0, $totalAPlazo - $stats['monto_pagado']);

    echo '<h1>💳 Cuenta de crédito — ' . htmlspecialchars((string)($sub['nombre'] ?? $sub['cliente_nombre'])) . '</h1>';
    echo '<p class="muted"><a href="?">← Lista de cuentas</a> · sub_id=' . $subId . ' · ' . htmlspecialchars((string)$sub['email']) . ' · ' . htmlspecialchars((string)$sub['telefono']) . '</p>';

    echo '<div class="kpis">';
    echo '<div class="kpi green"><div class="kpi-label">Pagados</div><div class="kpi-num">' . $stats['pagados'] . '/' . $stats['total'] . '</div><div class="progress"><div class="progress-fill" style="width:' . $progressPct . '%;"></div></div></div>';
    echo '<div class="kpi amber"><div class="kpi-label">Restantes</div><div class="kpi-num">' . ($stats['total'] - $stats['pagados']) . '</div><div class="muted">' . ($stats['overdue'] > 0 ? $stats['overdue'] . ' vencidos' : 'sin vencidos') . '</div></div>';
    echo '<div class="kpi green"><div class="kpi-label">Monto cobrado</div><div class="kpi-num">$' . number_format($stats['monto_pagado'], 0) . '</div><div class="muted">' . $amtProgress . '% del total</div></div>';
    echo '<div class="kpi blue"><div class="kpi-label">Saldo insoluto</div><div class="kpi-num">$' . number_format($saldoInsoluto, 0) . '</div><div class="muted">de $' . number_format($totalAPlazo, 0) . ' total</div></div>';
    echo '</div>';

    echo '<div class="card"><h2>Resumen de la operación</h2><table>';
    echo '<tr><td><strong>Modelo</strong></td><td>' . htmlspecialchars((string)$sub['modelo']) . ' · ' . htmlspecialchars((string)$sub['color']) . '</td>';
    echo '<td><strong>Plazo</strong></td><td>' . (int)$sub['plazo_meses'] . ' meses (' . $plazoSem . ' semanas)</td></tr>';
    echo '<tr><td><strong>Pago semanal</strong></td><td class="amt">$' . number_format($monto, 2) . '</td>';
    echo '<td><strong>Total a plazo</strong></td><td class="amt">$' . number_format($totalAPlazo, 2) . '</td></tr>';
    echo '<tr><td><strong>Fecha inicio</strong></td><td>' . htmlspecialchars((string)($sub['fecha_inicio'] ?? '?')) . '</td>';
    echo '<td><strong>Fecha entrega</strong></td><td>' . htmlspecialchars((string)($sub['fecha_entrega'] ?? '?')) . '</td></tr>';
    echo '<tr><td><strong>Próximo vencimiento</strong></td><td class="amt">' . ($nextRow ? htmlspecialchars((string)$nextRow['fecha_vencimiento']) . ' (#' . (int)$nextRow['semana_num'] . ' · $' . number_format((float)$nextRow['monto'], 0) . ')' : '—') . '</td>';
    echo '<td><strong>Tarjeta Stripe</strong></td><td>' . (!empty($sub['stripe_payment_method_id']) ? '<span class="badge b-paid">✓ Registrada</span>' : '<span class="badge b-overdue">✗ Sin tarjeta</span>') . '</td></tr>';
    echo '<tr><td><strong>Fallos consecutivos</strong></td><td>' . (int)$sub['cobro_fallos_seguidos'] . '</td>';
    echo '<td><strong>Estado</strong></td><td>' . htmlspecialchars((string)$sub['estado']) . '</td></tr>';
    echo '</table></div>';

    // ── Cycle history ──
    echo '<h2>Historial completo (78 ciclos)</h2><div class="card">';
    $list = $pdo->prepare("SELECT semana_num, fecha_vencimiento, fecha_pago, monto, estado, stripe_payment_intent
        FROM ciclos_pago WHERE subscripcion_id=? ORDER BY semana_num ASC");
    $list->execute([$subId]);
    echo '<table><thead><tr><th>#</th><th>Vence</th><th>Estado</th><th>Pagado el</th><th>Monto</th><th>Stripe PI</th></tr></thead><tbody>';
    foreach ($list->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $est = $c['estado'];
        $badge = $est === 'paid_auto' || $est === 'paid_manual' ? 'b-paid' :
                 ($est === 'overdue' ? 'b-overdue' :
                 ($c['fecha_vencimiento'] === date('Y-m-d') ? 'b-pending-today' : 'b-pending'));
        echo '<tr>';
        echo '<td><strong>' . (int)$c['semana_num'] . '</strong></td>';
        echo '<td>' . htmlspecialchars((string)$c['fecha_vencimiento']) . '</td>';
        echo '<td><span class="badge ' . $badge . '">' . htmlspecialchars($est) . '</span></td>';
        echo '<td>' . htmlspecialchars((string)($c['fecha_pago'] ?? '')) . '</td>';
        echo '<td class="amt">$' . number_format((float)$c['monto'], 2) . '</td>';
        echo '<td style="font-size:10px;color:#94a3b8;">' . htmlspecialchars(substr((string)($c['stripe_payment_intent'] ?? ''), 0, 28)) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
} else {
    // ════════════ LIST VIEW — all active credit accounts ════════════
    echo '<h1>💳 Cuentas de crédito</h1>';
    echo '<p class="muted">Cada cliente con subscripción de crédito activa. Click para ver detalle.</p>';

    $rows = $pdo->query("SELECT sc.id, sc.nombre, sc.email, sc.telefono, sc.modelo, sc.color,
            sc.monto_semanal, sc.plazo_semanas, sc.fecha_inicio, sc.estado,
            sc.cobro_fallos_seguidos, sc.stripe_payment_method_id,
            (SELECT COUNT(*) FROM ciclos_pago WHERE subscripcion_id=sc.id AND estado IN ('paid_auto','paid_manual')) AS pagados,
            (SELECT COUNT(*) FROM ciclos_pago WHERE subscripcion_id=sc.id) AS total,
            (SELECT COUNT(*) FROM ciclos_pago WHERE subscripcion_id=sc.id AND estado='overdue') AS overdue,
            (SELECT COALESCE(SUM(monto),0) FROM ciclos_pago WHERE subscripcion_id=sc.id AND estado IN ('paid_auto','paid_manual')) AS monto_cobrado,
            (SELECT MIN(fecha_vencimiento) FROM ciclos_pago WHERE subscripcion_id=sc.id AND estado='pending' AND fecha_vencimiento >= CURDATE()) AS proximo
        FROM subscripciones_credito sc
        WHERE sc.telefono != '5500000000'
          AND (sc.email IS NULL OR sc.email NOT LIKE '%diag-test%')
          AND (sc.nombre IS NULL OR (LOWER(sc.nombre) NOT LIKE '%voltika diag%' AND LOWER(sc.nombre) NOT LIKE '%test%'))
        ORDER BY (sc.fecha_inicio IS NULL) ASC, sc.fecha_inicio DESC, sc.id DESC
        LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);

    echo '<div class="card"><table><thead><tr>'
       . '<th>Cliente</th><th>Vehículo</th><th>$/sem · plazo</th>'
       . '<th>Progreso</th><th>Total cobrado</th><th>Próx. vencimiento</th><th>Estado</th>'
       . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $pct = (int)$r['total'] > 0 ? round(($r['pagados'] / $r['total']) * 100) : 0;
        $totalPlazo = (float)$r['monto_semanal'] * (int)$r['plazo_semanas'];
        echo '<tr>';
        echo '<td><a class="row-link" href="?sub_id=' . (int)$r['id'] . '"><strong>' . htmlspecialchars((string)$r['nombre']) . '</strong></a><br><span class="muted">' . htmlspecialchars((string)$r['telefono']) . '</span></td>';
        echo '<td>' . htmlspecialchars((string)$r['modelo']) . '<br><span class="muted">' . htmlspecialchars((string)$r['color']) . '</span></td>';
        echo '<td class="amt">$' . number_format((float)$r['monto_semanal'], 0) . ' × ' . (int)$r['plazo_semanas'] . '</td>';
        echo '<td><strong>' . (int)$r['pagados'] . '/' . (int)$r['total'] . '</strong> (' . $pct . '%)<div class="progress"><div class="progress-fill" style="width:' . $pct . '%;"></div></div>' . ($r['overdue'] > 0 ? '<span class="badge b-overdue">' . (int)$r['overdue'] . ' vencidos</span>' : '') . '</td>';
        echo '<td class="amt">$' . number_format((float)$r['monto_cobrado'], 0) . '<br><span class="muted">de $' . number_format($totalPlazo, 0) . '</span></td>';
        echo '<td>' . htmlspecialchars((string)($r['proximo'] ?? '—')) . '</td>';
        echo '<td><span class="badge ' . ($r['estado'] === 'activa' ? 'b-paid' : 'b-pending') . '">' . htmlspecialchars((string)$r['estado']) . '</span></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="muted">Mostrando ' . count($rows) . ' cuentas. Test customers excluidos (Voltika Diag, etc.).</p>';
}

echo '</body></html>';
