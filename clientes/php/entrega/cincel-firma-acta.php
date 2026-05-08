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

// ── 2. Generate the ACTA PDF inline (no internal cURL — Plesk/shared
//      hosting often blocks self-loopback HTTP, which timed out the previous
//      implementation). The same FPDF logic from acta-pdf.php is inlined
//      here. acta-pdf.php stays available for the preview/download path.
$pdfUrl  = null;
$pdfPath = null;
try {
    // Punto info — for delivery address
    $punto = null;
    if (!empty($moto['punto_voltika_id'])) {
        $pq = $pdo->prepare("SELECT nombre, ciudad, estado, direccion FROM puntos_voltika WHERE id=?");
        $pq->execute([(int)$moto['punto_voltika_id']]);
        $punto = $pq->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Locate FPDF (reuses admin's vendored copy)
    $fpdfPaths = [
        __DIR__ . '/../../../admin/php/lib/fpdf.php',
        __DIR__ . '/../../../admin_test/php/lib/fpdf.php',
        __DIR__ . '/../../../configurador/php/vendor/fpdf/fpdf.php',
        __DIR__ . '/../../../configurador/php/vendor/setasign/fpdf/fpdf.php',
    ];
    $fpdfFound = false;
    foreach ($fpdfPaths as $fp) {
        if (file_exists($fp)) { require_once $fp; $fpdfFound = true; break; }
    }
    if (!$fpdfFound) {
        portalJsonOut(['error' => 'FPDF no disponible en el servidor'], 500);
    }

    $enc = function($s) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string)$s); };

    $pdf = new FPDF('P', 'mm', 'Letter');
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetTitle($enc('Acta de Entrega - Voltika'));
    $pdf->SetAuthor('Voltika - MTECH GEARS, S.A. DE C.V.');
    $pdf->AddPage();

    $folio = 'ACT-' . $motoId . '-' . date('Ymd-His');
    $fechaEntrega = date('d/m/Y H:i');

    // Brand bar
    $pdf->SetFillColor(26, 58, 92);
    $pdf->Rect(0, 0, 215.9, 11, 'F');
    $pdf->SetTextColor(255);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetXY(15, 3);
    $pdf->Cell(0, 5, $enc('VOLTIKA · ACTA DE ENTREGA DE MOTOCICLETA ELÉCTRICA'), 0, 0, 'L');
    $pdf->SetTextColor(0);
    $pdf->SetY(16);

    // Subtitle + folio
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(95, 4, $enc('FOLIO: ' . $folio), 0, 0);
    $pdf->Cell(95, 4, $enc('FECHA Y HORA: ' . $fechaEntrega), 0, 1, 'R');
    $pdf->Ln(2);

    $pdf->SetFont('Arial', 'B', 13);
    $pdf->Cell(0, 7, $enc('ACTA DE ENTREGA DE VEHÍCULO'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->Cell(0, 4, $enc('MTECH GEARS, S.A. DE C.V. — Voltika'), 0, 1, 'C');
    $pdf->Ln(3);

    $nombreCompleto = trim(($moto['cliente_nombre'] ?? ''));
    $puntoNombre    = $punto['nombre'] ?? '';
    $puntoCiudad    = $punto['ciudad'] ?? ($moto['ciudad'] ?? 'México');

    // Opening declaration
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 4.5, $enc('En este acto, EL CLIENTE declara haber recibido la motocicleta eléctrica descrita en el presente documento, en condiciones óptimas de funcionamiento, completa y conforme a lo contratado.'), 0, 'J');
    $pdf->Ln(3);

    // Datos
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 5, $enc('DATOS DE LA OPERACIÓN'), 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $rows = [
        ['Cliente',         $nombreCompleto],
        ['Modelo',          $moto['modelo']  ?? '—'],
        ['Color',           $moto['color']   ?? '—'],
        ['VIN / NIV',       $moto['vin_display'] ?? $moto['vin'] ?? '—'],
        ['Pedido / folio',  $moto['pedido_num']  ?? '—'],
        ['Fecha y hora',    $fechaEntrega],
        ['Punto de entrega', $puntoNombre . ($puntoCiudad ? ' — ' . $puntoCiudad : '')],
    ];
    foreach ($rows as $r) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(60, 5, $enc($r[0] . ':'), 0);
        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell(0, 5, $enc($r[1]), 0);
    }
    $pdf->Ln(3);

    // Cláusulas
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 5, $enc('DECLARACIONES DEL CLIENTE'), 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $decls = [
        'El vehículo fue entregado en perfectas condiciones físicas y mecánicas, con todos sus componentes y accesorios completos según el checklist verificado por el personal Voltika.',
        'A partir de este momento, el CLIENTE asume la responsabilidad total del uso, custodia y cuidado del vehículo.',
        'EL CLIENTE recibió información sobre garantía, uso correcto y medidas de seguridad del vehículo eléctrico.',
        'EL CLIENTE acreditó su identidad mediante INE original y el código OTP enviado a su teléfono registrado.',
        'EL CLIENTE acepta el contenido de la presente acta y firma electrónicamente con validez NOM-151 a través del proveedor Cincel.',
    ];
    foreach ($decls as $i => $d) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(7, 5, ($i + 1) . '.', 0);
        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell(0, 4.8, $enc($d), 0, 'J');
        $pdf->Ln(1);
    }
    $pdf->Ln(8);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 5, $enc('Firma electrónica del cliente:'), 0, 1);
    $pdf->Ln(15);
    $pdf->Line(20, $pdf->GetY(), 110, $pdf->GetY());
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(90, 5, $enc($nombreCompleto), 0, 1);
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(90, 4, $enc('Firmado mediante Cincel — NOM-151'), 0, 1);

    // Persist to disk. Walk a list of candidate dirs in order — first one
    // that's actually writable wins. We need this because shared hosts often
    // restrict write access on /configurador/php/uploads/ subfolders that
    // don't already exist. The PDF only needs to live somewhere we can read
    // it later for the Cincel multipart upload — Cincel doesn't fetch by URL.
    $filename = 'acta_cliente_' . $motoId . '_' . date('Ymd_His') . '.pdf';
    $candidateDirs = [
        // 1. Match the same convention used by guardar-firma.php (admin path).
        __DIR__ . '/../../../configurador/php/uploads/actas',
        __DIR__ . '/../../../configurador_prueba_test/php/uploads/actas',
        // 2. clientes-local uploads (we know clientes/ is writable since the
        //    portal already drops files there — see firmar-acta.php).
        __DIR__ . '/../../../configurador/php/uploads/firmas',
        // 3. Always-writable fallback: PHP temp dir.
        sys_get_temp_dir() . '/voltika_actas',
    ];
    $pdfPath = null;
    $usedDir = null;
    $writeAttempts = [];
    foreach ($candidateDirs as $dir) {
        $err = null;
        if (!is_dir($dir)) {
            // Try to create. If parent isn't writable, this just returns false.
            $made = @mkdir($dir, 0775, true);
            if (!$made) {
                $err = 'mkdir failed';
                $writeAttempts[] = "$dir → $err";
                continue;
            }
        }
        if (!is_writable($dir)) {
            $writeAttempts[] = "$dir → not writable";
            continue;
        }
        $tryPath = $dir . '/' . $filename;
        try {
            $pdf->Output('F', $tryPath);
            if (file_exists($tryPath) && filesize($tryPath) > 0) {
                $pdfPath = $tryPath;
                $usedDir = $dir;
                break;
            }
            $writeAttempts[] = "$dir → output failed";
        } catch (Throwable $e) {
            $writeAttempts[] = "$dir → " . $e->getMessage();
        }
    }
    if (!$pdfPath) {
        portalJsonOut([
            'error'    => 'No se pudo escribir el PDF en ninguna ruta de almacenamiento',
            'attempts' => $writeAttempts,
        ], 500);
    }
    error_log('cincel-firma-acta saved PDF to: ' . $pdfPath);

    // Compose a public URL only if the file is under a web-served folder.
    // The temp dir is NOT web-accessible — in that case we leave $pdfUrl null
    // and the customer portal preview link will be omitted (Cincel still
    // signs the document fine).
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'voltika.mx';
    if (strpos($usedDir, '/configurador/php/uploads/') !== false) {
        $relative = substr($usedDir, strpos($usedDir, '/configurador/'));
        $pdfUrl   = $scheme . '://' . $host . $relative . '/' . $filename;
    } else {
        $pdfUrl = null;
    }
} catch (Throwable $e) {
    error_log('cincel-firma-acta inline PDF: ' . $e->getMessage());
    portalJsonOut(['error' => 'Error generando el PDF del acta: ' . $e->getMessage()], 500);
}

