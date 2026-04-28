<?php
/**
 * Voltika+ — Voluntary SPEI bonus tracking.
 *
 * Tech Spec EN §9 "Voltika+ (optional voluntary SPEI program)":
 *   Customer can opt-in to pay weekly via SPEI bank transfer voluntarily
 *   before the scheduled card charge:
 *     - If SPEI received before cutoff: card is NOT charged.
 *     - System tracks voluntary payment ratio per customer.
 *     - 80% voluntary = 4 free weeks bonus at end of contract.
 *     - 100% voluntary = 8 free weeks bonus at end of contract.
 *     - Bonus reduces final balance, not refunded as cash.
 *
 * This module computes and persists the voluntary ratio plus the bonus
 * tier the customer has earned. The auto-cobro cron already skips a
 * cycle if estado='paid_manual' (manual SPEI payment recorded).
 *
 * Public functions:
 *   vplusEnsureSchema(PDO)
 *   vplusOptIn(int $subId, bool $on): void
 *   vplusRecordVoluntaryPayment(int $cicloId): void  - also sets estado='paid_manual'
 *   vplusComputeRatio(int $subId): array  - returns ratio + earned bonus weeks
 *   vplusApplyBonusAtEnd(int $subId): array  - reduces final balance
 */

require_once __DIR__ . '/config.php';

function vplusEnsureSchema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        // Per-subscription opt-in flag and computed metrics.
        foreach ([
            'voltika_plus_optin'    => "ALTER TABLE subscripciones_credito ADD COLUMN voltika_plus_optin TINYINT(1) NOT NULL DEFAULT 0",
            'voltika_plus_ratio'    => "ALTER TABLE subscripciones_credito ADD COLUMN voltika_plus_ratio DECIMAL(5,2) NULL",
            'voltika_plus_bonus'    => "ALTER TABLE subscripciones_credito ADD COLUMN voltika_plus_bonus_weeks INT NOT NULL DEFAULT 0",
            'voltika_plus_applied'  => "ALTER TABLE subscripciones_credito ADD COLUMN voltika_plus_applied_at DATETIME NULL",
        ] as $col => $alter) {
            $cols = $pdo->query("SHOW COLUMNS FROM subscripciones_credito LIKE '" . $col . "'")->fetch();
            if (!$cols) $pdo->exec($alter);
        }
    } catch (Throwable $e) { error_log('vplusEnsureSchema: ' . $e->getMessage()); }
}

function vplusOptIn(int $subId, bool $on = true): void {
    $pdo = getDB();
    vplusEnsureSchema($pdo);
    $pdo->prepare("UPDATE subscripciones_credito SET voltika_plus_optin = ? WHERE id = ?")
        ->execute([$on ? 1 : 0, $subId]);
}

/**
 * Returns ['ratio' => float (0..1), 'bonus_weeks' => int, 'paid_manual' => int, 'paid_auto' => int]
 */
function vplusComputeRatio(int $subId): array {
    $pdo = getDB();
    vplusEnsureSchema($pdo);

    $st = $pdo->prepare("
        SELECT
            SUM(CASE WHEN estado='paid_manual' THEN 1 ELSE 0 END) AS paid_manual,
            SUM(CASE WHEN estado='paid_auto'   THEN 1 ELSE 0 END) AS paid_auto,
            SUM(CASE WHEN estado IN ('paid_manual','paid_auto') THEN 1 ELSE 0 END) AS paid_total
        FROM ciclos_pago
        WHERE subscripcion_id = ?
    ");
    $st->execute([$subId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['paid_manual' => 0, 'paid_auto' => 0, 'paid_total' => 0];

    $manual = (int)$row['paid_manual'];
    $total  = (int)$row['paid_total'];
    $ratio  = $total > 0 ? round($manual / $total, 4) : 0.0;

    // Bonus tiers per spec.
    $bonus = 0;
    if ($ratio >= 1.00)      $bonus = 8;
    elseif ($ratio >= 0.80)  $bonus = 4;

    // Persist for dashboard / portal.
    try {
        $pdo->prepare("UPDATE subscripciones_credito
                       SET voltika_plus_ratio = ?, voltika_plus_bonus_weeks = ?
                       WHERE id = ?")
            ->execute([$ratio * 100, $bonus, $subId]);
    } catch (Throwable $e) {}

    return [
        'paid_manual' => $manual,
        'paid_auto'   => (int)$row['paid_auto'],
        'paid_total'  => $total,
        'ratio'       => $ratio,
        'bonus_weeks' => $bonus,
    ];
}

/**
 * Apply earned bonus at contract end — marks the last N weekly cycles
 * as estado='bonus_applied' so auto-cobro skips them and the customer's
 * final balance is reduced by N × pago_semanal.
 *
 * Idempotent: noop if voltika_plus_applied_at is already set.
 */
function vplusApplyBonusAtEnd(int $subId): array {
    $pdo = getDB();
    vplusEnsureSchema($pdo);

    $sub = $pdo->prepare("SELECT voltika_plus_applied_at, pago_semanal
                          FROM subscripciones_credito WHERE id = ?");
    $sub->execute([$subId]);
    $r = $sub->fetch(PDO::FETCH_ASSOC);
    if (!$r) return ['ok' => false, 'error' => 'subscripcion no encontrada'];
    if ($r['voltika_plus_applied_at']) {
        return ['ok' => true, 'duplicate' => true];
    }

    $compute = vplusComputeRatio($subId);
    $bonusWeeks = $compute['bonus_weeks'];
    if ($bonusWeeks === 0) return ['ok' => true, 'bonus_weeks' => 0];

    // Mark the last N pending cycles as bonus_applied (skipped from cobro).
    $pdo->prepare("UPDATE ciclos_pago
                   SET estado = 'bonus_applied',
                       origen = COALESCE(origen,'') || ',voltika_plus_bonus'
                   WHERE subscripcion_id = ?
                     AND estado = 'pending'
                   ORDER BY semana_num DESC
                   LIMIT ?")
        ->execute([$subId, $bonusWeeks]);

    $pdo->prepare("UPDATE subscripciones_credito
                   SET voltika_plus_applied_at = NOW()
                   WHERE id = ?")
        ->execute([$subId]);

    return [
        'ok' => true,
        'bonus_weeks' => $bonusWeeks,
        'monto_descontado' => $bonusWeeks * floatval($r['pago_semanal']),
    ];
}
