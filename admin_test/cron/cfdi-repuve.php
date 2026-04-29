<?php
/**
 * Cron — CFDI emission + REPUVE notice filing.
 * ─────────────────────────────────────────────────────────────────────────
 * Tech Spec EN §5.6 + §5.7 + Cláusula Décima Novena of v5 contract:
 *
 *   1. PAID orders without a CFDI → emit CFDI 4.0 via PAC
 *   2. ISSUED CFDIs without a REPUVE notice → file with REPUVE
 *      "within next business day after invoicing" (Art. 23 LRPV)
 *
 * Recommended schedule: every hour.  Each pass processes max 50 orders /
 * 50 notices to stay within HTTP timeouts.
 *
 * Behaviour without API credentials:
 *   - PAC_PROVIDER=noop OR creds missing → CFDI is queued, NOT emitted.
 *   - REPUVE_API_KEY missing             → notice is queued, NOT filed.
 *   The cron is safe to run even without credentials; it logs progress.
 */
require_once __DIR__ . '/../php/bootstrap.php';

// Locate the configurador shared helpers.
$configuradorPhp = realpath(__DIR__ . '/../../configurador_prueba/php')
                ?: realpath(__DIR__ . '/../../configurador_prueba_test/php');
if (!$configuradorPhp) adminJsonOut(['error' => 'configurador_prueba/php not found'], 500);

require_once $configuradorPhp . '/sat-pac.php';
require_once $configuradorPhp . '/repuve.php';

// Auth
$cronToken = defined('VOLTIKA_CRON_TOKEN') ? VOLTIKA_CRON_TOKEN : (getenv('VOLTIKA_CRON_TOKEN') ?: '');
if ($cronToken) {
    $provided = $_SERVER['HTTP_X_CRON_TOKEN'] ?? ($_GET['token'] ?? '');
    if ($provided !== $cronToken) adminJsonOut(['error' => 'Token inválido'], 403);
}

$pdo = getDB();
satEnsureSchema($pdo);
repuveEnsureSchema($pdo);

$stats = [
    'cfdi_emitidos'   => 0,
    'cfdi_skipped'    => 0,
    'cfdi_errors'     => 0,
    'repuve_filed'    => 0,
    'repuve_skipped'  => 0,
    'repuve_errors'   => 0,
];

// ═══════════════════════════════════════════════════════════════════════
// PHASE 1 — Emit CFDI for paid orders that don't have one yet
// ═══════════════════════════════════════════════════════════════════════
//   Eligible:
//     - transacciones.pago_estado IN ('pagada','aprobada')
//     - no row in cfdi_emitidos for this transaccion_id (or only 'error')
//     - has at least vin (delivery completed) — vehicle-line CFDIs need VIN

