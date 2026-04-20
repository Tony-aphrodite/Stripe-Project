<?php
/**
 * Cleanup — remove BURO-SEED% rows from consultas_buro.
 *
 * Rationale: Client reported that the "Consultas Buro de Credito" admin view
 * showed a row "BURO-SEED-001 / Carlos Lopez Hernandez" which is seed/test
 * data, not a real consulta. This script removes all rows whose
 * folio_consulta starts with "BURO-SEED".
 *
 * Usage:
 *   GET  /admin_test/reparar-buro-seed.php         -> preview rows (dry run)
 *   POST /admin_test/reparar-buro-seed.php?run=1   -> apply DELETE
 */
require_once __DIR__ . '/php/bootstrap.php';
adminRequireAuth(['admin']);

$pdo = getDB();
$isRun = ($_SERVER['REQUEST_METHOD'] === 'POST') && (($_GET['run'] ?? '') === '1');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Voltika — Limpiar seed BURO-SEED de consultas_buro</title>
<style>
  body { font-family: system-ui, sans-serif; max-width: 960px; margin: 2rem auto; padding: 0 1rem; background: #f7f8fa; color:#1a3a5c; }
  h1 { font-size: 1.3rem; }
  .card { background: #fff; border-radius: 10px; padding: 1.2rem; box-shadow: 0 2px 8px rgba(0,0,0,.06); margin-bottom: 1rem; }
  button { padding: .7rem 1.4rem; border: none; border-radius: 7px; font-size: 1rem; font-weight: 700; cursor: pointer; margin-right: .5rem; }
  .btn-run { background: #c41e3a; color: #fff; }
  .ok   { background: #e6f4ea; color: #1e7e34; padding:1rem;border-radius:8px;font-weight:700; }
  .warn { background: #fffbe6; color: #ad6800; padding:1rem;border-radius:8px; }
  code { font-family: ui-monospace, Consolas, monospace; background: #f5f5f5; padding: 1px 6px; border-radius: 3px; }
  table { width: 100%; border-collapse: collapse; font-size: .88rem; margin-top:8px; }
  th, td { text-align: left; padding: .4rem .6rem; border-bottom: 1px solid #eee; }
  th { background: #fafafa; }
</style>
</head>
<body>
<h1>Eliminar filas seed <code>folio_consulta LIKE 'BURO-SEED%'</code> en <code>consultas_buro</code></h1>

<?php
try {
    $countStmt = $pdo->query("SELECT COUNT(*) FROM consultas_buro WHERE folio_consulta LIKE 'BURO-SEED%'");
    $pendientes = (int)$countStmt->fetchColumn();

    $sampleStmt = $pdo->query("
        SELECT id, folio_consulta, nombre, apellido_paterno, apellido_materno,
               score, tipo_consulta, freg
        FROM consultas_buro
        WHERE folio_consulta LIKE 'BURO-SEED%'
        ORDER BY id DESC
        LIMIT 50
    ");
    $sample = $sampleStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($isRun) {
        $del = $pdo->prepare("DELETE FROM consultas_buro WHERE folio_consulta LIKE 'BURO-SEED%'");
        $del->execute();
        $affected = $del->rowCount();
        adminLog('buro_seed_cleanup', ['rows_deleted' => $affected]);
        echo '<div class="ok">Limpieza aplicada. Filas eliminadas: <strong>' . $affected . '</strong></div>';
        echo '<p><a href="reparar-buro-seed.php">Volver a verificar</a></p>';
    } else {
        echo '<div class="card">';
        echo '<p>Filas actuales con <code>folio_consulta LIKE \'BURO-SEED%\'</code>: <strong>' . $pendientes . '</strong></p>';
        if ($pendientes > 0) {
            echo '<form method="POST" action="reparar-buro-seed.php?run=1" '
               . 'onsubmit="return confirm(\'¿Eliminar ' . $pendientes . ' fila(s) seed de consultas_buro? Esta accion es irreversible.\');">';
            echo '<button class="btn-run" type="submit">Eliminar filas seed ahora</button>';
            echo '</form>';
            if (!empty($sample)) {
                echo '<h3 style="margin-top:18px">Vista previa (hasta 50 filas):</h3>';
                echo '<table><thead><tr>'
                   . '<th>ID</th><th>Folio CDC</th><th>Nombre</th>'
                   . '<th>Score</th><th>Tipo</th><th>Fecha registro</th>'
                   . '</tr></thead><tbody>';
                foreach ($sample as $r) {
                    $nombre = trim(($r['nombre'] ?? '') . ' ' . ($r['apellido_paterno'] ?? '') . ' ' . ($r['apellido_materno'] ?? ''));
                    echo '<tr>'
                       . '<td>' . (int)$r['id'] . '</td>'
                       . '<td>' . htmlspecialchars($r['folio_consulta'] ?? '') . '</td>'
                       . '<td>' . htmlspecialchars($nombre) . '</td>'
                       . '<td>' . htmlspecialchars((string)($r['score'] ?? '')) . '</td>'
                       . '<td>' . htmlspecialchars($r['tipo_consulta'] ?? '') . '</td>'
                       . '<td>' . htmlspecialchars($r['freg'] ?? '') . '</td>'
                       . '</tr>';
                }
                echo '</tbody></table>';
            }
        } else {
            echo '<div class="ok">No hay filas seed con <code>folio_consulta LIKE \'BURO-SEED%\'</code>. Nada que limpiar.</div>';
        }
        echo '</div>';
    }
} catch (Throwable $e) {
    echo '<div class="warn"><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>
</body>
</html>
