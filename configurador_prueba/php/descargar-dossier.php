<?php
/**
 * Voltika — Serve / build the Dossier de Defensa for a purchase.
 *
 * Two access paths:
 *   1. ?moto_id=N&format=zip|pdf                — admin session required
 *   2. ?pedido=XXX&token=YY&format=zip|pdf      — HMAC-signed (legal/external counsel)
 *
 * If `&build=1` is passed and the caller is an admin, regenerate the
 * dossier (new version row) before serving. Otherwise serve the most
 * recent existing one.
 *
 * `format=pdf` returns the master index PDF only (Stripe Dispute upload
 * limit is ~5 MB; the index PDF stays well under). `format=zip` returns
 * the full evidence pack.
 */

require_once __DIR__ . '/dossier-defensa.php';

$pdo = getDB();
dossierEnsureSchema($pdo);

if (session_status() === PHP_SESSION_NONE) @session_start();
$adminOk = !empty($_SESSION['admin_user_id']);

$motoId = (int)($_GET['moto_id'] ?? 0);
$pedido = trim((string)($_GET['pedido'] ?? ''));
$token  = trim((string)($_GET['token']  ?? ''));
$format = strtolower((string)($_GET['format'] ?? 'zip'));
$forceBuild = !empty($_GET['build']);

if (!in_array($format, ['zip', 'pdf'], true)) {
    http_response_code(400);
    exit('format inválido (zip|pdf)');
}

// Resolve pedido + stripe_pi from moto_id when needed.
$stripePi = '';
if ($motoId > 0) {
    $st = $pdo->prepare("SELECT t.pedido, t.stripe_pi
                         FROM inventario_motos m
                         LEFT JOIN transacciones t ON t.id = m.transaccion_id
                         WHERE m.id = ?");
    $st->execute([$motoId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        if (!$pedido) $pedido = (string)$row['pedido'];
        $stripePi = (string)$row['stripe_pi'];
    }
}

// HMAC token verification for non-admin access.
if (!$adminOk) {
    if (!$pedido || !dossierVerifyToken($pedido, $stripePi, $token)) {
        http_response_code(404);
        exit('No encontrado');
    }
}

// Resolve moto_id from pedido for build calls.
if (!$motoId && $pedido) {
    $st = $pdo->prepare("SELECT m.id
                         FROM inventario_motos m
                         JOIN transacciones t ON t.id = m.transaccion_id
                         WHERE t.pedido = ? ORDER BY m.id DESC LIMIT 1");
    $st->execute([$pedido]);
    $motoId = (int)($st->fetchColumn() ?: 0);
}

// Optional rebuild.
if ($forceBuild && $adminOk && $motoId > 0) {
    $r = dossierBuild($motoId, ['motivo' => 'manual']);
    if (!$r['ok']) {
        http_response_code(500);
        exit('Error al construir dossier: ' . ($r['error'] ?? 'unknown'));
    }
}

// Look up the latest dossier row.
$dossier = null;
if ($pedido) {
    $dossier = dossierLatestForPedido($pedido);
}
if (!$dossier && $motoId > 0) {
    $st = $pdo->prepare("SELECT * FROM dossiers_defensa WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$motoId]);
    $dossier = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// If no dossier yet AND admin is calling, build one on the fly.
if (!$dossier && $adminOk && $motoId > 0) {
    $r = dossierBuild($motoId, ['motivo' => 'manual']);
    if ($r['ok']) {
        $st = $pdo->prepare("SELECT * FROM dossiers_defensa WHERE id = ?");
        $st->execute([$r['dossier_id']]);
        $dossier = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!$dossier) {
    http_response_code(404);
    exit('Dossier no disponible — el sistema requiere al menos VIN asignado y entrega registrada.');
}

$relPath = $format === 'pdf' ? $dossier['master_pdf_path'] : $dossier['zip_path'];
if (!$relPath) {
    http_response_code(404);
    exit('Archivo del dossier no localizado');
}
$absPath = __DIR__ . '/../' . ltrim($relPath, '/');
if (!file_exists($absPath)) {
    http_response_code(404);
    exit('Archivo del dossier no encontrado en disco — regenerar con &build=1');
}

$dispositionName = $format === 'pdf'
    ? 'Voltika_Defensa_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $dossier['pedido']) . '.pdf'
    : 'Voltika_Defensa_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $dossier['pedido']) . '.zip';

header('Content-Type: ' . ($format === 'pdf' ? 'application/pdf' : 'application/zip'));
header('Content-Disposition: attachment; filename="' . $dispositionName . '"');
header('Content-Length: ' . filesize($absPath));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($absPath);
exit;
