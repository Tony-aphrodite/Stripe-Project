<?php
/**
 * Voltika Admin — Round 21 (2026-05-14).
 *
 * Customer brief (Óscar, VK-1826-0001 Carlos Ricardo Sánchez): the
 * Identidad section in the admin Documentos modal had most fields blank
 * (Truora estado, process_id, nombre coincide, última actualización,
 * manual review) and no INE/selfie photos — even though Truora's own
 * dashboard had the data. The local DB stub row had only minimal data
 * (account_id + approved flag) because the original Truora flow finished
 * after the webhook signature was misconfigured, so the post-flow
 * enrichment never ran.
 *
 * This endpoint backfills that data on-demand: given a customer's phone
 * or email, it finds the most-recent verificaciones_identidad row, walks
 * up to Truora's API via the saved truora_account_id (or process_id when
 * present), fetches the full process details, persists every standard
 * field back into the DB, and tries to download any document/selfie
 * URLs Truora exposed in the response.
 *
 * POST body:  { telefono?: string, email?: string, preap_id?: int }
 * Response :  { ok, updated, fetched: {...}, photos_downloaded: [..], reason? }
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../configurador/php/truora-api-helpers.php';

adminRequireAuth(['admin','cedis']);

$body  = adminJsonIn();
$tel   = trim((string)($body['telefono'] ?? ''));
$email = trim((string)($body['email']    ?? ''));
$preapId = (int)($body['preap_id'] ?? 0);

if ($tel === '' && $email === '' && $preapId <= 0) {
    adminJsonOut(['ok' => false, 'error' => 'parametros_faltantes',
                  'message' => 'Se requiere telefono, email o preap_id.'], 400);
}

$pdo = getDB();

// ── Step 1: fall back to preaprobaciones if only preap_id given ───────────
if ($preapId > 0 && ($tel === '' || $email === '')) {
    try {
        $st = $pdo->prepare("SELECT telefono, email FROM preaprobaciones WHERE id = ? LIMIT 1");
        $st->execute([$preapId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if ($p) {
            if ($tel === '')   $tel   = (string)($p['telefono'] ?? '');
            if ($email === '') $email = (string)($p['email']    ?? '');
        }
    } catch (Throwable $e) { error_log('sync-truora preap lookup: ' . $e->getMessage()); }
}

// ── Step 2: locate the verificaciones_identidad row to enrich ─────────────
$verifRow = null;
try {
    $st = $pdo->prepare("
        SELECT * FROM verificaciones_identidad
         WHERE (LENGTH(?) > 0 AND telefono = ?)
            OR (LENGTH(?) > 0 AND email    = ?)
         ORDER BY id DESC LIMIT 1
    ");
    $st->execute([$tel, $tel, $email, $email]);
    $verifRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    error_log('sync-truora verif lookup: ' . $e->getMessage());
}

if (!$verifRow) {
    adminJsonOut(['ok' => false, 'reason' => 'sin_verificacion',
                  'message' => 'No hay registro de verificación de identidad para este cliente. Truora nunca recibió la solicitud, o se ejecutó con datos de contacto distintos.'], 200);
}

$processId = trim((string)($verifRow['truora_process_id'] ?? ''));
$accountId = trim((string)($verifRow['truora_account_id'] ?? ''));

// ── Step 3: resolve process_id from account_id when missing ───────────────
// Customer report (Carlos VK-1826-0001): the row had truora_account_id but
// no truora_process_id because the webhook signature was misconfigured and
// never wrote the process_id back. truoraFindProcessByAccountId probes
// Truora's listing endpoints to find the most-recent process for this
// account, filtered to STRICTLY match the account_id (so we never bleed
// another customer's verdict into this row).
if ($processId === '' && $accountId !== '') {
    $found = truoraFindProcessByAccountId($accountId);
    if ($found) $processId = $found;
}

if ($processId === '') {
    adminJsonOut(['ok' => false, 'reason' => 'sin_process_id',
                  'message' => 'No se pudo resolver el process_id en Truora. account_id=' . ($accountId ?: '(vacío)') . '. Es posible que el cliente nunca completara el flujo Truora.',
                  'verif_id' => (int)$verifRow['id']], 200);
}

// ── Step 4: fetch the full process payload from Truora ────────────────────
$details = truoraFetchProcessDetails($processId);
if (!$details) {
    adminJsonOut(['ok' => false, 'reason' => 'truora_unreachable',
                  'message' => 'Truora respondió pero no devolvió datos útiles (consultar tabla truora_fetch_log para diagnóstico).',
                  'process_id' => $processId,
                  'verif_id'   => (int)$verifRow['id']], 200);
}

// ── Step 5: extract standard fields ───────────────────────────────────────
$status     = truoraExtractStatus($details);     // valid | invalid | pending | ...
$nameInfo   = truoraExtractName($details);
$curpFound  = truoraExtractCurp($details);

// Round 21 v2: recursive search for fields that the original parser
// only found at top-level. Truora response shapes vary heavily by
// flow_id — some put manual_review_required directly on the root,
// some bury it under .result, .validations[].validation_data,
// .process_data, etc. Walk the entire tree once and pick the first
// non-null match.
$_recursiveFind = function (array $node, array $keys, bool $boolish = false) use (&$_recursiveFind) {
    foreach ($node as $k => $v) {
        if (is_string($k)) {
            $kl = strtolower($k);
            foreach ($keys as $needle) {
                if ($kl === $needle || strpos($kl, $needle) !== false) {
                    if ($boolish) {
                        if (is_bool($v))   return (int)$v;
                        if (is_int($v))    return (int)((bool)$v);
                        if (is_string($v)) {
                            $sv = strtolower(trim($v));
                            if (in_array($sv, ['true','1','yes','sí','si','required'], true)) return 1;
                            if (in_array($sv, ['false','0','no','not_required'], true))     return 0;
                        }
                    } else {
                        if (is_string($v) && $v !== '') return $v;
                        if (is_int($v) || is_bool($v))  return (int)((bool)$v);
                    }
                }
            }
        }
        if (is_array($v)) {
            $found = $_recursiveFind($v, $keys, $boolish);
            if ($found !== null) return $found;
        }
    }
    return null;
};

$manualRev      = $_recursiveFind($details, ['manual_review_required','manual_review'], true);
$declinedReason = (string)($_recursiveFind($details, ['declined_reason','rejection_reason','failure_status','failure_reason'], false) ?? '');

// Truora's "approved" is sometimes a boolean, sometimes inferred from
// status. Be defensive — recursive search first, then status fallback.
$approved = $_recursiveFind($details, ['approved','is_approved','approval'], true);
if ($approved === null) {
    if ($status === 'valid')                                                              $approved = 1;
    elseif (in_array((string)$status, ['invalid','failed','failure','rejected'], true)) $approved = 0;
}

// Round 21 v2: backfill expected_name / expected_curp from
// preaprobaciones (or transacciones) when the verificaciones_identidad
// stub row was created before those fields were captured. Without
// this, name_match / curp_match stay null forever on legacy rows.
$expectedName = trim((string)($verifRow['expected_name'] ?? ''));
$expectedCurp = trim((string)($verifRow['expected_curp'] ?? ''));
if ($expectedName === '' || $expectedCurp === '') {
    try {
        $look = $pdo->prepare("
            SELECT nombre, apellido_paterno, apellido_materno, NULL AS curp_field
              FROM preaprobaciones
             WHERE (LENGTH(?) > 0 AND telefono = ?)
                OR (LENGTH(?) > 0 AND email    = ?)
             ORDER BY id DESC LIMIT 1
        ");
        $look->execute([$tel, $tel, $email, $email]);
        $pr = $look->fetch(PDO::FETCH_ASSOC);
        if ($pr && $expectedName === '') {
            $expectedName = trim(($pr['nombre'] ?? '') . ' ' .
                                 ($pr['apellido_paterno'] ?? '') . ' ' .
                                 ($pr['apellido_materno'] ?? ''));
        }
        // CURP fallback: try consultas_buro (most reliable source).
        if ($expectedCurp === '') {
            $cb = $pdo->prepare("SELECT curp FROM consultas_buro WHERE telefono = ? OR email = ? ORDER BY id DESC LIMIT 1");
            $cb->execute([$tel, $email]);
            $expectedCurp = trim((string)($cb->fetchColumn() ?: ''));
        }
    } catch (Throwable $e) { error_log('sync-truora expected backfill: ' . $e->getMessage()); }
}

// Name + CURP match — re-evaluate against what the customer entered.
$nameMatch = null; $curpMatch = null;
if ($expectedName !== '' && $nameInfo && !empty($nameInfo['full_name'])) {
    $nameMatch = truoraNamesMatch($expectedName, $nameInfo['full_name']) ? 1 : 0;
}
if ($expectedCurp !== '' && $curpFound) {
    $curpMatch = (strtoupper($expectedCurp) === strtoupper($curpFound)) ? 1 : 0;
}
// If Truora explicitly reported these per-field flags in its response
// (sometimes nested under validations[].validation_data), prefer those
// over our heuristic.
$tNameMatch = $_recursiveFind($details, ['name_match','full_name_match','match_name'], true);
$tCurpMatch = $_recursiveFind($details, ['curp_match','national_id_match','match_curp'], true);
if ($tNameMatch !== null) $nameMatch = $tNameMatch;
if ($tCurpMatch !== null) $curpMatch = $tCurpMatch;

// Final fallback for rejected verifications — when Truora rejected the
// process outright but per-field flags are still null, treat the
// rejection as a name+curp mismatch so the admin sees the failure
// reason instead of three empty rows next to "✗ Rechazado".
if ($approved === 0) {
    if ($nameMatch === null) $nameMatch = 0;
    if ($curpMatch === null) $curpMatch = 0;
    if ($manualRev === null) $manualRev = 0;  // already rejected → no manual review needed
}

// ── Step 6: scan the payload for downloadable image URLs ──────────────────
// Round 21 v2: try multiple Truora endpoints since attached_documents is
// often NOT in /v1/processes/<id> but in a separate endpoint. Then walk
// every payload looking for HTTPS URLs in image-shaped fields.
$imageSources = [$details];
$extraEndpoints = [
    '/v1/processes/' . urlencode($processId) . '/attached_documents',
    '/v1/processes/' . urlencode($processId) . '/documents',
    '/v1/processes/' . urlencode($processId) . '/attached_pictures',
    '/v1/processes/' . urlencode($processId) . '/pictures',
];
$baseApi = defined('TRUORA_IDENTITY_API_URL') ? TRUORA_IDENTITY_API_URL : 'https://api.identity.truora.com';
foreach ($extraEndpoints as $path) {
    $ch = curl_init($baseApi . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Truora-API-Key: ' . (defined('TRUORA_API_KEY') ? TRUORA_API_KEY : ''),
            'Accept: application/json',
        ],
    ]);
    $b = curl_exec($ch);
    $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    // Audit log so we know which endpoints have data.
    try {
        $pdo->prepare("INSERT INTO truora_fetch_log (process_id, url, http_code, response, curl_err)
                       VALUES (?, ?, ?, ?, NULL)")
            ->execute([$processId, $path, $c, substr((string)$b, 0, 4000)]);
    } catch (Throwable $e) {}
    if ($c >= 200 && $c < 300 && $b) {
        $arr = json_decode((string)$b, true);
        if (is_array($arr)) $imageSources[] = $arr;
    }
}

$imageUrls = []; // associative: ['ine_frente'|'ine_reverso'|'selfie' => url]
$walk = function ($node, $contextKey = '', $parentKey = '') use (&$walk, &$imageUrls) {
    if (is_array($node)) {
        foreach ($node as $k => $v) {
            $cur = is_string($k) ? strtolower((string)$k) : '';
            $walk($v, $cur ?: $contextKey, $contextKey);
        }
        return;
    }
    if (!is_string($node)) return;
    if (!preg_match('#^https?://#i', $node)) return;
    // Round 21 v2 — broader image filter. Accept:
    //   1. Known image extensions
    //   2. URLs containing "truora"
    //   3. URLs inside fields named *url|*link|*image|*picture|*document|*photo
    //   4. AWS-signed URLs (X-Amz-Signature) which is how Truora delivers most
    $hayKey  = $contextKey . ' ' . $parentKey;
    $isImg   = preg_match('#\.(jpe?g|png|webp|heic)(\?|$)#i', $node);
    $isTru   = (stripos($node, 'truora') !== false);
    $isAws   = (stripos($node, 'x-amz-signature') !== false || stripos($node, 'amazonaws.com') !== false);
    $isImgK  = preg_match('/(url|link|image|picture|document|photo|file)/', $hayKey);
    if (!$isImg && !$isTru && !$isAws && !$isImgK) return;

    $hay = $hayKey . ' ' . strtolower($node);
    $key = null;
    if (preg_match('/(selfie|liveness|face|portrait|user_picture)/', $hay))    $key = 'selfie';
    elseif (preg_match('/(reverse|reverso|back\b|trasera|trasero|verso)/', $hay)) $key = 'ine_reverso';
    elseif (preg_match('/(front|frente|delantera|delantero|obverse|anverso)/', $hay)) $key = 'ine_frente';
    elseif (preg_match('/(document_image|id_card_image|ine_image|identification_image)/', $hay)) $key = 'ine_frente';
    if ($key && empty($imageUrls[$key])) {
        $imageUrls[$key] = $node;
    }
};
foreach ($imageSources as $src) $walk($src);

// ── Step 7: try to download each classified URL to local uploads dir ──────
// Pattern matches verificar-identidad.php naming so admin-identidad.php /
// listar.php files_saved JSON path keeps working transparently.
$uploadDir = realpath(__DIR__ . '/../../../configurador/php/uploads')
          ?: __DIR__ . '/../../../configurador/php/uploads';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);

$downloaded = [];
$prefix = 'truorasync_' . preg_replace('/[^a-zA-Z0-9]/', '', $accountId ?: $processId) . '_' . time();
foreach ($imageUrls as $kind => $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 18,
        // Truora signed URLs may need the API key too; harmless on public URLs.
        CURLOPT_HTTPHEADER     => [
            'Truora-API-Key: ' . (defined('TRUORA_API_KEY') ? TRUORA_API_KEY : ''),
            'Accept: image/*,*/*',
        ],
    ]);
    $bin  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct   = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($code >= 200 && $code < 300 && $bin && strlen($bin) > 1024) {
        // Best-effort extension from MIME or URL.
        $ext = 'jpg';
        if (stripos($ct, 'png')  !== false) $ext = 'png';
        if (stripos($ct, 'webp') !== false) $ext = 'webp';
        if (preg_match('/\.(jpe?g|png|webp|heic)(\?|$)/i', $url, $m)) $ext = strtolower($m[1]);
        $fname = $prefix . '_' . $kind . '.' . $ext;
        $dest  = $uploadDir . '/' . $fname;
        if (@file_put_contents($dest, $bin) !== false) {
            $downloaded[$kind] = $fname;
        }
    }
}