if (!$pdfPath || !file_exists($pdfPath)) {
    portalJsonOut(['error' => 'PDF del acta no se pudo persistir'], 500);
}

// ── 3. Authenticate against Cincel ─────────────────────────────────────
require_once __DIR__ . '/../../../configurador/php/config.php';
if (!defined('CINCEL_API_URL') || !defined('CINCEL_EMAIL') || !defined('CINCEL_PASSWORD')) {
    portalJsonOut(['error' => 'Cincel no está configurado en este entorno'], 500);
}
$cincelUrl = rtrim(CINCEL_API_URL, '/');

// Cincel exposes two auth endpoints in the wild — /auth/login (older v3
// docs) and /auth/tokens (used by admin/php/checklists/guardar-firma.php).
// Try the same one admin uses first, fall back to /auth/login. The token
// field also varies: 'access_token' on /auth/tokens, 'token' on /auth/login.
$cincelToken = null;
$authDebug = [];
foreach (['/auth/tokens', '/auth/login'] as $authPath) {
    $ch = curl_init($cincelUrl . $authPath);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['email' => CINCEL_EMAIL, 'password' => CINCEL_PASSWORD]),
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw      = curl_exec($ch);
    $authCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);
    $auth = json_decode($raw, true);

    $authDebug[] = [
        'endpoint' => $authPath,
        'http'     => $authCode,
        'curl_err' => $err ?: null,
        // First 300 chars of the response so we can spot HTML error pages
        // (CDN rate-limit, WAF) without dumping the entire body.
        'body_preview' => $raw ? substr((string)$raw, 0, 300) : null,
    ];

    if ($authCode === 200) {
        $cincelToken = $auth['access_token'] ?? $auth['token'] ?? null;
        if ($cincelToken) break;
    }
}
if (!$cincelToken) {
    portalJsonOut([
        'error'     => 'No se pudo autenticar con Cincel',
        'detail'    => 'Ambos endpoints de auth fallaron. Revisa CINCEL_EMAIL / CINCEL_PASSWORD / CINCEL_API_URL.',
        'attempts'  => $authDebug,
        'api_url'   => $cincelUrl,
    ], 500);
}

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
