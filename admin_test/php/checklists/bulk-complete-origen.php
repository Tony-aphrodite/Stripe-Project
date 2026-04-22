<?php
/**
 * POST — Bulk-mark multiple motos' checklist_origen as completed.
 * Used by admins/CEDIS to quickly process many recently-imported bikes
 * after a physical inspection batch.
 *
 * Body: { moto_ids: [12, 14, 18, ...], notas?: "Inspección lote A" }
 * Returns: { ok, completados: N, ya_completos: N, errores: N }
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin', 'cedis']);

$in   = adminJsonIn();
$ids  = $in['moto_ids'] ?? [];
$nota = trim((string)($in['notas'] ?? ''));

if (!is_array($ids) || empty($ids)) {
    adminJsonOut(['error' => 'Debes seleccionar al menos una moto'], 400);
}
$ids = array_values(array_unique(array_map('intval', $ids)));
$ids = array_filter($ids, function($v){ return $v > 0; });
if (empty($ids)) adminJsonOut(['error' => 'IDs inválidos'], 400);

$pdo = getDB();

// Check each moto exists + fetch metadata for the checklist row
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT id, vin, modelo, color FROM inventario_motos WHERE id IN ($placeholders)");
$stmt->execute($ids);
$motos = $stmt->fetchAll(PDO::FETCH_ASSOC);
$found = array_column($motos, null, 'id');

$completados = 0;
$yaCompletos = 0;
$errores     = 0;
$detalle     = [];

// All 55 binary fields that make up the origen checklist. When bulk-completing
// we set every one to 1 so the detail view shows "55/55 items" instead of the
// previous confusing "completado: true + progreso 0/55".
$ALL_ORIGEN_FIELDS = [
    // 1. Estructura principal
    'frame_completo','chasis_sin_deformaciones','soportes_estructurales','charola_trasera',
    // 2. Sistema de rodamiento
    'llanta_delantera','llanta_trasera','rines_sin_dano','ejes_completos',
    // 3. Dirección y control
    'manubrio','soportes_completos','dashboard_incluido','controles_completos',
    // 4. Sistema de frenado
    'freno_delantero','freno_trasero','discos_sin_dano','calipers_instalados','lineas_completas',
    // 5. Sistema eléctrico
    'cableado_completo','conectores_correctos','controlador_instalado','encendido_operativo',
    // 6. Motor
    'motor_instalado','motor_sin_dano','motor_conexion',
    // 7. Baterías
    'bateria_1','bateria_2','baterias_sin_dano',
    // 8. Accesorios
    'espejos','tornilleria_completa','birlos_completos','kit_herramientas','cargador_incluido',
    // 9. Complementos
    'llaves_2','manual_usuario','carnet_garantia',
    // 10. Validación eléctrica
    'sistema_enciende','dashboard_funcional','indicador_bateria','luces_funcionando',
    'conectores_firmes','cableado_sin_dano',
    // 11. Artes decorativos
    'calcomanias_correctas','alineacion_correcta','sin_burbujas','sin_desprendimientos',
    'sin_rayones','acabados_correctos',
    // 12. Empaque
    'embalaje_correcto','protecciones_colocadas','caja_sin_dano','sellos_colocados',
    'empaque_accesorios','empaque_llaves',
    // 14. Declaración legal
    'declaracion_aceptada',
    // 15. Validación final
    'validacion_final',
];

// Probe which of these columns actually exist (schema may lag behind code)
try {
    $cols = $pdo->query("SHOW COLUMNS FROM checklist_origen")->fetchAll(PDO::FETCH_COLUMN);
    $colSet = array_flip($cols);
    $ALL_ORIGEN_FIELDS = array_values(array_filter($ALL_ORIGEN_FIELDS, function($f) use ($colSet) {
        return isset($colSet[$f]);
    }));
} catch (Throwable $e) {}

// Build a column list that includes all item fields + the completion markers.
// Reused for both INSERT and UPDATE statements below.
$itemSetSql = implode(', ', array_map(function($f) { return "$f = 1"; }, $ALL_ORIGEN_FIELDS));

$insCols = array_merge(
    ['moto_id','dealer_id','vin','modelo','color','completado','bloqueado','hash_registro','notas'],
    $ALL_ORIGEN_FIELDS
);
$insPlaceholders = implode(',', array_merge(
    ['?','?','?','?','?','1','1','?','?'],
    array_fill(0, count($ALL_ORIGEN_FIELDS), '1')
));
$insStmt = $pdo->prepare("INSERT INTO checklist_origen (" . implode(',', $insCols) . ")
    VALUES ($insPlaceholders)");

$updStmt = $pdo->prepare("UPDATE checklist_origen
    SET completado = 1, bloqueado = 1, fcompletado = NOW(), $itemSetSql
    WHERE moto_id = ? AND completado = 0");

// Repair path: if a row is already completado=1 but the 55 items are empty
// (legacy bulk-complete bug), backfill items to 1 so detail shows 55/55.
$firstField = $ALL_ORIGEN_FIELDS[0] ?? 'frame_completo';
$repairStmt = $pdo->prepare("UPDATE checklist_origen
    SET $itemSetSql
    WHERE moto_id = ? AND completado = 1 AND ($firstField = 0 OR $firstField IS NULL)");

$chkStmt = $pdo->prepare("SELECT id, completado, $firstField AS probe FROM checklist_origen WHERE moto_id = ? LIMIT 1");

foreach ($ids as $motoId) {
    $m = $found[$motoId] ?? null;
    if (!$m) { $errores++; $detalle[] = "Moto $motoId no existe"; continue; }

    try {
        $chkStmt->execute([$motoId]);
        $existing = $chkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing && (int)$existing['completado'] === 1) {
            // Already marked complete — but if items are empty, backfill them.
            if (empty($existing['probe'])) {
                $repairStmt->execute([$motoId]);
                $completados++;
            } else {
                $yaCompletos++;
            }
            continue;
        }
        if ($existing) {
            $updStmt->execute([$motoId]);
            $completados++;
        } else {
            $hash = hash('sha256', "bulk-$motoId-" . date('c'));
            $insStmt->execute([$motoId, $uid, $m['vin'], $m['modelo'], $m['color'], $hash, $nota ?: 'Inspección masiva']);
            $completados++;
        }
    } catch (Throwable $e) {
        $errores++;
        $detalle[] = "Moto $motoId: " . $e->getMessage();
    }
}

adminLog('checklist_origen_bulk', [
    'usuario_id' => $uid,
    'completados' => $completados,
    'ya_completos' => $yaCompletos,
    'errores' => $errores,
    'total_seleccionados' => count($ids),
]);

adminJsonOut([
    'ok'           => true,
    'completados'  => $completados,
    'ya_completos' => $yaCompletos,
    'errores'      => $errores,
    'detalle'      => array_slice($detalle, 0, 20),
]);
