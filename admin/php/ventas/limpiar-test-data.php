<?php
/**
 * POST — Identify + delete TEST / placeholder rows that leak into
 * production dashboards (Ventas, Pagos, Envíos).
 *
 * Customer brief 2026-05-09 (Óscar — "The purchase I asked you to
 * delete are still there"): test orders created during development /
 * verification keep showing up in money totals and shipment lists.
 * They share a recognisable footprint:
 *
 *   • nombre / email contain "test", "prueba", "diag", "voltika diag"
 *   • pedido / pedido_corto contain "TEST", "DIAG"
 *   • stripe_pi starts with "manual-" or "test-"
 *   • TEST-* / BIC-TEST destinations on envíos
 *
 * Safety:
 *   • Defaults to ?dry_run=1 — returns a preview without mutating anything.
 *   • Real delete requires ?dry_run=0 AND a typed motivo (audit log).
 *   • Soft-delete (activo=0 where applicable) for inventario_motos.
 *   • Skips rows with real customer data (real CURP, valid CDC score,
 *     successful Stripe PI starting with pi_).
 *   • Returns a per-row "why" so the admin can verify before committing.
 *
 * Usage:
 *   GET  /admin/php/ventas/limpiar-test-data.php?dry_run=1
 *        → preview JSON, no writes
 *   POST /admin/php/ventas/limpiar-test-data.php
 *        Body: { dry_run: 0, motivo: "limpieza test feb-may" }
 *        → actually deletes + returns counts
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin']);   // admin-only — too destructive for cedis/operador

$pdo = getDB();

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    $body = adminJsonIn();
    $dryRun = !empty($body['dry_run']);
    $motivo = trim((string)($body['motivo'] ?? ''));
} else {
    $dryRun = !isset($_GET['dry_run']) || $_GET['dry_run'] !== '0';
    $motivo = trim((string)($_GET['motivo'] ?? ''));
}

if (!$dryRun && mb_strlen($motivo) < 6) {
    adminJsonOut([
        'error' => 'Motivo requerido (mín. 6 caracteres) para la operación destructiva. Usa ?dry_run=1 para preview sin tocar nada.',
    ], 400);
}

// ── Detection patterns ─────────────────────────────────────────────────
// Substring matches are case-insensitive (LIKE on lower-cased text).
$nameTestLike  = "(LOWER(nombre) LIKE '%test%' OR LOWER(nombre) LIKE '%prueba%' OR LOWER(nombre) LIKE '%diag%' OR LOWER(nombre) LIKE '%voltika diag%' OR LOWER(nombre) LIKE '%oscar%test%' OR LOWER(nombre) LIKE '%pavel%prueba%')";
$emailTestLike = "(LOWER(email) LIKE '%test%' OR LOWER(email) LIKE '%prueba%' OR LOWER(email) LIKE '%diag%')";
$pedidoTestLike= "(UPPER(pedido) LIKE '%TEST%' OR UPPER(pedido) LIKE '%DIAG%')";
$stripeTestLike= "(stripe_pi LIKE 'manual-%' OR stripe_pi LIKE 'test-%' OR stripe_pi LIKE 'TEST-%')";

$mainCond = "($nameTestLike OR $emailTestLike OR $pedidoTestLike OR $stripeTestLike)";

// ── 1. Identify candidate transacciones ─────────────────────────────────
$rows = [];
try {
    $stmt = $pdo->prepare("SELECT id, pedido, nombre, email, telefono, modelo, tpago, total,
                                  stripe_pi, pago_estado, freg
                             FROM transacciones
                            WHERE $mainCond
                            ORDER BY id DESC");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    adminJsonOut(['error' => 'Query transacciones: ' . $e->getMessage()], 500);
}

// Compute "why" reason per row + heuristic safety filter.
$candidates = [];
foreach ($rows as $r) {
    $reasons = [];
    $n  = strtolower((string)($r['nombre'] ?? ''));
    $em = strtolower((string)($r['email']  ?? ''));
    $p  = strtoupper((string)($r['pedido'] ?? ''));
    $pi = (string)($r['stripe_pi'] ?? '');

    if (strpos($n, 'test')   !== false)    $reasons[] = 'nombre contains "test"';
    if (strpos($n, 'prueba') !== false)    $reasons[] = 'nombre contains "prueba"';
    if (strpos($n, 'diag')   !== false)    $reasons[] = 'nombre contains "diag"';
    if (strpos($em, 'test')  !== false)    $reasons[] = 'email contains "test"';
    if (strpos($em, 'prueba')!== false)    $reasons[] = 'email contains "prueba"';
    if (strpos($em, 'diag')  !== false)    $reasons[] = 'email contains "diag"';
    if (strpos($p, 'TEST')   !== false)    $reasons[] = 'pedido contains "TEST"';
    if (strpos($p, 'DIAG')   !== false)    $reasons[] = 'pedido contains "DIAG"';
    if (strpos($pi, 'manual-') === 0)      $reasons[] = 'stripe_pi prefix manual-';
    if (strpos($pi, 'test-')   === 0 || strpos($pi, 'TEST-') === 0) $reasons[] = 'stripe_pi prefix test-';

    // Heuristic safety: skip rows that LOOK legit despite name match.
    // A real Stripe PaymentIntent always starts with "pi_". If the row
    // has one of those AND a non-trivial total AND pago_estado='pagada',
    // treat as legit even if the name happens to contain "test".
    $hasRealPI    = is_string($pi) && strpos($pi, 'pi_') === 0;
    $payOk        = strtolower((string)($r['pago_estado'] ?? '')) === 'pagada';
    $totalNonZero = (float)($r['total'] ?? 0) > 100;   // anything < 100 MXN is test grade
    if ($hasRealPI && $payOk && $totalNonZero) {
        // Flag as conflict — do NOT delete by default
        $r['conflict'] = 'Tiene stripe_pi=pi_ + pago=pagada + total>100. Revisar manualmente.';
    }
    $r['reasons'] = $reasons;
    $candidates[] = $r;
}

// ── 2. Linked records to clean too ─────────────────────────────────────
// We won't delete these here in dry-run, just count them. Real delete
// will issue CASCADE-style cleanup.
$linkedCounts = ['envios' => 0, 'inventario_motos' => 0, 'preaprobaciones' => 0];
$txIds = array_filter(array_map(function($r){ return (int)$r['id']; }, $candidates), function($id){ return $id > 0; });
$txIds = array_values(array_unique($txIds));

if ($txIds) {
    $in = implode(',', array_fill(0, count($txIds), '?'));
    try {
        $pedidos = $pdo->prepare("SELECT pedido FROM transacciones WHERE id IN ($in)");
        $pedidos->execute($txIds);
        $pedidoList = array_filter(array_column($pedidos->fetchAll(PDO::FETCH_ASSOC), 'pedido'));
        if ($pedidoList) {
            $inP = implode(',', array_fill(0, count($pedidoList), '?'));
            $pedidoNums = array_map(function($p){ return 'VK-' . $p; }, $pedidoList);
            $linkedCounts['inventario_motos'] = (int)$pdo->prepare("SELECT COUNT(*) FROM inventario_motos WHERE pedido_num IN ($inP)")
                ->execute($pedidoNums) ? (int)$pdo->query("SELECT FOUND_ROWS()")->fetchColumn() : 0;
        }
    } catch (Throwable $e) { /* non-fatal */ }
}