// ── Step 8: merge new file names with whatever files_saved already had ────
$existingFiles = [];
if (!empty($verifRow['files_saved'])) {
    $decoded = @json_decode((string)$verifRow['files_saved'], true);
    if (is_array($decoded)) $existingFiles = $decoded;
}
// Drop legacy entries for the same kinds we just refreshed.
$existingFiles = array_values(array_filter($existingFiles, function($fn) use ($downloaded) {
    if (!is_string($fn)) return false;
    $l = strtolower($fn);
    if (isset($downloaded['selfie'])     && strpos($l, '_selfie')      !== false) return false;
    if (isset($downloaded['ine_frente']) && strpos($l, '_ine_frente')  !== false) return false;
    if (isset($downloaded['ine_reverso']) && strpos($l, '_ine_reverso') !== false) return false;
    return true;
}));
foreach ($downloaded as $kind => $fname) {
    // Re-name so admin-identidad.php's substring matcher keeps working.
    $rename = preg_replace('/_(' . $kind . ')\./', '_' . $kind . '.', $fname);
    if ($rename === null) $rename = $fname;
    $existingFiles[] = $rename;
}

// ── Step 9: persist every backfilled field ────────────────────────────────
// Audit the raw Truora payload too so future investigations can replay it.
try {
    @$pdo->exec("ALTER TABLE verificaciones_identidad ADD COLUMN raw_truora_payload MEDIUMTEXT NULL");
} catch (Throwable $e) {}

