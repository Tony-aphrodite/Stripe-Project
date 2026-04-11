<?php
/**
 * POST — Recover an orphan order into `transacciones`.
 *
 * Two entry modes:
 *   1) source=transacciones_errores, err_id=<id>
 *      Promotes a row from the recovery table into transacciones.
 *   2) source=stripe, stripe_pi=pi_xxx
 *      Queries Stripe directly and reconstructs the transacciones row from
 *      the PaymentIntent metadata + billing details. Used for orders lost
 *      before Plan B (transacciones_errores) existed — e.g. the historical
 *      #1775922576 / VK-20260411-LEO case.
 *
 * In both modes the admin can optionally pass override fields (nombre,
 * email, telefono, modelo, color, total, folio_contrato) when the source
 * data is incomplete. Idempotent: if a row with the same stripe_pi already
 * exists in transacciones, returns it instead of duplicating.
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$d = adminJsonIn();
$source   = $d['source']   ?? '';
$stripePi = trim($d['stripe_pi'] ?? '');
$errId    = (int)($d['err_id'] ?? 0);

$override = [
    'nombre'         => trim($d['nombre']         ?? ''),
    'email'          => trim($d['email']          ?? ''),
    'telefono'       => trim($d['telefono']       ?? ''),
    'modelo'         => trim($d['modelo']         ?? ''),
    'color'          => trim($d['color']          ?? ''),
    'total'          => isset($d['total'])        ? (float)$d['total'] : null,
    'folio_contrato' => trim($d['folio_contrato'] ?? ''),
    'ciudad'         => trim($d['ciudad']         ?? ''),
    'estado'         => trim($d['estado']         ?? ''),
    'cp'             => trim($d['cp']             ?? ''),
    'tpago'          => trim($d['tpago']          ?? 'enganche'),
];

$pdo = getDB();

// Idempotency: if stripe_pi already lives in transacciones, return that row.
if ($stripePi !== '') {
    $chk = $pdo->prepare("SELECT id, pedido, folio_contrato FROM transacciones WHERE stripe_pi = ? LIMIT 1");
    $chk->execute([$stripePi]);
    $existing = $chk->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        adminJsonOut([
            'ok'          => true,
            'already'     => true,
            'transaccion' => $existing,
            'msg'         => 'La orden ya existe en transacciones — no se duplicó.',
        ]);
    }
}

// Collect source data
$row = null;

if ($source === 'transacciones_errores' && $errId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM transacciones_errores WHERE id = ? LIMIT 1");
    $stmt->execute([$errId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) adminJsonOut(['ok' => false, 'error' => 'Error id no encontrado'], 404);
    if (empty($stripePi)) $stripePi = $row['stripe_pi'] ?? '';
    // Hydrate from payload JSON if present
    $payload = [];
    if (!empty($row['payload'])) {
        $tmp = json_decode($row['payload'], true);
        if (is_array($tmp)) $payload = $tmp;
    }
    $row = array_merge($payload, $row);
}

if ($source === 'stripe') {
    if ($stripePi === '') adminJsonOut(['ok' => false, 'error' => 'stripe_pi requerido'], 400);
    $stripeKey = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : (getenv('STRIPE_SECRET_KEY') ?: '');
    if (!$stripeKey) adminJsonOut(['ok' => false, 'error' => 'STRIPE_SECRET_KEY no configurada'], 500);

    $ch = curl_init('https://api.stripe.com/v1/payment_intents/' . urlencode($stripePi) . '?expand[]=charges.data.billing_details');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $stripeKey . ':',
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) adminJsonOut(['ok' => false, 'error' => 'Stripe PI no encontrado', 'http' => $code], 404);

    $pi = json_decode($raw, true) ?: [];
    if (($pi['status'] ?? '') !== 'succeeded') {
        adminJsonOut(['ok' => false, 'error' => 'Stripe PI no está succeeded', 'status' => $pi['status'] ?? 'unknown'], 400);
    }
    $billing = $pi['charges']['data'][0]['billing_details'] ?? [];
    $meta    = $pi['metadata'] ?? [];
    $row = [
        'nombre'   => $billing['name']  ?? ($meta['nombre']  ?? ''),
        'email'    => $billing['email'] ?? ($meta['email']   ?? ''),
        'telefono' => $billing['phone'] ?? ($meta['telefono']?? ''),
        'modelo'   => $meta['modelo']   ?? '',
        'color'    => $meta['color']    ?? '',
        'total'    => ($pi['amount_received'] ?? 0) / 100,
        'stripe_pi'=> $pi['id'],
        'freg'     => date('Y-m-d H:i', (int)($pi['created'] ?? time())),
    ];
}

if (!$row) {
    adminJsonOut(['ok' => false, 'error' => 'source inválido (usa transacciones_errores|stripe)'], 400);
}

// Overrides win over source data
$pick = function(string $k, $fallback = null) use ($override, $row) {
    if (array_key_exists($k, $override) && $override[$k] !== '' && $override[$k] !== null) return $override[$k];
    if (array_key_exists($k, $row) && $row[$k] !== '' && $row[$k] !== null) return $row[$k];
    return $fallback;
};

$nombre   = (string)$pick('nombre', '');
$email    = (string)$pick('email', '');
$telefono = (string)$pick('telefono', '');
$modelo   = (string)$pick('modelo', '');
$color    = (string)$pick('color', '');
$total    = (float) $pick('total', 0);
$tpago    = (string)$pick('tpago', 'enganche');
$ciudad   = (string)$pick('ciudad', '');
$estadoCli= (string)$pick('estado', '');
$cp       = (string)$pick('cp', '');

// Pedido and folio — generate sensible defaults when source didn't have one
$pedidoNum = $row['pedido'] ?? (time() . '-' . substr(bin2hex(random_bytes(3)), 0, 4));
$folio = $override['folio_contrato'] !== ''
    ? $override['folio_contrato']
    : ($row['folio_contrato'] ?? ('VK-' . date('Ymd', strtotime($row['freg'] ?? 'now')) . '-' . strtoupper(substr($nombre ?: 'REC', 0, 3))));

// Ensure target schema exists (bootstrap usually did this, but be safe)
$pdo->exec("CREATE TABLE IF NOT EXISTS transacciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(200), email VARCHAR(200), telefono VARCHAR(30),
    modelo VARCHAR(200), color VARCHAR(100),
    ciudad VARCHAR(100), estado VARCHAR(100), cp VARCHAR(10),
    tpago VARCHAR(50), precio DECIMAL(12,2), total DECIMAL(12,2),
    freg DATETIME DEFAULT CURRENT_TIMESTAMP,
    pedido VARCHAR(40), stripe_pi VARCHAR(100),
    folio_contrato VARCHAR(40) NULL,
    INDEX idx_stripe_pi (stripe_pi), INDEX idx_pedido (pedido), INDEX idx_folio (folio_contrato)
)");

try {
    $ins = $pdo->prepare("
        INSERT INTO transacciones
            (nombre, email, telefono, modelo, color, ciudad, estado, cp, tpago,
             precio, total, freg, pedido, stripe_pi, folio_contrato)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)
    ");
    $ins->execute([
        $nombre, $email, $telefono, $modelo, $color,
        $ciudad, $estadoCli, $cp, $tpago,
        $total, $total, $pedidoNum, $stripePi, $folio,
    ]);
    $newId = $pdo->lastInsertId();
} catch (Throwable $e) {
    adminJsonOut(['ok' => false, 'error' => 'INSERT falló: ' . $e->getMessage()], 500);
}

// Mark the error row as recovered (but keep it for audit)
if ($source === 'transacciones_errores' && $errId > 0) {
    try {
        $pdo->exec("ALTER TABLE transacciones_errores ADD COLUMN recuperado_tx_id INT NULL");
    } catch (Throwable $e) { /* already exists */ }
    try {
        $pdo->prepare("UPDATE transacciones_errores SET recuperado_tx_id = ? WHERE id = ?")
            ->execute([$newId, $errId]);
    } catch (Throwable $e) { /* noop */ }
}

adminLog('recuperar_orden', [
    'source'    => $source,
    'err_id'    => $errId,
    'stripe_pi' => $stripePi,
    'new_tx_id' => $newId,
]);

adminJsonOut([
    'ok'        => true,
    'recovered' => true,
    'tx_id'     => (int)$newId,
    'pedido'    => $pedidoNum,
    'folio'     => $folio,
    'stripe_pi' => $stripePi,
]);
