<?php
/**
 * Voltika Admin — Inspect ciclos_pago for a subscription.
 * Shows the status distribution + first/last cycles so we can see why a
 * customer isn't appearing in Cobranza.
 *
 * Usage: ?sub_id=3
 */
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$subId = (int)($_GET['sub_id'] ?? 0);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Ciclos check</title>';
echo '<style>
body{font-family:system-ui,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1000px;margin:0 auto;line-height:1.5;}
h1{font-size:20px;margin:0 0 4px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px;margin-bottom:12px;}
table{border-collapse:collapse;width:100%;font-size:12.5px;}
th{background:#f1f5f9;padding:6px 9px;text-align:left;font-size:11px;}
td{padding:6px 9px;border-top:1px solid #f1f5f9;}
.lbl{font-weight:600;background:#f8fafc;}
input{padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;}
button{padding:6px 14px;background:#039fe1;color:#fff;border:0;border-radius:4px;cursor:pointer;font-weight:600;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;padding:11px 14px;border-radius:8px;}
.banner-warn{background:#fef3c7;border:1px solid #fcd34d;color:#92400e;padding:11px 14px;border-radius:8px;}
</style></head><body>';
echo '<h1>📊 Ciclos check</h1>';
echo '<form method="get" style="margin-bottom:14px;"><label>sub_id:</label> '
   . '<input type="number" name="sub_id" value="' . htmlspecialchars((string)$subId) . '" required> '
   . '<button>Buscar</button> · ej: 3 (Carlos), 1 (Voltika Diag)</form>';

if (!$subId) { echo '</body></html>'; exit; }

// Subscription info
$st = $pdo->prepare("SELECT sc.*, c.nombre, c.email, c.telefono
    FROM subscripciones_credito sc
    LEFT JOIN clientes c ON c.id = sc.cliente_id WHERE sc.id = ?");
$st->execute([$subId]);
$sub = $st->fetch(PDO::FETCH_ASSOC);
if (!$sub) { echo '<div class="banner-warn">Subscripción no encontrada</div></body></html>'; exit; }

echo '<div class="card"><strong>Sub #' . (int)$sub['id'] . '</strong> · '
   . htmlspecialchars((string)($sub['nombre'] ?? '')) . ' · '
   . htmlspecialchars((string)($sub['email'] ?? '')) . '<br>'
   . 'status=' . htmlspecialchars((string)($sub['status'] ?? '?')) . ' · '
   . 'estado=' . htmlspecialchars((string)($sub['estado'] ?? '?')) . ' · '
   . 'fecha_inicio=' . htmlspecialchars((string)($sub['fecha_inicio'] ?? '?')) . ' · '
   . '$' . number_format((float)($sub['monto_semanal'] ?? 0), 2) . '/sem · '
   . 'plazo=' . (int)($sub['plazo_semanas'] ?? 0) . ' sem · '
   . 'fallos_seguidos=' . (int)($sub['cobro_fallos_seguidos'] ?? 0)
   . '</div>';

// Status distribution
echo '<h2 style="font-size:15px;">Distribución de ciclos por estado</h2>';
$dist = $pdo->prepare("SELECT estado, COUNT(*) AS n, MIN(fecha_vencimiento) AS first_due, MAX(fecha_vencimiento) AS last_due
    FROM ciclos_pago WHERE subscripcion_id = ? GROUP BY estado ORDER BY n DESC");
$dist->execute([$subId]);
echo '<div class="card"><table><thead><tr><th>estado</th><th>cantidad</th><th>primer vencimiento</th><th>último vencimiento</th></tr></thead><tbody>';
foreach ($dist->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo '<tr><td>' . htmlspecialchars((string)$r['estado']) . '</td>'
       . '<td>' . (int)$r['n'] . '</td>'
       . '<td>' . htmlspecialchars((string)$r['first_due']) . '</td>'
       . '<td>' . htmlspecialchars((string)$r['last_due']) . '</td></tr>';
}
echo '</tbody></table></div>';

// First 10 cycles
echo '<h2 style="font-size:15px;">Primeros 10 ciclos</h2>';
$q = $pdo->prepare("SELECT semana_num, fecha_vencimiento, fecha_pago, monto, estado, stripe_payment_intent, freg
    FROM ciclos_pago WHERE subscripcion_id = ? ORDER BY semana_num ASC LIMIT 10");
$q->execute([$subId]);
echo '<div class="card"><table><thead><tr><th>#</th><th>vence</th><th>pagado</th><th>monto</th><th>estado</th><th>stripe_pi</th><th>creado</th></tr></thead><tbody>';
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo '<tr><td>' . htmlspecialchars((string)$r['semana_num']) . '</td>'
       . '<td>' . htmlspecialchars((string)$r['fecha_vencimiento']) . '</td>'
       . '<td>' . htmlspecialchars((string)($r['fecha_pago'] ?? '')) . '</td>'
       . '<td>$' . number_format((float)$r['monto'], 2) . '</td>'
       . '<td><code>' . htmlspecialchars((string)$r['estado']) . '</code></td>'
       . '<td style="font-size:10px;">' . htmlspecialchars(substr((string)($r['stripe_payment_intent'] ?? ''), 0, 30)) . '</td>'
       . '<td>' . htmlspecialchars((string)$r['freg']) . '</td></tr>';
}
echo '</tbody></table></div>';

// What Cobranza expects
echo '<div class="card" style="background:#fef9c3;border-color:#facc15;">'
   . '<strong>📋 Cobranza filtra por:</strong> <code>estado IN (\'overdue\', \'pending\', \'paid_auto\', \'paid_manual\')</code><br>'
   . 'Si los ciclos están en otro estado (failed, cancelled, etc), NO aparecerán en Cobranza.'
   . '</div>';

echo '</body></html>';
