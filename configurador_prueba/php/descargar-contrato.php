<?php
/**
 * Voltika — Serve Contrato de Compraventa al Contado PDF.
 *
 * Two authorization paths:
 *   1. Customer link  : ?pedido=XXX&token=YY  (HMAC over pedido + stripe_pi)
 *   2. Admin override : valid admin session (used by the admin panel)
 *
 * The admin override exists so support staff can re-issue / inspect a
 * contract without the customer having to find the original email link.
 */

require_once __DIR__ . '/contrato-contado.php';

$pedido = isset($_GET['pedido']) ? trim($_GET['pedido']) : '';
$token  = isset($_GET['token'])  ? trim($_GET['token'])  : '';

if ($pedido === '') {
    http_response_code(400);
    echo 'pedido requerido';
    exit;
}

// Look up the order to retrieve the stripe_pi we hashed against. If we
// can't find the row we 404 instead of leaking existence information by
// distinguishing "no row" from "wrong token".
$stripePi = '';
$pdfPath  = '';
try {
    $pdo = getDB();
    $row = $pdo->prepare("SELECT stripe_pi, contrato_pdf_path
                          FROM transacciones
                          WHERE pedido = ?
                          ORDER BY id DESC LIMIT 1");
    $row->execute([$pedido]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $stripePi = (string)($r['stripe_pi'] ?? '');
        $pdfPath  = (string)($r['contrato_pdf_path'] ?? '');
    }
} catch (Throwable $e) {
    error_log('descargar-contrato lookup: ' . $e->getMessage());
}

// ── Authorize ───────────────────────────────────────────────────────────
// Admin session lives under session_name('VOLTIKA_ADMIN') (see
// admin/php/bootstrap.php). We must adopt that name BEFORE session_start
// or PHP creates a fresh empty session under PHPSESSID and admin auth
// silently fails — that produced the misleading "No encontrado" 404
// when an admin clicked the contract download button (2026-04-29).
$adminOk = false;
if (session_status() === PHP_SESSION_NONE) {
    @session_name('VOLTIKA_ADMIN');
    @session_start();
}
if (!empty($_SESSION['admin_user_id'])) {
    $adminOk = true;
}

if (!$adminOk && !contratoContadoVerifyToken($pedido, $stripePi, $token)) {
    http_response_code(404);
    // Helpful hint for admin who clicked the button without an active
    // session (e.g. testing in a private window) — for end-customers
    // we still return a generic 404 so we don't leak existence info.
    if (isset($_GET['debug']) && $_GET['debug'] === '1') {
        echo "404 · token inválido o sesión admin no detectada (session_name VOLTIKA_ADMIN). pedido_db_found=" . (!empty($r) ? '1' : '0');
    } else {
        echo 'No encontrado';
    }
    exit;
}

// ── Resolve the PDF on disk ─────────────────────────────────────────────
// If the DB has a relative path use it; otherwise fall back to the
// canonical naming so we can still serve files that pre-date the schema
// migration (path was empty for old rows).
$absPath = '';
if ($pdfPath !== '') {
    $candidate = __DIR__ . '/../' . ltrim($pdfPath, '/');
    if (file_exists($candidate)) $absPath = $candidate;
}
if ($absPath === '') {
    $candidate = contratoContadoPdfPath($pedido);
    if (file_exists($candidate)) $absPath = $candidate;
}

if ($absPath === '' || !is_readable($absPath)) {
    http_response_code(404);
    echo 'Contrato no disponible';
    exit;
}

// ── Stream PDF ──────────────────────────────────────────────────────────
$dispositionName = 'Contrato_Voltika_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $pedido) . '.pdf';
$disposition = isset($_GET['inline']) && $_GET['inline'] === '1' ? 'inline' : 'attachment';

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($absPath));
header('Content-Disposition: ' . $disposition . '; filename="' . $dispositionName . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($absPath);
exit;
