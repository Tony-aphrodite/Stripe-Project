<?php
/**
 * Phantom-order repair tool.
 *
 * "Phantom" = transacciones row with empty nombre OR empty modelo. Caused by
 * pre-validation INSERTs (now blocked in confirmar-orden.php + stripe-webhook.php),
 * but old rows remain. This endpoint lets admin triage them safely.
 *
 * GET  → returns all phantoms with whatever data we can scrape from Stripe
 *        PI metadata and subscripciones_credito (preview only, no DB writes).
 * POST { action: 'backfill', ids: [...] } → apply the preview's backfilled
 *        values to the transaccion rows.
 * POST { action: 'archive', ids: [...] } → set seguimiento='archivado' +
 *        reason. Hides from main Ventas view without destroying evidence.
 * POST { action: 'delete',  ids: [...] } → hard DELETE (admin-only, last
 *        resort for test-data pollution).
 */
require_once __DIR__ . '/../bootstrap.php';

$pdo = getDB();

// Config lookup (Stripe secret)
$cfgCandidates = [
    __DIR__ . '/../../../configurador_prueba/php/config.php',
    __DIR__ . '/../../../configurador_prueba_test/php/config.php',
];
foreach ($cfgCandidates as $p) { if (file_exists($p)) { require_once $p; break; } }

// Ensure transacciones.seguimiento column exists (used for archive)
try {
    $cols = $pdo->query("SHOW COLUMNS FROM transacciones")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('seguimiento', $cols, true)) {
        $pdo->exec("ALTER TABLE transacciones ADD COLUMN seguimiento VARCHAR(30) NULL");
    }
    if (!in_array('seguimiento_nota', $cols, true)) {
        $pdo->exec("ALTER TABLE transacciones ADD COLUMN seguimiento_nota TEXT NULL");
    }
} catch (Throwable $e) { error_log('reparar-phantom ensure cols: ' . $e->getMessage()); }

// ── Helper: fetch Stripe PI + return normalized {nombre, modelo, color, telefono, email} ───
function fetchStripeBackfill(string $piId): array {
    if (!defined('STRIPE_SECRET_KEY') || !STRIPE_SECRET_KEY || !$piId) return [];
    $ch = curl_init('https://api.stripe.com/v1/payment_intents/' . urlencode($piId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . STRIPE_SECRET_KEY],
        CURLOPT_TIMEOUT        => 8,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) return [];
    $d = json_decode($resp, true);
    if (!is_array($d)) return [];
    $meta = $d['metadata'] ?? [];
    return [
        'nombre'   => trim((string)($meta['nombre'] ?? '')),
        'apellidos'=> trim((string)($meta['apellidos'] ?? '')),
        'modelo'   => trim((string)($meta['modelo'] ?? '')),
        'color'    => trim((string)($meta['color']  ?? '')),
        'telefono' => trim((string)($meta['telefono'] ?? $d['charges']['data'][0]['billing_details']['phone'] ?? '')),
        'email'    => trim((string)($d['receipt_email'] ?? $meta['email'] ?? $d['charges']['data'][0]['billing_details']['email'] ?? '')),
        'ciudad'   => trim((string)($meta['ciudad'] ?? '')),
        'cp'       => trim((string)($meta['cp'] ?? '')),
    ];
}

