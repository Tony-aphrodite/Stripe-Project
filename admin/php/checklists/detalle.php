<?php
/**
 * GET ?moto_id= — Get full checklist data for a moto
 * Returns all 3 checklists (latest record each)
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$motoId = (int)($_GET['moto_id'] ?? 0);
if (!$motoId) adminJsonOut(['error' => 'moto_id requerido'], 400);

$pdo = getDB();

// Moto info
$stmt = $pdo->prepare("SELECT id, vin, vin_display, modelo, color, anio_modelo, estado,
    cliente_nombre, cliente_telefono, cliente_email, pedido_num, fecha_entrega_estimada
    FROM inventario_motos WHERE id=?");
$stmt->execute([$motoId]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) adminJsonOut(['error' => 'Moto no encontrada'], 404);

// Linked transacción — we need tpago to decide which signatures to show at
// delivery (pagaré only applies to credit-family orders).
try {
    $moto['tpago'] = null;
    if (!empty($moto['pedido_num'])) {
        $pedido = preg_replace('/^VK-/', '', $moto['pedido_num']);
        $ts = $pdo->prepare("SELECT tpago FROM transacciones WHERE pedido = ? ORDER BY id DESC LIMIT 1");
        $ts->execute([$pedido]);
        $moto['tpago'] = $ts->fetchColumn() ?: null;
    }
} catch (Throwable $e) { error_log('detalle.php tpago lookup: ' . $e->getMessage()); }

// Credit plan info — enrich moto with plan data so the signing screen can
// render the customer-friendly summary card (modelo / pago semanal / plazo /
// primer pago) without needing extra round trips. Match the subscription by
// client telefono / email since inventario_motos has no FK to subscripciones.
try {
    $sub = null;
    if (!empty($moto['cliente_telefono']) || !empty($moto['cliente_email'])) {
        $q = "SELECT id, modelo, color, monto_semanal, plazo_semanas, plazo_meses,
                     fecha_inicio, fecha_entrega, nombre
              FROM subscripciones_credito
              WHERE (telefono = ? OR email = ?)
              ORDER BY id DESC LIMIT 1";
        $ss = $pdo->prepare($q);
        $ss->execute([$moto['cliente_telefono'] ?? '', $moto['cliente_email'] ?? '']);
        $sub = $ss->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $moto['plan'] = $sub ? [
        'pago_semanal'  => (float)($sub['monto_semanal'] ?? 0),
        'plazo_semanas' => (int)($sub['plazo_semanas'] ?? 0),
        'plazo_meses'   => (int)($sub['plazo_meses'] ?? 0),
        'fecha_entrega' => $sub['fecha_entrega'] ?? ($moto['fecha_entrega_estimada'] ?? null),
        'nombre'        => $sub['nombre'] ?? $moto['cliente_nombre'],
    ] : null;
} catch (Throwable $e) {
    error_log('detalle.php plan lookup: ' . $e->getMessage());
    $moto['plan'] = null;
}

// Checklist origen
$co = $pdo->prepare("SELECT * FROM checklist_origen WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$co->execute([$motoId]);

// Checklist ensamble
$ce = $pdo->prepare("SELECT * FROM checklist_ensamble WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$ce->execute([$motoId]);

// Checklist entrega
$cv = $pdo->prepare("SELECT * FROM checklist_entrega_v2 WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$cv->execute([$motoId]);

$origenRow   = $co->fetch(PDO::FETCH_ASSOC) ?: null;
$ensambleRow = $ce->fetch(PDO::FETCH_ASSOC) ?: null;
$entregaRow  = $cv->fetch(PDO::FETCH_ASSOC) ?: null;

// ── Enrich each phase with dealer name + decoded photo URLs ──────────────
// Customer brief 2026-05-14 (Óscar): admin Documentos modal showed only
// status + freg per phase. Now we expose:
//   - dealer_nombre: who completed (snapshot if present, else live lookup)
//   - dealer_rol:    role label for context
//   - fotos:         photo column → URL array (decoded from JSON)
// so the frontend can render a drill-in detail with photos + author.
//
// JSON photo columns enumerated from subir-foto.php whitelist.
$photoColsByPhase = [
    'origen' => [
        'fotos', 'foto_unidad_completa', 'foto_vin', 'foto_tablero_encendido',
        'foto_bateria', 'foto_contenido_previo_cierre', 'foto_caja_cerrada',
        'foto_sellos', 'foto_detalle_calcomanias', 'foto_empaque_accesorios',
        'foto_empaque_llaves',
    ],
    'ensamble' => [
        'fotos_fase1', 'fotos_desembalaje', 'fotos_base', 'fotos_manubrio',
        'fotos_llanta', 'fotos_espejos', 'fotos_fase3',
        'fotos_3_1_frenos', 'fotos_3_2_iluminacion', 'fotos_3_3_electrico',
        'fotos_3_4_motor', 'fotos_3_5_acceso', 'fotos_3_6_mecanica',
    ],
    'entrega' => [
        'fotos_identidad', 'fotos_unidad',
    ],
];

// Per-phase timestamp fields we surface in the detail view (UI shows audit
// trail: when was each phase actually completed, beyond just freg/fmod).
$timestampFieldsByPhase = [
    'origen'   => ['fecha_inicio', 'fecha_completado', 'freg', 'fmod'],
    'ensamble' => ['fase1_fecha', 'fase2_fecha', 'fase3_fecha', 'freg', 'fmod'],
    'entrega'  => ['fase1_fecha', 'fase2_fecha', 'fase3_fecha', 'fase4_fecha', 'fase5_fecha', 'freg', 'fmod'],
];

/**
 * Decode a column that holds a JSON array of either URLs or
 * { filename, url } objects (legacy variance). Returns normalised array of
 * { filename, url } entries. Empty/invalid → [].
 */
