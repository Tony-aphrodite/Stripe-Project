<?php
/**
 * ONE-SHOT TOOL — Trigger ciclo generation NOW (no waiting for cron).
 *
 * Customer brief (Óscar, 2026-05-26): Carlos Ricardo Sánchez recibió su moto
 * pero no aparece en el Cobranza dashboard porque ningún ciclo_pago se
 * generó aún. El cron /admin/cron/generar-ciclos.php corre periódicamente
 * pero el usuario necesita verlo AHORA.
 *
 * Esta herramienta replica exactamente la lógica del cron, pero corre
 * on-demand desde el admin. Muestra:
 *   - Subscripciones elegibles (estado=activa AND fecha_inicio NOT NULL)
 *   - Para cada una: cuántos ciclos ya existen + si faltan generar
 *   - Botón "Generar ahora" — ejecuta y muestra el resultado
 *
 * Después del run, los ciclos aparecerán en /admin → Cobranza inmediato.
 *
 * Auth: admin.
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$apply = !empty($_POST['apply']) && $_SERVER['REQUEST_METHOD'] === 'POST';

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Generar ciclos AHORA</title>';
echo '<style>
body{font-family:system-ui,-apple-system,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1180px;margin:0 auto;line-height:1.5;}
h1{font-size:22px;margin:0 0 4px;}
h2{font-size:16px;color:#475569;margin:24px 0 8px;border-bottom:1px solid #cbd5e1;padding-bottom:4px;}
.sec{background:#fff;padding:14px 16px;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:14px;}
table{width:100%;border-collapse:collapse;font-size:12.5px;}
th{background:#f1f5f9;text-align:left;padding:8px 10px;font-size:11.5px;}
td{padding:8px 10px;border-top:1px solid #f1f5f9;vertical-align:top;}
.btn{padding:10px 20px;background:#039fe1;color:#fff;border:0;border-radius:6px;cursor:pointer;font-size:14px;font-weight:600;text-decoration:none;display:inline-block;}
.btn.ghost{background:#fff;color:#0c2340;border:1px solid #cbd5e1;}
.btn.danger{background:#dc2626;}
.btn.success{background:#16a34a;}
.ok{color:#15803d;font-weight:700;}
.warn{color:#a16207;font-weight:700;}
.err{color:#b91c1c;font-weight:700;}
.success-box{background:#d1fae5;border:1px solid #34d399;color:#065f46;padding:14px 18px;border-radius:10px;margin:14px 0;}
.hint{background:#fef9c3;border:1px solid #facc15;padding:10px 14px;border-radius:8px;margin:10px 0;font-size:13px;}
code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:11.5px;}
</style></head><body>';
echo '<h1>🔁 Generar ciclos de pago AHORA</h1>';
echo '<p style="color:#64748b;font-size:13px;margin-top:0;">Replica la lógica del cron <code>/admin/cron/generar-ciclos.php</code> on-demand para que no tengas que esperar la próxima corrida.</p>';

// Apply generation
if ($apply) {
    $subs = $pdo->query("
        SELECT id, cliente_id, monto_semanal, plazo_semanas, fecha_inicio, nombre, telefono, email
          FROM subscripciones_credito
         WHERE estado = 'activa' AND fecha_inicio IS NOT NULL
    ")->fetchAll(PDO::FETCH_ASSOC);

    $totalCreated = 0;
    $perSub = [];
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO ciclos_pago
            (subscripcion_id, cliente_id, semana_num, fecha_vencimiento, monto, estado)
        VALUES (?, ?, ?, ?, ?, 'pending')
    ");
    foreach ($subs as $sub) {
        try {
            $inicio = new DateTime((string)$sub['fecha_inicio']);
            $hoy    = new DateTime('today');
            if ($inicio > $hoy) {
                $perSub[$sub['id']] = ['name' => $sub['nombre'], 'created' => 0, 'reason' => 'fecha_inicio futura'];
                continue;
            }
            $diffDays = (int)$inicio->diff($hoy)->days;
            $semanasTranscurridas = (int)floor($diffDays / 7);
            $maxSemana = min($semanasTranscurridas + 1, (int)$sub['plazo_semanas']);
            $createdHere = 0;
            for ($semana = 1; $semana <= $maxSemana; $semana++) {
                $venc = (clone $inicio)->modify('+' . ($semana * 7) . ' days')->format('Y-m-d');
                $stmt->execute([(int)$sub['id'], (int)$sub['cliente_id'], $semana, $venc, $sub['monto_semanal']]);
                if ($stmt->rowCount() > 0) { $createdHere++; $totalCreated++; }
            }
            $perSub[$sub['id']] = ['name' => $sub['nombre'], 'created' => $createdHere, 'reason' => ''];
        } catch (Throwable $e) {
            $perSub[$sub['id']] = ['name' => $sub['nombre'] ?? '?', 'created' => 0, 'reason' => $e->getMessage()];
        }
    }
    if (function_exists('adminLog')) {
        adminLog('admin_generar_ciclos_ahora', [
            'subs_evaluadas' => count($subs),
            'ciclos_creados' => $totalCreated,
        ]);
    }
    echo '<div class="success-box">';
    echo '<h2 style="margin-top:0;color:#065f46;border:0;">✅ Ciclos generados</h2>';
    echo 'Subscripciones evaluadas: <strong>' . count($subs) . '</strong> · Ciclos nuevos creados: <strong>' . $totalCreated . '</strong>';
    if ($perSub) {
        echo '<ul style="margin-top:10px;">';
        foreach ($perSub as $sid => $info) {
            echo '<li>Sub #' . (int)$sid . ' (' . htmlspecialchars((string)$info['name']) . ') — ' .
                 ($info['created'] > 0
                    ? '<strong class="ok">+' . $info['created'] . ' ciclo(s)</strong>'
                    : ($info['reason'] ? '<span class="warn">' . htmlspecialchars($info['reason']) . '</span>' : '<span style="color:#64748b;">sin cambios (ya al día)</span>')) .
                 '</li>';
        }
        echo '</ul>';
    }
    echo '<p style="margin-top:10px;"><a class="btn" href="/admin/" target="_blank">→ Ir a Cobranza</a> <a class="btn ghost" href="?">← Re-evaluar</a></p>';
    echo '</div>';
    echo '</body></html>';
    exit;
}

// Default view — show eligible subs
echo '<div class="sec">';
echo '<h2>Subscripciones activas (elegibles para ciclo generation)</h2>';
try {
    $rows = $pdo->query("
        SELECT s.id, s.nombre, s.email, s.telefono, s.modelo, s.monto_semanal, s.plazo_semanas,
               s.fecha_inicio, s.estado,
               (SELECT COUNT(*) FROM ciclos_pago WHERE subscripcion_id = s.id) AS ciclos_existentes
          FROM subscripciones_credito s
         WHERE s.estado = 'activa'
         ORDER BY s.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rows = [];
    echo '<div class="err">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

if (!$rows) {
    echo '<p style="color:#94a3b8;font-style:italic;">Sin subscripciones activas.</p>';
} else {
    echo '<table><thead><tr>';
    echo '<th>sub_id</th><th>Cliente</th><th>Modelo</th><th>$/sem</th><th>Plazo</th><th>fecha_inicio</th><th>Ciclos existentes</th><th>Elegible</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $elegible = !empty($r['fecha_inicio']) && (int)$r['ciclos_existentes'] < (int)$r['plazo_semanas'];
        echo '<tr>';
        echo '<td><code>' . (int)$r['id'] . '</code></td>';
        echo '<td>' . htmlspecialchars((string)$r['nombre']) . '<br><small style="color:#64748b;">' . htmlspecialchars((string)$r['email']) . ' · ' . htmlspecialchars((string)$r['telefono']) . '</small></td>';
        echo '<td>' . htmlspecialchars((string)$r['modelo']) . '</td>';
        echo '<td>$' . number_format((float)$r['monto_semanal'], 0) . '</td>';
        echo '<td>' . (int)$r['plazo_semanas'] . ' sem</td>';
        echo '<td>' . (empty($r['fecha_inicio']) ? '<span class="err">NULL</span>' : htmlspecialchars((string)$r['fecha_inicio'])) . '</td>';
        echo '<td>' . (int)$r['ciclos_existentes'] . '</td>';
        echo '<td>' . ($elegible ? '<span class="ok">SÍ</span>' : '<span class="warn">no</span>') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<form method="post" style="margin-top:18px;">';
    echo '<input type="hidden" name="apply" value="1">';
    echo '<button type="submit" class="btn success" onclick="return confirm(\'¿Generar ciclos para todas las subscripciones elegibles? Es idempotente — solo crea ciclos faltantes.\')">▶ Generar ciclos AHORA</button>';
    echo '</form>';
}
echo '</div>';

echo '<div class="hint">'
   . '<strong>Cómo funciona el cron normalmente:</strong> corre periódicamente y para cada subscripción activa con fecha_inicio set, calcula <code>semanasTranscurridas + 1</code> y crea esos ciclos (INSERT IGNORE — no duplica). Esta herramienta hace exactamente lo mismo on-demand. Ejemplo: cliente entregado hoy con plazo=156 → se crea ciclo #1 (vence en 7 días). Cliente entregado hace 3 semanas → se crean ciclos #1-#4.'
   . '</div>';

echo '</body></html>';
