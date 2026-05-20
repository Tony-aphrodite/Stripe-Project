<?php
/**
 * GET — List available bikes for manual assignment
 * Params: ?modelo=M05&color=gris  (optional filters)
 * Returns bikes that have NO customer assigned (pedido_num IS NULL)
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$pdo = getDB();

// ── Round 64 (2026-05-20) — Auto-heal stale moto state ────────────────────
// Both guardar-ensamble.php endpoints (admin + punto) wrap the
// "UPDATE inventario_motos SET estado='lista_para_entrega'" in
// try/catch (Throwable $e) { error_log(...); }. If that UPDATE fails
// for ANY reason — DB lock contention, dropped connection, replication
// lag, network blip — the checklist gets marked completado=1 but the
// moto's estado stays 'en_ensamble', and the moto disappears from the
// picker forever (until someone manually re-saves the checklist).
//
// This is exactly what blocked VIN R4WPDTA1XT8000049 (Voltika Center)
// on 2026-05-20.
//
// Fix: every time the picker is opened, run a self-healing pass that
// flips estado='lista_para_entrega' for any moto whose MOST RECENT
// checklist_ensamble row has completado=1 but whose estado is still
// 'en_ensamble'. Idempotent + safe — only affects rows that are
// already inconsistent. The audit row in log_estados records that the
// heal ran so future investigations can trace the cause.
try {
    $self = $pdo->prepare("
        SELECT m.id, m.vin_display, m.vin
          FROM inventario_motos m
          JOIN (
              SELECT ce.moto_id, ce.completado
                FROM checklist_ensamble ce
                JOIN (
                    SELECT moto_id, MAX(id) AS mid
                      FROM checklist_ensamble
                     GROUP BY moto_id
                ) latest ON latest.moto_id = ce.moto_id AND ce.id = latest.mid
          ) x ON x.moto_id = m.id
         WHERE m.estado    = 'en_ensamble'
           AND m.activo    = 1
           AND x.completado = 1
    ");
    $self->execute();
    $stale = $self->fetchAll(PDO::FETCH_ASSOC);
    if ($stale) {
        $ids = array_map(static function($r) { return (int)$r['id']; }, $stale);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("
            UPDATE inventario_motos
               SET estado       = 'lista_para_entrega',
                   fecha_estado = NOW(),
                   fmod         = NOW(),
                   log_estados  = JSON_ARRAY_APPEND(
                       COALESCE(log_estados, '[]'),
                       '$',
                       JSON_OBJECT(
                           'estado',  'lista_para_entrega',
                           'accion',  'auto_heal_picker',
                           'fecha',   NOW(),
                           'origen',  'motos-disponibles.php · Round 64',
                           'razon',   'checklist_ensamble.completado=1 pero estado seguia en en_ensamble'
                       )
                   )
             WHERE id IN ($ph)
        ")->execute($ids);
        foreach ($stale as $r) {
            error_log('motos-disponibles auto-heal: moto id=' . $r['id']
                      . ' vin=' . ($r['vin_display'] ?: $r['vin'])
                      . ' estado en_ensamble→lista_para_entrega');
        }
    }
} catch (Throwable $e) {
    // Non-fatal — heal failure must never break the picker.
    error_log('motos-disponibles auto-heal failed: ' . $e->getMessage());
}

// ── Diagnostic mode: ?debug_vin=<partial VIN> ─────────────────────────────
// Round 62-debug (2026-05-20): returns every moto whose VIN contains the
// given substring along with the exact reason each is/isn't eligible for
// the assignment picker. Use this when a specific VIN refuses to appear
// in the modal despite the admin believing it should be available.
//
// Example: GET /admin/php/ventas/motos-disponibles.php?debug_vin=0049
if (!empty($_GET['debug_vin'])) {
    header('Content-Type: application/json; charset=utf-8');
    $needle = trim((string)$_GET['debug_vin']);
    $like = '%' . $needle . '%';
    $q = $pdo->prepare("SELECT m.id, m.vin, m.vin_display, m.modelo, m.color, m.estado,
                               m.activo, m.pedido_num, m.cliente_email, m.cliente_nombre,
                               m.bloqueado_venta, m.bloqueado_motivo,
                               m.punto_voltika_id, pv.nombre AS punto_nombre,
                               m.fecha_estado, m.freg, m.fmod
                          FROM inventario_motos m
                     LEFT JOIN puntos_voltika pv ON pv.id = m.punto_voltika_id
                         WHERE m.vin LIKE ? OR m.vin_display LIKE ?
                         ORDER BY m.id DESC");
    $q->execute([$like, $like]);
    $matches = $q->fetchAll(PDO::FETCH_ASSOC);

    $analysis = [];
    foreach ($matches as $m) {
        $blockers = [];
        if ((int)$m['activo'] !== 1) {
            $blockers[] = "activo=" . ($m['activo'] ?? 'null') . " — la moto está marcada como inactiva (eliminada)";
        }
        if (!empty($m['pedido_num'])) {
            $blockers[] = "pedido_num='" . $m['pedido_num'] . "' — ya asignada a otra orden";
        }
        if (!empty($m['cliente_email'])) {
            $blockers[] = "cliente_email='" . $m['cliente_email'] . "' — ya vinculada a un cliente";
        }
        $vinForRegex = $m['vin'] ?? '';
        if (preg_match('/^VK-[A-Z0-9]+-[0-9]+-[a-f0-9]+/i', $vinForRegex)) {
            $blockers[] = "VIN coincide con regex de phantom (VK-MODEL-timestamp-hex) — registro virtual de confirmar-orden.php";
        }
        $estado = strtolower(trim($m['estado'] ?? ''));
        $estadosLibres = ['recibida','lista_para_entrega'];
        if (!in_array($estado, $estadosLibres, true)) {
            $blockers[] = "estado='" . $m['estado'] . "' — el picker solo acepta IN ('recibida','lista_para_entrega')";
        }
        $analysis[] = [
            'id'              => (int)$m['id'],
            'vin'             => $m['vin'],
            'vin_display'     => $m['vin_display'],
            'modelo'          => $m['modelo'],
            'color'           => $m['color'],
            'estado'          => $m['estado'],
            'activo'          => (int)$m['activo'],
            'pedido_num'      => $m['pedido_num'],
            'cliente_email'   => $m['cliente_email'],
            'cliente_nombre'  => $m['cliente_nombre'],
            'bloqueado_venta' => (int)$m['bloqueado_venta'],
            'bloqueado_motivo'=> $m['bloqueado_motivo'],
            'punto_voltika_id'=> $m['punto_voltika_id'],
            'punto_nombre'    => $m['punto_nombre'],
            'fecha_estado'    => $m['fecha_estado'],
            'freg'            => $m['freg'],
            'fmod'            => $m['fmod'],
            'visible_en_picker' => empty($blockers),
            'bloqueadores'    => $blockers,
            'note_modelo_color' => 'El picker también filtra por modelo+color del pedido — si esta moto no coincide con el modelo/color de la orden, no aparecerá aunque no haya bloqueadores arriba.',
        ];
    }
    echo json_encode([
        'ok'        => true,
        'busqueda'  => $needle,
        'total'     => count($analysis),
        'motos'     => $analysis,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$where  = [
    "m.activo = 1",
    "(m.pedido_num IS NULL OR m.pedido_num = '')",
    "(m.cliente_email IS NULL OR m.cliente_email = '')",
    "m.vin NOT REGEXP '^VK-[A-Z0-9]+-[0-9]+-[a-f0-9]+'",
    "m.estado IN ('recibida','lista_para_entrega')",
];
$params = [];

// Round 62 (2026-05-20, Óscar — VIN 0049 case): historically this query
// hid any moto physically parked at a punto OTHER than the order's
// destination. The 0049 example proved this is wrong: a moto whose
// origin + assembly checklists are both complete has by definition
// already moved to a punto. If that punto differs from the order's
// punto (or the order has no punto yet), the moto silently disappears
// from the dropdown even though every "available" condition is met:
// activo=1, no pedido_num, no cliente_email, real VIN, free estado.
//
// Fix: do NOT exclude by punto. Surface every truly-available moto,
// keep returning punto_nombre so the UI can display where it lives,
// and sort so the natural pick order is preserved:
//   1) Motos already at the order's destination punto (instant pickup)
//   2) Motos at CEDIS (no punto yet)
//   3) Motos at any other punto (admin sees "needs transfer")
//
// asignar-moto.php already overwrites moto.punto_voltika_id with the
// order's punto, so picking a moto from "elsewhere" is a one-click
// move, not a forbidden cross-location.
$puntoId = (int)($_GET['punto_id'] ?? 0);
// (no WHERE-clause restriction by punto — sort below handles ordering)

// Filters tolerate legacy values ("Voltika Tromox Pesgo" / "Gris moderno"):
// we normalize the incoming query and also normalize m.modelo / m.color on
// the SQL side so either format matches. This is what lets a legacy order
// (e.g. Eduardo Gonzalez Lopez, VK-1776828725) find the stock row even
// though its stored modelo/color is not the short code.
if (!empty($_GET['modelo'])) {
    $wantedModelo = voltikaNormalizeModelo($_GET['modelo']);
    $where[] = "("
             . "m.modelo = ? "
             . "OR LOWER(TRIM(m.modelo)) = LOWER(?) "
             . "OR LOWER(REPLACE(TRIM(m.modelo), 'Voltika Tromox ', '')) = LOWER(?)"
             . ")";
    $params[] = $wantedModelo;
    $params[] = $wantedModelo;
    $params[] = $wantedModelo;
}
if (!empty($_GET['color'])) {
    $wantedColor = voltikaNormalizeColor($_GET['color']);
    $where[]  = "(LOWER(m.color) = ? OR LOWER(SUBSTRING_INDEX(TRIM(m.color), ' ', 1)) = ?)";
    $params[] = $wantedColor;
    $params[] = $wantedColor;
}

// co_force: completado=1 but key inspection items still at 0 (bulk-force-completed
// without actual physical inspection). Helps the assignment modal flag bikes
// that need proper checklist review before handover.
//
// Round 62: sort prioritizes motos at the order's destination punto, then
// CEDIS (no punto), then motos elsewhere — so admins still see local
// stock first but ALL available units are reachable.
$puntoSortParam = $puntoId > 0 ? $puntoId : -1;
$sql = "SELECT m.id, m.vin, m.vin_display, m.modelo, m.color, m.estado,
               m.fecha_llegada, m.freg,
               m.punto_voltika_id,
               pv.nombre AS punto_nombre,
               pv.ciudad AS punto_ciudad,
               CASE
                   WHEN m.punto_voltika_id = ?              THEN 1
                   WHEN m.punto_voltika_id IS NULL
                     OR m.punto_voltika_id = 0              THEN 2
                   ELSE                                          3
               END AS _ubicacion_orden,
               co.id AS co_id,
               COALESCE(co.completado, 0) AS co_ok,
               CASE WHEN co.completado = 1
                     AND (COALESCE(co.frame_completo,0) = 0
                          OR COALESCE(co.validacion_final,0) = 0)
                    THEN 1 ELSE 0 END AS co_force
        FROM inventario_motos m
        LEFT JOIN puntos_voltika pv ON pv.id = m.punto_voltika_id
        LEFT JOIN (
            SELECT moto_id, id, completado, frame_completo, validacion_final
            FROM checklist_origen
            WHERE id IN (SELECT MAX(id) FROM checklist_origen GROUP BY moto_id)
        ) co ON co.moto_id = m.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY _ubicacion_orden ASC, m.fecha_llegada ASC, m.freg ASC";

// The sort CASE consumes one parameter before the WHERE params.
$execParams = array_merge([$puntoSortParam], $params);

$stmt = $pdo->prepare($sql);
$stmt->execute($execParams);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Decorate each row with a human-readable `ubicacion` label so the UI
// can show "Aquí mismo" / "CEDIS" / "En Punto X — necesita traslado".
foreach ($rows as &$row) {
    $uo = (int)($row['_ubicacion_orden'] ?? 0);
    if ($uo === 1) {
        $row['ubicacion']        = 'aqui';
        $row['ubicacion_label']  = 'En este punto';
        $row['necesita_traslado'] = 0;
    } elseif ($uo === 2) {
        $row['ubicacion']        = 'cedis';
        $row['ubicacion_label']  = 'CEDIS';
        $row['necesita_traslado'] = 0;
    } else {
        $row['ubicacion']        = 'otro_punto';
        $row['ubicacion_label']  = 'En ' . ($row['punto_nombre'] ?? 'otro punto')
                                 . ($row['punto_ciudad'] ? ' · ' . $row['punto_ciudad'] : '');
        $row['necesita_traslado'] = 1;
    }
    unset($row['_ubicacion_orden']);
}
unset($row);

// ─────────────────────────────────────────────────────────────────────────
// Round 63 (2026-05-20): also surface motos of the SAME modelo+color that
// match every other available-condition BUT are excluded by state. This
// is what the boss was hitting with VIN 0049 — the moto existed at
// Voltika Center but was stuck in estado='en_ensamble' because the
// assembly checklist had been started but never marked completado=1.
// The picker correctly hid it, but the admin had no way to know WHY.
// Now we return a `pendientes` array so the UI can show a banner like
// "1 moto en ensamble — completa el checklist para asignarla".
$pendientes = [];
try {
    $pWhere = [
        "m.activo = 1",
        "(m.pedido_num IS NULL OR m.pedido_num = '')",
        "(m.cliente_email IS NULL OR m.cliente_email = '')",
        "m.vin NOT REGEXP '^VK-[A-Z0-9]+-[0-9]+-[a-f0-9]+'",
        "m.estado NOT IN ('recibida','lista_para_entrega','entregada','retenida')",
    ];
    $pParams = [];
    // Re-apply modelo / color filters (same logic as the main query) so we
    // only surface near-matches relevant to THIS order.
    if (!empty($_GET['modelo'])) {
        $wantedModelo = voltikaNormalizeModelo($_GET['modelo']);
        $pWhere[] = "("
                  . "m.modelo = ? "
                  . "OR LOWER(TRIM(m.modelo)) = LOWER(?) "
                  . "OR LOWER(REPLACE(TRIM(m.modelo), 'Voltika Tromox ', '')) = LOWER(?)"
                  . ")";
        $pParams[] = $wantedModelo;
        $pParams[] = $wantedModelo;
        $pParams[] = $wantedModelo;
    }
    if (!empty($_GET['color'])) {
        $wantedColor = voltikaNormalizeColor($_GET['color']);
        $pWhere[]  = "(LOWER(m.color) = ? OR LOWER(SUBSTRING_INDEX(TRIM(m.color), ' ', 1)) = ?)";
        $pParams[] = $wantedColor;
        $pParams[] = $wantedColor;
    }
    $pSql = "SELECT m.id, m.vin, m.vin_display, m.modelo, m.color, m.estado,
                    m.punto_voltika_id,
                    pv.nombre AS punto_nombre, pv.ciudad AS punto_ciudad,
                    ce.id AS ensamble_id,
                    COALESCE(ce.completado, 0) AS ensamble_completado,
                    ce.fase_actual AS ensamble_fase
               FROM inventario_motos m
          LEFT JOIN puntos_voltika pv ON pv.id = m.punto_voltika_id
          LEFT JOIN (
              SELECT moto_id, id, completado, fase_actual
                FROM checklist_ensamble
               WHERE id IN (SELECT MAX(id) FROM checklist_ensamble GROUP BY moto_id)
          ) ce ON ce.moto_id = m.id
              WHERE " . implode(' AND ', $pWhere) . "
              ORDER BY m.estado, m.fecha_llegada ASC";
    $ps = $pdo->prepare($pSql);
    $ps->execute($pParams);
    foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $reason = '';
        $action = '';
        $estado = strtolower((string)$r['estado']);
        switch ($estado) {
            case 'en_ensamble':
                $reason = (int)$r['ensamble_completado'] === 1
                    ? 'Ensamble marcado como completado pero el estado de la moto no se actualizó (caso raro — re-guarda el checklist).'
                    : 'Ensamble en progreso' . ($r['ensamble_fase'] ? ' (fase ' . $r['ensamble_fase'] . ')' : '') . ' — falta marcar el checklist como completado.';
                $action = 'Completar checklist de ensamble';
                break;
            case 'por_llegar':
                $reason = 'En tránsito desde CEDIS — todavía no llega al punto.';
                $action = 'Esperar recepción';
                break;
            case 'en_transito':
                $reason = 'En tránsito — todavía no recibida.';
                $action = 'Esperar recepción';
                break;
            default:
                $reason = 'Estado "' . $r['estado'] . '" — no asignable.';
                $action = 'Revisar estado en Inventario';
                break;
        }
        $loc = $r['punto_nombre']
             ? ('En ' . $r['punto_nombre'] . ($r['punto_ciudad'] ? ' · ' . $r['punto_ciudad'] : ''))
             : 'En CEDIS';
        $pendientes[] = [
            'id'                  => (int)$r['id'],
            'vin'                 => $r['vin_display'] ?: $r['vin'],
            'modelo'              => $r['modelo'],
            'color'               => $r['color'],
            'estado'              => $r['estado'],
            'ubicacion_label'     => $loc,
            'razon'               => $reason,
            'accion_sugerida'     => $action,
            'ensamble_completado' => (int)$r['ensamble_completado'],
            'ensamble_fase'       => $r['ensamble_fase'],
        ];
    }
} catch (Throwable $e) { error_log('motos-disponibles pendientes: ' . $e->getMessage()); }

adminJsonOut([
    'ok'         => true,
    'motos'      => $rows,
    'total'      => count($rows),
    'pendientes' => $pendientes,
]);
