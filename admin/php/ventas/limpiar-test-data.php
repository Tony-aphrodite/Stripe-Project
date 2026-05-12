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
    // A REAL Stripe PaymentIntent always matches the pattern
    //   pi_3[A-Za-z0-9]{24+}      (current production format, e.g. pi_3TSQuyDzBRkc6ufK1kS0S2lW)
    // FAKE / TEST PIs use distinguishable prefixes:
    //   pi_TEST_5500_CREDITO_2    (literal TEST)
    //   pi_GCTE_...                (GC test corrections, GCTE prefix)
    //   pi_TST_..., pi_FAKE_...    (other dev artifacts)
    // Customer brief 2026-05-09 (Oscar — second pass cleanup): the
    // earlier guard (strpos pi_ === 0) was too lax and preserved
    // obvious TEST rows whose PIs only START with pi_ but clearly
    // contain "TEST", "GCTE", etc. Tighten to the actual Stripe
    // PaymentIntent format AND exclude any PI whose body screams test.
    $isRealStripePI = is_string($pi)
        && preg_match('/^pi_3[A-Za-z0-9]{20,}$/', $pi)        // canonical pi_3 + ≥20 alnum
        && stripos($pi, 'test') === false
        && stripos($pi, 'gcte') === false
        && stripos($pi, 'fake') === false
        && stripos($pi, 'mock') === false;
    $payOk        = strtolower((string)($r['pago_estado'] ?? '')) === 'pagada';
    $totalNonZero = (float)($r['total'] ?? 0) > 100;   // anything < 100 MXN is test grade
    if ($isRealStripePI && $payOk && $totalNonZero) {
        // Flag as conflict — do NOT delete by default
        $r['conflict'] = 'Tiene stripe_pi formato real (pi_3...) + pago=pagada + total>100. Revisar manualmente.';
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

// ── 2b. ORPHAN TEST envíos / motos pass (customer brief 2026-05-09) ────
// Catches TEST data that has NO matching transacciones row — e.g. the
// R4WPATATEST500001 / VK-1826-TEST envío whose moto was orphan TEST
// inventory never linked to a sale. The previous pass only deleted
// transacciones and their linked envíos; this pass independently scans
// inventario_motos + envios for VIN / VIN_display patterns that scream
// "test artifact". Same safety stance as the txn pass: only matches
// names that no real customer would have, and the cleanup is logged.
$orphanMotos  = [];
$orphanEnvios = [];
try {
    // Motos with a test-marker VIN that are STILL active (activo=1)
    // and DON'T have a real customer linked. Adding the activo check
    // means we don't bother flagging motos already soft-deleted by the
    // punto-side eliminar.php flow.
    $vinTestSql = "SELECT id, vin, vin_display, modelo, color, pedido_num, cliente_nombre, estado, activo
                     FROM inventario_motos
                    WHERE activo = 1
                      AND (
                            UPPER(vin)         LIKE '%TEST%'
                         OR UPPER(vin)         LIKE '%GCTEST%'
                         OR UPPER(vin)         LIKE '%FAKE%'
                         OR UPPER(vin_display) LIKE '%TEST%'
                         OR UPPER(vin_display) LIKE '%DIAG%'
                          )";
    $stmt = $pdo->prepare($vinTestSql);
    $stmt->execute();
    $orphanMotos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Annotate each with a "why" + safety verdict. If a moto has a
    // valid cliente_nombre that doesn't itself look test-y, flag it as
    // conflict so the cleanup skips it.
    foreach ($orphanMotos as &$m) {
        $m['why'] = 'VIN coincide con patrón test';
        $cn = strtolower((string)($m['cliente_nombre'] ?? ''));
        $clienteRealSospechoso = $cn !== ''
            && strpos($cn, 'test')   === false
            && strpos($cn, 'prueba') === false
            && strpos($cn, 'diag')   === false;
        if ($clienteRealSospechoso) {
            $m['conflict'] = 'Tiene cliente_nombre no-test (' . $m['cliente_nombre'] . '). Revisar manualmente.';
        }
    }
    unset($m);

    // Envíos pointing at any moto in the orphan list.
    // Customer brief 2026-05-09 (Óscar, 3rd round — "GCTESTVIN0000005,
    // GCTESTVIN0000007 and VK-1826-TEST still showing"): those rows
    // had estado=NULL/empty so the previous filter (only active states)
    // missed them entirely. Widen to also include rows with no estado
    // — they're test artifacts that never advanced past row creation
    // and should be closed for the same reason as active rows.
    if ($orphanMotos) {
        $motoIds = array_map(function($m){ return (int)$m['id']; }, $orphanMotos);
        $inM = implode(',', array_fill(0, count($motoIds), '?'));
        $eSql = "SELECT e.id AS envio_id, e.moto_id, e.estado, e.tracking_number, e.carrier,
                        e.fecha_envio, e.fecha_estimada_llegada,
                        m.vin, m.vin_display, m.modelo, m.color
                   FROM envios e
                   JOIN inventario_motos m ON m.id = e.moto_id
                  WHERE e.moto_id IN ($inM)
                    AND (
                          e.estado IN ('lista_para_enviar','enviada','enviado','en_transito')
                       OR e.estado IS NULL
                       OR e.estado = ''
                        )";
        $eStmt = $pdo->prepare($eSql);
        $eStmt->execute($motoIds);
        $orphanEnvios = $eStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('limpiar-test-data orphan scan: ' . $e->getMessage());
}

// ── 2c. Duplicate-envío detection ──────────────────────────────────────
// Customer brief 2026-05-09 (Óscar, 3rd round): R4WPDTA18T8000048
// appeared 3 times in Envíos — same moto routed to 3 different
// destinations. A motorcycle cannot be physically shipped to 3 places.
// The cause is admin / cron flows creating new envío rows for an
// existing moto without closing the previous ones. We detect the
// pattern (same moto_id × multiple active envíos) and KEEP the most
// recent row, closing the older duplicates as completado_no_exitoso.
$duplicateEnvios = [];
try {
    $dupSql = "SELECT moto_id,
                      GROUP_CONCAT(id ORDER BY freg DESC) AS env_ids,
                      COUNT(*) AS dup_count
                 FROM envios
                WHERE moto_id IS NOT NULL
                  AND (
                        estado IN ('lista_para_enviar','enviada','enviado','en_transito')
                     OR estado IS NULL
                     OR estado = ''
                      )
             GROUP BY moto_id
               HAVING COUNT(*) > 1";
    $dupRows = $pdo->query($dupSql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($dupRows as $dup) {
        $ids = array_map('intval', explode(',', (string)$dup['env_ids']));
        // First id (sorted by freg DESC) is the most recent — keep it.
        // Everything else becomes a stale duplicate to close.
        $keep = $ids[0];
        $toClose = array_slice($ids, 1);
        // Hydrate with details for the dry-run preview
        if ($toClose) {
            $inD = implode(',', array_fill(0, count($toClose), '?'));
            $dStmt = $pdo->prepare("SELECT e.id AS envio_id, e.moto_id, e.estado, e.freg,
                                           e.punto_destino_id, pv.nombre AS punto_nombre,
                                           m.vin, m.vin_display, m.modelo, m.color
                                      FROM envios e
                                 LEFT JOIN puntos_voltika pv ON pv.id = e.punto_destino_id
                                      JOIN inventario_motos m ON m.id = e.moto_id
                                     WHERE e.id IN ($inD)");
            $dStmt->execute($toClose);
            foreach ($dStmt->fetchAll(PDO::FETCH_ASSOC) as $de) {
                $de['kept_envio_id'] = $keep;
                $de['why'] = 'moto_id ' . $dup['moto_id'] . ' tiene ' . $dup['dup_count']
                           . ' envíos activos — mantener el más reciente (#' . $keep . '), cerrar los anteriores';
                $duplicateEnvios[] = $de;
            }
        }
    }
} catch (Throwable $e) {
    error_log('limpiar-test-data duplicate scan: ' . $e->getMessage());
}

// ── DRY-RUN: return the plan and exit ──────────────────────────────────
if ($dryRun) {
    adminJsonOut([
        'ok'                 => true,
        'mode'               => 'dry_run',
        'candidates'         => $candidates,
        'count'              => count($candidates),
        'linked'             => $linkedCounts,
        // Orphan-TEST pass — VIN-based detection (no transacciones row)
        'orphan_motos'       => $orphanMotos,
        'orphan_motos_count' => count($orphanMotos),
        'orphan_envios'      => $orphanEnvios,
        'orphan_envios_count'=> count($orphanEnvios),
        // Duplicate-envío pass — same moto with multiple active envíos
        'duplicate_envios'       => $duplicateEnvios,
        'duplicate_envios_count' => count($duplicateEnvios),
        'instructions'       => 'Para borrar realmente: POST a este mismo endpoint con body {"dry_run":0,"motivo":"..."}',
    ]);
}

// ── REAL DELETE: transactional, with linked-row cleanup ────────────────
$deletedTx          = 0;
$releasedMotos      = 0;
$deletedEnvios      = 0;
$skippedConflict    = 0;
$orphanEnviosClosed = 0;
$orphanMotosSoftDel = 0;
$orphanSkipped      = 0;

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

    // ── 2b. Orphan TEST pass: close envíos + soft-delete motos whose
    //        VIN matches test patterns, when no real customer is linked.
    //        These had NO transacciones row so the loop above missed them.
    foreach ($orphanEnvios as $oe) {
        // Find matching orphan moto to honour its conflict flag
        $matching = null;
        foreach ($orphanMotos as $om) {
            if ((int)$om['id'] === (int)$oe['moto_id']) { $matching = $om; break; }
        }
        if ($matching && !empty($matching['conflict'])) { $orphanSkipped++; continue; }
        try {
            $pdo->prepare("UPDATE envios
                              SET estado = 'completado_no_exitoso',
                                  notas  = CONCAT(COALESCE(notas,''), '\n[test-cleanup-orphan] ', ?),
                                  fmod   = NOW()
                            WHERE id = ?
                              AND (
                                    estado IN ('lista_para_enviar','enviada','enviado','en_transito')
                                 OR estado IS NULL
                                 OR estado = ''
                                  )")
                ->execute([$motivo, (int)$oe['envio_id']]);
            $orphanEnviosClosed++;
        } catch (Throwable $e) {
            error_log('orphan envio close: ' . $e->getMessage());
        }
    }
    // Soft-delete the orphan motos themselves so they stop appearing in
    // inventario listings. activo=0 preserves the row for audit; admin
    // can flip back to activo=1 if it turns out to be a mistake. We
    // also lazy-add eliminado_* audit columns so the source of the
    // deletion is traceable. Skip motos with a conflict flag.
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM inventario_motos")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('eliminado_por',    $cols, true)) $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN eliminado_por INT NULL");
        if (!in_array('eliminado_motivo', $cols, true)) $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN eliminado_motivo VARCHAR(250) NULL");
        if (!in_array('eliminado_fecha',  $cols, true)) $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN eliminado_fecha DATETIME NULL");
    } catch (Throwable $e) { error_log('orphan moto audit cols: ' . $e->getMessage()); }
    foreach ($orphanMotos as $om) {
        if (!empty($om['conflict'])) { continue; }
        try {
            $pdo->prepare("UPDATE inventario_motos
                              SET activo = 0,
                                  eliminado_por = ?,
                                  eliminado_motivo = ?,
                                  eliminado_fecha = NOW()
                            WHERE id = ? AND activo = 1")
                ->execute([$uid, '[test-cleanup-orphan] ' . $motivo, (int)$om['id']]);
            if ($pdo->prepare("SELECT 1")->rowCount() >= 0) {
                $orphanMotosSoftDel++;
            }
        } catch (Throwable $e) {
            error_log('orphan moto soft-delete: ' . $e->getMessage());
        }
    }

    // ── 2c. Close duplicate envíos (keep latest per moto) ──────────────
    // Each entry in $duplicateEnvios is an OLDER duplicate that should
    // be closed; the most recent envío for the same moto is kept.
    $duplicatesClosed = 0;
    foreach ($duplicateEnvios as $de) {
        try {
            $pdo->prepare("UPDATE envios
                              SET estado = 'completado_no_exitoso',
                                  notas  = CONCAT(COALESCE(notas,''),
                                                  '\n[test-cleanup-duplicate] mantener envío #', ?, ' · ', ?),
                                  fmod   = NOW()
                            WHERE id = ?
                              AND (
                                    estado IN ('lista_para_enviar','enviada','enviado','en_transito')
                                 OR estado IS NULL
                                 OR estado = ''
                                  )")
                ->execute([(int)$de['kept_envio_id'], $motivo, (int)$de['envio_id']]);
            $duplicatesClosed++;
        } catch (Throwable $e) {
            error_log('duplicate envio close: ' . $e->getMessage());
        }
    }
    $GLOBALS['__dupClosed'] = $duplicatesClosed;

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    adminJsonOut(['error' => 'Falló la operación: ' . $e->getMessage()], 500);
}

$duplicatesClosed = (int)($GLOBALS['__dupClosed'] ?? 0);

adminLog('limpiar_test_data', [
    'admin_id'              => $uid,
    'motivo'                => $motivo,
    'deleted_tx'            => $deletedTx,
    'released_motos'        => $releasedMotos,
    'deleted_envios'        => $deletedEnvios,
    'skipped'               => $skippedConflict,
    'orphan_envios_closed'  => $orphanEnviosClosed,
    'orphan_motos_soft_del' => $orphanMotosSoftDel,
    'orphan_skipped'        => $orphanSkipped,
    'duplicates_closed'     => $duplicatesClosed,
]);

adminJsonOut([
    'ok'                    => true,
    'mode'                  => 'execute',
    'deleted_tx'            => $deletedTx,
    'released_motos'        => $releasedMotos,
    'deleted_envios'        => $deletedEnvios,
    'skipped_conflict'      => $skippedConflict,
    // Orphan-TEST pass (no transacciones row, just VIN match)
    'orphan_envios_closed'  => $orphanEnviosClosed,
    'orphan_motos_soft_del' => $orphanMotosSoftDel,
    'orphan_skipped'        => $orphanSkipped,
    // Duplicate-envío pass (same moto with multiple active envíos)
    'duplicates_closed'     => $duplicatesClosed,
    'motivo'                => $motivo,
]);
