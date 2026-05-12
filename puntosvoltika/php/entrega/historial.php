<?php
/**
 * GET — Historial de entregas completadas en este punto.
 *
 * Customer brief 2026-05-12 (Óscar, 7th round — screenshot 1: Entregas
 * sidebar entry): "Here we need the history of the delivered bikes."
 * The Entregas screen previously only listed motos that were ready for
 * delivery. Once a delivery was completed, the record vanished from the
 * punto's view — no way to consult past deliveries, documents, or
 * receivers.
 *
 * Returns each delivered moto with the linked entregas row, the matching
 * transaccion (for stripe_pi + payment data), and a quick `disponible`
 * map indicating which documents the front-end can offer.
 *
 * Query params:
 *   q       — optional free-text filter (VIN / modelo / pedido / cliente).
 *   limit   — optional cap (default 100, max 500).
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$pdo = getDB();

$q     = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 100);
if ($limit < 1)   $limit = 100;
if ($limit > 500) $limit = 500;

// Detect optional columns once so older installs degrade cleanly.
$hasActaCol = false;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM inventario_motos")->fetchAll(PDO::FETCH_COLUMN);
    $hasActaCol = in_array('cliente_acta_firmada', $cols, true);
} catch (Throwable $e) { /* non-fatal */ }

$actaSel = $hasActaCol ? 'm.cliente_acta_firmada' : '0 AS cliente_acta_firmada';

$where  = "WHERE m.punto_voltika_id = ? AND m.estado = 'entregada' AND m.activo = 1";
$params = [$ctx['punto_id']];

if ($q !== '') {
    $where .= " AND (m.vin LIKE ? OR m.vin_display LIKE ? OR m.modelo LIKE ?
                     OR m.pedido_num LIKE ? OR m.cliente_nombre LIKE ?)";
    $needle = '%' . $q . '%';
    array_push($params, $needle, $needle, $needle, $needle, $needle);
}

// Customer brief 2026-05-12 (Óscar, 7th round — Documentos modal showed
// "Contrato no disponible — falta identificador de pedido" and "Recibo
// no disponible — sin PaymentIntent" even when pedido_num was populated):
// many legacy rows have inventario_motos.pedido_num set but
// transaccion_id NULL. The strict JOIN t.id=m.transaccion_id then returns
// nothing and the modal lacks every document. We widen the JOIN with
// 3 fallback predicates so a matching transaccion is found via the
// pedido_num itself: 'VK-<raw>' shape, pedido_corto shape, or raw shape.
$sql = "SELECT m.id AS moto_id, m.vin, m.vin_display, m.modelo, m.color,
               m.cliente_nombre, m.cliente_telefono, m.cliente_email,
               m.pedido_num, m.precio_venta, m.fecha_estado AS fecha_entrega,
               $actaSel,
               e.id   AS entrega_id,
               e.freg AS entrega_freg,
               e.estado AS entrega_estado,
               e.otp_verified,
               u.nombre AS recibido_por_nombre,
               t.id       AS transaccion_id,
               t.pedido   AS pedido_largo,
               t.pedido_corto,
               t.stripe_pi,
               t.tpago,
               t.total    AS total_transaccion
          FROM inventario_motos m
          LEFT JOIN (
              SELECT e1.* FROM entregas e1
              WHERE e1.id = (SELECT MAX(e2.id) FROM entregas e2 WHERE e2.moto_id = e1.moto_id)
          ) e ON e.moto_id = m.id
          LEFT JOIN dealer_usuarios u ON u.id = e.dealer_id
          LEFT JOIN transacciones t ON (
                 t.id = m.transaccion_id
              OR (m.transaccion_id IS NULL AND CONCAT('VK-', t.pedido) = m.pedido_num)
              OR (m.transaccion_id IS NULL AND t.pedido_corto         = m.pedido_num)
              OR (m.transaccion_id IS NULL AND t.pedido               = m.pedido_num)
          )
          $where
         ORDER BY m.fecha_estado DESC, m.fmod DESC
         LIMIT $limit";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('historial entregas: ' . $e->getMessage());
    puntoJsonOut(['error' => 'Error al consultar historial', 'detail' => $e->getMessage()], 500);
}

