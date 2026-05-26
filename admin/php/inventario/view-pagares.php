<?php
/**
 * Voltika Admin — Lista y visor de PAGARÉs generados.
 *
 * Customer brief (Óscar, 2026-05-26): "Can you share me the screen of the PAGARE?"
 * Antes había backend (`serve-pagare.php`) pero ningún botón en el admin para
 * navegar a la lista de PAGARÉs y verlos. Este endpoint resuelve eso:
 *
 *   1. Lista todos los PAGARÉs con su moto + cliente + estado Cincel
 *   2. Click "Ver PDF" → abre el PDF inline
 *   3. Marca visualmente si tiene sello Cincel NOM-151 o no (Round 96 fix)
 *
 * Auth: admin/cedis.
 */

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin', 'cedis']);

$pdo = getDB();

// Ensure forensic columns exist (idempotent). Same columns generar-pagare.php
// creates on demand — but if no pagaré has been generated since Round 96
// deployed, the SELECT below would fail with "Unknown column".
foreach ([
    'cincel_pagare_timestamp_hash' => "CHAR(64) NULL",
    'cincel_pagare_status'         => "VARCHAR(40) NULL",
] as $col => $def) {
    try { $pdo->exec("ALTER TABLE checklist_entrega_v2 ADD COLUMN $col $def"); } catch (Throwable $e) {}
}

$serveFile = (string)($_GET['f'] ?? '');

// ──────────────────────────────────────────────────────────────────────────
// MODE: serve inline PDF when ?f= is provided
// ──────────────────────────────────────────────────────────────────────────
if ($serveFile !== '') {
    $filename = basename($serveFile);
    if (!preg_match('/^pagare_moto\d+_\d{8}_\d{6}\.pdf$/', $filename)) {
        http_response_code(400);
        exit('Nombre de archivo inválido');
    }
    $storageDir = sys_get_temp_dir() . '/voltika_pagares/';
    $filePath = $storageDir . $filename;
    if (!file_exists($filePath)) {
        // Fallback to other known dirs
        foreach ([
            __DIR__ . '/../../../configurador/php/uploads/pagares/',
            __DIR__ . '/../../../configurador/php/pagares/',
        ] as $dir) {
            if (file_exists($dir . $filename)) { $filePath = $dir . $filename; break; }
        }
    }
    if (!file_exists($filePath)) {
        http_response_code(404);
        exit('PDF no encontrado en disco');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    readfile($filePath);
    exit;
}

// ──────────────────────────────────────────────────────────────────────────
// MODE: list all PAGARÉs
// ──────────────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>PAGARÉs — Voltika</title>';
echo '<style>
body{font-family:system-ui,-apple-system,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:1280px;margin:0 auto;line-height:1.5;}
h1{font-size:22px;margin:0 0 4px;}
h2{font-size:16px;color:#475569;margin:24px 0 8px;border-bottom:1px solid #cbd5e1;padding-bottom:4px;}
.sec{background:#fff;padding:14px 16px;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:14px;}
table{width:100%;border-collapse:collapse;font-size:12.5px;}
th{background:#f1f5f9;text-align:left;padding:8px 10px;font-size:11.5px;}
td{padding:8px 10px;border-top:1px solid #f1f5f9;vertical-align:top;}
.empty{color:#94a3b8;font-style:italic;font-size:13px;}
.btn{padding:6px 12px;background:#039fe1;color:#fff;border:0;border-radius:5px;cursor:pointer;font-size:12px;font-weight:600;text-decoration:none;display:inline-block;}
.btn.ghost{background:#fff;color:#0c2340;border:1px solid #cbd5e1;}
.ok{color:#15803d;font-weight:700;}
.warn{color:#a16207;font-weight:700;}
code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:11px;font-family:ui-monospace,monospace;}
.hint{background:#fef9c3;border:1px solid #facc15;padding:10px 14px;border-radius:8px;margin:10px 0;font-size:13px;}
</style></head><body>';
echo '<h1>📜 PAGARÉs generados</h1>';
echo '<p style="color:#64748b;font-size:13px;margin-top:0;">Documentos PDF de pagaré ejecutivo (Art. 170-173 Ley General de Títulos y Operaciones de Crédito) generados desde el flujo de entrega.</p>';

try {
    $rows = $pdo->query("
        SELECT ce.id AS checklist_id, ce.moto_id, ce.pagare_pdf_path, ce.pagare_pdf_hash,
               ce.pagare_ip, ce.cincel_pagare_timestamp_hash, ce.cincel_pagare_status,
               ce.fase3_completada, ce.completado, ce.freg AS checklist_freg,
               m.vin_display, m.vin, m.modelo, m.color, m.estado,
               m.cliente_nombre, m.cliente_email, m.cliente_telefono
          FROM checklist_entrega_v2 ce
          JOIN inventario_motos m ON m.id = ce.moto_id
         WHERE ce.pagare_pdf_path IS NOT NULL
           AND ce.pagare_pdf_path <> ''
         ORDER BY ce.id DESC
         LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rows = [];
    echo '<div class="hint">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

if (!$rows) {
    echo '<div class="sec"><p class="empty">Sin PAGARÉs generados aún. Aparecerán aquí en cuanto el flujo de entrega genere el primero (admin/php/checklists/generar-pagare.php).</p></div>';
} else {
    echo '<div class="sec">';
    echo '<table><thead><tr>';
    echo '<th>Moto</th>';
    echo '<th>Cliente</th>';
    echo '<th>Hash PDF</th>';
    echo '<th>Sello NOM-151</th>';
    echo '<th>Estado entrega</th>';
    echo '<th>Acción</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $vin = $r['vin_display'] ?: $r['vin'];
        $hasCincel = !empty($r['cincel_pagare_timestamp_hash']);
        echo '<tr>';
        echo '<td><strong>' . htmlspecialchars((string)$r['modelo']) . ' ' . htmlspecialchars((string)$r['color']) . '</strong><br>';
        echo '<small><code>' . htmlspecialchars((string)$vin) . '</code></small><br>';
        echo '<small style="color:#64748b;">moto_id: ' . (int)$r['moto_id'] . '</small></td>';
        echo '<td>' . htmlspecialchars((string)$r['cliente_nombre']) . '<br>';
        echo '<small style="color:#64748b;">' . htmlspecialchars((string)$r['cliente_email']) . '<br>' . htmlspecialchars((string)$r['cliente_telefono']) . '</small></td>';
        echo '<td><code style="font-size:9.5px;">' . htmlspecialchars(substr((string)$r['pagare_pdf_hash'], 0, 16) . '…') . '</code></td>';
        echo '<td>' . ($hasCincel
            ? '<span class="ok">✓ Sellado</span><br><code style="font-size:9.5px;">' . htmlspecialchars(substr((string)$r['cincel_pagare_timestamp_hash'], 0, 16) . '…') . '</code>'
            : '<span class="warn">⚠ Sin sello</span>') . '</td>';
        echo '<td>' . htmlspecialchars((string)$r['estado']) . '</td>';
        echo '<td>';
        echo '<a class="btn" href="?f=' . urlencode((string)$r['pagare_pdf_path']) . '" target="_blank">📄 Ver PDF</a>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
}

echo '<div class="hint">'
   . '<strong>Nota:</strong> los PAGARÉs con <span class="warn">⚠ Sin sello</span> se generaron antes de Round 96 (2026-05-26). Para sellarlos retroactivamente, re-ejecuta <code>admin/php/checklists/generar-pagare.php</code> con su moto_id — es idempotente y solo agrega el sello Cincel.'
   . '</div>';

echo '</body></html>';
