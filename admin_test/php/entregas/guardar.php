<?php
/**
 * POST — Create/update delivery time configuration
 * Body: { id?, modelo, ciudad, dias_estimados, disponible_inmediato, notas }
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin']);

$d = adminJsonIn();
$pdo = getDB();

$fields = [
    'modelo'              => trim($d['modelo'] ?? ''),
    'ciudad'              => trim($d['ciudad'] ?? ''),
    'dias_estimados'      => (int)($d['dias_estimados'] ?? 7),
    'disponible_inmediato'=> (int)($d['disponible_inmediato'] ?? 0),
    'notas'               => trim($d['notas'] ?? ''),
    'activo'              => (int)($d['activo'] ?? 1),
];

if (!$fields['modelo'] && !$fields['ciudad']) adminJsonOut(['error' => 'Modelo o ciudad requerido'], 400);

$id = (int)($d['id'] ?? 0);
if ($id) {
    $sets = []; $vals = [];
    foreach ($fields as $k=>$v) { $sets[]="$k=?"; $vals[]=$v; }
    $vals[] = $id;
    $pdo->prepare("UPDATE tiempos_entrega SET ".implode(',',$sets)." WHERE id=?")->execute($vals);
    adminLog('tiempo_entrega_actualizado', ['id'=>$id]);
    adminJsonOut(['ok'=>true,'id'=>$id]);
} else {
    $cols = implode(',',array_keys($fields));
    $ph = implode(',',array_fill(0,count($fields),'?'));
    $updates = implode(',',array_map(function($k){return "$k=VALUES($k)";},array_keys($fields)));
    $pdo->prepare("INSERT INTO tiempos_entrega ({$cols}) VALUES ({$ph}) ON DUPLICATE KEY UPDATE {$updates}")
        ->execute(array_values($fields));
    adminLog('tiempo_entrega_creado', $fields);
    adminJsonOut(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
}