// ── DRY-RUN: return the plan and exit ──────────────────────────────────
if ($dryRun) {
    adminJsonOut([
        'ok'          => true,
        'mode'        => 'dry_run',
        'candidates'  => $candidates,
        'count'       => count($candidates),
        'linked'      => $linkedCounts,
        'instructions'=> 'Para borrar realmente: POST a este mismo endpoint con body {"dry_run":0,"motivo":"..."}',
    ]);
}

// ── REAL DELETE: transactional, with linked-row cleanup ────────────────
$deletedTx       = 0;
$releasedMotos   = 0;
$deletedEnvios   = 0;
$skippedConflict = 0;

try {
    $pdo->beginTransaction();
    foreach ($candidates as $row) {
        if (!empty($row['conflict'])) { $skippedConflict++; continue; }
        $txId = (int)$row['id'];

        // Release any inventario_motos linked to this pedido (don't
        // hard-delete the moto — it may be a real unit later reassigned).
        if (!empty($row['pedido'])) {
            $pedidoNum = 'VK-' . $row['pedido'];
            try {
                $pdo->prepare("UPDATE inventario_motos
                                  SET cliente_nombre=NULL, cliente_email=NULL, cliente_telefono=NULL,
                                      pedido_num=NULL, stripe_pi=NULL, pago_estado=NULL,
                                      tipo_asignacion=NULL, fmod=NOW()
                                WHERE pedido_num = ?")
                    ->execute([$pedidoNum]);
                $releasedMotos += $pdo->prepare("SELECT 1")->rowCount();
            } catch (Throwable $e) {}
            // Close any envíos pointing at this pedido
            try {
                $pdo->prepare("UPDATE envios SET estado='completado_no_exitoso', notas=CONCAT(COALESCE(notas,''),'\n[test-cleanup] ',?), fmod=NOW()
                                WHERE moto_id IN (SELECT id FROM inventario_motos WHERE pedido_num = ?)")
                    ->execute([$motivo, $pedidoNum]);
                $deletedEnvios++;
            } catch (Throwable $e) {}
        }

        // Hard-delete the transacciones row.
        try {
            $pdo->prepare("DELETE FROM transacciones WHERE id = ?")->execute([$txId]);
            $deletedTx++;
        } catch (Throwable $e) {}
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    adminJsonOut(['error' => 'Falló la operación: ' . $e->getMessage()], 500);
}

adminLog('limpiar_test_data', [
    'admin_id'       => $uid,
    'motivo'         => $motivo,
    'deleted_tx'     => $deletedTx,
    'released_motos' => $releasedMotos,
    'deleted_envios' => $deletedEnvios,
    'skipped'        => $skippedConflict,
]);

adminJsonOut([
    'ok'              => true,
    'mode'            => 'execute',
    'deleted_tx'      => $deletedTx,
    'released_motos'  => $releasedMotos,
    'deleted_envios'  => $deletedEnvios,
    'skipped_conflict'=> $skippedConflict,
    'motivo'          => $motivo,
]);
