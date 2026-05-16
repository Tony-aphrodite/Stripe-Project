<?php
/**
 * Voltika Punto — Round 44 (2026-05-16, Óscar).
 *
 * Session keep-alive heartbeat.
 *
 * WHY: long-running flows (especially Ensamble checklist: 48 items spread
 * across 3 fases, many photo uploads) frequently take longer than the
 * server's session.gc_maxlifetime (even after Round 35 extended it to 2 h).
 * The customer screenshot for Round 44 shows the operator at 26/48 items
 * (54%) hitting "No autorizado" when trying to upload a Fase 3 photo.
 *
 * This endpoint is a no-op except for the call to session_start() inside
 * bootstrap, which refreshes the cookie expiry on every hit. The punto JS
 * pings it every 3 minutes while the operator is on a long-running
 * screen (ensamble checklist, recepción form, entrega flow).
 *
 * Response is intentionally minimal: { ok, user_id, until } so the JS can
 * confirm the session is alive AND extract the expected expiry.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

// Don't use puntoRequireAuth here — we want a clean 200 with ok=false
// when the session has truly expired, so the JS can react gracefully
// (show "please re-login" UI) instead of getting an opaque 401.
header('Cache-Control: no-store');

if (empty($_SESSION['punto_user_id'])) {
    puntoJsonOut([
        'ok'    => false,
        'reason'=> 'session_expired',
        'hint'  => 'La sesión expiró. Inicia sesión otra vez para continuar.',
    ], 200);
}

// Bump the cookie expiry by re-issuing it with a fresh lifetime, matching
// the bootstrap config (2 h sliding window). This keeps long-running
// operators logged in as long as their browser is open + heartbeating.
$lifetime = 7200;
if (function_exists('session_set_cookie_params')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        session_id(),
        [
            'expires'  => time() + $lifetime,
            'path'     => $params['path']     ?? '/',
            'domain'   => $params['domain']   ?? '',
            'secure'   => $params['secure']   ?? !empty($_SERVER['HTTPS']),
            'httponly' => $params['httponly'] ?? true,
            'samesite' => $params['samesite'] ?? 'Lax',
        ]
    );
}

puntoJsonOut([
    'ok'       => true,
    'user_id'  => (int)$_SESSION['punto_user_id'],
    'punto_id' => (int)($_SESSION['punto_id'] ?? 0),
    'until'    => date('c', time() + $lifetime),
]);
