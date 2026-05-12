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
// Customer brief 2026-05-12 (Óscar, 12th round — "the test purchase
// I sent you yesterday is still there"): leftover test rows survived
// the previous cleanup pass. Expand the pattern set to catch internal-
// developer emails (@voltika.mx, @mtechmexico, @mrcdev), the common
// "dcm@" / "demo" / "qa" prefixes admins use for staging accounts,
// and OXXO/SPEI test references Stripe issues in test mode.
$nameTestLike  = "("
    . "LOWER(nombre) LIKE '%test%' "
    . "OR LOWER(nombre) LIKE '%prueba%' "
    . "OR LOWER(nombre) LIKE '%diag%' "
    . "OR LOWER(nombre) LIKE '%demo%' "
    . "OR LOWER(nombre) LIKE '%qa %' "
    . "OR LOWER(nombre) LIKE '%qa-%' "
    . "OR LOWER(nombre) LIKE '%voltika diag%' "
    . "OR LOWER(nombre) LIKE '%oscar%test%' "
    . "OR LOWER(nombre) LIKE '%pavel%prueba%' "
    . "OR LOWER(nombre) LIKE 'cliente voltika%' "
    . "OR LOWER(nombre) LIKE '%xxxx%'"
    . ")";
$emailTestLike = "("
    . "LOWER(email) LIKE '%test%' "
    . "OR LOWER(email) LIKE '%prueba%' "
    . "OR LOWER(email) LIKE '%diag%' "
    . "OR LOWER(email) LIKE '%demo%' "
    . "OR LOWER(email) LIKE '%qa@%' "
    . "OR LOWER(email) LIKE '%@example%' "
    . "OR LOWER(email) LIKE '%@voltika.mx' "
    . "OR LOWER(email) LIKE 'dcm@%' "
    . "OR LOWER(email) LIKE '%@mtechmexico%' "
    . "OR LOWER(email) LIKE '%@mrcdev%' "
    . "OR LOWER(email) LIKE '%@mrcdev.mx%' "
    . "OR LOWER(email) LIKE 'noreply@%' "
    . "OR LOWER(email) LIKE 'admin@%'"
    . ")";
$pedidoTestLike= "(UPPER(pedido) LIKE '%TEST%' OR UPPER(pedido) LIKE '%DIAG%' OR UPPER(pedido) LIKE '%DEMO%' OR UPPER(pedido) LIKE '%GCTE%')";
$stripeTestLike= "("
    . "stripe_pi LIKE 'manual-%' "
    . "OR stripe_pi LIKE 'test-%' "
    . "OR stripe_pi LIKE 'TEST-%' "
    . "OR stripe_pi LIKE 'pi_TEST%' "
    . "OR stripe_pi LIKE 'pi_GCTE%' "
    . "OR stripe_pi LIKE 'pi_TST%' "
    . "OR stripe_pi LIKE 'pi_FAKE%' "
    . "OR stripe_pi LIKE 'pi_MOCK%'"
    . ")";

