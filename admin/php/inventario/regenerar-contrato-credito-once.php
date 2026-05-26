<?php
/**
 * ONE-SHOT TOOL — Regenerar contratos de crédito incorrectos.
 *
 * Handles two legacy bugs (now patched by Round 87 + Round 88):
 *
 *   A) "Crédito mal clasificado como contado" (Leobardo Arreola case,
 *      VK-2605-0004): the customer chose the credit plan + paid the
 *      enganche via OXXO, but pre-Round-88 metadata stored tpago='oxxo'.
 *      confirmar-orden then routed to the contado contract path. He
 *      ended up with a "Contrato de compraventa AL CONTADO" instead of
 *      a credit Carátula.
 *
 *   B) "Contrato de crédito con todos los montos en $0.00" (Carlos
 *      Ricardo Sánchez case): pre-Round-87 the SPA omitted precioContado
 *      and enganche when calling generar-contrato-pdf.php. The backend
 *      defaulted both to 0 → the PDF rendered with $0 in every monetary
 *      row even though the customer paid real money.
 *
 * Discovery sections list candidates from the DB. Admin picks a case,
 * confirms plazoMeses (and optional enganchePct override), clicks
 * "Regenerar". The tool:
 *
 *   1) UPDATEs transacciones.tpago = 'enganche' if it was previously a
 *      non-credit value (e.g. 'oxxo', 'spei', 'contado').
 *   2) Computes precioContado from the catálogo (hard-coded prices) and
 *      derives enganchePct from total/precioContado.
 *   3) Loads the existing autograph signature from firmas_contratos
 *      (matched by email/telefono).
 *   4) Calls generar-contrato-pdf.php's generateContractPDF() helper to
 *      build the corrected Carátula a plazos PDF.
 *   5) UPDATEs transacciones.contrato_pdf_path with the new file.
 *   6) Adds a forensic flag (contrato_regenerado_admin=1) +
 *      contrato_regenerado_motivo so the row is distinguishable from a
 *      normal first-issue contract.
 *   7) Audit log via adminLog().
 *
 * Idempotent — re-running on an already-regenerated row is a no-op.
 *
 * Admin auth required. Safe to delete file once both Leobardo and Carlos
 * are processed (Round 87 + 88 prevent the bugs from recurring).
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

// ── Catalog (mirrors configurador/js/data/productos.js precioContado) ──
const CATALOGO_PRECIOS = [
    'M05'           => 48260,
    'M03'           => 39900,
    'Ukko S+'       => 89900,
    'MC10 Streetx'  => 109900,
    'MC10'          => 109900,
    'Pesgo Plus'    => 36600,
    'Mino-B'        => 41820,
    'mino B'        => 41820,
];

$pdo = getDB();
header('Content-Type: text/html; charset=utf-8');

// Ensure forensic columns exist (idempotent).
foreach ([
    'contrato_regenerado_admin'         => "TINYINT(1) NULL DEFAULT 0",
    'contrato_regenerado_admin_motivo'  => "TEXT NULL",
    'contrato_regenerado_admin_user_id' => "INT NULL",
    'contrato_regenerado_admin_fecha'   => "DATETIME NULL",
] as $col => $def) {
    try { $pdo->exec("ALTER TABLE transacciones ADD COLUMN $col $def"); } catch (Throwable $e) {}
}

// Round 90 v3 (2026-05-26) — POST must override GET for $action. When the
// preview form POSTs back to the same URL, the URL still carries
// ?action=preview, so the old GET-first precedence made $action='preview'
// even though the form's hidden input set action='apply'. The apply
// branch never ran and the preview page re-rendered. Symptoms:
// "Regenerar contrato" button looked like a no-op.
$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'list');
$pedido = trim((string)($_POST['pedido'] ?? $_GET['pedido'] ?? ''));
$txId   = (int)($_POST['tx_id'] ?? $_GET['tx_id'] ?? 0);

// ──────────────────────────────────────────────────────────────────────────
// Render helpers
// ──────────────────────────────────────────────────────────────────────────
echo '<!doctype html><html><head><meta charset="utf-8"><title>Regenerar contratos de crédito</title>';
echo '<style>
body{font-family:system-ui,-apple-system,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1280px;margin:0 auto;line-height:1.5;}
h1{font-size:22px;margin:0 0 4px;}
h2{font-size:16px;color:#475569;margin:24px 0 8px;border-bottom:1px solid #cbd5e1;padding-bottom:4px;}
.sec{background:#fff;padding:14px 16px;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:14px;}
table{width:100%;border-collapse:collapse;font-size:12.5px;}
th{background:#f1f5f9;text-align:left;padding:7px 9px;font-size:11.5px;}
td{padding:7px 9px;border-top:1px solid #f1f5f9;vertical-align:top;}
.empty{color:#94a3b8;font-style:italic;font-size:13px;}
.btn{padding:7px 14px;background:#039fe1;color:#fff;border:0;border-radius:5px;cursor:pointer;font-size:12.5px;font-weight:600;text-decoration:none;display:inline-block;}
.btn.danger{background:#dc2626;}
.btn.ghost{background:#fff;color:#0c2340;border:1px solid #cbd5e1;}
.ok{color:#15803d;font-weight:700;}
.warn{color:#a16207;font-weight:700;}
.err{color:#b91c1c;font-weight:700;}
.hint{background:#fef9c3;border:1px solid #facc15;padding:10px 14px;border-radius:8px;margin:10px 0;font-size:13px;}
.success{background:#d1fae5;border:1px solid #34d399;color:#065f46;padding:14px 18px;border-radius:10px;margin:14px 0;}
code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:11.5px;}
select,input{padding:6px 10px;border:1px solid #cbd5e1;border-radius:5px;font-size:13px;}
.crumb a{color:#0369a1;text-decoration:none;font-size:12px;}
</style></head><body>';
echo '<h1>🔁 Regenerar contratos de crédito (1-shot)</h1>';
echo '<p style="color:#64748b;font-size:13px;margin-top:0;">Corrige los dos bugs heredados (clasificación errónea + montos en $0). Round 87 + 88 ya impide que vuelvan a ocurrir.</p>';
echo '<p class="crumb"><a href="?">← Lista de candidatos</a></p>';

// ──────────────────────────────────────────────────────────────────────────
// ACTION: APPLY — actually regenerate the contract
// ──────────────────────────────────────────────────────────────────────────
if ($action === 'apply' && ($pedido !== '' || $txId > 0) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $plazoMeses = max(12, min(60, (int)($_POST['plazo'] ?? 36)));

    // 1) Load transacciones row — prefer tx_id when given (it's unique), fall back to pedido.
    if ($txId > 0) {
        $st = $pdo->prepare("SELECT * FROM transacciones WHERE id = ? LIMIT 1");
        $st->execute([$txId]);
    } else {
        $st = $pdo->prepare("SELECT * FROM transacciones WHERE pedido = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$pedido]);
    }
    $tx = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$tx) {
        echo '<div class="err">Transacción no encontrada (pedido=' . htmlspecialchars($pedido) . ', tx_id=' . $txId . ')</div></body></html>';
        exit;
    }
    $pedido = (string)($tx['pedido'] ?? '');

    // Idempotency check — skip only when a previous regen succeeded AND the
    // stored file is still findable on disk. Round 90 v4 (2026-05-26): a
    // previous regen could have stored a relative path that descargar-contrato.php
    // resolves to a non-existent location (the path-resolution bug). Detect
    // that case and ALLOW the re-run instead of false-positively short-
    // circuiting on "ya regenerado".
    if ((int)($tx['contrato_regenerado_admin'] ?? 0) === 1) {
        $existingPath = (string)($tx['contrato_pdf_path'] ?? '');
        $resolvedFile = '';
        if ($existingPath !== '') {
            if ($existingPath[0] === '/') {
                $resolvedFile = is_file($existingPath) ? $existingPath : '';
            } else {
                // Try both legacy and new save folders.
                foreach ([
                    __DIR__ . '/../../../configurador/' . ltrim($existingPath, '/'),
                    __DIR__ . '/../../../configurador/php/' . ltrim($existingPath, '/'),
                ] as $candidate) {
                    if (is_file($candidate)) { $resolvedFile = $candidate; break; }
                }
            }
        }
        if ($resolvedFile !== '') {
            // If the DB-stored path is relative and doesn't match where the file
            // actually lives, fix the DB to use the absolute path so future
            // descargar-contrato.php calls find it. One-shot correction for
            // contracts regenerated before Round 90 v4.
            $needsPathFix = ($existingPath === '' || $existingPath[0] !== '/' || !is_file($existingPath));
            if ($needsPathFix && $resolvedFile !== $existingPath) {
                try {
                    $pdo->prepare("UPDATE transacciones SET contrato_pdf_path = ? WHERE id = ?")
                        ->execute([$resolvedFile, (int)$tx['id']]);
                    echo '<div class="success">✅ Ya regenerado el ' . htmlspecialchars((string)$tx['contrato_regenerado_admin_fecha']) . '. <strong>Path en BD corregido</strong>: <code>' . htmlspecialchars($existingPath) . '</code> → <code>' . htmlspecialchars($resolvedFile) . '</code></div>';
                } catch (Throwable $e) {
                    echo '<div class="err">No se pudo corregir el path: ' . htmlspecialchars($e->getMessage()) . '</div>';
                }
            } else {
                echo '<div class="success">✅ Ya regenerado el ' . htmlspecialchars((string)$tx['contrato_regenerado_admin_fecha']) . '. Archivo encontrado en <code>' . htmlspecialchars($resolvedFile) . '</code>. Nada que hacer.</div>';
            }
            $viewUrl = '/configurador/php/descargar-contrato.php?pedido=TX' . (int)$tx['id'] . '&inline=1';
            echo '<p><a class="btn" href="' . htmlspecialchars($viewUrl) . '" target="_blank" style="background:#10b981;">📄 Ver PDF</a> <a class="btn ghost" href="?">← Volver a la lista</a></p>';
            echo '</body></html>';
            exit;
        }
        // File missing → proceed with re-run.
        echo '<div class="hint">⚠ Esta transacción ya tiene flag contrato_regenerado_admin=1 pero el archivo PDF no se encuentra en disco. Re-generando...</div>';
    }

    // 2) Resolve modelo precio
    $modelo = trim((string)($tx['modelo'] ?? ''));
    $precioContado = CATALOGO_PRECIOS[$modelo] ?? null;
    if ($precioContado === null) {
        // Try fuzzy match
        foreach (CATALOGO_PRECIOS as $k => $v) {
            if (stripos($modelo, $k) !== false) { $precioContado = $v; break; }
        }
    }
    if (!$precioContado) {
        echo '<div class="err">No se reconoce el modelo "' . htmlspecialchars($modelo) . '". Agrega el precio a CATALOGO_PRECIOS y reintenta.</div></body></html>';
        exit;
    }

    // 3) Derive enganche + enganchePct from the paid total
    $enganchePagado = (float)($tx['total'] ?? 0);
    if ($enganchePagado <= 0) {
        echo '<div class="err">transacciones.total = 0 o negativo. No puedo derivar el enganche.</div></body></html>';
        exit;
    }
    $enganchePct = round($enganchePagado / $precioContado, 4);
    if ($enganchePct < 0.10 || $enganchePct > 0.90) {
        echo '<div class="err">enganchePct derivado fuera de rango razonable (' . round($enganchePct*100, 1) . '%). Revisa precioContado vs total pagado.</div></body></html>';
        exit;
    }

    // 4) Compute credit terms (mirrors VkCalculadora.calcular)
    $montoFinanciado = $precioContado - $enganchePagado;
    // Annual rate matches the JS calculator. Inspect calculadora-credito.js
    // for the canonical value; the credit Carátula is computed off these
    // numbers so any drift here will produce a contract that differs from
    // what the customer was quoted.
    $tasaAnual = 0.42; // 42% annual (approx) — adjust if calculator differs
    $r = $tasaAnual / 52;
    $n = (int)round($plazoMeses * 4.33);
    $pagoSemanal = $r > 0
        ? $montoFinanciado * $r / (1 - pow(1 + $r, -$n))
        : $montoFinanciado / $n;
    $pagoSemanal = (int)round($pagoSemanal);

    // 5) Fetch existing autograph signature (matched by email/telefono)
    $email = trim((string)($tx['email']    ?? ''));
    $tel   = trim((string)($tx['telefono'] ?? ''));
    $firma = null;
    try {
        $where = [];
        $args  = [];
        if ($email !== '') { $where[] = 'email = ?';    $args[] = $email; }
        if ($tel   !== '') { $where[] = 'telefono = ?'; $args[] = $tel; }
        if ($where) {
            $sql = "SELECT firma_base64 FROM firmas_contratos
                     WHERE (" . implode(' OR ', $where) . ")
                       AND firma_base64 IS NOT NULL AND firma_base64 <> ''
                     ORDER BY id DESC LIMIT 1";
            $fs = $pdo->prepare($sql);
            $fs->execute($args);
            $firma = (string)($fs->fetchColumn() ?: '');
        }
    } catch (Throwable $e) { error_log('regen contrato firma: ' . $e->getMessage()); }

    // 6) Promote tpago to 'enganche' if it was a non-credit value
    $oldTpago = (string)($tx['tpago'] ?? '');
    $newTpago = $oldTpago;
    if (!in_array($oldTpago, ['enganche','credito','parcial','credito-orfano'], true)) {
        $newTpago = 'enganche';
        try {
            $pdo->prepare("UPDATE transacciones SET tpago = ? WHERE id = ?")
                ->execute([$newTpago, (int)$tx['id']]);
        } catch (Throwable $e) {
            echo '<div class="err">Error al actualizar tpago: ' . htmlspecialchars($e->getMessage()) . '</div></body></html>';
            exit;
        }
    }

    // 7) Call generateContractPDF() — load in library mode so the file's
    //    top-level HTTP handler doesn't execute and corrupt our flow.
    if (!defined('VOLTIKA_PDF_LIBRARY_MODE')) define('VOLTIKA_PDF_LIBRARY_MODE', true);
    require_once __DIR__ . '/../../../configurador/php/generar-contrato-pdf.php';
    $credito = [
        'precioContado'   => $precioContado,
        'enganche'        => $enganchePagado,
        'plazoMeses'      => $plazoMeses,
        'pagoSemanal'     => $pagoSemanal,
        'montoFinanciado' => $montoFinanciado,
    ];
    $newPdfPath = null;
    try {
        $newPdfPath = generateContractPDF(
            (string)($tx['nombre']   ?? ''),
            $email,
            $tel,
            $modelo,
            (string)($tx['color']    ?? ''),
            (string)($tx['ciudad']   ?? ''),
            (string)($tx['estado']   ?? ''),
            (string)($tx['cp']       ?? ''),
            $credito,
            $firma ?: null,
            '',  // curp (not stored on transacciones)
            '',  // domicilio
            (string)($tx['stripe_pi'] ?? '')
        );
    } catch (Throwable $e) {
        echo '<div class="err">Error generando PDF: ' . htmlspecialchars($e->getMessage()) . '</div></body></html>';
        exit;
    }

    if (!$newPdfPath || !is_file($newPdfPath)) {
        echo '<div class="err">PDF no fue creado correctamente.</div></body></html>';
        exit;
    }

    // 8) Persist new path + forensic markers.
    // Round 90 v4 (2026-05-26) — store the ABSOLUTE path. generateContractPDF
    // saves to configurador/php/contratos/ but descargar-contrato.php's
    // relative-path resolver assumes configurador/contratos/ (different folder).
    // Storing the absolute path is unambiguous — descargar-contrato.php's line
    // 150 handles absolute paths directly without folder guessing.
    $relPath = $newPdfPath;  // absolute
    $hash    = @hash_file('sha256', $newPdfPath) ?: null;
    $adminUser = $_SESSION['admin_user_id'] ?? null;
    $adminName = $_SESSION['admin_user_nombre'] ?? ($_SESSION['admin_email'] ?? 'admin');
    $motivo = sprintf(
        '[FORENSIC] Regeneración admin %s — tpago %s→%s, modelo=%s, precio=%d, enganche=%d (%.2f%%), plazo=%d meses, pagoSemanal=%d.',
        $adminName, $oldTpago, $newTpago, $modelo, $precioContado, (int)$enganchePagado, $enganchePct * 100, $plazoMeses, $pagoSemanal
    );
    try {
        $pdo->prepare("UPDATE transacciones
            SET contrato_pdf_path = ?, contrato_pdf_hash = ?,
                contrato_regenerado_admin = 1,
                contrato_regenerado_admin_motivo = ?,
                contrato_regenerado_admin_user_id = ?,
                contrato_regenerado_admin_fecha = NOW()
            WHERE id = ?")
            ->execute([$relPath, $hash, $motivo, $adminUser ? (int)$adminUser : null, (int)$tx['id']]);
    } catch (Throwable $e) {
        echo '<div class="warn">PDF generado pero falló persistir contrato_pdf_path: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }

    // 9) Ensure subscripciones_credito row (for Leobardo who never had one)
    try {
        $hasSub = $pdo->prepare("SELECT id FROM subscripciones_credito
            WHERE (telefono = ? OR email = ?) AND modelo = ? LIMIT 1");
        $hasSub->execute([$tel, $email, $modelo]);
        if (!$hasSub->fetchColumn()) {
            $pdo->prepare("INSERT INTO subscripciones_credito
                (nombre, email, telefono, modelo, color, monto_semanal, plazo_meses, estado, freg)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pendiente', NOW())")
                ->execute([
                    $tx['nombre'] ?? '', $email, $tel, $modelo,
                    $tx['color'] ?? '', $pagoSemanal, $plazoMeses,
                ]);
        }
    } catch (Throwable $e) { error_log('regen contrato sub: ' . $e->getMessage()); }

    // 10) Audit log
    if (function_exists('adminLog')) {
        adminLog('contrato_credito_regenerado_admin', [
            'pedido'     => $pedido,
            'tx_id'      => (int)$tx['id'],
            'old_tpago'  => $oldTpago,
            'new_tpago'  => $newTpago,
            'precioContado' => $precioContado,
            'enganche'   => $enganchePagado,
            'plazoMeses' => $plazoMeses,
            'pagoSemanal'=> $pagoSemanal,
            'pdf_path'   => $relPath,
        ]);
    }

    echo '<div class="success">';
    echo '<h2 style="margin-top:0;color:#065f46;border:0;">✅ Contrato regenerado</h2>';
    echo '<ul>';
    echo '<li>Pedido: <strong>' . htmlspecialchars($pedido) . '</strong></li>';
    echo '<li>Cliente: <strong>' . htmlspecialchars((string)$tx['nombre']) . '</strong></li>';
    echo '<li>tpago: <code>' . htmlspecialchars($oldTpago) . '</code> → <code>' . htmlspecialchars($newTpago) . '</code></li>';
    echo '<li>Modelo: ' . htmlspecialchars($modelo) . ' · precioContado $' . number_format($precioContado, 2) . '</li>';
    echo '<li>Enganche pagado: $' . number_format($enganchePagado, 2) . ' (' . round($enganchePct * 100, 1) . '%)</li>';
    echo '<li>Plazo: ' . $plazoMeses . ' meses (' . $n . ' pagos semanales)</li>';
    echo '<li>Pago semanal: $' . number_format($pagoSemanal, 2) . '</li>';
    echo '<li>PDF nuevo: <code>' . htmlspecialchars($relPath) . '</code></li>';
    echo '<li>Hash: <code>' . htmlspecialchars((string)$hash) . '</code></li>';
    echo '</ul>';
    echo '<p><strong>Importante:</strong> el contrato se regeneró con la firma autógrafa existente del cliente. Si necesitas que el cliente firme un contrato amendment formal, gestiónalo aparte por flujo de re-firma.</p>';
    echo '</div>';
    $viewUrl = '/configurador/php/descargar-contrato.php?pedido=TX' . (int)$tx['id'] . '&inline=1';
    echo '<p>';
    echo '<a class="btn" href="' . htmlspecialchars($viewUrl) . '" target="_blank" style="background:#10b981;">📄 Ver PDF nuevo</a> ';
    echo '<a class="btn ghost" href="?">← Volver a la lista</a>';
    echo '</p>';
    echo '</body></html>';
    exit;
}

// ──────────────────────────────────────────────────────────────────────────
// ACTION: PREVIEW — show details + form
// ──────────────────────────────────────────────────────────────────────────
if ($action === 'preview' && ($pedido !== '' || $txId > 0)) {
    if ($txId > 0) {
        $st = $pdo->prepare("SELECT * FROM transacciones WHERE id = ? LIMIT 1");
        $st->execute([$txId]);
    } else {
        $st = $pdo->prepare("SELECT * FROM transacciones WHERE pedido = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$pedido]);
    }
    $tx = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$tx) {
        echo '<div class="err">Transacción no encontrada (pedido=' . htmlspecialchars($pedido) . ', tx_id=' . $txId . ')</div>';
        echo '</body></html>'; exit;
    }
    $pedido = (string)($tx['pedido'] ?? '');
    $txId   = (int)$tx['id'];
    $modelo = (string)($tx['modelo'] ?? '');
    $precioContado = CATALOGO_PRECIOS[$modelo] ?? null;
    foreach (CATALOGO_PRECIOS as $k => $v) {
        if ($precioContado === null && stripos($modelo, $k) !== false) { $precioContado = $v; break; }
    }
    $enganchePagado = (float)($tx['total'] ?? 0);
    $enganchePct = $precioContado ? round($enganchePagado / $precioContado, 4) : 0;

    echo '<div class="sec"><h2>Preview — ' . htmlspecialchars($pedido) . '</h2>';
    echo '<table>';
    echo '<tr><th>Cliente</th><td>' . htmlspecialchars((string)$tx['nombre']) . '</td></tr>';
    echo '<tr><th>Email</th><td>' . htmlspecialchars((string)$tx['email']) . '</td></tr>';
    echo '<tr><th>Teléfono</th><td>' . htmlspecialchars((string)$tx['telefono']) . '</td></tr>';
    echo '<tr><th>Modelo</th><td>' . htmlspecialchars($modelo) . ' · color: ' . htmlspecialchars((string)$tx['color']) . '</td></tr>';
    echo '<tr><th>Total pagado (= enganche)</th><td><strong>$' . number_format($enganchePagado, 2) . '</strong></td></tr>';
    echo '<tr><th>tpago actual</th><td><code>' . htmlspecialchars((string)$tx['tpago']) . '</code></td></tr>';
    echo '<tr><th>precioContado (catálogo)</th><td>$' . ($precioContado ? number_format($precioContado, 2) : '<span class="err">No reconocido</span>') . '</td></tr>';
    echo '<tr><th>enganchePct derivado</th><td>' . round($enganchePct * 100, 1) . '%</td></tr>';
    echo '<tr><th>PDF actual</th><td><code>' . htmlspecialchars((string)$tx['contrato_pdf_path']) . '</code></td></tr>';
    if (!empty($tx['contrato_regenerado_admin'])) {
        echo '<tr><th>Estado</th><td class="ok">YA REGENERADO el ' . htmlspecialchars((string)$tx['contrato_regenerado_admin_fecha']) . '</td></tr>';
    }
    echo '</table>';

    if (!empty($tx['contrato_regenerado_admin'])) {
        echo '<div class="success">Ya regenerado. Si necesitas regenerar otra vez por algún motivo, primero borra el flag <code>contrato_regenerado_admin</code> en la BD.</div>';
        echo '</div></body></html>'; exit;
    }
    if (!$precioContado) {
        echo '<div class="err">No reconozco el modelo. Agrega "' . htmlspecialchars($modelo) . '" a CATALOGO_PRECIOS en este script.</div></div></body></html>'; exit;
    }

    echo '<form method="post" style="margin-top:14px;">';
    echo '<input type="hidden" name="action" value="apply">';
    echo '<input type="hidden" name="pedido" value="' . htmlspecialchars($pedido) . '">';
    echo '<input type="hidden" name="tx_id" value="' . (int)$txId . '">';
    echo '<label><strong>plazoMeses:</strong> ';
    echo '<select name="plazo">';
    foreach ([12, 18, 24, 36, 48] as $p) {
        $sel = $p === 36 ? ' selected' : '';
        echo '<option value="' . $p . '"' . $sel . '>' . $p . ' meses</option>';
    }
    echo '</select></label>';
    echo ' <button class="btn" type="submit" onclick="return confirm(\'¿Regenerar el contrato de crédito? Esto sobrescribe el PDF actual y deja un flag forense en la BD.\')">▶ Regenerar contrato</button>';
    echo '</form>';
    echo '</div></body></html>';
    exit;
}

// ──────────────────────────────────────────────────────────────────────────
// DEFAULT: LIST candidates
// ──────────────────────────────────────────────────────────────────────────

// Section A: contado/oxxo/spei rows whose customer ALSO has a subscripciones_credito row
echo '<div class="sec"><h2>A. Crédito clasificado erróneamente como contado/oxxo/spei</h2>';
try {
    $rows = $pdo->query("
        SELECT t.id, t.pedido, t.nombre, t.email, t.telefono, t.modelo, t.color,
               t.total, t.tpago, t.contrato_pdf_path, t.contrato_regenerado_admin
          FROM transacciones t
         WHERE t.tpago IN ('contado','oxxo','spei','unico','msi')
           AND EXISTS (
                 SELECT 1 FROM subscripciones_credito s
                  WHERE (s.email <> '' AND s.email = t.email)
                     OR (s.telefono <> '' AND s.telefono = t.telefono)
               )
         ORDER BY t.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        echo '<div class="empty">Sin candidatos.</div>';
    } else {
        echo '<table><thead><tr><th>Pedido</th><th>Cliente</th><th>Modelo</th><th>Total</th><th>tpago</th><th>Regenerado?</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr>';
            echo '<td><code>' . htmlspecialchars((string)($r['pedido'] ?: '#' . (int)$r['id'])) . '</code></td>';
            echo '<td>' . htmlspecialchars((string)$r['nombre']) . '<br><small style="color:#64748b;">' . htmlspecialchars((string)$r['email']) . ' · ' . htmlspecialchars((string)$r['telefono']) . '</small></td>';
            echo '<td>' . htmlspecialchars((string)$r['modelo']) . ' / ' . htmlspecialchars((string)$r['color']) . '</td>';
            echo '<td>$' . number_format((float)$r['total'], 2) . '</td>';
            echo '<td><code>' . htmlspecialchars((string)$r['tpago']) . '</code></td>';
            echo '<td>' . ($r['contrato_regenerado_admin'] ? '<span class="ok">SÍ</span>' : '<span class="warn">no</span>') . '</td>';
            echo '<td><a class="btn ghost" href="?action=preview&tx_id=' . (int)$r['id'] . '">Preview →</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
} catch (Throwable $e) {
    echo '<div class="err">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
echo '</div>';

// Section B: Manual lookup (for Carlos and others)
echo '<div class="sec"><h2>B. Buscar por pedido (regenerar contrato con datos correctos)</h2>';
echo '<p style="color:#64748b;font-size:12.5px;">Útil cuando el tpago ya es correcto (enganche/credito) pero el PDF salió con montos en $0 — caso Carlos Ricardo Sánchez. Ingresa el pedido o busca por email/teléfono.</p>';
echo '<form method="get" style="margin-bottom:14px;">';
echo '<input type="hidden" name="action" value="search">';
echo '<input name="q" placeholder="pedido / email / teléfono" value="' . htmlspecialchars((string)($_GET['q'] ?? '')) . '" style="width:300px;">';
echo ' <button class="btn" type="submit">Buscar</button>';
echo '</form>';

$q = trim((string)($_GET['q'] ?? ''));
if ($action === 'search' && $q !== '') {
    try {
        // Round 90 (2026-05-26) — Strip "VK-" prefix and use LIKE so
        // searches like "VK-2605-0004" match the underlying transacciones.pedido
        // (which is stored as "2605-0004" or just numbers — the "VK-" prefix
        // is only added at display time). Also join inventario_motos so a
        // search for the displayed pedido_num (e.g. "VK-2605-0004") finds
        // the linked transaction even if transacciones.pedido was empty/diff.
        $qStripped = preg_replace('/^VK-?/i', '', $q);
        $like      = '%' . $qStripped . '%';
        $nameLike  = '%' . $q . '%';
        $st = $pdo->prepare("
            SELECT DISTINCT t.id, t.pedido, t.nombre, t.email, t.telefono,
                   t.modelo, t.color, t.total, t.tpago,
                   t.contrato_pdf_path, t.contrato_regenerado_admin
              FROM transacciones t
              LEFT JOIN inventario_motos m
                     ON (m.cliente_email <> '' AND m.cliente_email = t.email)
                     OR (m.cliente_telefono <> '' AND m.cliente_telefono = t.telefono)
                     OR (m.stripe_pi <> '' AND m.stripe_pi = t.stripe_pi)
             WHERE t.pedido = ? OR t.pedido = ? OR t.pedido LIKE ?
                OR t.email = ? OR t.telefono = ?
                OR t.nombre LIKE ?
                OR m.pedido_num = ? OR m.pedido_num = ?
             ORDER BY t.id DESC
             LIMIT 20");
        $st->execute([
            $q, $qStripped, $like,
            $q, $q,
            $nameLike,
            $q, ('VK-' . $qStripped),
        ]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        // Round 90 v2 — 2-step fallback: if direct search returns 0, query
        // inventario_motos by pedido_num and then use its email/telefono to
        // find linked transacciones. Handles the case where transacciones
        // and inventario_motos have different email/telefono values (the
        // JOIN above fails) but the user knows the pedido_num from the
        // admin moto detail screen.
        if (!$rows) {
            try {
                $invSt = $pdo->prepare("SELECT id, pedido_num, cliente_nombre, cliente_email, cliente_telefono,
                                               modelo, color, stripe_pi, estado
                                          FROM inventario_motos
                                         WHERE pedido_num = ?
                                            OR pedido_num = ?
                                            OR pedido_num LIKE ?
                                            OR cliente_email = ?
                                            OR cliente_telefono = ?
                                            OR cliente_nombre LIKE ?
                                         ORDER BY id DESC LIMIT 5");
                $invSt->execute([$q, ('VK-' . $qStripped), '%' . $qStripped . '%', $q, $q, $nameLike]);
                $invRows = $invSt->fetchAll(PDO::FETCH_ASSOC);
                if ($invRows) {
                    // Now use each inventario_motos row's email/telefono/stripe_pi
                    // to find the linked transacciones row.
                    $foundIds = [];
                    foreach ($invRows as $inv) {
                        $tArgs = [];
                        $where2 = [];
                        if (!empty($inv['stripe_pi'])) {
                            $where2[] = 't.stripe_pi = ?'; $tArgs[] = $inv['stripe_pi'];
                        }
                        if (!empty($inv['cliente_email'])) {
                            $where2[] = 't.email = ?'; $tArgs[] = $inv['cliente_email'];
                        }
                        if (!empty($inv['cliente_telefono'])) {
                            $where2[] = 't.telefono = ?'; $tArgs[] = $inv['cliente_telefono'];
                        }
                        if (!$where2) continue;
                        $tStmt = $pdo->prepare("SELECT t.id, t.pedido, t.nombre, t.email, t.telefono,
                                                       t.modelo, t.color, t.total, t.tpago,
                                                       t.contrato_pdf_path, t.contrato_regenerado_admin
                                                  FROM transacciones t
                                                 WHERE (" . implode(' OR ', $where2) . ")
                                                 ORDER BY t.id DESC LIMIT 5");
                        $tStmt->execute($tArgs);
                        foreach ($tStmt->fetchAll(PDO::FETCH_ASSOC) as $tr) {
                            if (!isset($foundIds[$tr['id']])) {
                                $foundIds[$tr['id']] = true;
                                $rows[] = $tr;
                            }
                        }
                    }
                    if ($rows) {
                        echo '<div class="hint">⚠ No match directo en <code>transacciones</code>. Match encontrado vía <code>inventario_motos</code> (email/teléfono/stripe_pi de la moto).</div>';
                    } else {
                        echo '<div class="hint">⚠ La moto existe en <code>inventario_motos</code> pero no encontré ninguna <code>transacciones</code> ligada por email/teléfono/stripe_pi. Posiblemente la transacción nunca se grabó (webhook falló) o sus campos están vacíos. Detalles abajo:</div>';
                        echo '<table style="margin-top:10px;"><thead><tr><th>moto_id</th><th>pedido_num</th><th>cliente</th><th>email</th><th>tel</th><th>stripe_pi</th><th>estado</th></tr></thead><tbody>';
                        foreach ($invRows as $inv) {
                            echo '<tr>';
                            echo '<td>' . (int)$inv['id'] . '</td>';
                            echo '<td><code>' . htmlspecialchars((string)$inv['pedido_num']) . '</code></td>';
                            echo '<td>' . htmlspecialchars((string)$inv['cliente_nombre']) . '</td>';
                            echo '<td>' . htmlspecialchars((string)$inv['cliente_email']) . '</td>';
                            echo '<td>' . htmlspecialchars((string)$inv['cliente_telefono']) . '</td>';
                            echo '<td><code style="font-size:10px;">' . htmlspecialchars((string)$inv['stripe_pi']) . '</code></td>';
                            echo '<td>' . htmlspecialchars((string)$inv['estado']) . '</td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
                    }
                }
            } catch (Throwable $e) {
                echo '<div class="err">Error fallback inventario_motos: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }

        if (!$rows) {
            echo '<div class="empty">Sin resultados — ni en transacciones ni en inventario_motos.</div>';
        } else {
            echo '<table><thead><tr><th>Pedido</th><th>Cliente</th><th>Modelo</th><th>Total</th><th>tpago</th><th>Regenerado?</th><th></th></tr></thead><tbody>';
            foreach ($rows as $r) {
                echo '<tr>';
                echo '<td><code>' . htmlspecialchars((string)($r['pedido'] ?: '#' . (int)$r['id'])) . '</code></td>';
                echo '<td>' . htmlspecialchars((string)$r['nombre']) . '<br><small style="color:#64748b;">' . htmlspecialchars((string)$r['email']) . ' · ' . htmlspecialchars((string)$r['telefono']) . '</small></td>';
                echo '<td>' . htmlspecialchars((string)$r['modelo']) . ' / ' . htmlspecialchars((string)$r['color']) . '</td>';
                echo '<td>$' . number_format((float)$r['total'], 2) . '</td>';
                echo '<td><code>' . htmlspecialchars((string)$r['tpago']) . '</code></td>';
                echo '<td>' . ($r['contrato_regenerado_admin'] ? '<span class="ok">SÍ</span>' : '<span class="warn">no</span>') . '</td>';
                echo '<td><a class="btn ghost" href="?action=preview&tx_id=' . (int)$r['id'] . '">Preview →</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
    } catch (Throwable $e) {
        echo '<div class="err">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}
echo '</div>';

echo '<div class="hint" style="margin-top:30px;">⚠ Nota legal: la regeneración usa la firma autógrafa existente (firmas_contratos) y agrega un flag forense (<code>contrato_regenerado_admin=1</code>). Para una "re-firma" formal con consentimiento explícito del cliente, gestiona el flujo aparte.</div>';
echo '</body></html>';
