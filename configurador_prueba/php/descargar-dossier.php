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

// Admin session uses a custom session_name (VOLTIKA_ADMIN). Adopt it
// BEFORE session_start so admin logins from the admin panel are
// recognized when downloading from configurador_prueba/.
if (session_status() === PHP_SESSION_NONE) {
    @session_name('VOLTIKA_ADMIN');
    @session_start();
}
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
$buildErr = null;
if (!$dossier && $adminOk && $motoId > 0) {
    $r = dossierBuild($motoId, ['motivo' => 'manual']);
    if ($r['ok']) {
        $st = $pdo->prepare("SELECT * FROM dossiers_defensa WHERE id = ?");
        $st->execute([$r['dossier_id']]);
        $dossier = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } else {
        $buildErr = $r['error'] ?? 'unknown';
    }
}

if (!$dossier) {
    http_response_code(404);
    if ($adminOk) {
        // For admins, render a one-page HTML guidance instead of a bare
        // text 404 — explains exactly what's missing and what to do next.
        header('Content-Type: text/html; charset=UTF-8');

        // Determine the most useful "reason" — server config issues take
        // priority over per-order issues, since the latter resolves
        // automatically once CEDIS assigns inventory.
        $hasFpdf = class_exists('FPDF');
        $dossiersDir = __DIR__ . '/../dossiers';
        $dirOk = is_dir($dossiersDir) && is_writable($dossiersDir);
        if (!$hasFpdf) {
            $reason = 'Falta la librería FPDF en el servidor — se necesita para generar PDFs. Verifica la instalación (ver bloque "ARREGLO" abajo).';
        } elseif (!$dirOk) {
            $reason = 'El directorio dossiers/ no existe o no tiene permisos de escritura. Crear con los comandos del bloque "ARREGLO" abajo.';
        } else {
            $reason = $buildErr ?: 'Esta orden aún no tiene moto asignada (VIN). El dossier se construye automáticamente cuando CEDIS asigna inventario.';
        }

        // FPDF tried-paths (set by dossier-defensa.php loader).
        $fpdfTried = $GLOBALS['_dossier_fpdf_tried'] ?? [];
        $fpdfDetail = '';
        if (!$hasFpdf && $fpdfTried) {
            $fpdfDetail = ' · paths: ' . implode(', ', array_map(
                fn($p, $exists) => basename(dirname($p)) . '/' . basename($p) . '=' . ($exists ? '✓' : '×'),
                array_keys($fpdfTried), array_values($fpdfTried)
            ));
        }
        $usingTemp = !empty($GLOBALS['_dossier_using_temp_dir']) ? ' (FALLBACK→ /tmp)' : '';

        $diag = [
            'admin'             => $adminOk ? 1 : 0,
            'moto_id'           => $motoId,
            'pedido'            => $pedido,
            'build_intentado'   => $motoId > 0 ? 'sí' : 'no — sin moto_id',
            'build_error'       => $buildErr,
            'php_zip'           => class_exists('ZipArchive') ? 'ok' : '⚠️ MISSING (apt install php-zip)',
            'php_fpdf'          => $hasFpdf ? 'ok' : '⚠️ MISSING' . $fpdfDetail,
            'dossiers_dir'      => $dirOk
                ? 'ok' . $usingTemp
                : '⚠️ NO ESCRIBIBLE — ' . $dossiersDir,
        ];
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8">';
        echo '<title>Dossier no disponible</title><style>';
        echo 'body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f8fafc;color:#1a3a5c;padding:32px;max-width:680px;margin:0 auto;}';
        echo 'h1{font-size:20px;margin:0 0 6px;} h2{font-size:14px;color:#64748b;margin:0 0 24px;}';
        echo '.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px;margin-bottom:16px;}';
        echo '.tag{display:inline-block;background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:600;}';
        echo 'table{width:100%;border-collapse:collapse;font-size:13px;}';
        echo 'td{padding:6px 10px;border-bottom:1px solid #f1f5f9;}';
        echo 'td:first-child{color:#64748b;width:40%;}';
        echo '.next{background:#eff6ff;border-left:3px solid #039fe1;padding:14px 16px;border-radius:6px;margin-top:14px;font-size:13px;}';
        echo '.next b{color:#1a3a5c;}';
        echo 'code{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:12px;}';
        echo '</style></head><body>';
        echo '<h1>📦 Dossier de Defensa no disponible</h1>';
        echo '<h2>Pedido: ' . htmlspecialchars($pedido ?: '—') . ' · Moto ID: ' . (int)$motoId . '</h2>';
        echo '<div class="card"><div class="tag">RAZÓN</div>';
        echo '<p style="margin:10px 0 0;font-size:14px;">' . htmlspecialchars($reason) . '</p></div>';
        echo '<div class="card"><div class="tag">DIAGNÓSTICO</div><table>';
        foreach ($diag as $k => $v) {
            echo '<tr><td>' . htmlspecialchars($k) . '</td><td>' . htmlspecialchars((string)($v ?? '—')) . '</td></tr>';
        }
        echo '</table></div>';
        // Server-fix block — only render commands for the issues we
        // actually detected so the admin doesn't run unnecessary fixes.
        $needsServerFix = !$hasFpdf || !$dirOk;
        if ($needsServerFix) {
            echo '<div class="card" style="background:#fef2f2;border-color:#fecaca;">';
            echo '<div class="tag" style="background:#fecaca;color:#991b1b;">ARREGLO REQUERIDO EN EL SERVIDOR</div>';
            echo '<p style="margin:10px 0 6px;font-size:13px;">Ejecuta estos comandos por SSH como root o con sudo:</p>';
            echo '<pre style="background:#1e293b;color:#e2e8f0;padding:14px;border-radius:6px;font-size:12px;overflow-x:auto;line-height:1.6;">';
            if (!$dirOk) {
                echo "# Crear el directorio de dossiers + permisos de escritura\n";
                echo "mkdir -p /var/www/voltika.mx/configurador_prueba/dossiers\n";
                echo "chmod 0775 /var/www/voltika.mx/configurador_prueba/dossiers\n";
                echo "chown www-data:www-data /var/www/voltika.mx/configurador_prueba/dossiers\n";
                if (strpos($_SERVER['HTTP_HOST'] ?? '', 'test') !== false || strpos($_SERVER['REQUEST_URI'] ?? '', '_test') !== false) {
                    echo "# (también para test)\n";
                    echo "mkdir -p /var/www/voltika.mx/configurador_prueba_test/dossiers\n";
                    echo "chmod 0775 /var/www/voltika.mx/configurador_prueba_test/dossiers\n";
                    echo "chown www-data:www-data /var/www/voltika.mx/configurador_prueba_test/dossiers\n";
                }
                echo "\n";
            }
            if (!$hasFpdf) {
                echo "# Verificar FPDF (debería existir si ya genera contratos PDF)\n";
                echo "ls -la /var/www/voltika.mx/configurador_prueba/php/vendor/fpdf/fpdf.php\n";
                echo "\n";
                echo "# Si no existe, descargar e instalar:\n";
                echo "cd /var/www/voltika.mx/configurador_prueba/php/vendor/\n";
                echo "wget -O fpdf.zip http://www.fpdf.org/en/download/fpdf186.zip\n";
                echo "unzip fpdf.zip -d fpdf/\n";
                echo "mv fpdf/fpdf186/* fpdf/ && rmdir fpdf/fpdf186/\n";
                echo "chown -R www-data:www-data fpdf/\n";
            }
            echo '</pre></div>';
        }

        echo '<div class="next"><b>¿Qué hacer?</b><br>';
        if ($needsServerFix) {
            echo '0. <b>Primero ejecuta los comandos del bloque rojo arriba</b> (arregla la infraestructura).<br>';
        }
        echo ($needsServerFix ? '1' : '1') . '. Asigna una moto del inventario (botón <b>Asignar</b> en la lista de ventas).<br>';
        echo '2. Una vez asignada, el cron <code>auto-dossier.php</code> generará el dossier dentro de 1 hora.<br>';
        echo '3. Para forzar inmediatamente: vuelve a hacer clic en 📦 después de asignar la moto, o llama con <code>&build=1</code>.<br>';
        echo '4. Si el chargeback es URGENTE y la moto aún no está asignada, descarga el contrato 📄 (suficiente para evidencia básica) mientras CEDIS asigna inventario.';
        echo '</div></body></html>';
    } else {
        echo 'Dossier no disponible';
    }
    exit;
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
