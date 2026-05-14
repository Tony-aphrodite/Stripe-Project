<?php
/**
 * Voltika Admin — Round 21 v3 (2026-05-14).
 *
 * Quick diagnostic: list customers that DO have INE/selfie photos
 * captured (verificaciones_identidad.files_saved is a non-empty JSON
 * array). Used to find a test target for the Identidad photo display
 * flow when the customer at hand (e.g. Carlos Ricardo VK-1826-0001)
 * was rejected by Truora and therefore has no documents.
 *
 * GET /admin/php/preaprobaciones/clientes-con-fotos.php?limit=20
 *   → HTML table (default) OR ?format=json for raw rows.
 *
 * Columns shown: telefono, email, nombre, files_saved photo count,
 * truora_status, approved, last update, and a one-click "Abrir en
 * Ventas" link to jump straight to the order in admin.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$limit  = max(1, min(200, (int)($_GET['limit'] ?? 30)));
$format = strtolower((string)($_GET['format'] ?? 'html'));

$pdo = getDB();

try {
    // The matching rows have a non-NULL, non-'[]' files_saved JSON.
    // We can't trust strlen alone (JSON whitespace varies), so use a
    // loose LIKE for a leading '"' inside the array — i.e. at least one
    // filename present.
    $sql = "
        SELECT
            vi.id                AS verif_id,
            vi.telefono,
            vi.email,
            CONCAT_WS(' ', vi.nombre, vi.apellidos) AS nombre,
            vi.truora_status,
            vi.approved,
            vi.truora_process_id,
            vi.truora_account_id,
            vi.files_saved,
            vi.freg,
            vi.truora_updated_at,
            (SELECT t.pedido_corto FROM transacciones t
              WHERE t.telefono = vi.telefono OR t.email = vi.email
              ORDER BY t.id DESC LIMIT 1)              AS pedido_corto,
            (SELECT t.id FROM transacciones t
              WHERE t.telefono = vi.telefono OR t.email = vi.email
              ORDER BY t.id DESC LIMIT 1)              AS tx_id
        FROM verificaciones_identidad vi
        WHERE vi.files_saved IS NOT NULL
          AND vi.files_saved <> ''
          AND vi.files_saved <> '[]'
          AND vi.files_saved LIKE '%\"%'
        ORDER BY vi.id DESC
        LIMIT $limit
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Decode files_saved + classify each photo by suffix.
    foreach ($rows as &$r) {
        $files = @json_decode((string)($r['files_saved'] ?? ''), true);
        $r['photos'] = ['selfie' => null, 'ine_frente' => null, 'ine_reverso' => null];
        $r['photos_count'] = 0;
        if (is_array($files)) {
            foreach ($files as $fn) {
                if (!is_string($fn)) continue;
                $r['photos_count']++;
                $l = strtolower($fn);
                if (strpos($l, '_selfie')      !== false) $r['photos']['selfie']      = $fn;
                if (strpos($l, '_ine_frente')  !== false) $r['photos']['ine_frente']  = $fn;
                if (strpos($l, '_ine_reverso') !== false) $r['photos']['ine_reverso'] = $fn;
            }
        }
    }
    unset($r);
} catch (Throwable $e) {
    if ($format === 'json') {
        adminJsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    http_response_code(500);
    echo "<pre>Error: " . htmlspecialchars($e->getMessage()) . "</pre>";
    exit;
}

if ($format === 'json') {
    adminJsonOut(['ok' => true, 'rows' => $rows, 'total' => count($rows)]);
}

// ── HTML render ──────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=UTF-8');

$esc = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8">
<title>Clientes con fotos INE/Selfie — diagnóstico</title>
<style>
body{font-family:system-ui,sans-serif;background:#f5f7fb;color:#0c2340;padding:20px;}
h1{font-size:20px;margin:0 0 6px;}
.sub{color:#64748b;font-size:13px;margin-bottom:18px;}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);}
th{text-align:left;padding:10px 12px;background:#1a3a5c;color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:.4px;}
td{padding:10px 12px;font-size:13px;border-bottom:1px solid #f1f5f9;vertical-align:top;}
tr:last-child td{border-bottom:0;}
.thumb{display:inline-block;width:48px;height:48px;border-radius:6px;overflow:hidden;border:1px solid #cbd5e1;margin-right:4px;vertical-align:middle;}
.thumb img{width:100%;height:100%;object-fit:cover;}
.ok{color:#16a34a;font-weight:700;}
.bad{color:#b91c1c;font-weight:700;}
.muted{color:#94a3b8;}
a{color:#0ea5e9;text-decoration:none;font-weight:600;}
a:hover{text-decoration:underline;}
.empty{padding:40px;text-align:center;color:#64748b;background:#fff;border-radius:10px;}
.notice{padding:12px 14px;background:#dbeafe;border:1px solid #93c5fd;color:#1e40af;border-radius:8px;font-size:13px;margin-bottom:14px;line-height:1.5;}
</style></head><body>

<h1>📷 Clientes con fotos INE / Selfie</h1>
<div class="sub">Diagnóstico Round 21 — encontrar un cliente para probar la visualización de fotos en la sección Identidad.</div>

<?php if (empty($rows)): ?>
  <div class="empty">
    <strong>No hay ningún cliente con fotos guardadas en <code>verificaciones_identidad.files_saved</code>.</strong><br><br>
    Esto significa que ningún cliente ha completado exitosamente el flujo Truora con captura de documentos, o que la columna nunca se ha llenado.<br>
    Para probar: hacer un crédito de prueba en voltika.mx con un INE válido — Truora capturará y subirá las fotos a <code>configurador/php/uploads/</code>.
  </div>
<?php else: ?>
  <div class="notice">
    <strong>Total: <?= count($rows) ?> cliente<?= count($rows)===1?'':'s' ?> con fotos.</strong><br>
    Click "Abrir en Ventas" para ir directamente al pedido del cliente y verificar la sección "Identidad (INE / PASSPORT)" del modal Documentos.
  </div>
  <table>
    <thead><tr>
      <th>#</th>
      <th>Cliente</th>
      <th>Teléfono</th>
      <th>Email</th>
      <th>Truora</th>
      <th>Fotos</th>
      <th>Última actualización</th>
      <th>Acción</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <?php
        $approved = $r['approved'];
        $approvedTxt = ($approved == 1) ? '<span class="ok">✓ Aprobado</span>'
                     : ($approved === 0 || $approved === '0' ? '<span class="bad">✗ Rechazado</span>'
                     : '<span class="muted">—</span>');
        $thumbs = '';
        foreach (['ine_frente','ine_reverso','selfie'] as $k) {
          $fn = $r['photos'][$k] ?? null;
          if ($fn) {
            $url = '/configurador/php/uploads/' . rawurlencode($fn);
            $thumbs .= '<a href="'.$url.'" target="_blank" class="thumb"><img src="'.$url.'" alt="'.$k.'"></a>';
          }
        }
        $abrirUrl = $r['pedido_corto']
            ? '/admin/#ventas?q=' . rawurlencode($r['pedido_corto'])
            : ($r['tx_id'] ? '/admin/#ventas?q=' . (int)$r['tx_id'] : '/admin/#ventas');
      ?>
      <tr>
        <td><?= $esc($r['verif_id']) ?></td>
        <td><strong><?= $esc(trim($r['nombre'])) ?></strong></td>
        <td><?= $esc($r['telefono']) ?></td>
        <td><?= $esc($r['email']) ?></td>
        <td><?= $approvedTxt ?><br><span class="muted" style="font-size:11px;"><?= $esc($r['truora_status'] ?? '—') ?></span></td>
        <td><?= $thumbs ?: '<span class="muted">—</span>' ?><br><span class="muted" style="font-size:11px;"><?= (int)$r['photos_count'] ?> archivos</span></td>
        <td style="font-size:11px;font-family:ui-monospace,monospace;color:#475569;"><?= $esc($r['truora_updated_at'] ?: $r['freg']) ?></td>
        <td><a href="<?= $esc($abrirUrl) ?>">Abrir en Ventas →</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<div style="margin-top:18px;font-size:11px;color:#94a3b8;">
  Tip: si necesitas el JSON crudo, agrega <code>?format=json</code> a la URL.
</div>
</body></html>
