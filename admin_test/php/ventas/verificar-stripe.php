<?php
/**
 * Plan G — Stripe reconciliation
 *
 * Lista los PaymentIntents `succeeded` de las últimas N horas en Stripe y los
 * compara contra la tabla `transacciones`. Cualquier PI cobrado en Stripe que
 * no tenga fila en transacciones se reporta como "huérfano" y se graba en
 * `transacciones_errores` para que el admin lo vea en el dashboard de ventas.
 *
 * Uso:
 *   - GET /admin/php/ventas/verificar-stripe.php?horas=24
 *   - Ideal para correr cada 15 min como cron.
 */
require_once __DIR__ . '/../bootstrap.php';

// Dual auth: admin session OR cron token via header/query.
// Cron token is set in .env as VOLTIKA_CRON_TOKEN=xxxxxxxxxxxxxx
$cronToken = getenv('VOLTIKA_CRON_TOKEN') ?: '';
$provided  = $_GET['token']
    ?? ($_SERVER['HTTP_X_CRON_TOKEN'] ?? '');
$isCron = $cronToken !== '' && hash_equals($cronToken, (string)$provided);

if (!$isCron) {
    adminRequireAuth(['admin','cedis']);
}

$horas = max(1, min(720, (int)($_GET['horas'] ?? 24)));
$desde = time() - ($horas * 3600);

$stripeKey = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : (getenv('STRIPE_SECRET_KEY') ?: '');
if (!$stripeKey) {
    adminJsonOut(['ok' => false, 'error' => 'STRIPE_SECRET_KEY no configurada'], 500);
}

$pdo = getDB();

// Asegurar tabla de errores (misma estructura que confirmar-orden.php)
$pdo->exec("CREATE TABLE IF NOT EXISTS transacciones_errores (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    nombre    VARCHAR(200),
    email     VARCHAR(200),
    telefono  VARCHAR(30),
    modelo    VARCHAR(200),
    color     VARCHAR(100),
    total     DECIMAL(12,2),
    stripe_pi VARCHAR(100),
    payload   TEXT,
    error_msg TEXT,
    freg      DATETIME DEFAULT CURRENT_TIMESTAMP
)");

function stripeListPaymentIntents(string $key, int $since, ?string $startingAfter = null): array {
    $qs = [
        'limit'                => 100,
        'created[gte]'         => $since,
    ];
    if ($startingAfter) $qs['starting_after'] = $startingAfter;
    $url = 'https://api.stripe.com/v1/payment_intents?' . http_build_query($qs);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $key . ':',
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err || $code >= 400) {
        return ['error' => $err ?: ('HTTP ' . $code), 'data' => []];
    }
    return json_decode($raw, true) ?: ['data' => []];
}

$orphans   = [];
$scanned   = 0;
$succeeded = 0;
$matched   = 0;
$cursor    = null;
$safety    = 0;

do {
    $page = stripeListPaymentIntents($stripeKey, $desde, $cursor);
    if (isset($page['error'])) {
        adminJsonOut(['ok' => false, 'error' => 'Stripe API: ' . $page['error']], 502);
    }
    $list = $page['data'] ?? [];
    foreach ($list as $pi) {
        $scanned++;
        if (($pi['status'] ?? '') !== 'succeeded') continue;
        $succeeded++;
        $piId = $pi['id'] ?? '';
        if (!$piId) continue;

        $chk = $pdo->prepare("SELECT id FROM transacciones WHERE stripe_pi = ? LIMIT 1");
        $chk->execute([$piId]);
        if ($chk->fetchColumn()) {
            $matched++;
            continue;
        }

        // Órfano — no existe en transacciones. Ya lo tenemos en errores?
        $dup = $pdo->prepare("SELECT id FROM transacciones_errores WHERE stripe_pi = ? LIMIT 1");
        $dup->execute([$piId]);
        if ($dup->fetchColumn()) {
            $orphans[] = ['stripe_pi' => $piId, 'already_logged' => true];
            continue;
        }

        $meta = $pi['metadata'] ?? [];
        $billing = $pi['charges']['data'][0]['billing_details'] ?? [];
        $ins = $pdo->prepare("
            INSERT INTO transacciones_errores
                (nombre, email, telefono, modelo, color, total, stripe_pi, payload, error_msg)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $billing['name']  ?? ($meta['nombre']  ?? null),
            $billing['email'] ?? ($meta['email']   ?? null),
            $billing['phone'] ?? ($meta['telefono']?? null),
            $meta['modelo'] ?? null,
            $meta['color']  ?? null,
            ($pi['amount_received'] ?? 0) / 100,
            $piId,
            json_encode($pi),
            'Stripe PI succeeded sin fila en transacciones — detectado por verificar-stripe.php',
        ]);
        $orphans[] = [
            'stripe_pi' => $piId,
            'monto'     => ($pi['amount_received'] ?? 0) / 100,
            'created'   => date('Y-m-d H:i:s', (int)($pi['created'] ?? 0)),
            'already_logged' => false,
        ];
    }
    $cursor = $page['has_more'] ?? false ? end($list)['id'] : null;
    $safety++;
} while ($cursor && $safety < 20);

adminJsonOut([
    'ok'         => true,
    'horas'      => $horas,
    'scanned'    => $scanned,
    'succeeded'  => $succeeded,
    'matched'    => $matched,
    'orphans'    => count($orphans),
    'detalle'    => $orphans,
    'checked_at' => date('c'),
]);
