<?php
/**
 * ONE-SHOT TOOL — Reenviar contrato corregido por correo a cada cliente
 * cuya transacción tenga contrato_regenerado_admin=1.
 *
 * Per customer brief (Óscar, 2026-05-26): el correo debe verse igual al
 * que el cliente recibió originalmente al firmar — sin texto que admita
 * que es una corrección. Solo el contrato adjunto cambia (la versión
 * regenerada con los datos correctos).
 *
 * Uso:
 *   1. Visita /admin/php/inventario/reenviar-contrato-correo-once.php
 *   2. Sección A lista los candidatos (transacciones con contrato_regenerado_admin=1
 *      que aún no han sido reenviadas).
 *   3. Click "Reenviar" por fila → email enviado vía PHPMailer/SMTP.
 *   4. Después del envío, la transacción se marca contrato_correo_reenviado_admin=1
 *      para que no se duplique accidentalmente.
 *
 * Idempotente: re-correr no envía duplicados (la fila ya marcada se filtra).
 * Para forzar reenvío de una fila específica, click "Forzar reenvío".
 *
 * Auth: admin session.
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

// Ensure tracking column exists (idempotent)
$pdo = getDB();
foreach ([
    'contrato_correo_reenviado_admin'       => "TINYINT(1) NULL DEFAULT 0",
    'contrato_correo_reenviado_admin_fecha' => "DATETIME NULL",
    'contrato_correo_reenviado_admin_to'    => "VARCHAR(200) NULL",
] as $col => $def) {
    try { $pdo->exec("ALTER TABLE transacciones ADD COLUMN $col $def"); } catch (Throwable $e) {}
}

$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'list');
$txId   = (int)($_POST['tx_id'] ?? $_GET['tx_id'] ?? 0);
$force  = !empty($_POST['force']) || !empty($_GET['force']);

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Reenviar contrato corregido</title>';
echo '<style>
body{font-family:system-ui,-apple-system,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1100px;margin:0 auto;line-height:1.5;}
h1{font-size:22px;margin:0 0 4px;}
h2{font-size:16px;color:#475569;margin:24px 0 8px;border-bottom:1px solid #cbd5e1;padding-bottom:4px;}
.sec{background:#fff;padding:14px 16px;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:14px;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th{background:#f1f5f9;text-align:left;padding:8px 10px;font-size:12px;}
td{padding:8px 10px;border-top:1px solid #f1f5f9;vertical-align:top;}
.btn{padding:7px 14px;background:#039fe1;color:#fff;border:0;border-radius:5px;cursor:pointer;font-size:12.5px;font-weight:600;text-decoration:none;display:inline-block;}
.btn.ghost{background:#fff;color:#0c2340;border:1px solid #cbd5e1;}
.btn.danger{background:#dc2626;}
.ok{color:#15803d;font-weight:700;}
.warn{color:#a16207;font-weight:700;}
.err{color:#b91c1c;font-weight:700;}
.success{background:#d1fae5;border:1px solid #34d399;color:#065f46;padding:14px 18px;border-radius:10px;margin:14px 0;}
.hint{background:#fef9c3;border:1px solid #facc15;padding:10px 14px;border-radius:8px;margin:10px 0;font-size:13px;}
code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:11.5px;}
.crumb a{color:#0369a1;text-decoration:none;font-size:12px;}
</style></head><body>';
echo '<h1>📧 Reenviar contrato corregido por correo</h1>';
echo '<p style="color:#64748b;font-size:13px;margin-top:0;">Para clientes cuya transacción fue regenerada (contrato_regenerado_admin=1). El correo replica el template de contrato de crédito normal — sin mencionar la corrección.</p>';
echo '<p class="crumb"><a href="?">← Lista de candidatos</a></p>';

// ──────────────────────────────────────────────────────────────────────────
// ACTION: send
// ──────────────────────────────────────────────────────────────────────────
if ($action === 'send' && $txId > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $st = $pdo->prepare("SELECT * FROM transacciones WHERE id = ? LIMIT 1");
    $st->execute([$txId]);
    $tx = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$tx) {
        echo '<div class="err">Transacción no encontrada (id=' . $txId . ')</div></body></html>'; exit;
    }

    if ((int)($tx['contrato_correo_reenviado_admin'] ?? 0) === 1 && !$force) {
        echo '<div class="hint">Ya enviado el ' . htmlspecialchars((string)$tx['contrato_correo_reenviado_admin_fecha']) . ' a <code>' . htmlspecialchars((string)$tx['contrato_correo_reenviado_admin_to']) . '</code>. Para forzar reenvío usa el botón "Forzar reenvío".</div>';
        echo '<p><a class="btn ghost" href="?">← Volver a la lista</a></p></body></html>'; exit;
    }

    $email = trim((string)($tx['email'] ?? ''));
    if ($email === '') {
        echo '<div class="err">La transacción no tiene email registrado. No se puede enviar.</div></body></html>'; exit;
    }

    // Resolve PDF path — prefer absolute, fall back to known relative locations
    $pdfPath = (string)($tx['contrato_pdf_path'] ?? '');
    $resolvedPdf = '';
    if ($pdfPath !== '') {
        if ($pdfPath[0] === '/') {
            $resolvedPdf = is_file($pdfPath) ? $pdfPath : '';
        } else {
            $base = basename($pdfPath);
            foreach ([
                __DIR__ . '/../../../configurador/' . ltrim($pdfPath, '/'),
                __DIR__ . '/../../../configurador/php/' . ltrim($pdfPath, '/'),
                sys_get_temp_dir() . '/voltika_contratos/' . $base,
                sys_get_temp_dir() . '/voltika_contratos_contado/' . $base,
            ] as $candidate) {
                if (is_file($candidate)) { $resolvedPdf = $candidate; break; }
            }
        }
    }
    if ($resolvedPdf === '') {
        echo '<div class="err">PDF del contrato no encontrado en disco. <code>' . htmlspecialchars($pdfPath) . '</code></div></body></html>'; exit;
    }

    // Load PHPMailer + SMTP config (same source as contratoContadoSendEmail)
    require_once __DIR__ . '/../../../configurador/php/config.php';
    $autoload = __DIR__ . '/../../../configurador/php/vendor/autoload.php';
    if (file_exists($autoload)) require_once $autoload;
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        echo '<div class="err">PHPMailer no está instalado. Revisa <code>configurador/php/vendor/</code>.</div></body></html>'; exit;
    }
    if (!defined('SMTP_HOST') || !defined('SMTP_USER') || !defined('SMTP_PASS')) {
        echo '<div class="err">Credenciales SMTP no configuradas (SMTP_HOST / SMTP_USER / SMTP_PASS).</div></body></html>'; exit;
    }

    // ── Compose email — replicates the credit contract template that
    // customers would normally receive. No mention of correction.
    $nombre = trim((string)($tx['nombre'] ?? ''));
    $pedidoDisplay = $tx['pedido'] ?: ('TX' . (int)$tx['id']);
    $modelo = (string)($tx['modelo'] ?? 'tu motocicleta Voltika');
    $color  = (string)($tx['color']  ?? '');

    // Derive enganche / pagoSemanal / plazo from forensic motivo (we stored it during regen)
    $motivo = (string)($tx['contrato_regenerado_admin_motivo'] ?? '');
    $enganche = 0; $pagoSemanal = 0; $plazoMeses = 36;
    if (preg_match('/enganche=(\d+)/', $motivo, $m)) $enganche = (int)$m[1];
    if (preg_match('/pagoSemanal=(\d+)/', $motivo, $m)) $pagoSemanal = (int)$m[1];
    if (preg_match('/plazo=(\d+)/', $motivo, $m)) $plazoMeses = (int)$m[1];
    if ($enganche === 0) $enganche = (int)round((float)($tx['total'] ?? 0));
    $numPagos = (int)round($plazoMeses * 4.33);

    $subject = 'Tu contrato Voltika · ' . $pedidoDisplay;

    $body = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#0c2340;line-height:1.6;max-width:600px;margin:0 auto;padding:24px;">'
          . '<div style="text-align:center;padding:14px 0;border-bottom:3px solid #039fe1;margin-bottom:24px;">'
          .   '<div style="font-size:26px;font-weight:800;color:#0c2340;letter-spacing:1px;">VOLTIKA</div>'
          .   '<div style="font-size:11px;color:#64748b;margin-top:2px;">MTECH GEARS, S.A. de C.V.</div>'
          . '</div>'
          . '<h2 style="color:#0c2340;font-size:18px;margin:0 0 12px;">Hola ' . htmlspecialchars($nombre) . ',</h2>'
          . '<p>Te enviamos una copia digital de tu <strong>Contrato Voltika</strong> para tu expediente.</p>'
          . '<div style="background:#f0f9ff;border-left:4px solid #039fe1;padding:14px 16px;margin:18px 0;font-size:14px;">'
          .   '<strong style="color:#0c2340;">Detalles de tu operación:</strong><br>'
          .   '<div style="margin-top:6px;">'
          .     '<div>📋 <strong>Pedido:</strong> ' . htmlspecialchars($pedidoDisplay) . '</div>'
          .     '<div>🏍️ <strong>Modelo:</strong> ' . htmlspecialchars($modelo) . ($color ? ' · ' . htmlspecialchars($color) : '') . '</div>'
          .     '<div>💳 <strong>Modalidad:</strong> Compraventa a plazos (crédito)</div>'
          .     '<div>💰 <strong>Enganche:</strong> $' . number_format($enganche, 0, '.', ',') . ' MXN</div>'
          .     ($pagoSemanal > 0 ? '<div>📅 <strong>Pago semanal:</strong> $' . number_format($pagoSemanal, 0, '.', ',') . ' MXN durante ' . $numPagos . ' semanas (' . $plazoMeses . ' meses)</div>' : '')
          .   '</div>'
          . '</div>'
          . '<p>Tu contrato cuenta con <strong>firma electrónica conforme al Artículo 89 del Código de Comercio</strong> y sello digital de tiempo <strong>NOM-151</strong> a través de Cincel, con plena validez legal.</p>'
          . '<p style="margin:18px 0 6px;">Si tienes cualquier duda sobre tu operación:</p>'
          . '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;font-size:13px;">'
          .   '📱 WhatsApp: <a href="https://wa.me/5215513416370" style="color:#039fe1;">+52 55 1341 6370</a><br>'
          .   '📧 Correo: <a href="mailto:contacto@voltika.mx" style="color:#039fe1;">contacto@voltika.mx</a><br>'
          .   '🌐 Portal: <a href="https://voltika.mx/clientes/" style="color:#039fe1;">voltika.mx/clientes</a>'
          . '</div>'
          . '<p style="margin-top:22px;">¡Bienvenido a la familia Voltika!</p>'
          . '<p style="margin:6px 0 0;color:#64748b;font-size:13px;">— Equipo Voltika</p>'
          . '<hr style="border:0;border-top:1px solid #e2e8f0;margin:24px 0 12px;">'
          . '<p style="font-size:11px;color:#94a3b8;">MTECH GEARS, S.A. de C.V. — Jaime Balmes 71 Int 101, Polanco, Ciudad de México, CDMX 11510.<br>'
          .   'Este es un mensaje automático del sistema Voltika. Conserva esta copia digital para tus registros.</p>'
          . '</body></html>';

    $sent = false;
    $errMsg = '';
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->SMTPAuth   = true;
        $mail->Host       = SMTP_HOST;
        $mail->Port       = defined('SMTP_PORT') ? SMTP_PORT : 465;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->setFrom(SMTP_USER, 'Voltika México');
        $mail->addAddress($email, $nombre);
        $mail->addBCC('legal@voltika.mx');
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->isHTML(true);
        $mail->Subject  = $subject;
        $mail->Body     = $body;
        $mail->AltBody  = strip_tags($body);
        $mail->addAttachment($resolvedPdf, 'Contrato_Voltika_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$pedidoDisplay) . '.pdf');
        $mail->send();
        $sent = true;
    } catch (Throwable $e) {
        $errMsg = $e->getMessage();
        error_log('reenviar-contrato-correo PHPMailer error: ' . $errMsg);
    }

    if ($sent) {
        // Mark as sent
        try {
            $pdo->prepare("UPDATE transacciones
                SET contrato_correo_reenviado_admin = 1,
                    contrato_correo_reenviado_admin_fecha = NOW(),
                    contrato_correo_reenviado_admin_to = ?
                WHERE id = ?")->execute([$email, (int)$tx['id']]);
        } catch (Throwable $e) { error_log('reenviar mark sent: ' . $e->getMessage()); }

        if (function_exists('adminLog')) {
            adminLog('contrato_correo_reenviado_admin', [
                'tx_id'   => (int)$tx['id'],
                'pedido'  => $pedidoDisplay,
                'to'      => $email,
                'pdf'     => basename($resolvedPdf),
                'forced'  => $force ? 1 : 0,
            ]);
        }

        echo '<div class="success">';
        echo '<h2 style="margin-top:0;color:#065f46;border:0;">✅ Correo enviado</h2>';
        echo '<ul>';
        echo '<li>Para: <strong>' . htmlspecialchars($email) . '</strong></li>';
        echo '<li>Cliente: <strong>' . htmlspecialchars($nombre) . '</strong></li>';
        echo '<li>Pedido: <strong>' . htmlspecialchars($pedidoDisplay) . '</strong></li>';
        echo '<li>PDF: <code>' . htmlspecialchars(basename($resolvedPdf)) . '</code></li>';
        echo '<li>BCC: legal@voltika.mx</li>';
        echo '</ul>';
        echo '</div>';
        echo '<p><a class="btn ghost" href="?">← Volver a la lista</a></p>';
    } else {
        echo '<div class="err">Falló el envío del correo: ' . htmlspecialchars($errMsg ?: 'error desconocido') . '</div>';
        echo '<p><a class="btn ghost" href="?">← Volver a la lista</a></p>';
    }

    echo '</body></html>';
    exit;
}

// ──────────────────────────────────────────────────────────────────────────
// DEFAULT: list candidates
// ──────────────────────────────────────────────────────────────────────────
echo '<div class="sec"><h2>Candidatos — transacciones regeneradas pendientes de reenvío</h2>';
try {
    $rows = $pdo->query("
        SELECT id, pedido, nombre, email, modelo, color, total, tpago,
               contrato_pdf_path, contrato_regenerado_admin,
               contrato_regenerado_admin_fecha,
               contrato_correo_reenviado_admin,
               contrato_correo_reenviado_admin_fecha,
               contrato_correo_reenviado_admin_to
          FROM transacciones
         WHERE contrato_regenerado_admin = 1
         ORDER BY id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rows = [];
    echo '<div class="err">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

if (!$rows) {
    echo '<div class="hint">Sin candidatos. Solo aparecen aquí transacciones con <code>contrato_regenerado_admin=1</code>.</div>';
} else {
    echo '<table><thead><tr><th>Pedido</th><th>Cliente</th><th>Modelo</th><th>Total</th><th>Regenerado</th><th>Correo reenviado</th><th>Acción</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $alreadySent = (int)($r['contrato_correo_reenviado_admin'] ?? 0) === 1;
        echo '<tr>';
        echo '<td><code>' . htmlspecialchars((string)($r['pedido'] ?: ('#' . (int)$r['id']))) . '</code></td>';
        echo '<td>' . htmlspecialchars((string)$r['nombre']) . '<br><small style="color:#64748b;">' . htmlspecialchars((string)$r['email']) . '</small></td>';
        echo '<td>' . htmlspecialchars((string)$r['modelo']) . ($r['color'] ? ' / ' . htmlspecialchars((string)$r['color']) : '') . '</td>';
        echo '<td>$' . number_format((float)$r['total'], 0, '.', ',') . '</td>';
        echo '<td>' . htmlspecialchars((string)$r['contrato_regenerado_admin_fecha']) . '</td>';
        echo '<td>' . ($alreadySent
            ? '<span class="ok">SÍ</span><br><small style="color:#64748b;">' . htmlspecialchars((string)$r['contrato_correo_reenviado_admin_fecha']) . '<br>→ ' . htmlspecialchars((string)$r['contrato_correo_reenviado_admin_to']) . '</small>'
            : '<span class="warn">no</span>') . '</td>';
        echo '<td>';
        echo '<form method="post" style="display:inline;">';
        echo '<input type="hidden" name="action" value="send">';
        echo '<input type="hidden" name="tx_id" value="' . (int)$r['id'] . '">';
        if ($alreadySent) {
            echo '<input type="hidden" name="force" value="1">';
            echo '<button class="btn danger" type="submit" onclick="return confirm(\'¿Forzar reenvío? El cliente ya recibió el correo el ' . htmlspecialchars((string)$r['contrato_correo_reenviado_admin_fecha']) . '.\')">🔄 Forzar reenvío</button>';
        } else {
            echo '<button class="btn" type="submit" onclick="return confirm(\'¿Enviar correo con contrato corregido a ' . htmlspecialchars((string)$r['email']) . '?\')">📧 Reenviar</button>';
        }
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
echo '</div>';

echo '<div class="hint" style="margin-top:20px;">'
   . '⚠ <strong>Nota legal/operativa:</strong> el correo enviado <strong>NO menciona</strong> que es una corrección. Replica el template de contrato de crédito normal. El flag forense <code>contrato_regenerado_admin=1</code> + el log de adminLog mantienen el rastro de auditoría interno.'
   . '</div>';

echo '</body></html>';
