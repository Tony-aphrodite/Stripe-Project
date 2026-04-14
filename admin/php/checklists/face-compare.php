<?php
/**
 * POST (multipart) — Face comparison for delivery checklist
 * Fields: moto_id, foto (file)
 * Finds original selfie from verificaciones_identidad, compares via Truora API
 * Saves result to checklist_entrega_v2
 */
require_once __DIR__ . '/../bootstrap.php';
$uid = adminRequireAuth(['admin','cedis']);

$motoId = (int)($_POST['moto_id'] ?? 0);
if (!$motoId) {
    echo json_encode(['ok' => false, 'error' => 'moto_id requerido']);
    exit;
}

// Validate uploaded photo
if (empty($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'No se recibió la foto']);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($_FILES['foto']['tmp_name']);
if (strpos($mime, 'image/') !== 0) {
    echo json_encode(['ok' => false, 'error' => 'El archivo no es una imagen']);
    exit;
}

// Save uploaded photo
$uploadDir = sys_get_temp_dir() . '/voltika_checklists/';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);

$ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime] ?? 'jpg';
$filename = 'face_' . $motoId . '_' . time() . '.' . $ext;
$destPath = $uploadDir . $filename;

if (!move_uploaded_file($_FILES['foto']['tmp_name'], $destPath)) {
    echo json_encode(['ok' => false, 'error' => 'Error al guardar foto']);
    exit;
}

$pdo = getDB();

// Get client info to find original selfie
$stmt = $pdo->prepare("SELECT cliente_email, cliente_telefono FROM inventario_motos WHERE id=?");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) {
    echo json_encode(['ok' => false, 'error' => 'Moto no encontrada']);
    exit;
}

// Find original selfie from verificaciones_identidad
$originalSelfie = null;
$conditions = []; $params = [];
if (!empty($moto['cliente_email'])) { $conditions[] = "email=?"; $params[] = $moto['cliente_email']; }
if (!empty($moto['cliente_telefono'])) { $conditions[] = "telefono=?"; $params[] = $moto['cliente_telefono']; }

$selfieDir = __DIR__ . '/../../configurador_prueba/php/uploads/';

if ($conditions) {
    $sql = "SELECT files_saved FROM verificaciones_identidad WHERE (" . implode(' OR ', $conditions) . ") ORDER BY freg DESC LIMIT 1";
    $vStmt = $pdo->prepare($sql);
    $vStmt->execute($params);
    $verif = $vStmt->fetch(PDO::FETCH_ASSOC);
    if ($verif) {
        $files = json_decode($verif['files_saved'], true) ?: [];
        foreach ($files as $f) {
            if (strpos($f, '_selfie') !== false) {
                $path = $selfieDir . $f;
                if (file_exists($path)) $originalSelfie = $path;
                break;
            }
        }
    }
}

if (!$originalSelfie) {
    // No original selfie — save photo only, skip face match
    $pdo->prepare("UPDATE checklist_entrega_v2 SET face_match_result='no_selfie' WHERE id=(SELECT id FROM (SELECT id FROM checklist_entrega_v2 WHERE moto_id=? ORDER BY freg DESC LIMIT 1) t)")
        ->execute([$motoId]);
    echo json_encode([
        'ok' => true, 'comparison' => false,
        'message' => 'No se encontró selfie original del cliente. Se requiere verificación visual.',
        'foto' => 'php/checklists/serve-foto.php?f=' . $filename,
    ]);
    exit;
}

// Call Truora Face Validation API
$apiKey = defined('TRUORA_API_KEY') ? TRUORA_API_KEY : '';
if (!$apiKey) {
    echo json_encode(['ok' => true, 'comparison' => false, 'message' => 'API key de Truora no configurada. Verificar manualmente.']);
    exit;
}

$ch = curl_init('https://api.truora.com/v1/face-validation');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'type'   => 'face-recognition',
        'image1' => new CURLFile($originalSelfie, 'image/jpeg', 'original.jpg'),
        'image2' => new CURLFile($destPath, $mime, 'delivery.jpg'),
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Truora-API-Key: ' . $apiKey],
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

$result = json_decode($response, true);

if ($curlErr || $httpCode < 200 || $httpCode >= 300) {
    // API failed
    echo json_encode([
        'ok' => true, 'comparison' => false,
        'message' => 'No se pudo realizar la comparación automática. Verificar visualmente.',
        'foto' => 'php/checklists/serve-foto.php?f=' . $filename,
    ]);
    exit;
}

$similarity = $result['face_validation']['similarity'] ?? $result['similarity'] ?? null;
$match = $result['face_validation']['match'] ?? $result['match'] ?? null;
$isMatch = ($match === true || $match === 'true' || ($similarity !== null && $similarity >= 0.70));

// Save to checklist
$matchResult = $isMatch ? 'match' : 'no_match';
$pdo->prepare("
    UPDATE checklist_entrega_v2 SET face_match_result=?, face_match_score=?
    WHERE id=(SELECT id FROM (SELECT id FROM checklist_entrega_v2 WHERE moto_id=? ORDER BY freg DESC LIMIT 1) t)
")->execute([$matchResult, $similarity, $motoId]);

adminLog('checklist_face_compare', ['moto_id' => $motoId, 'match' => $isMatch, 'score' => $similarity]);

echo json_encode([
    'ok' => true,
    'comparison' => true,
    'match' => $isMatch,
    'similarity' => $similarity,
    'result' => $matchResult,
    'message' => $isMatch
        ? 'Las caras coinciden (' . round($similarity*100, 1) . '%).'
        : 'Las caras NO coinciden. Verificar identidad manualmente.',
    'foto' => 'php/checklists/serve-foto.php?f=' . $filename,
]);
