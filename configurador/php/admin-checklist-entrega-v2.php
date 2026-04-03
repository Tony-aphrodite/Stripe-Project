<?php
/**
 * Voltika Admin - Checklist de Entrega v2 (5 fases)
 * GET  ?moto_id=N
 * POST { moto_id, fase, items{}, fotos{}, completar_fase }
 *
 * 5 Phases: Identity → Payment → Unit → OTP → Legal certificate
 * STRICT: Cannot deliver without identity + OTP + signed certificate
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-auth.php';

$dealer = requireDealerAuth();

$campos_fase1 = ['ine_presentada','nombre_coincide','foto_coincide','datos_confirmados','ultimos4_telefono','modelo_confirmado','forma_pago_confirmada'];
$campos_fase2 = ['pago_confirmado','enganche_validado','metodo_pago_registrado','domiciliacion_confirmada'];
$campos_fase3 = ['vin_coincide','unidad_ensamblada','estado_fisico_ok','sin_danos','unidad_completa'];
$campos_fase4 = ['otp_enviado','otp_validado'];
// Fase 5 campos — shared + type-specific
$campos_fase5_credito = [
    'decl_identidad','decl_validacion','decl_condicion','decl_componentes','decl_funcionamiento',
    'acta_aceptada','acta_liberacion','clausula_medios','clausula_uso_info',
    'acepta_terminos','firma_digital','firma_punto',
];
$campos_fase5_contado = [
    'decl_pago_total','decl_validacion','decl_condicion','decl_componentes','decl_funcionamiento',
    'acta_aceptada','acta_liberacion',
    'cumpl_pago_confirmado','cumpl_entrega_total','cumpl_sin_obligacion','cumpl_op_concluida',
    'transf_posesion','transf_uso_responsable','transf_voltika_libre',
    'renuncia_pago_voluntario','renuncia_cumplimiento','renuncia_contracargos','renuncia_registros_prueba',
    'clausula_medios','clausula_uso_info',
    'evidencia_foto_cliente','evidencia_foto_vin','evidencia_foto_entrega','evidencia_video',
    'acepta_terminos','telefono_validado','firma_digital','firma_punto',
];

// Determine type from request or existing record
function getCamposFase5($pdo, $motoId, $json = []) {
    global $campos_fase5_credito, $campos_fase5_contado;

    $tipoActa = $json['tipo_acta'] ?? null;

    if (!$tipoActa) {
        // Check existing checklist
        $s = $pdo->prepare("SELECT tipo_acta FROM checklist_entrega_v2 WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
        $s->execute([$motoId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        $tipoActa = $r['tipo_acta'] ?? null;
    }

    if (!$tipoActa) {
        // Infer from moto payment type
        $s = $pdo->prepare("SELECT pago_estado, tipo_asignacion FROM inventario_motos WHERE id = ?");
        $s->execute([$motoId]);
        $m = $s->fetch(PDO::FETCH_ASSOC);
        // consignacion or fully paid = contado; voltika_entrega with parcial = credito
        $tipoActa = ($m && $m['pago_estado'] === 'parcial') ? 'credito' : 'contado';
    }

    return [
        'tipo'   => $tipoActa,
        'campos' => $tipoActa === 'credito' ? $campos_fase5_credito : $campos_fase5_contado,
    ];
}

// ── GET ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $motoId = intval($_GET['moto_id'] ?? 0);
    if (!$motoId) { echo json_encode(['ok' => true, 'checklist' => null]); exit; }
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT * FROM checklist_entrega_v2 WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$motoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Also return the acta type
        $f5 = getCamposFase5($pdo, $motoId);
        echo json_encode(['ok' => true, 'checklist' => $row ?: null, 'tipo_acta' => $f5['tipo']]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'DB error']);
    }
    exit;
}

// ── POST ─────────────────────────────────────────────────────────────────────
$json          = json_decode(file_get_contents('php://input'), true) ?? [];
$motoId        = intval($json['moto_id'] ?? 0);
$fase          = $json['fase'] ?? 'fase1';
$items         = $json['items'] ?? [];
$fotos         = $json['fotos'] ?? [];
$completarFase = !empty($json['completar_fase']);
$notas         = $json['notas'] ?? '';

if (!$motoId) {
    http_response_code(400);
    echo json_encode(['error' => 'moto_id requerido']);
    exit;
}

try {
    $pdo = getDB();

    $existing = $pdo->prepare("SELECT * FROM checklist_entrega_v2 WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
    $existing->execute([$motoId]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);

    if ($row && $row['bloqueado']) {
        echo json_encode(['ok' => false, 'error' => 'Entrega ya completada y bloqueada.']);
        exit;
    }

    // Validate phase order
    $faseOrder = ['fase1' => 0, 'fase2' => 1, 'fase3' => 2, 'fase4' => 3, 'fase5' => 4];
    if ($row) {
        $prevFases = ['fase2' => 'fase1', 'fase3' => 'fase2', 'fase4' => 'fase3', 'fase5' => 'fase4'];
        if (isset($prevFases[$fase]) && empty($row[$prevFases[$fase] . '_completada'])) {
            echo json_encode(['ok' => false, 'error' => 'Debe completar la fase anterior primero.']);
            exit;
        }
    }

    // Determine campos — fase5 depends on acta type
    $f5Info = getCamposFase5($pdo, $motoId, $json);
    $camposMap = [
        'fase1' => $campos_fase1,
        'fase2' => $campos_fase2,
        'fase3' => $campos_fase3,
        'fase4' => $campos_fase4,
        'fase5' => $f5Info['campos'],
    ];
    $camposToSave = $camposMap[$fase] ?? [];

    // Build updates
    $sets = [];
    $vals = [];
    foreach ($camposToSave as $c) {
        $sets[] = "$c = ?";
        $vals[] = !empty($items[$c]) ? 1 : 0;
    }
    $sets[] = "notas = ?"; $vals[] = $notas;

    // Save tipo_acta and metodo_pago_acta
    if ($fase === 'fase5') {
        $sets[] = "tipo_acta = ?"; $vals[] = $f5Info['tipo'];
        if (!empty($json['metodo_pago_acta'])) {
            $sets[] = "metodo_pago_acta = ?"; $vals[] = $json['metodo_pago_acta'];
        }
        if (!empty($json['punto_taller'])) {
            $sets[] = "punto_taller = ?"; $vals[] = $json['punto_taller'];
        }
    }

    // Save fotos
    if ($fase === 'fase1' && !empty($fotos)) {
        $sets[] = "fotos_identidad = ?"; $vals[] = json_encode($fotos);
    }
    if ($fase === 'fase3' && !empty($fotos)) {
        $sets[] = "fotos_unidad = ?"; $vals[] = json_encode($fotos);
    }

    // Face match results
    if ($fase === 'fase1' && isset($json['face_match_result'])) {
        $sets[] = "face_match_result = ?"; $vals[] = $json['face_match_result'];
        $sets[] = "face_match_score = ?";  $vals[] = floatval($json['face_match_score'] ?? 0);
    }

    // OTP timestamp
    if ($fase === 'fase4' && !empty($items['otp_validado'])) {
        $sets[] = "otp_timestamp = NOW()";
    }

    // Check all items for this phase
    $faseAllOk = true;
    foreach ($camposToSave as $c) {
        if (empty($items[$c])) $faseAllOk = false;
    }

    if ($completarFase && $faseAllOk) {
        $sets[] = "{$fase}_completada = ?"; $vals[] = 1;
        $sets[] = "{$fase}_fecha = NOW()";

        $nextFase = ['fase1'=>'fase2','fase2'=>'fase3','fase3'=>'fase4','fase4'=>'fase5','fase5'=>'completado'];
        $sets[] = "fase_actual = ?"; $vals[] = $nextFase[$fase] ?? $fase;

        // If fase5 → lock and finalize delivery
        if ($fase === 'fase5') {
            $sets[] = "completado = ?"; $vals[] = 1;
            $sets[] = "bloqueado = ?";  $vals[] = 1;
        }
    }

    if ($row) {
        $vals[] = $row['id'];
        $setStr = implode(', ', $sets);
        $pdo->prepare("UPDATE checklist_entrega_v2 SET $setStr WHERE id = ?")->execute($vals);
    } else {
        $sets[] = "moto_id = ?";   $vals[] = $motoId;
        $sets[] = "dealer_id = ?"; $vals[] = $dealer['id'];
        $cols = implode(', ', array_map(fn($s) => explode(' = ', $s)[0], $sets));
        $qmarks = [];
        $cleanVals = [];
        foreach ($sets as $i => $s) {
            if (str_contains($s, 'NOW()')) {
                $qmarks[] = 'NOW()';
            } else {
                $qmarks[] = '?';
                $cleanVals[] = $vals[$i];
            }
        }
        $pdo->prepare("INSERT INTO checklist_entrega_v2 ($cols) VALUES (" . implode(',', $qmarks) . ")")->execute($cleanVals);
    }

    // Final delivery
    if ($completarFase && $fase === 'fase5' && $faseAllOk) {
        // Verify all 5 phases are complete
        $check = $pdo->prepare("SELECT fase1_completada, fase2_completada, fase3_completada, fase4_completada, fase5_completada FROM checklist_entrega_v2 WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
        $check->execute([$motoId]);
        $phases = $check->fetch(PDO::FETCH_ASSOC);

        if ($phases && $phases['fase1_completada'] && $phases['fase2_completada'] && $phases['fase3_completada'] && $phases['fase4_completada'] && $phases['fase5_completada']) {
            $pdo->prepare("UPDATE inventario_motos SET estado = 'entregada', fecha_estado = NOW() WHERE id = ?")->execute([$motoId]);
            echo json_encode(['ok' => true, 'completado' => true, 'message' => 'Entrega finalizada. Sin identidad + OTP + acta firmada = NO EXISTE ENTREGA ✅']);
        } else {
            echo json_encode(['ok' => false, 'error' => 'No se puede finalizar: faltan fases por completar.']);
        }
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
