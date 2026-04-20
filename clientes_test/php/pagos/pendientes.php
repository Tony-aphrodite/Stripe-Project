<?php
/**
 * Voltika Portal - List pending OXXO/SPEI payments
 * Returns all `transacciones` rows for this client whose estado = 'pending'
 * and origen IN (portal_oxxo, portal_spei), enriched with live Stripe data
 * (voucher URL / CLABE) so the customer can re-open the instructions modal.
 */
require_once __DIR__ . '/../bootstrap.php';
$cid = portalRequireAuth();

$pdo = getDB();
$rows = [];
try {
    $stmt = $pdo->prepare("SELECT id, stripe_payment_intent, monto, origen, freg
        FROM transacciones
        WHERE cliente_id = ? AND estado = 'pending' AND origen IN ('portal_oxxo','portal_spei')
        ORDER BY id DESC LIMIT 5");
    $stmt->execute([$cid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('pendientes: ' . $e->getMessage()); }

$out = [];
foreach ($rows as $r) {
    $piId = $r['stripe_payment_intent'] ?? '';
    if (!$piId) continue;

    $ch = curl_init('https://api.stripe.com/v1/payment_intents/' . urlencode($piId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . STRIPE_SECRET_KEY],
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch); curl_close($ch);
    $pi = json_decode($resp, true) ?: [];
    $status = $pi['status'] ?? '';

    // Auto-clean: if Stripe already succeeded, flip our pending row so the
    // banner doesn't linger forever when the webhook raced ahead.
    if ($status === 'succeeded') {
        try { $pdo->prepare("UPDATE transacciones SET estado = 'succeeded' WHERE id = ?")
                 ->execute([$r['id']]); } catch (Throwable $e) {}
        continue;
    }
    if (in_array($status, ['canceled','payment_failed'], true)) {
        try { $pdo->prepare("UPDATE transacciones SET estado = 'expired' WHERE id = ?")
                 ->execute([$r['id']]); } catch (Throwable $e) {}
        continue;
    }
    if ($status !== 'requires_action' && $status !== 'requires_payment_method' && $status !== 'processing') {
        continue;
    }

    $next = $pi['next_action'] ?? [];

    if ($r['origen'] === 'portal_oxxo') {
        $oxxo = $next['oxxo_display_details'] ?? null;
        if (!$oxxo) continue;
        $out[] = [
            'id'          => (int)$r['id'],
            'origen'      => 'portal_oxxo',
            'monto'       => (float)$r['monto'],
            'referencia'  => $oxxo['number'] ?? '',
            'voucher_url' => $oxxo['hosted_voucher_url'] ?? '',
            'expires_at'  => (int)($oxxo['expires_after'] ?? 0),
            'payment_intent' => $piId,
        ];
    } else if ($r['origen'] === 'portal_spei') {
        $inst = $next['display_bank_transfer_instructions'] ?? null;
        if (!$inst) continue;
        $clabe = '';
        foreach (($inst['financial_addresses'] ?? []) as $addr) {
            if (!empty($addr['spei']['clabe']))       { $clabe = $addr['spei']['clabe']; break; }
            if (!empty($addr['spei_clabe']['clabe'])) { $clabe = $addr['spei_clabe']['clabe']; break; }
            if (!empty($addr['clabe']))               { $clabe = $addr['clabe']; break; }
        }
        if (!$clabe && !empty($inst['financial_addresses'])) {
            array_walk_recursive($inst['financial_addresses'], function($v) use (&$clabe) {
                if (!$clabe && is_string($v) && preg_match('/^\d{18}$/', $v)) $clabe = $v;
            });
        }
        $out[] = [
            'id'            => (int)$r['id'],
            'origen'        => 'portal_spei',
            'monto'         => (float)$r['monto'],
            'clabe'         => $clabe,
            'banco'         => 'STP',
            'beneficiario'  => 'MTECH GEARS S.A. DE C.V.',
            'referencia'    => $inst['reference'] ?? '',
            'payment_intent' => $piId,
        ];
    }
}

portalJsonOut(['pendientes' => $out]);
