<?php
/**
 * Voltika — Audit endpoint for credit orders missing a signed contract.
 *
 * Customer brief 2026-05-12 (Óscar, 10th round — "There's other purchase
 * operations without signed contract" — systemic issue beyond Carlos):
 * surface every credit-family order (tpago in {credito, enganche, parcial})
 * where the customer paid down payment but never completed the
 * Truora+Cincel signing flow (contrato_pdf_path is empty / no Cincel
 * record). Each row carries enough info for the admin to:
 *   • triage urgency (días sin firmar, monto enganche, Truora status)
 *   • take action (preaprobacion_id → resend Truora link)
 *
 * Returns the same shape that admin-ventas.js already consumes, plus the
 * extra fields needed for the recovery panel.
 *
 * GET /admin/php/ventas/credito-sin-firma.php
 * Optional: ?days_min=0  (oldest unsigned first)
 *           ?limit=200
 */

require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin', 'cedis']);

$pdo = getDB();

$limit    = max(1, min((int)($_GET['limit']    ?? 200), 1000));
$daysMin  = max(0, (int)($_GET['days_min']  ?? 0));

// ── Schema discovery (table + column existence) ────────────────────────
// Customer brief 2026-05-12 (Óscar, 10th round — initial deployment
// returned "query_failed" because preaprobaciones / verificaciones_
// identidad tables don't exist on every install, and their column names
// also drift between deploys (curp_match vs vi.curp_match, etc.). We
// discover the schema at runtime and emit a query that only references
// what's actually present.
function _tableExists(PDO $pdo, string $name): bool {
    try {
        $q = $pdo->prepare("SELECT 1 FROM information_schema.tables
                             WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
        $q->execute([$name]);
        return (bool)$q->fetchColumn();
    } catch (Throwable $e) {
        // Fallback for hosts where information_schema is restricted.
        try { $pdo->query("SELECT 1 FROM `$name` LIMIT 0"); return true; }
        catch (Throwable $e2) { return false; }
    }
}
function _cols(PDO $pdo, string $table): array {
    try { return $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN); }
    catch (Throwable $e) { return []; }
}

$txCols = _cols($pdo, 'transacciones');
$hasPdfPath      = in_array('contrato_pdf_path', $txCols, true);
$hasPdfHash      = in_array('contrato_pdf_hash', $txCols, true);
$hasPedidoCorto  = in_array('pedido_corto',      $txCols, true);

$hasPreaprob = _tableExists($pdo, 'preaprobaciones');
$pCols       = $hasPreaprob ? _cols($pdo, 'preaprobaciones') : [];
$pHasScore   = in_array('score',       $pCols, true);
$pHasStatus  = in_array('status',      $pCols, true);
$pHasSeguim  = in_array('seguimiento', $pCols, true);

$hasVI = _tableExists($pdo, 'verificaciones_identidad');
$viCols = $hasVI ? _cols($pdo, 'verificaciones_identidad') : [];
$viHasStatus    = in_array('truora_status',     $viCols, true);
$viHasApproved  = in_array('truora_approved',   $viCols, true);
// Different installs use `truora_process_id` OR `process_id`; pick whichever exists.
$viProcCol = in_array('truora_process_id', $viCols, true) ? 'truora_process_id'
           : (in_array('process_id',         $viCols, true) ? 'process_id' : null);
$viHasUpdated   = in_array('truora_updated_at', $viCols, true);

// ── Build SELECT columns with NULL/empty fallbacks ─────────────────────
$selectPdfPath  = $hasPdfPath     ? "t.contrato_pdf_path"          : "NULL AS contrato_pdf_path";
$selectPdfHash  = $hasPdfHash     ? "t.contrato_pdf_hash"          : "NULL AS contrato_pdf_hash";
$selectShort    = $hasPedidoCorto ? "t.pedido_corto"               : "NULL AS pedido_corto";
$selectPid      = $hasPreaprob    ? "p.id"                         : "NULL AS preaprobacion_id";
$selectScore    = ($hasPreaprob && $pHasScore)  ? "p.score"        : "NULL AS score";
$selectPStatus  = ($hasPreaprob && $pHasStatus) ? "p.status"       : "NULL AS preap_status";
$selectPSeguim  = ($hasPreaprob && $pHasSeguim) ? "p.seguimiento"  : "NULL AS preap_seguimiento";
$selectViStatus = ($hasVI && $viHasStatus)    ? "vi.truora_status"     : "NULL AS truora_status";
$selectViAppr   = ($hasVI && $viHasApproved)  ? "vi.truora_approved"   : "NULL AS truora_approved";
$selectViProc   = ($hasVI && $viProcCol)      ? "vi.$viProcCol AS truora_process_id" : "NULL AS truora_process_id";
$selectViUpd    = ($hasVI && $viHasUpdated)   ? "vi.truora_updated_at" : "NULL AS truora_updated_at";

