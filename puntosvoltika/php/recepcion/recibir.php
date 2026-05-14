<?php
/**
 * POST — Punto recibe moto: scan VIN, checklist, fotos
 *
 * Body (legacy fields kept):
 *   envio_id, moto_id, vin_escaneado,
 *   estado_fisico_ok, sin_danos, componentes_completos, bateria_ok,
 *   fotos:[], notas
 *
 * Bug 3.3 (customer brief 2026-05-08): the checklist must be more detailed.
 * NEW optional fields (all backward-compatible — older clients still work):
 *   vin_caja            text   — VIN written on the box outside the moto
 *   sello_numero        text   — security seal number applied
 *   sello_intacto       0|1    — seal applied AND not violated
 *   foto_sello          base64 — photo of the seal
 *   foto_vin_label      base64 — photo of the VIN label
 *   foto_unidad         base64 — photo of the unit reception
 *   observaciones       text   — broader notes (in addition to legacy 'notas')
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$d = puntoJsonIn();
$envioId = (int)($d['envio_id'] ?? 0);
$motoId  = (int)($d['moto_id'] ?? 0);
$vinScan = trim($d['vin_escaneado'] ?? '');

if (!$envioId || !$motoId || !$vinScan) puntoJsonOut(['error' => 'Datos incompletos'], 400);

$pdo = getDB();

// Bug 3.3 — idempotent migration: add new columns if missing. Wrapped in
// try/catch so a single failure doesn't abort the whole reception. This
// preserves the existing schema for installs that already have the
// columns added through a separate migration script.
$newCols = [
    'vin_caja'         => "VARCHAR(40) NULL",
    'vin_caja_coincide'    => "TINYINT(1) NULL DEFAULT NULL",
    'vin_mismatch_confirmed' => "TINYINT(1) NOT NULL DEFAULT 0",
    'sello_numero'     => "VARCHAR(60) NULL",
    'sello_intacto'    => "TINYINT(1) NULL DEFAULT NULL",
    'foto_sello_url'   => "VARCHAR(255) NULL",
    'foto_vin_label_url' => "VARCHAR(255) NULL",
    'foto_unidad_url'  => "VARCHAR(255) NULL",
    'observaciones'    => "TEXT NULL",
];
try {
    $existing = $pdo->query("SHOW COLUMNS FROM recepcion_punto")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($newCols as $col => $def) {
        if (!in_array($col, $existing, true)) {
            try { $pdo->exec("ALTER TABLE recepcion_punto ADD COLUMN $col $def"); }
            catch (Throwable $e) { error_log("recepcion_punto add $col: " . $e->getMessage()); }
        }
    }
} catch (Throwable $e) {
    // Even SHOW COLUMNS may fail on permission-restricted instances. We log
    // and proceed — the INSERT below uses only legacy columns when the new
    // ones aren't present (column existence is re-checked just before).
    error_log('recepcion_punto migrate: ' . $e->getMessage());
}

// Verify moto belongs to this envio
$stmt = $pdo->prepare("SELECT m.*, e.id as envio_id FROM envios e
    JOIN inventario_motos m ON m.id=e.moto_id
    WHERE e.id=? AND e.moto_id=? AND e.punto_destino_id=?");
$stmt->execute([$envioId, $motoId, $ctx['punto_id']]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) puntoJsonOut(['error' => 'Envío no corresponde a este punto'], 404);

$vinCoincide = (strcasecmp(trim($row['vin']), $vinScan) === 0) ? 1 : 0;
if (!$vinCoincide) {
    puntoJsonOut(['error' => 'VIN escaneado no coincide con la moto esperada', 'vin_esperado' => $row['vin'], 'vin_escaneado' => $vinScan], 400);
}

// Customer brief 2026-05-09 (Óscar, 5th round): the VIN PRINTED ON THE BOX
// must also be compared against the expected chassis VIN. A mismatch means
// either the wrong moto was put in the box at CEDIS or there's a packing
// error — the operator MUST acknowledge the discrepancy before we save the
// reception. The frontend sends confirm_vin_mismatch=1 after showing a
// confirm() dialog; without that flag, we reject so the situation can't
// slip through silently.
$vinCajaIn      = trim((string)($d['vin_caja'] ?? ''));
$confirmVinDiff = !empty($d['confirm_vin_mismatch']);
$vinCajaCoincide = null;
if ($vinCajaIn !== '') {
    $vinCajaCoincide = (strcasecmp(trim($row['vin']), $vinCajaIn) === 0) ? 1 : 0;
    if (!$vinCajaCoincide && !$confirmVinDiff) {
        puntoJsonOut([
            'error'          => 'El VIN impreso en la caja no coincide con el VIN esperado. Verifica que la moto correcta está en esta caja y reenvía con confirmación explícita.',
            'vin_esperado'   => $row['vin'],
            'vin_escaneado'  => $vinScan,
            'vin_caja'       => $vinCajaIn,
            'requires_confirm' => true,
        ], 409);
    }
}

// Customer brief 2026-05-09 (Óscar — "When the Voltika point receives a
// motorcycle and goes through the checklist, all fields must be filled
// out"): every checklist field must come back truthy / non-empty BEFORE
// we record the reception. The old code only flipped `completado=0` and
// stored the reception anyway as 'retenida' — that left the unit half-
// received with no audit trail of WHICH fields the operator skipped.
// Now we return a 400 with the explicit list of missing items so the
// punto UI can highlight each one.
$checks = ['estado_fisico_ok','sin_danos','componentes_completos','bateria_ok'];
$missing = [];
foreach ($checks as $c) {
    if (empty($d[$c])) $missing[] = $c;
}
// New required fields (since 2026-05-08 schema upgrade). We require the
// seal photo / VIN label photo / unit photo + seal-intact confirmation
// + seal number + VIN-on-box. Observaciones stays optional (it's free-
// form notes — required would force a sentence every time).
$requiredExtras = [
    'sello_intacto'  => 'boolean',
    'sello_numero'   => 'text',
    'vin_caja'       => 'text',
    'foto_sello'     => 'photo',
    'foto_vin_label' => 'photo',
    'foto_unidad'    => 'photo',
];
foreach ($requiredExtras as $key => $kind) {
    $v = $d[$key] ?? null;
    if ($kind === 'boolean') {
        if (empty($v)) $missing[] = $key;
    } elseif ($kind === 'photo') {
        if (!is_string($v) || trim($v) === '') $missing[] = $key;
    } else {
        if (!is_string($v) || trim($v) === '') $missing[] = $key;
    }
}
if ($missing) {
    puntoJsonOut([
        'error'   => 'Faltan campos del checklist de recepción. Completa todos los puntos antes de guardar.',
        'missing' => $missing,
        'hint'    => 'Verifica los 4 checks de estado físico, el sello (número + foto + estado intacto), VIN en caja, y las 3 fotos (sello / etiqueta VIN / unidad).',
    ], 400);
}
$allOk = true; // all required fields validated above; legacy flag kept for downstream code

// ── Bug 3.3 + Round 30 v4: persist optional photos to disk ─────────────
// Customer report 2026-05-14 (Óscar — "Uploads directory missing"): every
// previous upload to /configurador/php/uploads/recepcion/ silently failed
// because Plesk's default permissions block mkdir() under
// configurador/php/. Photos appeared "saved" (recibir.php returned ok)
// but no file existed on disk, so the historial lightbox always showed
// "No se pudo cargar la imagen". Mirror the contrato-contado.php /tmp
// fallback pattern: try the canonical path first; if mkdir or the write
// is rejected, fall back to /tmp/voltika_recepcion_fotos which is always
// writable. Persist the resolved absolute path so serve-foto.php can
// find it regardless of where it ended up.
function _puntoResolveUploadDir(): array {
    $candidates = [
        __DIR__ . '/../../../configurador/php/uploads/recepcion',
        __DIR__ . '/../../../admin/uploads/recepcion-puntos',
        sys_get_temp_dir() . '/voltika_recepcion_fotos',
    ];
    foreach ($candidates as $c) {
        if (!is_dir($c)) {
            @mkdir($c, 0775, true);
            @chmod($c, 0775);
        }
        if (is_dir($c) && is_writable($c)) {
            return [$c, basename($c)];   // [absolute, dirname for serve]
        }
    }
    return ['', ''];
}
[$uploadsDir, $uploadsLabel] = _puntoResolveUploadDir();

function _puntoSavePhoto($b64, $tipo, $motoId, $uploadsDir) {
    if (!$b64 || !is_string($b64)) return null;
    if ($uploadsDir === '')        return null;
    $clean = preg_replace('#^data:image/\w+;base64,#', '', $b64);
    $bin = base64_decode($clean);
    if (!$bin) return null;
    $fname = "{$tipo}_{$motoId}_" . time() . '_' . substr(md5(uniqid('', true)), 0, 6) . '.jpg';
    $path  = "$uploadsDir/$fname";
    $ok = @file_put_contents($path, $bin);
    if ($ok === false || $ok === 0) {
        error_log('recibir.php save failed: ' . $tipo . ' moto=' . $motoId . ' dir=' . $uploadsDir);
        return null;
    }
    // Always return through the serve helper — it knows how to look up the
    // file in any of the candidate locations.
    return '/puntosvoltika/php/recepcion/serve-foto.php?f=' . rawurlencode($fname);
}
$urlSello   = _puntoSavePhoto($d['foto_sello']     ?? null, 'sello',     $motoId, $uploadsDir);
$urlVinLbl  = _puntoSavePhoto($d['foto_vin_label'] ?? null, 'vin_label', $motoId, $uploadsDir);
$urlUnidad  = _puntoSavePhoto($d['foto_unidad']    ?? null, 'unidad',    $motoId, $uploadsDir);

// Determine which extra columns we can actually write to. If the migration
// failed silently above we degrade gracefully: only legacy fields are
// inserted, so the operator's reception still saves.
$availableCols = [];
try {
    $availableCols = $pdo->query("SHOW COLUMNS FROM recepcion_punto")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

$cols = ['envio_id','moto_id','punto_id','recibido_por','vin_escaneado','vin_coincide',
         'estado_fisico_ok','sin_danos','componentes_completos','bateria_ok','fotos','notas','completado'];
$vals = [
    $envioId, $motoId, $ctx['punto_id'], $ctx['user_id'],
    $vinScan, $vinCoincide,
    (int)($d['estado_fisico_ok'] ?? 0),
    (int)($d['sin_danos'] ?? 0),
    (int)($d['componentes_completos'] ?? 0),
    (int)($d['bateria_ok'] ?? 0),
    json_encode($d['fotos'] ?? []),
    $d['notas'] ?? '',
    $allOk ? 1 : 0
];

// Conditional column appends — only added if the schema actually has them.
$extras = [
    'vin_caja'           => trim((string)($d['vin_caja'] ?? '')),
    // Audit trail for the VIN-on-box vs chassis-VIN comparison:
    //   coincide=1 → matched; coincide=0 + confirmed=1 → operator
    //   acknowledged the discrepancy; coincide=NULL → vin_caja blank
    'vin_caja_coincide'      => $vinCajaCoincide,
    'vin_mismatch_confirmed' => $confirmVinDiff ? 1 : 0,
    'sello_numero'       => trim((string)($d['sello_numero'] ?? '')),
    'sello_intacto'      => isset($d['sello_intacto']) ? (int)!!$d['sello_intacto'] : null,
    'foto_sello_url'     => $urlSello,
    'foto_vin_label_url' => $urlVinLbl,
    'foto_unidad_url'    => $urlUnidad,
    'observaciones'      => trim((string)($d['observaciones'] ?? '')),
];
foreach ($extras as $col => $val) {
    if (!in_array($col, $availableCols, true)) continue;   // schema doesn't have it yet
    // For binary flags we want 0 (false) to be written, not skipped. Only
    // skip when the value is genuinely empty/null AND not the flag columns.
    $isFlag = in_array($col, ['vin_caja_coincide','vin_mismatch_confirmed','sello_intacto'], true);
    if (!$isFlag && ($val === '' || $val === null)) continue;
    if ($isFlag && $val === null) continue;
    $cols[] = $col;
    $vals[] = $val;
}

$placeholders = implode(',', array_fill(0, count($cols), '?'));
$ins = $pdo->prepare("INSERT INTO recepcion_punto (" . implode(',', $cols) . ") VALUES ($placeholders)");
$ins->execute($vals);

// Update envio → recibida
$pdo->prepare("UPDATE envios SET estado='recibida', fecha_recepcion=NOW(), recibido_por=? WHERE id=?")
    ->execute([$ctx['user_id'], $envioId]);

// Update moto status
$newEstado = $allOk ? 'recibida' : 'retenida';
$pdo->prepare("UPDATE inventario_motos SET estado=? WHERE id=?")->execute([$newEstado, $motoId]);

puntoLog('recibir_moto', ['moto_id' => $motoId, 'envio_id' => $envioId, 'estado' => $newEstado]);

// NOTE: The `lista_para_recoger` notification is NOT sent here. Per the flow diagram,
// the client is only notified after the point marks the moto as `lista_para_entrega`
// with a pickup date (see inventario/cambiar-estado.php). Reception alone is an
// intermediate state — assembly + pickup date come next.

puntoJsonOut(['ok' => true, 'recepcion_id' => $pdo->lastInsertId(), 'estado' => $newEstado]);
