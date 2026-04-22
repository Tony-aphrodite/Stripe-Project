<?php
/**
 * Certificates folder diagnostic — show what's actually in certs/ on the
 * server and whether the private key is loadable from session/disk.
 *
 *   https://voltika.mx/configurador_prueba/php/certs-diag.php?key=voltika_cdc_2026
 */
$expected = 'voltika_cdc_2026';
if (($_GET['key'] ?? '') !== $expected) {
    http_response_code(403); exit("Forbidden. Add ?key=$expected\n");
}

header('Content-Type: application/json');
session_start();

$dir = __DIR__ . '/certs';
$out = [
    'server_time'     => date('c'),
    'certs_dir'       => $dir,
    'dir_exists'      => is_dir($dir),
    'dir_writable'    => is_dir($dir) ? is_writable($dir) : null,
    'files'           => [],
    'session'         => [
        'cdc_key_pem_set'  => !empty($_SESSION['cdc_key_pem']),
        'cdc_cert_pem_set' => !empty($_SESSION['cdc_cert_pem']),
        'cdc_key_pem_len'  => isset($_SESSION['cdc_key_pem']) ? strlen($_SESSION['cdc_key_pem']) : 0,
        'session_id'       => session_id(),
        'session_name'     => session_name(),
    ],
    'expected_files'  => [
        'cdc_private.key' => [
            'exists'   => false,
            'size'     => 0,
            'readable' => false,
        ],
        'cdc_certificate.pem' => [
            'exists'   => false,
            'size'     => 0,
            'readable' => false,
        ],
    ],
];

if (is_dir($dir)) {
    $ls = @scandir($dir);
    if ($ls) {
        foreach ($ls as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            $out['files'][] = [
                'name'    => $f,
                'size'    => @filesize($p),
                'mtime'   => date('c', @filemtime($p)),
                'readable'=> is_readable($p),
            ];
        }
    }
    foreach (['cdc_private.key', 'cdc_certificate.pem'] as $key) {
        $p = $dir . '/' . $key;
        $out['expected_files'][$key] = [
            'exists'   => file_exists($p),
            'size'     => file_exists($p) ? filesize($p) : 0,
            'readable' => is_readable($p),
        ];
    }
}

$out['php_user'] = get_current_user();
$out['php_script_user'] = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? 'n/a')
    : 'n/a';

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