// Aliases so the renamed SELECT columns match the alias names below.
$selectPid     = $hasPreaprob ? "p.id AS preaprobacion_id" : "NULL AS preaprobacion_id";

// ── Conditional JOIN clauses (skip table entirely when missing) ────────
$joinPreaprob = $hasPreaprob ? "
    LEFT JOIN preaprobaciones p ON (
              (p.email    <> '' AND p.email    = t.email)
           OR (p.telefono <> '' AND p.telefono = t.telefono)
    )" : "";
$joinVI = $hasVI ? "
    LEFT JOIN verificaciones_identidad vi ON (
              (vi.email    <> '' AND vi.email    = t.email)
           OR (vi.telefono <> '' AND vi.telefono = t.telefono)
    )" : "";

// ── "Missing signature" predicate ──────────────────────────────────────
$whereMissing = $hasPdfPath
    ? "(t.contrato_pdf_path IS NULL OR t.contrato_pdf_path = '')"
    : "1=1";  // legacy install — treat all credit orders as missing PDF

$sql = "
    SELECT
        t.id                              AS transaccion_id,
        $selectShort,
        t.pedido                          AS pedido_legacy,
        t.nombre,
        t.email,
        t.telefono,
        t.tpago,
        t.pago_estado,
        t.total,
        t.modelo,
        t.color,
        t.stripe_pi,
        t.freg                            AS fecha_compra,
        DATEDIFF(NOW(), t.freg)           AS dias_sin_firmar,
        $selectPdfPath,
        $selectPdfHash,
        $selectPid,
        $selectScore,
        $selectPStatus,
        $selectPSeguim,
        $selectViStatus,
        $selectViAppr,
        $selectViProc,
        $selectViUpd
    FROM transacciones t
    $joinPreaprob
    $joinVI
    WHERE LOWER(TRIM(COALESCE(t.tpago,''))) IN ('credito','credito-orfano','enganche','parcial')
      AND LOWER(TRIM(COALESCE(t.pago_estado,''))) IN ('parcial','pagada','aprobada','approved','paid')
      AND $whereMissing
      AND DATEDIFF(NOW(), t.freg) >= $daysMin
    ORDER BY t.freg ASC
    LIMIT $limit
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Always return diagnostic detail so the admin can fix install-
    // specific schema issues without going through server logs.
    adminJsonOut([
        'ok' => false,
        'error' => 'query_failed',
        'detail' => $e->getMessage(),
        'schema' => [
            'has_preaprobaciones'        => $hasPreaprob,
            'has_verificaciones_identidad' => $hasVI,
            'has_contrato_pdf_path'      => $hasPdfPath,
            'has_pedido_corto'           => $hasPedidoCorto,
            'tx_cols'  => $txCols,
            'vi_cols'  => $viCols,
            'p_cols'   => $pCols,
        ],
    ], 500);
}

// Compute KPIs the dashboard banner can show in one glance.
$totalEnganches  = 0.0;
$conPreaprob     = 0;
$conTruora       = 0;
$diasMax         = 0;
$diasSum         = 0;
foreach ($rows as $r) {
    $totalEnganches += (float)($r['total'] ?? 0);
    if (!empty($r['preaprobacion_id']))  $conPreaprob++;
    if (!empty($r['truora_process_id'])) $conTruora++;
    $d = (int)($r['dias_sin_firmar'] ?? 0);
    if ($d > $diasMax) $diasMax = $d;
    $diasSum += $d;
}
$diasAvg = count($rows) > 0 ? round($diasSum / count($rows), 1) : 0;

adminJsonOut([
    'ok'    => true,
    'rows'  => $rows,
    'kpi'   => [
        'total_pedidos'      => count($rows),
        'monto_total'        => round($totalEnganches, 2),
        'con_preaprobacion'  => $conPreaprob,
        'sin_preaprobacion'  => count($rows) - $conPreaprob,
        'con_truora_iniciado'=> $conTruora,
        'dias_max_sin_firmar'=> $diasMax,
        'dias_promedio'      => $diasAvg,
    ],
]);
