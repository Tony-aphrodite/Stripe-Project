<?php
/**
 * POST — Initiate Cincel NOM-151 signature for the ACTA DE ENTREGA from the
 *        customer portal.
 *
 * Bug 5.7 (customer brief 2026-05-08, CRITICAL):
 *   "The Customer Portal must request the signature using the Cincel
 *    signature system in order to validate the delivery document. The
 *    checkbox alone is not a valid signature."
 *
 * Flow (mirrors configurador/php/cincel-firma.php which is used for the
 * credit contract — same Cincel pipeline, ACTA-flavored payload):
 *   1. Validate ownership of the moto via portalRequireAuth.
 *   2. Generate the customer-facing ACTA PDF on disk + obtain its public URL.
 *   3. Authenticate against Cincel.
 *   4. Create document in Cincel (POST /documents, multipart) with the PDF.
 *   5. Add the customer as the lone signer.
 *   6. Request signatures.
 *   7. Persist the cincel_acta_document_id on inventario_motos so the
 *      shared cincel-webhook.php can locate this row when the signature
 *      lands.
 *   8. Return the signing_url for the portal to embed in an iframe.
 *
 * Body: { moto_id }
 * Returns: { ok, cincel_document_id, signing_url, pdf_url }
 *
 * NOTE: Does NOT touch inventario_motos.cincel_document_id (that column
 * holds the credit contract document and must not be overwritten). Uses a
 * NEW column cincel_acta_document_id created here on first run.
 */
require_once __DIR__ . '/../bootstrap.php';

$cid = portalRequireAuth();
$in  = portalJsonIn();
$motoId = (int)($in['moto_id'] ?? 0);
if (!$motoId) portalJsonOut(['error' => 'moto_id requerido'], 400);

// ── 1. Ownership check ─────────────────────────────────────────────────
$moto = portalFindOwnedMoto($cid, $motoId);
if (!$moto) portalJsonOut(['error' => 'Moto no encontrada o no te pertenece'], 404);

// Idempotency: if we already have an in-flight Cincel ACTA for this moto
// AND it isn't yet signed, return the existing signing_url instead of
// creating a duplicate Cincel document. Customer brief 2026-05-08 also
// notes the signature must NOT be re-requested if already in progress.
$pdo = getDB();
try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cincel_acta_document_id VARCHAR(255) NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cincel_acta_signing_url VARCHAR(600) NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cincel_acta_status      VARCHAR(50)  NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE inventario_motos ADD COLUMN cincel_acta_pdf_url     VARCHAR(600) NULL"); } catch (Throwable $e) {}

$existing = $pdo->prepare("SELECT cincel_acta_document_id, cincel_acta_signing_url,
        cincel_acta_status, cincel_acta_pdf_url, cliente_acta_firmada
    FROM inventario_motos WHERE id=?");
$existing->execute([$motoId]);
$ex = $existing->fetch(PDO::FETCH_ASSOC) ?: [];
if (!empty($ex['cliente_acta_firmada'])) {
    portalJsonOut([
        'ok'                  => true,
        'already_signed'      => true,
        'cincel_document_id'  => $ex['cincel_acta_document_id'] ?: null,
        'pdf_url'             => $ex['cincel_acta_pdf_url']     ?: null,
    ]);
}
if (!empty($ex['cincel_acta_document_id']) && !empty($ex['cincel_acta_signing_url'])
    && in_array(strtolower((string)$ex['cincel_acta_status']), ['pending','sent','requested',''], true)) {
    portalJsonOut([
        'ok'                 => true,
        'reused'             => true,
        'cincel_document_id' => $ex['cincel_acta_document_id'],
        'signing_url'        => $ex['cincel_acta_signing_url'],
        'pdf_url'            => $ex['cincel_acta_pdf_url'] ?: null,
    ]);
}

// ── 2. Generate the ACTA PDF — call our own POST acta-pdf.php endpoint ──
$generatorScript = __DIR__ . '/acta-pdf.php';
$pdfUrl = null; $pdfPath = null;
if (file_exists($generatorScript)) {
    // Inline include with mocked POST/json so we don't need cURL+session.
    // Buffer output and parse the JSON. This is a process-internal call
    // and stays inside the same authenticated request.
    $_ORIG_METHOD = $_SERVER['REQUEST_METHOD'];
    $_ORIG_INPUT  = null;
    $_SERVER['REQUEST_METHOD'] = 'POST';

    // Stub php://input for portalJsonIn() inside acta-pdf.php
    if (!function_exists('voltikaActaPdfStubInput')) {
        function voltikaActaPdfStubInput($json){
            $GLOBALS['__voltika_acta_pdf_stub_input'] = $json;
        }
    }
    $stubFile = sys_get_temp_dir() . '/voltika_acta_pdf_input_' . uniqid('', true) . '.json';
    file_put_contents($stubFile, json_encode(['moto_id' => $motoId]));

    // The bootstrap's portalJsonIn() reads from php://input, which we can't
    // override portably. Easier path: call the script via cURL using the
    // same session cookie so portalRequireAuth() passes.
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'voltika.mx';
    $sessionName = session_name();
    $sessionId   = session_id();

    $url = $scheme . '://' . $host . dirname($_SERVER['SCRIPT_NAME']) . '/acta-pdf.php';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Cookie: ' . $sessionName . '=' . $sessionId,
        ],
        CURLOPT_POSTFIELDS => json_encode(['moto_id' => $motoId]),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    @unlink($stubFile);

    $_SERVER['REQUEST_METHOD'] = $_ORIG_METHOD;

    if ($err || $code >= 400) {
        portalJsonOut(['error' => 'No se pudo generar el PDF del acta: ' . ($err ?: 'HTTP ' . $code)], 500);
    }
    $j = json_decode($resp, true);
    if (!$j || empty($j['ok']) || empty($j['pdf_url']) || empty($j['pdf_path'])) {
        portalJsonOut(['error' => 'Respuesta inválida del generador de PDF'], 500);
    }
    $pdfUrl  = $j['pdf_url'];
    $pdfPath = __DIR__ . '/../../../' . $j['pdf_path'];
}
if (!$pdfUrl || !$pdfPath || !file_exists($pdfPath)) {
    portalJsonOut(['error' => 'PDF del acta no disponible'], 500);
}

