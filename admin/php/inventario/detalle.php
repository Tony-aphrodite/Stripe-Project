<?php
/**
 * GET ?id= — Full detail of a single moto (for modal view)
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis','operador']);

$id = (int)($_GET['id'] ?? 0);
if (!$id) adminJsonOut(['error' => 'ID requerido'], 400);

$pdo = getDB();

// Moto
$stmt = $pdo->prepare("SELECT m.*, pv.nombre AS punto_voltika_nombre,
    DATEDIFF(CURDATE(), COALESCE(m.fecha_estado, m.freg)) AS dias_en_estado,
    CASE WHEN m.punto_voltika_id IS NOT NULL AND m.estado NOT IN ('entregada','por_llegar','retenida')
         THEN DATEDIFF(CURDATE(), COALESCE(m.fecha_estado, m.freg)) ELSE NULL END AS dias_en_punto
    FROM inventario_motos m LEFT JOIN puntos_voltika pv ON pv.id=m.punto_voltika_id WHERE m.id=?");
$stmt->execute([$id]);
$moto = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$moto) adminJsonOut(['error' => 'Moto no encontrada'], 404);

// Checklists
$co = $pdo->prepare("SELECT * FROM checklist_origen WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$co->execute([$id]);

$ce = $pdo->prepare("SELECT * FROM checklist_ensamble WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$ce->execute([$id]);

$cd = $pdo->prepare("SELECT * FROM checklist_entrega_v2 WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$cd->execute([$id]);

// Envíos
$env = $pdo->prepare("SELECT e.*, pv.nombre AS punto_nombre FROM envios e
    LEFT JOIN puntos_voltika pv ON pv.id=e.punto_destino_id WHERE e.moto_id=? ORDER BY e.freg DESC");
$env->execute([$id]);

// Entrega
$ent = $pdo->prepare("SELECT * FROM entregas WHERE moto_id=? ORDER BY freg DESC LIMIT 1");
$ent->execute([$id]);

// Transacción/pedido linked
$tx = $pdo->prepare("SELECT * FROM transacciones WHERE stripe_pi=? LIMIT 1");
$tx->execute([$moto['stripe_pi'] ?? '']);

// ── Round 28 (2026-05-14, Óscar — Pesgo Plus VIN ...12 retenida sin
// motivo visible): the admin detail panel showed a red "retenida" badge
// but the operator could not see WHO retained it, WHEN, or WHY. The
// information lives in inventario_motos.log_estados (JSON array of
// state-change events, written by configurador/php/admin-moto-accion.php)
// but is never returned by this endpoint. Extract the most-recent
// 'retenida' entry and expose it so the JS can render a dedicated
// RETENCIÓN section next to BLOQUEO DE VENTA.
//
// Also enrich with the user's display name when possible — log_estados
// stores `dealer` (user id), not the friendly name.
$retencion = null;
$logRaw = $moto['log_estados'] ?? null;
if ($logRaw) {
    $logArr = is_array($logRaw) ? $logRaw : @json_decode((string)$logRaw, true);
    if (is_array($logArr)) {
        // Walk backwards: most recent matching event wins.
        for ($i = count($logArr) - 1; $i >= 0; $i--) {
            $ev = $logArr[$i];
            if (!is_array($ev)) continue;
            $estadoEv = strtolower((string)($ev['estado'] ?? ''));
            $accionEv = strtolower((string)($ev['accion'] ?? ''));
            $origenEv = strtolower((string)($ev['origen'] ?? ''));
            // Match either the new shape (estado='retenida') or older
            // shape (accion='retener'). origen='retener_manual' covers
            // manual SQL fixes too.
            $isReten = ($estadoEv === 'retenida' || $accionEv === 'retener'
                     || strpos($origenEv, 'retener') !== false);
            if ($isReten) {
                $retencion = [
                    'estado'    => $ev['estado']    ?? null,
                    'accion'    => $ev['accion']    ?? null,
                    'origen'    => $ev['origen']    ?? null,
                    'fecha'     => $ev['fecha']     ?? ($ev['timestamp'] ?? null),
                    'usuario'   => $ev['usuario']   ?? ($ev['dealer'] ?? null),
                    'notas'     => $ev['notas']     ?? null,
                ];
                break;
            }
        }
    }
}
if ($retencion && !empty($retencion['usuario'])) {
    try {
        $du = $pdo->prepare("SELECT nombre FROM dealer_usuarios WHERE id = ? LIMIT 1");
        $du->execute([(int)$retencion['usuario']]);
        $duRow = $du->fetchColumn();
        if ($duRow) $retencion['usuario_nombre'] = (string)$duRow;
    } catch (Throwable $e) { /* non-fatal */ }
}

