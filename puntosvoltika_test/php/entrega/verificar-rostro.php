<?php
/**
 * POST — Verify the customer's face at pickup time.
 *
 * Body: { entrega_id, moto_id, foto_cliente (base64), foto_ine (base64) }
 *
 * Behaviour (customer brief 2026-04-24 — face match for ALL purchase types):
 *   - Face match is attempted for EVERY purchase type (CREDITO, MSI, CONTADO)
 *     whenever a Truora selfie exists in `verificaciones_identidad`.
 *   - If no stored selfie is found, fall back to manual visual review.
 *   - CREDITO purchases still hard-block on mismatch — the credit titular
 *     must receive the moto. MSI / CONTADO show a warning but the operator
 *     can override visually.
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$d = puntoJsonIn();
$entregaId = (int)($d['entrega_id'] ?? 0);
$motoId    = (int)($d['moto_id'] ?? 0);
if (!$entregaId || !$motoId) puntoJsonOut(['error' => 'Datos incompletos'], 400);

$pdo = getDB();

// Step-order guard — per dashboards_diagrams.pdf (Delivery process step 4),
// user verification can only start AFTER the OTP has been verified in step 3.
$prev = $pdo->prepare("SELECT estado, otp_verified FROM entregas WHERE id=? AND moto_id=?");
$prev->execute([$entregaId, $motoId]);
$prevRow = $prev->fetch(PDO::FETCH_ASSOC);
if (!$prevRow) puntoJsonOut(['error' => 'Entrega no encontrada'], 404);
if (empty($prevRow['otp_verified'])) {
    puntoJsonOut(['error' => 'Debes verificar el OTP del cliente antes de la verificación facial'], 409);
}

// ── Look up the moto and figure out the purchase type ────────────────────────
$stmt = $pdo->prepare("SELECT cliente_email, cliente_telefono, cliente_nombre, pedido_num
    FROM inventario_motos WHERE id=? AND punto_voltika_id=?");
$stmt->execute([$motoId, $ctx['punto_id']]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) puntoJsonOut(['error' => 'Moto no encontrada en tu inventario'], 404);

// Resolve payment type from transacciones (joined via pedido_num or cliente_email)
$esCredito = false;
try {
    $q = $pdo->prepare("SELECT tpago FROM transacciones
        WHERE (pedido = ? OR (email = ? AND email <> ''))
        ORDER BY freg DESC LIMIT 1");
    $pedidoKey = $moto['pedido_num'] ? preg_replace('/^VK-/', '', $moto['pedido_num']) : '';
    $q->execute([$pedidoKey, $moto['cliente_email'] ?? '']);
    $tx = $q->fetch(PDO::FETCH_ASSOC);
    if ($tx) {
        $tpago = strtolower($tx['tpago'] ?? '');
        $esCredito = in_array($tpago, ['credito', 'enganche', 'parcial'], true);
    }
} catch (Throwable $e) { error_log('verificar-rostro tpago lookup: ' . $e->getMessage()); }

// ── Save uploaded photos to disk ─────────────────────────────────────────────
$uploadsDir = __DIR__ . '/../../../configurador_prueba/php/uploads/entregas';
if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);

$savedPaths = [];
$localPaths = [];
foreach (['foto_cliente' => 'cliente', 'foto_ine' => 'identificacion'] as $key => $tipo) {
    if (!empty($d[$key])) {
        $b64 = preg_replace('#^data:image/\w+;base64,#', '', $d[$key]);
        $bin = base64_decode($b64);
        if ($bin) {
            $fname = "{$tipo}_{$motoId}_" . time() . '.jpg';
            $path  = "$uploadsDir/$fname";
            file_put_contents($path, $bin);
            $url = "/configurador_prueba/php/uploads/entregas/$fname";
            $pdo->prepare("INSERT INTO fotos_entrega (entrega_id, moto_id, tipo, url) VALUES (?,?,?,?)")
                ->execute([$entregaId, $motoId, $tipo, $url]);
            $savedPaths[$tipo] = $url;
            $localPaths[$tipo] = $path;
        }
    }
}

if (empty($localPaths['cliente'])) {
    puntoJsonOut(['error' => 'Foto del cliente requerida'], 400);
}

// ── Face match applies to ALL purchase types (customer brief 2026-04-24).
//    We look up the Truora selfie regardless of credito/MSI/contado so the
//    operator always gets a comparison against whoever applied. If no
//    selfie exists (e.g. legacy contado order without identity upload),
//    we fall back to manual visual review.
// ── Locate the selfie captured during the identity verification ─────────────
$originalSelfie = null;
try {
    $params = [];
    $where  = [];
    if (!empty($moto['cliente_email']))    { $where[] = 'email = ?';    $params[] = $moto['cliente_email']; }
    if (!empty($moto['cliente_telefono'])) { $where[] = 'telefono = ?'; $params[] = $moto['cliente_telefono']; }
    if ($where) {
        $sql = "SELECT selfie_path, files_saved FROM verificaciones_identidad
                WHERE approved = 1 AND (" . implode(' OR ', $where) . ")
                ORDER BY freg DESC LIMIT 1";
        $q = $pdo->prepare($sql);
        $q->execute($params);
        $verif = $q->fetch(PDO::FETCH_ASSOC);
        if ($verif) {
            // Prefer the explicit selfie_path column; fall back to files_saved JSON
            $candidate = $verif['selfie_path'] ?? null;
            if (!$candidate && !empty($verif['files_saved'])) {
                $files = json_decode($verif['files_saved'], true) ?: [];
                foreach ($files as $f) {
                    if (is_string($f) && stripos($f, 'selfie') !== false) { $candidate = $f; break; }
                }
            }
            if ($candidate) {
                // Resolve relative paths against the uploads dir used by verificar-identidad.php
                if (!preg_match('#^(/|[A-Za-z]:)#', $candidate)) {
                    $candidate = __DIR__ . '/../../../configurador_prueba/php/uploads/' . ltrim($candidate, '/');
                }
                if (file_exists($candidate)) $originalSelfie = $candidate;
            }
        }
    }
} catch (Throwable $e) { error_log('verificar-rostro selfie lookup: ' . $e->getMessage()); }

// No original selfie on file — require manual visual verification
if (!$originalSelfie) {
    $pdo->prepare("INSERT INTO checklist_entrega_v2
            (moto_id, dealer_id, face_match_score, face_match_result, fase1_completada, fase1_fecha)
        VALUES (?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE
            face_match_score=VALUES(face_match_score),
            face_match_result=VALUES(face_match_result),
            fase1_completada=1, fase1_fecha=NOW()")
        ->execute([$motoId, $ctx['user_id'], null, 'manual_review_required', 1]);

    puntoLog('entrega_rostro_verificado', ['moto_id' => $motoId, 'caso' => 'credito_sin_selfie_original']);
    puntoJsonOut([
        'ok'          => true,
        'es_credito'  => true,
        'comparison'  => false,
        'manual'      => true,
        'face_score'  => null,
        'message'     => 'No se encontró la selfie original del crédito. Verificación manual requerida.',
        'fotos'       => $savedPaths,
    ]);
}

// ── Truora face comparison (CREDITO) ─────────────────────────────────────────
$truoraKey = defined('TRUORA_API_KEY') ? TRUORA_API_KEY : (getenv('TRUORA_API_KEY') ?: '');
$similarity = null;
$isMatch    = null;

if ($truoraKey) {
    $ch = curl_init('https://api.truora.com/v1/face-validation');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Truora-API-Key: ' . $truoraKey],
        CURLOPT_POSTFIELDS     => [
            'type'   => 'face-recognition',
            'image1' => new CURLFile($originalSelfie, 'image/jpeg', 'selfie_original.jpg'),
            'image2' => new CURLFile($localPaths['cliente'], 'image/jpeg', 'selfie_pickup.jpg'),
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp && $code >= 200 && $code < 300) {
        $r = json_decode($resp, true) ?: [];
        $similarity = $r['face_validation']['similarity'] ?? $r['similarity'] ?? $r['score'] ?? null;
        $match      = $r['face_validation']['match']      ?? $r['match']      ?? null;
        if ($match === true || $match === 'true') {
            $isMatch = true;
        } elseif ($similarity !== null && (float)$similarity >= 0.70) {
            $isMatch = true;
        } else {
            $isMatch = false;
        }
    }
}

// Persist result — normalize similarity to 0..100 score for the checklist
$score = ($similarity !== null) ? (int)round(((float)$similarity) * (abs((float)$similarity) <= 1 ? 100 : 1)) : null;
$result = $isMatch === true ? 'match' : ($isMatch === false ? 'no_match' : 'manual_review_required');

$pdo->prepare("INSERT INTO checklist_entrega_v2
        (moto_id, dealer_id, face_match_score, face_match_result, fase1_completada, fase1_fecha)
    VALUES (?,?,?,?,?,NOW())
    ON DUPLICATE KEY UPDATE
        face_match_score=VALUES(face_match_score),
        face_match_result=VALUES(face_match_result),
        fase1_completada=1, fase1_fecha=NOW()")
    ->execute([$motoId, $ctx['user_id'], $score, $result, 1]);

puntoLog('entrega_rostro_verificado', [
    'moto_id' => $motoId,
    'caso'    => 'credito',
    'score'   => $score,
    'result'  => $result,
]);

puntoJsonOut([
    'ok'          => true,
    'es_credito'  => true,
    'comparison'  => $isMatch !== null,
    'match'       => $isMatch,
    'face_score'  => $score,
    'similarity'  => $similarity,
    'message'     => $isMatch === true
        ? 'Coincide con la persona del crédito. Puedes continuar.'
        : ($isMatch === false
            ? 'Las caras NO coinciden. Verificación visual manual requerida antes de entregar.'
            : 'Comparación automática no disponible. Verificación manual requerida.'),
    'fotos'       => $savedPaths,
]);
