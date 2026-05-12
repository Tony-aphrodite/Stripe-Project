<?php
/**
 * Voltika — Serve Contrato de Compraventa al Contado PDF.
 *
 * Two authorization paths:
 *   1. Customer link  : ?pedido=XXX&token=YY  (HMAC over pedido + stripe_pi)
 *   2. Admin override : valid admin session (used by the admin panel)
 *
 * The admin override exists so support staff can re-issue / inspect a
 * contract without the customer having to find the original email link.
 */

require_once __DIR__ . '/contrato-contado.php';

$pedido = isset($_GET['pedido']) ? trim($_GET['pedido']) : '';
$token  = isset($_GET['token'])  ? trim($_GET['token'])  : '';

if ($pedido === '') {
    http_response_code(400);
    echo 'pedido requerido';
    exit;
}

// Look up the order to retrieve the stripe_pi we hashed against. If we
// can't find the row we 404 instead of leaking existence information by
// distinguishing "no row" from "wrong token".
//
// Customer brief 2026-05-09 (Óscar — Documentos modal of VK-2605-0002
// hid the Contrato row because `pedido` was empty even though
// pedido_corto was set): accept three identifier shapes so the admin
// link always resolves to the right row:
//   1. raw legacy pedido      ("1778302204-2d66242e", "123456")
//   2. customer-facing short  ("2605-0002")  — looked up against
//                              transacciones.pedido_corto
//   3. transaction id synth   ("TX42")       — last-resort canonical
$stripePi = '';
$pdfPath  = '';
$resolvedPedido = $pedido;   // what we'll embed in regenerated PDF / token
try {
    $pdo = getDB();
    // (1) raw pedido
    $row = $pdo->prepare("SELECT id, pedido, stripe_pi, contrato_pdf_path
                          FROM transacciones
                          WHERE pedido = ?
                          ORDER BY id DESC LIMIT 1");
    $row->execute([$pedido]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    // (2) pedido_corto fallback — try both the bare and VK-prefixed
    // forms. voltikaResolvePedidoCorto persists the column as
    // "VK-YYMM-NNNN" (with prefix), but admin links sometimes carry
    // just the bare body "2605-0002". Test both shapes.
    if (!$r) {
        try {
            $bare    = preg_replace('/^VK-/i', '', $pedido);
            $withPfx = 'VK-' . $bare;
            $row = $pdo->prepare("SELECT id, pedido, stripe_pi, contrato_pdf_path
                                  FROM transacciones
                                  WHERE pedido_corto = ? OR pedido_corto = ?
                                  ORDER BY id DESC LIMIT 1");
            $row->execute([$bare, $withPfx]);
            $r = $row->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // pedido_corto column may not exist on legacy installs — ignore
        }
    }
    // (3) "TX42" synth → resolve to id
    if (!$r && preg_match('/^TX(\d+)$/i', $pedido, $m)) {
        $row = $pdo->prepare("SELECT id, pedido, stripe_pi, contrato_pdf_path
                              FROM transacciones
                              WHERE id = ?
                              LIMIT 1");
        $row->execute([(int)$m[1]]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
    }
    if ($r) {
        $stripePi = (string)($r['stripe_pi'] ?? '');
        $pdfPath  = (string)($r['contrato_pdf_path'] ?? '');
        // Normalise the working identifier to the raw pedido so downstream
        // regen / file lookup behaves the same regardless of which key
        // the admin clicked through.
        if (!empty($r['pedido'])) $resolvedPedido = (string)$r['pedido'];
    }
} catch (Throwable $e) {
    error_log('descargar-contrato lookup: ' . $e->getMessage());
}

// Use the resolved pedido for the rest of the file (filename derivation,
// regen calls, etc.) so a pedido_corto/TX-id click still produces the
// correct PDF path.
$pedido = $resolvedPedido;

// ── Authorize ───────────────────────────────────────────────────────────
// Admin session lives under session_name('VOLTIKA_ADMIN') (see
// admin/php/bootstrap.php). We must adopt that name BEFORE session_start
// or PHP creates a fresh empty session under PHPSESSID and admin auth
// silently fails — that produced the misleading "No encontrado" 404
// when an admin clicked the contract download button (2026-04-29).
//
// Customer brief 2026-05-12 (Óscar, 7th round — screenshot 3: punto user
// got "404 · token inválido o sesión admin no detectada (session_name
// VOLTIKA_ADMIN). pedido_db_found=1"): punto users also need to view
// the contract from the Entregas Historial. They use a different
// session_name (VOLTIKA_PUNTO), so the admin-only check rejects them.
// We now try BOTH session names and accept either an admin_user_id or
// a punto_user_id. The contract is delivery-related, not financially
// sensitive — punto already sees all customer data for its motos.
$adminOk = false;
if (session_status() === PHP_SESSION_NONE) {
    // Try admin first.
    @session_name('VOLTIKA_ADMIN');
    @session_start();
    if (empty($_SESSION['admin_user_id'])) {
        // Not an admin — close and reopen as punto so the same request
        // can be authorized through the dealer panel.
        @session_write_close();
        @session_name('VOLTIKA_PUNTO');
        @session_start();
    }
}
if (!empty($_SESSION['admin_user_id']) || !empty($_SESSION['punto_user_id'])) {
    $adminOk = true;
}

if (!$adminOk && !contratoContadoVerifyToken($pedido, $stripePi, $token)) {
    http_response_code(404);
    // Helpful hint for admin who clicked the button without an active
    // session (e.g. testing in a private window) — for end-customers
    // we still return a generic 404 so we don't leak existence info.
    if (isset($_GET['debug']) && $_GET['debug'] === '1') {
        echo "404 · token inválido o sesión admin no detectada (session_name VOLTIKA_ADMIN). pedido_db_found=" . (!empty($r) ? '1' : '0');
    } else {
        echo 'No encontrado';
    }
    exit;
}

// ── Resolve the PDF on disk ─────────────────────────────────────────────
// We search EVERY known location in priority order:
//   1. DB-stored path (relative or absolute — both supported)
//   2. Canonical configurador/contratos/contado/...
//   3. /tmp fallback (Plesk hosting writes here when code-tree is read-only)
// This catches both old rows (relative path saved when /tmp was used as
// the actual write location) and new rows (absolute path stored).
$absPath = '';
$safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $pedido);
$filename = 'contrato_contado_' . $safe . '.pdf';
$searchPaths = [];
if ($pdfPath !== '') {
    if ($pdfPath[0] === '/') {
        $searchPaths[] = $pdfPath; // DB stored absolute
    } else {
        $searchPaths[] = __DIR__ . '/../' . ltrim($pdfPath, '/'); // DB stored relative
    }
}
$searchPaths[] = __DIR__ . '/../contratos/contado/' . $filename;            // canonical
$searchPaths[] = sys_get_temp_dir() . '/voltika_contratos_contado/' . $filename; // /tmp fallback

// Force regeneration when test mode is on or ?regen=1 is passed. Customer
// report 2026-04-30: a cached PDF on disk was being served with its
// original generation date (April 28), but during repeated testing the
// customer expects every download to reflect the current day. In live
// mode the saved PDF is preserved (a contract's date should not silently
// change between downloads for a real customer).
$forceRegen = (
    !empty($_GET['regen']) ||
    in_array(strtolower((string)(getenv('CDC_TEST_MODE') ?: '0')), ['1','true','yes','on'], true)
);

if (!$forceRegen) {
    foreach ($searchPaths as $candidate) {
        if ($candidate && file_exists($candidate) && is_readable($candidate)) {
            $absPath = $candidate;
            break;
        }
    }
}

// ── If no PDF exists, regenerate on-the-fly (admin only) ────────────────
// Older orders (placed before contrato-contado.php deployment), orders
// whose temp PDFs were cleaned up, and ALL orders on hosting where the
// code-tree was previously read-only end up here. The /tmp fallback in
// contratoContadoOutputDir() means the regen now always succeeds.
$regenError = null;
if (($absPath === '' || !is_readable($absPath)) && ($adminOk || $forceRegen)) {
    try {
        // Customer brief 2026-05-09 (Óscar — Contrato no disponible on
        // VK-2605-0002 despite assignment working): the regen lookup
        // previously used only `WHERE pedido = ?`. For rows where the
        // legacy `pedido` column is empty but `pedido_corto` is set
        // (the standard case for orders going through the new
        // voltikaResolvePedidoCorto flow), this lookup returned 0 rows
        // and the diagnostic page wrongly reported "El pedido no
        // existe en la tabla transacciones". Use the same 3-tier
        // fallback as the top-of-file lookup so an admin-clicked link
        // always finds the row when ANY identifier matches.
        $tx = null;
        // (1) raw pedido
        $bRow = $pdo->prepare("SELECT * FROM transacciones WHERE pedido = ? ORDER BY id DESC LIMIT 1");
        $bRow->execute([$pedido]);
        $tx = $bRow->fetch(PDO::FETCH_ASSOC);
        // (2) pedido_corto (bare + VK-prefixed)
        if (!$tx) {
            try {
                $bare    = preg_replace('/^VK-/i', '', $pedido);
                $withPfx = 'VK-' . $bare;
                $bRow = $pdo->prepare("SELECT * FROM transacciones
                                       WHERE pedido_corto = ? OR pedido_corto = ?
                                       ORDER BY id DESC LIMIT 1");
                $bRow->execute([$bare, $withPfx]);
                $tx = $bRow->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { /* pedido_corto column may not exist */ }
        }
        // (3) TX{id} synth
        if (!$tx && preg_match('/^TX(\d+)$/i', $pedido, $m)) {
            $bRow = $pdo->prepare("SELECT * FROM transacciones WHERE id = ? LIMIT 1");
            $bRow->execute([(int)$m[1]]);
            $tx = $bRow->fetch(PDO::FETCH_ASSOC);
        }
        if (!$tx) {
            $regenError = 'transacciones row not found for pedido ' . $pedido . ' (probadas: pedido, pedido_corto, TX{id})';
        } else {
            $tpagoNorm = strtolower((string)($tx['tpago'] ?? ''));
            // Map all 100 %-payment variants — credit-family is excluded.
            $allowed = ['contado','unico','msi','spei','oxxo','tarjeta','tarjeta de débito o crédito','tarjeta de credito','tarjeta de debito'];
            $isAllowed = false;
            foreach ($allowed as $a) if (strpos($tpagoNorm, $a) !== false || $tpagoNorm === $a) { $isAllowed = true; break; }
            // Customer brief 2026-05-09 (Óscar — "We can't check the
            // contract yet"): for credit-family orders the cash-sale
            // generator isn't applicable, but generar-contrato-pdf.php
            // (the credit caratula generator) writes its output to
            // configurador/php/contratos/contrato_<name>_<ts>.pdf. If
            // such a file already exists for this customer we serve
            // the most recent one. If none exists yet, fail with a
            // hint that the customer must complete the credit-signing
            // flow (no admin-side regen is possible for credit because
            // it requires the Cincel NOM-151 timestamp via Truora).
            $isCreditFam = !$isAllowed && in_array($tpagoNorm, ['credito','enganche','parcial'], true);
            if ($isCreditFam) {
                $safeName = preg_replace('/[^a-zA-Z0-9]/', '_', (string)($tx['nombre'] ?? ''));
                $candidates = [];
                if ($safeName !== '') {
                    $candidates = array_merge(
                        glob(__DIR__ . '/contratos/contrato_*' . $safeName . '*.pdf') ?: [],
                        glob(sys_get_temp_dir() . '/voltika_contratos/contrato_*' . $safeName . '*.pdf') ?: []
                    );
                }
                if ($candidates) {
                    usort($candidates, function($a, $b) { return filemtime($b) - filemtime($a); });
                    $absPath = $candidates[0];
                } else {
                    $regenError = 'No existe contrato de crédito generado para este pedido. El PDF se genera automáticamente cuando el cliente firma desde el configurador (Truora + Cincel). Reenvía el link de firma desde la solicitud en Solicitudes.';
                }
            } elseif (!$isAllowed) {
                $regenError = "tpago='{$tpagoNorm}' no es contado/MSI/SPEI/OXXO/crédito — pedido inválido";
            } else {
                $total = floatval($tx['total'] ?: $tx['precio']);
                $costoLog = (strpos($tpagoNorm, 'msi') !== false) ? 1800 : 0;
                // Customer brief 2026-05-09: when the row was resolved
                // via pedido_corto, $tx['pedido'] may be empty. Fall
                // back to pedido_corto (without VK- prefix) or the URL
                // param so the regenerated contract never carries an
                // empty pedido / folio field.
                $effectivePedido = $tx['pedido']
                    ?: preg_replace('/^VK-/i', '', (string)($tx['pedido_corto'] ?? ''))
                    ?: $pedido;
                $contratoData = [
                    'pedido'                  => $effectivePedido,
                    'folio'                   => $tx['folio_contrato'] ?: $effectivePedido,
                    // Use today's date for the contract header. Customer
                    // report 2026-04-30: regenerated contracts were showing
                    // the transaction's `freg` (e.g. April 28) instead of
                    // the date the contract is being issued (April 30).
                    // Aligned with confirmar-orden.php:1107 which already
                    // uses date('d/m/Y') at first generation.
                    'contract_date'           => date('d/m/Y'),
                    'customer_full_name'      => $tx['nombre'] ?: 'Cliente Voltika',
                    'customer_email'          => $tx['email'] ?? '',
                    'customer_phone'          => $tx['telefono'] ?? '',
                    'customer_zip'            => $tx['cp'] ?? '',
                    'vehicle_model'           => $tx['modelo'] ?? '',
                    'vehicle_color'           => $tx['color'] ?? '',
                    'vehicle_year'            => (int)date('Y'),
                    'vehicle_price'           => $total,
                    'logistics_cost'          => (strpos($tpagoNorm, 'msi') !== false) ? $costoLog : 0,
                    'total_amount'            => $total,
                    'payment_method'          => (strpos($tpagoNorm, 'msi') !== false ? 'msi' : (strpos($tpagoNorm, 'tarjeta') !== false ? 'contado' : $tpagoNorm)),
                    'payment_reference'       => $tx['stripe_pi'] ?: $tx['pedido'],
                    'payment_date'            => date('d/m/Y H:i', strtotime($tx['freg'] ?? 'now')),
                    // Customer brief 2026-05-07: regen path was always
                    // computing ETA as freg+10 days even when the order
                    // already had a real fecha_estimada_entrega from the
                    // Asignar punto flow; and it never passed delivery_point
                    // so the contract template always rendered "Por definir".
                    // Now we pull both straight from transacciones so the
                    // PDF reflects the actual logistics commitment.
                    'estimated_delivery_date' => !empty($tx['fecha_estimada_entrega'])
                        ? date('d/m/Y', strtotime((string)$tx['fecha_estimada_entrega']))
                        : date('d/m/Y', strtotime('+10 days', strtotime($tx['freg'] ?? 'now'))),
                    'delivery_point'          => trim((string)($tx['punto_nombre'] ?? '')) === ''
                        ? ''
                        : trim((string)$tx['punto_nombre']) . (
                            !empty($tx['ciudad']) ? ' · ' . trim((string)$tx['ciudad']) : ''
                        ),
                    'acceptance_timestamp'    => $tx['contrato_aceptado_at'] ?: ($tx['freg'] ?? gmdate('Y-m-d H:i:s')),
                    'acceptance_ip'           => $tx['contrato_aceptado_ip'] ?? '',
                    'acceptance_user_agent'   => $tx['contrato_aceptado_ua'] ?? '',
                    'acceptance_geolocation'  => $tx['contrato_geolocation'] ?? '',
                    'otp_validated'           => (int)($tx['contrato_otp_validated'] ?? 0),
                    // OTP audit-trail (post-payment OTP step persists these
                    // via verificar-otp.php). When ?regen=1 hits this path
                    // after the user completes the SMS OTP step, the
                    // refreshed PDF includes the full audit row in its
                    // REGISTRO DE ACEPTACIÓN ELECTRÓNICA.
                    'otp_validated_at'        => $tx['contrato_otp_validated_at'] ?? null,
                    'otp_phone_masked'        => $tx['contrato_otp_phone_masked'] ?? null,
                    'otp_code_sha256'         => $tx['contrato_otp_code_sha256']  ?? null,
                    'otp_ip'                  => $tx['contrato_otp_ip']           ?? null,
                    'otp_send_count'          => (int)($tx['contrato_otp_send_count'] ?? 0),
                ];
                $r = contratoContadoGenerate($contratoData);
                if ($r['ok'] && !empty($r['path']) && file_exists($r['path'])) {
                    $absPath = $r['path'];
                    // Persist absolute path + hash so subsequent requests
                    // hit the file directly without regen.
                    try {
                        $relPath = contratoContadoRelativePath($pedido);
                        // Lazy-add hash column (older installs lack it)
                        try {
                            $cols = $pdo->query("SHOW COLUMNS FROM transacciones LIKE 'contrato_pdf_hash'")->fetch();
                            if (!$cols) $pdo->exec("ALTER TABLE transacciones ADD COLUMN contrato_pdf_hash CHAR(64) NULL");
                        } catch (Throwable $e) {}
                        $pdo->prepare("UPDATE transacciones
                                        SET contrato_pdf_path = ?, contrato_pdf_hash = ?
                                        WHERE pedido = ?")
                            ->execute([$relPath, $r['hash'] ?? null, $pedido]);
                    } catch (Throwable $e) { /* non-fatal */ }
                } else {
                    $regenError = 'contratoContadoGenerate falló: ' . ($r['error'] ?? 'unknown');
                }
            }
        }
    } catch (Throwable $e) {
        $regenError = $e->getMessage();
        error_log('descargar-contrato regen: ' . $e->getMessage());
    }
}

if ($absPath === '' || !is_readable($absPath)) {
    http_response_code(404);

    // Customer brief 2026-05-12 (Óscar, 7th round — screenshot: punto
    // operator clicked Contrato firmado on a credit order and got the
    // full admin diagnostic page with "RUTAS BUSCADAS", "Dossier Setup",
    // server filesystem paths). The technical view is useful for admin
    // support staff but confusing for the punto operator. Detect the
    // punto session and render a simple, non-technical explanation
    // instead.
    $isPunto = empty($_SESSION['admin_user_id']) && !empty($_SESSION['punto_user_id']);

    if ($isPunto) {
        $tx = $tx ?? null;
        $tpagoNorm = strtolower(trim((string)($tx['tpago'] ?? '')));
        $isCredit  = in_array($tpagoNorm, ['credito','enganche','parcial'], true);

        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8">';
        echo '<title>Contrato no disponible</title><style>';
        echo 'body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f8fafc;color:#0f172a;padding:24px;max-width:540px;margin:40px auto;line-height:1.55;}';
        echo '.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.05);}';
        echo 'h1{font-size:20px;margin:0 0 12px;color:#0f172a;}';
        echo '.icon{font-size:42px;display:block;text-align:center;margin-bottom:14px;}';
        echo '.note{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 14px;color:#92400e;font-size:13.5px;margin-top:14px;}';
        echo '.note strong{color:#78350f;}';
        echo '.muted{color:#64748b;font-size:12.5px;margin-top:14px;}';
        echo '</style></head><body><div class="card">';
        echo '<span class="icon">📄</span>';
        if ($isCredit) {
            echo '<h1>Contrato de crédito pendiente de firma</h1>';
            echo '<p>Este pedido es <strong>a crédito</strong> y el contrato electrónico aún no ha sido firmado por el cliente.</p>';
            echo '<div class="note"><strong>¿Qué pasa?</strong><br>';
            echo 'El PDF del contrato se genera automáticamente cuando el cliente firma desde su portal voltika.mx/clientes con su identificación (Truora) y firma electrónica (Cincel). ';
            echo 'Pídele al cliente que complete la firma desde su celular para que el contrato esté disponible aquí.</div>';
        } else {
            echo '<h1>Contrato aún no disponible</h1>';
            echo '<p>El contrato de este pedido todavía no está listo para descarga.</p>';
            echo '<div class="note">Esto suele resolverse solo en unos minutos. Si persiste, avisa al equipo central (CEDIS / admin) con el número de pedido para que lo regeneren.</div>';
        }
        echo '<div class="muted">Pedido: <code>' . htmlspecialchars($pedido) . '</code></div>';
        echo '</div></body></html>';
        exit;
    }

    if ($adminOk) {
        // Full diagnostic page — admin needs to know exactly what failed.
        header('Content-Type: text/html; charset=UTF-8');
        $tx = $tx ?? null;
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8">';
        echo '<title>Contrato no disponible</title><style>';
        echo 'body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f0f4f8;color:#0c2340;padding:24px;max-width:780px;margin:0 auto;}';
        echo 'h1{font-size:20px;margin:0 0 6px;}h2{font-size:13px;color:#64748b;margin:0 0 18px;}';
        echo '.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:14px;}';
        echo '.tag{display:inline-block;background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:600;margin-bottom:8px;}';
        echo '.bad{background:#fee2e2;color:#991b1b;}';
        echo 'table{width:100%;border-collapse:collapse;font-size:12.5px;}';
        echo 'td{padding:6px 10px;border-bottom:1px solid #f1f5f9;vertical-align:top;}';
        echo 'td:first-child{color:#64748b;width:35%;}';
        echo 'code{background:#1e293b;color:#e2e8f0;padding:2px 7px;border-radius:4px;font-size:11px;}';
        echo '.ok{color:#16a34a;font-weight:600}.err{color:#dc2626;font-weight:600}';
        echo '</style></head><body>';
        echo '<h1>📄 Contrato no disponible</h1>';
        echo '<h2>Pedido: ' . htmlspecialchars($pedido) . '</h2>';

        echo '<div class="card"><div class="tag bad">RAZÓN</div>';
        if (!$tx) {
            echo '<p>El pedido no existe en la tabla <code>transacciones</code>. Verifica el folio o el pedido pudo haber sido eliminado.</p>';
        } elseif (!empty($regenError)) {
            echo '<p>La regeneración del PDF falló:<br><code>' . htmlspecialchars($regenError) . '</code></p>';
        } else {
            echo '<p>El PDF no existe en disco y no fue posible regenerarlo automáticamente.</p>';
        }
        echo '</div>';

        echo '<div class="card"><div class="tag">RUTAS BUSCADAS</div><table>';
        foreach ($searchPaths as $sp) {
            $exists = $sp && file_exists($sp);
            echo '<tr><td><code>' . htmlspecialchars($sp) . '</code></td>';
            echo '<td class="' . ($exists ? 'ok' : 'err') . '">' . ($exists ? '✓ existe' : '× no') . '</td></tr>';
        }
        echo '</table></div>';

        if ($tx) {
            echo '<div class="card"><div class="tag">DATOS DEL PEDIDO</div><table>';
            $rows = [
                'tpago' => $tx['tpago'] ?? '—',
                'pago_estado' => $tx['pago_estado'] ?? '—',
                'total' => $tx['total'] ?? '—',
                'contrato_pdf_path' => $tx['contrato_pdf_path'] ?? '(vacío)',
                'contrato_pdf_hash' => $tx['contrato_pdf_hash'] ?? '(vacío)',
                'modelo · color' => ($tx['modelo'] ?? '—') . ' · ' . ($tx['color'] ?? '—'),
            ];
            foreach ($rows as $k => $v) echo '<tr><td>' . $k . '</td><td><code>' . htmlspecialchars((string)$v) . '</code></td></tr>';
            echo '</table></div>';
        }

        echo '<div class="card" style="background:#eff6ff;border-color:#60a5fa;">';
        echo '<b>¿Qué hacer?</b><br>';
        echo '1. Ejecuta <a href="../../admin/php/dossier-setup.php"><b>Dossier Setup</b></a> para confirmar que <code>contratos/contado/</code> es escribible o que el fallback /tmp está activo.<br>';
        echo '2. Si el pedido es de crédito (enganche), usa el endpoint <code>generar-contrato-pdf.php</code> en lugar de éste.<br>';
        echo '3. Para regenerar manualmente: añade <code>?pedido=' . htmlspecialchars($pedido) . '&inline=1&_force=' . time() . '</code> a la URL.';
        echo '</div></body></html>';
    } else {
        echo 'Contrato no disponible';
    }
    exit;
}

// ── Stream PDF ──────────────────────────────────────────────────────────
$dispositionName = 'Contrato_Voltika_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $pedido) . '.pdf';
$disposition = isset($_GET['inline']) && $_GET['inline'] === '1' ? 'inline' : 'attachment';

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($absPath));
header('Content-Disposition: ' . $disposition . '; filename="' . $dispositionName . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($absPath);
exit;
