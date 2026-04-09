<?php
/**
 * Voltika - Recordatorio semanal de pago para créditos activos
 *
 * Ejecutar via cron cada lunes a las 8am:
 *   0 8 * * 1 php /var/www/configurador/php/recordatorio-pago.php >> /var/log/voltika-recordatorios.log 2>&1
 *
 * O llamar manualmente (con key):
 *   GET /php/recordatorio-pago.php?key=voltika_cron_2026&dry=1
 */

// Allow CLI or HTTP with key
$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    $key = $_GET['key'] ?? '';
    if ($key !== 'voltika_cron_2026') {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=UTF-8');
}

$dryRun = $isCli
    ? in_array('--dry', $argv ?? [])
    : !empty($_GET['dry']);

require_once __DIR__ . '/config.php';

$log = function(string $msg) {
    $ts = date('[Y-m-d H:i:s]');
    echo "$ts $msg\n";
    flush();
};

$log("=== Voltika Recordatorio Semanal de Pago ===");
$log("Modo: " . ($dryRun ? 'DRY RUN (sin envío)' : 'REAL'));

try {
    $pdo = getDB();

    // Fetch active credit orders with weekly payments
    // Uses the 'pedidos' table with metodo = 'credito' and estado = 'activo'
    $stmt = $pdo->query("
        SELECT p.id, p.nombre, p.email, p.telefono,
               p.modelo, p.color, p.pedido_num,
               p.pago_semanal, p.plazo_meses,
               p.fecha_inicio_credito,
               p.semanas_pagadas,
               p.semanas_totales,
               p.total_credito
        FROM pedidos p
        WHERE p.metodo = 'credito'
          AND p.estado_credito = 'activo'
          AND p.email IS NOT NULL
          AND p.email != ''
        ORDER BY p.nombre ASC
    ");
    $creditos = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // If pedidos table doesn't have these columns, try transacciones
    $log("Tabla pedidos no disponible o sin columnas de crédito. Intentando transacciones...");
    try {
        $pdo = getDB();
        $stmt = $pdo->query("
            SELECT t.id, t.nombre, t.email, t.telefono,
                   t.modelo, t.color, t.pedido,
                   t.precio AS pago_semanal,
                   t.total AS total_credito,
                   NULL AS semanas_pagadas,
                   NULL AS semanas_totales
            FROM transacciones t
            WHERE t.tpago IN ('enganche','credito')
              AND t.email IS NOT NULL AND t.email != ''
            ORDER BY t.nombre ASC
        ");
        $creditos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) {
        $log("ERROR: " . $e2->getMessage());
        exit(1);
    }
}

$log("Créditos activos encontrados: " . count($creditos));

$enviados = 0;
$errores  = 0;

foreach ($creditos as $c) {
    $nombre    = $c['nombre'] ?? '';
    $email     = $c['email']  ?? '';
    $modelo    = $c['modelo'] ?? '';
    $color     = $c['color']  ?? '';
    $pedidoNum = $c['pedido_num'] ?? $c['pedido'] ?? '';
    $pagoSem   = floatval($c['pago_semanal'] ?? 0);
    $semPag    = intval($c['semanas_pagadas'] ?? 0);
    $semTot    = intval($c['semanas_totales'] ?? 0);

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $log("SKIP: {$nombre} — email inválido: {$email}");
        continue;
    }

    $pagoFmt   = $pagoSem > 0 ? '$' . number_format($pagoSem, 0, '.', ',') . ' MXN' : 'Consultar';
    $progTexto = ($semTot > 0) ? "{$semPag} de {$semTot} semanas pagadas" : '';
    $whatsapp  = '+52 55 1341 6370';

    $n = htmlspecialchars($nombre);
    $m = htmlspecialchars($modelo . ' ' . $color);
    $p = htmlspecialchars($pedidoNum);

    $body = '<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Recordatorio de pago semanal</title></head>
<body style="margin:0;padding:0;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;">
<tr><td align="center" style="padding:24px 12px;">
<table width="580" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;max-width:580px;width:100%;box-shadow:0 2px 12px rgba(0,0,0,.08);">

<!-- Header -->
<tr><td style="background:linear-gradient(135deg,#1a3a5c,#039fe1);padding:24px 28px;text-align:center;">
<img src="https://www.voltika.mx/configurador_prueba/img/voltika_logo_h_white.svg" alt="Voltika" style="height:36px;width:auto;display:block;margin:0 auto;">
<p style="margin:6px 0 0;font-size:12px;color:rgba(255,255,255,.8);">Movilidad eléctrica inteligente</p>
</td></tr>

<!-- Body -->
<tr><td style="padding:28px;">

<h2 style="margin:0 0 8px;font-size:18px;color:#1a3a5c;">Recordatorio de pago semanal 💳</h2>
<p style="margin:0 0 20px;font-size:14px;color:#555;line-height:1.7;">Hola <strong>' . $n . '</strong>, te recordamos que esta semana vence tu pago semanal de tu crédito Voltika.</p>

<!-- Monto destacado -->
<div style="background:#f0faff;border-radius:10px;padding:20px;text-align:center;margin-bottom:24px;border:1.5px solid #e0f4fd;">
<p style="margin:0 0 4px;font-size:13px;color:#6b7280;">Pago semanal</p>
<p style="margin:0;font-size:36px;font-weight:900;color:#039fe1;">' . $pagoFmt . '</p>
' . ($progTexto ? '<p style="margin:8px 0 0;font-size:12px;color:#6b7280;">' . htmlspecialchars($progTexto) . '</p>' : '') . '
</div>

<!-- Detalle -->
<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
<tr style="background:#f9fafb;"><td style="padding:10px 14px;font-size:13px;color:#6b7280;">Moto</td>
    <td style="padding:10px 14px;font-size:13px;font-weight:600;color:#1a3a5c;">' . $m . '</td></tr>
<tr><td style="padding:10px 14px;font-size:13px;color:#6b7280;">Pedido</td>
    <td style="padding:10px 14px;font-size:13px;font-weight:600;color:#1a3a5c;">#' . $p . '</td></tr>
</table>

<!-- Autopago -->
<div style="background:#fff8e1;border-radius:8px;padding:14px 16px;margin-bottom:20px;border-left:4px solid #f59e0b;">
<p style="margin:0 0 6px;font-size:13px;font-weight:700;color:#1a1a1a;">🔄 Tu pago es automático</p>
<p style="margin:0;font-size:12px;color:#555;">Si configuraste autopago con tarjeta, el cargo se realizará automáticamente. Asegúrate de tener fondos disponibles.</p>
</div>

<!-- Soporte -->
<p style="font-size:13px;color:#555;margin:0 0 4px;">¿Problemas con tu pago? Contáctanos:</p>
<p style="font-size:14px;margin:0 0 4px;">📱 <a href="https://wa.me/5213416370" style="color:#039fe1;font-weight:700;">' . $whatsapp . '</a></p>
<p style="font-size:14px;margin:0 0 20px;">📧 <a href="mailto:redes@voltika.mx" style="color:#039fe1;font-weight:700;">redes@voltika.mx</a></p>

<p style="font-size:11px;color:#aaa;margin:0;">Recibes este recordatorio porque tienes un crédito activo con Voltika México.<br>
Para cancelar notificaciones contacta a soporte.</p>

</td></tr>

<!-- Footer -->
<tr><td style="background:#1a3a5c;padding:18px 28px;text-align:center;">
<p style="margin:0 0 4px;font-size:13px;font-weight:700;color:#fff;">Voltika México</p>
<p style="margin:0;font-size:11px;color:rgba(255,255,255,.6);">Movilidad eléctrica inteligente · Mtech Gears, S.A. de C.V.</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>';

    if ($dryRun) {
        $log("DRY - Enviaría a: {$nombre} <{$email}> — {$m} — {$pagoFmt}");
        $enviados++;
        continue;
    }

    $sent = sendMail($email, $nombre, 'Voltika — Recordatorio de pago semanal', $body);

    if ($sent) {
        $log("OK: {$nombre} <{$email}> — {$m}");
        $enviados++;
    } else {
        $log("ERROR: {$nombre} <{$email}> — fallo al enviar");
        $errores++;
    }

    // Small delay to avoid SMTP rate limiting
    if (!$isCli) usleep(300000);
}

$log("=== Resultado: {$enviados} enviados, {$errores} errores ===");
exit(0);
