<?php
/**
 * POST — Save / update checklist de entrega v2
 * Body: { moto_id, ...fields, completar? }
 * 5 phases: identidad, pago, unidad, OTP, acta legal
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$motoId = (int)($d['moto_id'] ?? 0);
if (!$motoId) adminJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();

$stmt = $pdo->prepare("SELECT id, bloqueado_venta, bloqueado_motivo FROM inventario_motos WHERE id=?");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) adminJsonOut(['error' => 'Moto no encontrada'], 404);
if (!empty($moto['bloqueado_venta'])) {
    adminJsonOut(['error' => 'Esta moto está bloqueada. Motivo: ' . ($moto['bloqueado_motivo'] ?? 'Sin motivo') . '. Desbloquéala primero.'], 403);
}

$existing = $pdo->prepare("SELECT id, completado FROM checklist_entrega_v2 WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$existing->execute([$motoId]);
$prev = $existing->fetch(PDO::FETCH_ASSOC);

if ($prev && $prev['completado']) {
    adminJsonOut(['error' => 'Este checklist ya fue completado y no se puede modificar'], 403);
}

// Fields by phase
$fase1Fields = ['ine_presentada','nombre_coincide','foto_coincide','datos_confirmados',
    'ultimos4_telefono','modelo_confirmado','forma_pago_confirmada'];
$fase2Fields = ['pago_confirmado','enganche_validado','metodo_pago_registrado','domiciliacion_confirmada'];
$fase3Fields = ['vin_coincide','unidad_ensamblada','estado_fisico_ok','sin_danos','unidad_completa'];
$fase4Fields = ['otp_enviado','otp_validado'];
$fase5Fields = ['acta_aceptada','clausula_identidad','clausula_medios','clausula_uso_info','firma_digital'];
$allFields = array_merge($fase1Fields, $fase2Fields, $fase3Fields, $fase4Fields, $fase5Fields);

$vals = ['moto_id' => $motoId, 'dealer_id' => $uid];
foreach ($allFields as $f) {
    $vals[$f] = !empty($d[$f]) ? 1 : 0;
}
$vals['notas'] = $d['notas'] ?? '';

// Determine phase completion
$fasesDone = [];
$fasesFields = [$fase1Fields, $fase2Fields, $fase3Fields, $fase4Fields, $fase5Fields];
for ($i = 0; $i < 5; $i++) {
    $done = true;
    foreach ($fasesFields[$i] as $f) { if (!$vals[$f]) { $done = false; break; } }
    $fasesDone[$i] = $done;
    $vals['fase' . ($i+1) . '_completada'] = $done ? 1 : 0;
    if ($done) $vals['fase' . ($i+1) . '_fecha'] = date('Y-m-d H:i:s');
}

// Determine current phase
$faseActual = 'fase1';
for ($i = 0; $i < 5; $i++) {
    if ($fasesDone[$i]) {
        $faseActual = ($i < 4) ? 'fase' . ($i+2) : 'completado';
    } else {
        $faseActual = 'fase' . ($i+1);
        break;
    }
}
$vals['fase_actual'] = $faseActual;

$completar = !empty($d['completar']);
if ($completar) {
    $missing = [];
    foreach ($allFields as $f) { if (!$vals[$f]) $missing[] = $f; }
    if ($missing) {
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
    $pdo->prepare("UPDATE checklist_entrega_v2 SET " . implode(',', $sets) . " WHERE id=?")->execute($params);
    $checkId = $prev['id'];
} else {
    $cols = array_keys($vals);
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $pdo->prepare("INSERT INTO checklist_entrega_v2 (" . implode(',', $cols) . ") VALUES ($placeholders)")
        ->execute(array_values($vals));
    $checkId = (int)$pdo->lastInsertId();
}

adminLog('checklist_entrega_' . ($vals['completado'] ? 'completado' : 'guardado'), [
    'moto_id' => $motoId, 'checklist_id' => $checkId, 'fase' => $vals['fase_actual']
]);

adminJsonOut([
    'ok' => true,
    'checklist_id' => $checkId,
    'fase_actual' => $vals['fase_actual'],
    'completado' => $vals['completado'],
]);
