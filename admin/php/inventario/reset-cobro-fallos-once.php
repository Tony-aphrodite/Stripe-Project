<?php
/**
 * Voltika Admin — One-shot reset for cobro_fallos_seguidos counter on a
 * subscripcion_credito row.
 *
 * Use case: when cycles are regenerated (manual delivery / emergency fix /
 * delivery flow re-run), the cobro_fallos_seguidos counter from the previous
 * subscription attempt stays high. That blocks the auto-cobro cron from even
 * trying to charge the card. Reset to 0 so Stripe gets a clean attempt.
 *
 * Usage: ?sub_id=3
 *        → preview current state + button to confirm reset
 *        Then click "Resetear" → counter goes to 0
 *
 * Auth: admin only.
 */
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$subId = (int)($_GET['sub_id'] ?? $_POST['sub_id'] ?? 0);
$confirm = !empty($_POST['confirm']);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Reset cobro_fallos</title>';
echo '<style>
body{font-family:system-ui,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:760px;margin:0 auto;line-height:1.55;}
h1{font-size:20px;margin:0 0 4px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px 18px;margin-bottom:14px;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th{background:#f1f5f9;padding:7px 9px;text-align:left;font-size:11.5px;}
td{padding:7px 9px;border-top:1px solid #f1f5f9;}
.lbl{font-weight:600;background:#f8fafc;width:200px;}
.banner{padding:12px 14px;border-radius:8px;font-size:13.5px;margin-bottom:14px;font-weight:600;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.banner-warn{background:#fef3c7;border:1px solid #fcd34d;color:#92400e;}
.banner-info{background:#dbeafe;border:1px solid #93c5fd;color:#1e40af;}
input{padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;}
.btn{padding:8px 16px;background:#dc2626;color:#fff;border:0;border-radius:5px;cursor:pointer;font-weight:600;font-size:13px;}
.btn.warn{background:#f59e0b;}
.btn.ghost{background:#fff;color:#0c2340;border:1px solid #cbd5e1;}
</style></head><body>';
echo '<h1>🔧 Reset contador de fallos de cobro</h1>';
echo '<p style="color:#64748b;font-size:12.5px;margin-top:0;">Pone subscripciones_credito.cobro_fallos_seguidos = 0 para que el cron auto-cobro vuelva a intentar.</p>';

echo '<form method="get" style="margin-bottom:14px;"><label>sub_id:</label> '
   . '<input type="number" name="sub_id" value="' . htmlspecialchars((string)$subId) . '" required> '
   . '<button class="btn ghost">Ver</button> · Ejemplos: 3 (Carlos)</form>';

if (!$subId) { echo '</body></html>'; exit; }

// Fetch current state
$st = $pdo->prepare("SELECT sc.id, sc.cliente_id, sc.nombre, sc.email, sc.telefono,
        sc.status, sc.estado, sc.cobro_fallos_seguidos, sc.monto_semanal, sc.plazo_semanas,
        sc.fecha_inicio, sc.stripe_payment_method_id, sc.inventario_moto_id
    FROM subscripciones_credito sc WHERE sc.id = ?");
$st->execute([$subId]);
$sub = $st->fetch(PDO::FETCH_ASSOC);
if (!$sub) {
    echo '<div class="banner banner-warn">Subscripción ' . $subId . ' no encontrada.</div></body></html>'; exit;
}

$current = (int)($sub['cobro_fallos_seguidos'] ?? 0);

// ── Handle reset POST ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $confirm) {
    try {
        $pdo->prepare("UPDATE subscripciones_credito SET cobro_fallos_seguidos = 0 WHERE id = ?")
            ->execute([$subId]);
        adminLog('subscripcion_cobro_fallos_reset', [
            'sub_id' => $subId,
            'cliente' => $sub['nombre'],
            'previous_value' => $current,
            'reset_by_admin' => $_SESSION['admin_user_id'] ?? null,
        ]);
        echo '<div class="banner banner-ok">✓ Reseteado correctamente. cobro_fallos_seguidos: ' . $current . ' → 0</div>';
        echo '<p style="font-size:13px;">El próximo run del cron <code>auto-cobro.php</code> intentará cobrar normalmente. Si la tarjeta sigue fallando, el contador volverá a subir.</p>';
        echo '<p><a href="?sub_id=' . $subId . '">Refrescar estado</a> · <a href="creditos-entregados.php">← Volver al dashboard</a></p>';
        echo '</body></html>'; exit;
    } catch (Throwable $e) {
        echo '<div class="banner banner-warn">Error al resetear: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// ── Show current state + confirm button ───────────────────────────────
echo '<div class="card"><h2 style="font-size:15px;margin:0 0 8px;">Sub #' . (int)$sub['id'] . '</h2><table>';
echo '<tr><td class="lbl">Cliente</td><td>' . htmlspecialchars((string)($sub['nombre'] ?? '')) . '</td></tr>';
echo '<tr><td class="lbl">Email</td><td>' . htmlspecialchars((string)($sub['email'] ?? '')) . '</td></tr>';
echo '<tr><td class="lbl">Teléfono</td><td>' . htmlspecialchars((string)($sub['telefono'] ?? '')) . '</td></tr>';
echo '<tr><td class="lbl">inventario_moto_id</td><td>' . (int)($sub['inventario_moto_id'] ?? 0) . '</td></tr>';
echo '<tr><td class="lbl">status / estado</td><td>' . htmlspecialchars((string)($sub['status'] ?? '?')) . ' / ' . htmlspecialchars((string)($sub['estado'] ?? '?')) . '</td></tr>';
echo '<tr><td class="lbl">monto_semanal · plazo</td><td>$' . number_format((float)$sub['monto_semanal'], 2) . ' × ' . (int)$sub['plazo_semanas'] . ' semanas</td></tr>';
echo '<tr><td class="lbl">fecha_inicio</td><td>' . htmlspecialchars((string)($sub['fecha_inicio'] ?? '')) . '</td></tr>';
echo '<tr><td class="lbl">stripe_payment_method_id</td><td>' . (!empty($sub['stripe_payment_method_id']) ? htmlspecialchars((string)$sub['stripe_payment_method_id']) : '<em style="color:#dc2626;">VACÍO — sin tarjeta activa</em>') . '</td></tr>';
echo '<tr><td class="lbl" style="background:#fef3c7;">cobro_fallos_seguidos</td><td style="background:#fef3c7;font-weight:700;font-size:18px;color:#92400e;">' . $current . '</td></tr>';
echo '</table></div>';

if ($current === 0) {
    echo '<div class="banner banner-info">ℹ️ Ya está en 0. No hay nada que resetear.</div>';
} else {
    echo '<div class="banner banner-warn">⚠ El contador en ' . $current . ' indica que Stripe falló ' . $current . ' veces consecutivas. El cron auto-cobro probablemente está saltando esta subscripción. Resetear a 0 para que vuelva a intentar.</div>';
    echo '<form method="post">';
    echo '<input type="hidden" name="sub_id" value="' . $subId . '">';
    echo '<input type="hidden" name="confirm" value="1">';
    echo '<button class="btn">▶ Sí, resetear a 0</button> ';
    echo '<a href="?sub_id=' . $subId . '" class="btn ghost">Cancelar</a>';
    echo '</form>';
}

echo '<p style="margin-top:14px;font-size:11.5px;color:#94a3b8;">Acción auditada en admin_logs.</p>';
echo '</body></html>';
