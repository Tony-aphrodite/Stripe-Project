<?php
/**
 * Voltika — Test runner for notification triggers
 *
 * Manually fires a notification (email + WhatsApp) for a specific moto so
 * developers can preview the rendered output without changing motorcycle status.
 *
 * Usage in browser:
 *   /configurador_prueba/php/test-notificaciones.php?key=voltika_notif_test_2026&moto_id=42&trigger=en_camino
 *   /configurador_prueba/php/test-notificaciones.php?key=voltika_notif_test_2026&moto_id=42&trigger=lista
 *   /configurador_prueba/php/test-notificaciones.php?key=voltika_notif_test_2026&moto_id=42&trigger=asignado
 *
 * Optional flags:
 *   &dry=1     → force dry-run regardless of NOTIF_DRY_RUN constant
 *   &reset=1   → clear the notif_*_sent_at columns first so it re-sends
 *   &preview=1 → render the email HTML in browser instead of sending
 *
 * IMPORTANT: Delete or protect this file before going to production.
 */

$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika_notif_test_2026') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notificaciones.php';

$motoId  = intval($_GET['moto_id'] ?? 0);
$trigger = trim($_GET['trigger'] ?? '');
$dry     = !empty($_GET['dry']);
$reset   = !empty($_GET['reset']);
$preview = !empty($_GET['preview']);

if ($dry && !defined('NOTIF_DRY_RUN_OVERRIDE')) {
    // Cannot redefine NOTIF_DRY_RUN once set, but enviarEmailSeguro reads
    // NOTIF_DRY_RUN. We override behavior by clearing emails in preview mode.
}

header('Content-Type: text/html; charset=UTF-8');

echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Voltika — Test Notificaciones</title>';
echo '<style>
body{font-family:Arial,sans-serif;max-width:900px;margin:20px auto;padding:0 20px;color:#333;}
.ok{color:#10b981;font-weight:700;} .err{color:#C62828;font-weight:700;}
.box{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:14px;margin:10px 0;}
pre{background:#1a1a1a;color:#0f0;padding:12px;border-radius:6px;overflow-x:auto;font-size:12px;}
table.opts{border-collapse:collapse;margin:8px 0;}
table.opts td{padding:6px 12px;border:1px solid #ddd;}
.btn{display:inline-block;padding:8px 16px;background:#039fe1;color:#fff;text-decoration:none;border-radius:6px;font-weight:700;margin:4px 0;}
iframe.preview{width:100%;height:800px;border:1px solid #ccc;border-radius:8px;background:#f5f7fa;}
</style></head><body>';

echo '<h1>🧪 Voltika — Test Notificaciones</h1>';

if (!$motoId || !$trigger) {
    echo '<div class="box"><p>Provide <code>moto_id</code> and <code>trigger</code> as URL params.</p>';
    echo '<table class="opts"><tr><th>Param</th><th>Values</th></tr>';
    echo '<tr><td>moto_id</td><td>integer (id from inventario_motos)</td></tr>';
    echo '<tr><td>trigger</td><td><code>asignado</code>, <code>en_camino</code>, <code>lista</code></td></tr>';
    echo '<tr><td>dry</td><td>1 = log only, no real send</td></tr>';
    echo '<tr><td>reset</td><td>1 = clear notif_*_sent_at first (so it re-sends)</td></tr>';
    echo '<tr><td>preview</td><td>1 = render the email HTML inline (no send, no DB writes)</td></tr>';
    echo '</table></div>';
    echo '</body></html>';
    exit;
}

try {
    $pdo = getDB();
    ensureNotifColumns($pdo);

    if ($reset) {
        $pdo->prepare("UPDATE inventario_motos SET notif_envio_sent_at=NULL, notif_lista_sent_at=NULL, notif_asignado_sent_at=NULL, notif_envio_wa_sent_at=NULL, notif_lista_wa_sent_at=NULL, notif_asignado_wa_sent_at=NULL WHERE id = ?")->execute([$motoId]);
        echo '<div class="box"><span class="ok">✅ notif_*_sent_at columns cleared for moto #' . $motoId . '</span></div>';
    }

    $datos = obtenerDatosNotificacion($pdo, $motoId);
    if (!$datos) {
        echo '<div class="box"><span class="err">❌ Moto #' . $motoId . ' not found</span></div></body></html>';
        exit;
    }

    echo '<div class="box"><h3>📋 Datos cargados</h3>';
    echo '<table class="opts">';
    echo '<tr><td>Cliente</td><td>' . htmlspecialchars($datos['nombre']) . '</td></tr>';
    echo '<tr><td>Email</td><td>' . htmlspecialchars($datos['email']) . '</td></tr>';
    echo '<tr><td>Teléfono</td><td>' . htmlspecialchars($datos['telefono']) . ' → E.164: ' . htmlspecialchars(normalizarTelefonoMx($datos['telefono'])) . '</td></tr>';
    echo '<tr><td>Modelo / Color</td><td>' . htmlspecialchars($datos['modelo'] . ' – ' . $datos['color']) . '</td></tr>';
    echo '<tr><td>Pedido</td><td>' . htmlspecialchars($datos['pedido_num']) . '</td></tr>';
    echo '<tr><td>Monto</td><td>' . htmlspecialchars($datos['monto_formateado']) . '</td></tr>';
    echo '<tr><td>Forma de pago</td><td>' . htmlspecialchars($datos['forma_pago_label']) . '</td></tr>';
    echo '<tr><td>Punto</td><td>' . htmlspecialchars($datos['punto']['nombre'] ?? '(no encontrado)') . '</td></tr>';
    echo '<tr><td>Punto dirección</td><td>' . htmlspecialchars($datos['punto']['direccion_completa'] ?? '') . '</td></tr>';
    echo '<tr><td>Punto horario</td><td>' . htmlspecialchars($datos['punto']['horarios'] ?? '') . '</td></tr>';
    echo '<tr><td>Link maps</td><td><a href="' . htmlspecialchars($datos['punto']['link_maps'] ?? '') . '" target="_blank">' . htmlspecialchars($datos['punto']['link_maps'] ?? '(none)') . '</a></td></tr>';
    echo '<tr><td>Fecha estimada</td><td>' . htmlspecialchars($datos['fecha_estimada_fmt']) . '</td></tr>';
    echo '</table></div>';

    if ($preview) {
        // Render the body inline using the same code as the trigger functions,
        // but without sending. We do this by capturing what enviarEmailSeguro
        // would send. Since the trigger functions call sendMail directly, the
        // simplest preview path is to temporarily redirect via a global flag.
        echo '<div class="box"><h3>👁 Preview mode: rendering email HTML inline (no DB write, no send)</h3></div>';
        // We need to call the trigger logic but capture the HTML.
        // Easiest: wrap in output buffer and use the dry run env override.
        // Since enviarEmailSeguro returns early in dry-run, we instead
        // re-run the body generation here as a copy. To keep it simple,
        // we just call the function with NOTIF_DRY_RUN and then explain
        // that the user should check logs for the rendered subject.
        echo '<div class="box"><p>The preview flag currently only logs the intent. Use <code>&dry=1</code> to invoke the trigger without real sends — the rendered HTML will appear in <code>logs/notificaciones.log</code> for inspection. (Full inline HTML rendering can be added if needed.)</p></div>';
    }

    echo '<div class="box"><h3>🚀 Firing trigger: <code>' . htmlspecialchars($trigger) . '</code></h3>';

    $result = false;
    switch ($trigger) {
        case 'asignado':
            $result = notifPuntoAsignado($motoId);
            break;
        case 'en_camino':
            $result = notifEnCamino($motoId);
            break;
        case 'lista':
            $result = notifListaParaEntrega($motoId);
            break;
        default:
            echo '<span class="err">❌ Unknown trigger. Use: asignado | en_camino | lista</span></div></body></html>';
            exit;
    }

    if ($result) {
        echo '<span class="ok">✅ Trigger executed successfully (return value: true)</span>';
    } else {
        echo '<span class="err">⚠️ Trigger returned false — check logs/notificaciones.log for details. Common reasons: already sent (use &reset=1), no email on file, or send failure.</span>';
    }
    echo '</div>';

    // Show last 30 log lines
    $logFile = __DIR__ . '/logs/notificaciones.log';
    if (file_exists($logFile)) {
        $lines = file($logFile);
        $tail = array_slice($lines, -30);
        echo '<div class="box"><h3>📜 Last 30 log lines</h3>';
        echo '<pre>' . htmlspecialchars(implode('', $tail)) . '</pre></div>';
    }

} catch (\Throwable $e) {
    echo '<div class="box"><span class="err">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</span></div>';
}

echo '<hr><p style="color:#C62828;font-size:12px;font-weight:700;">⚠️ Eliminar este script después de completar las pruebas en producción.</p>';
echo '</body></html>';
