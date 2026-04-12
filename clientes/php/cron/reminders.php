<?php
/**
 * Voltika Portal - Recordatorios de pago (D-2, D0, D+1, D+3, D+7)
 * Cron: ejecutar cada hora o diario.
 */
require_once __DIR__ . '/../bootstrap.php';

$pdo = getDB();
$today = new DateTime('today');

// Fetch all pending/overdue cycles within the reminder window
$stmt = $pdo->query("SELECT c.*, s.cliente_id FROM ciclos_pago c
    JOIN subscripciones_credito s ON s.id = c.subscripcion_id
    WHERE c.estado IN ('pending','overdue')");
$ciclos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sent = 0;
foreach ($ciclos as $c) {
    try { $venc = new DateTime($c['fecha_vencimiento']); } catch(Throwable $e){ continue; }
    $diff = (int)$today->diff($venc)->format('%r%a');
    // diff = venc - today. Negative => past due.
    $tipo = null;
    if ($diff === 2)  $tipo = 'D-2';
    elseif ($diff === 0)  $tipo = 'D0';
    elseif ($diff === -1) $tipo = 'D+1';
    elseif ($diff === -3) $tipo = 'D+3';
    elseif ($diff === -7) $tipo = 'D+7';
    if (!$tipo) continue;

    // Skip if already sent today
    $chk = $pdo->prepare("SELECT 1 FROM portal_recordatorios_log
        WHERE cliente_id=? AND ciclo_id=? AND tipo=? AND DATE(freg)=CURDATE()");
    $chk->execute([$c['cliente_id'], $c['id'], $tipo]);
    if ($chk->fetch()) continue;

    // Fetch cliente + preferencias
    $q = $pdo->prepare("SELECT c.nombre, c.email, c.telefono,
        COALESCE(p.notif_email,1) nE, COALESCE(p.notif_whatsapp,1) nW, COALESCE(p.notif_sms,1) nS
        FROM clientes c LEFT JOIN portal_preferencias p ON p.cliente_id=c.id WHERE c.id=?");
    $q->execute([$c['cliente_id']]);
    $cli = $q->fetch(PDO::FETCH_ASSOC);
    if (!$cli) continue;

    $tpl = reminderTemplate($tipo, $cli['nombre'], $c['monto'], $c['fecha_vencimiento']);

    if ($cli['nE'] && $cli['email']) {
        @sendMail($cli['email'], $cli['nombre'] ?: 'Cliente', $tpl['subject'], $tpl['html']);
    }
    if ($cli['nS'] && $cli['telefono']) {
        @portalSendSMS($cli['telefono'], $tpl['sms']);
    }

    $ins = $pdo->prepare("INSERT INTO portal_recordatorios_log (cliente_id, ciclo_id, tipo, canal) VALUES (?,?,?,?)");
    $ins->execute([$cli['cliente_id'] ?? $c['cliente_id'], $c['id'], $tipo, 'email+sms']);
    $sent++;
}

echo "Recordatorios enviados: $sent\n";

function reminderTemplate($tipo, $nombre, $monto, $fecha) {
    $n = htmlspecialchars($nombre ?: 'cliente');
    $m = '$'.number_format((float)$monto, 2);
    $f = $fecha;
    $templates = [
        'D-2' => [
            'subject' => "⚡ Tu pago Voltika vence en 2 días",
            'html'    => "<p>Hola $n,</p><p>Te recordamos que tu pago semanal de <b>$m</b> vence el <b>$f</b>.</p><p>Puedes pagar desde la app o esperar el cobro automático.</p><p>— Voltika</p>",
            'sms'     => "Voltika: Tu pago de $m vence el $f. Paga en la app para evitar cargos.",
        ],
        'D0' => [
            'subject' => "⚡ Hoy vence tu pago Voltika",
            'html'    => "<p>Hola $n,</p><p>Hoy es el día: tu pago de <b>$m</b> vence hoy <b>$f</b>.</p>",
            'sms'     => "Voltika: Tu pago de $m vence HOY. Abre la app para pagar.",
        ],
        'D+1' => [
            'subject' => "⚠️ Pago Voltika pendiente",
            'html'    => "<p>Hola $n,</p><p>Tu pago de <b>$m</b> del $f quedó pendiente. Ayer intentamos cobrar sin éxito.</p><p>Entra a la app para regularizar.</p>",
            'sms'     => "Voltika: Tu pago de $m quedo pendiente. Regulariza en la app.",
        ],
        'D+3' => [
            'subject' => "⚠️ Tu cuenta Voltika está vencida",
            'html'    => "<p>Hola $n,</p><p>Han pasado 3 días desde tu pago del $f ($m). Por favor regulariza a la brevedad para evitar afectar tu historial.</p>",
            'sms'     => "Voltika: Cuenta vencida hace 3 dias. Regulariza en la app.",
        ],
        'D+7' => [
            'subject' => "🚨 URGENTE: Voltika — Pago vencido hace 1 semana",
            'html'    => "<p>Hola $n,</p><p>Tu pago del $f ($m) tiene 7 días vencido. Contáctanos por WhatsApp para evitar acciones de cobranza.</p>",
            'sms'     => "Voltika URGENTE: Pago vencido 7 dias. Contactanos YA.",
        ],
    ];
    return $templates[$tipo];
}
