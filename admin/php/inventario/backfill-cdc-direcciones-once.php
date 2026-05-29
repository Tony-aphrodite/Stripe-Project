<?php
/**
 * Voltika Admin — One-shot backfill of address fields in consultas_buro
 * by re-parsing the stored raw_response from CDC.
 *
 * Customer brief 2026-05-29: many consultas_buro rows have empty address
 * columns (calle_numero, colonia, municipio, ciudad, estado, cp) because
 * the original save logic only persisted what the configurador sent in the
 * REQUEST, not what CDC returned. CDC stores the canonical address in its
 * domicilios array. The raw_response is preserved in consultas_buro.raw_response
 * so we can re-parse and backfill retroactively.
 *
 * Workflow:
 *   1. Preview: count rows with empty address but non-empty raw_response
 *   2. Click "Backfill" to extract address from each row's raw_response
 *      and UPDATE the columns
 *
 * Auth: admin only. Idempotent — safe to re-run.
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'preview');

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Backfill CDC direcciones</title>';
echo '<style>
body{font-family:system-ui,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:980px;margin:0 auto;line-height:1.55;}
h1{font-size:20px;margin:0 0 4px;}
h2{font-size:14px;color:#475569;margin:18px 0 8px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;margin-bottom:12px;}
table{width:100%;border-collapse:collapse;font-size:12px;}
th{background:#f1f5f9;padding:6px 9px;text-align:left;font-size:10.5px;}
td{padding:6px 9px;border-top:1px solid #f1f5f9;}
.banner{padding:11px 14px;border-radius:8px;font-size:13.5px;margin-bottom:12px;font-weight:600;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.banner-warn{background:#fef3c7;border:1px solid #fcd34d;color:#92400e;}
.banner-err{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;}
.btn{padding:8px 16px;background:#039fe1;color:#fff;border:0;border-radius:5px;cursor:pointer;font-weight:600;font-size:13px;text-decoration:none;display:inline-block;}
.btn.danger{background:#dc2626;}
.btn.ghost{background:#fff;color:#0c2340;border:1px solid #cbd5e1;}
.ok{color:#15803d;font-weight:700;}
.warn{color:#a16207;}
.muted{color:#94a3b8;}
</style></head><body>';
echo '<h1>🔄 Backfill CDC direcciones</h1>';
echo '<p style="color:#64748b;font-size:12.5px;margin-top:0;">Re-extrae las direcciones del raw_response de CDC para filas con address columns vacías.</p>';

// Helper: extract domicilio fields from CDC raw response
function extractDomicilioFromRaw(string $raw): ?array {
    $data = json_decode($raw, true);
    if (!is_array($data)) return null;
    $candidates = [
        $data['personas'][0]['domicilios'][0] ?? null,
        $data['domicilios'][0] ?? null,
        $data['persona']['domicilios'][0] ?? null,
    ];
    foreach ($candidates as $d) {
        if (is_array($d)) {
            $pick = function(array $row, array $keys): string {
                foreach ($keys as $k) {
                    if (!empty($row[$k])) return strtoupper(trim((string)$row[$k]));
                }
                return '';
            };
            return [
                'calle_numero' => $pick($d, ['direccion','calle','calleNumero','calle_numero']),
                'colonia'      => $pick($d, ['coloniaPoblacion','colonia']),
                'municipio'    => $pick($d, ['delegacionMunicipio','municipio','delegacion']),
                'ciudad'       => $pick($d, ['ciudad']),
                'estado'       => $pick($d, ['estado']),
                'cp'           => $pick($d, ['cp','codigoPostal']),
            ];
        }
    }
    return null;
}

// Count rows needing backfill
$total = (int)$pdo->query("SELECT COUNT(*) FROM consultas_buro
    WHERE (calle_numero IS NULL OR calle_numero = '')
      AND raw_response IS NOT NULL AND raw_response != ''")->fetchColumn();

echo '<div class="card">';
echo '<strong>Filas con address vacía + raw_response disponible:</strong> ' . $total;
echo '</div>';

if ($action === 'apply' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->query("SELECT id, raw_response FROM consultas_buro
        WHERE (calle_numero IS NULL OR calle_numero = '')
          AND raw_response IS NOT NULL AND raw_response != ''
        LIMIT 500");
    $updated = 0; $noData = 0; $errors = 0;
    $upd = $pdo->prepare("UPDATE consultas_buro
        SET calle_numero = ?, colonia = ?, municipio = ?, ciudad = ?, estado = ?, cp = ?
        WHERE id = ?");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        try {
            $dom = extractDomicilioFromRaw((string)$row['raw_response']);
            if (!$dom || ($dom['calle_numero'] === '' && $dom['colonia'] === '')) {
                $noData++;
                continue;
            }
            $upd->execute([
                $dom['calle_numero'] ?: null,
                $dom['colonia'] ?: null,
                $dom['municipio'] ?: null,
                $dom['ciudad'] ?: null,
                $dom['estado'] ?: null,
                $dom['cp'] ?: null,
                (int)$row['id'],
            ]);
            if ($upd->rowCount() > 0) $updated++;
        } catch (Throwable $e) {
            $errors++;
            error_log('backfill cdc direccion id=' . $row['id'] . ': ' . $e->getMessage());
        }
    }
    if (function_exists('adminLog')) {
        adminLog('backfill_cdc_direcciones', [
            'updated' => $updated, 'no_data' => $noData, 'errors' => $errors,
            'admin_user' => $_SESSION['admin_user_id'] ?? null,
        ]);
    }
    echo '<div class="banner banner-ok">✓ Backfill completado: <strong>' . $updated . '</strong> filas actualizadas · ' . $noData . ' sin data en raw_response · ' . $errors . ' errores</div>';
    echo '<p><a class="btn ghost" href="?">Refrescar</a></p>';
}

// Preview first 10 rows that would be affected
$sample = $pdo->query("SELECT id, nombre, apellido_paterno, rfc, raw_response, freg
    FROM consultas_buro
    WHERE (calle_numero IS NULL OR calle_numero = '')
      AND raw_response IS NOT NULL AND raw_response != ''
    ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

if ($sample) {
    echo '<h2>Preview — primeros 10 candidatos</h2>';
    echo '<div class="card"><table><thead><tr><th>id</th><th>Nombre</th><th>RFC</th><th>Domicilio extraído del raw_response</th></tr></thead><tbody>';
    foreach ($sample as $r) {
        $dom = extractDomicilioFromRaw((string)$r['raw_response']);
        $extracted = $dom
            ? trim(($dom['calle_numero'] ?? '') . ' · ' . ($dom['colonia'] ?? '') . ' · ' . ($dom['municipio'] ?? '') . ' · CP ' . ($dom['cp'] ?? ''))
            : '<span class="warn">(no domicilio en raw_response)</span>';
        echo '<tr>';
        echo '<td>' . (int)$r['id'] . '</td>';
        echo '<td>' . htmlspecialchars((string)$r['nombre'] . ' ' . (string)$r['apellido_paterno']) . '</td>';
        echo '<td>' . htmlspecialchars((string)$r['rfc']) . '</td>';
        echo '<td>' . $extracted . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    if ($total > 0) {
        echo '<form method="post">';
        echo '<input type="hidden" name="action" value="apply">';
        echo '<button class="btn danger" onclick="return confirm(\'Procesar hasta 500 filas. Continuar?\')">▶ Aplicar backfill (hasta 500 filas)</button>';
        echo '</form>';
        echo '<p class="muted" style="font-size:11px;margin-top:8px;">Re-ejecuta el script si hay más de 500 filas pendientes (se procesan en lotes).</p>';
    }
} else {
    echo '<div class="banner banner-warn">⚠ Ningún registro pendiente — todos ya tienen address o no tienen raw_response para parsear.</div>';
}

echo '</body></html>';
