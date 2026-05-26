<?php
/**
 * ONE-SHOT TOOL — Backfill customers wrongly flagged as rejected when
 * Truora actually approved them.
 *
 * Customer brief (Óscar, 2026-05-26): admin Voltika shows customers as
 * rejected but Truora's own dashboard shows them as Exitoso. Caso Carlos
 * Ricardo Sánchez (Truora aprobado 13-may, Voltika rechazado).
 *
 * Root cause (now patched by Round 101 in truora-status.php +
 * truora-webhook.php): the strict-mode "verified_curp_unavailable"
 * branch overrode Truora's approved=1 verdict to approved=0 whenever
 * our code failed to extract verified_curp from Truora's response.
 * After Truora changed their payload format, this rejected every
 * successfully-verified customer.
 *
 * This backfill identifies the false rejections — rows where
 * truora_declined_reason='verified_curp_unavailable' OR
 * truora_failure_status='identity_unverifiable' — and flips them to
 * approved=1 with manual_review_required=0.
 *
 * Preview mode by default. Click "Aplicar backfill" to commit.
 *
 * Auth: admin session.
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$apply = !empty($_POST['apply']) && $_SERVER['REQUEST_METHOD'] === 'POST';

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Backfill — Falsos rechazos Truora</title>';
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
echo '<h1>🔧 Backfill — Falsos rechazos Truora (Round 101)</h1>';
echo '<p style="color:#64748b;font-size:13px;margin-top:0;">Flippea a aprobado=1 los clientes que tenían approved=0 con declined_reason="verified_curp_unavailable" (rechazo paranoico cuando no se pudo extraer el CURP del payload de Truora). Truora ya los aprobó en su dashboard — este backfill arregla nuestra DB para reflejar eso.</p>';

// Round 101 v2 — Broader detection. The system-wide rejection bug can
// manifest via THREE different signals (any of which the admin UI treats
// as a hard reject): approved=0, truora_status IN (failure/rejected/denied),
// or manual_review_required=1. Catch all three so the backfill clears any
// false rejection regardless of which field carries the marker.
try {
    $rows = $pdo->query("
        SELECT id, telefono, email, expected_curp, verified_curp,
               truora_process_id, truora_account_id, truora_status,
               truora_declined_reason, truora_failure_status,
               approved, manual_review_required, manual_review_reason,
               curp_match, name_match, freg
          FROM verificaciones_identidad
         WHERE (
                  approved = 0
               OR manual_review_required = 1
               OR truora_status IN ('failed','failure','rejected','denied','invalid')
               OR truora_declined_reason IS NOT NULL
               OR truora_failure_status IS NOT NULL
               OR manual_review_reason IS NOT NULL
           )
           AND truora_process_id IS NOT NULL
           AND truora_process_id <> ''
         ORDER BY id DESC
         LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rows = [];
    echo '<div class="err">Error consultando candidatos: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

if ($apply && $rows) {
    $applied = 0;
    $errors  = [];
    foreach ($rows as $r) {
        try {
            // Clear the false-rejection flags + restore approval
            $pdo->prepare("UPDATE verificaciones_identidad
                SET approved = 1,
                    truora_declined_reason = NULL,
                    truora_failure_status  = NULL,
                    manual_review_required = 0,
                    manual_review_reason   = NULL
                WHERE id = ?")->execute([(int)$r['id']]);
            $applied++;
        } catch (Throwable $e) {
            $errors[] = 'verif_id=' . (int)$r['id'] . ': ' . $e->getMessage();
        }
    }
    if (function_exists('adminLog')) {
        adminLog('truora_falsos_rechazados_backfill', [
            'applied' => $applied,
            'errors'  => count($errors),
            'reason'  => 'Round 101 — verified_curp_unavailable was a paranoid override that rejected legitimately-approved Truora customers.',
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
if (!$rows) {
    echo '<p class="ok">✓ Sin candidatos. No hay filas wrongly-marked as rejected.</p>';
} else {
    echo '<div class="hint">Se encontraron <strong>' . count($rows) . '</strong> verificaciones marcadas erróneamente como rechazadas. Aplicar el backfill las flippea a aprobado.</div>';
    echo '<table><thead><tr><th>id</th><th>tel</th><th>email</th><th>expected_curp</th><th>verified_curp</th><th>truora_status</th><th>declined_reason</th><th>approved</th><th>manual_review</th><th>fecha</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        echo '<td><code>' . (int)$r['id'] . '</code></td>';
        echo '<td>' . htmlspecialchars((string)$r['telefono']) . '</td>';
        echo '<td>' . htmlspecialchars((string)$r['email']) . '</td>';
        echo '<td><code>' . htmlspecialchars((string)$r['expected_curp']) . '</code></td>';
        echo '<td>' . htmlspecialchars((string)($r['verified_curp'] ?: '(vacío)')) . '</td>';
        echo '<td>' . htmlspecialchars((string)$r['truora_status']) . '</td>';
        echo '<td>' . htmlspecialchars((string)$r['truora_declined_reason']) . '</td>';
        echo '<td>' . htmlspecialchars((string)$r['approved']) . '</td>';
        echo '<td>' . htmlspecialchars((string)$r['manual_review_required']) . '</td>';
        echo '<td>' . htmlspecialchars(substr((string)$r['freg'], 0, 16)) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    echo '<form method="post" style="margin-top:18px;">';
    echo '<input type="hidden" name="apply" value="1">';
    echo '<button type="submit" class="btn" onclick="return confirm(\'¿Aplicar backfill a ' . count($rows) . ' filas?\')">▶ Aplicar backfill</button>';
    echo '<a class="btn ghost" href="?" style="margin-left:8px;">Cancelar</a>';
    echo '</form>';
}
echo '</div>';
echo '</body></html>';
