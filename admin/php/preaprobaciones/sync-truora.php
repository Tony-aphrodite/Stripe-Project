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
$manualRev  = null;
// Probe common nested keys for manual_review_required (boolean) +
// declined_reason / failure_status (string).
foreach (['manual_review_required','manual_review'] as $k) {
    if (isset($details[$k])) { $manualRev = (int)((bool)$details[$k]); break; }
}
$declinedReason = '';
foreach (['declined_reason','rejection_reason','failure_status'] as $k) {
    if (!empty($details[$k]) && is_string($details[$k])) { $declinedReason = $details[$k]; break; }
}

// Truora's "approved" is sometimes a boolean, sometimes inferred from
// status. Be defensive.
$approved = null;
if (isset($details['approved']))            $approved = (int)((bool)$details['approved']);
elseif ($status === 'valid')                 $approved = 1;
elseif (in_array((string)$status, ['invalid','failed','failure','rejected'], true)) $approved = 0;

// Name + CURP match — re-evaluate against what the customer entered.
$expectedName = trim((string)($verifRow['expected_name'] ?? ''));
$expectedCurp = trim((string)($verifRow['expected_curp'] ?? ''));
$nameMatch = null; $curpMatch = null;
if ($expectedName !== '' && $nameInfo && !empty($nameInfo['full_name'])) {
    $nameMatch = truoraNamesMatch($expectedName, $nameInfo['full_name']) ? 1 : 0;
}
if ($expectedCurp !== '' && $curpFound) {
    $curpMatch = (strtoupper($expectedCurp) === strtoupper($curpFound)) ? 1 : 0;
}

// ── Step 6: scan the payload for downloadable image URLs ──────────────────
// Truora flow versions vary in field naming, so walk the entire response
// tree and collect every string that looks like an HTTP(S) URL pointing
// at an image (or a Truora-signed URL). Classify by context keywords from
// the surrounding field name.
$imageUrls = []; // associative: ['ine_frente'|'ine_reverso'|'selfie' => url]
$walk = function ($node, $contextKey = '') use (&$walk, &$imageUrls) {
    if (is_array($node)) {
        foreach ($node as $k => $v) {
            $walk($v, is_string($k) ? strtolower((string)$k) : $contextKey);
        }
        return;
    }
    if (!is_string($node)) return;
    if (!preg_match('#^https?://#i', $node)) return;
    // Image-only filter — accept either a known image extension OR a
    // Truora-signed URL (which doesn't carry an extension).
    $isImg  = preg_match('#\.(jpe?g|png|webp|heic)(\?|$)#i', $node);
    $isTru  = (stripos($node, 'truora') !== false);
    if (!$isImg && !$isTru) return;
    // Heuristic classification.
    $hay = $contextKey . ' ' . strtolower($node);
    $key = null;
    if (preg_match('/(selfie|liveness|face|portrait)/', $hay))           $key = 'selfie';
    elseif (preg_match('/(reverse|reverso|back|trasera|trasero)/', $hay)) $key = 'ine_reverso';
    elseif (preg_match('/(front|frente|delantera|delantero|obverse)/', $hay)) $key = 'ine_frente';
    elseif (preg_match('/(document|ine|id_card|identification)/', $hay)) $key = 'ine_frente';
    if ($key && empty($imageUrls[$key])) {
        $imageUrls[$key] = $node;
    }
};
$walk($details);

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
