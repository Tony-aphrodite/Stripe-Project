<?php
/**
 * POST — Create or update a Punto Voltika
 * Now includes configurador fields: tipo, servicios, tags, zonas, colonia, descripcion
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin']);

$d = adminJsonIn();
$pdo = getDB();
$id = (int)($d['id'] ?? 0);

$fields = ['nombre','responsable','direccion','colonia','ciudad','estado','cp','telefono','email',
           'lat','lng','horarios','capacidad','activo','tipo','descripcion','autorizado','orden'];

// Ensure responsable column exists
try {
    $pdo->exec("ALTER TABLE puntos_voltika ADD COLUMN responsable VARCHAR(200) NULL AFTER nombre");
} catch (Throwable $e) { /* already exists */ }
$vals = [];
foreach ($fields as $f) {
    $vals[$f] = $d[$f] ?? null;
}

// JSON fields
$jsonFields = ['servicios','tags','zonas'];
foreach ($jsonFields as $jf) {
    if (isset($d[$jf])) {
        $vals[$jf] = is_string($d[$jf]) ? $d[$jf] : json_encode($d[$jf], JSON_UNESCAPED_UNICODE);
    } else {
        $vals[$jf] = null;
    }
}

// Generate slug from nombre if not provided
$slug = trim($d['slug'] ?? '');
if (!$slug && !empty($vals['nombre'])) {
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($vals['nombre'])));
    $slug = trim($slug, '-');
}
$vals['slug'] = $slug ?: null;

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

    $vals['codigo_venta'] = $codigoVenta;
    $vals['codigo_electronico'] = $codigoElec;

    $cols = array_keys($vals);
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $pdo->prepare("INSERT INTO puntos_voltika (" . implode(',', $cols) . ") VALUES ($placeholders)")
        ->execute(array_values($vals));
    $id = $pdo->lastInsertId();
    adminLog('punto_crear', ['punto_id' => $id, 'nombre' => $vals['nombre']]);
}

adminJsonOut(['ok' => true, 'punto_id' => $id]);
