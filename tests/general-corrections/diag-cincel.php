<?php
/**
 * Diagnóstico — Verificación rápida de la configuración Cincel + FPDF
 * para Bug 5.7. Si "Iniciar firma con Cincel" falla en el portal cliente,
 * abrir esta URL para identificar la causa rápidamente.
 *
 * URL: /tests/general-corrections/diag-cincel.php
 */
require_once __DIR__ . '/../../configurador/php/config.php';

header('Content-Type: text/html; charset=utf-8');

function row(string $label, $val, ?string $hint = null): string {
    $ok = $val === true || (is_string($val) && $val !== '' && $val !== 'NO');
    $color = $ok ? '#16a34a' : '#dc2626';
    $valStr = is_bool($val) ? ($val ? '✓' : '✗') : htmlspecialchars((string)$val);
    return "<tr><td style=\"padding:6px 12px;border:1px solid #e2e8f0;\">$label</td>"
         . "<td style=\"padding:6px 12px;border:1px solid #e2e8f0;color:$color;font-family:monospace;\">$valStr</td>"
         . "<td style=\"padding:6px 12px;border:1px solid #e2e8f0;color:#64748b;font-size:12px;\">" . htmlspecialchars($hint ?? '') . "</td></tr>";
}

echo '<style>body{font-family:system-ui;max-width:920px;margin:24px auto;padding:0 16px;} table{border-collapse:collapse;width:100%;} h2{color:#039fe1;margin-top:24px;}</style>';
echo '<h1>🔍 Diagnóstico Cincel + FPDF</h1>';

// ── 1. Constants ────────────────────────────────────────────────────────
echo '<h2>1. Configuración Cincel</h2><table>';
echo '<tr><th style="padding:6px 12px;background:#f1f5f9;text-align:left;">Item</th><th style="padding:6px 12px;background:#f1f5f9;text-align:left;">Valor</th><th style="padding:6px 12px;background:#f1f5f9;text-align:left;">Comentario</th></tr>';
echo row('CINCEL_API_URL',  defined('CINCEL_API_URL')  ? CINCEL_API_URL  : 'NO',
        defined('CINCEL_API_URL') ? '' : 'Define en configurador/php/config.php');
echo row('CINCEL_EMAIL',    defined('CINCEL_EMAIL')    ? CINCEL_EMAIL    : 'NO');
echo row('CINCEL_PASSWORD', defined('CINCEL_PASSWORD') ? '••••' . substr(CINCEL_PASSWORD, -2) : 'NO');
echo '</table>';

// ── 2. FPDF availability ────────────────────────────────────────────────
echo '<h2>2. FPDF</h2><table>';
$fpdfPaths = [
    __DIR__ . '/../../admin/php/lib/fpdf.php',
    __DIR__ . '/../../admin_test/php/lib/fpdf.php',
    __DIR__ . '/../../configurador/php/vendor/fpdf/fpdf.php',
    __DIR__ . '/../../configurador/php/vendor/setasign/fpdf/fpdf.php',
];
$fpdfFound = null;
foreach ($fpdfPaths as $p) {
    $exists = file_exists($p);
    echo row($p, $exists ? 'OK' : 'NO');
    if ($exists && !$fpdfFound) $fpdfFound = $p;
}
echo '</table>';
if (!$fpdfFound) {
    echo '<p style="color:#dc2626;">❌ FPDF no encontrado en ninguna ruta — descargar e instalar antes de probar Bug 5.7.</p>';
}

// ── 3. Cincel auth test ─────────────────────────────────────────────────
echo '<h2>3. Test de autenticación Cincel — ambos endpoints</h2>';
if (!defined('CINCEL_API_URL') || !defined('CINCEL_EMAIL') || !defined('CINCEL_PASSWORD')) {
    echo '<p style="color:#dc2626;">❌ Configuración incompleta — saltando.</p>';
} else {
    $base = rtrim(CINCEL_API_URL, '/');
    $endpoints = ['/auth/tokens', '/auth/login'];
    $winner = null;
    foreach ($endpoints as $path) {
        $url = $base . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['email' => CINCEL_EMAIL, 'password' => CINCEL_PASSWORD]),
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $resp = json_decode($raw, true);
        $token = is_array($resp) ? ($resp['access_token'] ?? $resp['token'] ?? null) : null;

        echo '<h4 style="margin-top:18px;">' . htmlspecialchars($path) . '</h4>';
        echo '<table>';
        echo row('URL',          $url);
        echo row('HTTP code',    (string)$code);
        echo row('curl error',   $err ?: 'ninguno');
        echo row('Token (access_token o token)',
                 $token ? 'SÍ (' . substr((string)$token, 0, 16) . '…)' : 'NO',
                 !$token && $raw ? 'response: ' . substr($raw, 0, 200) : '');
        echo '</table>';

        if ($token && !$winner) $winner = $path;
    }

    if ($winner) {
        echo '<p style="color:#16a34a;font-size:15px;font-weight:700;">✅ Endpoint funcional: <code>' . $winner . '</code></p>';
        echo '<p>El código de cincel-firma-acta.php ya intenta /auth/tokens primero — si la versión más reciente está subida al servidor, el portal cliente debería funcionar.</p>';
    } else {
        echo '<p style="color:#dc2626;">❌ Ningún endpoint devolvió token — credenciales rechazadas o API URL incorrecta.</p>';
    }
}

// ── 4. Test PDF generation (sin tocar la DB) ────────────────────────────
echo '<h2>4. Generador de ACTA PDF (lectura)</h2>';
$actaPdfPath = __DIR__ . '/../../clientes/php/entrega/acta-pdf.php';
echo '<table>';
echo row('Archivo existe', file_exists($actaPdfPath) ? 'SÍ' : 'NO', $actaPdfPath);
if (file_exists($actaPdfPath)) {
    echo row('Tamaño (bytes)', (string)filesize($actaPdfPath));
    $err = null;
    @set_error_handler(function($s,$m) use (&$err) { $err = $m; });
    $tokens = @token_get_all(file_get_contents($actaPdfPath));
    @restore_error_handler();
    echo row('Sintaxis PHP', $err ? 'ERROR: ' . $err : 'OK');
}
echo '</table>';

echo '<h2>5. Próximos pasos</h2>';
echo '<ul>';
echo '<li>Si todo está ✅, intentar nuevamente "Iniciar firma con Cincel" en el portal cliente.</li>';
echo '<li>Si falla por <strong>FPDF</strong>: descargar fpdf.php oficial y subir a admin/php/lib/.</li>';
echo '<li>Si falla por <strong>Cincel auth</strong>: revisar CINCEL_EMAIL / CINCEL_PASSWORD en config.php.</li>';
echo '<li>Si falla por <strong>cincel-firma-acta.php internal cURL</strong>: verificar que <code>https://voltika.mx/clientes/php/entrega/acta-pdf.php</code> sea alcanzable desde el mismo host.</li>';
echo '</ul>';
