<?php
/**
 * GET — Lista de todos los contratos firmados (contado / MSI / crédito).
 *
 * Customer brief 2026-05-13 (Óscar, 12th round — "my boss cannot check
 * the signed contracts"): the existing flow forced the boss to open
 * each order's Documentos modal one at a time to access the signed
 * contract — slow and impossible to audit. This endpoint surfaces
 * EVERY signed contract in a single dashboard:
 *
 *   • Cash / MSI: rows where contrato_pdf_path is set OR contrato_aceptado_at
 *     is set (the cash regen flow can always produce a PDF on demand).
 *   • Credit:   rows where contrato_pdf_path is set OR a file matching
 *     contrato_*<safe_name>*.pdf exists on disk (Truora+Cincel output).
 *
 * Filters (all optional):
 *   ?q=<search>        — fuzzy match cliente / pedido / VIN / email
 *   ?desde=YYYY-MM-DD  — earliest fecha_compra
 *   ?hasta=YYYY-MM-DD  — latest fecha_compra
 *   ?tpago=<value>     — exact tpago match (msi / contado / credito / enganche / parcial)
 *   ?punto_id=<int>    — restrict to one punto
 *   ?limit=<int>       — default 200, max 1000
 *
 * Response shape compatible with admin-contratos-firmados.js — see that
 * module for the rendering logic.
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin', 'cedis', 'documentos']);

$pdo = getDB();

$q        = trim((string)($_GET['q']        ?? ''));
$desde    = trim((string)($_GET['desde']    ?? ''));
$hasta    = trim((string)($_GET['hasta']    ?? ''));
$tpago    = trim((string)($_GET['tpago']    ?? ''));
$puntoId  = (int)($_GET['punto_id'] ?? 0);
$limit    = max(1, min((int)($_GET['limit'] ?? 200), 1000));

// ── Schema detection (some installs lag behind on columns) ─────────────
$txCols = [];
try {
    $txCols = $pdo->query("SHOW COLUMNS FROM transacciones")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { /* defensive */ }
$hasPdfPath  = in_array('contrato_pdf_path',     $txCols, true);
$hasAccepted = in_array('contrato_aceptado_at',  $txCols, true);
$hasShort    = in_array('pedido_corto',          $txCols, true);
$hasFolio    = in_array('folio_contrato',        $txCols, true);
$hasOtpAt    = in_array('contrato_otp_validated_at', $txCols, true);

if (!$hasPdfPath && !$hasAccepted) {
    adminJsonOut([
        'ok'    => true,
        'rows'  => [],
        'total' => 0,
        'note'  => 'La instalación no tiene aún las columnas de contrato — corre la migración de master-bootstrap.',
    ]);
}

// ── Build WHERE clauses ────────────────────────────────────────────────
$where  = [];
$params = [];

// "Signed contract" detection — at least ONE of the canonical signals
// must be present.
//
// Customer brief 2026-05-13 (Óscar, 12th round — Option B): cash and
// MSI orders are LEGALLY signed the moment the customer accepts the
// terms in checkout and Stripe captures the payment. The system stores
// the audit trail (stripe_pi, freg, customer info) on transacciones;
// the PDF is just a presentation of that audit trail and can always be
// regenerated on-demand via descargar-contrato.php. So we widen the
// detection: cash-family orders with a REAL Stripe PI + paid status
// are implicitly signed even if contrato_pdf_path was never persisted.
//
// Credit-family (enganche / parcial / credito) stays strict — those
// orders require an EXPLICIT Truora+Cincel signing event and don't get
// the implicit shortcut.
$signedConds = [];
if ($hasPdfPath)  $signedConds[] = "(t.contrato_pdf_path IS NOT NULL AND t.contrato_pdf_path <> '')";
if ($hasAccepted) $signedConds[] = "(t.contrato_aceptado_at IS NOT NULL)";
if ($hasOtpAt)    $signedConds[] = "(t.contrato_otp_validated_at IS NOT NULL)";
// Implicit-signed branch (cash/MSI/SPEI/OXXO only).
$signedConds[] = "(
    LOWER(COALESCE(t.tpago,'')) IN ('contado','unico','msi','spei','oxxo','tarjeta','tarjeta de débito o crédito','tarjeta de credito','tarjeta de debito')
    AND LOWER(COALESCE(t.pago_estado,'')) IN ('pagada','aprobada','approved','paid')
    AND t.stripe_pi IS NOT NULL
    AND t.stripe_pi REGEXP '^pi_3[A-Za-z0-9]{20,}$'
)";
$where[] = '(' . implode(' OR ', $signedConds) . ')';

// Search across multiple columns
if ($q !== '') {
    $like = '%' . $q . '%';
    $qparts = [
        "t.nombre LIKE ?",
        "t.email LIKE ?",
        "t.telefono LIKE ?",
        "t.pedido LIKE ?",
        "t.modelo LIKE ?",
    ];
    if ($hasShort) $qparts[] = "t.pedido_corto LIKE ?";
    $where[] = '(' . implode(' OR ', $qparts) . ')';
    foreach ($qparts as $_) $params[] = $like;
}

if ($desde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
    $where[] = "t.freg >= ?";
    $params[] = $desde . ' 00:00:00';
}
if ($hasta !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
    $where[] = "t.freg <= ?";
    $params[] = $hasta . ' 23:59:59';
}
if ($tpago !== '') {
    $where[] = "LOWER(t.tpago) = ?";
    $params[] = strtolower($tpago);
}
if ($puntoId > 0) {
    $where[] = "t.punto_id = ?";
    $params[] = $puntoId;
}

