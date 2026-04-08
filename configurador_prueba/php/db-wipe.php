<?php
$secret = $_GET['key'] ?? '';
if ($secret !== 'voltika-wipe-2026') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/config.php';

// dealer_usuarios is intentionally excluded — login accounts must be preserved
$tables = [
    'inventario_motos',
    'ventas_log',
    'checklist_entrega',
    'checklist_entrega_v2',
    'checklist_ensamble',
    'checklist_origen',
    'referidos',
    'pedidos',
    'transacciones',
    'facturacion',
    'verificaciones_identidad',
    'consultas_buro',
    'preaprobaciones',
];

$pdo = getDB();
$results = [];

$pdo->exec("SET FOREIGN_KEY_CHECKS=0;");
foreach ($tables as $table) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->execute([$table]);
    if (!(int)$stmt->fetchColumn()) {
        $results[] = ['table' => $table, 'status' => 'skip', 'msg' => 'no existe'];
        continue;
    }
    $rows = (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    $pdo->exec("TRUNCATE TABLE `$table`");
    $results[] = ['table' => $table, 'status' => 'ok', 'msg' => $rows . ' filas eliminadas'];
}
$pdo->exec("SET FOREIGN_KEY_CHECKS=1;");

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Voltika DB Wipe</title>
<style>
body{font-family:Arial,sans-serif;max-width:560px;margin:60px auto;padding:0 20px;}
.ok{color:#22C55E;font-weight:700;}
.skip{color:#f59e0b;font-weight:700;}
table{width:100%;border-collapse:collapse;margin-top:16px;}
th,td{padding:10px 14px;border:1px solid #ddd;text-align:left;font-size:14px;}
th{background:#f5f5f5;}
.warn{margin-top:20px;padding:12px;background:#FFF3CD;border-radius:8px;font-size:13px;color:#856404;}
.safe{margin-top:10px;padding:12px;background:#d1fae5;border-radius:8px;font-size:13px;color:#065f46;}
</style></head><body>
<h2>&#128465; Voltika DB — Datos eliminados</h2>
<div class="safe">&#10004; <strong>dealer_usuarios</strong> conservado — login intacto</div>
<table>
<tr><th>Tabla</th><th>Resultado</th></tr>
<?php foreach ($results as $r): ?>
<tr>
  <td><strong><?= htmlspecialchars($r['table']) ?></strong></td>
  <td class="<?= $r['status'] ?>"><?= htmlspecialchars($r['msg']) ?></td>
</tr>
<?php endforeach; ?>
</table>
<div class="warn">&#9888; <strong>Elimina este archivo del servidor inmediatamente después de usarlo.</strong></div>
</body></html>