// ── Helper: subscripciones_credito lookup by stripe_pi, phone, or email ───
function fetchSubscripcionBackfill(PDO $pdo, array $tx): array {
    try {
        $q = $pdo->prepare("SELECT nombre, email, telefono, modelo, color
                            FROM subscripciones_credito
                            WHERE (stripe_payment_intent_id = ? AND ? <> '')
                               OR (telefono = ? AND ? <> '')
                               OR (email = ? AND ? <> '')
                            ORDER BY id DESC LIMIT 1");
        $pi  = $tx['stripe_pi'] ?? '';
        $tel = $tx['telefono']  ?? '';
        $em  = $tx['email']     ?? '';
        $q->execute([$pi, $pi, $tel, $tel, $em, $em]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ?: [];
    } catch (Throwable $e) { return []; }
}

// ── GET: preview ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    adminRequireAuth(['admin', 'cedis']);
    $stmt = $pdo->query("SELECT id, pedido, pedido_corto, nombre, email, telefono,
                                modelo, color, tpago, total, stripe_pi, freg,
                                COALESCE(seguimiento, '') AS seguimiento
                         FROM transacciones
                         WHERE (TRIM(COALESCE(nombre, '')) = '' OR TRIM(COALESCE(modelo, '')) = '')
                           AND (seguimiento IS NULL OR seguimiento <> 'archivado')
                         ORDER BY freg DESC
                         LIMIT 200");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($rows as $r) {
        $stripeFill = $r['stripe_pi'] ? fetchStripeBackfill($r['stripe_pi']) : [];
        $subFill    = fetchSubscripcionBackfill($pdo, $r);
        // Pick the best candidate per field: existing > subs > stripe
        $proposal = [
            'nombre'   => $r['nombre']   ?: ($subFill['nombre']   ?? '') ?: trim(($stripeFill['nombre'] ?? '') . ' ' . ($stripeFill['apellidos'] ?? '')),
            'modelo'   => $r['modelo']   ?: ($subFill['modelo']   ?? '') ?: ($stripeFill['modelo']   ?? ''),
            'color'    => $r['color']    ?: ($subFill['color']    ?? '') ?: ($stripeFill['color']    ?? ''),
            'telefono' => $r['telefono'] ?: ($subFill['telefono'] ?? '') ?: ($stripeFill['telefono'] ?? ''),
            'email'    => $r['email']    ?: ($subFill['email']    ?? '') ?: ($stripeFill['email']    ?? ''),
        ];
        $hasProposalData = trim($proposal['nombre']) !== '' && trim($proposal['modelo']) !== '';
        $results[] = [
            'id'           => (int)$r['id'],
            'pedido_corto' => $r['pedido_corto'] ?: ('VK-' . ($r['pedido'] ?? $r['id'])),
            'pedido'       => $r['pedido'],
            'freg'         => $r['freg'],
            'current'      => [
                'nombre'   => $r['nombre'],
                'modelo'   => $r['modelo'],
                'color'    => $r['color'],
                'telefono' => $r['telefono'],
                'email'    => $r['email'],
                'monto'    => (float)$r['total'],
                'tpago'    => $r['tpago'],
                'stripe_pi'=> $r['stripe_pi'],
            ],
            'proposal'     => $proposal,
            'can_backfill' => $hasProposalData,
            'sources'      => [
                'stripe' => !empty($stripeFill),
                'subs'   => !empty($subFill),
            ],
        ];
    }
    adminJsonOut([
        'ok'       => true,
        'phantoms' => $results,
        'count'    => count($results),
    ]);
}

// ── POST: actions ────────────────────────────────────────────────────────
adminRequireAuth(['admin']); // only admin can mutate
$d      = adminJsonIn();
$action = trim($d['action'] ?? '');
$ids    = is_array($d['ids'] ?? null) ? array_map('intval', $d['ids']) : [];
$ids    = array_values(array_filter($ids, fn($i) => $i > 0));

if (!$ids) adminJsonOut(['error' => 'ids requerido'], 400);

if ($action === 'archive') {
    $motivo = trim((string)($d['motivo'] ?? 'Orden phantom sin datos — archivada por admin'));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("UPDATE transacciones SET seguimiento='archivado',
                          seguimiento_nota=CONCAT(COALESCE(seguimiento_nota,''), ?, '\n')
                   WHERE id IN ($ph)")
        ->execute(array_merge(['[' . date('Y-m-d H:i') . '] ' . $motivo], $ids));
    adminLog('phantom_archive', ['ids' => $ids, 'motivo' => $motivo]);
    adminJsonOut(['ok' => true, 'archived' => count($ids)]);
}

if ($action === 'delete') {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM transacciones WHERE id IN ($ph)
                       AND (TRIM(COALESCE(nombre,'')) = '' OR TRIM(COALESCE(modelo,'')) = '')")
        ->execute($ids);
    adminLog('phantom_delete', ['ids' => $ids]);
    adminJsonOut(['ok' => true, 'deleted' => count($ids)]);
}

if ($action === 'backfill') {
    $applied = 0; $skipped = [];
    $upd = $pdo->prepare("UPDATE transacciones
                          SET nombre = CASE WHEN TRIM(COALESCE(nombre,''))  = '' THEN ? ELSE nombre END,
                              modelo = CASE WHEN TRIM(COALESCE(modelo,''))  = '' THEN ? ELSE modelo END,
                              color  = CASE WHEN TRIM(COALESCE(color,''))   = '' THEN ? ELSE color  END,
                              telefono = CASE WHEN TRIM(COALESCE(telefono,''))= '' THEN ? ELSE telefono END,
                              email  = CASE WHEN TRIM(COALESCE(email,''))   = '' THEN ? ELSE email  END
                          WHERE id = ?
                            AND (TRIM(COALESCE(nombre,'')) = '' OR TRIM(COALESCE(modelo,'')) = '')");

    $sel = $pdo->prepare("SELECT id, pedido, nombre, email, telefono, modelo, color, stripe_pi FROM transacciones WHERE id = ? LIMIT 1");

    foreach ($ids as $id) {
        $sel->execute([$id]);
        $tx = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$tx) { $skipped[] = ['id' => $id, 'reason' => 'not_found']; continue; }
        $stripeFill = $tx['stripe_pi'] ? fetchStripeBackfill($tx['stripe_pi']) : [];
        $subFill    = fetchSubscripcionBackfill($pdo, $tx);
        $nombre   = $tx['nombre']   ?: ($subFill['nombre']   ?? '') ?: trim(($stripeFill['nombre'] ?? '') . ' ' . ($stripeFill['apellidos'] ?? ''));
        $modelo   = $tx['modelo']   ?: ($subFill['modelo']   ?? '') ?: ($stripeFill['modelo']   ?? '');
        $color    = $tx['color']    ?: ($subFill['color']    ?? '') ?: ($stripeFill['color']    ?? '');
        $telefono = $tx['telefono'] ?: ($subFill['telefono'] ?? '') ?: ($stripeFill['telefono'] ?? '');
        $email    = $tx['email']    ?: ($subFill['email']    ?? '') ?: ($stripeFill['email']    ?? '');
        if (trim($nombre) === '' || trim($modelo) === '') {
            $skipped[] = ['id' => $id, 'reason' => 'no_source_data', 'stripe_pi' => $tx['stripe_pi']];
            continue;
        }
        $upd->execute([$nombre, $modelo, $color, $telefono, $email, $id]);
        $applied++;
    }

    adminLog('phantom_backfill', ['applied' => $applied, 'skipped' => $skipped]);
    adminJsonOut([
        'ok'      => true,
        'applied' => $applied,
        'skipped' => $skipped,
        'message' => $applied . ' órdenes rellenadas. ' . count($skipped) . ' quedan sin datos suficientes — pueden archivarse.',
    ]);
}

adminJsonOut(['error' => 'action desconocida (archive|delete|backfill)'], 400);
