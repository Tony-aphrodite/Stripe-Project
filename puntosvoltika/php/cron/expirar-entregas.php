<?php
/**
 * Cron — Auto-expire stale delivery sessions.
 *
 * Bug 5.1 (customer brief 2026-05-08): "The process must not last longer
 * than 6 hours. If it is started and not completed within that time frame,
 * it must be closed so it can be started again."
 *
 * Schedule: every 30 minutes from crontab. Idempotent — running twice in
 * a row is a no-op for already-closed sessions.
 *
 * Reach: any entregas row where freg < NOW() - INTERVAL 6 HOUR AND
 * estado NOT IN ('entregada','no_exitosa') gets:
 *   estado            → 'no_exitosa'
 *   cancelado_motivo  → 'Expiración automática (>6h sin completar)'
 *   cancelado_at      → NOW()
 *
 * Run modes:
 *   php expirar-entregas.php             → live (acts on rows)
 *   php expirar-entregas.php --dry-run   → reports counts without writing
 */
require_once __DIR__ . '/../bootstrap.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

$pdo = getDB();

// Idempotent — same migration the live endpoints use.
try { $pdo->exec("ALTER TABLE entregas ADD COLUMN cancelado_motivo VARCHAR(500) NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE entregas ADD COLUMN cancelado_at DATETIME NULL"); } catch (Throwable $e) {}

$find = $pdo->prepare("
    SELECT id, moto_id, estado, freg
    FROM entregas
    WHERE freg < (NOW() - INTERVAL 6 HOUR)
      AND (estado IS NULL OR estado NOT IN ('entregada', 'no_exitosa'))
    ORDER BY freg ASC
    LIMIT 500
");
$find->execute();
$stale = $find->fetchAll(PDO::FETCH_ASSOC);

$count = count($stale);
echo "[expirar-entregas] " . date('Y-m-d H:i:s') . " — " . $count . " sesión(es) caducadas " . ($dryRun ? '(dry-run)' : '') . "\n";

if ($dryRun || $count === 0) {
    foreach ($stale as $s) {
        echo "  - entrega_id={$s['id']} moto_id={$s['moto_id']} estado=" . ($s['estado'] ?? 'null') . " freg={$s['freg']}\n";
    }
    exit(0);
}

$ids = array_map('intval', array_column($stale, 'id'));
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$pdo->prepare("UPDATE entregas
    SET estado='no_exitosa',
        cancelado_motivo=COALESCE(cancelado_motivo, 'Expiración automática (>6h sin completar)'),
        cancelado_at=NOW()
    WHERE id IN ($placeholders)")
    ->execute($ids);

echo "  ✓ Cerradas {$count} sesiones por expiración automática.\n";
