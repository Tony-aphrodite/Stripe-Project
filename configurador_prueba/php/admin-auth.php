<?php
/**
 * Voltika Admin - Session Authentication Helper
 * Include this at the top of every protected admin PHP endpoint.
 * For HTML pages use: require_once 'php/admin-auth.php'; at the top.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Returns the authenticated dealer data or exits with 401.
 * $returnJson = true  → sends JSON error (for API endpoints)
 * $returnJson = false → redirects to dealer-panel.html (for HTML pages)
 */
function requireDealerAuth(bool $returnJson = true): array {
    if (empty($_SESSION['dealer_id'])) {
        if ($returnJson) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['error' => 'No autenticado', 'redirect' => '/configurador/dealer-panel.html']);
            exit;
        } else {
            header('Location: /configurador/dealer-panel.html');
            exit;
        }
    }
    return [
        'id'          => (int)$_SESSION['dealer_id'],
        'nombre'      => $_SESSION['dealer_nombre']      ?? '',
        'email'       => $_SESSION['dealer_email']       ?? '',
        'punto_nombre'=> $_SESSION['dealer_punto_nombre'] ?? '',
        'punto_id'    => $_SESSION['dealer_punto_id']    ?? '',
        'rol'         => $_SESSION['dealer_rol']         ?? 'dealer',
    ];
}

/**
 * Check if current session is authenticated (non-blocking).
 */
function isDealerAuth(): bool {
    return !empty($_SESSION['dealer_id']);
}

/**
 * Set session after successful login.
 */
function setDealerSession(array $dealer): void {
    $_SESSION['dealer_id']          = $dealer['id'];
    $_SESSION['dealer_nombre']      = $dealer['nombre'];
    $_SESSION['dealer_email']       = $dealer['email'];
    $_SESSION['dealer_punto_nombre']= $dealer['punto_nombre'] ?? '';
    $_SESSION['dealer_punto_id']    = $dealer['punto_id']     ?? '';
    $_SESSION['dealer_rol']         = $dealer['rol']          ?? 'dealer';
}

/**
 * Destroy session on logout.
 */
function destroyDealerSession(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
