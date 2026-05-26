<?php
/**
 * POST — Guardar checklist de entrega
 *
 * Bug 5.6 (customer brief 2026-05-08): the Point of Sale checklist must be
 * the FULL checklist (5 phases), matching the admin version, NOT the old
 * reduced 4-checkbox+3-photo version.
 *
 * Backward-compatible body (legacy callers — admin testing harness, etc.,
 * still send only fase3 fields and they keep working):
 *   moto_id,
 *   // Fase 1 — Identidad
 *   ine_presentada, nombre_coincide, foto_coincide, datos_confirmados,
 *   ultimos4_telefono, modelo_confirmado, forma_pago_confirmada,
 *   // Fase 2 — Pago
 *   pago_confirmado, enganche_validado, metodo_pago_registrado, domiciliacion_confirmada,
 *   // Fase 3 — Unidad (legacy fields, kept)
 *   vin_coincide, estado_fisico_ok, sin_danos, unidad_completa, unidad_ensamblada,
 *   // Photos
 *   fotos_moto:[base64]
 */
require_once __DIR__ . '/../bootstrap.php';
$ctx = puntoRequireAuth();

$d = puntoJsonIn();
$motoId = (int)($d['moto_id'] ?? 0);
if (!$motoId) puntoJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();

// Step-order guards (unchanged) — OTP + face must already be verified.
$guard = $pdo->prepare("SELECT otp_verified FROM entregas WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$guard->execute([$motoId]);
$otpOk = (int)($guard->fetchColumn() ?: 0);
if (!$otpOk) {
    puntoJsonOut(['error' => 'OTP del cliente no verificado — no se puede llenar el checklist'], 409);
}
$guard = $pdo->prepare("SELECT fase1_completada FROM checklist_entrega_v2 WHERE moto_id=? ORDER BY id DESC LIMIT 1");
$guard->execute([$motoId]);
$faceOk = (int)($guard->fetchColumn() ?: 0);
if (!$faceOk) {
    puntoJsonOut(['error' => 'Verificación facial pendiente — no se puede llenar el checklist'], 409);
}

// Idempotent column migration — checklist_entrega_v2 may not yet have all
// the fase1/fase2 columns we need. Add them silently when missing so old
// installs gracefully upgrade. Uses the same try/catch pattern as the rest
// of the codebase.
$entregaCols = [
    // Fase 1 — Identidad
    'ine_presentada'         => "TINYINT(1) NULL DEFAULT 0",
    'nombre_coincide'        => "TINYINT(1) NULL DEFAULT 0",
    'foto_coincide'          => "TINYINT(1) NULL DEFAULT 0",
    'datos_confirmados'      => "TINYINT(1) NULL DEFAULT 0",
    'ultimos4_telefono'      => "TINYINT(1) NULL DEFAULT 0",
    'modelo_confirmado'      => "TINYINT(1) NULL DEFAULT 0",
    'forma_pago_confirmada'  => "TINYINT(1) NULL DEFAULT 0",
    // Fase 2 — Pago
    'pago_confirmado'        => "TINYINT(1) NULL DEFAULT 0",
    'enganche_validado'      => "TINYINT(1) NULL DEFAULT 0",
    'metodo_pago_registrado' => "TINYINT(1) NULL DEFAULT 0",
    'domiciliacion_confirmada' => "TINYINT(1) NULL DEFAULT 0",
    // Fase 3 — Unidad (extra)
    'unidad_ensamblada'      => "TINYINT(1) NULL DEFAULT 0",
    'fase2_completada'       => "TINYINT(1) NULL DEFAULT 0",
    'fase2_fecha'            => "DATETIME NULL",
];
try {
    $existing = $pdo->query("SHOW COLUMNS FROM checklist_entrega_v2")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($entregaCols as $col => $def) {
        if (!in_array($col, $existing, true)) {
            try { $pdo->exec("ALTER TABLE checklist_entrega_v2 ADD COLUMN $col $def"); }
            catch (Throwable $e) { error_log("checklist_entrega_v2 add $col: " . $e->getMessage()); }
        }
    }
} catch (Throwable $e) { error_log('checklist_entrega_v2 migrate: ' . $e->getMessage()); }

// Save moto photos
$uploadsDir = __DIR__ . '/../../../configurador/php/uploads/entregas';
if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);