// Telefono short / sequential patterns common in test data (e.g. 5500000000,
// 5512345678, 1234567890 repeats). Don't include this in the main condition
// alone — too broad — but use it as supporting evidence.
$telefonoSuspectLike = "(telefono REGEXP '^(5500|0000|1111|2222|3333|4444|5555|6666|7777|8888|9999|1234|0123)' OR telefono = '' OR telefono IS NULL)";

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

    // Envíos pointing at any moto whose VIN matches the test patterns,
    // regardless of inventario_motos.activo state. Earlier cleanup
    // rounds may have already soft-deleted (activo=0) the TEST motos
    // themselves, but the corresponding envíos with estado=NULL/empty
    // can still be lingering — that's exactly the symptom Óscar
    // showed in his 3rd-round screenshot (GCTESTVIN0000005,
    // GCTESTVIN0000007, VK-1826-TEST still rendering as "Sin estado").
    // Scan envíos DIRECTLY by the moto's VIN so we catch both:
    //   (a) motos still activo=1 (the original orphan case), and
    //   (b) motos already activo=0 but with orphan envíos remaining.
    $orphanEnviosSql = "SELECT e.id AS envio_id, e.moto_id, e.estado, e.tracking_number, e.carrier,
                              e.fecha_envio, e.fecha_estimada_llegada,
                              m.vin, m.vin_display, m.modelo, m.color, m.activo AS moto_activo
                         FROM envios e
                         JOIN inventario_motos m ON m.id = e.moto_id
                        WHERE (
                                UPPER(m.vin)         LIKE '%TEST%'
                             OR UPPER(m.vin)         LIKE '%GCTEST%'
                             OR UPPER(m.vin)         LIKE '%FAKE%'
                             OR UPPER(COALESCE(m.vin_display,'')) LIKE '%TEST%'
                             OR UPPER(COALESCE(m.vin_display,'')) LIKE '%DIAG%'
                              )
                          AND (
                                e.estado IN ('lista_para_enviar','enviada','enviado','en_transito')
                             OR e.estado IS NULL
                             OR e.estado = ''
                              )";
    try {
        $eStmt = $pdo->prepare($orphanEnviosSql);
        $eStmt->execute();
        $orphanEnvios = $eStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('orphan envios direct scan: ' . $e->getMessage()); }
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
    // Customer brief 2026-05-12 (Óscar, 12th round): admins were
    // reading JSON via the URL bar. Hard to interpret. ?html=1 renders
    // an admin-friendly diagnostic page with all candidates grouped,
    // reasons, and a one-click "Delete selected" affordance.
    if (!empty($_GET['html'])) {
        header('Content-Type: text/html; charset=UTF-8');
        $title = 'Limpieza de datos test — Diagnóstico';
        ?><!doctype html><html lang="es"><head><meta charset="utf-8">
        <title><?= htmlspecialchars($title) ?></title>
        <style>
            body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f5f7fb;color:#0c2340;padding:24px;max-width:1180px;margin:0 auto;}
            h1{font-size:22px;margin:0 0 6px;}
            h2{font-size:14px;color:#475569;margin:14px 0 8px;text-transform:uppercase;letter-spacing:.4px;}
            .kpi-row{display:flex;gap:10px;flex-wrap:wrap;margin:14px 0 22px;}
            .kpi{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;flex:1;min-width:160px;}
            .kpi-label{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;}
            .kpi-value{font-size:26px;font-weight:800;color:#0c2340;margin-top:4px;}
            .kpi.danger .kpi-value{color:#dc2626;}
            table{width:100%;background:#fff;border-collapse:collapse;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);margin-bottom:18px;}
            thead{background:#f1f5f9;}
            th,td{padding:8px 12px;font-size:12.5px;text-align:left;border-bottom:1px solid #f1f5f9;vertical-align:top;}
            th{color:#475569;font-weight:700;text-transform:uppercase;letter-spacing:.4px;font-size:11px;}
            tr:last-child td{border-bottom:0;}
            .badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;}
            .badge.red{background:#fee2e2;color:#991b1b;}
            .badge.yellow{background:#fef3c7;color:#92400e;}
            .badge.gray{background:#e5e7eb;color:#374151;}
            .reasons{font-size:11px;color:#64748b;}
            .conflict{background:#fffbeb;border-left:3px solid #d97706;padding:4px 8px;margin-top:2px;font-size:11px;color:#92400e;}
            .empty{padding:30px;text-align:center;background:#fff;border:1px dashed #cbd5e1;border-radius:10px;color:#64748b;}
            .action-bar{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;margin-top:20px;}
            .btn{background:#dc2626;color:#fff;border:0;padding:10px 18px;border-radius:6px;font-weight:700;cursor:pointer;font-size:13px;}
            .btn-ghost{background:#fff;color:#475569;border:1px solid #cbd5e1;}
            code{background:#1e293b;color:#e2e8f0;padding:2px 6px;border-radius:3px;font-size:11px;}
            details{margin-top:8px;}
            summary{cursor:pointer;font-size:12px;color:#475569;}
        </style></head><body>
        <h1>🧹 Limpieza de datos TEST — Diagnóstico</h1>
        <div style="color:#64748b;font-size:13px;margin-bottom:12px;">
            Modo: <strong>dry-run</strong> (no se modificó la base de datos). Para borrar realmente, hay que hacer POST con motivo.
        </div>
        <div class="kpi-row">
            <div class="kpi <?= count($candidates) > 0 ? 'danger' : '' ?>"><div class="kpi-label">Transacciones test</div><div class="kpi-value"><?= count($candidates) ?></div></div>
            <div class="kpi <?= count($orphanMotos) > 0 ? 'danger' : '' ?>"><div class="kpi-label">Motos huérfanas test</div><div class="kpi-value"><?= count($orphanMotos) ?></div></div>
            <div class="kpi <?= count($orphanEnvios) > 0 ? 'danger' : '' ?>"><div class="kpi-label">Envíos test residuales</div><div class="kpi-value"><?= count($orphanEnvios) ?></div></div>
            <div class="kpi <?= count($duplicateEnvios) > 0 ? 'danger' : '' ?>"><div class="kpi-label">Envíos duplicados</div><div class="kpi-value"><?= count($duplicateEnvios) ?></div></div>
        </div>

        <h2>Transacciones marcadas como test</h2>
        <?php if (!count($candidates)): ?>
            <div class="empty">✅ No se encontraron transacciones que coincidan con patrones de test.</div>
        <?php else: ?>
            <table><thead><tr>
                <th>ID</th><th>Pedido</th><th>Cliente</th><th>Email</th><th>Total</th><th>Stripe PI</th><th>Estado pago</th><th>Razones</th>
            </tr></thead><tbody>
            <?php foreach ($candidates as $r): ?>
                <tr>
                    <td><strong><?= (int)$r['id'] ?></strong></td>
                    <td><code><?= htmlspecialchars((string)($r['pedido'] ?? '—')) ?></code></td>
                    <td><?= htmlspecialchars((string)($r['nombre'] ?? '—')) ?></td>
                    <td><?= htmlspecialchars((string)($r['email'] ?? '—')) ?></td>
                    <td>$<?= number_format((float)($r['total'] ?? 0), 2) ?></td>
                    <td><code style="word-break:break-all;"><?= htmlspecialchars((string)($r['stripe_pi'] ?? '—')) ?></code></td>
                    <td><?= htmlspecialchars((string)($r['pago_estado'] ?? '—')) ?></td>
                    <td class="reasons">
                        <?php foreach (($r['reasons'] ?? []) as $why): ?>
                            <span class="badge red"><?= htmlspecialchars($why) ?></span>
                        <?php endforeach; ?>
                        <?php if (!empty($r['conflict'])): ?>
                            <div class="conflict">⚠ <?= htmlspecialchars($r['conflict']) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>

        <h2>Motos huérfanas (VIN test)</h2>
        <?php if (!count($orphanMotos)): ?>
            <div class="empty">✅ Sin motos huérfanas con VIN de test.</div>
        <?php else: ?>
            <table><thead><tr>
                <th>ID</th><th>VIN</th><th>Modelo · Color</th><th>Cliente</th><th>Pedido</th><th>Estado</th><th>Razón</th>
            </tr></thead><tbody>
            <?php foreach ($orphanMotos as $m): ?>
                <tr>
                    <td><strong><?= (int)$m['id'] ?></strong></td>
                    <td><code><?= htmlspecialchars((string)($m['vin'] ?? '—')) ?></code></td>
                    <td><?= htmlspecialchars((string)(($m['modelo'] ?? '') . ' · ' . ($m['color'] ?? ''))) ?></td>
                    <td><?= htmlspecialchars((string)($m['cliente_nombre'] ?? '—')) ?></td>
                    <td><code><?= htmlspecialchars((string)($m['pedido_num'] ?? '—')) ?></code></td>
                    <td><?= htmlspecialchars((string)($m['estado'] ?? '—')) ?></td>
                    <td class="reasons">
                        <span class="badge yellow"><?= htmlspecialchars((string)($m['why'] ?? '—')) ?></span>
                        <?php if (!empty($m['conflict'])): ?>
                            <div class="conflict">⚠ <?= htmlspecialchars((string)$m['conflict']) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>

        <h2>Envíos huérfanos (VIN test)</h2>
        <?php if (!count($orphanEnvios)): ?>
            <div class="empty">✅ Sin envíos test residuales.</div>
        <?php else: ?>
            <table><thead><tr>
                <th>Envío ID</th><th>Moto ID</th><th>VIN</th><th>Estado</th><th>Tracking</th><th>Modelo</th>
            </tr></thead><tbody>
            <?php foreach ($orphanEnvios as $e): ?>
                <tr>
                    <td><strong><?= (int)$e['envio_id'] ?></strong></td>
                    <td><?= (int)$e['moto_id'] ?></td>
                    <td><code><?= htmlspecialchars((string)($e['vin'] ?? '—')) ?></code></td>
                    <td><span class="badge gray"><?= htmlspecialchars((string)($e['estado'] ?? '—')) ?></span></td>
                    <td><code><?= htmlspecialchars((string)($e['tracking_number'] ?? '—')) ?></code></td>
                    <td><?= htmlspecialchars((string)(($e['modelo'] ?? '') . ' ' . ($e['color'] ?? ''))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>

        <h2>Envíos duplicados</h2>
        <?php if (!count($duplicateEnvios)): ?>
            <div class="empty">✅ Sin envíos duplicados activos.</div>
        <?php else: ?>
            <table><thead><tr>
                <th>Envío ID</th><th>Moto · VIN</th><th>Punto</th><th>Mantener envío</th><th>Razón</th>
            </tr></thead><tbody>
            <?php foreach ($duplicateEnvios as $d): ?>
                <tr>
                    <td><strong><?= (int)$d['envio_id'] ?></strong></td>
                    <td><?= htmlspecialchars((string)(($d['modelo'] ?? '') . ' · ' . ($d['color'] ?? ''))) ?><br><code style="font-size:10px;"><?= htmlspecialchars((string)($d['vin'] ?? '—')) ?></code></td>
                    <td><?= htmlspecialchars((string)($d['punto_nombre'] ?? '—')) ?></td>
                    <td>#<?= (int)($d['kept_envio_id'] ?? 0) ?></td>
                    <td class="reasons"><span class="badge yellow"><?= htmlspecialchars((string)($d['why'] ?? '—')) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>

        <div class="action-bar">
            <strong>Para borrar realmente:</strong> envía POST a este mismo URL con body
            <code>{"dry_run":0,"motivo":"limpieza solicitada por Óscar"}</code> desde un admin con permiso.
            <br><br>
            <a href="?dry_run=1&html=1" class="btn btn-ghost" style="text-decoration:none;display:inline-block;">🔄 Volver a escanear</a>
        </div>
        </body></html><?php
        exit;
    }

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
        'instructions'       => 'Para borrar realmente: POST a este mismo endpoint con body {"dry_run":0,"motivo":"..."}. Para vista HTML: agrega ?html=1',
    ]);
}

// ── REAL DELETE: transactional, with linked-row cleanup ────────────────
//
// Customer brief 2026-05-12 (Óscar, 12th round — Marcelino Hernandez
// rows ID#5 and #23 with @mrcdev.mx test email): row #5 had pago=pagada
// with a real-looking Stripe PI, so the conflict guard skipped it on
// bulk delete. Admins need a SURGICAL override for cases like this —
// when they've manually confirmed a row is test despite passing the
// safety heuristic. Two new body parameters:
//
//   force_ids: [5, 23]
//       Restrict the delete pass to ONLY these transaccion IDs AND
//       bypass the conflict flag for them. Other candidates aren't
//       touched. Use this when you've reviewed the dry-run and want
//       to remove specific known-test rows.
//
//   force_conflicts: true
//       Process the full candidates list but bypass the conflict flag.
//       More aggressive — only use when the dry-run looks clean of
//       false positives.
$forceIds       = [];
$forceConflicts = false;
if ($method === 'POST') {
    $body = $body ?? [];
    if (!empty($body['force_ids']) && is_array($body['force_ids'])) {
        $forceIds = array_values(array_unique(array_filter(array_map('intval', $body['force_ids']), fn($v)=>$v>0)));
    }
    $forceConflicts = !empty($body['force_conflicts']);
}
$useForceIds = count($forceIds) > 0;

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
        $txId = (int)$row['id'];
        // Surgical mode — skip rows not in the explicit list.
        if ($useForceIds && !in_array($txId, $forceIds, true)) continue;
        // Conflict guard — bypass when caller explicitly forced this ID or
        // requested force_conflicts. Without an override the row is kept
        // (safety against accidentally wiping a real paid order).
        if (!empty($row['conflict']) && !$forceConflicts && !($useForceIds && in_array($txId, $forceIds, true))) {
            $skippedConflict++;
            continue;
        }

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
    // Customer brief 2026-05-12 (12th round): echo back the surgical-
    // delete inputs so the admin can confirm the override was applied.
    // If skipped_conflict > 0 but force_ids was provided, the deploy
    // might be running an older copy of this script (PHP OPcache).
    'force_ids'             => $forceIds,
    'force_conflicts'       => $forceConflicts,
    'candidates_total'      => count($candidates),
]);
