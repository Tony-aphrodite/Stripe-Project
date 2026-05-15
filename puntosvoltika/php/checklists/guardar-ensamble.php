<?php
/**
 * POST — Save / update checklist de ensamble from the Punto Voltika panel.
 *
 * Mirrors admin/php/checklists/guardar-ensamble.php but with dealer auth
 * and scope-to-own-punto validation. Writes to the same checklist_ensamble
 * table so the admin dashboard sees the same data.
 *
 * Body: { moto_id, [...checklist fields...], notas, completar? }
 */
require_once __DIR__ . '/../bootstrap.php';
$auth = puntoRequireAuth();

$d = puntoJsonIn();
$motoId = (int)($d['moto_id'] ?? 0);
if (!$motoId) puntoJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();

// Verify moto exists AND is at this operator's punto
$stmt = $pdo->prepare("SELECT id, punto_voltika_id, bloqueado_venta, bloqueado_motivo
                         FROM inventario_motos WHERE id=?");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) puntoJsonOut(['error' => 'Moto no encontrada'], 404);
if ((int)$moto['punto_voltika_id'] !== (int)$auth['punto_id']) {
    puntoJsonOut(['error' => 'Moto no pertenece a este punto'], 403);
}
if (!empty($moto['bloqueado_venta'])) {
    puntoJsonOut(['error' => 'Moto bloqueada. Motivo: ' . ($moto['bloqueado_motivo'] ?? 'Sin motivo')], 403);
}

// Existing checklist (if any)
$existing = $pdo->prepare("SELECT id, completado FROM checklist_ensamble WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$existing->execute([$motoId]);
$prev = $existing->fetch(PDO::FETCH_ASSOC);
if ($prev && $prev['completado']) {
    puntoJsonOut(['error' => 'Este checklist ya está completado'], 403);
}

// Field definitions — MUST match the admin version exactly so data stays
// consistent across both panels.
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

$vals = ['moto_id' => $motoId, 'dealer_id' => $auth['user_id']];
foreach ($allFields as $f) $vals[$f] = !empty($d[$f]) ? 1 : 0;
$vals['notas'] = $d['notas'] ?? '';

$fase1Done = !in_array(0, array_map(fn($f) => $vals[$f], $fase1Fields), true);
$fase2Done = !in_array(0, array_map(fn($f) => $vals[$f], $fase2Fields), true);
$fase3Done = !in_array(0, array_map(fn($f) => $vals[$f], $fase3Fields), true);
$vals['fase1_completada'] = $fase1Done ? 1 : 0;
$vals['fase2_completada'] = $fase2Done ? 1 : 0;
$vals['fase3_completada'] = $fase3Done ? 1 : 0;

if ($fase1Done && !$fase2Done)       $vals['fase_actual'] = 'fase2';
elseif ($fase2Done && !$fase3Done)   $vals['fase_actual'] = 'fase3';
elseif ($fase3Done)                  $vals['fase_actual'] = 'completado';
else                                 $vals['fase_actual'] = 'fase1';

if ($fase1Done) $vals['fase1_fecha'] = date('Y-m-d H:i:s');
if ($fase2Done) $vals['fase2_fecha'] = date('Y-m-d H:i:s');
if ($fase3Done) $vals['fase3_fecha'] = date('Y-m-d H:i:s');

$completar = !empty($d['completar']);
if ($completar) {
    if (!$fase1Done || !$fase2Done || !$fase3Done) {
        $missing = [];
        foreach ($allFields as $f) if (!$vals[$f]) $missing[] = $f;
        puntoJsonOut(['error' => 'Faltan ' . count($missing) . ' items', 'missing' => $missing], 400);
    }

    // ── Round 36 (2026-05-14, Óscar — "the photos are mandatory") ───────
    // Customer brief: the operator must NOT be able to finalize the
    // assembly checklist without uploading evidence photos. Previously
    // the server accepted `completar=1` even when zero photos had been
    // uploaded — admins had no proof of work for liability claims. We
    // require at least ONE photo per fase (fase1 / fase2 / fase3) before
    // accepting completion. Mid-fase saves are unaffected — only the
    // final "Completar" submit is gated.
    //
    // Photos live in JSON columns populated by subir-foto.php. We read
    // the latest checklist_ensamble row and count non-empty photo arrays
    // per fase. Fase2 has multiple optional zones; we accept ANY of them.
    $fase1Photos = ['fotos_fase1'];
    $fase2Photos = ['fotos_desembalaje','fotos_base','fotos_manubrio','fotos_llanta','fotos_espejos'];
    $fase3Photos = ['fotos_fase3','fotos_3_1_frenos','fotos_3_2_iluminacion','fotos_3_3_electrico',
                    'fotos_3_4_motor','fotos_3_5_acceso','fotos_3_6_mecanica'];
    $photoCheck = function (array $cols, ?array $row): int {
        if (!$row) return 0;
        $count = 0;
        foreach ($cols as $c) {
            if (empty($row[$c])) continue;
            $arr = is_array($row[$c]) ? $row[$c] : @json_decode((string)$row[$c], true);
            if (is_array($arr)) {
                foreach ($arr as $u) {
                    if (is_string($u) && trim($u) !== '') $count++;
                }
            }
        }
        return $count;
    };
    $photoRow = null;
    if ($prev) {
        try {
            $allPhotoCols = array_merge($fase1Photos, $fase2Photos, $fase3Photos);
            $pq = $pdo->prepare("SELECT " . implode(',', $allPhotoCols) .
                                " FROM checklist_ensamble WHERE id = ?");
            $pq->execute([$prev['id']]);
            $photoRow = $pq->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            // If columns don't exist yet on legacy schema, treat as no photos
            // so the operator gets a polite error instead of a 500.
            error_log('guardar-ensamble photo check: ' . $e->getMessage());
        }
    }
    $f1Count = $photoCheck($fase1Photos, $photoRow);
    $f2Count = $photoCheck($fase2Photos, $photoRow);
    $f3Count = $photoCheck($fase3Photos, $photoRow);
    if ($f1Count === 0 || $f2Count === 0 || $f3Count === 0) {
        $missingPhases = [];
        if ($f1Count === 0) $missingPhases[] = 'Fase 1 (Recepción y preparación)';
        if ($f2Count === 0) $missingPhases[] = 'Fase 2 (Ensamble mecánico)';
        if ($f3Count === 0) $missingPhases[] = 'Fase 3 (Verificación final)';
        puntoJsonOut([
            'error'   => 'Para finalizar el checklist debes subir al menos una foto en cada fase. ' .
                         'Faltan fotos en: ' . implode(', ', $missingPhases) . '.',
            'missing_photos' => [
                'fase1' => $f1Count === 0,
                'fase2' => $f2Count === 0,
                'fase3' => $f3Count === 0,
            ],
            'hint' => 'Toca el ícono de cámara en cada sección de la fase indicada y sube una imagen antes de presionar Completar.',
        ], 400);
    }

    $vals['completado'] = 1;
    $vals['fase_actual'] = 'completado';
} else {
    $vals['completado'] = 0;
}

// Upsert
if ($prev) {
    $sets = []; $params = [];
    foreach ($vals as $k => $v) {
        if ($k === 'moto_id') continue;
        $sets[] = "$k=?"; $params[] = $v;
    }
    $params[] = $prev['id'];
    $pdo->prepare("UPDATE checklist_ensamble SET " . implode(',', $sets) . " WHERE id=?")
        ->execute($params);
    $checkId = (int)$prev['id'];
} else {
    $cols = array_keys($vals);
    $ph   = implode(',', array_fill(0, count($cols), '?'));
    $pdo->prepare("INSERT INTO checklist_ensamble (" . implode(',', $cols) . ") VALUES ($ph)")
        ->execute(array_values($vals));
    $checkId = (int)$pdo->lastInsertId();
}

puntoLog('checklist_ensamble_' . ($vals['completado'] ? 'completado' : 'guardado'), [
    'moto_id' => $motoId, 'checklist_id' => $checkId, 'fase' => $vals['fase_actual'],
]);

// When completed, flip the moto's state so the admin dashboard picks it up
// automatically. Customer notification is handled by the admin endpoint's
// webhook flow which will fire on the next status change event — we keep
// this file focused on the checklist itself.
if ($vals['completado']) {
    try {
        $pdo->prepare("UPDATE inventario_motos SET estado='lista_para_entrega', fecha_estado=NOW()
                        WHERE id=?")->execute([$motoId]);
    } catch (Throwable $e) { error_log('lista_para_entrega update: ' . $e->getMessage()); }
}

puntoJsonOut([
    'ok'           => true,
    'checklist_id' => $checkId,
    'completado'   => (bool)$vals['completado'],
    'fase_actual'  => $vals['fase_actual'],
    'progreso'     => [
        'fase1' => $fase1Done,
        'fase2' => $fase2Done,
        'fase3' => $fase3Done,
    ],
]);
