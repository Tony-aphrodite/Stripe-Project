<?php
/**
 * POST — Create or update a Punto Voltika
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin']);

$d = adminJsonIn();
$pdo = getDB();
$id = (int)($d['id'] ?? 0);

$fields = ['nombre','direccion','ciudad','estado','cp','telefono','email','lat','lng','horarios','capacidad','activo'];
$vals = [];
foreach ($fields as $f) {
    $vals[$f] = $d[$f] ?? null;
}

if ($id) {
    // Update
    $sets = []; $params = [];
    foreach ($vals as $k => $v) { $sets[] = "$k=?"; $params[] = $v; }
    $params[] = $id;
    $pdo->prepare("UPDATE puntos_voltika SET " . implode(',', $sets) . " WHERE id=?")->execute($params);
    adminLog('punto_actualizar', ['punto_id' => $id]);
} else {
    // Create with unique referral codes
    $codigoVenta = 'PV' . strtoupper(substr(md5(uniqid()), 0, 6));
    $codigoElec  = 'PE' . strtoupper(substr(md5(uniqid()), 0, 6));

    $cols = array_keys($vals);
    $cols[] = 'codigo_venta'; $vals['codigo_venta'] = $codigoVenta;
    $cols[] = 'codigo_electronico'; $vals['codigo_electronico'] = $codigoElec;

    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $pdo->prepare("INSERT INTO puntos_voltika (" . implode(',', $cols) . ") VALUES ($placeholders)")
        ->execute(array_values($vals));
    $id = $pdo->lastInsertId();
    adminLog('punto_crear', ['punto_id' => $id, 'nombre' => $vals['nombre']]);
}

adminJsonOut(['ok' => true, 'punto_id' => $id]);