// Round 21 v4 (2026-05-14, Óscar — Brayan #69): sync was downgrading a
// previously-approved row from status='success' / approved=1 to
// status='failure' / approved=0 when Truora's API later reported the
// process as failure (e.g. expired or invalidated). Never overwrite a
// "better" verdict with a worse one — the historical approval is the
// legal evidence we relied on at purchase time. Only upgrade.
$prevStatus    = strtolower((string)($verifRow['truora_status'] ?? ''));
$prevApproved  = $verifRow['approved'];
$wasApproved   = ($prevApproved == 1) || in_array($prevStatus, ['success','valid'], true);

if ($wasApproved) {
    // Stay approved/success regardless of what Truora returns now.
    if ($status === 'failure' || in_array((string)$status, ['invalid','failed','rejected'], true)) {
        $status   = $prevStatus ?: 'success';
        $approved = 1;
        // Don't pollute the rejection-reason field with a stale failure either.
        $declinedReason = '';
    }
}

$updates = [
    'truora_process_id'       => $processId ?: null,
    'truora_status'           => $status    ?: null,
    'truora_declined_reason'  => $declinedReason ?: null,
    'name_match'              => $nameMatch,
    'curp_match'              => $curpMatch,
    'verified_name'           => $nameInfo ? ($nameInfo['full_name'] ?? null) : null,
    'verified_curp'           => $curpFound,
    'manual_review_required'  => $manualRev,
    'approved'                => $approved,
    'files_saved'             => json_encode(array_values($existingFiles), JSON_UNESCAPED_SLASHES),
    'truora_updated_at'       => gmdate('Y-m-d H:i:s'),
    'raw_truora_payload'      => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
];
$sets = []; $args = [];
foreach ($updates as $col => $val) {
    if ($val === null && in_array($col, ['truora_process_id','truora_status','verified_name','verified_curp','truora_declined_reason'], true)) {
        // Don't overwrite existing strings with NULL.
        continue;
    }
    $sets[] = "$col = ?";
    $args[] = $val;
}
$args[] = (int)$verifRow['id'];

