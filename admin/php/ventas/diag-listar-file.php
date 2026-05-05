<?php
/**
 * Diagnostic — verifies the integrity of listar.php on the server.
 * Compares file size, line count, last bytes against the expected
 * values from the developer's local copy.
 *
 * Usage:
 *   https://voltika.mx/admin/php/ventas/diag-listar-file.php?key=voltika-diag-2026
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

if (($_GET['key'] ?? '') !== 'voltika-diag-2026') {
    http_response_code(403);
    echo json_encode(['ok'=>false, 'error'=>'forbidden']);
    exit;
}

$path = __DIR__ . '/listar.php';
if (!file_exists($path)) {
    echo json_encode(['ok'=>false, 'error'=>'listar.php missing on server']);
    exit;
}

$size  = filesize($path);
$cont  = file_get_contents($path);
$lines = substr_count($cont, "\n") + 1;
$tail  = substr($cont, -100);
$head  = substr($cont, 0, 100);
$hash  = md5($cont);

// Try to lint via the OPcache; if PHP can compile it, it's syntactically OK.
$compileError = null;
try {
    if (function_exists('opcache_compile_file')) {
        @opcache_invalidate($path, true);
        @opcache_compile_file($path);
    }
    // Last resort — eval-ish check: include the file in a guarded scope.
    // We don't actually run it (would require auth), just see if PHP can
    // parse it. token_get_all() returns false on parse error.
    $tokens = @token_get_all($cont, TOKEN_PARSE);
    if ($tokens === false) {
        $compileError = 'token_get_all failed — parse error in file';
    }
} catch (Throwable $e) {
    $compileError = $e->getMessage();
}

echo json_encode([
    'ok'             => true,
    'path'           => $path,
    'size_bytes'     => $size,
    'expected_size'  => 34821,
    'size_ok'        => $size === 34821,
    'line_count'     => $lines,
    'expected_lines' => 686,
    'lines_ok'       => $lines === 686,
    'first_100_chars'=> $head,
    'last_100_chars' => $tail,
    'md5'            => $hash,
    'compile_check'  => $compileError ?: 'parse OK',
    'php_version'    => PHP_VERSION,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
