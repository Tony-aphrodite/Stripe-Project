<?php
/**
 * Voltika Admin — Quick check: does this customer have a CDC (Círculo de
 * Crédito) query record? Returns just the consultas_buro data.
 *
 * Usage: ?moto_id=142
 */
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$motoId = (int)($_GET['moto_id'] ?? 0);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo '<!doctype html><html><head><meta charset="utf-8"><title>CDC check</title>';
echo '<style>
body{font-family:system-ui,sans-serif;background:#f0f4f8;color:#0c2340;padding:20px;max-width:900px;margin:0 auto;line-height:1.5;}
h1{font-size:20px;margin:0 0 4px;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px;margin-bottom:12px;}
table{border-collapse:collapse;width:100%;font-size:12.5px;}
th{background:#f1f5f9;padding:6px 9px;text-align:left;font-size:11px;}
td{padding:6px 9px;border-top:1px solid #f1f5f9;}
.lbl{font-weight:600;color:#0c2340;width:220px;background:#f8fafc;}
.has{background:#dcfce7;}
.banner{padding:12px 14px;border-radius:8px;font-size:14px;margin-bottom:14px;font-weight:600;}
.banner-ok{background:#dcfce7;border:1px solid #86efac;color:#166534;}
.banner-warn{background:#fef3c7;border:1px solid #fcd34d;color:#92400e;}
.banner-err{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;}
input{padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px;}
button{padding:6px 14px;background:#039fe1;color:#fff;border:0;border-radius:4px;cursor:pointer;font-weight:600;}
</style></head><body>';
echo '<h1>🔍 CDC (Círculo de Crédito) check</h1>';
echo '<p style="color:#64748b;font-size:12.5px;margin-top:0;">Verifica si el cliente tiene un registro en consultas_buro con su RFC completo + dirección.</p>';
echo '<form method="get" style="margin-bottom:14px;"><label>moto_id:</label> '
   . '<input type="number" name="moto_id" value="' . htmlspecialchars((string)$motoId) . '" required> '
   . '<button>Buscar</button></form>';

if (!$motoId) { echo '</body></html>'; exit; }

$st = $pdo->prepare("SELECT cliente_nombre, cliente_email, cliente_telefono FROM inventario_motos WHERE id = ?");
$st->execute([$motoId]);
$moto = $st->fetch(PDO::FETCH_ASSOC);
if (!$moto) { echo '<div class="banner banner-err">Moto no encontrada</div></body></html>'; exit; }

$nombre = (string)($moto['cliente_nombre'] ?? '');
$firstName = strtoupper(trim(explode(' ', $nombre)[0] ?? ''));

echo '<div class="card"><strong>Cliente:</strong> ' . htmlspecialchars($nombre) . '<br>'
   . '<strong>Email:</strong> ' . htmlspecialchars($moto['cliente_email'] ?? '') . '<br>'
   . '<strong>Teléfono:</strong> ' . htmlspecialchars($moto['cliente_telefono'] ?? '') . '<br>'
   . '<strong>Buscando en consultas_buro por nombre:</strong> ' . htmlspecialchars($firstName) . '...</div>';

try {
    $q = $pdo->prepare("SELECT * FROM consultas_buro
        WHERE nombre LIKE ? OR CONCAT(nombre,' ',apellido_paterno,' ',apellido_materno) LIKE ?
        ORDER BY id DESC LIMIT 5");
    $q->execute(['%' . $firstName . '%', '%' . strtoupper($nombre) . '%']);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo '<div class="banner banner-err">Error: tabla consultas_buro no existe o error de query: ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '</body></html>'; exit;
}

if (!$rows) {
    echo '<div class="banner banner-warn">⚠ Sin registros CDC para este cliente. El sistema usará RFC derivado del CURP (10 chars sin homoclave).</div>';
    echo '</body></html>'; exit;
}

echo '<div class="banner banner-ok">✓ Encontrados ' . count($rows) . ' registro(s) CDC. Datos disponibles:</div>';

$keyCols = ['id','freg','nombre','apellido_paterno','apellido_materno','fecha_nacimiento',
            'curp','rfc','calle_numero','colonia','municipio','ciudad','estado','cp','score','folio_consulta'];
foreach ($rows as $r) {
    echo '<div class="card"><h3 style="font-size:14px;margin:0 0 8px;">id=' . (int)$r['id'] . ' · ' . htmlspecialchars((string)$r['freg']) . '</h3><table>';
    foreach ($keyCols as $k) {
        if (!isset($r[$k])) continue;
        $v = (string)$r[$k];
        $has = $v !== '';
        echo '<tr><td class="lbl">' . htmlspecialchars($k) . '</td>'
           . '<td' . ($has ? ' class="has"' : '') . '>' . htmlspecialchars($v ?: '(vacío)') . '</td></tr>';
    }
    echo '</table></div>';
}
echo '</body></html>';
