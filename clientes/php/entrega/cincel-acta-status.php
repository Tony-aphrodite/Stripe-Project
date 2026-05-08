<?php
/**
 * GET ?moto_id=N — Poll Cincel ACTA signing status from the customer portal.
 *
 * Bug 5.7 (customer brief 2026-05-08): the customer portal embeds the
 * Cincel signing UI in an iframe and polls this endpoint every few seconds
 * to detect signature completion. When the webhook (cincel-webhook.php)
 * has already updated cliente_acta_firmada=1, this endpoint returns
 * `signed: true` so the portal can redirect to the success view.
 *
 * Auth: portalRequireAuth (customer scope). Always validates ownership.
 */
require_once __DIR__ . '/../bootstrap.php';
$cid = portalRequireAuth();

$motoId = (int)($_GET['moto_id'] ?? 0);
if (!$motoId) portalJsonOut(['error' => 'moto_id requerido'], 400);

$moto = portalFindOwnedMoto($cid, $motoId);
if (!$moto) portalJsonOut(['error' => 'Moto no encontrada'], 404);

$pdo = getDB();
$stmt = $pdo->prepare("SELECT cincel_acta_document_id, cincel_acta_signing_url,
        cincel_acta_status, cincel_acta_pdf_url, cliente_acta_firmada,
        cliente_acta_fecha
    FROM inventario_motos WHERE id=?");
$stmt->execute([$motoId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

portalJsonOut([
    'ok'                 => true,
    'signed'             => !empty($row['cliente_acta_firmada']),
    'signed_at'          => $row['cliente_acta_fecha']         ?: null,
    'cincel_status'      => $row['cincel_acta_status']         ?: null,
    'cincel_document_id' => $row['cincel_acta_document_id']    ?: null,
    'signing_url'        => $row['cincel_acta_signing_url']    ?: null,
    'pdf_url'            => $row['cincel_acta_pdf_url']        ?: null,
]);
