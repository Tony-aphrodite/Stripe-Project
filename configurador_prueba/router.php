<?php
/**
 * Voltika Configurador — Local dev router
 * Usage: php -S localhost:4000 router.php
 *
 * - Serves real files normally
 * - Returns empty JS/CSS stubs for missing vendor files (prevents parse errors)
 * - Returns tiny transparent GIF for missing images
 * - Returns 204 for missing fonts/SVGs
 */

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;

// Serve actual files normally (configurador's own JS/CSS/images work as-is)
if (is_file($file)) {
    return false;
}

// Directory index → serve index.html
if (is_dir($file)) {
    $index = rtrim($file, '/') . '/index.html';
    if (is_file($index)) {
        include $index;
        exit;
    }
}

$ext = strtolower(pathinfo($uri, PATHINFO_EXTENSION));

// Missing JS → empty stub (prevents "Unexpected token '<'" syntax errors)
if ($ext === 'js') {
    header('Content-Type: application/javascript; charset=utf-8');
    echo '/* dev-stub: ' . basename($uri) . ' */';
    exit;
}

// Missing CSS → empty stub
if ($ext === 'css') {
    header('Content-Type: text/css; charset=utf-8');
    echo '/* dev-stub: ' . basename($uri) . ' */';
    exit;
}

// Missing images → 1×1 transparent GIF
if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'])) {
    header('Content-Type: image/gif');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}

// Missing SVG → empty SVG
if ($ext === 'svg') {
    header('Content-Type: image/svg+xml');
    echo '<svg xmlns="http://www.w3.org/2000/svg"/>';
    exit;
}

// Missing fonts → 204 No Content
if (in_array($ext, ['woff', 'woff2', 'ttf', 'eot'])) {
    http_response_code(204);
    exit;
}

// Anything else → 404
http_response_code(404);
