<?php
/**
 * Busca automáticamente el PaymentIntent de Stripe correspondiente a una
 * transacción que quedó sin stripe_pi (típico de ventas del configurador
 * legacy que no cerraron el webhook correctamente, ej. Eduardo Gonzalez Lopez
 * VK-1776828725: pago exitoso pero stripe_pi = NULL en DB).
 *
 * Estrategia de búsqueda:
 *   1. Email cliente + monto exacto en ventana ±3 días → mejor match
 *   2. Solo monto + ventana de ±1 día → fallback
 *   3. Solo email (cualquier monto/fecha) → último recurso
 *
 * GET  ?transaccion_id=123  → lista candidates (preview)
 * POST { transaccion_id, stripe_pi } → vincula el PI y recalcula pago_estado
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin', 'cedis']);

// Load Stripe secret
$cfgCandidates = [
    __DIR__ . '/../../../configurador_prueba/php/config.php',
    __DIR__ . '/../../../configurador_prueba_test/php/config.php',
];
foreach ($cfgCandidates as $p) { if (file_exists($p)) { require_once $p; break; } }
if (!defined('STRIPE_SECRET_KEY') || !STRIPE_SECRET_KEY) {
    adminJsonOut(['error' => 'STRIPE_SECRET_KEY no configurada'], 500);
}

$pdo = getDB();

// ── Helpers ──────────────────────────────────────────────────────────────
function stripeApi(string $path, array $query = []): array {
    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    if ($query) $url .= '?' . http_build_query($query);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . STRIPE_SECRET_KEY],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        return ['_error' => 'Stripe HTTP ' . $code, '_raw' => $raw];
    }
    return json_decode($raw, true) ?: [];
}

function formatPi(array $pi): array {
    $charges = $pi['charges']['data'] ?? [];
    $email = $pi['receipt_email'] ?? null;
    $card  = null;
    if (!empty($charges)) {
        $pm = $charges[0]['payment_method_details'] ?? [];
        if (!empty($pm['card'])) {
            $card = ($pm['card']['brand'] ?? '') . ' ****' . ($pm['card']['last4'] ?? '');
        }
        if (!$email) $email = $charges[0]['billing_details']['email'] ?? null;
    }
    return [
        'id'       => $pi['id'] ?? '',
        'status'   => $pi['status'] ?? '',
        'amount'   => isset($pi['amount']) ? $pi['amount'] / 100 : 0,
        'currency' => strtoupper($pi['currency'] ?? 'mxn'),
        'created'  => isset($pi['created']) ? date('Y-m-d H:i', $pi['created']) : null,
        'email'    => $email,
        'card'     => $card,
    ];
}

// ── POST: vincular ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d   = adminJsonIn();
    $tid = (int)($d['transaccion_id'] ?? 0);
    $pi  = trim($d['stripe_pi'] ?? '');
    if (!$tid || !$pi) adminJsonOut(['error' => 'transaccion_id y stripe_pi requeridos'], 400);
    if (strpos($pi, 'pi_') !== 0) adminJsonOut(['error' => 'stripe_pi debe empezar con pi_'], 400);

    // Verify against Stripe (evita escribir PIs inventados)
    $resp = stripeApi('payment_intents/' . urlencode($pi));
    if (isset($resp['_error']) || empty($resp['id'])) {
        adminJsonOut(['error' => 'Stripe no reconoce ese PaymentIntent: ' . ($resp['_error'] ?? 'id inválido')], 400);
    }

    $status   = $resp['status'] ?? '';
    $tStmt = $pdo->prepare("SELECT id, tpago FROM transacciones WHERE id = ? LIMIT 1");
    $tStmt->execute([$tid]);
    $t = $tStmt->fetch(PDO::FETCH_ASSOC);
    if (!$t) adminJsonOut(['error' => 'Transacción no encontrada'], 404);

    $tpagoLc = strtolower(trim($t['tpago'] ?? ''));
    $isCreditFam = in_array($tpagoLc, ['credito', 'enganche', 'parcial'], true);

    $expected = 'pendiente';
    if ($status === 'succeeded') $expected = $isCreditFam ? 'parcial' : 'pagada';
    elseif ($status === 'canceled') $expected = 'fallido';

    $pdo->prepare("UPDATE transacciones SET stripe_pi = ?, pago_estado = ? WHERE id = ?")
        ->execute([$pi, $expected, $tid]);
    // Propagate to inventario_motos if already assigned to this order
    $pdo->prepare("UPDATE inventario_motos SET stripe_pi = ?, pago_estado = ? WHERE stripe_pi IS NULL AND cliente_email IN (SELECT email FROM transacciones WHERE id = ?)")
        ->execute([$pi, $expected, $tid]);

    adminLog('vincular_stripe_pi', [
        'transaccion_id' => $tid,
        'stripe_pi'      => $pi,
        'status'         => $status,
        'pago_estado'    => $expected,
    ]);

    adminJsonOut([
        'ok'          => true,
        'stripe_pi'   => $pi,
        'status'      => $status,
        'pago_estado' => $expected,
        'message'     => 'PaymentIntent vinculado correctamente (estado: ' . $expected . ').',
    ]);
}

// ── GET: buscar candidates ───────────────────────────────────────────────
$tid = (int)($_GET['transaccion_id'] ?? 0);
if (!$tid) adminJsonOut(['error' => 'transaccion_id requerido'], 400);

$stmt = $pdo->prepare("SELECT id, email, telefono, total, freg, stripe_pi FROM transacciones WHERE id = ? LIMIT 1");
$stmt->execute([$tid]);
$t = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$t) adminJsonOut(['error' => 'Transacción no encontrada'], 404);

if (!empty($t['stripe_pi'])) {
    adminJsonOut([
        'ok' => true,
        'already_linked' => true,
        'stripe_pi' => $t['stripe_pi'],
        'message' => 'Esta transacción ya tiene PaymentIntent vinculado.',
    ]);
}

$email       = trim($t['email'] ?? '');
$amountCents = (int)round(((float)$t['total']) * 100);
$fecha       = strtotime($t['freg'] ?? 'now');

// Stripe API: list payment_intents. We pull a window around the order date.
// Max 100 per page — enough for typical sale volume.
$windowDays = 3;
$createdGte = $fecha ? $fecha - ($windowDays * 86400) : null;
$createdLte = $fecha ? $fecha + ($windowDays * 86400) : null;

$query = [
    'limit' => 100,
];
if ($createdGte) $query['created[gte]'] = $createdGte;
if ($createdLte) $query['created[lte]'] = $createdLte;

$resp = stripeApi('payment_intents', $query);
if (isset($resp['_error'])) {
    adminJsonOut(['error' => 'Stripe: ' . $resp['_error']], 502);
}

$all = $resp['data'] ?? [];
$matches = [
    'exact'    => [],  // email + amount (ideal)
    'amount'   => [],  // amount only (email diff o vacío)
    'email'    => [],  // email only (monto no coincide — p.ej. enganche parcial)
];

foreach ($all as $pi) {
    $piFmt = formatPi($pi);
    $piEmail  = strtolower(trim($piFmt['email'] ?? ''));
    $piAmount = (int)($pi['amount'] ?? 0);

    $emailMatches  = $email !== '' && $piEmail === strtolower($email);
    $amountMatches = $amountCents > 0 && $piAmount === $amountCents;

    if ($emailMatches && $amountMatches)      $matches['exact'][]  = $piFmt;
    elseif ($amountMatches)                   $matches['amount'][] = $piFmt;
    elseif ($emailMatches)                    $matches['email'][]  = $piFmt;
}

adminJsonOut([
    'ok'       => true,
    'order'    => [
        'id'       => (int)$t['id'],
        'email'    => $email,
        'total'    => (float)$t['total'],
        'fecha'    => $t['freg'],
    ],
    'searched_window_days' => $windowDays,
    'scanned'  => count($all),
    'matches'  => $matches,
    'summary'  => [
        'exact'    => count($matches['exact']),
        'amount'   => count($matches['amount']),
        'email'    => count($matches['email']),
    ],
]);
