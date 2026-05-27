<?php
/**
 * Cron — Generar ciclos de pago semanales
 * Crea registros en ciclos_pago para cada semana transcurrida
 * de las subscripciones activas que aún no tienen su ciclo generado.
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

$pdo = getDB();

// Get active subscriptions with a start date.
// Round 109 (2026-05-27) — Also select plazo_meses as fallback for when
// plazo_semanas is NULL (common when the sub was created by the regen tool
// or by legacy webhooks that only set plazo_meses).
$subs = $pdo->query("
    SELECT id, cliente_id, monto_semanal, plazo_semanas, plazo_meses, fecha_inicio
    FROM subscripciones_credito
    WHERE estado = 'activa' AND fecha_inicio IS NOT NULL
")->fetchAll(PDO::FETCH_ASSOC);

$totalCreated = 0;

foreach ($subs as $sub) {
    $inicio = new DateTime($sub['fecha_inicio']);
    $hoy    = new DateTime('today');
    $diffDays = (int)$inicio->diff($hoy)->days;

    // If fecha_inicio is in the future, skip
    if ($inicio > $hoy) continue;

    $semanasTranscurridas = (int)floor($diffDays / 7);
    // Round 109 (2026-05-27) — Derive plazo_semanas from plazo_meses when
    // the column is NULL/0. Without this, subs with only plazo_meses set
    // get maxSemana=0 → zero ciclos ever generated → invisible in Cobranza.
    // Caso Carlos Ricardo Sánchez: sub id=3, plazo_meses=36, plazo_semanas=NULL.
    $plazoSemanas = (int)($sub['plazo_semanas'] ?? 0);
    if ($plazoSemanas <= 0 && !empty($sub['plazo_meses'])) {
        $plazoSemanas = (int)round((int)$sub['plazo_meses'] * 4.33);
        // Self-heal: persist the derived value so future runs don't recalculate
        try {
            $pdo->prepare("UPDATE subscripciones_credito SET plazo_semanas = ? WHERE id = ? AND (plazo_semanas IS NULL OR plazo_semanas = 0)")
                ->execute([$plazoSemanas, (int)$sub['id']]);
        } catch (Throwable $e) { /* non-fatal */ }
    }
    $maxSemana = min($semanasTranscurridas + 1, $plazoSemanas);

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO ciclos_pago
            (subscripcion_id, cliente_id, semana_num, fecha_vencimiento, monto, estado)
        VALUES (?, ?, ?, ?, ?, 'pending')
    ");

    for ($semana = 1; $semana <= $maxSemana; $semana++) {
        $vencimiento = (clone $inicio)->modify('+' . ($semana * 7) . ' days')->format('Y-m-d');
        $stmt->execute([
            $sub['id'],
            $sub['cliente_id'],
            $semana,
            $vencimiento,
            $sub['monto_semanal'],
        ]);
        $totalCreated += $stmt->rowCount();
    }
}

adminLog('cron_generar_ciclos', [
    'subscripciones' => count($subs),
    'ciclos_creados' => $totalCreated,
]);

adminJsonOut([
    'ok'               => true,
    'subscripciones'   => count($subs),
    'ciclos_creados'   => $totalCreated,
]);
