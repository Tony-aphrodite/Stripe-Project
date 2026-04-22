<?php
/**
 * Voltika Admin - Face Comparison for Delivery
 *
 * Compares a new photo (taken at pickup) with the stored selfie from credit application.
 *
 * POST (multipart/form-data):
 *   moto_id  - motorcycle ID
 *   foto     - new photo file taken at delivery
 *   tipo     - 'credito' (face comparison) | 'contado' (store only)
 *
 * For crédito: uses Truora face validation to compare faces
 * For contado/MSI: just stores the photo + INE for records
 */

header('Content-Type: application/json');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-auth.php';
requireDealerAuth(true);

$motoId = intval($_POST['moto_id'] ?? 0);
$tipo   = $_POST['tipo'] ?? 'credito';

if (!$motoId) {
    echo json_encode(['ok' => false, 'error' => 'moto_id requerido']);
    exit;
}

// ── Save uploaded photo ──────────────────────────────────────────────────────
$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

$savedFiles = [];
$fileFields = ['foto', 'ine_foto'];

foreach ($fileFields as $field) {
    if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES[$field]['tmp_name'];
        $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION)) ?: 'jpg';

        // Validate image
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmpName);
        finfo_close($finfo);
        if (strpos($mime, 'image/') !== 0) continue;
        if ($_FILES[$field]['size'] > 10 * 1024 * 1024) continue;

        $destName = 'delivery_' . $motoId . '_' . $field . '_' . date('Ymd_His') . '.' . $ext;
        $destPath = $uploadDir . '/' . $destName;
        if (move_uploaded_file($tmpName, $destPath)) {
            $savedFiles[$field] = $destName;
        }
    }
}

if (empty($savedFiles['foto'])) {
    echo json_encode(['ok' => false, 'error' => 'No se recibió la foto']);
    exit;
}

$pdo = getDB();

// ── For contado/MSI: just store the photo ────────────────────────────────────
if ($tipo !== 'credito') {
    // Save reference in entregas or log
    try {
        $stmt = $pdo->prepare("UPDATE inventario_motos SET notas = CONCAT(IFNULL(notas,''), '\n[Entrega] Foto tomada: " . $savedFiles['foto'] . "') WHERE id = ?");
        $stmt->execute([$motoId]);
    } catch (PDOException $e) {
        error_log('Face compare DB error: ' . $e->getMessage());
    }

    echo json_encode([
        'ok' => true,
        'tipo' => $tipo,
        'comparison' => false,
        'message' => 'Foto de entrega guardada correctamente',
        'files' => $savedFiles
    ]);
    exit;
}

// ── For crédito: find original selfie and compare ────────────────────────────
$stmt = $pdo->prepare("SELECT cliente_email, cliente_telefono, cliente_nombre FROM inventario_motos WHERE id = ?");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$moto) {
    echo json_encode(['ok' => false, 'error' => 'Moto no encontrada']);
    exit;
}

// Find original selfie from verificaciones_identidad
$originalSelfie = null;
$conditions = [];
$params = [];

if (!empty($moto['cliente_email'])) {
    $conditions[] = "email = ?";
    $params[] = $moto['cliente_email'];
}
if (!empty($moto['cliente_telefono'])) {
    $conditions[] = "telefono = ?";
    $params[] = $moto['cliente_telefono'];
}

if (!empty($conditions)) {
    $sql = "SELECT files_saved FROM verificaciones_identidad WHERE (" . implode(' OR ', $conditions) . ") ORDER BY freg DESC LIMIT 1";
    $stmt2 = $pdo->prepare($sql);
    $stmt2->execute($params);
    $verif = $stmt2->fetch(PDO::FETCH_ASSOC);

    if ($verif) {
        $files = json_decode($verif['files_saved'], true) ?: [];
        foreach ($files as $f) {
            if (strpos($f, '_selfie') !== false) {
                $originalSelfie = $uploadDir . '/' . $f;
                break;
            }
        }
    }
}

