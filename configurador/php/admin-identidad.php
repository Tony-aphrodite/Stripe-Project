<?php
/**
 * Voltika Admin - Consultar fotos de verificación de identidad
 * Busca en verificaciones_identidad por email/telefono del cliente vinculado a la moto.
 *
 * GET ?moto_id=N  → { ok, verificacion: { selfie_url, ine_frente_url, ine_reverso_url, ... } }
 */

header('Content-Type: application/json');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-auth.php';
requireDealerAuth(true);

$motoId = intval($_GET['moto_id'] ?? 0);
if (!$motoId) {
    echo json_encode(['ok' => false, 'error' => 'moto_id requerido']);
    exit;
}

$pdo = getDB();

$stmt = $pdo->prepare("SELECT cliente_email, cliente_telefono, cliente_nombre FROM inventario_motos WHERE id = ?");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$moto) {
    echo json_encode(['ok' => false, 'error' => 'Moto no encontrada']);
    exit;
}

$verif = null;
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
    $sql = "SELECT * FROM verificaciones_identidad WHERE (" . implode(' OR ', $conditions) . ") ORDER BY freg DESC LIMIT 1";
    $stmt2 = $pdo->prepare($sql);
    $stmt2->execute($params);
    $verif = $stmt2->fetch(PDO::FETCH_ASSOC);
}

if (!$verif) {
    echo json_encode([
        'ok'    => true,
        'found' => false,
        'message' => 'No se encontró verificación de identidad para este cliente'
    ]);
    exit;
}

$files     = json_decode($verif['files_saved'], true) ?: [];
$uploadUrl = 'php/uploads/';

$selfieUrl    = null;
$ineFrenteUrl = null;
$ineReversoUrl = null;

foreach ($files as $filename) {
    if (strpos($filename, '_selfie') !== false) {
        $selfieUrl = $uploadUrl . $filename;
    } elseif (strpos($filename, '_ine_frente') !== false) {
        $ineFrenteUrl = $uploadUrl . $filename;
    } elseif (strpos($filename, '_ine_reverso') !== false) {
        $ineReversoUrl = $uploadUrl . $filename;
    }
}

echo json_encode([
    'ok'    => true,
    'found' => true,
    'verificacion' => [
        'nombre'          => trim(($verif['nombre'] ?? '') . ' ' . ($verif['apellidos'] ?? '')),
        'email'           => $verif['email'] ?? '',
        'telefono'        => $verif['telefono'] ?? '',
        'truora_score'    => $verif['truora_score'],
        'identity_status' => $verif['identity_status'],
        'approved'        => (bool) $verif['approved'],
        'fecha'           => $verif['freg'],
        'selfie_url'      => $selfieUrl,
        'ine_frente_url'  => $ineFrenteUrl,
        'ine_reverso_url' => $ineReversoUrl,
    ]
]);
