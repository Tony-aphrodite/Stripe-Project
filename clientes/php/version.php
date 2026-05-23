<?php
/**
 * Voltika — Customer portal build version endpoint.
 *
 * Round 70 v5 (2026-05-23): the customer portal's JS modules are
 * cache-busted via $asset()?v=<mtime> in index.php, but that only
 * triggers a refresh when the HTML page itself reloads. Customers
 * who keep the portal open in a tab (or pinned as a PWA) hold onto
 * the old SPA in memory across deploys — so a bug fix in inicio.js
 * doesn't reach them until they manually hard-refresh.
 *
 * This endpoint returns the current "build version" — a hash of the
 * mtimes of the customer-facing JS modules. The SPA polls it on
 * startup, compares against the version stored in localStorage, and
 * forces a hard reload (clearing localStorage of the version key)
 * whenever the server version differs. End result: every customer
 * gets the latest JS within seconds of opening the app, with zero
 * manual intervention.
 *
 * URL: GET /clientes/php/version.php
 * Response: { version: "<hex>", files: { "<rel>": <mtime>, ... } }
 */

declare(strict_types=1);

// Strong no-cache headers so this response itself never gets cached.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// We hash mtimes of the JS files that drive the customer portal UI.
// If ANY of them is updated by a deploy, the hash changes and every
// connected client will reload on their next page open.
$files = [
    __DIR__ . '/../js/app.js',
    __DIR__ . '/../js/modules/login.js',
    __DIR__ . '/../js/modules/recovery.js',
    __DIR__ . '/../js/modules/inicio.js',
    __DIR__ . '/../js/modules/mis-compras.js',
    __DIR__ . '/../js/modules/pagos.js',
    __DIR__ . '/../js/modules/entrega.js',
    __DIR__ . '/../js/modules/documentos.js',
    __DIR__ . '/../js/modules/cuenta.js',
    __DIR__ . '/../js/modules/mivoltika.js',
    __DIR__ . '/../js/modules/ayuda.js',
    __DIR__ . '/../js/modules/notificaciones.js',
];

$mtimes = [];
$concat = '';
foreach ($files as $f) {
    $rel = ltrim(str_replace(__DIR__ . '/../', '', $f), '/');
    if (is_file($f)) {
        $m = @filemtime($f) ?: 0;
        $mtimes[$rel] = $m;
        $concat .= $rel . ':' . $m . "\n";
    }
}
$version = substr(sha1($concat), 0, 12);

echo json_encode([
    'version' => $version,
    'files'   => $mtimes,
], JSON_UNESCAPED_SLASHES);