function _detalleDecodePhotos($raw): array {
    if ($raw === null || $raw === '' || $raw === '[]') return [];
    $decoded = is_array($raw) ? $raw : @json_decode((string)$raw, true);
    if (!is_array($decoded)) return [];
    $out = [];
    foreach ($decoded as $item) {
        if (is_string($item)) {
            // Could be a URL or a filename. Normalise to serve-foto.php?f=
            $isUrl = preg_match('#^https?://#', $item) || str_contains($item, 'serve-foto');
            $out[] = [
                'filename' => basename(parse_url($item, PHP_URL_PATH) ?: $item),
                'url'      => $isUrl ? $item : ('php/checklists/serve-foto.php?f=' . urlencode($item)),
            ];
        } elseif (is_array($item)) {
            $fn  = (string)($item['filename'] ?? '');
            $url = (string)($item['url'] ?? '');
            if ($url === '' && $fn !== '') $url = 'php/checklists/serve-foto.php?f=' . urlencode($fn);
            if ($url !== '') $out[] = ['filename' => $fn, 'url' => $url];
        }
    }
    return $out;
}

/**
 * Resolve the human display name + role of the dealer who created the row.
 * Prefers a stored snapshot (immune to later renames). Falls back to a live
 * dealer_usuarios lookup. Returns ['nombre' => ..., 'rol' => ..., 'punto' => ...].
 */
function _detalleDealerInfo(PDO $pdo, ?array $row): array {
    $info = ['nombre' => null, 'rol' => null, 'punto' => null];
    if (!$row) return $info;
    if (!empty($row['dealer_nombre_snapshot'])) {
        $info['nombre'] = (string)$row['dealer_nombre_snapshot'];
    }
    $dealerId = (int)($row['dealer_id'] ?? 0);
    if ($dealerId > 0) {
        try {
            $st = $pdo->prepare("SELECT nombre, rol, punto_nombre FROM dealer_usuarios WHERE id=? LIMIT 1");
            $st->execute([$dealerId]);
            $du = $st->fetch(PDO::FETCH_ASSOC);
            if ($du) {
                if ($info['nombre'] === null || $info['nombre'] === '') $info['nombre'] = (string)$du['nombre'];
                $info['rol']   = (string)($du['rol']          ?? '');
                $info['punto'] = (string)($du['punto_nombre'] ?? '');
            }
        } catch (Throwable $e) { error_log('detalle.php dealer lookup: ' . $e->getMessage()); }
    }
    return $info;
}

foreach (['origen' => &$origenRow, 'ensamble' => &$ensambleRow, 'entrega' => &$entregaRow] as $phaseKey => &$row) {
    if (!$row) continue;
    $dealer = _detalleDealerInfo($pdo, $row);
    $row['_dealer_nombre'] = $dealer['nombre'];
    $row['_dealer_rol']    = $dealer['rol'];
    $row['_dealer_punto']  = $dealer['punto'];

    // Decode every photo column for this phase into URL arrays.
    $fotosOut = [];
    foreach ($photoColsByPhase[$phaseKey] as $col) {
        if (array_key_exists($col, $row)) {
            $fotosOut[$col] = _detalleDecodePhotos($row[$col]);
        }
    }
    $row['_fotos'] = $fotosOut;

    // Total photo count for UI badges.
    $row['_fotos_count'] = array_sum(array_map('count', $fotosOut));

    // Compact timestamp object so the frontend doesn't need to know which
    // columns exist on which table.
    $ts = [];
    foreach ($timestampFieldsByPhase[$phaseKey] as $tsCol) {
        if (array_key_exists($tsCol, $row) && $row[$tsCol] !== null && $row[$tsCol] !== '') {
            $ts[$tsCol] = (string)$row[$tsCol];
        }
    }
    $row['_timestamps'] = $ts;
}
unset($row);

adminJsonOut([
    'ok'    => true,
    'moto'  => $moto,
    'origen'   => $origenRow,
    'ensamble' => $ensambleRow,
    'entrega'  => $entregaRow,
]);
