<?php
/**
 * POST — Create or update a model
 * Body: { id?, nombre, categoria, precio_contado, precio_financiado, costo, bateria, velocidad, autonomia, torque, imagen_url, activo }
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin']);

$d = adminJsonIn();
$id = (int)($d['id'] ?? 0);

// Validate
if (empty($d['nombre'])) adminJsonOut(['error' => 'Nombre del modelo requerido'], 400);

$pdo = getDB();

$fields = [
    'nombre'           => trim($d['nombre']),
    'categoria'        => trim($d['categoria'] ?? ''),
    'precio_contado'   => (float)($d['precio_contado'] ?? 0),
    'precio_financiado'=> (float)($d['precio_financiado'] ?? 0),
    'costo'            => (float)($d['costo'] ?? 0),
    'bateria'          => trim($d['bateria'] ?? ''),
    'velocidad'        => trim($d['velocidad'] ?? ''),
    'autonomia'        => trim($d['autonomia'] ?? ''),
    'torque'           => trim($d['torque'] ?? ''),
    'imagen_url'       => trim($d['imagen_url'] ?? ''),
    'activo'           => (int)($d['activo'] ?? 1),
];

if ($id) {
    // Update
    $sets = [];
    $vals = [];
    foreach ($fields as $k => $v) {
        $sets[] = "$k = ?";
        $vals[] = $v;
    }
    $vals[] = $id;
    $pdo->prepare("UPDATE modelos SET " . implode(', ', $sets) . " WHERE id=?")->execute($vals);
    adminLog('modelo_actualizado', ['id' => $id, 'nombre' => $fields['nombre']]);
    adminJsonOut(['ok' => true, 'id' => $id, 'accion' => 'actualizado']);
} else {
    // Insert
    $cols = implode(', ', array_keys($fields));
    $placeholders = implode(', ', array_fill(0, count($fields), '?'));
    $pdo->prepare("INSERT INTO modelos ({$cols}) VALUES ({$placeholders})")->execute(array_values($fields));
    $newId = (int)$pdo->lastInsertId();
    adminLog('modelo_creado', ['id' => $newId, 'nombre' => $fields['nombre']]);
    adminJsonOut(['ok' => true, 'id' => $newId, 'accion' => 'creado']);
}
