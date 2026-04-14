<?php
/**
 * POST — Save / update checklist de ensamble
 * Body: { moto_id, fase (fase1|fase2|fase3|completar), ...fields }
 * Each fase can be saved independently. completar locks the record.
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$motoId = (int)($d['moto_id'] ?? 0);
$fase   = $d['fase'] ?? '';
if (!$motoId) adminJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();

// Verify moto
$stmt = $pdo->prepare("SELECT id, bloqueado_venta, bloqueado_motivo FROM inventario_motos WHERE id=?");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) adminJsonOut(['error' => 'Moto no encontrada'], 404);
if (!empty($moto['bloqueado_venta'])) {
    adminJsonOut(['error' => 'Esta moto está bloqueada. Motivo: ' . ($moto['bloqueado_motivo'] ?? 'Sin motivo') . '. Desbloquéala primero.'], 403);
}

// Check existing record
$existing = $pdo->prepare("SELECT id, completado, fase_actual FROM checklist_ensamble WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$existing->execute([$motoId]);
$prev = $existing->fetch(PDO::FETCH_ASSOC);

if ($prev && $prev['completado']) {
    adminJsonOut(['error' => 'Este checklist ya fue completado y no se puede modificar'], 403);
}

// All fields by phase
$fase1Fields = ['recepcion_validada','primera_apertura','area_segura','herramientas_disponibles',
    'equipo_proteccion','declaracion_fase1'];
$fase2Fields = ['componentes_sin_dano','accesorios_separados','llanta_identificada',
    'base_instalada','asiento_instalado','tornilleria_base','torque_base_25',
    'manubrio_instalado','cableado_sin_tension','alineacion_manubrio','torque_manubrio_25',
    'buje_corto','buje_largo','disco_alineado','eje_instalado','torque_llanta_50',
    'espejo_izq','espejo_der','roscas_ok','ajuste_espejos'];
$fase3Fields = ['freno_del_funcional','freno_tras_funcional','luz_freno_operativa',
    'direccionales_ok','intermitentes_ok','luz_alta','luz_baja',
    'claxon_ok','dashboard_ok','bateria_cargando','puerto_carga_ok',
    'modo_eco','modo_drive','modo_sport','reversa_ok',
    'nfc_ok','control_remoto_ok','llaves_funcionales',
    'sin_ruidos','sin_interferencias','torques_verificados','declaracion_fase3'];
$allFields = array_merge($fase1Fields, $fase2Fields, $fase3Fields);

// Build values
$vals = ['moto_id' => $motoId, 'dealer_id' => $uid];
foreach ($allFields as $f) {
    $vals[$f] = !empty($d[$f]) ? 1 : 0;
}
$vals['notas'] = $d['notas'] ?? '';

// Determine phase completion
$fase1Done = true;
foreach ($fase1Fields as $f) { if (!$vals[$f]) { $fase1Done = false; break; } }
$fase2Done = true;
foreach ($fase2Fields as $f) { if (!$vals[$f]) { $fase2Done = false; break; } }
$fase3Done = true;
foreach ($fase3Fields as $f) { if (!$vals[$f]) { $fase3Done = false; break; } }

$vals['fase1_completada'] = $fase1Done ? 1 : 0;
$vals['fase2_completada'] = $fase2Done ? 1 : 0;
$vals['fase3_completada'] = $fase3Done ? 1 : 0;

if ($fase1Done && !$fase2Done) $vals['fase_actual'] = 'fase2';
elseif ($fase2Done && !$fase3Done) $vals['fase_actual'] = 'fase3';
elseif ($fase3Done) $vals['fase_actual'] = 'completado';
else $vals['fase_actual'] = 'fase1';

// Set fase dates
if ($fase1Done) $vals['fase1_fecha'] = date('Y-m-d H:i:s');
if ($fase2Done) $vals['fase2_fecha'] = date('Y-m-d H:i:s');
if ($fase3Done) $vals['fase3_fecha'] = date('Y-m-d H:i:s');

// If requesting complete
$completar = !empty($d['completar']);
if ($completar) {
    if (!$fase1Done || !$fase2Done || !$fase3Done) {
        $missing = [];
        foreach ($allFields as $f) { if (!$vals[$f]) $missing[] = $f; }
        adminJsonOut(['error' => 'Faltan ' . count($missing) . ' items por completar', 'missing' => $missing], 400);
    }
    $vals['completado'] = 1;
    $vals['fase_actual'] = 'completado';
} else {
    $vals['completado'] = 0;
}

if ($prev) {
    $sets = []; $params = [];
    foreach ($vals as $k => $v) {
        if ($k === 'moto_id') continue;
        $sets[] = "$k=?";
        $params[] = $v;
    }
    $params[] = $prev['id'];
    $pdo->prepare("UPDATE checklist_ensamble SET " . implode(',', $sets) . " WHERE id=?")->execute($params);
    $checkId = $prev['id'];
} else {
    $cols = array_keys($vals);
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $pdo->prepare("INSERT INTO checklist_ensamble (" . implode(',', $cols) . ") VALUES ($placeholders)")
        ->execute(array_values($vals));
    $checkId = (int)$pdo->lastInsertId();
}

adminLog('checklist_ensamble_' . ($vals['completado'] ? 'completado' : 'guardado'), [
    'moto_id' => $motoId, 'checklist_id' => $checkId, 'fase' => $vals['fase_actual']
]);

adminJsonOut([
    'ok' => true,
    'checklist_id' => $checkId,
    'fase_actual' => $vals['fase_actual'],
    'completado' => $vals['completado'],
]);
