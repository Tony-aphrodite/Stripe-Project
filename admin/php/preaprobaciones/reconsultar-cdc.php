<?php
/**
 * Voltika Admin — Reintentar CDC para una preaprobación existente (Round 72, 2026-05-23).
 *
 * Customer brief (Óscar): después de que CDC tuvo 403.2 por desajuste
 * cert ↔ portal, varios solicitantes quedaron con `circulo_source='estimado'`
 * y CONDICIONAL_ESTIMADO (50% enganche, 12 meses) cuando en realidad
 * deberían haber tenido un score real. Cada uno de esos casos requiere
 * re-consultar CDC con los datos de la persona y recomputar la recomendación.
 *
 * Este endpoint hace exactamente eso para UN preap_id:
 *   1. Lee los datos de la persona desde preaprobaciones.
 *   2. Llama cdcQueryPersona() para hacer la consulta real a CDC.
 *   3. Actualiza el row con score, circulo_source, pago_mensual_buro,
 *      dpd90_flag, dpd_max + pti_total recomputado.
 *   4. Devuelve el resultado al frontend, que recarga la lista para
 *      que el card de recomendación se renderice con datos frescos.
 *
 * NO modifica status/enganche_requerido/plazo_max — esos los decide el
 * admin con los botones "Aprobar Plazos / Contado / MSI / Rechazar"
 * después de revisar el card actualizado.
 *
 * POST body: { preap_id: int }
 * Response : { ok, message, fetched: { score, circulo_source, pago_mensual_buro,
 *              dpd90_flag, dpd_max, pti_total, person_found }, http, diag? }
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../configurador/php/cdc-call.php';

adminRequireAuth(['admin']);

$body    = adminJsonIn();
$preapId = (int)($body['preap_id'] ?? 0);

if ($preapId <= 0) {
    adminJsonOut(['ok' => false, 'error' => 'preap_id_requerido',
                  'message' => 'Falta preap_id en el cuerpo de la petición.'], 400);
}

$pdo = getDB();

// ── Step 1: load the applicant row ────────────────────────────────────────
$row = null;
try {
    $st = $pdo->prepare("SELECT id, nombre, apellido_paterno, apellido_materno,
                                fecha_nacimiento, cp, ciudad, estado,
                                ingreso_mensual, pago_mensual
                         FROM preaprobaciones WHERE id = ? LIMIT 1");
    $st->execute([$preapId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    adminJsonOut(['ok' => false, 'error' => 'db_lookup_failed',
                  'message' => 'Error consultando preaprobaciones: ' . $e->getMessage()], 500);
}
if (!$row) {
    adminJsonOut(['ok' => false, 'error' => 'preap_no_encontrada',
                  'message' => 'No existe la preaprobación con id ' . $preapId], 404);
}

$nombre   = trim((string)($row['nombre']           ?? ''));
$paterno  = trim((string)($row['apellido_paterno'] ?? ''));
$materno  = trim((string)($row['apellido_materno'] ?? ''));
$dob      = trim((string)($row['fecha_nacimiento'] ?? ''));
$cp       = trim((string)($row['cp']               ?? ''));
$ciudad   = trim((string)($row['ciudad']           ?? ''));
$estado   = trim((string)($row['estado']           ?? ''));
$ingreso  = (float)($row['ingreso_mensual'] ?? 0);
$pagoMVK  = (float)($row['pago_mensual']    ?? 0);

if ($nombre === '' || $paterno === '' || $dob === '') {
    adminJsonOut(['ok' => false, 'error' => 'datos_insuficientes',
                  'message' => 'La preaprobación no tiene nombre/apellido/DOB suficientes para consultar CDC.'], 422);
}

// ── Step 2: query CDC ─────────────────────────────────────────────────────
$cdc = cdcQueryPersona([
    'primerNombre'    => $nombre,
    'apellidoPaterno' => $paterno,
    'apellidoMaterno' => $materno,
    'fechaNacimiento' => $dob,
    'cp'              => $cp,
    'ciudad'          => $ciudad,
    'estado'          => $estado,
]);

if (empty($cdc['ok'])) {
    adminJsonOut([
        'ok'      => false,
        'error'   => 'cdc_falló',
        'message' => 'CDC no respondió correctamente: HTTP ' . ($cdc['http'] ?? '?') .
                     ($cdc['error'] ?? '' ? ' — ' . $cdc['error'] : ''),
        'http'    => $cdc['http']     ?? 0,
        'curl_err'=> $cdc['curl_err'] ?? '',
        'diag'    => $cdc['diag']     ?? null,
    ], 502);
}

// ── Step 3: classify result + persist ─────────────────────────────────────
// circulo_source values used by the admin UI's buildRecomendacion():
//   'real'           → CDC returned a numeric score
//   'cdc_sin_score'  → CDC found persona but score is null (thin file)
//   'estimado'       → CDC unreachable (transport error) — kept for completeness
//
// person_found follows the cdcQueryPersona contract: true / false / null.
$score        = $cdc['score'];
$personFound  = $cdc['person_found'];      // true / false / null
$pagoBuro     = (float)($cdc['pago_mensual_buro'] ?? 0);
$dpd90        = !empty($cdc['dpd90_flag']) ? 1 : 0;
$dpdMax       = (int)($cdc['dpd_max'] ?? 0);

if ($personFound === false) {
    $circuloSource = 'estimado'; // CDC explicit 404.1 — UI shows "no aparece en Buró"
} elseif ($score !== null) {
    $circuloSource = 'real';
} else {
    $circuloSource = 'cdc_sin_score';
}

// Recompute PTI total with the fresh pago_mensual_buro.
$ptiTotal = null;
if ($ingreso > 0) {
    $ptiTotal = round(($pagoBuro + $pagoMVK) / $ingreso, 4);
}

try {
    $pdo->prepare("UPDATE preaprobaciones
                      SET score = ?,
                          circulo_source = ?,
                          pago_mensual_buro = ?,
                          dpd90_flag = ?,
                          dpd_max = ?,
                          pti_total = ?
                    WHERE id = ?")
        ->execute([
            $score,
            $circuloSource,
            $pagoBuro,
            $dpd90,
            $dpdMax,
            $ptiTotal,
            $preapId,
        ]);
} catch (Throwable $e) {
    adminJsonOut(['ok' => false, 'error' => 'db_update_failed',
                  'message' => 'CDC respondió OK pero no pude guardar en BD: ' . $e->getMessage(),
                  'fetched' => [
                      'score'             => $score,
                      'circulo_source'    => $circuloSource,
                      'person_found'      => $personFound,
                      'pago_mensual_buro' => $pagoBuro,
                      'dpd90_flag'        => (bool)$dpd90,
                      'dpd_max'           => $dpdMax,
                      'pti_total'         => $ptiTotal,
                  ],
                  'http' => $cdc['http']], 500);
}

// ── Step 4: log the manual retry for traceability ────────────────────────
try {
    if (function_exists('adminLog')) {
        adminLog('preaprobacion_reconsultar_cdc', [
            'preap_id'         => $preapId,
            'http'             => $cdc['http'] ?? 0,
            'new_score'        => $score,
            'new_source'       => $circuloSource,
            'person_found'     => $personFound,
        ]);
    }
} catch (Throwable $e) { /* non-fatal */ }

adminJsonOut([
    'ok'      => true,
    'message' => $circuloSource === 'real'
                  ? 'CDC respondió con score real. Recomendación actualizada.'
                  : ($circuloSource === 'cdc_sin_score'
                      ? 'CDC encontró a la persona pero sin FICO (thin file).'
                      : 'CDC no encontró a esta persona en el Buró.'),
    'http'    => $cdc['http'] ?? 0,
    'fetched' => [
        'score'             => $score,
        'circulo_source'    => $circuloSource,
        'person_found'      => $personFound,
        'pago_mensual_buro' => $pagoBuro,
        'dpd90_flag'        => (bool)$dpd90,
        'dpd_max'           => $dpdMax,
        'pti_total'         => $ptiTotal,
        'folio_consulta'    => $cdc['folioConsulta'] ?? '',
    ],
]);
