<?php
/**
 * Cron — Enviar recordatorios y avisos de pago (WhatsApp + SMS + Email)
 * ────────────────────────────────────────────────────────────────────────
 * MSG A  — 2 days before due          (10:00 AM)
 * MSG B  — Due today                  (9:00 AM)
 * MSG C  — 48h overdue, unpaid        (max 1/day)
 * MSG D  — 96h overdue, unpaid        (max 1/day)
 * MSG E  — Advance payment incentive  (1st/15th of month, 4+ on-time)
 *
 * Ejecutar cada hora; el script filtra por hora para respetar horarios.
 * ────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/../php/bootstrap.php';

// ── voltika-notify.php lives in configurador_prueba/php (shared) ────────────
$notifyPath = realpath(__DIR__ . '/../../configurador_prueba/php/voltika-notify.php');
if (!$notifyPath) {
    $notifyPath = realpath(__DIR__ . '/../../configurador_prueba_test/php/voltika-notify.php');
}
if ($notifyPath) {
    require_once $notifyPath;
}

// ── Auth: validar token cron ────────────────────────────────────────────────
$cronToken = defined('VOLTIKA_CRON_TOKEN') ? VOLTIKA_CRON_TOKEN : (getenv('VOLTIKA_CRON_TOKEN') ?: '');
if ($cronToken) {
    $provided = $_SERVER['HTTP_X_CRON_TOKEN'] ?? ($_GET['token'] ?? '');
    if ($provided !== $cronToken) {
        adminJsonOut(['error' => 'Token inválido'], 403);
    }
}

$pdo = getDB();
$hora = (int)date('G'); // 0-23 server hour
$hoy  = date('Y-m-d');
$dia  = (int)date('j');  // day of month

$stats = [
    'msg_a' => 0, 'msg_b' => 0, 'msg_c' => 0, 'msg_d' => 0, 'msg_e' => 0,
    'failed' => 0,
];

// ═══════════════════════════════════════════════════════════════════════════════
// MSG A — Reminder 2 days before due (send at 10:00 AM)
// ═══════════════════════════════════════════════════════════════════════════════
if ($hora === 10) {
    $rows = $pdo->query("
        SELECT c.id, c.monto, c.semana_num, c.fecha_vencimiento,
               s.nombre, s.email, s.telefono, s.stripe_customer_id
        FROM ciclos_pago c
        JOIN subscripciones_credito s ON c.subscripcion_id = s.id
        WHERE c.estado = 'pending'
          AND c.fecha_vencimiento = DATE_ADD(CURDATE(), INTERVAL 2 DAY)
          AND (c.origen IS NULL OR c.origen NOT LIKE '%msg_a_sent%')
          AND s.estado = 'activa'
          AND s.telefono IS NOT NULL AND s.telefono != ''
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $ok = sendPaymentNotification('recordatorio_pago_2dias', $r, 'msg_a');
        $stats[$ok ? 'msg_a' : 'failed']++;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// MSG B — Payment due today (send at 9:00 AM)
// ═══════════════════════════════════════════════════════════════════════════════
if ($hora === 9) {
    $rows = $pdo->query("
        SELECT c.id, c.monto, c.semana_num, c.fecha_vencimiento,
               s.nombre, s.email, s.telefono, s.stripe_customer_id
        FROM ciclos_pago c
        JOIN subscripciones_credito s ON c.subscripcion_id = s.id
        WHERE c.estado = 'pending'
          AND c.fecha_vencimiento = CURDATE()
          AND (c.origen IS NULL OR c.origen NOT LIKE '%msg_b_sent%')
          AND s.estado = 'activa'
          AND s.telefono IS NOT NULL AND s.telefono != ''
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $ok = sendPaymentNotification('pago_vence_hoy', $r, 'msg_b');
        $stats[$ok ? 'msg_b' : 'failed']++;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// MSG C — First overdue notice (48h after due, unpaid)
// Only send if NOT paid and NOT pending (OXXO/SPEI acreditation window)
// ═══════════════════════════════════════════════════════════════════════════════
if ($hora === 10) {
    $rows = $pdo->query("
        SELECT c.id, c.monto, c.semana_num, c.fecha_vencimiento,
               s.nombre, s.email, s.telefono, s.stripe_customer_id
        FROM ciclos_pago c
        JOIN subscripciones_credito s ON c.subscripcion_id = s.id
        WHERE c.estado = 'overdue'
          AND c.fecha_vencimiento = DATE_SUB(CURDATE(), INTERVAL 2 DAY)
          AND (c.origen IS NULL OR c.origen NOT LIKE '%msg_c_sent%')
          AND s.estado = 'activa'
          AND s.telefono IS NOT NULL AND s.telefono != ''
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $ok = sendPaymentNotification('pago_vencido_48h', $r, 'msg_c');
        $stats[$ok ? 'msg_c' : 'failed']++;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// MSG D — Critical overdue (96h after due, unpaid)
// ═══════════════════════════════════════════════════════════════════════════════
if ($hora === 10) {
    $rows = $pdo->query("
        SELECT c.id, c.monto, c.semana_num, c.fecha_vencimiento,
               s.nombre, s.email, s.telefono, s.stripe_customer_id
        FROM ciclos_pago c
        JOIN subscripciones_credito s ON c.subscripcion_id = s.id
        WHERE c.estado = 'overdue'
          AND c.fecha_vencimiento = DATE_SUB(CURDATE(), INTERVAL 4 DAY)
          AND (c.origen IS NULL OR c.origen NOT LIKE '%msg_d_sent%')
          AND s.estado = 'activa'
          AND s.telefono IS NOT NULL AND s.telefono != ''
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $ok = sendPaymentNotification('pago_vencido_96h', $r, 'msg_d');
        $stats[$ok ? 'msg_d' : 'failed']++;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// MSG E — Advance payment incentive (1st and 15th of month, 10:00 AM)
// Only for customers with 4+ consecutive on-time payments
// ═══════════════════════════════════════════════════════════════════════════════
if ($hora === 10 && ($dia === 1 || $dia === 15)) {
    $rows = $pdo->query("
        SELECT s.id AS sub_id, s.nombre, s.email, s.telefono, s.stripe_customer_id
        FROM subscripciones_credito s
        WHERE s.estado = 'activa'
          AND s.telefono IS NOT NULL AND s.telefono != ''
          AND (
              SELECT COUNT(*) FROM ciclos_pago c2
              WHERE c2.subscripcion_id = s.id
                AND c2.estado IN ('paid_auto','paid_manual')
                AND c2.semana_num >= (
                    SELECT COALESCE(MAX(c3.semana_num), 0) - 3
                    FROM ciclos_pago c3
                    WHERE c3.subscripcion_id = s.id
                      AND c3.estado IN ('paid_auto','paid_manual')
                )
          ) >= 4
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        if (function_exists('voltikaNotify')) {
            $result = voltikaNotify('incentivo_adelanto', [
                'nombre'     => $r['nombre'] ?? '',
                'telefono'   => $r['telefono'] ?? '',
                'email'      => $r['email'] ?? '',
                'whatsapp'   => $r['telefono'] ?? '',
                'payment_link' => 'voltika.mx/mi-cuenta',
            ]);
            $stats['msg_e']++;
        }
    }
}


// ═══════════════════════════════════════════════════════════════════════════════
// HELPER
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Send a payment notification via voltikaNotify and mark the ciclo to avoid duplicates.
 */
