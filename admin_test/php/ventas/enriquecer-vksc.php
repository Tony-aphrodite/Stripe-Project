<?php
/**
 * POST — Plan G: Enrich subscripciones_credito orphan rows from Stripe.
 *
 * Some VK-SC rows (especially legacy ones created before the Plan A–H fix)
 * have empty modelo/color/precio_contado because the original call to
 * create-setup-intent.php didn't persist that context. Going forward those
 * fields are also written to Stripe SetupIntent metadata, so we can recover
 * them from Stripe by querying the SetupIntent.
 *
 * Strategy per row:
 *   1. If modelo + color + precio_contado are all populated → skip.
 *   2. Else, fetch SetupIntent via stripe_setup_intent_id and read its metadata.
 *   3. If metadata has the fields → UPDATE the DB row.
 *   4. Fallback: if there's a transacciones row with same telefono/email, copy
 *      modelo/color/total from there.
 *
 * Response: { ok, scanned, enriched, skipped, no_source, errors[] }
 * Supports POST { dry_run: true } for preview.
 */
require_once __DIR__ . '/../bootstrap.php';
adminRequireAuth(['admin','cedis']);

$body   = adminJsonIn();
$dryRun = !empty($body['dry_run']);

$pdo = getDB();

$stripeKey = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : (getenv('STRIPE_SECRET_KEY') ?: '');
if (!$stripeKey) {
    adminJsonOut(['ok' => false, 'error' => 'STRIPE_SECRET_KEY no configurada'], 500);
}

function stripeGetSetupIntent(string $key, string $id): array {
    $url = 'https://api.stripe.com/v1/setup_intents/' . urlencode($id);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $key . ':',
        CURLOPT_TIMEOUT        => 20,
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err || $code >= 400) {
        return ['error' => $err ?: ('HTTP ' . $code)];
    }
    return json_decode($raw, true) ?: ['error' => 'invalid JSON'];
}

// Find orphan VK-SC rows with missing data
$rows = $pdo->query("
    SELECT s.id, s.telefono, s.email, s.modelo, s.color, s.precio_contado,
           s.plazo_meses, s.monto_semanal, s.stripe_setup_intent_id
    FROM subscripciones_credito s
    WHERE s.stripe_setup_intent_id IS NOT NULL
      AND s.stripe_setup_intent_id <> ''
      AND (
            (s.modelo IS NULL OR s.modelo = '' OR s.modelo = '-')
         OR (s.color  IS NULL OR s.color  = '' OR s.color  = '-')
         OR (s.precio_contado IS NULL OR s.precio_contado = 0)
      )
    ORDER BY s.id DESC
    LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);

$scanned  = count($rows);
$enriched = 0;
$skipped  = 0;
$noSource = 0;
$errors   = [];
$actions  = [];

$upd = $pdo->prepare("
    UPDATE subscripciones_credito
    SET modelo         = COALESCE(NULLIF(:modelo, ''), modelo),
        color          = COALESCE(NULLIF(:color,  ''), color),
        precio_contado = COALESCE(NULLIF(:precio, 0),  precio_contado),
        plazo_meses    = COALESCE(NULLIF(:plazo,  0),  plazo_meses),
        monto_semanal  = COALESCE(NULLIF(:monto,  0),  monto_semanal)
    WHERE id = :id
");

foreach ($rows as $r) {
    $sid = $r['stripe_setup_intent_id'];

    // Skip simulated ids
    if (strpos($sid, 'simulated_') === 0) { $skipped++; continue; }

    $si = stripeGetSetupIntent($stripeKey, $sid);
    if (isset($si['error'])) {
        // Fallback: copy from transacciones via telefono/email
        $match = null;
        if (!empty($r['telefono'])) {
            $m = $pdo->prepare("SELECT modelo, color, total FROM transacciones WHERE telefono = ? AND modelo <> '' LIMIT 1");
            $m->execute([$r['telefono']]);
            $match = $m->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$match && !empty($r['email'])) {
            $m = $pdo->prepare("SELECT modelo, color, total FROM transacciones WHERE email = ? AND modelo <> '' LIMIT 1");
            $m->execute([$r['email']]);
            $match = $m->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($match) {
            if ($dryRun) {
                $actions[] = "would enrich sub_id={$r['id']} from transacciones (modelo={$match['modelo']})";
                $enriched++;
                continue;
            }
            try {
                $upd->execute([
                    ':modelo' => $match['modelo'] ?? '',
                    ':color'  => $match['color']  ?? '',
                    ':precio' => (float)($match['total'] ?? 0),
                    ':plazo'  => 0,
                    ':monto'  => 0,
                    ':id'     => $r['id'],
                ]);
                $enriched++;
                $actions[] = "enriched sub_id={$r['id']} from transacciones";
            } catch (Throwable $e) {
                $errors[] = "sub_id={$r['id']}: " . $e->getMessage();
            }
        } else {
            $noSource++;
            $errors[] = "sub_id={$r['id']}: Stripe " . $si['error'] . ' y sin match en transacciones';
        }
        continue;
    }

    $meta = $si['metadata'] ?? [];
    $modelo = $meta['modelo'] ?? '';
    $color  = $meta['color']  ?? '';
    $precio = (float)($meta['precio_contado'] ?? 0);
    $plazo  = (int)($meta['plazo_meses']   ?? 0);
    $monto  = (float)($meta['monto_semanal'] ?? 0);

    if ($modelo === '' && $color === '' && $precio == 0) {
        $noSource++;
        $actions[] = "sub_id={$r['id']}: Stripe metadata vacío (SetupIntent legacy)";
        continue;
    }

    if ($dryRun) {
        $actions[] = "would enrich sub_id={$r['id']} from Stripe (modelo={$modelo}, color={$color}, precio={$precio})";
        $enriched++;
        continue;
    }

    try {
        $upd->execute([
            ':modelo' => $modelo,
            ':color'  => $color,
            ':precio' => $precio,
            ':plazo'  => $plazo,
            ':monto'  => $monto,
            ':id'     => $r['id'],
        ]);
        $enriched++;
        $actions[] = "enriched sub_id={$r['id']} from Stripe SetupIntent";
    } catch (Throwable $e) {
        $errors[] = "sub_id={$r['id']}: " . $e->getMessage();
    }
}

adminLog('enriquecer_vksc', [
    'dry_run'  => $dryRun,
    'scanned'  => $scanned,
    'enriched' => $enriched,
    'skipped'  => $skipped,
    'no_source'=> $noSource,
    'errors'   => count($errors),
]);

adminJsonOut([
    'ok'        => true,
    'dry_run'   => $dryRun,
    'scanned'   => $scanned,
    'enriched'  => $enriched,
    'skipped'   => $skipped,
    'no_source' => $noSource,
    'errors'    => $errors,
    'actions'   => $actions,
]);