// ── Round 31 (2026-05-14, Óscar) — Recepción info for admin detail ────
// The moto detail panel previously showed only "Recepción: Pendiente" or
// "Pendiente" with no further info. The actual reception data (who
// received it, when, photos, seal info, integrity checks) lives in
// recepcion_punto + dealer_usuarios, and was only visible from the punto
// admin's Historial tab. Admin now gets the same info inline so they
// don't have to switch panels to investigate.
$recepcion = null;
try {
    $rq = $pdo->prepare("
        SELECT rp.*,
               rp.freg AS fecha_recepcion,
               u.nombre AS recibido_por_nombre,
               u.email  AS recibido_por_email,
               pv.nombre AS punto_nombre
          FROM recepcion_punto rp
          LEFT JOIN dealer_usuarios u  ON u.id  = rp.recibido_por
          LEFT JOIN puntos_voltika  pv ON pv.id = rp.punto_id
         WHERE rp.moto_id = ?
         ORDER BY rp.freg DESC LIMIT 1");
    $rq->execute([$id]);
    $recepcion = $rq->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    error_log('detalle.php recepción lookup: ' . $e->getMessage());
}

// Rewrite legacy photo URLs to go through the admin-side serve helper
// (same .htaccess-bypass pattern as the punto serve-foto.php). This way
// admin can view photos even when /configurador/php/uploads/recepcion/
// is blocked by Plesk.
//
// Round 31 v2 (2026-05-14, Óscar — broken thumbnails): also verify the
// underlying file actually exists in any of the 3 candidate upload dirs.
// Files from legacy uploads (pre-Round-30-v4) never actually saved to
// disk — the recibir.php @file_put_contents() silently failed because
// Plesk blocks mkdir() under configurador/php/. Setting the URL to null
// when the file is missing lets the frontend show a polite "photo not
// available" message instead of a broken thumbnail.
if ($recepcion) {
    $uploadCandidates = array_filter([
        realpath(__DIR__ . '/../../../configurador/php/uploads/recepcion'),
        realpath(__DIR__ . '/../../uploads/recepcion-puntos'),
        realpath(sys_get_temp_dir() . '/voltika_recepcion_fotos'),
    ]);
    $fileExistsLocally = function (string $url) use ($uploadCandidates): bool {
        if (empty($uploadCandidates)) return false;
        $base = basename(parse_url($url, PHP_URL_PATH) ?: $url);
        if ($base === '') return false;
        foreach ($uploadCandidates as $dir) {
            if (is_file($dir . '/' . $base)) return true;
        }
        return false;
    };
    $rewriteAdminFoto = function (?string $url) use ($fileExistsLocally): ?string {
        if (!$url) return null;
        if (!$fileExistsLocally($url)) return null;  // file missing → null = no thumbnail
        if (strpos($url, 'serve-recepcion-foto.php') !== false) return $url;
        $base = basename(parse_url($url, PHP_URL_PATH) ?: $url);
        if ($base === '') return $url;
        return '/admin/php/inventario/serve-recepcion-foto.php?f=' . rawurlencode($base);
    };
    foreach (['foto_sello_url','foto_vin_label_url','foto_unidad_url'] as $k) {
        if (array_key_exists($k, $recepcion)) {
            $recepcion[$k] = $rewriteAdminFoto($recepcion[$k] ?? null);
        }
    }
    // Decode the legacy `fotos` JSON column (extra free-form photos).
    $extras = [];
    if (!empty($recepcion['fotos']) && is_string($recepcion['fotos'])) {
        $decoded = json_decode($recepcion['fotos'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $u) {
                if (is_string($u) && $u !== '') {
                    $rw = $rewriteAdminFoto($u);
                    if ($rw) $extras[] = $rw;
                }
            }
        }
    }
    $recepcion['fotos_extra'] = $extras;
    // Counter so the frontend can show a polite "X photos missing" hint.
    $missing = 0;
    foreach (['foto_sello_url','foto_vin_label_url','foto_unidad_url'] as $k) {
        if (array_key_exists($k, $recepcion) && $recepcion[$k] === null) $missing++;
    }
    $recepcion['fotos_missing_count'] = $missing;
    unset($recepcion['fotos']);
}

adminJsonOut([
    'moto' => $moto,
    'checklist_origen' => $co->fetch(PDO::FETCH_ASSOC) ?: null,
    'checklist_ensamble' => $ce->fetch(PDO::FETCH_ASSOC) ?: null,
    'checklist_entrega' => $cd->fetch(PDO::FETCH_ASSOC) ?: null,
    'envios' => $env->fetchAll(PDO::FETCH_ASSOC),
    'entrega' => $ent->fetch(PDO::FETCH_ASSOC) ?: null,
    'transaccion' => $tx->fetch(PDO::FETCH_ASSOC) ?: null,
    // Round 28: expose the parsed retención context for the UI. NULL when
    // the moto is not retenida or no log entry was found.
    'retencion' => (strtolower((string)($moto['estado'] ?? '')) === 'retenida') ? $retencion : null,
    // Round 31: reception event with photos + integrity checks. NULL when
    // no recepción row exists yet.
    'recepcion' => $recepcion,
]);
