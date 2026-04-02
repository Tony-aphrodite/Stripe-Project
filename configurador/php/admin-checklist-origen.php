<?php
/**
 * Voltika Admin - Checklist de Origen
 * GET  ?moto_id=N                → fetch existing checklist
 * POST { moto_id, items{}, notas, completar, fotos }
 *
 * On complete: locks record, generates hash, updates moto status to 'recibida'
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-auth.php';

$dealer = requireDealerAuth();

$campos = [
    // 1. Estructura
    'frame_completo','chasis_sin_deformaciones','soportes_estructurales','charola_trasera',
    // 2. Rodamiento
    'llanta_delantera','llanta_trasera','rines_sin_dano','ejes_completos',
    // 3. Dirección
    'manubrio','soportes_completos','dashboard_incluido','controles_completos',
    // 4. Frenado
    'freno_delantero','freno_trasero','discos_sin_dano','calipers_instalados','lineas_completas',
    // 5. Eléctrico
    'cableado_completo','conectores_correctos','controlador_instalado','encendido_operativo',
    // 6. Motor
    'motor_instalado','motor_sin_dano','motor_conexion',
    // 7. Baterías
    'bateria_1','bateria_2','baterias_sin_dano','cargador_incluido',
    // 8. Accesorios
    'espejos','tornilleria_completa','birlos_completos','kit_herramientas',
    // 9. Complementos
    'llaves_2','manual_usuario','carnet_garantia',
    // 10. Validación eléctrica
    'sistema_enciende','dashboard_funcional','indicador_bateria','luces_funcionando','conectores_firmes','cableado_sin_dano',
    // 11. Artes decorativos
    'calcomanias_correctas','alineacion_correcta','sin_burbujas','sin_desprendimientos','sin_rayones','acabados_correctos',
    // 12. Empaque
    'embalaje_correcto','protecciones_colocadas','caja_sin_dano','sellos_colocados',
    // 14-15. Declaración
    'declaracion_aceptada','validacion_final',
];

// ── GET ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $motoId = intval($_GET['moto_id'] ?? 0);
    if (!$motoId) { echo json_encode(['ok' => true, 'checklist' => null]); exit; }

    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT * FROM checklist_origen WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$motoId]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'checklist' => $row ?: null]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'DB error']);
    }
    exit;
}

// ── POST ─────────────────────────────────────────────────────────────────────
$json      = json_decode(file_get_contents('php://input'), true) ?? [];
$motoId    = intval($json['moto_id'] ?? 0);
$items     = $json['items'] ?? [];
$notas     = $json['notas'] ?? '';
$completar = !empty($json['completar']);
$numSellos = intval($json['num_sellos'] ?? 0);
$configBat = $json['config_baterias'] ?? '1';
$fotos     = $json['fotos'] ?? [];

if (!$motoId) {
    http_response_code(400);
    echo json_encode(['error' => 'moto_id requerido']);
    exit;
}

// Check all items
$allOk = true;
foreach ($campos as $c) {
    if (empty($items[$c])) $allOk = false;
}

try {
    $pdo = getDB();

    // Check if locked
    $existing = $pdo->prepare("SELECT id, bloqueado FROM checklist_origen WHERE moto_id = ? ORDER BY id DESC LIMIT 1");
    $existing->execute([$motoId]);
    $row = $existing->fetch();

    if ($row && $row['bloqueado']) {
        echo json_encode(['ok' => false, 'error' => 'Este checklist ya fue completado y no puede modificarse.']);
        exit;
    }

    // Build SET clause
    $sets = [];
    $vals = [];
    foreach ($campos as $c) {
        $sets[] = "$c = ?";
        $vals[] = !empty($items[$c]) ? 1 : 0;
    }
    $sets[] = "notas = ?";           $vals[] = $notas;
    $sets[] = "num_sellos = ?";      $vals[] = $numSellos;
    $sets[] = "config_baterias = ?"; $vals[] = $configBat;
    $sets[] = "fotos = ?";           $vals[] = json_encode($fotos);

    if ($row) {
        // Update
        $vals[] = $row['id'];
        $pdo->prepare("UPDATE checklist_origen SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
        $checklistId = $row['id'];
    } else {
        // Insert
        // Get moto info
        $motoStmt = $pdo->prepare("SELECT vin, modelo, color FROM inventario_motos WHERE id = ?");
        $motoStmt->execute([$motoId]);
        $motoInfo = $motoStmt->fetch(PDO::FETCH_ASSOC);

        $sets[] = "moto_id = ?";   $vals[] = $motoId;
        $sets[] = "dealer_id = ?"; $vals[] = $dealer['id'];
        $sets[] = "vin = ?";       $vals[] = $motoInfo['vin'] ?? '';
        $sets[] = "modelo = ?";    $vals[] = $motoInfo['modelo'] ?? '';
        $sets[] = "color = ?";     $vals[] = $motoInfo['color'] ?? '';

        $placeholders = implode(', ', array_map(fn($s) => explode(' = ', $s)[0], $sets));
        $qmarks = implode(', ', array_fill(0, count($vals), '?'));
        $pdo->prepare("INSERT INTO checklist_origen ($placeholders) VALUES ($qmarks)")->execute($vals);
        $checklistId = $pdo->lastInsertId();
    }

    // Complete and lock
    if ($completar && $allOk) {
        // Generate hash
        $hashData = json_encode(['id' => $checklistId, 'moto_id' => $motoId, 'items' => $items, 'ts' => date('c')]);
        $hash = hash('sha256', $hashData);

        $pdo->prepare("UPDATE checklist_origen SET completado = 1, bloqueado = 1, hash_registro = ? WHERE id = ?")->execute([$hash, $checklistId]);

        // Update moto status to 'recibida'
        $pdo->prepare("UPDATE inventario_motos SET estado = 'recibida', fecha_estado = NOW() WHERE id = ? AND estado = 'por_llegar'")->execute([$motoId]);

        echo json_encode(['ok' => true, 'completado' => true, 'hash' => $hash, 'message' => 'Checklist de origen completado y bloqueado.']);
    } else {
        echo json_encode(['ok' => true, 'completado' => false, 'message' => $completar ? 'Faltan items por completar.' : 'Progreso guardado.']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error: ' . $e->getMessage()]);
}