try {
    $up = $pdo->prepare("UPDATE verificaciones_identidad SET " . implode(', ', $sets) . " WHERE id = ?");
    $up->execute($args);
} catch (Throwable $e) {
    error_log('sync-truora update: ' . $e->getMessage());
    adminJsonOut(['ok' => false, 'error' => 'update_failed', 'detail' => $e->getMessage()], 500);
}

// Log the sync action so future audits know who triggered it + when.
try {
    adminLog('sync_truora', [
        'verif_id'    => (int)$verifRow['id'],
        'process_id'  => $processId,
        'account_id'  => $accountId,
        'photos'      => array_keys($downloaded),
        'status'      => $status,
        'approved'    => $approved,
    ]);
} catch (Throwable $e) { /* non-fatal */ }

adminJsonOut([
    'ok'       => true,
    'updated'  => true,
    'verif_id' => (int)$verifRow['id'],
    'fetched'  => [
        'process_id'   => $processId,
        'account_id'   => $accountId,
        'status'       => $status,
        'approved'     => $approved,
        'name_match'   => $nameMatch,
        'curp_match'   => $curpMatch,
        'manual_review_required' => $manualRev,
        'verified_name'=> $nameInfo['full_name'] ?? null,
        'verified_curp'=> $curpFound,
    ],
    'photos_downloaded' => array_keys($downloaded),
    'photos_count'      => count($downloaded),
]);
