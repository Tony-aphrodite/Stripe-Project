<?php
/**
 * Cron — Enviar recordatorios de pago
 * Envía SMS a clientes con ciclos que vencen hoy o están vencidos (1-3 días).
 * Ejecutar una vez al día por la mañana.
 */
require_once __DIR__ . '/../php/bootstrap.php';

// ── Auth: validar token cron ────────────────────────────────────────────────
$cronToken = defined('VOLTIKA_CRON_TOKEN') ? VOLTIKA_CRON_TOKEN : (getenv('VOLTIKA_CRON_TOKEN') ?: '');
if ($cronToken) {
    $provided = $_SERVER['HTTP_X_CRON_TOKEN'] ?? ($_GET['token'] ?? '');
    if ($provided !== $cronToken) {
        adminJsonOut(['error' => 'Token inválido'], 403);
    }
}

$smsKey = defined('SMSMASIVOS_API_KEY') ? SMSMASIVOS_API_KEY : (getenv('SMSMASIVOS_API_KEY') ?: '');
if (!$smsKey) {
    adminJsonOut(['error' => 'SMS API no configurada'], 500);
}

$pdo = getDB();

// Get cycles due today (pending) or overdue by 1-3 days
// Skip cycles that already have 'reminder_sent' in origen to avoid re-sending
$ciclos = $pdo->query("
    SELECT c.id, c.monto, c.semana_num, c.fecha_vencimiento, c.estado,
           s.nombre, s.email, s.telefono
    FROM ciclos_pago c
    JOIN subscripciones_credito s ON c.subscripcion_id = s.id
    WHERE (
        (c.estado = 'pending' AND c.fecha_vencimiento = CURDATE())
        OR
        (c.estado = 'overdue' AND c.fecha_vencimiento BETWEEN DATE_SUB(CURDATE(), INTERVAL 3 DAY) AND CURDATE())
    )
    AND (c.origen IS NULL OR c.origen NOT LIKE '%reminder_sent%')
    AND s.telefono IS NOT NULL AND s.telefono != ''
    AND s.estado = 'activa'
")->fetchAll(PDO::FETCH_ASSOC);

$sent   = 0;
$failed = 0;

foreach ($ciclos as $ciclo) {
    // Build message
    $montoFmt = number_format($ciclo['monto'], 2);
    if ($ciclo['fecha_vencimiento'] === date('Y-m-d')) {
        $msg = "Voltika: Tu pago semanal de \${$montoFmt} vence hoy. Realiza tu pago para mantener tu cuenta al día.";
    } else {
        $msg = "Voltika: Tu pago semanal de \${$montoFmt} está vencido. Realiza tu pago para mantener tu cuenta al día.";
    }

    // Normalize phone number
    $tel = preg_replace('/\D/', '', $ciclo['telefono']);
    if (strlen($tel) === 10) $tel = '52' . $tel;

    // Send SMS
    $ch = curl_init('https://api.smsmasivos.com.mx/sms/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $smsKey],
        CURLOPT_POSTFIELDS     => json_encode([
            'phone_number' => $tel,
            'message'      => $msg,
        ]),
    ]);
    $raw     = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        $failed++;
        continue;
    }

    // Mark as reminder sent to avoid duplicates
    $currentOrigen = $ciclo['origen'] ?? '';
    $newOrigen = $currentOrigen ? $currentOrigen . ',reminder_sent' : 'reminder_sent';
    $pdo->prepare("UPDATE ciclos_pago SET origen = ? WHERE id = ?")
        ->execute([$newOrigen, $ciclo['id']]);

    $sent++;
}

adminLog('cron_recordatorios', [
    'candidatos' => count($ciclos),
    'enviados'   => $sent,
    'fallidos'   => $failed,
]);

adminJsonOut([
    'ok'        => true,
    'candidatos' => count($ciclos),
    'enviados'   => $sent,
    'fallidos'   => $failed,
]);