$entregaStmt = $pdo->prepare("SELECT id FROM entregas WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$entregaStmt->execute([$motoId]);
$entregaId = (int)($entregaStmt->fetchColumn() ?: 0);

if (!empty($d['fotos_moto']) && is_array($d['fotos_moto'])) {
    $tipos = ['moto_frente','moto_lateral','moto_trasera','otra'];
    foreach ($d['fotos_moto'] as $i => $b64) {
        $bin = base64_decode(preg_replace('#^data:image/\w+;base64,#','',$b64));
        if ($bin) {
            $tipo = $tipos[$i] ?? 'otra';
            $fname = "{$tipo}_{$motoId}_" . time() . "_$i.jpg";
            file_put_contents("$uploadsDir/$fname", $bin);
            $url = "/configurador/php/uploads/entregas/$fname";
            $pdo->prepare("INSERT INTO fotos_entrega (entrega_id, moto_id, tipo, url) VALUES (?,?,?,?)")
                ->execute([$entregaId, $motoId, $tipo, $url]);
        }
    }
}

// Helper — accept legacy 0|1 ints OR booleans OR missing (treats missing as 0).
$bit = function($k) use ($d) { return !empty($d[$k]) ? 1 : 0; };

// Compose all field values up front. Legacy payloads (only fase3 fields)
// produce 0 for the new fase1/fase2 fields — the row is upserted but the
// fase{1,2}_completada flags only flip when ALL fields in that fase are set.
$fields = [
    // Fase 1
    'ine_presentada'           => $bit('ine_presentada'),
    'nombre_coincide'          => $bit('nombre_coincide'),
    'foto_coincide'            => $bit('foto_coincide'),
    'datos_confirmados'        => $bit('datos_confirmados'),
    'ultimos4_telefono'        => $bit('ultimos4_telefono'),
    'modelo_confirmado'        => $bit('modelo_confirmado'),
    'forma_pago_confirmada'    => $bit('forma_pago_confirmada'),
    // Fase 2
    'pago_confirmado'          => $bit('pago_confirmado'),
    'enganche_validado'        => $bit('enganche_validado'),
    'metodo_pago_registrado'   => $bit('metodo_pago_registrado'),
    'domiciliacion_confirmada' => $bit('domiciliacion_confirmada'),
    // Fase 3 (legacy)
    'vin_coincide'             => $bit('vin_coincide'),
    'estado_fisico_ok'         => $bit('estado_fisico_ok'),
    'sin_danos'                => $bit('sin_danos'),
    'unidad_completa'          => $bit('unidad_completa'),
    'unidad_ensamblada'        => $bit('unidad_ensamblada'),
];

// Round 84 (2026-05-26) — Mandatory-fields gate. Customer brief (Óscar):
// "Solve this but put the next checklist mandatory all fields". Before
// Round 84 this endpoint silently accepted partial payloads — only the
// per-fase completion flags flipped when ALL fields were 1, but the row
// was still saved with any combination. That let UI-bypassing callers
// (devtools, legacy harnesses, manual POST) advance the entrega flow
// with an incomplete checklist, which is what produced Adrian's case
// (estado='entregada' + yellow inconsistency banner).
//
// Now: reject the save with HTTP 400 and a Spanish breakdown of what's
// missing. The legacy "partial save" mode is opt-in via { _allow_partial: 1 }
// so the older admin testing harness still works.
$labels = [
    'ine_presentada'           => 'INE presentada',
    'nombre_coincide'          => 'Nombre coincide con la orden',
    'foto_coincide'            => 'Foto de INE coincide con el cliente',
    'datos_confirmados'        => 'Datos personales confirmados',
    'ultimos4_telefono'        => 'Últimos 4 dígitos del teléfono verificados',
    'modelo_confirmado'        => 'Modelo de moto confirmado',
    'forma_pago_confirmada'    => 'Forma de pago confirmada',
    'pago_confirmado'          => 'Pago confirmado en sistema',
    'enganche_validado'        => 'Enganche validado',
    'metodo_pago_registrado'   => 'Método de pago registrado',
    'domiciliacion_confirmada' => 'Domiciliación confirmada',
    'vin_coincide'             => 'VIN coincide con la orden',
    'estado_fisico_ok'         => 'Estado físico correcto',
    'sin_danos'                => 'Sin daños visibles',
    'unidad_completa'          => 'Unidad completa (accesorios, llaves, manual)',
    'unidad_ensamblada'        => 'Unidad ensamblada (checklist ensamble completo)',
];
$allowPartial = !empty($d['_allow_partial']);
if (!$allowPartial) {
    $missing = [];
    foreach ($fields as $col => $val) {
        if ((int)$val !== 1) $missing[] = ['key' => $col, 'label' => $labels[$col] ?? $col];
    }
    if ($missing) {
        $list = array_map(fn($m) => '• ' . $m['label'], $missing);
        puntoJsonOut([
            'error'   => 'Faltan ' . count($missing) . ' verificación(es) por marcar. '
                       . 'No se puede guardar el checklist incompleto — todos los campos son obligatorios:' . "\n"
                       . implode("\n", $list),
            'code'    => 'checklist_incompleto',
            'missing' => $missing,
        ], 400);
    }
}

// Determine fase completion — only flip the flag when EVERY field in the
// fase is present, mirroring the admin's behavior.
$fase1Keys = ['ine_presentada','nombre_coincide','foto_coincide','datos_confirmados',
              'ultimos4_telefono','modelo_confirmado','forma_pago_confirmada'];
$fase2Keys = ['pago_confirmado','enganche_validado','metodo_pago_registrado','domiciliacion_confirmada'];
$fase3Keys = ['vin_coincide','estado_fisico_ok','sin_danos','unidad_completa'];

$fase1Done = !in_array(0, array_map(fn($k) => $fields[$k], $fase1Keys), true);
$fase2Done = !in_array(0, array_map(fn($k) => $fields[$k], $fase2Keys), true);
$fase3Done = !in_array(0, array_map(fn($k) => $fields[$k], $fase3Keys), true);

// We don't OVERWRITE existing fase{1,2,3}_completada=1 to 0 just because
// this payload is partial — that would let a legacy caller accidentally
// undo a previously-completed fase. We only *flip up*.
$availableCols = [];
try { $availableCols = $pdo->query("SHOW COLUMNS FROM checklist_entrega_v2")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}

$cols = ['moto_id','dealer_id'];
$vals = [$motoId, $ctx['user_id']];
foreach ($fields as $col => $val) {
    if (!in_array($col, $availableCols, true)) continue;
    $cols[] = $col;
    $vals[] = $val;
}
// Compose ON DUPLICATE KEY UPDATE — only update fields present in this payload
// AND avoid downgrading fase{1,2,3}_completada from 1 to 0.
$updateSets = [];
foreach ($fields as $col => $val) {
    if (!in_array($col, $availableCols, true)) continue;
    $updateSets[] = "$col=VALUES($col)";
}
if (in_array('fase3_completada', $availableCols, true)) {
    $updateSets[] = $fase3Done ? 'fase3_completada=1' : 'fase3_completada=fase3_completada';
    if (in_array('fase3_fecha', $availableCols, true) && $fase3Done) {
        $updateSets[] = 'fase3_fecha=NOW()';
    }
}
if (in_array('fase2_completada', $availableCols, true)) {
    $updateSets[] = $fase2Done ? 'fase2_completada=1' : 'fase2_completada=fase2_completada';
    if (in_array('fase2_fecha', $availableCols, true) && $fase2Done) {
        $updateSets[] = 'fase2_fecha=NOW()';
    }
}
// Round 103 (2026-05-26) — CRITICAL FIX. fase1_completada was being
// computed but NEVER persisted to DB (only fase2 and fase3 had the
// UPDATE/INSERT logic). Result: even after operator completed all F1
// boxes, fase1_completada stayed at 0 → Round 84 finalize gate
// rejected "F1 — Identidad" → delivery couldn't be finalized. Carlos
// Ricardo Sánchez delivery today blocked because of this. Now F1
// flag persists exactly like F2 and F3.
if (in_array('fase1_completada', $availableCols, true)) {
    $updateSets[] = $fase1Done ? 'fase1_completada=1' : 'fase1_completada=fase1_completada';
    if (in_array('fase1_fecha', $availableCols, true) && $fase1Done) {
        $updateSets[] = 'fase1_fecha=NOW()';
    }
}
// Always include into the INSERT side
$cols[] = 'fase3_completada'; $vals[] = $fase3Done ? 1 : 0;
$cols[] = 'fase3_fecha';      $vals[] = $fase3Done ? date('Y-m-d H:i:s') : null;
if (in_array('fase2_completada', $availableCols, true)) {
    $cols[] = 'fase2_completada'; $vals[] = $fase2Done ? 1 : 0;
}
if (in_array('fase2_fecha', $availableCols, true)) {
    $cols[] = 'fase2_fecha'; $vals[] = $fase2Done ? date('Y-m-d H:i:s') : null;
}
// Round 103 — F1 was missing from INSERT side too. Add it.
if (in_array('fase1_completada', $availableCols, true)) {
    $cols[] = 'fase1_completada'; $vals[] = $fase1Done ? 1 : 0;
}
if (in_array('fase1_fecha', $availableCols, true)) {
    $cols[] = 'fase1_fecha'; $vals[] = $fase1Done ? date('Y-m-d H:i:s') : null;
}

// De-dup any double appends (defensive)
$seen = []; $finalCols = []; $finalVals = [];
foreach ($cols as $i => $c) {
    if (isset($seen[$c])) continue;
    $seen[$c] = true; $finalCols[] = $c; $finalVals[] = $vals[$i];
}
$ph = implode(',', array_fill(0, count($finalCols), '?'));
$sql = "INSERT INTO checklist_entrega_v2 (" . implode(',', $finalCols) . ") VALUES ($ph)
        ON DUPLICATE KEY UPDATE " . implode(', ', $updateSets);
$pdo->prepare($sql)->execute($finalVals);

puntoLog('entrega_checklist', [
    'moto_id'    => $motoId,
    'fase1_done' => $fase1Done,
    'fase2_done' => $fase2Done,
    'fase3_done' => $fase3Done,
]);
puntoJsonOut([
    'ok' => true,
    'progreso' => [
        'fase1' => $fase1Done,
        'fase2' => $fase2Done,
        'fase3' => $fase3Done,
    ],
]);
