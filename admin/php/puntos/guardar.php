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

$fields = ['nombre','responsable_nombre','ubicacion','calle_numero','direccion','colonia','ciudad','estado','cp',
           'telefono','email','lat','lng','horarios','capacidad','activo','tipo','descripcion','autorizado','orden',
           'codigo_venta','codigo_electronico','comision_entrega',
           'svc_configurador','svc_entrega','svc_exhibicion','svc_tecnico','svc_pruebas','svc_refacciones'];

// Ensure all extended columns exist (2026-04-19 customer template v1).
$ensureCols = [
    'responsable_nombre' => "ALTER TABLE puntos_voltika ADD COLUMN responsable_nombre VARCHAR(200) NULL",
    'calle_numero'       => "ALTER TABLE puntos_voltika ADD COLUMN calle_numero VARCHAR(200) NULL",
    'ubicacion'          => "ALTER TABLE puntos_voltika ADD COLUMN ubicacion VARCHAR(120) NULL",
    'comision_entrega'   => "ALTER TABLE puntos_voltika ADD COLUMN comision_entrega DECIMAL(10,2) NULL DEFAULT 0",
    'svc_configurador'   => "ALTER TABLE puntos_voltika ADD COLUMN svc_configurador TINYINT(1) NOT NULL DEFAULT 0",
    'svc_entrega'        => "ALTER TABLE puntos_voltika ADD COLUMN svc_entrega TINYINT(1) NOT NULL DEFAULT 0",
    'svc_exhibicion'     => "ALTER TABLE puntos_voltika ADD COLUMN svc_exhibicion TINYINT(1) NOT NULL DEFAULT 0",
    'svc_tecnico'        => "ALTER TABLE puntos_voltika ADD COLUMN svc_tecnico TINYINT(1) NOT NULL DEFAULT 0",
    'svc_pruebas'        => "ALTER TABLE puntos_voltika ADD COLUMN svc_pruebas TINYINT(1) NOT NULL DEFAULT 0",
    'svc_refacciones'    => "ALTER TABLE puntos_voltika ADD COLUMN svc_refacciones TINYINT(1) NOT NULL DEFAULT 0",
];
try {
    $existing = array_column($pdo->query("SHOW COLUMNS FROM puntos_voltika")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    foreach ($ensureCols as $col => $sql) {
        if (!in_array($col, $existing, true)) {
            try { $pdo->exec($sql); } catch (Throwable $e) {}
        }
    }
} catch (Throwable $e) {}
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
    // Update — skip codigo_* fields if empty so admin keeps existing codes unchanged
    // when the form input was left blank (no accidental NULL overwrite).
    $sets = []; $params = [];
    foreach ($vals as $k => $v) {
        if (in_array($k, ['codigo_venta','codigo_electronico'], true) && (string)$v === '') continue;
        $sets[] = "$k=?";
        $params[] = $v;
    }
    $params[] = $id;
    if ($sets) {
        $pdo->prepare("UPDATE puntos_voltika SET " . implode(',', $sets) . " WHERE id=?")->execute($params);
    }
    adminLog('punto_actualizar', ['punto_id' => $id]);
} else {
    // Create — if admin didn't provide custom codes, auto-generate unique ones
    if (empty($vals['codigo_venta']))       $vals['codigo_venta']       = 'PV' . strtoupper(substr(md5(uniqid()), 0, 6));
    if (empty($vals['codigo_electronico'])) $vals['codigo_electronico'] = 'PE' . strtoupper(substr(md5(uniqid()), 0, 6));

    $cols = array_keys($vals);
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $pdo->prepare("INSERT INTO puntos_voltika (" . implode(',', $cols) . ") VALUES ($placeholders)")
        ->execute(array_values($vals));
    $id = $pdo->lastInsertId();
    adminLog('punto_crear', ['punto_id' => $id, 'nombre' => $vals['nombre']]);
}

adminJsonOut(['ok' => true, 'punto_id' => $id]);