function sendPaymentNotification(string $tipo, array $ciclo, string $marker): bool {
    global $pdo;

    if (!function_exists('voltikaNotify')) {
        // Fallback: send SMS directly (legacy behavior)
        return sendLegacySMS($ciclo);
    }

    $montoRaw     = (float)($ciclo['monto'] ?? 0);
    $montoFmt     = number_format($montoRaw, 2);
    $fechaHuman   = function_exists('voltikaFormatFechaHuman')
        ? voltikaFormatFechaHuman($ciclo['fecha_vencimiento'] ?? null)
        : ($ciclo['fecha_vencimiento'] ?? '');

    // Resolve pedido + short code from subscripcion → transacciones (best effort).
    $pedido = '';
    $pedidoCorto = '';
    if (!empty($ciclo['stripe_customer_id'])) {
        try {
            $t = $pdo->prepare("SELECT id, pedido FROM transacciones
                                 WHERE stripe_customer_id = ?
                                 ORDER BY id DESC LIMIT 1");
            $t->execute([$ciclo['stripe_customer_id']]);
            $txRow = $t->fetch(PDO::FETCH_ASSOC);
            if ($txRow) {
                $pedido = (string)($txRow['pedido'] ?? '');
                if (function_exists('voltikaResolvePedidoCorto')) {
                    $pedidoCorto = voltikaResolvePedidoCorto($pdo, (int)$txRow['id']);
                }
            }
        } catch (Throwable $e) {}
    }
    if (!$pedidoCorto && $pedido) $pedidoCorto = 'VK-' . $pedido;

    $result = voltikaNotify($tipo, [
        'nombre'            => $ciclo['nombre'] ?? '',
        'telefono'          => $ciclo['telefono'] ?? '',
        'email'             => $ciclo['email'] ?? '',
        'whatsapp'          => $ciclo['telefono'] ?? '',
        // Legacy aliases kept so old templates still interpolate cleanly.
        'monto'             => $montoFmt,
        'fecha'             => $ciclo['fecha_vencimiento'] ?? '',
        // New placeholders the rewritten templates expect.
        'monto_semanal'     => $montoFmt,
        'fecha_vencimiento' => $fechaHuman,
        'semana'            => (string)($ciclo['semana_num'] ?? ''),
        'pedido'            => $pedido,
        'pedido_corto'      => $pedidoCorto,
        'payment_link'      => 'https://voltika.mx/mi-cuenta',
    ]);

    // Mark as sent to avoid duplicate sends
    $currentOrigen = $ciclo['origen'] ?? '';
    $flag = $marker . '_sent';
    $newOrigen = $currentOrigen ? $currentOrigen . ',' . $flag : $flag;
    $pdo->prepare("UPDATE ciclos_pago SET origen = ? WHERE id = ?")
        ->execute([$newOrigen, $ciclo['id']]);

    return true;
}

/**
 * Legacy SMS fallback when voltikaNotify is unavailable.
 */
function sendLegacySMS(array $ciclo): bool {
    $smsKey = defined('SMSMASIVOS_API_KEY') ? SMSMASIVOS_API_KEY : (getenv('SMSMASIVOS_API_KEY') ?: '');
    if (!$smsKey) return false;

    $montoFmt = number_format($ciclo['monto'], 2);
    if (($ciclo['fecha_vencimiento'] ?? '') === date('Y-m-d')) {
        $msg = "Voltika: Tu pago semanal de \${$montoFmt} vence hoy. Paga en voltika.mx/mi-cuenta";
    } else {
        $msg = "Voltika: Tu pago semanal de \${$montoFmt} está vencido. Regulariza en voltika.mx/mi-cuenta";
    }

    $tel = preg_replace('/\D/', '', $ciclo['telefono']);
    if (strlen($tel) === 10) $tel = '52' . $tel;

    $ch = curl_init('https://api.smsmasivos.com.mx/sms/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $smsKey],
        CURLOPT_POSTFIELDS     => json_encode(['phone_number' => $tel, 'message' => $msg]),
    ]);
    $raw     = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    return !$curlErr;
}


adminLog('cron_recordatorios', $stats);
adminJsonOut(array_merge(['ok' => true], $stats));