try {
    $candidates = $pdo->query("
        SELECT t.id, t.email, t.nombre, t.telefono, t.modelo, t.color, t.cp,
               t.tpago, t.precio, t.total, t.pedido,
               (SELECT id FROM inventario_motos WHERE transaccion_id = t.id LIMIT 1) AS moto_id,
               (SELECT vin FROM inventario_motos WHERE transaccion_id = t.id LIMIT 1) AS vin
        FROM transacciones t
        WHERE t.pago_estado IN ('pagada','aprobada','paid')
          AND NOT EXISTS (
              SELECT 1 FROM cfdi_emitidos e
              WHERE e.transaccion_id = t.id
                AND e.estado IN ('emitido','pendiente_pac')
          )
          AND t.email IS NOT NULL AND t.email <> ''
        ORDER BY t.freg DESC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('cfdi-repuve fetch candidates: ' . $e->getMessage());
    $candidates = [];
}

foreach ($candidates as $tx) {
    // Resolve customer RFC — if not provided, use generic XAXX010101000.
    $rfc = 'XAXX010101000';
    try {
        $cs = $pdo->prepare("SELECT rfc, apellido_paterno, apellido_materno, nombre AS first_name
                             FROM clientes WHERE email = ? ORDER BY id DESC LIMIT 1");
        $cs->execute([$tx['email']]);
        if ($c = $cs->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($c['rfc'])) $rfc = $c['rfc'];
        }
    } catch (Throwable $e) {}

    $total = floatval($tx['total'] ?: $tx['precio']);
    $iva   = round($total * 16 / 116, 2);
    $sub   = $total - $iva;

    $tpago = strtolower($tx['tpago'] ?: 'contado');
    $metodoPago = in_array($tpago, ['credito','enganche','parcial'], true) ? 'PPD' : 'PUE';
    $formaPago  = in_array($tpago, ['spei'], true) ? '03'
                : (in_array($tpago, ['oxxo'], true) ? '99'
                : '04'); // 04 = tarjeta crédito (default)

    $r = satEmitirCFDI([
        'transaccion_id'   => (int)$tx['id'],
        'moto_id'          => $tx['moto_id'] ? (int)$tx['moto_id'] : null,
        'rfc_receptor'     => $rfc,
        'nombre_receptor'  => $tx['nombre'],
        'subtotal'         => $sub,
        'iva'              => $iva,
        'total'            => $total,
        'metodo_pago'      => $metodoPago,
        'forma_pago'       => $formaPago,
        'uso_cfdi'         => $rfc === 'XAXX010101000' ? 'S01' : 'G03', // S01 = sin efectos fiscales
        'vehicle_model'    => $tx['modelo'],
        'vehicle_color'    => $tx['color'],
        'vehicle_year'     => date('Y'),
        'vin'              => $tx['vin'] ?? '',
        'zip_receptor'     => $tx['cp'] ?: '11510',
    ]);

    if (!empty($r['duplicate'])) { $stats['cfdi_skipped']++; continue; }
    if ($r['ok'] && ($r['estado'] ?? '') === 'emitido') {
        $stats['cfdi_emitidos']++;
        // Cross-link the CFDI on the checklist if delivery has happened.
        if (!empty($r['uuid']) && !empty($tx['moto_id'])) {
            try {
                $pdo->prepare("UPDATE checklist_entrega_v2
                               SET cfdi_uuid = ?, cfdi_folio = ?
                               WHERE moto_id = ?")
                    ->execute([$r['uuid'], $r['folio'] ?? null, (int)$tx['moto_id']]);
            } catch (Throwable $e) {}
        }
    } elseif ($r['ok'] && ($r['estado'] ?? '') === 'pendiente_pac') {
        $stats['cfdi_skipped']++;
    } else {
        $stats['cfdi_errors']++;
        error_log('cfdi-repuve emit err pedido=' . $tx['pedido'] . ': ' . ($r['error'] ?? 'unknown'));
    }
}

// ═══════════════════════════════════════════════════════════════════════
// PHASE 2 — File REPUVE notice for emitted CFDIs that don't have one
// ═══════════════════════════════════════════════════════════════════════
//   Per Art. 23 LRPV: "within next business day after invoicing".
//   We don't enforce business-day cadence here — the cron runs hourly
//   and SSP REPUVE accepts late filings too.

try {
    $cfdiNeedsRepuve = $pdo->query("
        SELECT e.id AS cfdi_id, e.transaccion_id, e.moto_id, e.uuid AS cfdi_uuid,
               e.rfc_receptor, e.nombre_receptor,
               t.cp, t.modelo,
               (SELECT vin FROM inventario_motos WHERE id = e.moto_id LIMIT 1) AS vin
        FROM cfdi_emitidos e
        LEFT JOIN transacciones t ON t.id = e.transaccion_id
        WHERE e.estado = 'emitido'
          AND e.uuid IS NOT NULL
          AND NOT EXISTS (
              SELECT 1 FROM repuve_avisos r
              WHERE r.cfdi_uuid = e.uuid
                AND r.estado IN ('pendiente','enviado','aceptado')
          )
        ORDER BY e.femitido ASC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('cfdi-repuve fetch repuve candidates: ' . $e->getMessage());
    $cfdiNeedsRepuve = [];
}

foreach ($cfdiNeedsRepuve as $row) {
    if (empty($row['vin'])) {
        // Vehicle not yet assigned — REPUVE filing requires VIN. Skip until
        // CEDIS assigns inventory.
        $stats['repuve_skipped']++;
        continue;
    }
    $r = repuveFilePurchaseNotice([
        'transaccion_id'    => (int)$row['transaccion_id'],
        'moto_id'           => (int)$row['moto_id'],
        'cfdi_uuid'         => $row['cfdi_uuid'],
        'vin'               => $row['vin'],
        'rfc_adquirente'    => $row['rfc_receptor'],
        'nombre_adquirente' => $row['nombre_receptor'],
        'domicilio'         => '',
        'fecha_operacion'   => date('Y-m-d'),
        'vehicle_model'     => $row['modelo'],
        'vehicle_year'      => date('Y'),
    ]);
    if (!empty($r['duplicate'])) { $stats['repuve_skipped']++; continue; }
    if ($r['ok'] && in_array($r['estado'] ?? '', ['enviado','aceptado'], true)) {
        $stats['repuve_filed']++;
    } elseif ($r['ok'] && ($r['estado'] ?? '') === 'pendiente') {
        $stats['repuve_skipped']++;
    } else {
        $stats['repuve_errors']++;
        error_log('cfdi-repuve repuve err uuid=' . $row['cfdi_uuid'] . ': ' . ($r['error'] ?? 'unknown'));
    }
}

adminLog('cron_cfdi_repuve', $stats);
adminJsonOut(array_merge(['ok' => true], $stats));
