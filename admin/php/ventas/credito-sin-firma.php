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

// Detect optional columns once so legacy installs still work.
$txCols = [];
try {
    $txCols = $pdo->query("SHOW COLUMNS FROM transacciones")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { /* fatal handled below */ }
$hasPdfPath = in_array('contrato_pdf_path', $txCols, true);
$hasPdfHash = in_array('contrato_pdf_hash', $txCols, true);
$hasPedidoCorto = in_array('pedido_corto', $txCols, true);

// SQL with conditional pieces. We deliberately use LEFT JOINs everywhere
// so a missing match (no preaprobacion, no verificaciones_identidad)
// doesn't drop the transaction from the report — those are exactly the
// rows the admin needs to see.
$selectPdfPath  = $hasPdfPath ? "t.contrato_pdf_path" : "'' AS contrato_pdf_path";
$selectPdfHash  = $hasPdfHash ? "t.contrato_pdf_hash" : "'' AS contrato_pdf_hash";
$selectShort    = $hasPedidoCorto ? "t.pedido_corto" : "'' AS pedido_corto";

// The "missing signature" predicate. Credit-family orders only have a PDF
// when contrato_pdf_path is set OR when a Cincel signing actually happened.
// We approximate "missing" as path-empty since cincel produces the PDF
// path. Legacy installs without contrato_pdf_path are treated as missing.
$whereMissing = $hasPdfPath
    ? "(t.contrato_pdf_path IS NULL OR t.contrato_pdf_path = '')"
    : "1=1";

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
        p.id                              AS preaprobacion_id,
        p.score                           AS score,
        p.status                          AS preap_status,
        p.seguimiento                     AS preap_seguimiento,
        vi.truora_status                  AS truora_status,
        vi.truora_approved                AS truora_approved,
        vi.truora_process_id              AS truora_process_id,
        vi.truora_updated_at              AS truora_updated_at
    FROM transacciones t
    LEFT JOIN preaprobaciones p ON (
              (p.email    <> '' AND p.email    = t.email)
           OR (p.telefono <> '' AND p.telefono = t.telefono)
    )
    LEFT JOIN verificaciones_identidad vi ON (
              (vi.email    <> '' AND vi.email    = t.email)
           OR (vi.telefono <> '' AND vi.telefono = t.telefono)
    )
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
    adminJsonOut([
        'ok' => false,
        'error' => 'query_failed',
        'detail' => $e->getMessage(),
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
