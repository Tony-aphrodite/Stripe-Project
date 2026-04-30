<?php
/**
 * POST — Initiate Cincel NOM-151 digital signature process
 * Body: { "moto_id": 123, "contrato_pdf_url": "https://..." }
 *
 * Flow:
 *   1. Validate moto exists and has a customer
 *   2. Create document in Cincel API
 *   3. Add signer to document
 *   4. Request signatures
 *   5. Store cincel_document_id in DB
 *   6. Return signing URL
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST,OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function jsonOut($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['ok' => false, 'error' => 'Metodo no permitido'], 405);
}

$d = json_decode(file_get_contents('php://input'), true) ?: [];
$motoId        = (int)($d['moto_id'] ?? 0);
$contratoPdfUrl = trim($d['contrato_pdf_url'] ?? '');

if (!$motoId) jsonOut(['ok' => false, 'error' => 'moto_id requerido'], 400);
if (!$contratoPdfUrl) jsonOut(['ok' => false, 'error' => 'contrato_pdf_url requerido'], 400);

// Check Cincel config
if (!defined('CINCEL_API_URL') || !CINCEL_API_URL) {
    jsonOut(['ok' => false, 'error' => 'Cincel no configurado'], 500);
}

$cincelUrl = rtrim(CINCEL_API_URL, '/');

// ── Authenticate with Cincel ────────────────────────────────────────────────
$cincelEmail    = defined('CINCEL_EMAIL')    ? CINCEL_EMAIL    : '';
$cincelPassword = defined('CINCEL_PASSWORD') ? CINCEL_PASSWORD : '';
if (!$cincelEmail || !$cincelPassword) {
    jsonOut(['ok' => false, 'error' => 'Cincel no configurado (credenciales)'], 500);
}

$ch = curl_init("$cincelUrl/auth/login");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode([
        'email'    => $cincelEmail,
        'password' => $cincelPassword,
    ]),
]);
$authRaw  = curl_exec($ch);
$authCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$authResult = json_decode($authRaw, true);

if ($authCode !== 200 || empty($authResult['token'])) {
    jsonOut(['ok' => false, 'error' => 'No se pudo autenticar con Cincel', 'step' => 0], 500);
}
$cincelToken = $authResult['token'];

// ── Validate moto + customer ────────────────────────────────────────────────
$pdo = getDB();

$stmt = $pdo->prepare("
    SELECT m.*, m.cliente_nombre, m.cliente_email, m.cliente_telefono
    FROM inventario_motos m
    WHERE m.id = ?
");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$moto) jsonOut(['ok' => false, 'error' => 'Moto no encontrada'], 404);
if (empty($moto['cliente_nombre']) || empty($moto['cliente_email'])) {
    jsonOut(['ok' => false, 'error' => 'La moto no tiene un cliente asignado con datos completos'], 400);
}

// ── Ensure cincel_document_id column exists ─────────────────────────────────
try {
    $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cincel_document_id VARCHAR(255) NULL");
} catch (Throwable $e) {
    // Column likely already exists
}

// ── Step 1: Download PDF and create document in Cincel ──────────────────────
$pdfContent = @file_get_contents($contratoPdfUrl);
if (!$pdfContent) {
    jsonOut(['ok' => false, 'error' => 'No se pudo descargar el PDF del contrato', 'step' => 1], 500);
}

$tmpFile = tempnam(sys_get_temp_dir(), 'cincel_') . '.pdf';
file_put_contents($tmpFile, $pdfContent);

$cfile = new CURLFile($tmpFile, 'application/pdf', 'contrato_' . $motoId . '.pdf');
$ch = curl_init("$cincelUrl/documents");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $cincelToken,
    ],
    CURLOPT_POSTFIELDS     => [
        'file' => $cfile,
        'name' => 'Contrato Voltika - ' . $moto['cliente_nombre'] . ' - VIN ' . ($moto['vin'] ?? ''),
    ],
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
@unlink($tmpFile);

$docResult = json_decode($raw, true);
if ($code < 200 || $code >= 300 || empty($docResult['id'])) {
    jsonOut(['ok' => false, 'error' => 'Error al crear documento en Cincel: ' . ($docResult['message'] ?? $raw), 'step' => 1], 500);
}

$docId = $docResult['id'];

// ── Step 2: Add signer ─────────────────────────────────────────────────────
$ch = curl_init("$cincelUrl/documents/$docId/signers");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $cincelToken,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS     => json_encode([
        'name'  => $moto['cliente_nombre'],
        'email' => $moto['cliente_email'],
        'phone' => $moto['cliente_telefono'] ?? '',
    ]),
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$signerResult = json_decode($raw, true);
if ($code < 200 || $code >= 300) {
    jsonOut(['ok' => false, 'error' => 'Error al agregar firmante en Cincel: ' . ($signerResult['message'] ?? $raw), 'step' => 2], 500);
}

// ── Step 3: Request signatures ──────────────────────────────────────────────
$ch = curl_init("$cincelUrl/documents/$docId/request-signatures");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $cincelToken,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS     => '{}',
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$sigResult = json_decode($raw, true);
if ($code < 200 || $code >= 300) {
    jsonOut(['ok' => false, 'error' => 'Error al solicitar firmas en Cincel: ' . ($sigResult['message'] ?? $raw), 'step' => 3], 500);
}

$signingUrl = $sigResult['signing_url'] ?? ($sigResult['url'] ?? '');

// ── Step 4: Store in DB ─────────────────────────────────────────────────────
$pdo->prepare("UPDATE inventario_motos SET cincel_document_id = ? WHERE id = ?")
    ->execute([$docId, $motoId]);

jsonOut([
    'ok'                  => true,
    'cincel_document_id'  => $docId,
    'signing_url'         => $signingUrl,
]);