// Compute a per-row "disponible" map so the frontend can render the
// Documentos modal without an extra round-trip.
//
// Customer brief 2026-05-12 (Óscar, 7th round): contract_key now also
// falls back to inventario_motos.pedido_num itself (stripped of the VK-
// prefix), which lets descargar-contrato.php's own 3-tier lookup find
// the transaccion when none was joinable here. This way even rows
// without a populated transaccion_id can still surface the contract
// option as long as the pedido_num is something descargar-contrato can
// resolve.
//
// Customer brief 2026-05-12 (Óscar, 7th round — screenshot: clicking
// Contrato firmado on a credit order opened a technical diagnostic page
// "Contrato no disponible · Pedido: 1778302204-2d66242e · RUTAS
// BUSCADAS / Dossier Setup / ..."). The cash/MSI contract is generated
// on the spot, but credit-family contracts (enganche/parcial/crédito)
// only get a PDF after the customer signs via Truora+Cincel from their
// portal. When that signing hasn't happened yet, descargar-contrato.php
// has nothing to serve and falls back to its admin-debug diagnostic
// page. We pre-check the credit-PDF availability here so the modal can
// render a friendly "pendiente de firma" card instead of letting the
// punto operator click into the technical view.
$contratosDir   = __DIR__ . '/../../../configurador/php/contratos';
$contratosDirTmp = sys_get_temp_dir() . '/voltika_contratos';

foreach ($rows as &$row) {
    $hasPi = !empty($row['stripe_pi']) && strpos($row['stripe_pi'], 'pi_') === 0;
    $pedidoNumBare = preg_replace('/^VK-/i', '', (string)($row['pedido_num'] ?? ''));
    $contractKey = $row['pedido_largo']
        ?: preg_replace('/^VK-/i', '', (string)($row['pedido_corto'] ?? ''))
        ?: $pedidoNumBare
        ?: ($row['transaccion_id'] ? 'TX' . (int)$row['transaccion_id'] : '');
    $row['contract_key'] = $contractKey;

    // Credit-family detection — these tpagos require the Truora+Cincel
    // signing flow before a PDF exists on disk.
    $tpagoNorm   = strtolower(trim((string)($row['tpago'] ?? '')));
    $isCreditFam = in_array($tpagoNorm, ['credito','enganche','parcial'], true);

    // For credit orders, mirror descargar-contrato.php's glob search to
    // know whether the signed PDF is actually present.
    $creditPdfExists = false;
    if ($isCreditFam) {
        $safeName = preg_replace('/[^a-zA-Z0-9]/', '_', (string)($row['cliente_nombre'] ?? ''));
        if ($safeName !== '') {
            $candidates = array_merge(
                @glob($contratosDir   . '/contrato_*' . $safeName . '*.pdf') ?: [],
                @glob($contratosDirTmp . '/contrato_*' . $safeName . '*.pdf') ?: []
            );
            $creditPdfExists = !empty($candidates);
        }
    }

    $row['is_credit']                  = $isCreditFam;
    $row['contract_credito_pendiente'] = $isCreditFam && !$creditPdfExists;
    $row['disponible']                 = [
        // Contract is "available" when:
        //   • we have a contract_key, AND
        //   • either it's NOT a credit order (cash/MSI regen always works)
        //     OR the credit PDF exists on disk
        'contrato'                   => $contractKey !== '' && (!$isCreditFam || $creditPdfExists),
        'contrato_credito_pendiente' => $isCreditFam && !$creditPdfExists,
        'recibo_pago'                => $hasPi,
    ];
}
unset($row);

puntoJsonOut([
    'ok'      => true,
    'entregas'=> $rows,
    'total'   => count($rows),
    'filter'  => $q,
]);
