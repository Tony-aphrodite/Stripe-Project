<?php
/**
 * Quick diagnostic: shows breakdown of consultas_buro address coverage.
 * Helps determine whether backfill is possible or impossible.
 */
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><style>
body{font-family:system-ui,sans-serif;padding:20px;max-width:780px;margin:0 auto;}
table{border-collapse:collapse;width:100%;font-size:14px;}
th,td{padding:8px 10px;border-bottom:1px solid #e2e8f0;text-align:left;}
th{background:#f1f5f9;}
.num{font-family:ui-monospace,monospace;font-weight:700;text-align:right;}
</style></head><body>';
echo '<h1>📊 consultas_buro — diagnóstico de direcciones</h1>';

$tot     = (int)$pdo->query("SELECT COUNT(*) FROM consultas_buro")->fetchColumn();
$conAddr = (int)$pdo->query("SELECT COUNT(*) FROM consultas_buro WHERE calle_numero IS NOT NULL AND calle_numero != ''")->fetchColumn();
$sinAddr = $tot - $conAddr;
$conRaw  = (int)$pdo->query("SELECT COUNT(*) FROM consultas_buro WHERE raw_response IS NOT NULL AND raw_response != ''")->fetchColumn();
$sinRaw  = $tot - $conRaw;
$conRawSinAddr = (int)$pdo->query("SELECT COUNT(*) FROM consultas_buro
    WHERE (calle_numero IS NULL OR calle_numero = '')
      AND raw_response IS NOT NULL AND raw_response != ''")->fetchColumn();

echo '<table>';
echo '<tr><th>Total filas</th><td class="num">' . $tot . '</td></tr>';
echo '<tr><th>Con address llena</th><td class="num">' . $conAddr . '</td></tr>';
echo '<tr><th>Sin address</th><td class="num">' . $sinAddr . '</td></tr>';
echo '<tr><th>Con raw_response guardado</th><td class="num">' . $conRaw . '</td></tr>';
echo '<tr><th>Sin raw_response</th><td class="num">' . $sinRaw . '</td></tr>';
echo '<tr style="background:#fef9c3;"><th>Backfillable (sin address + con raw)</th><td class="num">' . $conRawSinAddr . '</td></tr>';
echo '</table>';

echo '<h2 style="font-size:14px;margin-top:20px;">Muestra de 5 filas sin address:</h2>';
$rows = $pdo->query("SELECT id, nombre, apellido_paterno, rfc,
    LENGTH(COALESCE(raw_response,'')) as raw_len,
    calle_numero, colonia, ciudad, freg
    FROM consultas_buro
    WHERE calle_numero IS NULL OR calle_numero = ''
    ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo '<table style="font-size:12px;"><tr><th>id</th><th>Nombre</th><th>RFC</th><th>raw_response bytes</th><th>address</th><th>freg</th></tr>';
foreach ($rows as $r) {
    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td>' . htmlspecialchars($r['nombre'] . ' ' . $r['apellido_paterno']) . '</td>';
    echo '<td>' . htmlspecialchars($r['rfc']) . '</td>';
    echo '<td class="num">' . (int)$r['raw_len'] . '</td>';
    echo '<td>' . htmlspecialchars((string)($r['calle_numero'] ?: '(vacío)')) . '</td>';
    echo '<td>' . htmlspecialchars((string)$r['freg']) . '</td>';
    echo '</tr>';
}
echo '</table>';
echo '</body></html>';