if (!$originalSelfie || !file_exists($originalSelfie)) {
    echo json_encode([
        'ok' => true,
        'comparison' => false,
        'message' => 'No se encontró selfie original del cliente. Foto de entrega guardada.',
        'files' => $savedFiles
    ]);
    exit;
}

// ── Truora Face Validation API ───────────────────────────────────────────────
$newPhotoPath = $uploadDir . '/' . $savedFiles['foto'];

// Uses the same endpoint + payload pattern as verificar-identidad.php
// (api.truora.com/v1/face-validation was legacy and is now blocked at TLS).
$ch = curl_init('https://api.checks.truora.com/v1/checks');
$postFields = [
    'country'         => 'MX',
    'type'            => 'face-recognition',
    'user_authorized' => 'true',
    'selfie_image'    => new CURLFile($newPhotoPath,   'image/jpeg', 'selfie_pickup.jpg'),
    'document_image'  => new CURLFile($originalSelfie, 'image/jpeg', 'selfie_original.jpg'),
];

curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postFields,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Truora-API-Key: ' . TRUORA_API_KEY,
    ],
    CURLOPT_TIMEOUT        => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

// Log
$logFile = __DIR__ . '/logs/face-compare.log';
@file_put_contents($logFile, json_encode([
    'timestamp' => date('c'),
    'moto_id'   => $motoId,
    'httpCode'  => $httpCode,
    'response'  => $response,
    'curlErr'   => $curlErr,
]) . "\n", FILE_APPEND | LOCK_EX);

// Parse result
$result = json_decode($response, true);

if ($curlErr || $httpCode < 200 || $httpCode >= 300) {
    // API failed — fallback to manual comparison
    echo json_encode([
        'ok' => true,
        'comparison' => false,
        'api_error' => true,
        'message' => 'No se pudo realizar la comparación automática. Se requiere verificación visual manual.',
        'original_selfie' => 'php/uploads/' . basename($originalSelfie),
        'pickup_photo' => 'php/uploads/' . $savedFiles['foto'],
        'files' => $savedFiles
    ]);
    exit;
}

// Truora returns similarity score. New api.checks.truora.com response shape
// nests fields under "check"; older shapes kept as fallbacks.
$check      = $result['check'] ?? $result;
$similarity = $check['face_recognition_score']
           ?? $result['face_validation']['similarity']
           ?? $check['score']
           ?? $result['similarity']
           ?? $result['score']
           ?? null;
$match      = $result['face_validation']['match'] ?? $check['match'] ?? $result['match'] ?? null;

// Determine if faces match (threshold: 70%)
$isMatch = false;
if ($match === true || $match === 'true') {
    $isMatch = true;
} elseif ($similarity !== null && $similarity >= 0.70) {
    $isMatch = true;
}

// Save result to DB
try {
    $stmt = $pdo->prepare("UPDATE inventario_motos SET notas = CONCAT(IFNULL(notas,''), ?) WHERE id = ?");
    $nota = "\n[Face Compare " . date('Y-m-d H:i') . '] ' .
        ($isMatch ? 'MATCH' : 'NO MATCH') .
        ' (similarity: ' . ($similarity ?? 'N/A') . ') ' .
        'Foto: ' . $savedFiles['foto'];
    $stmt->execute([$nota, $motoId]);
} catch (PDOException $e) {
    error_log('Face compare DB save error: ' . $e->getMessage());
}

echo json_encode([
    'ok' => true,
    'comparison' => true,
    'match' => $isMatch,
    'similarity' => $similarity,
    'message' => $isMatch
        ? 'Las caras coinciden. La persona que recoge es la misma del crédito.'
        : 'Las caras NO coinciden. Verificar identidad manualmente.',
    'original_selfie' => 'php/uploads/' . basename($originalSelfie),
    'pickup_photo' => 'php/uploads/' . $savedFiles['foto'],
    'files' => $savedFiles
]);