// ── 3. Authenticate against Cincel ─────────────────────────────────────
require_once __DIR__ . '/../../../configurador/php/config.php';
if (!defined('CINCEL_API_URL') || !defined('CINCEL_EMAIL') || !defined('CINCEL_PASSWORD')) {
    portalJsonOut(['error' => 'Cincel no está configurado en este entorno'], 500);
}
$cincelUrl = rtrim(CINCEL_API_URL, '/');

$ch = curl_init("$cincelUrl/auth/login");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['email' => CINCEL_EMAIL, 'password' => CINCEL_PASSWORD]),
    CURLOPT_TIMEOUT => 15,
]);
$auth = json_decode(curl_exec($ch), true);
$authCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($authCode !== 200 || empty($auth['token'])) {
    portalJsonOut(['error' => 'No se pudo autenticar con Cincel'], 500);
}
$cincelToken = $auth['token'];

// ── 4. Create document in Cincel ───────────────────────────────────────
$cfile = new CURLFile($pdfPath, 'application/pdf', 'acta_' . $motoId . '.pdf');
$ch = curl_init("$cincelUrl/documents");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cincelToken],
    CURLOPT_POSTFIELDS => [
        'file' => $cfile,
        'name' => 'Acta de Entrega Voltika - ' . ($moto['cliente_nombre'] ?? '') . ' - VIN ' . ($moto['vin'] ?? ''),
    ],
    CURLOPT_TIMEOUT => 30,
]);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$docResp = json_decode($raw, true);
if ($code < 200 || $code >= 300 || empty($docResp['id'])) {
    portalJsonOut([
        'error' => 'Error al crear documento en Cincel',
        'detail' => $docResp['message'] ?? substr((string)$raw, 0, 300),
    ], 500);
}
$docId = $docResp['id'];

// ── 5. Add the customer as the signer ──────────────────────────────────
$ch = curl_init("$cincelUrl/documents/$docId/signers");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $cincelToken,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'name'  => $moto['cliente_nombre']   ?? '',
        'email' => $moto['cliente_email']    ?? '',
        'phone' => $moto['cliente_telefono'] ?? '',
    ]),
    CURLOPT_TIMEOUT => 15,
]);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($code < 200 || $code >= 300) {
    portalJsonOut(['error' => 'Error al agregar firmante en Cincel'], 500);
}

// ── 6. Request signatures ──────────────────────────────────────────────
$ch = curl_init("$cincelUrl/documents/$docId/request-signatures");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $cincelToken,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => '{}',
    CURLOPT_TIMEOUT => 15,
]);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$sig = json_decode($raw, true);
if ($code < 200 || $code >= 300) {
    portalJsonOut(['error' => 'Error al solicitar la firma en Cincel'], 500);
}
$signingUrl = $sig['signing_url'] ?? ($sig['url'] ?? '');

// ── 7. Persist on inventario_motos ─────────────────────────────────────
$pdo->prepare("UPDATE inventario_motos
    SET cincel_acta_document_id = ?,
        cincel_acta_signing_url = ?,
        cincel_acta_status      = 'pending',
        cincel_acta_pdf_url     = ?
    WHERE id = ?")
    ->execute([$docId, $signingUrl, $pdfUrl, $motoId]);

portalLog('cincel_acta_iniciada', ['cliente_id' => $cid, 'detalle' => 'moto=' . $motoId . ' doc=' . $docId]);

portalJsonOut([
    'ok'                 => true,
    'cincel_document_id' => $docId,
    'signing_url'        => $signingUrl,
    'pdf_url'            => $pdfUrl,
]);
