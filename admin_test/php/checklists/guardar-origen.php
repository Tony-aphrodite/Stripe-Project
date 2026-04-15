<?php
/**
 * POST — Save / update checklist de origen
 * Body: { moto_id, ...all checklist fields }
 * If completado=1, locks the record (no further edits)
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$motoId = (int)($d['moto_id'] ?? 0);
if (!$motoId) adminJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();

// Verify moto exists
$stmt = $pdo->prepare("SELECT id, vin, vin_display, modelo, color, anio_modelo, bloqueado_venta, bloqueado_motivo FROM inventario_motos WHERE id=?");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) adminJsonOut(['error' => 'Moto no encontrada'], 404);
if (!empty($moto['bloqueado_venta'])) {
    adminJsonOut(['error' => 'Esta moto está bloqueada. Motivo: ' . ($moto['bloqueado_motivo'] ?? 'Sin motivo') . '. Desbloquéala primero.'], 403);
}

// Check if existing record (and if locked)
$existing = $pdo->prepare("SELECT id, completado FROM checklist_origen WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$existing->execute([$motoId]);
$prev = $existing->fetch(PDO::FETCH_ASSOC);

if ($prev && $prev['completado']) {
    adminJsonOut(['error' => 'Este checklist ya fue completado y no se puede modificar'], 403);
}

// All binary checklist fields
$binaryFields = [
    'frame_completo','chasis_sin_deformaciones','soportes_estructurales','charola_trasera',
    'llanta_delantera','llanta_trasera','rines_sin_dano','ejes_completos',
    'manubrio','soportes_completos','dashboard_incluido','controles_completos',
    'freno_delantero','freno_trasero','discos_sin_dano','calipers_instalados','lineas_completas',
    'cableado_completo','conectores_correctos','controlador_instalado','encendido_operativo',
    'motor_instalado','motor_sin_dano','motor_conexion',
    'bateria_1','bateria_2','baterias_sin_dano','cargador_incluido',
    'espejos','tornilleria_completa','birlos_completos','kit_herramientas',
    'llaves_2','manual_usuario','carnet_garantia',
    'sistema_enciende','dashboard_funcional','indicador_bateria','luces_funcionando',
    'conectores_firmes','cableado_sin_dano',
    'calcomanias_correctas','alineacion_correcta','sin_burbujas','sin_desprendimientos',
    'sin_rayones','acabados_correctos',
    'embalaje_correcto','protecciones_colocadas','caja_sin_dano','sellos_colocados',
    'declaracion_aceptada','validacion_final',
];

$vals = [
    'moto_id'          => $motoId,
    'dealer_id'        => $uid,
    'vin'              => $moto['vin'] ?? $moto['vin_display'] ?? '',
    'num_motor'        => $d['num_motor'] ?? '',
    'modelo'           => $moto['modelo'] ?? '',
    'color'            => $moto['color'] ?? '',
    'anio_modelo'      => $moto['anio_modelo'] ?? '',
    'config_baterias'  => $d['config_baterias'] ?? '1',
];

foreach ($binaryFields as $f) {
    $vals[$f] = !empty($d[$f]) ? 1 : 0;
}

$vals['num_sellos']  = (int)($d['num_sellos'] ?? 0);
$vals['notas']       = $d['notas'] ?? '';
$vals['completado']  = !empty($d['completado']) ? 1 : 0;

// If marking complete, validate all required fields
if ($vals['completado']) {
    $missing = [];
    foreach ($binaryFields as $f) {
        if (!$vals[$f]) $missing[] = $f;
    }
    if ($missing) {
        adminJsonOut([
            'error' => 'Faltan ' . count($missing) . ' items por completar para finalizar el checklist',
            'missing' => $missing,
        ], 400);
    }
}

if ($prev) {
    // Update existing
    $sets = []; $params = [];
    foreach ($vals as $k => $v) {
        if ($k === 'moto_id') continue;
        $sets[] = "$k=?";
        $params[] = $v;
    }
    $params[] = $prev['id'];
    $pdo->prepare("UPDATE checklist_origen SET " . implode(',', $sets) . " WHERE id=?")->execute($params);
    $checkId = $prev['id'];
} else {
    // Insert new
    $cols = array_keys($vals);
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $pdo->prepare("INSERT INTO checklist_origen (" . implode(',', $cols) . ") VALUES ($placeholders)")
        ->execute(array_values($vals));
    $checkId = (int)$pdo->lastInsertId();
}

// If completed, generate hash
if ($vals['completado']) {
    $hashData = json_encode($vals) . $checkId . date('c');
    $hash = hash('sha256', $hashData);
    $pdo->prepare("UPDATE checklist_origen SET hash_registro=? WHERE id=?")->execute([$hash, $checkId]);
}

adminLog('checklist_origen_' . ($vals['completado'] ? 'completado' : 'guardado'), [
    'moto_id' => $motoId, 'checklist_id' => $checkId
]);

adminJsonOut(['ok' => true, 'checklist_id' => $checkId, 'completado' => $vals['completado']]);
