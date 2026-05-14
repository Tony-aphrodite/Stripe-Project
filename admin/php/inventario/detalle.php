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
]);
