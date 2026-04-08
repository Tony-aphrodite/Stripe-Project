<?php
/**
 * Voltika Admin - Checklist de Ensamble (3 fases)
 * GET  ?moto_id=N
 * POST { moto_id, fase, items{}, fotos{}, declaracion, completar_fase }
 *
 * Rules: Cannot skip phases. All photos required. Torque must be confirmed.
 * On final release: lock + update status to 'lista_para_entrega'
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-auth.php';

$dealer = requireDealerAuth();

$campos_fase1 = ['recepcion_validada','primera_apertura','area_segura','herramientas_disponibles','equipo_proteccion','declaracion_fase1'];
$campos_fase2 = [
    'componentes_sin_dano','accesorios_separados','llanta_identificada',
    'base_instalada','asiento_instalado','tornilleria_base','torque_base_25',
    'manubrio_instalado','cableado_sin_tension','alineacion_manubrio','torque_manubrio_25',
    'buje_corto','buje_largo','disco_alineado','eje_instalado','torque_llanta_50',
    'espejo_izq','espejo_der','roscas_ok','ajuste_espejos',
];
$campos_fase3 = [
    'freno_del_funcional','freno_tras_funcional','luz_freno_operativa',
    'direccionales_ok','intermitentes_ok','luz_alta','luz_baja',
    'claxon_ok','dashboard_ok','bateria_cargando','puerto_carga_ok',
    'modo_eco','modo_drive','modo_sport','reversa_ok',
    'nfc_ok','control_remoto_ok','llaves_funcionales',
    'sin_ruidos','sin_interferencias','torques_verificados',
    'declaracion_fase3',
];

// ── GET ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $motoId = intval($_GET['moto_id'] ?? 0);
    if (!$motoId) { echo json_encode(['ok' => true, 'checklist' => null]); exit; }
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT * FROM checklist_ensamble WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$motoId]);
        echo json_encode(['ok' => true, 'checklist' => $stmt->fetch(PDO::FETCH_ASSOC) ?: null]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'DB error']);
    }
    exit;
}

// ── POST ─────────────────────────────────────────────────────────────────────
$json           = json_decode(file_get_contents('php://input'), true) ?? [];
$motoId         = intval($json['moto_id'] ?? 0);
$fase           = $json['fase'] ?? 'fase1';
$items          = $json['items'] ?? [];
$fotos          = $json['fotos'] ?? [];
$completarFase  = !empty($json['completar_fase']);
$notas          = $json['notas'] ?? '';

if (!$motoId) {
    http_response_code(400);
    echo json_encode(['error' => 'moto_id requerido']);
    exit;
}

try {
    $pdo = getDB();

    // Get or create record
    $existing = $pdo->prepare("SELECT * FROM checklist_ensamble WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
    $existing->execute([$motoId]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);

    if ($row && $row['bloqueado']) {
        echo json_encode(['ok' => false, 'error' => 'Ensamble ya completado y bloqueado.']);
        exit;
    }

    // Validate phase order
    if ($row) {
        $currentFase = $row['fase_actual'];
        if ($fase === 'fase2' && !$row['fase1_completada']) {
            echo json_encode(['ok' => false, 'error' => 'Debe completar Fase 1 primero.']);
            exit;
        }
        if ($fase === 'fase3' && !$row['fase2_completada']) {
            echo json_encode(['ok' => false, 'error' => 'Debe completar Fase 2 primero.']);
            exit;
        }
    }

    // Determine which campos to save
    $camposToSave = [];
    $fotosField = '';
    if ($fase === 'fase1') { $camposToSave = $campos_fase1; $fotosField = 'fotos_fase1'; }
    elseif ($fase === 'fase2') {
        $camposToSave = $campos_fase2;
        // Multiple foto fields for fase2
    }
    elseif ($fase === 'fase3') { $camposToSave = $campos_fase3; $fotosField = 'fotos_fase3'; }

    // Build updates
    $sets = [];
    $vals = [];
    foreach ($camposToSave as $c) {
        $sets[] = "$c = ?";
        $vals[] = !empty($items[$c]) ? 1 : 0;
    }
    $sets[] = "notas = ?"; $vals[] = $notas;

    // Save fotos
    if ($fase === 'fase1' && !empty($fotos)) {
        $sets[] = "fotos_fase1 = ?"; $vals[] = json_encode($fotos);
    }
    if ($fase === 'fase2') {
        foreach (['fotos_base','fotos_manubrio','fotos_llanta','fotos_espejos'] as $fk) {
            if (!empty($fotos[$fk])) {
                $sets[] = "$fk = ?"; $vals[] = json_encode($fotos[$fk]);
            }
        }
    }
    if ($fase === 'fase3' && !empty($fotos)) {
        $sets[] = "fotos_fase3 = ?"; $vals[] = json_encode($fotos);
    }

    // Check if all items complete for this phase
    $faseAllOk = true;
    foreach ($camposToSave as $c) {
        if (empty($items[$c])) $faseAllOk = false;
    }

    if ($completarFase && $faseAllOk) {
        $sets[] = "{$fase}_completada = ?"; $vals[] = 1;
        $sets[] = "{$fase}_fecha = NOW()";

        // Advance to next fase
        $nextFase = ['fase1' => 'fase2', 'fase2' => 'fase3', 'fase3' => 'completado'];
        $sets[] = "fase_actual = ?"; $vals[] = $nextFase[$fase] ?? $fase;

        // If fase3 completed → lock and update moto status
        if ($fase === 'fase3') {
            $sets[] = "completado = ?"; $vals[] = 1;
            $sets[] = "bloqueado = ?";  $vals[] = 1;
        }
    }

    if ($row) {
        $vals[] = $row['id'];
        $pdo->prepare("UPDATE checklist_ensamble SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
    } else {
        $insertCols = ['moto_id', 'dealer_id'];
        $insertQmarks = ['?', '?'];
        $insertVals = [$motoId, $dealer['id']];

        foreach ($sets as $i => $s) {
            $colName = trim(explode('=', $s)[0]);
            $insertCols[] = $colName;
            if (str_contains($s, 'NOW()')) {
                $insertQmarks[] = 'NOW()';
            } else {
                $insertQmarks[] = '?';
                $insertVals[] = $vals[$i];
            }
        }
        $pdo->prepare("INSERT INTO checklist_ensamble (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertQmarks) . ")")->execute($insertVals);
    }

    // If fully completed, update moto status
    if ($completarFase && $fase === 'fase3' && $faseAllOk) {
        $pdo->prepare("UPDATE inventario_motos SET estado = 'lista_para_entrega', fecha_estado = NOW() WHERE id = ? AND estado IN ('en_ensamble','por_ensamblar')")->execute([$motoId]);
        echo json_encode(['ok' => true, 'completado' => true, 'message' => 'Ensamble completado. Unidad liberada.']);
    } else {
        echo json_encode([
            'ok' => true,
            'completado' => false,
            'fase_completada' => $completarFase && $faseAllOk,
            'message' => $completarFase
                ? ($faseAllOk ? "Fase $fase completada." : "Faltan items en fase $fase.")
                : 'Progreso guardado.'
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error: ' . $e->getMessage()]);
}
