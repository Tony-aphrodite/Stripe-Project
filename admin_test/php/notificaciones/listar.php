<?php
/**
 * GET — List notification logs and status
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$pdo = getDB();
$today = date('Y-m-d');

$safeScalar = function($sql, $params = [], $default = 0) use ($pdo) {
    try { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchColumn(); }
    catch (Throwable $e) { return $default; }
};

// Summary
$remindersSentToday = (int)$safeScalar(
    "SELECT COUNT(*) FROM portal_recordatorios_log WHERE DATE(freg)=?", [$today]
);
$remindersSentWeek = (int)$safeScalar(
    "SELECT COUNT(*) FROM portal_recordatorios_log WHERE DATE(freg) >= DATE_SUB(?,INTERVAL 7 DAY)", [$today]
);

// Recent notification log
$recent = [];
try {
    $recent = $pdo->query("SELECT rl.*, c.nombre as cliente_nombre
        FROM portal_recordatorios_log rl
        LEFT JOIN clientes c ON rl.cliente_id = c.id
        ORDER BY rl.freg DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Notification preferences summary
$prefSummary = ['email' => 0, 'whatsapp' => 0, 'sms' => 0];
try {
    $prefSummary = $pdo->query("SELECT
        SUM(notif_email=1) as email,
        SUM(notif_whatsapp=1) as whatsapp,
        SUM(notif_sms=1) as sms
        FROM portal_preferencias")->fetch(PDO::FETCH_ASSOC) ?: $prefSummary;
} catch (Throwable $e) {}

// Trigger types defined in the system
$triggers = [
    ['tipo' => 'reminder_prepago',      'label' => 'Pago próximo',     'canal' => 'SMS/Email', 'activo' => true],
    ['tipo' => 'reminder_dia_anterior', 'label' => 'Día antes de pago','canal' => 'SMS/Email', 'activo' => true],
    ['tipo' => 'reminder_dia_pago',     'label' => 'Día de pago',      'canal' => 'SMS/Email', 'activo' => true],
    ['tipo' => 'pago_confirmado',       'label' => 'Pago confirmado',  'canal' => 'SMS/Email', 'activo' => true],
    ['tipo' => 'punto_asignado',        'label' => 'Unidad asignada',  'canal' => 'SMS/Email', 'activo' => true],
    ['tipo' => 'moto_enviada',          'label' => 'Unidad en tránsito','canal'=> 'SMS/Email', 'activo' => true],
    ['tipo' => 'lista_para_recoger',    'label' => 'Lista para entrega','canal'=> 'SMS/Email', 'activo' => true],
    ['tipo' => 'docs_disponibles',      'label' => 'Documentos listos','canal' => 'Email',     'activo' => false],
    ['tipo' => 'actualizar_tarjeta',    'label' => 'Actualizar tarjeta','canal'=> 'SMS/Email', 'activo' => false],
    ['tipo' => 'whatsapp_pago',         'label' => 'Recordatorio WhatsApp','canal'=> 'WhatsApp','activo' => false],
];

adminJsonOut([
    'ok'               => true,
    'enviados_hoy'     => $remindersSentToday,
    'enviados_semana'  => $remindersSentWeek,
    'preferencias'     => $prefSummary,
    'triggers'         => $triggers,
    'recientes'        => $recent,
]);
