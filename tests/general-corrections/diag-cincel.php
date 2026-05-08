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

// ── 3. Cincel auth test — barre TODAS las combinaciones URL × endpoint ──
//      hasta encontrar la que devuelva 200 + token. La primera que funcione
//      es la que debe usarse en cincel-firma-acta.php.
echo '<h2>3. Test exhaustivo de autenticación Cincel</h2>';
if (!defined('CINCEL_API_URL') || !defined('CINCEL_EMAIL') || !defined('CINCEL_PASSWORD')) {
    echo '<p style="color:#dc2626;">❌ Configuración incompleta — saltando.</p>';
} else {
    // Probamos varios prefijos comunes de Cincel + ambos endpoints conocidos.
    // El "ganador" es el primer (URL,endpoint) que devuelve 200 + token.
    $base = rtrim(CINCEL_API_URL, '/');
    // Strip cualquier sufijo /vN para poder probar todas las variantes.
    $rootBase = preg_replace('#/v\d+/?$#', '', $base);
    $bases = array_unique([
        $base,                         // configurado actualmente
        $rootBase,                     // sin /vN
        $rootBase . '/v1',
        $rootBase . '/v2',
        $rootBase . '/v3',
    ]);
    $endpoints = ['/auth/tokens', '/auth/login', '/sessions', '/oauth/token'];

    echo '<p style="font-size:13px;color:#64748b;">Probando '. count($bases) .' bases × '. count($endpoints) .' endpoints = '. (count($bases)*count($endpoints)) .' combinaciones. Tarda ~10 segundos.</p>';

    $winner = null;
    echo '<table><tr><th style="padding:6px 10px;background:#f1f5f9;text-align:left;">URL completa</th><th style="padding:6px 10px;background:#f1f5f9;">HTTP</th><th style="padding:6px 10px;background:#f1f5f9;">Token?</th><th style="padding:6px 10px;background:#f1f5f9;text-align:left;">Response preview</th></tr>';
    foreach ($bases as $b) {
        foreach ($endpoints as $path) {
            $url = $b . $path;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode(['email' => CINCEL_EMAIL, 'password' => CINCEL_PASSWORD]),
                CURLOPT_TIMEOUT => 6,
            ]);
            $raw  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            $resp = json_decode($raw, true);
            $token = is_array($resp) ? ($resp['access_token'] ?? $resp['token'] ?? null) : null;

            $color = $token ? '#16a34a' : ($code === 200 ? '#92400e' : '#dc2626');
            echo '<tr>'
               . '<td style="padding:5px 10px;border:1px solid #e2e8f0;font-family:monospace;font-size:12px;">' . htmlspecialchars($url) . '</td>'
               . '<td style="padding:5px 10px;border:1px solid #e2e8f0;color:'.$color.';font-weight:700;">' . htmlspecialchars((string)$code) . '</td>'
               . '<td style="padding:5px 10px;border:1px solid #e2e8f0;">' . ($token ? '✅' : ($err ? '⚠ ' . htmlspecialchars($err) : '—')) . '</td>'
               . '<td style="padding:5px 10px;border:1px solid #e2e8f0;font-size:11px;color:#64748b;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . htmlspecialchars(substr((string)$raw, 0, 120)) . '</td>'
               . '</tr>';

            if ($token && !$winner) $winner = ['url' => $url, 'base' => $b, 'path' => $path];
        }
    }
    echo '</table>';

    if ($winner) {
        echo '<div style="background:#ecfdf5;border:2px solid #10b981;padding:14px;border-radius:8px;margin-top:14px;">';
        echo '<p style="font-size:16px;font-weight:700;color:#065f46;margin:0;">✅ Endpoint funcional encontrado:</p>';
        echo '<p style="font-family:monospace;font-size:14px;margin:6px 0;"><strong>URL:</strong> ' . htmlspecialchars($winner['url']) . '</p>';
        echo '<p style="font-family:monospace;font-size:14px;margin:6px 0;"><strong>Base:</strong> ' . htmlspecialchars($winner['base']) . '</p>';
        echo '<p style="font-family:monospace;font-size:14px;margin:6px 0;"><strong>Path:</strong> ' . htmlspecialchars($winner['path']) . '</p>';
        echo '<p style="margin-top:10px;color:#065f46;">→ Actualiza <code>configurador/php/config.php</code> con <code>CINCEL_API_URL = "' . htmlspecialchars($winner['base']) . '"</code> si difiere del actual, y asegúrate de que <code>cincel-firma-acta.php</code> intente este path.</p>';
        echo '</div>';
    } else {
        echo '<div style="background:#fef2f2;border:2px solid #dc2626;padding:14px;border-radius:8px;margin-top:14px;">';
        echo '<p style="font-size:16px;font-weight:700;color:#991b1b;margin:0;">❌ Ninguna combinación devolvió token.</p>';
        echo '<p style="margin-top:6px;">Causas posibles:</p>';
        echo '<ul style="margin:6px 0;">';
        echo '<li>Credenciales <code>' . htmlspecialchars(CINCEL_EMAIL) . '</code> ya no son válidas (rotadas o expiradas).</li>';
        echo '<li>El servidor no tiene acceso outbound a <code>api.cincel.digital</code> (firewall del hosting).</li>';
        echo '<li>Cincel cambió su URL base. Consultar dashboard Cincel para nueva API URL.</li>';
        echo '</ul></div>';
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