// ── Build SELECT ──────────────────────────────────────────────────────
$selectShort = $hasShort ? 't.pedido_corto' : "NULL AS pedido_corto";
$selectPdf   = $hasPdfPath ? 't.contrato_pdf_path' : "NULL AS contrato_pdf_path";
$selectAcc   = $hasAccepted ? 't.contrato_aceptado_at' : "NULL AS contrato_aceptado_at";
$selectFolio = $hasFolio ? 't.folio_contrato' : "NULL AS folio_contrato";
$selectOtp   = $hasOtpAt ? 't.contrato_otp_validated_at' : "NULL AS contrato_otp_validated_at";

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT
        t.id, t.pedido, $selectShort, t.nombre, t.email, t.telefono,
        t.modelo, t.color, t.tpago, t.total, t.stripe_pi,
        t.pago_estado, t.punto_id, t.punto_nombre,
        t.freg AS fecha_compra,
        $selectPdf,
        $selectAcc,
        $selectFolio,
        $selectOtp
    FROM transacciones t
    $whereSql
    ORDER BY COALESCE(t.contrato_aceptado_at, t.freg) DESC
    LIMIT $limit
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    adminJsonOut([
        'ok'     => false,
        'error'  => 'query_failed',
        'detail' => $e->getMessage(),
    ], 500);
}

// ── Post-process — credit-family rows: detect on-disk PDF presence ─────
// For credit orders the cash regen path doesn't apply; the PDF lives at
// contratos/contrato_*<safe_name>*.pdf or the /tmp fallback. We surface
// the presence flag so the UI can show "📥 Disponible" vs "⏳ Pendiente".
$contratosDir    = __DIR__ . '/../../../configurador/php/contratos';
$contratosDirTmp = sys_get_temp_dir() . '/voltika_contratos';
foreach ($rows as &$row) {
    $tp = strtolower(trim((string)($row['tpago'] ?? '')));
    $isCredit = in_array($tp, ['credito','credito-orfano','enganche','parcial'], true);
    $hasPath  = !empty($row['contrato_pdf_path']);

    $row['is_credit'] = $isCredit;
    $row['pdf_on_disk'] = false;

    if ($hasPath) {
        $row['pdf_on_disk'] = true;
    } elseif ($isCredit) {
        $safeName = preg_replace('/[^a-zA-Z0-9]/', '_', (string)($row['nombre'] ?? ''));
        if ($safeName !== '') {
            $candidates = array_merge(
                @glob($contratosDir    . '/contrato_*' . $safeName . '*.pdf') ?: [],
                @glob($contratosDirTmp . '/contrato_*' . $safeName . '*.pdf') ?: []
            );
            $row['pdf_on_disk'] = !empty($candidates);
        }
    } else {
        // Cash/MSI — descargar-contrato.php regenerates from the row
        // (stripe_pi + nombre + fecha) whenever the admin requests it.
        // Customer brief 2026-05-13 (Option B): paid cash/MSI orders are
        // ALWAYS reachable for regen even without contrato_aceptado_at;
        // we mark pdf_on_disk=true so the boss sees the Ver/Descargar
        // buttons enabled. The actual file is generated on click and
        // cached afterwards.
        $hasRealPi = isset($row['stripe_pi']) && preg_match('/^pi_3[A-Za-z0-9]{20,}$/', (string)$row['stripe_pi']);
        $isPaid    = in_array(strtolower((string)($row['pago_estado'] ?? '')), ['pagada','aprobada','approved','paid'], true);
        $row['pdf_on_disk'] = !empty($row['contrato_aceptado_at'])
                           || !empty($row['contrato_otp_validated_at'])
                           || ($hasRealPi && $isPaid);
    }

    // Pre-build the contract download URL using the same fallback chain
    // descargar-contrato.php accepts (pedido / pedido_corto / TX{id}).
    $contractKey = !empty($row['pedido']) ? $row['pedido']
                : (!empty($row['pedido_corto']) ? preg_replace('/^VK-/i', '', $row['pedido_corto'])
                : ('TX' . (int)$row['id']));
    $row['contract_url']      = '/configurador/php/descargar-contrato.php?pedido='
                              . urlencode($contractKey) . '&inline=1';
    $row['contract_dl_url']   = '/configurador/php/descargar-contrato.php?pedido='
                              . urlencode($contractKey);
}
unset($row);

// ── KPI calculation ────────────────────────────────────────────────────
$todayStart = strtotime('today');
$weekStart  = strtotime('-7 days');
$monthStart = strtotime(date('Y-m-01'));
$kpi = [
    'total'        => count($rows),
    'esta_semana'  => 0,
    'este_mes'     => 0,
    'hoy'          => 0,
    'contado_msi'  => 0,
    'credito'      => 0,
    'pdf_listo'    => 0,
    'pdf_pendiente'=> 0,
];
foreach ($rows as $r) {
    $ts = strtotime((string)($r['contrato_aceptado_at'] ?: $r['fecha_compra'] ?: 'now'));
    if ($ts >= $todayStart) $kpi['hoy']++;
    if ($ts >= $weekStart)  $kpi['esta_semana']++;
    if ($ts >= $monthStart) $kpi['este_mes']++;
    if (!empty($r['is_credit'])) $kpi['credito']++;
    else                          $kpi['contado_msi']++;
    if (!empty($r['pdf_on_disk'])) $kpi['pdf_listo']++;
    else                            $kpi['pdf_pendiente']++;
}

adminJsonOut([
    'ok'    => true,
    'rows'  => $rows,
    'total' => count($rows),
    'kpi'   => $kpi,
    'filters' => [
        'q'        => $q,
        'desde'    => $desde,
        'hasta'    => $hasta,
        'tpago'    => $tpago,
        'punto_id' => $puntoId,
    ],
]);
