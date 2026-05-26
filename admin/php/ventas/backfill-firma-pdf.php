<?php
/**
 * ONE-SHOT TOOL — Backfill contrato_pdf_path on credit transacciones rows
 * that have a matching firmas_contratos row but were never linked.
 *
 * Customer brief (Óscar, 2026-05-26): admin Ventas showed "⚠ Pagado · Falta
 * firma →" for credit customers who had already signed. Root cause was
 * Round 99: signing endpoints only updated ONE transacciones row (id-based
 * or LIMIT 1) while credit customers typically have multiple rows (Stripe
 * retries, OXXO references, enganche splits). The sibling rows kept
 * contrato_pdf_path NULL → admin's Ventas list (which requires firma_id +
 * contrato_pdf_path + paid for credit) wrongly classified them as unsigned.
 *
 * This backfill finds those orphaned rows and links them to the existing
 * signature so the admin view aligns with the customer portal view.
 *
 * Read-only preview by default. Click "Aplicar backfill" to commit.
 *
 * Auth: admin session.
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$apply = !empty($_POST['apply']) && $_SERVER['REQUEST_METHOD'] === 'POST';

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Backfill firma → contrato_pdf_path</title>';
echo '<style>
body{font-family:system-ui,-apple-system,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1280px;margin:0 auto;line-height:1.5;}
h1{font-size:22px;margin:0 0 4px;}
h2{font-size:16px;color:#475569;margin:24px 0 8px;border-bottom:1px solid #cbd5e1;padding-bottom:4px;}
.sec{background:#fff;padding:14px 16px;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:14px;}
table{width:100%;border-collapse:collapse;font-size:12.5px;}
th{background:#f1f5f9;text-align:left;padding:8px 10px;font-size:11.5px;}
td{padding:8px 10px;border-top:1px solid #f1f5f9;vertical-align:top;}
.btn{padding:8px 16px;background:#039fe1;color:#fff;border:0;border-radius:5px;cursor:pointer;font-size:13px;font-weight:600;text-decoration:none;display:inline-block;}
.btn.ghost{background:#fff;color:#0c2340;border:1px solid #cbd5e1;}
.btn.danger{background:#dc2626;}
.ok{color:#15803d;font-weight:700;}
.warn{color:#a16207;font-weight:700;}
.err{color:#b91c1c;font-weight:700;}
.success{background:#d1fae5;border:1px solid #34d399;color:#065f46;padding:14px 18px;border-radius:10px;margin:14px 0;}
.hint{background:#fef9c3;border:1px solid #facc15;padding:10px 14px;border-radius:8px;margin:10px 0;font-size:13px;}
code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:11.5px;}
</style></head><body>';
echo '<h1>🔧 Backfill — Sincronizar firma con contrato_pdf_path</h1>';
echo '<p style="color:#64748b;font-size:13px;margin-top:0;">Encuentra transacciones de crédito donde el cliente firmó (firmas_contratos existe) pero <code>transacciones.contrato_pdf_path</code> está vacío. Esto causa que admin Ventas muestre "Falta firma" aunque el cliente sí firmó.</p>';

// Find candidates
try {
    $candidates = $pdo->query("
        SELECT t.id, t.pedido, t.tpago, t.pago_estado, t.email, t.telefono,
               t.modelo, t.total, t.freg, t.contrato_pdf_path,
               f.id AS firma_id, f.pdf_file AS firma_pdf_file, f.firma_sha256, f.freg AS firma_freg
          FROM transacciones t
          JOIN firmas_contratos f
            ON (LENGTH(t.email) > 0 AND f.email = t.email)
            OR (LENGTH(t.telefono) > 0 AND f.telefono = t.telefono)
         WHERE t.tpago IN ('credito','enganche','parcial','credito-orfano')
           AND (t.contrato_pdf_path IS NULL OR t.contrato_pdf_path = '')
           AND f.pdf_file IS NOT NULL AND f.pdf_file <> ''
         ORDER BY t.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $candidates = [];
    echo '<div class="err">Error consultando candidatos: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

// Deduplicate — keep one firma per transacción (most recent)
$seen = [];
$dedup = [];
foreach ($candidates as $c) {
    if (isset($seen[$c['id']])) continue;
    $seen[$c['id']] = true;
    $dedup[] = $c;
}

if ($apply && $dedup) {
    $applied = 0;
    $errors  = [];
    foreach ($dedup as $c) {
        try {
            $relPath = 'contratos/' . basename((string)$c['firma_pdf_file']);
            $pdo->prepare("UPDATE transacciones
                SET contrato_pdf_path = ?, contrato_pdf_hash = ?
                WHERE id = ?")
                ->execute([$relPath, (string)$c['firma_sha256'], (int)$c['id']]);
            $applied++;
        } catch (Throwable $e) {
            $errors[] = 'tx_id=' . (int)$c['id'] . ': ' . $e->getMessage();
        }
    }
    if (function_exists('adminLog')) {
        adminLog('ventas_backfill_firma_pdf_path', [
            'applied' => $applied,
            'errors'  => count($errors),
        ]);
    }
    echo '<div class="success">';
    echo '<h2 style="margin-top:0;color:#065f46;border:0;">✅ Backfill aplicado</h2>';
    echo 'Filas actualizadas: <strong>' . $applied . '</strong>';
    if ($errors) {
        echo '<br>Errores (' . count($errors) . '):<ul>';
        foreach ($errors as $e) echo '<li><code>' . htmlspecialchars($e) . '</code></li>';
        echo '</ul>';
    }
    echo '</div>';
    echo '<p><a class="btn ghost" href="?">← Re-evaluar</a></p>';
    echo '</body></html>';
    exit;
}

echo '<div class="sec">';
echo '<h2>Candidatos detectados</h2>';
if (!$dedup) {
    echo '<p class="ok">✓ Sin candidatos. Todas las transacciones de crédito firmadas tienen contrato_pdf_path correctamente vinculado.</p>';
} else {
    echo '<div class="hint">Se encontraron <strong>' . count($dedup) . '</strong> transacciones de crédito con firma pero sin <code>contrato_pdf_path</code>. Aplicar el backfill linkea cada una a su firma existente.</div>';
    echo '<table><thead><tr><th>tx_id</th><th>Pedido</th><th>Cliente</th><th>tpago</th><th>pago_estado</th><th>Modelo</th><th>firma_id</th><th>firma fecha</th><th>PDF a vincular</th></tr></thead><tbody>';
    foreach ($dedup as $c) {
        echo '<tr>';
        echo '<td><code>' . (int)$c['id'] . '</code></td>';
        echo '<td><code>' . htmlspecialchars((string)$c['pedido']) . '</code></td>';
        echo '<td>' . htmlspecialchars((string)$c['email']) . '<br><small>' . htmlspecialchars((string)$c['telefono']) . '</small></td>';
        echo '<td>' . htmlspecialchars((string)$c['tpago']) . '</td>';
        echo '<td>' . htmlspecialchars((string)$c['pago_estado']) . '</td>';
        echo '<td>' . htmlspecialchars((string)$c['modelo']) . '</td>';
        echo '<td><code>' . (int)$c['firma_id'] . '</code></td>';
        echo '<td>' . htmlspecialchars(substr((string)$c['firma_freg'], 0, 10)) . '</td>';
        echo '<td><code style="font-size:10px;">' . htmlspecialchars((string)$c['firma_pdf_file']) . '</code></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    echo '<form method="post" style="margin-top:18px;">';
    echo '<input type="hidden" name="apply" value="1">';
    echo '<button type="submit" class="btn" onclick="return confirm(\'¿Aplicar backfill a ' . count($dedup) . ' transacciones?\')">▶ Aplicar backfill</button>';
    echo '<a class="btn ghost" href="?" style="margin-left:8px;">Cancelar</a>';
    echo '</form>';
}
echo '</div>';
echo '</body></html>';
